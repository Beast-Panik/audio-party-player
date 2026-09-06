<?php

require __DIR__ . '/../bootstrap.php';

use App\Csrf;
use App\GuestIdentity;
use App\Repositories\TrackReactionRepository;
use App\Repositories\TrackRepository;

// Oeffentlich (kein Login) - leichtgewichtige Gaeste-Reaktion (Herz-Button)
// auf den aktuell laufenden Track, siehe TrackReactionRepository.
header('Content-Type: application/json; charset=utf-8');

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

if (($input['action'] ?? '') !== 'react') {
    json_fail(400, 'Unbekannte Aktion.');
}

$trackId = (int) ($input['track_id'] ?? 0);
if (!(new TrackRepository())->findById($trackId)) {
    json_fail(404, 'Song nicht gefunden.');
}

$repo = new TrackReactionRepository();
$repo->add($trackId, GuestIdentity::id());
echo json_encode(['ok' => true, 'count' => $repo->countForTrack($trackId)]);
