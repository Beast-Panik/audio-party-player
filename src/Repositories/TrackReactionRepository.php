<?php

namespace App\Repositories;

use App\Database;
use App\Util;

/**
 * Leichtgewichtige Gaeste-Reaktion (Herz-Button) auf den aktuell laufenden
 * Track - reiner Stimmungsindikator ohne den vollen Wunsch-Flow.
 */
final class TrackReactionRepository
{
    private const COOLDOWN_SECONDS = 3;

    /**
     * Legt eine Reaktion an, sofern derselbe Gast fuer denselben Track nicht
     * innerhalb der Cooldown-Zeit schon reagiert hat (Spam-Bremse gegen
     * gedrueckt gehaltene/schnell wiederholt geklickte Buttons).
     */
    public function add(int $trackId, string $guestToken): bool
    {
        $pdo = Database::get();
        $cutoff = date('Y-m-d H:i:s', time() - self::COOLDOWN_SECONDS);
        $stmt = $pdo->prepare(
            'SELECT 1 FROM track_reactions WHERE track_id = ? AND guest_token = ? AND created_at >= ? LIMIT 1'
        );
        $stmt->execute([$trackId, $guestToken, $cutoff]);
        if ($stmt->fetch()) {
            return false;
        }

        $pdo->prepare('INSERT INTO track_reactions (track_id, guest_token, created_at) VALUES (?, ?, ?)')
            ->execute([$trackId, $guestToken, Util::now()]);
        return true;
    }

    /** Anzahl Reaktionen fuer die "laeuft gerade"-Anzeige (nur die letzten $sinceMinutes Minuten). */
    public function countForTrack(int $trackId, int $sinceMinutes = 30): int
    {
        $since = date('Y-m-d H:i:s', time() - $sinceMinutes * 60);
        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) AS c FROM track_reactions WHERE track_id = ? AND created_at >= ?'
        );
        $stmt->execute([$trackId, $since]);
        return (int) $stmt->fetch()['c'];
    }
}
