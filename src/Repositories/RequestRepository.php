<?php

namespace App\Repositories;

use App\Database;
use App\Util;

final class RequestRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PLAYED = 'played';
    public const STATUS_REJECTED = 'rejected';

    public function create(int $trackId, ?string $guestName, ?string $guestToken = null, string $status = self::STATUS_PENDING): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO requests (track_id, guest_name, guest_token, status, ip_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $now = Util::now();
        $stmt->execute([$trackId, $guestName, $guestToken, $status, Util::clientIpHash(), $now, $now]);
        return (int) Database::get()->lastInsertId();
    }

    /** Anzahl Wuensche der letzten $minutes Minuten von dieser IP (grobe Spam-Bremse, IP-basiert). */
    public function countRecentFromIp(int $minutes = 5): int
    {
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        $stmt = Database::get()->prepare('SELECT COUNT(*) AS c FROM requests WHERE ip_hash = ? AND created_at >= ?');
        $stmt->execute([Util::clientIpHash(), $since]);
        return (int) $stmt->fetch()['c'];
    }

    /**
     * Effektiver Fensterbeginn fuer das Gast-Kontingent: normalerweise
     * "jetzt minus $minutes", aber wenn der Admin das Kontingent dieses
     * Gasts manuell zurueckgesetzt hat, gilt stattdessen dessen (spaeterer)
     * Reset-Zeitpunkt - dadurch zaehlen aeltere Wuensche vor dem Reset
     * nicht mehr mit, ohne dass ihre requests-Zeilen geloescht werden.
     */
    private function effectiveSince(string $guestToken, int $minutes): string
    {
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        $stmt = Database::get()->prepare('SELECT reset_at FROM guest_limit_resets WHERE guest_token = ?');
        $stmt->execute([$guestToken]);
        $row = $stmt->fetch();
        if ($row && $row['reset_at'] > $since) {
            return $row['reset_at'];
        }
        return $since;
    }

    /** Anzahl Wuensche der letzten $minutes Minuten von diesem Gast-Cookie (fuer das einstellbare Limit). */
    public function countRecentByGuestToken(string $guestToken, int $minutes): int
    {
        $since = $this->effectiveSince($guestToken, $minutes);
        $stmt = Database::get()->prepare('SELECT COUNT(*) AS c FROM requests WHERE guest_token = ? AND created_at >= ?');
        $stmt->execute([$guestToken, $since]);
        return (int) $stmt->fetch()['c'];
    }

    /** Zeitpunkt des aeltesten noch "zaehlenden" Wunsches - daraus laesst sich die Restwartezeit berechnen. */
    public function oldestRecentByGuestToken(string $guestToken, int $minutes): ?string
    {
        $since = $this->effectiveSince($guestToken, $minutes);
        $stmt = Database::get()->prepare(
            'SELECT created_at FROM requests WHERE guest_token = ? AND created_at >= ? ORDER BY created_at ASC LIMIT 1'
        );
        $stmt->execute([$guestToken, $since]);
        $row = $stmt->fetch();
        return $row ? $row['created_at'] : null;
    }

    /** Setzt/erneuert den manuellen Admin-Reset des Wunsch-Kontingents fuer einen Gast. */
    public function setLimitReset(string $guestToken): void
    {
        $pdo = Database::get();
        $now = Util::now();
        if (Database::driver() === 'mysql') {
            $pdo->prepare('INSERT INTO guest_limit_resets (guest_token, reset_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE reset_at = VALUES(reset_at)')
                ->execute([$guestToken, $now]);
        } else {
            $pdo->prepare('INSERT INTO guest_limit_resets (guest_token, reset_at) VALUES (?, ?) ON CONFLICT(guest_token) DO UPDATE SET reset_at = excluded.reset_at')
                ->execute([$guestToken, $now]);
        }
    }

    /**
     * Zusammenfassung pro Gast (die den Wunsch-Limit-Cookie im Zeitfenster
     * genutzt haben) fuer die Admin-Uebersicht: Name, Anzahl genutzter
     * Wuensche und aeltester zaehlender Wunsch (fuer den Reset-Countdown).
     */
    public function listGuestsSummary(int $minutes): array
    {
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        $sql = "SELECT r.guest_token,
                       gp.name AS locked_name,
                       MAX(r.guest_name) AS last_guest_name,
                       COUNT(*) AS used,
                       MIN(r.created_at) AS oldest_created_at,
                       MAX(r.created_at) AS last_active
                FROM requests r
                LEFT JOIN guest_limit_resets grl ON grl.guest_token = r.guest_token
                LEFT JOIN guest_profiles gp ON gp.guest_token = r.guest_token
                WHERE r.guest_token IS NOT NULL
                  AND r.created_at >= CASE WHEN grl.reset_at IS NOT NULL AND grl.reset_at > ? THEN grl.reset_at ELSE ? END
                GROUP BY r.guest_token, gp.name
                ORDER BY last_active DESC";
        $stmt = Database::get()->prepare($sql);
        $stmt->execute([$since, $since]);
        return $stmt->fetchAll();
    }

    /**
     * Wuensche fuer die Gast-Ansicht: alle, die kuerzlich angelegt ODER
     * kuerzlich im Status geaendert wurden (zeigt auch gespielte/abgelehnte
     * Wuensche kurz an), aeltere fallen automatisch aus der Liste.
     */
    public function listRecentForGuestFeed(int $minutes = 60, int $limit = 100): array
    {
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        $sql = 'SELECT r.*, t.title, t.artist, t.album, t.duration_seconds
                FROM requests r JOIN tracks t ON t.id = r.track_id
                WHERE r.updated_at >= ?
                ORDER BY r.created_at DESC LIMIT ?';
        $stmt = Database::get()->prepare($sql);
        $stmt->bindValue(1, $since);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function listWithTracks(?string $status = null, int $limit = 200): array
    {
        $pdo = Database::get();
        $sql = 'SELECT r.*, t.title, t.artist, t.album, t.duration_seconds
                FROM requests r JOIN tracks t ON t.id = r.track_id';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE r.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY r.created_at DESC LIMIT ?';
        $params[] = $limit;
        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p, is_int($p) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Wuensche, die noch "unterwegs" sind (weder gespielt noch abgelehnt) - fuer die Gast-Ansicht. */
    public function listUpcomingWithTracks(int $limit = 100): array
    {
        $sql = 'SELECT r.*, t.title, t.artist, t.album, t.duration_seconds
                FROM requests r JOIN tracks t ON t.id = r.track_id
                WHERE r.status IN (?, ?)
                ORDER BY r.created_at DESC LIMIT ?';
        $stmt = Database::get()->prepare($sql);
        $stmt->bindValue(1, self::STATUS_PENDING);
        $stmt->bindValue(2, self::STATUS_APPROVED);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function countPending(): int
    {
        $stmt = Database::get()->query("SELECT COUNT(*) AS c FROM requests WHERE status = 'pending'");
        return (int) $stmt->fetch()['c'];
    }

    public function updateStatus(int $id, string $status): void
    {
        $stmt = Database::get()->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$status, Util::now(), $id]);
    }

    public function delete(int $id): void
    {
        Database::get()->prepare('DELETE FROM requests WHERE id = ?')->execute([$id]);
    }

    public function clearOld(int $days = 30): void
    {
        $before = date('Y-m-d H:i:s', time() - $days * 86400);
        $stmt = Database::get()->prepare("DELETE FROM requests WHERE status IN ('played','rejected') AND updated_at < ?");
        $stmt->execute([$before]);
    }
}
