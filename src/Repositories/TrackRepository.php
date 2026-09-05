<?php

namespace App\Repositories;

use App\Database;
use App\Util;

final class TrackRepository
{
    /**
     * Legt einen Track an oder aktualisiert ihn, falls library_id+relpath
     * schon existiert. Gedacht fuer den Scanner (ein Aufruf pro Datei -
     * das ist bei ein paar tausend Dateien pro Scan-Chunk voellig
     * ausreichend performant und bleibt auf jedem DB-Treiber portabel).
     */
    public function upsert(int $libraryId, string $relpath, array $meta): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id FROM tracks WHERE library_id = ? AND relpath = ?');
        $stmt->execute([$libraryId, $relpath]);
        $existing = $stmt->fetch();

        $now = Util::now();
        $fields = [
            'filename' => $meta['filename'],
            'title' => $meta['title'],
            'artist' => $meta['artist'],
            'album' => $meta['album'],
            'album_artist' => $meta['album_artist'],
            'genre' => $meta['genre'],
            'track_no' => $meta['track_no'],
            'disc_no' => $meta['disc_no'],
            'year' => $meta['year'],
            'duration_seconds' => $meta['duration_seconds'],
            'bitrate' => $meta['bitrate'],
            'filesize' => $meta['filesize'],
            'mtime' => $meta['mtime'],
            'codec' => $meta['codec'],
        ];

        if ($existing) {
            $set = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE tracks SET {$set}, updated_at = ? WHERE id = ?");
            $stmt->execute([...array_values($fields), $now, $existing['id']]);
            return;
        }

        $columns = array_merge(['library_id', 'relpath'], array_keys($fields), ['added_at', 'updated_at']);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare('INSERT INTO tracks (' . implode(', ', $columns) . ") VALUES ({$placeholders})");
        $stmt->execute([$libraryId, $relpath, ...array_values($fields), $now, $now]);
    }

    /** @return string[] relpaths, die aktuell fuer diese Library in der DB stehen */
    public function relpathsForLibrary(int $libraryId): array
    {
        $stmt = Database::get()->prepare('SELECT relpath FROM tracks WHERE library_id = ?');
        $stmt->execute([$libraryId]);
        return array_column($stmt->fetchAll(), 'relpath');
    }

    public function deleteByRelpaths(int $libraryId, array $relpaths): void
    {
        if (empty($relpaths)) {
            return;
        }
        $pdo = Database::get();
        $placeholders = implode(', ', array_fill(0, count($relpaths), '?'));
        $stmt = $pdo->prepare("DELETE FROM tracks WHERE library_id = ? AND relpath IN ({$placeholders})");
        $stmt->execute([$libraryId, ...$relpaths]);
    }

    public function countForLibrary(int $libraryId): int
    {
        $stmt = Database::get()->prepare('SELECT COUNT(*) AS c FROM tracks WHERE library_id = ?');
        $stmt->execute([$libraryId]);
        return (int) $stmt->fetch()['c'];
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM tracks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Volltextsuche (einfach, per LIKE) ueber Titel/Interpret/Album.
     */
    public function search(string $query = '', int $limit = 100, int $offset = 0): array
    {
        $pdo = Database::get();
        $query = trim($query);
        if ($query === '') {
            $stmt = $pdo->prepare('SELECT * FROM tracks ORDER BY artist, album, track_no, title LIMIT ? OFFSET ?');
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';
        $sql = "SELECT * FROM tracks
                WHERE LOWER(title) LIKE LOWER(?) ESCAPE '\\'
                   OR LOWER(artist) LIKE LOWER(?) ESCAPE '\\'
                   OR LOWER(album) LIKE LOWER(?) ESCAPE '\\'
                ORDER BY artist, album, track_no, title
                LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $like);
        $stmt->bindValue(2, $like);
        $stmt->bindValue(3, $like);
        $stmt->bindValue(4, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(5, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return (int) Database::get()->query('SELECT COUNT(*) AS c FROM tracks')->fetch()['c'];
    }
}
