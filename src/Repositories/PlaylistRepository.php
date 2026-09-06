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
                       r.guest_name, r.guest_token
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

    /**
     * Fuegt einen Track in die Playlist ein - steht er schon drin, wird nicht
     * doppelt eingereiht. Feste Prioritaetsreihenfolge (vom Admin so
     * gewuenscht): manuell hinzugefuegte Tracks zuerst, danach Gastwuensche,
     * danach der Auto-DJ. Manuelle Tracks und Gastwuensche werden dafuer
     * jeweils VOR dem naechsten "niedriger priorisierten" Bereich einsortiert
     * statt ans Ende angehaengt; nur Auto-DJ-Tracks landen weiterhin ganz
     * hinten. Innerhalb der Gastwuensche werden mehrere aufeinanderfolgende
     * Wuensche desselben Gasts mit denen anderer Gaeste fair gemischt (Round-
     * Robin, siehe priorityInsertIndex()) statt sich zu einem Block
     * anzustauen.
     */
    public function add(int $trackId, string $source = self::SOURCE_MANUAL, ?int $requestId = null): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id FROM playlist WHERE track_id = ? LIMIT 1');
        $stmt->execute([$trackId]);
        $existing = $stmt->fetch();
        if ($existing) {
            return (int) $existing['id'];
        }

        if ($source === self::SOURCE_MANUAL || $source === self::SOURCE_GUEST) {
            $guestToken = null;
            if ($source === self::SOURCE_GUEST && $requestId !== null) {
                $rstmt = $pdo->prepare('SELECT guest_token FROM requests WHERE id = ?');
                $rstmt->execute([$requestId]);
                $guestToken = $rstmt->fetch()['guest_token'] ?? null;
            }
            return $this->insertAtIndex($trackId, $source, $requestId, $this->priorityInsertIndex($source, $guestToken));
        }

        $nextPos = (int) $pdo->query('SELECT COALESCE(MAX(position), -1) + 1 AS p FROM playlist')->fetch()['p'];
        $stmt = $pdo->prepare(
            'INSERT INTO playlist (track_id, source, request_id, position, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$trackId, $source, $requestId, $nextPos, Util::now()]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Index (in der aktuellen Abspielreihenfolge), an dem ein neuer manueller
     * oder Gast-Eintrag einsortiert werden soll - Index 0 (der gerade
     * laufende bzw. als naechstes anstehende Track) bleibt dabei immer
     * unangetastet vorn:
     *
     * - SOURCE_MANUAL: hinter alle bereits wartenden manuellen Eintraege,
     *   aber vor den ersten Gast-/Auto-DJ-Eintrag (manuell hat immer Vorrang).
     * - SOURCE_GUEST: hinter die manuellen Eintraege, dann fair per
     *   Round-Robin unter den Gaesten einsortiert (siehe unten), spaetestens
     *   aber vor dem ersten Auto-DJ-Eintrag.
     */
    private function priorityInsertIndex(string $source, ?string $guestToken): int
    {
        $items = $this->all();
        $n = count($items);

        $manualEnd = 1;
        while ($manualEnd < $n && $items[$manualEnd]['source'] === self::SOURCE_MANUAL) {
            $manualEnd++;
        }
        if ($source === self::SOURCE_MANUAL) {
            return $manualEnd;
        }

        $guestEnd = $manualEnd;
        while ($guestEnd < $n && $items[$guestEnd]['source'] !== self::SOURCE_AUTO) {
            $guestEnd++;
        }

        // Faire Einreihung: "Runde" = der wievielte Wunsch dieses Gasts es in
        // der aktuellen Warteschlange waere (1., 2., 3. ...). Der neue Wunsch
        // wird vor dem ersten bestehenden Eintrag mit einer HOEHEREN Runde
        // einsortiert - ergibt automatisch 1.-von-A, 1.-von-B, 2.-von-A, ...
        // statt A,A,A,B. Gaeste ohne bekannten Token (z.B. sehr alte Daten
        // ohne guest_token) werden ueber einen gemeinsamen Platzhalter-
        // Schluessel wie ein einzelner "Gast" behandelt.
        $counts = [];
        $roundOf = [];
        for ($i = $manualEnd; $i < $guestEnd; $i++) {
            $tok = $items[$i]['guest_token'] ?? '';
            $counts[$tok] = ($counts[$tok] ?? 0) + 1;
            $roundOf[$i] = $counts[$tok];
        }
        $newRound = ($counts[$guestToken ?? ''] ?? 0) + 1;
        for ($i = $manualEnd; $i < $guestEnd; $i++) {
            if ($roundOf[$i] > $newRound) {
                return $i;
            }
        }
        return $guestEnd;
    }

    /**
     * Stellt einen Track wieder ganz vorn in der Playlist ein (Player-
     * "Zurueck"-Button): der Track wurde beim Vorwaertsspielen per
     * markPlayed() aus der Playlist entfernt, soll beim Zurueckspringen
     * aber wieder sichtbar sein - an derselben Stelle (Position 0), an der
     * der aktuell spielende Track ueblicherweise steht. Steht er (Edgecase)
     * doch noch in der Playlist, wird er stattdessen nur nach vorn verschoben
     * statt doppelt eingefuegt.
     */
    public function restoreAtFront(int $trackId, string $source = self::SOURCE_MANUAL, ?int $requestId = null): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id FROM playlist WHERE track_id = ? LIMIT 1');
        $stmt->execute([$trackId]);
        $existing = $stmt->fetch();
        if ($existing) {
            $ids = array_column($this->all(), 'id');
            $ids = array_values(array_diff($ids, [(int) $existing['id']]));
            array_unshift($ids, (int) $existing['id']);
            $this->reorder($ids);
            return (int) $existing['id'];
        }
        return $this->insertAtIndex($trackId, $source, $requestId, 0);
    }

    /** Fuegt einen neuen Track an einem bestimmten Index der Reihenfolge ein und nummeriert die Positionen neu durch. */
    private function insertAtIndex(int $trackId, string $source, ?int $requestId, int $index): int
    {
        $pdo = Database::get();
        $ids = array_column($this->all(), 'id');

        $stmt = $pdo->prepare(
            'INSERT INTO playlist (track_id, source, request_id, position, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$trackId, $source, $requestId, count($ids), Util::now()]);
        $newId = (int) $pdo->lastInsertId();

        array_splice($ids, $index, 0, [$newId]);
        $this->reorder($ids);
        return $newId;
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
     * Fuellt die Playlist auf, sofern sie unter der in den Einstellungen
     * festgelegten Zielanzahl (Setting auto_dj_target_count, 0-15, Standard 3)
     * liegt: fuegt noch nicht (oder am laengsten nicht mehr) gespielte Tracks
     * aus der Bibliothek hinzu, bis das Ziel erreicht ist. Anders als eine
     * getrennte "Mindest-/Zielspanne" wird hier IMMER exakt auf die eine
     * konfigurierte Zahl aufgefuellt - sobald ein Track abgespielt wird und
     * die Playlist dadurch unter das Ziel faellt, ergaenzt der naechste
     * topUp()-Aufruf (SSE-Tick, Trackwechsel, neuer Gastwunsch) sofort wieder
     * genau einen Track, statt erst bei einer tieferen Schwelle in groesserem
     * Sprung nachzufuellen. 0 = Auto-DJ ergaenzt gar nichts (nur echte
     * Gastwuensche werden noch automatisch angenommen). Vermeidet dabei nach
     * Moeglichkeit, denselben Kuenstler direkt zu wiederholen (weiche
     * Praeferenz - siehe pickCandidate()).
     *
     * Garantiert das Auffuellen bis zum Ziel, sofern die Bibliothek
     * ueberhaupt genug Tracks enthaelt, die nicht schon in der Playlist
     * stehen: reicht die Sperrfrist-taugliche Auswahl nicht aus (z.B. weil
     * eine kleine Bibliothek durchgespielt wurde), wird als letzter Ausweg
     * auch ein eigentlich noch gesperrter Track gewaehlt (der am laengsten
     * nicht gespielte zuerst) - eine leere/zu kurze Playlist waere schlimmer
     * als eine faellige Wiederholung.
     *
     * @return int Anzahl tatsaechlich hinzugefuegter Tracks
     */
    public function topUp(): int
    {
        $targetCount = max(0, min(15, (int) (new SettingRepository())->get('auto_dj_target_count', '3')));
        $current = $this->count();
        if ($current >= $targetCount) {
            return 0;
        }
        $addCount = $targetCount - $current;

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
                // Letzter Ausweg: Sperrfrist ignorieren, sonst bliebe die
                // Playlist unter $minCount haengen (z.B. kleine Bibliothek
                // komplett "verbraucht"). Waehlt trotzdem den am laengsten
                // nicht gespielten/entfernten Track zuerst.
                $candidate = $this->pickCandidate($pdo, $excludeIds, null, null);
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

    /** Waehlt den naechsten Auto-DJ-Kandidaten aus (am laengsten nicht gespielt), optional ohne $avoidArtist.
     * $cutoff === null ignoriert die Sperrfrist komplett (letzter Ausweg, siehe topUp()). */
    private function pickCandidate(\PDO $pdo, array $excludeIds, ?string $cutoff, ?string $avoidArtist): ?array
    {
        $conditions = [];
        $params = [];
        if (!empty($excludeIds)) {
            $conditions[] = 'id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
            $params = array_merge($params, $excludeIds);
        }
        if ($cutoff !== null) {
            $conditions[] = '(last_played_at IS NULL OR last_played_at <= ? OR (lock_released_at IS NOT NULL AND lock_released_at > last_played_at))';
            $params[] = $cutoff;
            // Gerade erst vom Admin aus der Playlist entfernte Tracks sollen der
            // gleichen Sperrfrist unterliegen wie kuerzlich gespielte - sonst
            // waehlt der Auto-DJ genau den Track sofort wieder aus (Bug-Report).
            $conditions[] = '(removed_at IS NULL OR removed_at <= ? OR (lock_released_at IS NOT NULL AND lock_released_at > removed_at))';
            $params[] = $cutoff;
        }
        if ($avoidArtist !== null) {
            $conditions[] = '(artist IS NULL OR artist <> ?)';
            $params[] = $avoidArtist;
        }

        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
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
