<?php
/**
 * Party Player - pan1k.de - Konfiguration
 *
 * Diese Datei wird normalerweise automatisch von install.php erzeugt
 * (Kopie dieser Vorlage nach config/config.php mit deinen Werten).
 * Manuelles Anlegen ist ebenso moeglich, falls install.php nicht
 * genutzt werden soll.
 */

return [
    // Basis-URL der Installation, z.B. "https://party.example.de" oder
    // "http://localhost:8000" fuer den lokalen PHP-Server. Wird u.a. fuer
    // den QR-Code der Wunsch-Seite verwendet. Ohne abschliessenden Slash.
    'app_url' => 'http://localhost:8000',

    'app_name' => 'Party Player - pan1k.de',

    // 'sqlite' (Standard, keine Einrichtung noetig) oder 'mysql'
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/../data/database.sqlite',
        'mysql' => [
            'host' => 'localhost',
            'port' => 3306,
            'name' => '',
            'user' => '',
            'pass' => '',
            'charset' => 'utf8mb4',
        ],
    ],

    // Zufaelliger, langer Zufallsstring - wird von install.php generiert.
    // Dient der Session-/CSRF-Absicherung. Nach dem Setup nicht mehr aendern.
    'app_secret' => 'CHANGE-ME',

    'session_name' => 'app_sess',

    // true = ausfuehrliche Fehlermeldungen (nur fuer lokale Entwicklung!)
    'debug' => false,
];
