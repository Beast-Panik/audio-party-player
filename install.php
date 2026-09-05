<?php

require __DIR__ . '/bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\Database;
use App\Repositories\UserRepository;

function render_page(string $title, string $body): void
{
    echo '<!DOCTYPE html><html lang="de" data-theme="dark"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . ' · Einrichtung</title>'
        . '<link rel="stylesheet" href="assets/css/panikdark.css">'
        . '<link rel="stylesheet" href="assets/css/app.css">'
        . '</head><body class="pnk-app app-login-wrap" style="display:flex; align-items:flex-start; padding:40px 16px;">'
        . '<div class="pnk-card pnk-card--raised" style="max-width:520px; width:100%; margin:0 auto;">'
        . '<div class="pnk-card__header"><span class="pnk-card__title">Party Player - pan1k.de Einrichtung</span></div>'
        . $body
        . '</div></body></html>';
}

function field(string $label, string $name, string $value = '', string $type = 'text', bool $required = true): string
{
    $req = $required ? 'required' : '';
    return '<label class="pnk-label">' . htmlspecialchars($label, ENT_QUOTES) . '</label>'
        . '<input class="pnk-input" style="margin-bottom:12px;" type="' . $type . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '" ' . $req . '>';
}

// ---------------------------------------------------------------------
// Stufe 1: Datenbank/Grundkonfiguration (config/config.php existiert noch nicht)
// ---------------------------------------------------------------------
if (!Config::isInstalled()) {
    $configDir = __DIR__ . '/config';
    $dataDir = __DIR__ . '/data';
    $writable = is_writable($configDir) && is_writable($dataDir);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $detectedBase = $scheme . '://' . $host . app_url('');
    $detectedBase = rtrim($detectedBase, '/');

    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $driver = ($_POST['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
        $appUrl = rtrim(trim($_POST['app_url'] ?? $detectedBase), '/');
        $appName = trim($_POST['app_name'] ?? 'Party Player - pan1k.de') ?: 'Party Player - pan1k.de';

        $mysql = [
            'host' => trim($_POST['mysql_host'] ?? 'localhost'),
            'port' => (int) ($_POST['mysql_port'] ?? 3306),
            'name' => trim($_POST['mysql_name'] ?? ''),
            'user' => trim($_POST['mysql_user'] ?? ''),
            'pass' => (string) ($_POST['mysql_pass'] ?? ''),
            'charset' => 'utf8mb4',
        ];

        if (!$writable) {
            $error = 'Die Ordner config/ und data/ muessen fuer den Webserver beschreibbar sein (chmod 755 oder 777, je nach Hoster).';
        } else {
            try {
                $pdo = $driver === 'mysql'
                    ? Database::connectWith('mysql', $mysql)
                    : Database::connectWith('sqlite', [], $dataDir . '/database.sqlite');
                Database::ensureSchemaOn($pdo, $driver);
                Database::markSchemaVersion($pdo, Database::SCHEMA_VERSION);

                // App-Name direkt in die Settings-Tabelle uebernehmen (die
                // Seiten lesen den Namen von dort, nicht aus config.php).
                $stmt = $pdo->prepare('SELECT setting_key FROM settings WHERE setting_key = ?');
                $stmt->execute(['app_name']);
                if ($stmt->fetch()) {
                    $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$appName, 'app_name']);
                } else {
                    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute(['app_name', $appName]);
                }

                $config = [
                    'app_url' => $appUrl,
                    'app_name' => $appName,
                    'db' => [
                        'driver' => $driver,
                        // Absoluter Pfad (statt __DIR__-Ausdruck) - laesst sich
                        // problemlos als reiner Datenwert exportieren.
                        'sqlite_path' => $dataDir . '/database.sqlite',
                        'mysql' => $mysql,
                    ],
                    'app_secret' => bin2hex(random_bytes(32)),
                    'session_name' => 'app_sess',
                    'debug' => false,
                ];

                $php = "<?php\n\nreturn " . var_export($config, true) . ";\n";

                if (file_put_contents($configDir . '/config.php', $php) === false) {
                    $error = 'config/config.php konnte nicht geschrieben werden. Bitte Schreibrechte pruefen.';
                } else {
                    header('Location: install.php');
                    exit;
                }
            } catch (\Throwable $e) {
                $error = 'Datenbankverbindung fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }

    $body = '<p class="pnk-text-muted">Schritt 1 von 2: Datenbank &amp; Grundeinstellungen.</p>';
    if (!$writable) {
        $body .= '<div class="pnk-alert pnk-alert--warning" style="margin-bottom:16px;">Die Ordner <code>config/</code> und <code>data/</code> muessen beschreibbar sein, bevor es weitergeht.</div>';
    }
    if ($error) {
        $body .= '<div class="pnk-alert pnk-alert--danger" style="margin-bottom:16px;">' . htmlspecialchars($error, ENT_QUOTES) . '</div>';
    }

    $body .= '<form method="post" action="install.php" id="install-form">';
    $body .= field('Party-/App-Name', 'app_name', 'Party Player - pan1k.de');
    $body .= field('Basis-URL dieser Installation', 'app_url', $detectedBase);
    $body .= '<p class="pnk-text-muted" style="font-size:12px; margin:-6px 0 12px;">Ohne abschliessenden Slash, z.B. https://party.example.de oder http://localhost:8000 fuer lokale Tests.</p>';

    $body .= '<label class="pnk-label">Datenbank</label>';
    $body .= '<div class="pnk-field-row" style="margin-bottom:6px;"><input class="pnk-radio" type="radio" name="driver" value="sqlite" id="drv-sqlite" checked onclick="document.getElementById(\'mysql-fields\').style.display=\'none\'"><label for="drv-sqlite">SQLite (empfohlen, keine Einrichtung noetig)</label></div>';
    $body .= '<div class="pnk-field-row" style="margin-bottom:12px;"><input class="pnk-radio" type="radio" name="driver" value="mysql" id="drv-mysql" onclick="document.getElementById(\'mysql-fields\').style.display=\'block\'"><label for="drv-mysql">MySQL / MariaDB</label></div>';

    $body .= '<div id="mysql-fields" style="display:none;">';
    $body .= field('DB-Host', 'mysql_host', 'localhost');
    $body .= field('DB-Port', 'mysql_port', '3306', 'number');
    $body .= field('Datenbankname', 'mysql_name', '', 'text', false);
    $body .= field('DB-Benutzer', 'mysql_user', '', 'text', false);
    $body .= field('DB-Passwort', 'mysql_pass', '', 'password', false);
    $body .= '</div>';

    $body .= '<button class="pnk-btn pnk-btn--primary" type="submit" style="width:100%; margin-top:8px;" ' . ($writable ? '' : 'disabled') . '>Weiter</button>';
    $body .= '</form>';

    render_page('Schritt 1', $body);
    exit;
}

// ---------------------------------------------------------------------
// Stufe 2: Erstes Admin-Konto (config.php existiert, aber noch keine User)
// ---------------------------------------------------------------------
$userRepo = new UserRepository();
if ($userRepo->count() === 0) {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password2'] ?? '');
        if ($username === '' || strlen($password) < 8) {
            $error = 'Benutzername erforderlich, Passwort mindestens 8 Zeichen.';
        } elseif ($password !== $password2) {
            $error = 'Die Passwoerter stimmen nicht ueberein.';
        } else {
            $userRepo->create($username, $password, 'admin');
            header('Location: login.php');
            exit;
        }
    }

    $body = '<p class="pnk-text-muted">Schritt 2 von 2: Erstes Admin-Konto anlegen.</p>';
    if ($error) {
        $body .= '<div class="pnk-alert pnk-alert--danger" style="margin-bottom:16px;">' . htmlspecialchars($error, ENT_QUOTES) . '</div>';
    }
    $body .= '<form method="post" action="install.php">';
    $body .= field('Benutzername', 'username');
    $body .= field('Passwort (min. 8 Zeichen)', 'password', '', 'password');
    $body .= field('Passwort wiederholen', 'password2', '', 'password');
    $body .= '<button class="pnk-btn pnk-btn--primary" type="submit" style="width:100%; margin-top:8px;">Admin-Konto anlegen</button>';
    $body .= '</form>';

    render_page('Schritt 2', $body);
    exit;
}

// ---------------------------------------------------------------------
// Stufe 3: Datenbank-Update nach einem Code-Update (neue Schema-Version)
// ---------------------------------------------------------------------
if (Database::needsUpdate()) {
    if (!Auth::isLoggedIn()) {
        header('Location: ' . app_url('login.php'));
        exit;
    }

    $error = null;
    $updated = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::requireValid();
        try {
            $pdo = Database::get();
            Database::ensureSchemaOn($pdo, Database::driver());
            Database::markSchemaVersion($pdo, Database::SCHEMA_VERSION);
            $updated = true;
        } catch (\Throwable $e) {
            $error = 'Update fehlgeschlagen: ' . $e->getMessage();
        }
    }

    if ($updated) {
        render_page('Update abgeschlossen', '<div class="pnk-alert pnk-alert--success" style="margin-bottom:16px;">Datenbank erfolgreich aktualisiert.</div>'
            . '<a class="pnk-btn pnk-btn--primary" style="width:100%; display:block; text-align:center;" href="' . app_url('admin/index.php') . '">Weiter zur Uebersicht</a>');
        exit;
    }

    $body = '<p class="pnk-text-muted">Diese Version von Party Player - pan1k.de braucht ein paar Anpassungen an der Datenbank, bevor es weitergeht.</p>';
    if ($error) {
        $body .= '<div class="pnk-alert pnk-alert--danger" style="margin-bottom:16px;">' . htmlspecialchars($error, ENT_QUOTES) . '</div>';
    }
    $body .= '<form method="post" action="' . app_url('install.php') . '">';
    $body .= Csrf::field();
    $body .= '<button class="pnk-btn pnk-btn--primary" type="submit" style="width:100%; margin-top:8px;">Datenbank jetzt aktualisieren</button>';
    $body .= '</form>';

    render_page('Update erforderlich', $body);
    exit;
}

// ---------------------------------------------------------------------
// Bereits vollstaendig eingerichtet
// ---------------------------------------------------------------------
render_page('Fertig', '<div class="pnk-alert pnk-alert--success" style="margin-bottom:16px;">Party Player - pan1k.de ist bereits eingerichtet.</div>'
    . '<a class="pnk-btn pnk-btn--primary" style="width:100%; display:block; text-align:center;" href="login.php">Zur Anmeldung</a>');
