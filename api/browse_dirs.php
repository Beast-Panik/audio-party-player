<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;

/**
 * Listet Unterordner eines Server-Verzeichnisses fuer den Ordner-Picker in
 * der Bibliotheksverwaltung auf (admin-only, rein lesend).
 */

header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();

// Session-Lock sofort freigeben (siehe dieselbe Massnahme in api/events.php):
// scandir() ueber ggf. grosse Verzeichnisse kann spuerbar dauern.
session_write_close();

function json_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * Erlaubte Wurzelverzeichnisse laut open_basedir (falls vom Hoster gesetzt).
 * Auf Shared-Hosting ist der PHP-Zugriff meist auf das eigene Home-Verzeichnis
 * beschraenkt - ohne das zu beruecksichtigen wuerde der Picker beim Start bei
 * "/" sofort mit "nicht lesbar" fehlschlagen und leer bleiben.
 *
 * @return string[]
 */
function allowedRoots(): array
{
    $raw = ini_get('open_basedir');
    if ($raw === false || $raw === '') {
        return [];
    }
    $roots = [];
    foreach (explode(PATH_SEPARATOR, $raw) as $part) {
        $part = rtrim(trim($part), '/\\');
        if ($part === '') {
            continue;
        }
        $real = realpath($part);
        if ($real !== false) {
            $roots[] = $real;
        }
    }
    return $roots;
}

function withinAllowedRoots(string $path, array $roots): bool
{
    if (!$roots) {
        return true;
    }
    foreach ($roots as $root) {
        if ($path === $root || strpos($path . '/', $root . '/') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Startpunkt fuer den Picker: bevorzugt das App-Wurzelverzeichnis (in dem
 * index.php liegt) - dort ist der Zugriff garantiert erlaubt, da die App
 * selbst von dort laeuft. Erst wenn das aus irgendeinem Grund nicht lesbar
 * ist, wird auf die open_basedir-Wurzeln bzw. "/" zurueckgefallen.
 */
function defaultStartPath(array $roots): string
{
    $appRoot = realpath(__DIR__ . '/..');
    if ($appRoot !== false && is_dir($appRoot) && is_readable($appRoot) && withinAllowedRoots($appRoot, $roots)) {
        return $appRoot;
    }
    foreach ($roots as $root) {
        if (is_dir($root) && is_readable($root)) {
            return $root;
        }
    }
    return '/';
}

$roots = allowedRoots();
$path = $_GET['path'] ?? '';
$path = $path === '' ? defaultStartPath($roots) : $path;

$real = realpath($path);
if ($real === false || !is_dir($real) || !is_readable($real) || !withinAllowedRoots($real, $roots)) {
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
if ($parent === $real || !withinAllowedRoots($parent, $roots)) {
    $parent = null;
}

echo json_encode([
    'path' => $real,
    'parent' => $parent,
    'dirs' => $dirs,
]);
