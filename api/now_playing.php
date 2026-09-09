<?php

require __DIR__ . '/../bootstrap.php';

use App\Repositories\PlaylistRepository;
use App\Repositories\SettingRepository;
use App\Repositories\TrackReactionRepository;

// Oeffentlich (kein Login) - fuer Ticker + Herz-Reaktion auf der Gaeste-Wunschseite
// sowie Ticker + "Als naechstes" auf der Anzeige-Seite (display.php).
header('Content-Type: application/json; charset=utf-8');

// Session-Lock sofort freigeben (siehe dieselbe Massnahme in api/events.php).
session_write_close();

$settings = new SettingRepository();
$trackId = (int) $settings->get('now_playing_track_id', '0');
$next = (new PlaylistRepository())->nextAfter($trackId ?: null);

echo json_encode([
    'title' => $settings->get('now_playing_title', '') ?: null,
    'artist' => $settings->get('now_playing_artist', '') ?: null,
    'track_id' => $trackId ?: null,
    'reaction_count' => $trackId ? (new TrackReactionRepository())->countForTrack($trackId) : 0,
    'next' => $next ? [
        'title' => $next['title'],
        'artist' => $next['artist'],
        'guest_name' => $next['guest_name'],
    ] : null,
]);
