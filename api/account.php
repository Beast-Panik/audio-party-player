<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Repositories\UserRepository;

/**
 * Selbstbedienung fuer den eigenen Account (Klick auf den Benutzernamen
 * oben rechts, siehe admin_header.php) - Benutzernamen/Passwort aendern.
 * Verlangt in beiden Faellen das eigene aktuelle Passwort zur Bestaetigung
 * (gleiche Ueberlegung wie bei admin/users.php: eine gekaperte Session
 * ohne Passwort-Kenntnis soll sich hier keinen Dauerzugriff verschaffen
 * koennen). Fehlversuche werden sessiongebunden gedrosselt, aehnlich der
 * Player-Sperre in api/lock.php.
 */
header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();

function json_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail(405, 'Methode nicht erlaubt.');
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!Csrf::check($token)) {
    json_fail(400, 'Ungueltiges Formular. Bitte Seite neu laden.');
}

$repo = new UserRepository();
$me = $repo->findById((int) Auth::userId());
if (!$me) {
    json_fail(401, 'Nicht angemeldet.');
}

$action = $input['action'] ?? '';

// Sessiongebundene Bremse gegen Erraten des aktuellen Passworts durch eine
// gekaperte Session (die selbst nicht ins Passwort eingeweiht ist).
$blockedUntil = (int) ($_SESSION['account_blocked_until'] ?? 0);
if (time() < $blockedUntil) {
    json_fail(429, 'Zu viele Fehlversuche. Bitte kurz warten und erneut versuchen.');
}

$currentPassword = (string) ($input['current_password'] ?? '');
if ($currentPassword === '' || !password_verify($currentPassword, $me['password_hash'])) {
    $attempts = (int) ($_SESSION['account_attempts'] ?? 0) + 1;
    $_SESSION['account_attempts'] = $attempts;
    if ($attempts >= 5) {
        $_SESSION['account_blocked_until'] = time() + 30;
        $_SESSION['account_attempts'] = 0;
        json_fail(429, 'Zu viele Fehlversuche. Bitte 30 Sekunden warten.');
    }
    json_fail(401, 'Aktuelles Passwort ist falsch.');
}
$_SESSION['account_attempts'] = 0;

if ($action === 'update_username') {
    $username = trim((string) ($input['username'] ?? ''));
    if ($username === '') {
        json_fail(400, 'Benutzername darf nicht leer sein.');
    }
    if ($username !== $me['username'] && $repo->usernameExists($username)) {
        json_fail(409, 'Dieser Benutzername existiert bereits.');
    }
    $repo->updateUsername((int) $me['id'], $username);
    $_SESSION['username'] = $username;
    echo json_encode(['ok' => true, 'username' => $username]);
    exit;
}

if ($action === 'update_password') {
    $password = (string) ($input['password'] ?? '');
    $password2 = (string) ($input['password2'] ?? '');
    if (strlen($password) < 8) {
        json_fail(400, 'Neues Passwort muss mindestens 8 Zeichen haben.');
    }
    if ($password !== $password2) {
        json_fail(400, 'Die neuen Passwoerter stimmen nicht ueberein.');
    }
    $repo->updatePassword((int) $me['id'], $password);
    echo json_encode(['ok' => true]);
    exit;
}

json_fail(400, 'Unbekannte Aktion.');
