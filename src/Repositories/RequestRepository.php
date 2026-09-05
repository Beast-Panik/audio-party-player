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

    /** Anzahl Wuensche der letzten $minutes Minuten von diesem Gast-Cookie (fuer das einstellbare Limit). */
    public function countRecentByGuestToken(string $guestToken, int $minutes): int
    {
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        $stmt = Database::get()->prepare('SELECT COUNT(*) AS c FROM requests WHERE guest_token = ? AND created_at >= ?');
        $stmt->execute([$guestToken, $since]);
        return (int) $stmt->fetch()['c'];
    }

    /** Zeitpunkt des aeltesten noch "zaehlenden" Wunsches - daraus laesst sich die Restwartezeit berechnen. */
    public function oldestRecentByGuestToken(string $guestToken, int $minutes): ?string
    {
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        $stmt = Database::get()->prepare(
            'SELECT created_at FROM requests WHERE guest_token = ? AND created_at >= ? ORDER BY created_at ASC LIMIT 1'
        );
        $stmt->execute([$guestToken, $since]);
        $row = $stmt->fetch();
        return $row ? $row['created_at'] : null;
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
