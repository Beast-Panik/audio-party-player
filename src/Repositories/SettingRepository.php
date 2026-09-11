<?php

namespace App\Repositories;

use App\Database;

final class SettingRepository
{
    /** Prozessweiter Cache aller Settings - vermeidet, dass Code-Pfade mit
     * vielen einzelnen get()-Aufrufen (z.B. events_payload_admin() in
     * api/events.php, bei jedem Echtzeit-Tick fuer jede verbundene Sitzung)
     * jedes Mal einzeln die Datenbank abfragen. Wird von set() sofort
     * mitgepflegt, damit ein get() innerhalb derselben Anfrage nie einen
     * veralteten Wert sieht. Ueberlebt nur die Laufzeit einer einzelnen
     * PHP-Anfrage (statisch pro Prozess) - Aenderungen aus einer anderen,
     * parallel laufenden Anfrage koennen daher fuer den Rest einer laenger
     * laufenden Anfrage (z.B. den aktuellen SSE-Verbindungszyklus) leicht
     * verzoegert ankommen, spaetestens mit der naechsten Anfrage/Verbindung.
     */
    private static ?array $cache = null;

    private function cache(): array
    {
        if (self::$cache === null) {
            self::$cache = $this->all();
        }
        return self::$cache;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $cache = $this->cache();
        return array_key_exists($key, $cache) ? $cache[$key] : $default;
    }

    /**
     * Wie get(), aber ignoriert/aktualisiert den Prozess-Cache - fuer Settings,
     * bei denen die normalerweise tolerierte leichte Verzoegerung (siehe
     * Klassen-Docblock) zu echten Fehlern fuehren kann. Konkreter Fall:
     * PlayerSession::touch() liest+schreibt player_master_session_id in
     * jedem Zyklus des bis zu 8s laufenden Admin-SSE-Streams (api/events.php)
     * - ohne Frischlesen sah der laengst laufende Stream einen parallelen
     * Logout (der die Master-Rolle freigibt) nicht und hat sie beim naechsten
     * Zyklus aus dem veralteten Cache heraus fuer die schon abgemeldete
     * Sitzung "wiederbelebt" (Nutzer-Report).
     */
    public function getFresh(string $key, ?string $default = null): ?string
    {
        $stmt = Database::get()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) {
            if (self::$cache !== null) {
                unset(self::$cache[$key]);
            }
            return $default;
        }
        if (self::$cache !== null) {
            self::$cache[$key] = $row['setting_value'];
        }
        return $row['setting_value'];
    }

    public function set(string $key, ?string $value): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT setting_key FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        if ($stmt->fetch()) {
            $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
        } else {
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
        }
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public function all(): array
    {
        $rows = Database::get()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }
}
