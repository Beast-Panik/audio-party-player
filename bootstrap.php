<?php

declare(strict_types=1);

// Einfacher Autoloader ohne Composer: App\Foo\Bar -> src/Foo/Bar.php
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = substr($class, strlen('App\\'));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

/**
 * Ermittelt den URL-Pfad, unter dem das Projekt-Wurzelverzeichnis erreichbar
 * ist - auch wenn die App nicht im Domain-Root, sondern in einem
 * Unterordner liegt (z.B. https://host.tld/party/). Wird von Seiten in
 * admin/ und api/ aus aufgerufen, daher wird eine bekannte Unterordner-Ebene
 * abgeschnitten.
 */
function app_base_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    foreach (['/admin', '/api'] as $sub) {
        if ($dir === $sub || str_ends_with($dir, $sub)) {
            $dir = substr($dir, 0, -strlen($sub));
            break;
        }
    }
    return rtrim($dir, '/');
}

define('APP_BASE_PATH', app_base_path());

// Wird im Sidebar-Footer angezeigt - bei jedem Release manuell auf den neuen
// Tag-Namen anpassen (siehe README/Release-Workflow).
define('APP_VERSION', 'v0.13.0-alpha');

/**
 * Baut eine root-relative URL innerhalb der App, egal in welchem Unterordner
 * sie liegt. Fuer assets/ haengt sie zusaetzlich die App-Version als
 * Cache-Buster an - sonst liefern Browser (und manche Hoster/CDNs) nach
 * einem Update oft noch die alte, gecachte CSS/JS-Datei aus.
 */
function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $url = APP_BASE_PATH . '/' . $path;
    if (strpos($path, 'assets/') === 0) {
        $url .= '?v=' . urlencode(APP_VERSION);
    }
    return $url;
}

use App\Auth;
use App\Config;
use App\Database;

if (!Config::isInstalled()) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== 'install.php') {
        header('Location: ' . app_url('install.php'));
        exit;
    }
    return;
}

error_reporting(E_ALL);
ini_set('display_errors', Config::get('debug', false) ? '1' : '0');
date_default_timezone_set('Europe/Berlin');

$sessionName = Config::get('session_name', 'app_sess');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($sessionName);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        // Bewusst auf die ganze App-Basis (nicht auf das Unterverzeichnis
        // des jeweiligen Scripts) beschraenkt - sonst faellt die Session
        // zwischen root-, admin/- und api/-Seiten auseinander.
        'path' => (APP_BASE_PATH === '' ? '/' : APP_BASE_PATH . '/'),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Nach einem Datei-Update kann die Datenbank noch auf dem alten Stand
// sein. Ein angemeldeter Admin wird dann automatisch zum Installer
// geschickt, der das Update per Klick nachholt (siehe install.php,
// Database::SCHEMA_VERSION). API-Endpunkte werden bewusst ausgenommen,
// da dort ein Redirect nur die JSON-Antworten des laufenden Players
// kaputt machen wuerde, ohne dass sich am Schema etwas aendert.
$scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isApiRequest = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
if (!$isApiRequest && !in_array($scriptName, ['install.php', 'logout.php'], true) && Auth::isLoggedIn() && Database::needsUpdate()) {
    header('Location: ' . app_url('install.php'));
    exit;
}
