<?php

namespace App\Repositories;

use App\Database;
use App\Util;

final class TrackRepository
{
    /**
     * Legt einen Track an oder aktualisiert ihn, falls library_id+relpath
     * schon existiert - als echtes atomares UPSERT (nicht mehr
     * SELECT-dann-INSERT-oder-UPDATE), damit zwei ueberlappende Scan-
     * Aufrufe fuer dieselbe Datei niemals eine Dublette erzeugen koennen.
     * Gibt die (neue oder bestehende) Track-ID zurueck.
     */
    public function upsert(int $libraryId, string $relpath, array $meta): int
    {
        $pdo = Database::get();
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
            'cover_ext' => $meta['cover_ext'] ?? null,
        ];

        $columns = array_merge(['library_id', 'relpath'], array_keys($fields), ['added_at', 'updated_at']);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $params = [$libraryId, $relpath, ...array_values($fields), $now, $now];

        if (Database::driver() === 'mysql') {
            $updateSet = implode(', ', array_map(fn ($k) => "$k = VALUES($k)", array_keys($fields)));
            $sql = 'INSERT INTO tracks (' . implode(', ', $columns) . ") VALUES ({$placeholders})
                    ON DUPLICATE KEY UPDATE {$updateSet}, updated_at = VALUES(updated_at)";
        } else {
            $updateSet = implode(', ', array_map(fn ($k) => "$k = excluded.$k", array_keys($fields)));
            $sql = 'INSERT INTO tracks (' . implode(', ', $columns) . ") VALUES ({$placeholders})
                    ON CONFLICT(library_id, relpath) DO UPDATE SET {$updateSet}, updated_at = excluded.updated_at";
        }

        $pdo->prepare($sql)->execute($params);

        $stmt = $pdo->prepare('SELECT id FROM tracks WHERE library_id = ? AND relpath = ?');
        $stmt->execute([$libraryId, $relpath]);
        return (int) $stmt->fetch()['id'];
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

    /**
     * Sucht einen bereits vorhandenen Track mit identischen Tags (Titel +
     * Interpret, normalisiert per trim/Kleinschreibung) - fuer die
     * Duplikat-Erkennung beim Scannen (dieselbe Aufnahme liegt als andere
     * Datei/in einer anderen Bibliothek nochmal vor). $excludeLibraryId/
     * $excludeRelpath schliessen die gerade gescannte Datei selbst aus,
     * damit ein erneuter Scan derselben Datei nicht sich selbst als
     * Duplikat meldet. Ein leerer Titel wird nie als Duplikat gewertet
     * (zu unspezifisch, traefe sonst auf beliebig viele "unbenannte" Tracks).
     */
    public function findDuplicateByTags(string $title, string $artist, int $excludeLibraryId, string $excludeRelpath): ?array
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }
        $stmt = Database::get()->prepare(
            'SELECT * FROM tracks
             WHERE LOWER(title) = LOWER(?) AND LOWER(COALESCE(artist, \'\')) = LOWER(?)
               AND NOT (library_id = ? AND relpath = ?)
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$title, trim($artist), $excludeLibraryId, $excludeRelpath]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByLibraryAndRelpath(int $libraryId, string $relpath): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM tracks WHERE library_id = ? AND relpath = ?');
        $stmt->execute([$libraryId, $relpath]);
        $row = $stmt->fetch();
        return $row ?: null;
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
     *
     * $startsWith: fuer die A-Z/0-9-Sprungleiste (Bibliothek/Gaeste-Suche) -
     * liefert nur Titel, die mit diesem einzelnen Zeichen beginnen,
     * alphabetisch sortiert; ignoriert $query dabei.
     *
     * Ohne Suchbegriff und ohne $startsWith (= die normale "Bibliothek
     * durchstoebern"-Ansicht) wird bewusst in zufaelliger Reihenfolge
     * sortiert statt immer alphabetisch - bei jedem Laden der Seite werden
     * so andere Titel oben angezeigt, damit nicht immer dieselben (alphabet-
     * isch fruehen) Tracks den sichtbaren Ausschnitt dominieren.
     *
     * $seed: haelt diese "zufaellige" Reihenfolge ueber mehrere LIMIT/OFFSET-
     * Aufrufe hinweg stabil (feste Rechenvorschrift statt echtem RANDOM() je
     * Aufruf) - noetig fuer das seitenweise Nachladen in der Bibliothek
     * (player.php): ohne festen Seed wuerde jede nachgeladene Seite neu
     * gemischt, wodurch Titel doppelt oder gar nicht auftauchen (Bug-Report).
     * Ohne $seed (z.B. andere Aufrufer) bleibt das alte Verhalten (echtes
     * RANDOM() je Aufruf) unveraendert.
     */
    public function search(string $query = '', int $limit = 100, int $offset = 0, ?string $startsWith = null, ?int $seed = null): array
    {
        $pdo = Database::get();
        $query = trim($query);
        $startsWith = $startsWith !== null ? trim($startsWith) : null;

        if ($startsWith !== null && $startsWith !== '') {
            $like = str_replace(['%', '_'], ['\%', '\_'], $startsWith) . '%';
            $stmt = $pdo->prepare(
                "SELECT * FROM tracks WHERE LOWER(title) LIKE LOWER(?) ESCAPE '\\'
                 ORDER BY title, artist, album, track_no LIMIT ? OFFSET ?"
            );
            $stmt->bindValue(1, $like);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        if ($query === '') {
            if ($seed !== null) {
                // Deterministische Pseudo-Zufalls-Reihenfolge per Multiplikations-
                // Hash statt echtem RANDOM() - bleibt fuer denselben Seed ueber
                // mehrere LIMIT/OFFSET-Aufrufe stabil (siehe Docblock oben).
                $stmt = $pdo->prepare(
                    'SELECT * FROM tracks ORDER BY ((id * 2654435761) + ?) % 1000000007 LIMIT ? OFFSET ?'
                );
                $stmt->bindValue(1, $seed, \PDO::PARAM_INT);
                $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
                $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->fetchAll();
            }
            $randomFn = Database::driver() === 'mysql' ? 'RAND()' : 'RANDOM()';
            $stmt = $pdo->prepare("SELECT * FROM tracks ORDER BY {$randomFn} LIMIT ? OFFSET ?");
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
                   OR CAST(year AS CHAR) LIKE ?
                ORDER BY artist, album, track_no, title
                LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $like);
        $stmt->bindValue(2, $like);
        $stmt->bindValue(3, $like);
        $stmt->bindValue(4, $like);
        $stmt->bindValue(5, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(6, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return (int) Database::get()->query('SELECT COUNT(*) AS c FROM tracks')->fetch()['c'];
    }

    /**
     * Tracks, deren letzte Wiedergabe innerhalb der Sperrfrist liegt (und
     * die nicht vom Admin vorzeitig freigegeben wurden). Fuer die
     * "Kuerzlich gespielt"-Liste auf der Player-Seite.
     */
    public function listRecentlyPlayed(int $hours): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - $hours * 3600);
        $stmt = Database::get()->prepare(
            "SELECT * FROM tracks
             WHERE last_played_at IS NOT NULL AND last_played_at > ?
               AND (lock_released_at IS NULL OR lock_released_at < last_played_at)
             ORDER BY last_played_at DESC"
        );
        $stmt->execute([$cutoff]);
        return $stmt->fetchAll();
    }

    /** Ob ein einzelner Track aktuell wegen kuerzlicher Wiedergabe gesperrt ist. */
    public function isLocked(array $track, int $hours): bool
    {
        if (empty($track['last_played_at'])) {
            return false;
        }
        if ($track['last_played_at'] <= date('Y-m-d H:i:s', time() - $hours * 3600)) {
            return false;
        }
        if (!empty($track['lock_released_at']) && $track['lock_released_at'] >= $track['last_played_at']) {
            return false;
        }
        return true;
    }

    /** Setzt die Admin-Freigabe fuer einen einzelnen gesperrten Track. */
    public function releaseLock(int $trackId): void
    {
        $stmt = Database::get()->prepare('UPDATE tracks SET lock_released_at = ? WHERE id = ?');
        $stmt->execute([Util::now(), $trackId]);
    }

    /**
     * Zaehlt die "Spielinstanz" eines Tracks hoch - aufgerufen bei jedem
     * (Wieder-)Start seiner Wiedergabe (siehe api/playlist.php Action
     * set_now_playing). Grundlage fuer TrackReactionRepository: eine
     * Herz-Reaktion ist nur einmal pro Track UND Spielinstanz erlaubt.
     */
    public function bumpPlaySeq(int $trackId): void
    {
        Database::get()->prepare('UPDATE tracks SET play_seq = play_seq + 1 WHERE id = ?')->execute([$trackId]);
    }

    public function currentPlaySeq(int $trackId): int
    {
        $stmt = Database::get()->prepare('SELECT play_seq FROM tracks WHERE id = ?');
        $stmt->execute([$trackId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['play_seq'] : 0;
    }
}
