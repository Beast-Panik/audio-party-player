<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;

/**
 * Listet Unterordner eines Server-Verzeichnisses fuer den Ordner-Picker in
 * der Bibliotheksverwaltung auf (admin-only, rein lesend).
 */

header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();

function json_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$path = $_GET['path'] ?? '';
$path = $path === '' ? '/' : $path;

$real = realpath($path);
if ($real === false || !is_dir($real) || !is_readable($real)) {
    json_fail(404, 'Verzeichnis nicht gefunden oder nicht lesbar.');
}
$real = rtrim($real, '/\\');
if ($real === '') {
    $real = '/';
}

$entries = @scandir($real);
if ($entries === false) {
    json_fail(403, 'Verzeichnis kann nicht gelesen werden.');
}

$dirs = [];
foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
        continue;
    }
    $full = ($real === '/' ? '' : $real) . '/' . $entry;
    if (is_dir($full) && is_readable($full)) {
        $dirs[] = $entry;
    }
}
sort($dirs, SORT_FLAG_CASE | SORT_STRING);

$parent = dirname($real);
if ($parent === $real) {
    $parent = null;
}

echo json_encode([
    'path' => $real,
    'parent' => $parent,
    'dirs' => $dirs,
]);
