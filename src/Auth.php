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
    public static function attempt(string $username, string $password): bool
    {
        $user = (new UserRepository())->findByUsername($username);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        (new UserRepository())->touchLogin((int) $user['id']);
        return true;
    }

    public static function logout(): void
    {
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

    /** Beendet die Anfrage mit 403/Redirect, falls nicht eingeloggt. */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . app_url('login.php'));
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
}
