<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\PartyReset;
use App\Repositories\SettingRepository;

/**
 * Schaltet die Party zwischen "Live" (Wunsch-/Anzeige-Seite oeffentlich
 * erreichbar) und "Offline" (beide zeigen nur noch eine leere Seite mit
 * "Offline", siehe request.php/display.php + templates/offline.php) um.
 * Jeder Wechsel - in beide Richtungen - setzt dabei die komplette
 * Gaeste-/Wunschliste zurueck (siehe PartyReset), da beide Uebergaenge als
 * Grenze zu einer neuen Party gedacht sind.
 */
header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!Csrf::check($token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges Formular. Bitte Seite neu laden.']);
    exit;
}

$live = !empty($input['live']);
$settings = new SettingRepository();
$settings->set('app_live', $live ? '1' : '0');
PartyReset::resetGuestData();

echo json_encode(['ok' => true, 'live' => $live]);
