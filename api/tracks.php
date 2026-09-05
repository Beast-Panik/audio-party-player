<?php

require __DIR__ . '/../bootstrap.php';

use App\Repositories\TrackRepository;

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$repo = new TrackRepository();
$tracks = $repo->search($q, $limit, $offset);

$out = array_map(static function (array $t): array {
    return [
        'id' => (int) $t['id'],
        'title' => $t['title'],
        'artist' => $t['artist'],
        'album' => $t['album'],
        'genre' => $t['genre'],
        'duration_seconds' => $t['duration_seconds'] !== null ? (int) $t['duration_seconds'] : null,
        'codec' => $t['codec'],
    ];
}, $tracks);

echo json_encode(['tracks' => $out, 'count' => $repo->countAll()]);
