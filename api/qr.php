<?php

require __DIR__ . '/../bootstrap.php';

use App\Config;
use App\QrCode;
use App\Repositories\SettingRepository;

$settings = new SettingRepository();
$override = $settings->get('request_url_override', '');
$requestUrl = ($override ?: rtrim(Config::get('app_url', ''), '/')) . app_url('request.php');

$logoExt = $settings->get('qr_logo_ext', '');
$logoDataUri = null;
if ($logoExt !== '') {
    $logoPath = dirname(__DIR__) . '/data/qr_logo.' . $logoExt;
    if (is_file($logoPath)) {
        $mime = match ($logoExt) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }
}

// Die Live-Vorschau in den Einstellungen (Schieberegler fuer Groesse/Rand)
// kann die gespeicherten Werte per Query-Param ueberschreiben, bevor sie
// gespeichert sind - sonst gelten die gespeicherten Werte. Harmlos auch
// oeffentlich aufrufbar (reine Anzeige-Variante derselben oeffentlichen
// URL), daher keine Auth-Pruefung noetig - Grenzen werden trotzdem serverseitig
// erzwungen (siehe QrCode::svg()).
$logoSizePercent = isset($_GET['logo_size_percent'])
    ? (int) $_GET['logo_size_percent']
    : (int) $settings->get('qr_logo_size_percent', '20');
$logoBorderPx = isset($_GET['logo_border_px'])
    ? (int) $_GET['logo_border_px']
    : (int) $settings->get('qr_logo_border_px', '6');

header('Content-Type: image/svg+xml');
header('Cache-Control: no-store');

try {
    echo QrCode::svg($requestUrl, 8, 4, '#000000', '#ffffff', QrCode::ECC_H, $logoDataUri, $logoSizePercent, $logoBorderPx);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR-Code konnte nicht erzeugt werden: ' . $e->getMessage();
}
