<?php

namespace App\Repositories;

use App\Database;
use App\Util;

/**
 * Die Playlist ist die konkrete "als naechstes dran"-Warteschlange fuer den
 * Player - anders als die Wunschliste (dort landen Gast-Wuensche, die im
 * manuellen Modus erst vom Admin freigegeben werden muessen). Reihenfolge
 * ergibt sich aus der "position"-Spalte (per Drag&Drop im Player sortierbar),
 * neue Eintraege werden ans Ende angehaengt (hoechste Position + 1).
 */
final class PlaylistRepository
{
    public const SOURCE_GUEST = 'guest';
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    /** @return array Playlist-Eintraege mit Track-Infos, in Abspielreihenfolge. */
    public function all(): array
    {
        $sql = 'SELECT p.*, t.title, t.artist, t.album, t.duration_seconds, t.codec,
                       r.guest_name
                FROM playlist p
                JOIN tracks t ON t.id = p.track_id
                LEFT JOIN requests r ON r.id = p.request_id
                ORDER BY p.position ASC, p.id ASC';
        return Database::get()->query($sql)->fetchAll();
    }

    public function count(): int
    {
        return (int) Database::get()->query('SELECT COUNT(*) AS c FROM playlist')->fetch()['c'];
    }

    public function first(): ?array
    {
        $rows = $this->all();
        return $rows[0] ?? null;
    }

    /** Fuegt einen Track ans Ende der Playlist an - steht er schon drin, wird nicht doppelt eingereiht. */
    public function add(int $trackId, string $source = self::SOURCE_MANUAL, ?int $requestId = null): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id FROM playlist WHERE track_id = ? LIMIT 1');
        $stmt->execute([$trackId]);
        $existing = $stmt->fetch();
        if ($existing) {
            return (int) $existing['id'];
        }

        $nextPos = (int) $pdo->query('SELECT COALESCE(MAX(position), -1) + 1 AS p FROM playlist')->fetch()['p'];
        $stmt = $pdo->prepare(
            'INSERT INTO playlist (track_id, source, request_id, position, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$trackId, $source, $requestId, $nextPos, Util::now()]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Stellt einen Track an den Anfang der Playlist (niedrigste Position) -
     * fuer den "Jetzt spielen"-Fall (Bibliothek/Playlist), damit ein manuell
     * gestarteter Track sofort als "aktuell" in der Playlist auftaucht.
     */
    public function addAtFront(int $trackId, string $source = self::SOURCE_MANUAL): int
    {
        $pdo = Database::get();
        $minPos = (int) $pdo->query('SELECT COALESCE(MIN(position), 0) AS p FROM playlist')->fetch()['p'];

        $stmt = $pdo->prepare('SELECT id FROM playlist WHERE track_id = ? LIMIT 1');
        $stmt->execute([$trackId]);
        $existing = $stmt->fetch();
        if ($existing) {
            $pdo->prepare('UPDATE playlist SET position = ? WHERE id = ?')->execute([$minPos - 1, $existing['id']]);
            return (int) $existing['id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO playlist (track_id, source, request_id, position, created_at) VALUES (?, ?, NULL, ?, ?)'
        );
        $stmt->execute([$trackId, $source, $minPos - 1, Util::now()]);
        return (int) $pdo->lastInsertId();
    }

    /** Schreibt eine neue Reihenfolge fest (Drag&Drop im Player) - $orderedIds sind Playlist-IDs. */
    public function reorder(array $orderedIds): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE playlist SET position = ? WHERE id = ?');
        foreach (array_values($orderedIds) as $i => $id) {
            $stmt->execute([$i, (int) $id]);
        }
    }

    public function remove(int $id): void
    {
        Database::get()->prepare('DELETE FROM playlist WHERE id = ?')->execute([$id]);
    }

    public function removeByTrackId(int $trackId): void
    {
        Database::get()->prepare('DELETE FROM playlist WHERE track_id = ?')->execute([$trackId]);
    }

    /** Markiert einen Track als (gerade) gespielt und entfernt ihn aus der Playlist, falls dort vorhanden. */
    public function markPlayed(int $trackId): void
    {
        $pdo = Database::get();
        $pdo->prepare('UPDATE tracks SET last_played_at = ? WHERE id = ?')->execute([Util::now(), $trackId]);
        $this->removeByTrackId($trackId);
    }

    /**
     * Fuellt die Playlist auf, falls sie unter $minCount Eintraege hat: fuegt
     * dann $addCount noch nicht (oder am laengsten nicht mehr) gespielte
     * Tracks aus der Bibliothek hinzu, die noch nicht in der Playlist stehen.
     *
     * @return int Anzahl tatsaechlich hinzugefuegter Tracks
     */
    public function topUp(int $minCount = 3, int $addCount = 2): int
    {
        if ($this->count() >= $minCount) {
            return 0;
        }

        $pdo = Database::get();
        $existingIds = array_column($this->all(), 'track_id');
        $placeholders = '';
        $params = [];
        if (!empty($existingIds)) {
            $placeholders = 'WHERE id NOT IN (' . implode(',', array_fill(0, count($existingIds), '?')) . ')';
            $params = $existingIds;
        }

        // NULL (noch nie gespielt) sortiert in SQLite/MySQL vor jedem Datum,
        // damit landen unangespielte Tracks automatisch zuerst.
        $sql = "SELECT id FROM tracks {$placeholders} ORDER BY last_played_at ASC LIMIT ?";
        $params[] = $addCount;
        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p, is_int($p) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        $candidates = $stmt->fetchAll();

        foreach ($candidates as $row) {
            $this->add((int) $row['id'], self::SOURCE_AUTO);
        }

        return count($candidates);
    }
}
