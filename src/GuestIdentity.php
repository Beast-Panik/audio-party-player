<?php

namespace App;

/**
 * Erkennt Gaeste ueber ein langlebiges Cookie wieder (kein Login/Account
 * noetig). Dient dazu, das Wunsch-Limit pro Gast durchzusetzen, auch wenn
 * sich der eingegebene Name aendert oder leer bleibt.
 */
final class GuestIdentity
{
    private const COOKIE_NAME = 'app_guest_id';
    private const LIFETIME_SECONDS = 60 * 60 * 24 * 365; // 1 Jahr

    private static ?string $id = null;

    /** Liefert die Gast-ID; legt bei Bedarf ein neues Cookie an. */
    public static function id(): string
    {
        if (self::$id !== null) {
            return self::$id;
        }

        $existing = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (is_string($existing) && preg_match('/^[a-f0-9]{32}$/', $existing)) {
            self::$id = $existing;
            return self::$id;
        }

        $id = bin2hex(random_bytes(16));
        self::$id = $id;
        $_COOKIE[self::COOKIE_NAME] = $id;

        if (!headers_sent()) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            setcookie(self::COOKIE_NAME, $id, [
                'expires' => time() + self::LIFETIME_SECONDS,
                'path' => (defined('APP_BASE_PATH') && APP_BASE_PATH !== '') ? APP_BASE_PATH . '/' : '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $id;
    }
}
