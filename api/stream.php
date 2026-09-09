<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Repositories\LibraryRepository;
use App\Repositories\TrackRepository;

// Wiedergabe ist bewusst Admin-only (siehe Architekturentscheidung: nur die
// Anlage/der DJ hoert ueber den Player, Gaeste wuenschen nur).
Auth::requireLogin();

// Session-Lock sofort freigeben (siehe dieselbe Massnahme in api/events.php):
// dieses Skript haelt die Verbindung ueber die komplette Uebertragungsdauer
// des Tracks offen (Byte-fuer-Byte-Streaming weiter unten) - ohne das wuerde
// die PHP-Standard-Session-Sperre fuer die gesamte Abspielzeit jede andere
// parallele Anfrage derselben Sitzung blockieren (Navigation, API-Aufrufe).
session_write_close();

$trackId = (int) ($_GET['id'] ?? 0);
$track = (new TrackRepository())->findById($trackId);
if (!$track) {
    http_response_code(404);
    exit('Track nicht gefunden.');
}

$library = (new LibraryRepository())->findById((int) $track['library_id']);
if (!$library) {
    http_response_code(404);
    exit('Bibliothek nicht gefunden.');
}

$root = realpath($library['path']);
$fullPath = $root !== false ? realpath($root . '/' . $track['relpath']) : false;
// str_starts_with($fullPath, $root) allein wuerde z.B. "/data/music-2" faelschlich
// als "innerhalb von" "/data/music" durchgehen (kein Trenner an der Grenze) -
// mit Trailing-Slash auf beiden Seiten vergleichen schliesst das aus.
$inside = $fullPath !== false && $root !== false && ($fullPath === $root || str_starts_with($fullPath, rtrim($root, '/\\') . DIRECTORY_SEPARATOR));

if ($root === false || $fullPath === false || !$inside || !is_file($fullPath)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$mime = $track['codec'] === 'flac' ? 'audio/flac' : 'audio/mpeg';
$size = filesize($fullPath);
$start = 0;
$end = $size - 1;

header('Accept-Ranges: bytes');
header('Content-Type: ' . $mime);

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') {
        $start = (int) $m[1];
    }
    if ($m[2] !== '') {
        $end = (int) $m[2];
    }
    $end = min($end, $size - 1);
    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
} else {
    http_response_code(200);
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

$fh = fopen($fullPath, 'rb');
if ($fh === false) {
    http_response_code(500);
    exit('Datei konnte nicht geoeffnet werden.');
}

fseek($fh, $start);
$bufferSize = 8192;
$remaining = $length;
while ($remaining > 0 && !feof($fh)) {
    $chunk = fread($fh, min($bufferSize, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($fh);
