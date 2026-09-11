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
    // Grobe IP-Bremse als Backstop, falls jemand den Gast-Cookie zwischen
    // Requests rotiert (siehe GuestIdentity) - ohne die waere die Sperre pro
    // Track+Spielinstanz unten trivial per Skript umgehbar und
    // track_reactions liesse sich unbegrenzt volllaufen lassen.
    private const IP_LIMIT_COUNT = 30;
    private const IP_LIMIT_SECONDS = 60;

    /**
     * Legt eine Reaktion an, sofern derselbe Gast fuer diesen Track in der
     * AKTUELLEN Spielinstanz (tracks.play_seq, hochgezaehlt bei jedem
     * (Wieder-)Start ueber TrackRepository::bumpPlaySeq()) noch nicht
     * reagiert hat - pro Track und Wiedergabe genau ein Herz je Gast, wird
     * der Track spaeter erneut gespielt, darf wieder reagiert werden. Und
     * sofern diese IP nicht bereits das grobe Gesamt-Limit erreicht hat.
     */
    public function add(int $trackId, string $guestToken): bool
    {
        $pdo = Database::get();

        $ipCutoff = date('Y-m-d H:i:s', time() - self::IP_LIMIT_SECONDS);
        $ipHash = Util::clientIpHash();
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM track_reactions WHERE ip_hash = ? AND created_at >= ?');
        $stmt->execute([$ipHash, $ipCutoff]);
        if ((int) $stmt->fetch()['c'] >= self::IP_LIMIT_COUNT) {
            return false;
        }

        $stmt = $pdo->prepare('SELECT play_seq FROM tracks WHERE id = ?');
        $stmt->execute([$trackId]);
        $track = $stmt->fetch();
        if (!$track) {
            return false;
        }
        $playSeq = (int) $track['play_seq'];

        $stmt = $pdo->prepare(
            'SELECT 1 FROM track_reactions WHERE track_id = ? AND guest_token = ? AND play_seq = ? LIMIT 1'
        );
        $stmt->execute([$trackId, $guestToken, $playSeq]);
        if ($stmt->fetch()) {
            return false;
        }

        try {
            $pdo->prepare('INSERT INTO track_reactions (track_id, guest_token, ip_hash, play_seq, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$trackId, $guestToken, $ipHash, $playSeq, Util::now()]);
            return true;
        } catch (\PDOException $e) {
            // Race: eine parallele Anfrage desselben Gasts (z.B. Doppelklick)
            // hat die Reaktion zwischen der Pruefung oben und diesem INSERT
            // bereits angelegt (idx_track_reactions_dedup ist UNIQUE) - dann
            // eben wie eine bereits vorhandene Reaktion behandeln statt einer 500.
            return false;
        }
    }

    /** Anzahl Reaktionen der aktuellen Spielinstanz fuer die "laeuft gerade"-Anzeige. */
    public function countForTrack(int $trackId): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT play_seq FROM tracks WHERE id = ?');
        $stmt->execute([$trackId]);
        $track = $stmt->fetch();
        if (!$track) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM track_reactions WHERE track_id = ? AND play_seq = ?');
        $stmt->execute([$trackId, (int) $track['play_seq']]);
        return (int) $stmt->fetch()['c'];
    }
}
