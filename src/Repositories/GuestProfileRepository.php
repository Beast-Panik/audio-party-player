<?php

namespace App\Repositories;

use App\Database;
use App\Util;

/**
 * Der pro Gast-Cookie fuer 24h fest vergebene Name ("Namens-Sperre" auf der
 * Wunschseite - erst nach Namenseingabe oeffnet sich die Suche, ein
 * Aenderungsversuch innerhalb der 24h wird ignoriert).
 */
final class GuestProfileRepository
{
    /** Wie lange ein einmal gesetzter Gast-Name gilt, bevor ein neuer vergeben werden darf. */
    public const NAME_LOCK_HOURS = 24;

    public function find(string $guestToken): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM guest_profiles WHERE guest_token = ?');
        $stmt->execute([$guestToken]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Sperrt den Namen fuer diesen Gast fuer $hours Stunden: innerhalb des
     * Fensters bleibt der zuerst gespeicherte Name unveraendert (ein neuer
     * Eingabeversuch wird ignoriert), danach darf ein neuer Name gesetzt
     * werden. Gibt in jedem Fall den (neuen oder weiterhin gesperrten)
     * Namen zurueck.
     */
    public function lockName(string $guestToken, string $name, int $hours = self::NAME_LOCK_HOURS): string
    {
        $existing = $this->find($guestToken);
        $cutoff = date('Y-m-d H:i:s', time() - $hours * 3600);
        if ($existing && $existing['created_at'] > $cutoff) {
            return $existing['name'];
        }

        $pdo = Database::get();
        $now = Util::now();
        if ($existing) {
            $pdo->prepare('UPDATE guest_profiles SET name = ?, created_at = ? WHERE guest_token = ?')
                ->execute([$name, $now, $guestToken]);
        } else {
            $pdo->prepare('INSERT INTO guest_profiles (guest_token, name, created_at) VALUES (?, ?, ?)')
                ->execute([$guestToken, $name, $now]);
        }
        return $name;
    }
}
