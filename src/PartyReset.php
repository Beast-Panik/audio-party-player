<?php

namespace App;

/**
 * Setzt alle gaestebezogenen Daten komplett zurueck (Wunschliste,
 * Namens-Sperren, Kontingent-Resets, Herz-Reaktionen) - wird bei jedem
 * Wechsel zwischen "Live" und "Offline" ausgeloest (siehe
 * api/live_status.php), da beide Uebergaenge als Grenze zu einer neuen
 * Party gedacht sind: alte Gaeste-/Wunschdaten sollen dann nicht in die
 * naechste Party hinueberlaufen. Playlist, Bibliothek und Einstellungen
 * bleiben unangetastet.
 */
final class PartyReset
{
    public static function resetGuestData(): void
    {
        $pdo = Database::get();
        $pdo->exec('DELETE FROM requests');
        $pdo->exec('DELETE FROM guest_profiles');
        $pdo->exec('DELETE FROM guest_limit_resets');
        $pdo->exec('DELETE FROM track_reactions');
    }
}
