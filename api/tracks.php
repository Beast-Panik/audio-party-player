<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Repositories\SettingRepository;
use App\Repositories\TrackRepository;

header('Content-Type: application/json; charset=utf-8');

$repo = new TrackRepository();
$settings = new SettingRepository();
$lockHours = (int) $settings->get('recent_played_lock_hours', '4');

// Admin-only Sonderfall fuer die "Kuerzlich gespielt"-Liste auf der
// Player-Seite (siehe assets/js/app.js initRecentlyPlayed()).
if (!empty($_GET['recently_played'])) {
    Auth::requireLoginApi();
    $tracks = $repo->listRecentlyPlayed($lockHours);
    $out = array_map(static function (array $t) use ($lockHours): array {
        $lockedUntil = date('c', strtotime($t['last_played_at']) + $lockHours * 3600);
        return [
            'id' => (int) $t['id'],
            'title' => $t['title'],
            'artist' => $t['artist'],
            'last_played_at' => $t['last_played_at'],
            'locked_until' => $lockedUntil,
        ];
    }, $tracks);
    echo json_encode(['tracks' => $out]);
    exit;
}

$q = trim($_GET['q'] ?? '');
$limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
// Sprungleiste (A-Z/0-9) - immer nur ein einzelnes Zeichen, alles andere wird
// ignoriert statt als LIKE-Muster durchgereicht.
$startsWithRaw = trim((string) ($_GET['starts_with'] ?? ''));
$startsWith = mb_strlen($startsWithRaw) === 1 ? $startsWithRaw : null;
// Fester Seed fuer die zufaellige Grundsortierung (siehe search()) - ohne
// ihn wuerde jede Seite (LIMIT/OFFSET) unabhaengig neu gemischt und beim
// seitenweisen Nachladen (Bibliothek player.php) kaemen Titel doppelt vor
// oder gar nicht (Bug-Report). Der Client schickt fuer die Dauer eines
// Browse-Vorgangs denselben Seed mit.
$seed = isset($_GET['seed']) ? (int) $_GET['seed'] : null;

$tracks = $repo->search($q, $limit, $offset, $startsWith, $seed);

$out = array_map(static function (array $t) use ($repo, $lockHours): array {
    $locked = $repo->isLocked($t, $lockHours);
    return [
        'id' => (int) $t['id'],
        'title' => $t['title'],
        'artist' => $t['artist'],
        'album' => $t['album'],
        'genre' => $t['genre'],
        'year' => $t['year'] !== null ? (int) $t['year'] : null,
        'duration_seconds' => $t['duration_seconds'] !== null ? (int) $t['duration_seconds'] : null,
        'codec' => $t['codec'],
        'has_cover' => !empty($t['cover_ext']),
        'locked' => $locked,
        'locked_until' => $locked ? date('c', strtotime($t['last_played_at']) + $lockHours * 3600) : null,
    ];
}, $tracks);

echo json_encode(['tracks' => $out, 'count' => $repo->countAll()]);
