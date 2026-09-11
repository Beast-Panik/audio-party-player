<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\PlayerSession;
use App\Repositories\PlaylistRepository;
use App\Repositories\RequestRepository;
use App\Repositories\SettingRepository;
use App\Repositories\TrackReactionRepository;

/**
 * Server-Sent-Events-Stream fuer Echtzeit-Updates (Playlist/Wunschliste/
 * Ticker/Reaktionen) statt separater 8-10s-Polling-Requests. Bewusst
 * kurzlebig (~6-8s) mit anschliessendem sauberem Verbindungsende statt einer
 * dauerhaft offenen Verbindung - viele Shared-Hoster limitieren die
 * PHP-Ausfuehrungszeit/Prozesszahl pro Client streng (siehe README). Der
 * `EventSource` im Browser reconnectet danach automatisch von selbst (nach
 * der unten gesetzten "retry"-Pause).
 *
 * Die Zykluslaenge war frueher 12 Iterationen (~24s) - das hielt auf
 * Shared-Hosting mit wenigen PHP-Arbeitsprozessen (Apache/PHP-FPM, oft nur
 * eine Handvoll gleichzeitig) durchgehend einen ganzen Prozess fuer JEDE
 * offene Admin-Sitzung belegt. Bei mehr Tracks/Cover-Bildern (siehe Upload-
 * Funktion) reichte der Rest-Pool dann nicht mehr aus: Cover-Bilder,
 * Seitenwechsel und Playlist-Aktionen mussten auf einen freien Prozess
 * warten und wirkten "haengend" (siehe Nutzer-Report, mit einem lokalen
 * Lasttest reproduziert: 10 parallele Cover-Anfragen brauchten mit der
 * 24s-Verbindung ueber 20s, mit einer 6-8s-Verbindung unter 0,1s). Kuerzere
 * Zyklen lassen den Prozess deutlich oefter wieder frei werden, auf Kosten
 * etwas haeufigerer (aber sehr billiger) Neuverbindungen.
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

/**
 * Playlist/Auto-DJ/Crossfade + Wunschliste + Reaktionszaehler fuer den
 * Admin-Player. $refreshMaster nur beim ersten Zyklus true (siehe Aufruf
 * unten) - sonst wuerde eine schon laengere Zeit offene Verbindung ueber
 * PlayerSession::touch() bei jedem der bis zu 4 Zyklen erneut die
 * Master-Rolle beanspruchen, auch noch Sekunden nachdem sich genau diese
 * Sitzung schon abgemeldet hat (releaseIfMaster() lief dann laengst, aber
 * der Stream bemerkt den Logout einer PARALLELEN Anfrage nicht - PHP kann
 * die Session hier nicht mehr neu einlesen, da fuer den SSE-Stream schon
 * Header/Ausgabe gesendet wurden). Ein einmaliger Refresh pro Verbindung
 * reicht: der naechste automatische Reconnect (alle ~6-8s, siehe unten)
 * prueft ueber Auth::requireLoginApi() ganz am Anfang zuverlaessig neu, ob
 * ueberhaupt noch eine gueltige Sitzung vorliegt (Nutzer-Report).
 */
function events_payload_admin(bool $refreshMaster): array
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
        // Master/Slave (siehe PlayerSession): is_master haelt den Heartbeat
        // dieser Session frisch bzw. lasst sie die Rolle uebernehmen, falls
        // sie gerade frei/verwaist ist. remote_cmd_seq/remote_cmd transportieren
        // Fernsteuerungs-Befehle einer Slave-Session (Skip vor/zurueck) zum
        // Master, der sie als einziger tatsaechlich lokal ausfuehrt (dort laeuft
        // die eigentliche Audiowiedergabe) - siehe api/playlist.php Action
        // remote_command und initTrackPlayer() in app.js.
        'is_master' => $refreshMaster ? PlayerSession::touch() : PlayerSession::isMaster(),
        'remote_cmd_seq' => (int) $settings->get('player_remote_cmd_seq', '0'),
        'remote_cmd' => $settings->get('player_remote_cmd', '') ?: null,
        'reaction_count' => $trackId ? (new TrackReactionRepository())->countForTrack($trackId) : 0,
        'auto_dj' => $settings->get('auto_dj_enabled', '0') === '1',
        'crossfade_enabled' => $settings->get('crossfade_enabled', '0') === '1',
        'crossfade_seconds' => (int) $settings->get('crossfade_seconds', '3'),
        'ticker_enabled' => $settings->get('ticker_enabled', '0') === '1',
        'countdown_enabled' => $settings->get('countdown_enabled', '0') === '1',
        'master_volume' => (int) $settings->get('master_volume', '100'),
        'pause_fade_out_ms' => (int) $settings->get('pause_fade_out_ms', '300'),
        'pause_fade_in_ms' => (int) $settings->get('pause_fade_in_ms', '300'),
        // Gleiches Mapping wie api/playlist.php (GET).
        'items' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'track_id' => (int) $r['track_id'],
                'title' => $r['title'],
                'artist' => $r['artist'],
                'album' => $r['album'],
                'duration_seconds' => $r['duration_seconds'] !== null ? (int) $r['duration_seconds'] : null,
                'codec' => $r['codec'],
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

for ($i = 0; $i < 4; $i++) {
    if (connection_aborted()) {
        break;
    }
    $payload = $scope === 'admin' ? events_payload_admin($i === 0) : events_payload_guest();
    echo 'data: ' . json_encode($payload) . "\n\n";
    flush();
    if ($i < 3) {
        sleep(2);
    }
}
