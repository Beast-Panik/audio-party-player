<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Repositories\SettingRepository;

/**
 * Prueft die Player-Sperr-PIN. Die eigentliche Sperre (Blur-Overlay) ist
 * rein clientseitig - hier wird nur die eingegebene PIN serverseitig gegen
 * den gespeicherten Hash geprueft, damit die PIN nie im Klartext im
 * JavaScript/HTML der Seite stehen muss. Erfordert weiterhin eine gueltige
 * Admin-Session (die Sperre ersetzt kein Login, sie ist ein zusaetzlicher
 * Schutz waehrend einer laufenden Sitzung).
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
    echo json_encode(['configured' => $settings->get('lock_pin_hash') !== null]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Csrf::check($token)) {
        json_fail(400, 'Ungueltiges Formular. Bitte Seite neu laden.');
    }

    if (($input['action'] ?? '') === 'unlock') {
        $blockedUntil = (int) ($_SESSION['lock_blocked_until'] ?? 0);
        if (time() < $blockedUntil) {
            json_fail(429, 'Zu viele Fehlversuche. Bitte kurz warten und erneut versuchen.');
        }

        $pin = (string) ($input['pin'] ?? '');
        $hash = $settings->get('lock_pin_hash');

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

    json_fail(400, 'Unbekannte Aktion.');
}

json_fail(405, 'Methode nicht erlaubt.');
