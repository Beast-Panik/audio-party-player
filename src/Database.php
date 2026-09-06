<?php

namespace App;

/**
 * Duenner PDO-Wrapper. Unterstuetzt SQLite (Standard, keine Einrichtung
 * noetig) und MySQL/MariaDB. Tabellen/Spalten werden bei der Einrichtung
 * bzw. ueber den Update-Schritt in install.php angelegt/migriert (siehe
 * SCHEMA_VERSION), nicht mehr automatisch bei jeder Anfrage.
 */
final class Database
{
    private static ?\PDO $pdo = null;
    private static ?string $driver = null;

    /**
     * Schema-Version des Codes. Bei strukturellen Aenderungen (neue Spalten/
     * Tabellen) hier hochzaehlen und die Migration in schemaStatements()/
     * ensureSchemaOn() ergaenzen. Die Datenbank wird NICHT mehr automatisch
     * bei jeder Anfrage migriert - stattdessen erkennt bootstrap.php eine
     * hoehere Code-Version, schickt einen angemeldeten Admin automatisch zu
     * install.php und die Migration laeuft dort erst nach einem Klick.
     */
    public const SCHEMA_VERSION = 4;

    public static function get(): \PDO
    {
        if (self::$pdo === null) {
            self::connect();
        }
        return self::$pdo;
    }

    /** Aktuell in der Datenbank hinterlegte Schema-Version. */
    public static function installedSchemaVersion(): int
    {
        try {
            $stmt = self::get()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $stmt->execute(['schema_version']);
            $row = $stmt->fetch();
            // Fehlender Eintrag = Installation von vor Einfuehrung dieser
            // Versionspruefung. Deren Schema wurde bis dahin bei jeder
            // Anfrage automatisch auf den damals aktuellen Stand (= Version 1)
            // gebracht, entspricht also bereits Version 1.
            return $row ? (int) $row['setting_value'] : 1;
        } catch (\Throwable $e) {
            return self::SCHEMA_VERSION;
        }
    }

    public static function markSchemaVersion(\PDO $pdo, int $version): void
    {
        $stmt = $pdo->prepare('SELECT setting_key FROM settings WHERE setting_key = ?');
        $stmt->execute(['schema_version']);
        if ($stmt->fetch()) {
            $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([(string) $version, 'schema_version']);
        } else {
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute(['schema_version', (string) $version]);
        }
    }

    /** true, wenn die Code-Version eine neuere Schema-Version braucht als aktuell in der DB steht. */
    public static function needsUpdate(): bool
    {
        return Config::isInstalled() && self::installedSchemaVersion() < self::SCHEMA_VERSION;
    }

    public static function driver(): string
    {
        if (self::$driver === null) {
            self::$driver = Config::get('db.driver', 'sqlite');
        }
        return self::$driver;
    }

    /** Fuer install.php: eigene Verbindung mit expliziten Zugangsdaten testen/aufbauen. */
    public static function connectWith(string $driver, array $mysql = [], ?string $sqlitePath = null): \PDO
    {
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $mysql['host'],
                (int) ($mysql['port'] ?? 3306),
                $mysql['name'],
                $mysql['charset'] ?? 'utf8mb4'
            );
            return new \PDO($dsn, $mysql['user'], $mysql['pass'], $options);
        }

        $path = $sqlitePath ?? (dirname(__DIR__) . '/data/database.sqlite');
        $pdo = new \PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        return $pdo;
    }

    private static function connect(): void
    {
        $driver = self::driver();
        if ($driver === 'mysql') {
            self::$pdo = self::connectWith('mysql', Config::get('db.mysql', []));
        } else {
            self::$pdo = self::connectWith('sqlite', [], Config::get('db.sqlite_path'));
        }
    }

    public static function ensureSchemaOn(\PDO $pdo, string $driver): void
    {
        // MySQL kennt kein "CREATE INDEX IF NOT EXISTS" - beim (Neu-)Anlegen
        // bereits vorhandener Tabellen/Indizes ignorieren wir daher gezielt
        // "existiert bereits"-Fehler statt abzubrechen.
        self::runStatements($pdo, self::schemaStatements($driver));

        // Leichte Mini-Migration: Spalten, die in einer bereits bestehenden
        // Installation (aeltere Version) noch fehlen, ergaenzen - MUSS vor
        // Indizes laufen, die sich auf diese Spalten beziehen.
        self::ensureColumn($pdo, $driver, 'requests', 'guest_token', $driver === 'mysql' ? 'VARCHAR(32)' : 'TEXT');
        self::ensureColumn($pdo, $driver, 'tracks', 'last_played_at', $driver === 'mysql' ? 'DATETIME NULL' : 'TEXT');
        self::ensureColumn($pdo, $driver, 'playlist', 'position', $driver === 'mysql' ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, $driver, 'tracks', 'cover_ext', $driver === 'mysql' ? 'VARCHAR(8) NULL' : 'TEXT');
        self::ensureColumn($pdo, $driver, 'tracks', 'lock_released_at', $driver === 'mysql' ? 'DATETIME NULL' : 'TEXT');

        self::runStatements($pdo, [
            $driver === 'mysql'
                ? 'CREATE INDEX idx_requests_guest_token ON requests (guest_token)'
                : 'CREATE INDEX IF NOT EXISTS idx_requests_guest_token ON requests (guest_token)',
        ]);

        self::backfillPlaylistPositions($pdo);
    }

    /**
     * Einmalige Nummerierung bestehender Playlist-Eintraege nach
     * Einfuege-Reihenfolge, direkt nachdem die position-Spalte ergaenzt
     * wurde (dort stehen dann ueberall Nullen). Sobald irgendein Eintrag
     * eine Position ungleich 0 hat (durch diesen Lauf selbst oder durch
     * spaeteres manuelles Umsortieren), gilt die Migration als erledigt
     * und wird bei folgenden Updates uebersprungen.
     */
    private static function backfillPlaylistPositions(\PDO $pdo): void
    {
        $already = (int) $pdo->query('SELECT COUNT(*) AS c FROM playlist WHERE position != 0')->fetch()['c'];
        if ($already > 0) {
            return;
        }
        $rows = $pdo->query('SELECT id FROM playlist ORDER BY id ASC')->fetchAll();
        $stmt = $pdo->prepare('UPDATE playlist SET position = ? WHERE id = ?');
        foreach ($rows as $i => $row) {
            $stmt->execute([$i, $row['id']]);
        }
    }

    private static function runStatements(\PDO $pdo, array $sqlStatements): void
    {
        foreach ($sqlStatements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                $code = $e->errorInfo[1] ?? null; // MySQL-Fehlercode
                $alreadyExists = $code === 1061 || $code === 1050; // Duplicate key/table name
                if (!$alreadyExists) {
                    throw $e;
                }
            }
        }
    }

    private static function ensureColumn(\PDO $pdo, string $driver, string $table, string $column, string $definition): void
    {
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([$table, $column]);
            $exists = (int) $stmt->fetch()['c'] > 0;
        } else {
            $exists = false;
            foreach ($pdo->query("PRAGMA table_info({$table})") as $row) {
                if ($row['name'] === $column) {
                    $exists = true;
                    break;
                }
            }
        }

        if (!$exists) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    /**
     * @return string[] einzelne CREATE-TABLE/INDEX-Anweisungen (kein
     * Mehrfach-Statement-Exec noetig, funktioniert so auf jedem Treiber).
     */
    private static function schemaStatements(string $driver): array
    {
        $isMysql = $driver === 'mysql';

        $pk = $isMysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $isMysql ? 'TEXT' : 'TEXT';
        $varchar190 = $isMysql ? 'VARCHAR(190)' : 'TEXT';
        $varchar32 = $isMysql ? 'VARCHAR(32)' : 'TEXT';
        $datetime = $isMysql ? 'DATETIME NULL' : 'TEXT';
        $datetimeNotNull = $isMysql ? 'DATETIME NOT NULL' : "TEXT NOT NULL";
        $bigint = $isMysql ? 'BIGINT' : 'INTEGER';
        $int = $isMysql ? 'INT' : 'INTEGER';
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $statements = [];

        $statements[] = "CREATE TABLE IF NOT EXISTS users (
            id {$pk},
            username {$varchar190} NOT NULL,
            password_hash {$varchar190} NOT NULL,
            role {$varchar32} NOT NULL DEFAULT 'admin',
            created_at {$datetimeNotNull},
            last_login_at {$datetime}
        ){$engine}";

        $statements[] = $isMysql
            ? "CREATE UNIQUE INDEX idx_users_username ON users (username)"
            : "CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users (username)";

        $statements[] = "CREATE TABLE IF NOT EXISTS libraries (
            id {$pk},
            name {$varchar190} NOT NULL,
            path {$text} NOT NULL,
            recursive {$int} NOT NULL DEFAULT 1,
            last_scanned_at {$datetime},
            track_count {$int} NOT NULL DEFAULT 0,
            created_at {$datetimeNotNull}
        ){$engine}";

        $statements[] = "CREATE TABLE IF NOT EXISTS tracks (
            id {$pk},
            library_id {$int} NOT NULL,
            relpath {$text} NOT NULL,
            filename {$varchar190} NOT NULL,
            title {$varchar190},
            artist {$varchar190},
            album {$varchar190},
            album_artist {$varchar190},
            genre {$varchar190},
            track_no {$int},
            disc_no {$int},
            year {$int},
            duration_seconds {$int},
            bitrate {$int},
            filesize {$bigint},
            mtime {$bigint},
            codec {$varchar32},
            added_at {$datetimeNotNull},
            updated_at {$datetimeNotNull}
        ){$engine}";

        $statements[] = $isMysql
            ? "CREATE UNIQUE INDEX idx_tracks_lib_relpath ON tracks (library_id, relpath(500))"
            : "CREATE UNIQUE INDEX IF NOT EXISTS idx_tracks_lib_relpath ON tracks (library_id, relpath)";
        $statements[] = $isMysql
            ? "CREATE INDEX idx_tracks_title ON tracks (title)"
            : "CREATE INDEX IF NOT EXISTS idx_tracks_title ON tracks (title)";
        $statements[] = $isMysql
            ? "CREATE INDEX idx_tracks_artist ON tracks (artist)"
            : "CREATE INDEX IF NOT EXISTS idx_tracks_artist ON tracks (artist)";

        $statements[] = "CREATE TABLE IF NOT EXISTS requests (
            id {$pk},
            track_id {$int} NOT NULL,
            guest_name {$varchar190},
            guest_token {$varchar32},
            status {$varchar32} NOT NULL DEFAULT 'pending',
            ip_hash {$varchar32},
            created_at {$datetimeNotNull},
            updated_at {$datetimeNotNull}
        ){$engine}";

        $statements[] = $isMysql
            ? "CREATE INDEX idx_requests_status ON requests (status)"
            : "CREATE INDEX IF NOT EXISTS idx_requests_status ON requests (status)";
        // Hinweis: Index auf guest_token wird erst NACH der Spalten-Migration
        // weiter unten in ensureSchemaOn() angelegt (Spalte existiert bei
        // aelteren Installationen zu diesem Zeitpunkt noch nicht).

        $statements[] = "CREATE TABLE IF NOT EXISTS playlist (
            id {$pk},
            track_id {$int} NOT NULL,
            source {$varchar32} NOT NULL DEFAULT 'manual',
            request_id {$int},
            created_at {$datetimeNotNull}
        ){$engine}";

        $statements[] = "CREATE TABLE IF NOT EXISTS settings (
            setting_key {$varchar190} NOT NULL,
            setting_value {$text}
        ){$engine}";

        $statements[] = $isMysql
            ? "CREATE UNIQUE INDEX idx_settings_key ON settings (setting_key)"
            : "CREATE UNIQUE INDEX IF NOT EXISTS idx_settings_key ON settings (setting_key)";

        // Pro Geraet (guest_token-Cookie) fuer 24h fest vergebener Name -
        // dient als "Namens-Sperre" auf der Wunschseite, siehe
        // GuestProfileRepository::lockName() / api/requests.php Action set_name.
        $statements[] = "CREATE TABLE IF NOT EXISTS guest_profiles (
            guest_token {$varchar32} NOT NULL,
            name {$varchar190} NOT NULL,
            created_at {$datetimeNotNull}
        ){$engine}";
        $statements[] = $isMysql
            ? "CREATE UNIQUE INDEX idx_guest_profiles_token ON guest_profiles (guest_token)"
            : "CREATE UNIQUE INDEX IF NOT EXISTS idx_guest_profiles_token ON guest_profiles (guest_token)";

        // Manueller Admin-Reset des Wunsch-Kontingents: verschiebt die
        // untere Zeitgrenze fuer die Kontingent-Zaehlung eines Gasts nach
        // vorn, ohne dessen bisherige requests-Zeilen zu loeschen.
        $statements[] = "CREATE TABLE IF NOT EXISTS guest_limit_resets (
            guest_token {$varchar32} NOT NULL,
            reset_at {$datetimeNotNull}
        ){$engine}";
        $statements[] = $isMysql
            ? "CREATE UNIQUE INDEX idx_guest_limit_resets_token ON guest_limit_resets (guest_token)"
            : "CREATE UNIQUE INDEX IF NOT EXISTS idx_guest_limit_resets_token ON guest_limit_resets (guest_token)";

        // Leichtgewichtige Gaeste-Reaktion (Herz-Button) auf den aktuell
        // laufenden Track - eine Zeile pro Klick, Spam-Bremse siehe
        // TrackReactionRepository::add().
        $statements[] = "CREATE TABLE IF NOT EXISTS track_reactions (
            id {$pk},
            track_id {$int} NOT NULL,
            guest_token {$varchar32} NOT NULL,
            created_at {$datetimeNotNull}
        ){$engine}";
        $statements[] = $isMysql
            ? "CREATE INDEX idx_track_reactions_track_created ON track_reactions (track_id, created_at)"
            : "CREATE INDEX IF NOT EXISTS idx_track_reactions_track_created ON track_reactions (track_id, created_at)";

        return $statements;
    }
}
