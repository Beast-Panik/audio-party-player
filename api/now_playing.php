<?php

require __DIR__ . '/../bootstrap.php';

use App\Repositories\SettingRepository;

// Oeffentlich (kein Login) - fuer den Ticker auf der Gaeste-Wunschseite.
header('Content-Type: application/json; charset=utf-8');

$settings = new SettingRepository();
echo json_encode([
    'title' => $settings->get('now_playing_title', '') ?: null,
    'artist' => $settings->get('now_playing_artist', '') ?: null,
]);
