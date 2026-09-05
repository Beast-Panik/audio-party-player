<?php

require __DIR__ . '/../bootstrap.php';

use App\Config;
use App\QrCode;
use App\Repositories\SettingRepository;

$settings = new SettingRepository();
$override = $settings->get('request_url_override', '');
$requestUrl = ($override ?: rtrim(Config::get('app_url', ''), '/')) . app_url('request.php');

header('Content-Type: image/svg+xml');
header('Cache-Control: no-store');

try {
    echo QrCode::svg($requestUrl, 8, 4);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR-Code konnte nicht erzeugt werden: ' . $e->getMessage();
}
