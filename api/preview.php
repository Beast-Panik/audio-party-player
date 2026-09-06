<?php

require __DIR__ . '/../bootstrap.php';

use App\Repositories\LibraryRepository;
use App\Repositories\SettingRepository;
use App\Repositories\TrackRepository;

// Oeffentlich (kein Login) - kurzer Vorhoer-Ausschnitt fuer Gaeste auf der
// Wunschseite. Liefert IMMER nur einen festen Ausschnitt aus der Mitte des
// Tracks aus (Range-Header vom Client werden bewusst ignoriert), damit sich
// darueber nicht die komplette Datei herunterladen laesst.
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

$size = filesize($fullPath);
$previewSeconds = max(1, (int) (new SettingRepository())->get('preview_seconds', '20'));
$durationSeconds = (int) ($track['duration_seconds'] ?? 0);

// Durchschnittliche Byte-Rate ueber die ganze Datei - robust genug fuer
// einen kurzen Vorhoer-Ausschnitt, unabhaengig von CBR/VBR. Ohne bekannte
// Dauer wird pauschal von 128 kbit/s ausgegangen.
$avgBytesPerSecond = $durationSeconds > 0 ? $size / $durationSeconds : (128000 / 8);

$clipBytes = min($size, (int) round($avgBytesPerSecond * $previewSeconds));
$mid = intdiv($size, 2);
$start = max(0, $mid - intdiv($clipBytes, 2));
$end = min($size - 1, $start + $clipBytes - 1);
$start = max(0, $end - $clipBytes + 1);

$mime = $track['codec'] === 'flac' ? 'audio/flac' : 'audio/mpeg';
$length = $end - $start + 1;

header('Content-Type: ' . $mime);
header('Content-Length: ' . $length);
header('Cache-Control: no-store');

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
