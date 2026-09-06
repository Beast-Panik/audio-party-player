<?php

require __DIR__ . '/../bootstrap.php';

use App\Repositories\TrackRepository;

// Oeffentlich (kein Login) - Cover-Bilder werden auch auf der Gaeste-
// Wunschseite gebraucht und sind keine sensiblen Daten.
$trackId = (int) ($_GET['id'] ?? 0);
$track = (new TrackRepository())->findById($trackId);

if (!$track || empty($track['cover_ext'])) {
    http_response_code(404);
    exit;
}

$path = dirname(__DIR__) . '/data/covers/' . $trackId . '.' . $track['cover_ext'];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = $track['cover_ext'] === 'png' ? 'image/png' : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800');
readfile($path);
