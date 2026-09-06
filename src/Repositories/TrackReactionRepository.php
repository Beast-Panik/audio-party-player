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

    // Grobe IP-Bremse als Backstop, falls jemand den Gast-Cookie zwischen
    // Requests rotiert (siehe GuestIdentity) - ohne die waere der
    // Guest-Token-Cooldown oben trivial per Skript umgehbar und
    // track_reactions liesse sich unbegrenzt volllaufen lassen.
    private const IP_LIMIT_COUNT = 30;
    private const IP_LIMIT_SECONDS = 60;

    /**
     * Legt eine Reaktion an, sofern derselbe Gast fuer denselben Track nicht
     * innerhalb der Cooldown-Zeit schon reagiert hat (Spam-Bremse gegen
     * gedrueckt gehaltene/schnell wiederholt geklickte Buttons), und diese
     * IP nicht bereits das grobe Gesamt-Limit erreicht hat.
     */
    public function add(int $trackId, string $guestToken): bool
    {
        $pdo = Database::get();
        $now = time();

        $ipCutoff = date('Y-m-d H:i:s', $now - self::IP_LIMIT_SECONDS);
        $ipHash = Util::clientIpHash();
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM track_reactions WHERE ip_hash = ? AND created_at >= ?');
        $stmt->execute([$ipHash, $ipCutoff]);
        if ((int) $stmt->fetch()['c'] >= self::IP_LIMIT_COUNT) {
            return false;
        }

        $cutoff = date('Y-m-d H:i:s', $now - self::COOLDOWN_SECONDS);
        $stmt = $pdo->prepare(
            'SELECT 1 FROM track_reactions WHERE track_id = ? AND guest_token = ? AND created_at >= ? LIMIT 1'
        );
        $stmt->execute([$trackId, $guestToken, $cutoff]);
        if ($stmt->fetch()) {
            return false;
        }

        $pdo->prepare('INSERT INTO track_reactions (track_id, guest_token, ip_hash, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$trackId, $guestToken, $ipHash, Util::now()]);
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
