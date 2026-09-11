<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\LoginThrottle;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;

/**
 * Prueft die Player-Sperr-PIN. Die eigentliche Sperre (Blur-Overlay) ist
 * rein clientseitig - hier wird nur die eingegebene PIN serverseitig gegen
 * den gespeicherten Hash geprueft, damit die PIN nie im Klartext im
 * JavaScript/HTML der Seite stehen muss. Erfordert weiterhin eine gueltige
 * Admin-Session (die Sperre ersetzt kein Login, sie ist ein zusaetzlicher
 * Schutz waehrend einer laufenden Sitzung).
 *
 * Zwei getrennte PIN-Mechanismen: Admins nutzen die eine, global in den
 * Einstellungen hinterlegte PIN (settings.lock_pin_hash). Die eingeschraenkte
 * Auth::ROLE_PLAYER-Rolle hat dort keinen Zugriff und soll auch nicht die
 * Admin-PIN kennen/nutzen muessen - sie legt sich stattdessen beim ersten
 * Sperren eine eigene PIN an (Aktion set_pin), die nur in der PHP-Session
 * liegt ($_SESSION['player_lock_pin_hash']) und damit automatisch mit dem
 * Logout verschwindet - nach der naechsten Anmeldung muss sie erneut
 * festgelegt werden (Nutzeranforderung).
 */

header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();

function json_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$settings = new SettingRepository();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && ($_GET['action'] ?? '') === 'status') {
    $configured = Auth::isAdmin()
        ? $settings->get('lock_pin_hash') !== null
        : isset($_SESSION['player_lock_pin_hash']);
    echo json_encode(['configured' => $configured]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Csrf::check($token)) {
        json_fail(400, 'Ungueltiges Formular. Bitte Seite neu laden.');
    }

    if (($input['action'] ?? '') === 'set_pin') {
        // Nur fuer die eingeschraenkte Rolle - Admins haben ihre PIN bereits
        // ueber admin/settings.php (mit Bestaetigungsfeld, siehe dort).
        if (Auth::isAdmin()) {
            json_fail(400, 'Admin-PINs werden in den Einstellungen verwaltet.');
        }

        $pin = (string) ($input['pin'] ?? '');
        if (!preg_match('/^\d{4}$/', $pin)) {
            json_fail(400, 'Die PIN muss genau 4 Ziffern haben.');
        }

        $_SESSION['player_lock_pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
        $_SESSION['lock_attempts'] = 0;
        $_SESSION['lock_blocked_until'] = 0;
        echo json_encode(['ok' => true]);
        exit;
    }

    if (($input['action'] ?? '') === 'unlock') {
        $blockedUntil = (int) ($_SESSION['lock_blocked_until'] ?? 0);
        if (time() < $blockedUntil) {
            json_fail(429, 'Zu viele Fehlversuche. Bitte kurz warten und erneut versuchen.');
        }

        $pin = (string) ($input['pin'] ?? '');
        // Admin -> globale Settings-PIN, Player-Rolle -> eigene, nur in der
        // Session hinterlegte PIN (siehe set_pin oben).
        $hash = Auth::isAdmin() ? $settings->get('lock_pin_hash') : ($_SESSION['player_lock_pin_hash'] ?? null);

        if ($hash === null) {
            json_fail(400, 'Es ist keine PIN eingerichtet.');
        }

        if (preg_match('/^\d{4}$/', $pin) && password_verify($pin, $hash)) {
            $_SESSION['lock_attempts'] = 0;
            echo json_encode(['ok' => true]);
            exit;
        }

        $attempts = (int) ($_SESSION['lock_attempts'] ?? 0) + 1;
        $_SESSION['lock_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['lock_blocked_until'] = time() + 30;
            $_SESSION['lock_attempts'] = 0;
            json_fail(429, 'Zu viele Fehlversuche. Bitte 30 Sekunden warten.');
        }

        json_fail(401, 'Falsche PIN.');
    }

    if (($input['action'] ?? '') === 'unlock_with_credentials') {
        // Notfall-Entsperrung mit dem eigenen Passwort - NICHT mit einem frei
        // waehlbaren Benutzernamen: diese Aktion soll ausschliesslich die
        // Identitaet der SCHON angemeldeten Session erneut bestaetigen
        // (aehnlich currentPasswordConfirmed() in admin/users.php), nicht
        // als zweites, schwaecher gedrosseltes Login-Formular fuer BELIEBIGE
        // Konten dienen. Ein eingeschraenkter Player-Account koennte sonst
        // ueber genau diesen Weg das Passwort eines Admin-Kontos erraten -
        // der session-gebundene Zaehler unten laesst sich per Logout+Login
        // trivial zuruecksetzen, daher zusaetzlich die IP-basierte, davon
        // unabhaengige Bremse aus LoginThrottle (wie login.php).
        $credBlockedUntil = (int) ($_SESSION['lock_cred_blocked_until'] ?? 0);
        $ipWaitSeconds = LoginThrottle::secondsUntilAllowed('lock_cred');
        if (time() < $credBlockedUntil || $ipWaitSeconds > 0) {
            json_fail(429, 'Zu viele Fehlversuche. Bitte kurz warten und erneut versuchen.');
        }

        $password = (string) ($input['password'] ?? '');
        $me = (new UserRepository())->findById((int) Auth::userId());

        if ($me && $password !== '' && password_verify($password, $me['password_hash'])) {
            $_SESSION['lock_attempts'] = 0;
            $_SESSION['lock_blocked_until'] = 0;
            $_SESSION['lock_cred_attempts'] = 0;
            LoginThrottle::recordSuccess('lock_cred');
            echo json_encode(['ok' => true]);
            exit;
        }

        LoginThrottle::recordFailure('lock_cred');
        $credAttempts = (int) ($_SESSION['lock_cred_attempts'] ?? 0) + 1;
        $_SESSION['lock_cred_attempts'] = $credAttempts;
        if ($credAttempts >= 5) {
            $_SESSION['lock_cred_blocked_until'] = time() + 30;
            $_SESSION['lock_cred_attempts'] = 0;
            json_fail(429, 'Zu viele Fehlversuche. Bitte 30 Sekunden warten.');
        }

        json_fail(401, 'Passwort falsch.');
    }

    json_fail(400, 'Unbekannte Aktion.');
}

json_fail(405, 'Methode nicht erlaubt.');
