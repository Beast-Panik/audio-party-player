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

    /**
     * Ermittelt den Playlist-Eintrag nach dem aktuell laufenden Track (fuer
     * die "Als naechstes"-Anzeige, z.B. display.php) - faellt auf den ersten
     * Eintrag zurueck, wenn der aktuelle Track nicht (mehr) in der Playlist
     * steht, analog zu nextItemAfterCurrent() in assets/js/app.js.
     */
    public function nextAfter(?int $currentTrackId): ?array
    {
        $items = $this->all();
        if ($currentTrackId !== null) {
            foreach ($items as $i => $item) {
                if ((int) $item['track_id'] === $currentTrackId) {
                    return $items[$i + 1] ?? null;
                }
            }
        }
        return $items[0] ?? null;
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

    /** Schreibt eine neue Reihenfolge fest (Drag&Drop im Player) - $orderedIds sind Playlist-IDs. */
    public function reorder(array $orderedIds): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE playlist SET position = ? WHERE id = ?');
        foreach (array_values($orderedIds) as $i => $id) {
            $stmt->execute([$i, (int) $id]);
        }
    }

    /**
     * Entfernt einen Playlist-Eintrag (Admin-Klick auf "Entfernen"). Markiert
     * den Track dabei als "gerade entfernt" (removed_at) - sonst wuerde der
     * naechste Auto-DJ-Durchlauf (pickCandidate()) ihn als "noch nie
     * gespielt" sofort wieder auswaehlen und er waere sekundenspaeter wieder
     * unten in der Playlist. Bewusst getrennt von last_played_at, damit die
     * "Kuerzlich gespielt"-Anzeige nicht faelschlich eine Wiedergabe zeigt.
     */
    public function remove(int $id): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT track_id FROM playlist WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $pdo->prepare('DELETE FROM playlist WHERE id = ?')->execute([$id]);
        if ($row) {
            $pdo->prepare('UPDATE tracks SET removed_at = ? WHERE id = ?')->execute([Util::now(), (int) $row['track_id']]);
        }
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
     * Vermeidet dabei nach Moeglichkeit, denselben Kuenstler direkt zu
     * wiederholen (weiche Praeferenz - siehe pickCandidate()).
     *
     * @return int Anzahl tatsaechlich hinzugefuegter Tracks
     */
    public function topUp(int $minCount = 3, int $addCount = 2): int
    {
        if ($this->count() >= $minCount) {
            return 0;
        }

        $pdo = Database::get();
        $playlistItems = $this->all();
        $existingIds = array_column($playlistItems, 'track_id');

        // Kuerzlich gespielte Tracks (Sperrfrist, Setting recent_played_lock_hours)
        // stehen dem Auto-DJ nicht zur Verfuegung, ausser der Admin hat sie
        // ueber "Kuerzlich gespielt" vorzeitig freigegeben.
        $lockHours = (int) (new SettingRepository())->get('recent_played_lock_hours', '4');
        $cutoff = date('Y-m-d H:i:s', time() - $lockHours * 3600);

        // Zu vermeidender Kuenstler fuer den ersten neuen Eintrag: der des
        // aktuell letzten Playlist-Eintrags, sonst (Playlist leer) der des
        // gerade laufenden Tracks (siehe api/playlist.php action set_now_playing).
        if (!empty($playlistItems)) {
            $avoidArtist = end($playlistItems)['artist'];
        } else {
            $avoidArtist = (new SettingRepository())->get('now_playing_artist');
        }
        $avoidArtist = $avoidArtist !== null && $avoidArtist !== '' ? $avoidArtist : null;

        $addedIds = [];
        for ($i = 0; $i < $addCount; $i++) {
            $excludeIds = array_merge($existingIds, $addedIds);
            $candidate = $this->pickCandidate($pdo, $excludeIds, $cutoff, $avoidArtist);
            if (!$candidate && $avoidArtist !== null) {
                // Weiche Praeferenz: liefert die Kuenstler-gefilterte Suche
                // nichts (z.B. Bibliothek besteht quasi nur aus einem
                // Kuenstler), ohne die Einschraenkung erneut versuchen -
                // die Playlist muss trotzdem aufgefuellt werden.
                $candidate = $this->pickCandidate($pdo, $excludeIds, $cutoff, null);
            }
            if (!$candidate) {
                break;
            }

            $this->add((int) $candidate['id'], self::SOURCE_AUTO);
            $addedIds[] = (int) $candidate['id'];
            $avoidArtist = $candidate['artist'] !== null && $candidate['artist'] !== '' ? $candidate['artist'] : null;
        }

        return count($addedIds);
    }

    /** Waehlt den naechsten Auto-DJ-Kandidaten aus (am laengsten nicht gespielt), optional ohne $avoidArtist. */
    private function pickCandidate(\PDO $pdo, array $excludeIds, string $cutoff, ?string $avoidArtist): ?array
    {
        $conditions = [];
        $params = [];
        if (!empty($excludeIds)) {
            $conditions[] = 'id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
            $params = array_merge($params, $excludeIds);
        }
        $conditions[] = '(last_played_at IS NULL OR last_played_at <= ? OR (lock_released_at IS NOT NULL AND lock_released_at > last_played_at))';
        $params[] = $cutoff;
        // Gerade erst vom Admin aus der Playlist entfernte Tracks sollen der
        // gleichen Sperrfrist unterliegen wie kuerzlich gespielte - sonst
        // waehlt der Auto-DJ genau den Track sofort wieder aus (Bug-Report).
        $conditions[] = '(removed_at IS NULL OR removed_at <= ? OR (lock_released_at IS NOT NULL AND lock_released_at > removed_at))';
        $params[] = $cutoff;
        if ($avoidArtist !== null) {
            $conditions[] = '(artist IS NULL OR artist <> ?)';
            $params[] = $avoidArtist;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);
        // NULL (noch nie gespielt) sortiert in SQLite/MySQL vor jedem Datum,
        // damit landen unangespielte Tracks automatisch zuerst.
        $sql = "SELECT id, artist FROM tracks {$where} ORDER BY last_played_at ASC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p, is_int($p) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
