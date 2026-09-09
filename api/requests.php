<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Database;
use App\GuestIdentity;
use App\Repositories\GuestProfileRepository;
use App\Repositories\PlaylistRepository;
use App\Repositories\RequestRepository;
use App\Repositories\SettingRepository;
use App\Repositories\TrackRepository;
use App\Util;

header('Content-Type: application/json; charset=utf-8');

function json_fail(int $code, string $message, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge(['error' => $message], $extra));
    exit;
}

/** Kontingent-Infos fuer die Begruessung/den Countdown auf der Gaeste-Seite. */
function guestLimitInfo(string $guestToken, RequestRepository $repo, SettingRepository $settings): array
{
    $limitCount = (int) $settings->get('guest_limit_count', '3');
    $limitMinutes = (int) $settings->get('guest_limit_minutes', '60');
    $used = $limitCount > 0 ? $repo->countRecentByGuestToken($guestToken, $limitMinutes) : 0;
    $waitSeconds = 0;
    if ($used > 0) {
        $oldest = $repo->oldestRecentByGuestToken($guestToken, $limitMinutes);
        $waitSeconds = $oldest !== null ? max(0, (strtotime($oldest) + $limitMinutes * 60) - time()) : 0;
    }
    return [
        'limit_count' => $limitCount,
        'limit_minutes' => $limitMinutes,
        'used' => $used,
        'remaining' => $limitCount > 0 ? max(0, $limitCount - $used) : null,
        'wait_seconds' => $waitSeconds,
    ];
}

$repo = new RequestRepository();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $status = $_GET['status'] ?? null;

    if ($status === 'guests') {
        // Admin-Uebersicht: alle Gaeste mit (noch) gueltigem Namens-Lock,
        // mit verbleibendem Wunsch-Kontingent im aktuellen Zeitfenster
        // (siehe admin/requests.php) - bleibt sichtbar, solange der Name gilt.
        Auth::requireLoginApi();
        $settings = new SettingRepository();
        $limitCount = (int) $settings->get('guest_limit_count', '3');
        $limitMinutes = (int) $settings->get('guest_limit_minutes', '60');
        $rows = $repo->listGuestsSummary(GuestProfileRepository::NAME_LOCK_HOURS, $limitMinutes);
        echo json_encode(['guests' => array_map(static function (array $g) use ($limitCount, $limitMinutes): array {
            $used = (int) $g['used'];
            $waitSeconds = $g['oldest_created_at']
                ? max(0, (strtotime($g['oldest_created_at']) + $limitMinutes * 60) - time())
                : 0;
            return [
                'guest_token' => $g['guest_token'],
                'name' => $g['name'] ?: '-',
                'used' => $used,
                'remaining' => $limitCount > 0 ? max(0, $limitCount - $used) : null,
                'wait_seconds' => $waitSeconds,
            ];
        }, $rows)]);
        exit;
    }

    if ($status === 'guest_history') {
        // Admin-Detailansicht: kompletter Wunsch-Verlauf eines einzelnen
        // Gasts (Klick auf den Namen in der Gaeste-Uebersicht).
        Auth::requireLoginApi();
        $guestToken = (string) ($_GET['guest_token'] ?? '');
        if ($guestToken === '') {
            json_fail(400, 'Kein Gast angegeben.');
        }
        $rows = $repo->listByGuestToken($guestToken);
        echo json_encode(['requests' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'artist' => $r['artist'],
                'status' => $r['status'],
                'created_at' => $r['created_at'],
            ];
        }, $rows)]);
        exit;
    }

    // Wunschliste anzeigen - fuer die Party-Anzeige/Wunsch-Seite oeffentlich
    // lesbar (keine sensiblen Daten: nur Songtitel + optionaler Gastname).
    if ($status !== null && !in_array($status, ['pending', 'approved', 'played', 'rejected', 'upcoming', 'feed'], true)) {
        json_fail(400, 'Ungueltiger Status.');
    }
    if ($status === 'feed') {
        $rows = $repo->listRecentForGuestFeed(60);
    } elseif ($status === 'upcoming') {
        $rows = $repo->listUpcomingWithTracks(100);
    } else {
        $rows = $repo->listWithTracks($status, 100);
    }
    echo json_encode(['requests' => array_map(static function (array $r): array {
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
    }, $rows)]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $input['action'] ?? 'create';

    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Csrf::check($token)) {
        json_fail(400, 'Ungueltiges Formular. Bitte Seite neu laden.');
    }

    // Party ist "offline" geschaltet - die Gaeste-Aktionen (Name setzen,
    // Wunsch einreichen) sollen dann auch bei direktem API-Aufruf keine
    // Wirkung mehr haben (die Wunschseite selbst zeigt ohnehin nur eine
    // leere Anzeige, siehe request.php). Admin-Aktionen weiter unten
    // bleiben davon unberuehrt.
    if (in_array($action, ['set_name', 'create'], true) && (new SettingRepository())->get('app_live', '1') === '0') {
        json_fail(403, 'Die Party ist gerade offline.');
    }

    if ($action === 'set_name') {
        // Namens-Sperre: der erste fuer dieses Geraet (Gast-Cookie) gesetzte
        // Name gilt dauerhaft, ein spaeterer Aenderungsversuch wird
        // ignoriert - schaltet auf der Wunschseite die Suche frei.
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            json_fail(400, 'Bitte gib deinen Namen ein.');
        }
        $name = mb_substr($name, 0, 60);
        $guestToken = GuestIdentity::id();
        $lockedName = (new GuestProfileRepository())->lockName($guestToken, $name);
        $info = guestLimitInfo($guestToken, $repo, new SettingRepository());
        echo json_encode(array_merge(['ok' => true, 'name' => $lockedName], $info));
        exit;
    }

    if ($action === 'create') {
        $trackId = (int) ($input['track_id'] ?? 0);
        $guestName = trim((string) ($input['guest_name'] ?? ''));
        if ($guestName === '') {
            json_fail(400, 'Bitte gib deinen Namen ein.');
        }
        $guestName = mb_substr($guestName, 0, 60);
        $guestToken = GuestIdentity::id();

        $trackRepo = new TrackRepository();
        $track = $trackRepo->findById($trackId);
        if (!$track) {
            json_fail(404, 'Song nicht gefunden.');
        }

        $settings = new SettingRepository();
        $lockHours = (int) $settings->get('recent_played_lock_hours', '4');
        if ($trackRepo->isLocked($track, $lockHours)) {
            json_fail(409, 'Dieser Track wurde kürzlich gespielt und ist noch gesperrt.');
        }

        // Kontingent-Pruefung + Anlage in einer Transaktion: sonst koennten
        // zwei parallele Requests desselben Gasts (oder derselben IP) beide
        // denselben "noch nicht ausgeschoepft"-Stand lesen und das Limit
        // gemeinsam ueberschreiten, bevor die erste INSERT committet ist
        // (TOCTOU). BEGIN IMMEDIATE erzwingt bei SQLite sofort den
        // Schreib-Lock, statt ihn erst bei der ersten Schreiboperation zu
        // holen - genau das schliesst die Luecke.
        $pdo = Database::get();
        $isMysql = Database::driver() === 'mysql';
        $isMysql ? $pdo->beginTransaction() : $pdo->exec('BEGIN IMMEDIATE');

        // Grobe, feste IP-Bremse als zusaetzliches Sicherheitsnetz (z.B. falls
        // jemand das Cookie loescht) - das eigentliche, einstellbare Limit
        // laeuft ueber das Gast-Cookie weiter unten.
        if ($repo->countRecentFromIp(5) >= 20) {
            json_fail(429, 'Zu viele Wünsche in kurzer Zeit. Bitte kurz warten.');
        }

        $limitInfo = guestLimitInfo($guestToken, $repo, $settings);
        if ($limitInfo['remaining'] === 0) {
            $timeLabel = $limitInfo['limit_minutes'] % 60 === 0 && $limitInfo['limit_minutes'] >= 60
                ? ($limitInfo['limit_minutes'] / 60) . ' Std.'
                : $limitInfo['limit_minutes'] . ' Min.';
            json_fail(
                429,
                "Du hast das Limit von {$limitInfo['limit_count']} Wünschen pro {$timeLabel} erreicht. Nächster Wunsch möglich in " . Util::formatWait($limitInfo['wait_seconds']) . '.',
                ['wait_seconds' => $limitInfo['wait_seconds']]
            );
        }

        // Auto-DJ: Wuensche werden direkt (ohne manuelle Freigabe) in die
        // Playlist geschoben, statt in der Wunschliste auf Freigabe zu warten.
        $autoDj = $settings->get('auto_dj_enabled', '0') === '1';
        if ($autoDj) {
            $id = $repo->create($trackId, $guestName, $guestToken, RequestRepository::STATUS_APPROVED);
            $playlist = new PlaylistRepository();
            $playlist->add($trackId, PlaylistRepository::SOURCE_GUEST, $id);
            $playlist->topUp();
        } else {
            $id = $repo->create($trackId, $guestName, $guestToken);
        }

        $isMysql ? $pdo->commit() : $pdo->exec('COMMIT');

        $limitInfo = guestLimitInfo($guestToken, $repo, $settings);
        echo json_encode(array_merge(['ok' => true, 'id' => $id, 'auto_dj' => $autoDj], $limitInfo));
        exit;
    }

    // Alle weiteren Aktionen (Status aendern) sind Admin-only.
    Auth::requireLoginApi();

    if ($action === 'reset_guest_limit') {
        $guestToken = (string) ($input['guest_token'] ?? '');
        if ($guestToken === '') {
            json_fail(400, 'Kein Gast angegeben.');
        }
        $repo->setLimitReset($guestToken);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'update_status') {
        $id = (int) ($input['id'] ?? 0);
        $status = $input['status'] ?? '';
        if (!in_array($status, ['pending', 'approved', 'played', 'rejected'], true)) {
            json_fail(400, 'Ungueltiger Status.');
        }
        $repo->updateStatus($id, $status);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        $repo->delete($id);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'accept') {
        // Ein Gast-Wunsch wird nie direkt abgespielt, sondern immer unten
        // an die Playlist angehaengt - der Player arbeitet sie der Reihe
        // nach ab (bzw. Crossfade-Uebergang).
        $id = (int) ($input['id'] ?? 0);
        $request = $repo->find($id);
        if (!$request) {
            json_fail(404, 'Wunsch nicht gefunden.');
        }
        (new PlaylistRepository())->add((int) $request['track_id'], PlaylistRepository::SOURCE_GUEST, $id);
        $repo->updateStatus($id, RequestRepository::STATUS_APPROVED);
        echo json_encode(['ok' => true]);
        exit;
    }

    json_fail(400, 'Unbekannte Aktion.');
}

json_fail(405, 'Methode nicht erlaubt.');
