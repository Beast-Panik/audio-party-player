<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Repositories\PlaylistRepository;
use App\Repositories\SavedPlaylistRepository;
use App\Repositories\TrackRepository;

/**
 * Verwaltung gespeicherter Playlists ("Sets", siehe admin/playlists.php) -
 * eigenstaendig von der Live-Playlist (api/playlist.php). Fuer beide Rollen
 * nutzbar (auch die eingeschraenkte Auth::ROLE_PLAYER-Rolle) - anders als
 * Bibliotheksverwaltung/Uploads/Einstellungen zaehlt das Playlist-Feature
 * zum Player-Funktionsumfang, siehe admin_header.php.
 */
header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();

function json_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$repo = new SavedPlaylistRepository();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
    if ($id !== null) {
        $playlist = $repo->findById($id);
        if (!$playlist) {
            json_fail(404, 'Playlist nicht gefunden.');
        }
        $tracks = array_map(static function (array $t): array {
            return [
                'entry_id' => (int) $t['id'],
                'track_id' => (int) $t['track_id'],
                'title' => $t['title'],
                'artist' => $t['artist'],
                'album' => $t['album'],
                'duration_seconds' => $t['duration_seconds'] !== null ? (int) $t['duration_seconds'] : null,
                'codec' => $t['codec'],
            ];
        }, $repo->tracks($id));
        echo json_encode([
            'id' => (int) $playlist['id'],
            'name' => $playlist['name'],
            'tracks' => $tracks,
        ]);
        exit;
    }

    $playlists = array_map(static function (array $p): array {
        return [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'track_count' => (int) $p['track_count'],
            'updated_at' => $p['updated_at'],
        ];
    }, $repo->all());
    echo json_encode(['playlists' => $playlists]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Csrf::check($token)) {
        json_fail(400, 'Ungueltiges Formular. Bitte Seite neu laden.');
    }

    $action = $input['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            json_fail(400, 'Bitte einen Namen angeben.');
        }
        $id = $repo->create($name);
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'rename') {
        $id = (int) ($input['id'] ?? 0);
        $name = trim((string) ($input['name'] ?? ''));
        if (!$repo->findById($id)) {
            json_fail(404, 'Playlist nicht gefunden.');
        }
        if ($name === '') {
            json_fail(400, 'Bitte einen Namen angeben.');
        }
        $repo->rename($id, $name);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        $repo->delete($id);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'add_track') {
        $id = (int) ($input['id'] ?? 0);
        $trackId = (int) ($input['track_id'] ?? 0);
        if (!$repo->findById($id)) {
            json_fail(404, 'Playlist nicht gefunden.');
        }
        if (!(new TrackRepository())->findById($trackId)) {
            json_fail(404, 'Song nicht gefunden.');
        }
        $entryId = $repo->addTrack($id, $trackId);
        echo json_encode(['ok' => true, 'entry_id' => $entryId]);
        exit;
    }

    if ($action === 'remove_track') {
        $entryId = (int) ($input['entry_id'] ?? 0);
        $repo->removeTrack($entryId);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reorder') {
        $id = (int) ($input['id'] ?? 0);
        $entryIds = array_map('intval', (array) ($input['entry_ids'] ?? []));
        if (!$repo->findById($id)) {
            json_fail(404, 'Playlist nicht gefunden.');
        }
        $repo->reorder($id, $entryIds);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'load_into_player') {
        $id = (int) ($input['id'] ?? 0);
        if (!$repo->findById($id)) {
            json_fail(404, 'Playlist nicht gefunden.');
        }
        $playlist = new PlaylistRepository();
        // PlaylistRepository::add() haengt einen Track, der schon in der
        // Live-Playlist steht, nicht doppelt an (liefert dann nur dessen
        // bestehende ID zurueck) - fuer eine ehrliche Rueckmeldung hier
        // vorab merken, welche Track-IDs schon drin waren.
        $existingTrackIds = array_column($playlist->all(), 'track_id');
        $added = 0;
        foreach ($repo->tracks($id) as $t) {
            $trackId = (int) $t['track_id'];
            $playlist->add($trackId, PlaylistRepository::SOURCE_MANUAL);
            if (!in_array($trackId, $existingTrackIds, true)) {
                $added++;
                $existingTrackIds[] = $trackId;
            }
        }
        echo json_encode(['ok' => true, 'added' => $added]);
        exit;
    }

    json_fail(400, 'Unbekannte Aktion.');
}

json_fail(405, 'Methode nicht erlaubt.');
