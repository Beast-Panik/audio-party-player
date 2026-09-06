<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Repositories\PlaylistRepository;
use App\Repositories\RequestRepository;
use App\Repositories\SettingRepository;
use App\Repositories\TrackReactionRepository;

/**
 * Server-Sent-Events-Stream fuer Echtzeit-Updates (Playlist/Wunschliste/
 * Ticker/Reaktionen) statt separater 8-10s-Polling-Requests. Bewusst
 * kurzlebig (~24s) mit anschliessendem sauberem Verbindungsende statt einer
 * dauerhaft offenen Verbindung - viele Shared-Hoster limitieren die
 * PHP-Ausfuehrungszeit/Prozesszahl pro Client streng (siehe README). Der
 * `EventSource` im Browser reconnectet danach automatisch von selbst.
 */

$scope = $_GET['scope'] ?? 'guest';
if (!in_array($scope, ['admin', 'guest'], true)) {
    http_response_code(400);
    exit;
}

if ($scope === 'admin') {
    Auth::requireLoginApi();
}

// Session-Lock sofort freigeben: sonst blockiert der offene Stream fuer
// seine gesamte Laufzeit alle anderen parallelen Requests derselben
// Session (z.B. ein Klick auf "Annehmen", waehrend der Stream laeuft).
session_write_close();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // schadet auf Apache nicht, hilft falls doch mal hinter nginx betrieben
while (ob_get_level() > 0) {
    ob_end_flush();
}

/** Playlist/Auto-DJ/Crossfade + Wunschliste + Reaktionszaehler fuer den Admin-Player. */
function events_payload_admin(): array
{
    $settings = new SettingRepository();
    $trackId = (int) $settings->get('now_playing_track_id', '0');
    $playlist = new PlaylistRepository();

    // Gleiches Verhalten wie bisher api/playlist.php (GET): bei aktivem
    // Auto-DJ pro Tick auffuellen, falls die Playlist z.B. durch manuelles
    // Entfernen unter das Mindest-Soll gefallen ist (billiger No-Op, wenn
    // schon genug Eintraege da sind - siehe PlaylistRepository::topUp()).
    if ($settings->get('auto_dj_enabled', '0') === '1') {
        $playlist->topUp();
    }

    return [
        'now_playing' => [
            'track_id' => $trackId ?: null,
            'title' => $settings->get('now_playing_title', '') ?: null,
            'artist' => $settings->get('now_playing_artist', '') ?: null,
        ],
        'reaction_count' => $trackId ? (new TrackReactionRepository())->countForTrack($trackId) : 0,
        'auto_dj' => $settings->get('auto_dj_enabled', '0') === '1',
        'crossfade_enabled' => $settings->get('crossfade_enabled', '0') === '1',
        'crossfade_seconds' => (int) $settings->get('crossfade_seconds', '3'),
        'ticker_enabled' => $settings->get('ticker_enabled', '0') === '1',
        'countdown_enabled' => $settings->get('countdown_enabled', '0') === '1',
        // Gleiches Mapping wie api/playlist.php (GET).
        'items' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'track_id' => (int) $r['track_id'],
                'title' => $r['title'],
                'artist' => $r['artist'],
                'album' => $r['album'],
                'duration_seconds' => $r['duration_seconds'] !== null ? (int) $r['duration_seconds'] : null,
                'source' => $r['source'],
                'guest_name' => $r['guest_name'],
            ];
        }, $playlist->all()),
        // Gleiches Mapping wie api/requests.php (GET status=pending) - treibt
        // die Wunschliste auf player.php (#queue-list).
        'requests' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'track_id' => (int) $r['track_id'],
                'title' => $r['title'],
                'artist' => $r['artist'],
                'album' => $r['album'],
                'duration_seconds' => $r['duration_seconds'] !== null ? (int) $r['duration_seconds'] : null,
                'guest_name' => $r['guest_name'],
                'status' => $r['status'],
                'created_at' => $r['created_at'],
            ];
        }, (new RequestRepository())->listWithTracks('pending', 200)),
    ];
}

/** Ticker + Wunsch-Feed + Reaktionszaehler + "Als naechstes" fuer Gaeste-Wunschseite und Anzeige-Seite. */
function events_payload_guest(): array
{
    $settings = new SettingRepository();
    $trackId = (int) $settings->get('now_playing_track_id', '0');
    $next = (new PlaylistRepository())->nextAfter($trackId ?: null);

    return [
        'now_playing' => [
            'track_id' => $trackId ?: null,
            'title' => $settings->get('now_playing_title', '') ?: null,
            'artist' => $settings->get('now_playing_artist', '') ?: null,
        ],
        'next' => $next ? [
            'title' => $next['title'],
            'artist' => $next['artist'],
            'guest_name' => $next['guest_name'],
        ] : null,
        'reaction_count' => $trackId ? (new TrackReactionRepository())->countForTrack($trackId) : 0,
        // Gleiches Mapping wie api/requests.php (GET status=feed).
        'queue' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'artist' => $r['artist'],
                'guest_name' => $r['guest_name'],
                'status' => $r['status'],
            ];
        }, (new RequestRepository())->listRecentForGuestFeed(60)),
    ];
}

echo "retry: 1000\n\n";
flush();

for ($i = 0; $i < 12; $i++) {
    if (connection_aborted()) {
        break;
    }
    $payload = $scope === 'admin' ? events_payload_admin() : events_payload_guest();
    echo 'data: ' . json_encode($payload) . "\n\n";
    flush();
    if ($i < 11) {
        sleep(2);
    }
}
