<?php

namespace App;

use App\Repositories\UserRepository;

/**
 * Sitzungsbasierte Admin-Authentifizierung. "Gast" ist bewusst kein
 * Account-Typ - jeder nicht eingeloggte Besucher ist automatisch Gast und
 * darf nur die Wunsch-Seite und die dazugehoerige API nutzen.
 */
final class Auth
{
    // Dummy-Hash fuer einen konstanten password_verify()-Aufruf auch bei
    // unbekanntem Benutzernamen - sonst waere per Timing (kein Hash-Vergleich
    // vs. echter bcrypt-Vergleich) erkennbar, welche Benutzernamen existieren.
    private const DUMMY_HASH = '$2y$10$abcdefghijklmnopqrstuuVGXjE0N4h1v6Z5f2z8W1p1e1c1s1e1s.a';

    // Eingeschraenkte Rolle: darf nur Player + Wunschliste bedienen (kein
    // Upload, keine Bibliotheksverwaltung, keine Einstellungen, keine
    // Benutzerverwaltung). Jede andere/fehlende Rolle (insbesondere 'admin',
    // der Spalten-Default in der DB) gilt als Vollzugriff - siehe isAdmin().
    public const ROLE_PLAYER = 'player';

    public static function attempt(string $username, string $password): bool
    {
        $user = (new UserRepository())->findByUsername($username);
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, $hash);
        if (!$user || !$valid) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        (new UserRepository())->touchLogin((int) $user['id']);
        return true;
    }

    public static function logout(): void
    {
        PlayerSession::releaseIfMaster();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    /** Vollzugriff (Upload/Bibliothek/Einstellungen/Benutzer) - alles ausser
     *  der expliziten eingeschraenkten Rolle gilt als Admin (siehe ROLE_PLAYER),
     *  damit bestehende Sessions/Konten ohne gesetzte Rolle nicht ploetzlich
     *  ausgesperrt werden. */
    public static function isAdmin(): bool
    {
        return self::role() !== self::ROLE_PLAYER;
    }

    /** Beendet die Anfrage mit 403/Redirect, falls nicht eingeloggt. */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . app_url('login.php'));
            exit;
        }
    }

    /** Schickt eine eingeschraenkte (Nicht-Admin-)Session zurueck zum Player -
     *  fuer Seiten, die nur der Vollzugriff sehen darf (Bibliothek/Einstellungen/
     *  Benutzer). Nach requireLogin() aufrufen. */
    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            header('Location: ' . app_url('player.php'));
            exit;
        }
    }

    public static function requireLoginApi(): void
    {
        if (!self::isLoggedIn()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Nicht angemeldet.']);
            exit;
        }
    }

    /** API-Gegenstueck zu requireAdmin() - nach requireLoginApi() aufrufen. */
    public static function requireAdminApi(): void
    {
        if (!self::isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Keine Berechtigung.']);
            exit;
        }
    }
}
