<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\PlayerSession;

/**
 * Erzwungene Master-Uebernahme fuer Admins, die durch eine andere, dauerhaft
 * aktive Session (z.B. eine offen gelassene Player-Rolle) von allen
 * admin/*-Seiten ausgesperrt sind (siehe PlayerSession::requireMasterOrRedirect()
 * und PlayerSession::forceTakeover()). Absichtlich Admin-only - die
 * eingeschraenkte Player-Rolle soll einem tatsaechlich spielenden Admin
 * nicht per Klick die Kontrolle entreissen koennen.
 */
header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();
Auth::requireAdminApi();

function json_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!Csrf::check($token)) {
    json_fail(400, 'Ungueltiges Formular. Bitte Seite neu laden.');
}

if (($input['action'] ?? '') !== 'take_master') {
    json_fail(400, 'Unbekannte Aktion.');
}

PlayerSession::forceTakeover();
echo json_encode(['ok' => true]);
