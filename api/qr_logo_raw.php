<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Repositories\SettingRepository;

/**
 * Liefert die aktuell gespeicherte (bereits zugeschnittene) QR-Code-Logo-
 * Datei roh aus - nur fuer die Live-Vorschau in admin/settings.php (siehe
 * initQrLogoPreview() in app.js), die daraus per <canvas> selbst die
 * Vorschau zusammensetzt. Admin-only, da das Logo sonst nirgends als eigene
 * URL oeffentlich erreichbar ist (im echten QR-Code wird es nur inline als
 * data-URI eingebettet, siehe api/qr.php).
 */
Auth::requireLoginApi();

// Session-Lock sofort freigeben (siehe dieselbe Massnahme in api/events.php).
session_write_close();

$settings = new SettingRepository();
$logoExt = $settings->get('qr_logo_ext', '');
$logoPath = $logoExt !== '' ? dirname(__DIR__) . '/data/qr_logo.' . $logoExt : null;

if ($logoExt === '' || $logoPath === null || !is_file($logoPath)) {
    http_response_code(404);
    exit;
}

$mime = match ($logoExt) {
    'png' => 'image/png',
    'webp' => 'image/webp',
    default => 'image/jpeg',
};

header('Content-Type: ' . $mime);
header('Cache-Control: no-store');
readfile($logoPath);
