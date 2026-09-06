<?php

require __DIR__ . '/../bootstrap.php';

use App\Config;
use App\QrCode;
use App\Repositories\SettingRepository;

$settings = new SettingRepository();
$override = $settings->get('request_url_override', '');
$requestUrl = ($override ?: rtrim(Config::get('app_url', ''), '/')) . app_url('request.php');

$logoExt = $settings->get('qr_logo_ext', '');
$logoPath = null;
// Fuer die client-seitige Live-Vorschau in den Einstellungen (siehe
// initQrLogoPreview() in app.js): liefert den reinen QR-Code ohne Logo als
// Basis-Bild, auf das die Vorschau das (evtl. noch nicht gespeicherte)
// Logo per <canvas> selbst zeichnet.
$noLogo = isset($_GET['no_logo']);
if (!$noLogo && $logoExt !== '') {
    $candidate = dirname(__DIR__) . '/data/qr_logo.' . $logoExt;
    if (is_file($candidate)) {
        $logoPath = $candidate;
    }
}

$logoSizePercent = (int) $settings->get('qr_logo_size_percent', '20');
$logoBorderPx = (int) $settings->get('qr_logo_border_px', '6');

header('Content-Type: image/svg+xml');
header('Cache-Control: no-store');

try {
    echo QrCode::svg($requestUrl, 8, 4, '#000000', '#ffffff', QrCode::ECC_H, $logoPath, $logoExt, $logoSizePercent, $logoBorderPx);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR-Code konnte nicht erzeugt werden: ' . $e->getMessage();
}
