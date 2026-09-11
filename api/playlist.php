<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\PlayerSession;
use App\Repositories\PlaylistRepository;
use App\Repositories\RequestRepository;
use App\Repositories\SettingRepository;
use App\Repositories\TrackRepository;

header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();

function json_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * Fuer Aktionen, die behaupten, "das hier ist gerade tatsaechlich hoerbar am
 * Laufen" (advance/restore_previous/set_now_playing) - nur die Master-Session
 * spielt wirklich lokales Audio (siehe PlayerSession), eine Slave-Session
 * hat gar kein laufendes <audio>-Element und darf diesen Zustand daher nicht
 * behaupten/veraendern (sonst liesse sich z.B. der oeffentliche Ticker auf
 * request.php/display.php per set_now_playing faelschen, ohne dass dort
 * ueberhaupt etwas spielt). Reine Playlist-Inhaltsaenderungen (add/remove/
 * reorder) sind davon bewusst ausgenommen - die duerfen weiterhin von jeder
 * eingeloggten Session kommen (z.B. der Player-Rolle beim Stoebern in der
 * Bibliothek), da sie nur die geteilte Warteschlange betreffen, nicht die
 * tatsaechliche Wiedergabe.
 */
function requireMasterApi(): void
{
    if (!PlayerSession::isMaster()) {
        http_response_code(403);
        echo json_encode(['error' => 'Nur die aktive Player-Sitzung kann das.']);
        exit;
    }
}

/**
 * Beim Einschalten des Auto-DJ alle aktuell wartenden Gastwuensche direkt
 * in die Playlist uebernehmen - fuer NEU eingehende Wuensche macht Auto-DJ
 * das ohnehin automatisch (siehe api/requests.php Action "create"), sonst
 * blieben bereits vor dem Einschalten eingegangene Wuensche unangetastet
 * in der Wunschliste liegen, obwohl das Einschalten erkennbar signalisiert
 * "jetzt bitte alles automatisch abspielen". Aeltester Wunsch zuerst
 * (faire Reihenfolge), kuerzlich gespielte oder inzwischen aus der
 * Bibliothek entfernte Tracks werden uebersprungen (bleiben als offener
 * Wunsch stehen) statt sie stillschweigend zu verwerfen oder die Sperre
 * zu umgehen.
 */
function acceptAllPendingRequests(PlaylistRepository $playlist, SettingRepository $settings): int
{
    $requestRepo = new RequestRepository();
    $trackRepo = new TrackRepository();
    $lockHours = (int) $settings->get('recent_played_lock_hours', '4');
    $pending = array_reverse($requestRepo->listWithTracks(RequestRepository::STATUS_PENDING, 500));

    $accepted = 0;
    foreach ($pending as $request) {
        $track = $trackRepo->findById((int) $request['track_id']);
        if (!$track || $trackRepo->isLocked($track, $lockHours)) {
            continue;
        }
        $playlist->add((int) $request['track_id'], PlaylistRepository::SOURCE_GUEST, (int) $request['id']);
        $requestRepo->updateStatus((int) $request['id'], RequestRepository::STATUS_APPROVED);
        $accepted++;
    }
    return $accepted;
}

$playlist = new PlaylistRepository();
$settings = new SettingRepository();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $autoDj = $settings->get('auto_dj_enabled', '0') === '1';
    if ($autoDj) {
        $playlist->topUp();
    }
    $rows = $playlist->all();
    echo json_encode([
        'auto_dj' => $autoDj,
        'crossfade_enabled' => $settings->get('crossfade_enabled', '0') === '1',
        'crossfade_seconds' => (int) $settings->get('crossfade_seconds', '3'),
        'ticker_enabled' => $settings->get('ticker_enabled', '0') === '1',
        'countdown_enabled' => $settings->get('countdown_enabled', '0') === '1',
        'master_volume' => (int) $settings->get('master_volume', '100'),
        'pause_fade_out_ms' => (int) $settings->get('pause_fade_out_ms', '300'),
        'pause_fade_in_ms' => (int) $settings->get('pause_fade_in_ms', '300'),
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
                'request_id' => $r['request_id'] !== null ? (int) $r['request_id'] : null,
            ];
        }, $rows),
    ]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Csrf::check($token)) {
        json_fail(400, 'Ungueltiges Formular. Bitte Seite neu laden.');
    }

    $action = $input['action'] ?? '';

    if ($action === 'add') {
        $trackId = (int) ($input['track_id'] ?? 0);
        if (!(new TrackRepository())->findById($trackId)) {
            json_fail(404, 'Song nicht gefunden.');
        }
        $id = $playlist->add($trackId, PlaylistRepository::SOURCE_MANUAL);
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'remove') {
        $playlist->remove((int) ($input['id'] ?? 0));
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reorder') {
        $ids = array_map('intval', (array) ($input['ids'] ?? []));
        if (!empty($ids)) {
            $playlist->reorder($ids);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'advance') {
        // Ein Track ist zu Ende gespielt (oder per Crossfade abgeloest) -
        // als gespielt markieren, aus der Playlist nehmen und bei Bedarf
        // (Auto-DJ) wieder auffuellen.
        requireMasterApi();
        $trackId = (int) ($input['track_id'] ?? 0);
        $playlist->markPlayed($trackId);
        if ($settings->get('auto_dj_enabled', '0') === '1') {
            $playlist->topUp();
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'restore_previous') {
        // Der Player-"Zurueck"-Button springt zu einem bereits durchgespielten
        // Track zurueck (beginCrossfade(prev, true) in app.js) - der wurde
        // beim Vorwaertsspielen per markPlayed() aus der Playlist entfernt und
        // muss jetzt wieder vorn erscheinen, siehe restoreAtFront().
        requireMasterApi();
        $trackId = (int) ($input['track_id'] ?? 0);
        if (!(new TrackRepository())->findById($trackId)) {
            json_fail(404, 'Song nicht gefunden.');
        }
        $source = (string) ($input['source'] ?? PlaylistRepository::SOURCE_MANUAL);
        $validSources = [PlaylistRepository::SOURCE_MANUAL, PlaylistRepository::SOURCE_GUEST, PlaylistRepository::SOURCE_AUTO];
        if (!in_array($source, $validSources, true)) {
            $source = PlaylistRepository::SOURCE_MANUAL;
        }
        $requestId = isset($input['request_id']) && $input['request_id'] !== null ? (int) $input['request_id'] : null;
        $id = $playlist->restoreAtFront($trackId, $source, $requestId);
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'set_now_playing') {
        // Wird vom Player bei jedem Trackwechsel gemeldet, damit die
        // Gaeste-Wunschseite den aktuell laufenden Track im Ticker anzeigen
        // kann (siehe api/now_playing.php).
        requireMasterApi();
        $npTrackId = (int) ($input['track_id'] ?? 0);
        $settings->set('now_playing_title', (string) ($input['title'] ?? ''));
        $settings->set('now_playing_artist', (string) ($input['artist'] ?? ''));
        $settings->set('now_playing_track_id', (string) $npTrackId);
        if ($npTrackId > 0) {
            // Neue Spielinstanz - Herz-Reaktionen (siehe TrackReactionRepository)
            // duerfen fuer diesen Track wieder abgegeben werden.
            (new TrackRepository())->bumpPlaySeq($npTrackId);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'release_lock') {
        // Admin gibt einen kuerzlich gespielten Track vorzeitig wieder fuer
        // Gastwuensche/Auto-DJ frei (siehe "Kuerzlich gespielt"-Liste).
        $trackId = (int) ($input['track_id'] ?? 0);
        if (!(new TrackRepository())->findById($trackId)) {
            json_fail(404, 'Song nicht gefunden.');
        }
        (new TrackRepository())->releaseLock($trackId);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'remote_command') {
        // Fernsteuerung einer Slave-Session (siehe PlayerSession/app.js) -
        // wird nicht hier ausgefuehrt (kein lokales Audio auf dieser Session),
        // sondern nur als Befehl mit fortlaufender Sequenznummer hinterlegt.
        // Die Master-Session holt ihn beim naechsten SSE-Tick ab und fuehrt
        // ihn auf ihrer eigenen, tatsaechlich spielenden Audioquelle aus.
        $command = (string) ($input['command'] ?? '');
        if (!in_array($command, ['next', 'prev'], true)) {
            json_fail(400, 'Unbekannter Befehl.');
        }
        $seq = (int) $settings->get('player_remote_cmd_seq', '0') + 1;
        $settings->set('player_remote_cmd', $command);
        $settings->set('player_remote_cmd_seq', (string) $seq);
        echo json_encode(['ok' => true, 'seq' => $seq]);
        exit;
    }

    if ($action === 'set_auto_dj') {
        $enabled = !empty($input['enabled']);
        $wasEnabled = $settings->get('auto_dj_enabled', '0') === '1';
        $settings->set('auto_dj_enabled', $enabled ? '1' : '0');

        $accepted = 0;
        if ($enabled && !$wasEnabled) {
            $accepted = acceptAllPendingRequests($playlist, $settings);
        }

        // Kein topUp() hier - der direkt danach vom Frontend ausgeloeste GET
        // auf diesen Endpunkt fuellt bei Bedarf ohnehin auf (sonst wuerde
        // doppelt aufgefuellt: einmal hier, einmal beim folgenden GET).
        echo json_encode(['ok' => true, 'auto_dj' => $enabled, 'accepted' => $accepted]);
        exit;
    }

    json_fail(400, 'Unbekannte Aktion.');
}

json_fail(405, 'Methode nicht erlaubt.');
