<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Repositories\PlaylistRepository;
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

    if ($action === 'mark_played') {
        $trackId = (int) ($input['track_id'] ?? 0);
        $playlist->markPlayed($trackId);
        if ($settings->get('auto_dj_enabled', '0') === '1') {
            $playlist->topUp();
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'set_auto_dj') {
        $enabled = !empty($input['enabled']);
        $settings->set('auto_dj_enabled', $enabled ? '1' : '0');
        // Kein topUp() hier - der direkt danach vom Frontend ausgeloeste GET
        // auf diesen Endpunkt fuellt bei Bedarf ohnehin auf (sonst wuerde
        // doppelt aufgefuellt: einmal hier, einmal beim folgenden GET).
        echo json_encode(['ok' => true, 'auto_dj' => $enabled]);
        exit;
    }

    json_fail(400, 'Unbekannte Aktion.');
}

json_fail(405, 'Methode nicht erlaubt.');
