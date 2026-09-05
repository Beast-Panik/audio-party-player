<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\GuestIdentity;
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

$repo = new RequestRepository();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Wunschliste anzeigen - fuer die Party-Anzeige/Wunsch-Seite oeffentlich
    // lesbar (keine sensiblen Daten: nur Songtitel + optionaler Gastname).
    $status = $_GET['status'] ?? null;
    if ($status !== null && !in_array($status, ['pending', 'approved', 'played', 'rejected', 'upcoming'], true)) {
        json_fail(400, 'Ungueltiger Status.');
    }
    $rows = $status === 'upcoming' ? $repo->listUpcomingWithTracks(100) : $repo->listWithTracks($status, 100);
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

    if ($action === 'create') {
        $trackId = (int) ($input['track_id'] ?? 0);
        $guestName = trim((string) ($input['guest_name'] ?? ''));
        if ($guestName === '') {
            json_fail(400, 'Bitte gib deinen Namen ein.');
        }
        $guestName = mb_substr($guestName, 0, 60);
        $guestToken = GuestIdentity::id();

        $track = (new TrackRepository())->findById($trackId);
        if (!$track) {
            json_fail(404, 'Song nicht gefunden.');
        }

        // Grobe, feste IP-Bremse als zusaetzliches Sicherheitsnetz (z.B. falls
        // jemand das Cookie loescht) - das eigentliche, einstellbare Limit
        // laeuft ueber das Gast-Cookie weiter unten.
        if ($repo->countRecentFromIp(5) >= 20) {
            json_fail(429, 'Zu viele Wünsche in kurzer Zeit. Bitte kurz warten.');
        }

        $settings = new SettingRepository();
        $limitCount = (int) $settings->get('guest_limit_count', '3');
        $limitMinutes = (int) $settings->get('guest_limit_minutes', '60');
        if ($limitCount > 0 && $repo->countRecentByGuestToken($guestToken, $limitMinutes) >= $limitCount) {
            $timeLabel = $limitMinutes % 60 === 0 && $limitMinutes >= 60
                ? ($limitMinutes / 60) . ' Std.'
                : $limitMinutes . ' Min.';
            $oldest = $repo->oldestRecentByGuestToken($guestToken, $limitMinutes);
            $waitSeconds = $oldest !== null ? max(0, (strtotime($oldest) + $limitMinutes * 60) - time()) : 0;
            json_fail(
                429,
                "Du hast das Limit von {$limitCount} Wünschen pro {$timeLabel} erreicht. Nächster Wunsch möglich in " . Util::formatWait($waitSeconds) . '.',
                ['wait_seconds' => $waitSeconds]
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

        echo json_encode(['ok' => true, 'id' => $id, 'auto_dj' => $autoDj]);
        exit;
    }

    // Alle weiteren Aktionen (Status aendern) sind Admin-only.
    Auth::requireLoginApi();

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
