<?php

namespace App;

/**
 * Erkennt Gaeste ueber ein langlebiges Cookie wieder (kein Login/Account
 * noetig). Dient dazu, das Wunsch-Limit pro Gast durchzusetzen, auch wenn
 * sich der eingegebene Name aendert oder leer bleibt.
 *
 * Ein Gast kann sich durch Loeschen/Ignorieren des Cookies jederzeit eine
 * neue Identitaet geben lassen - das laesst sich mit einem reinen
 * Cookie-Mechanismus grundsaetzlich nicht verhindern (siehe die zusaetzliche
 * IP-Bremse in api/requests.php/api/reactions.php dagegen). Wovor die
 * Signatur hier aber schuetzt: dass jemand sich absichtlich einen fremden,
 * bereits vergebenen Token in sein Cookie setzt (z.B. um dessen Kontingent/
 * Namens-Sperre zu uebernehmen) - ohne den serverseitigen app_secret laesst
 * sich keine gueltige Signatur fuer eine selbst gewaehlte ID faelschen.
 */
final class GuestIdentity
{
    private const COOKIE_NAME = 'app_guest_id';
    private const LIFETIME_SECONDS = 60 * 60 * 24 * 365; // 1 Jahr

    private static ?string $id = null;

    private static function sign(string $id): string
    {
        return substr(hash_hmac('sha256', $id, Config::get('app_secret', '')), 0, 16);
    }

    /** Liefert die Gast-ID; legt bei Bedarf ein neues (signiertes) Cookie an. */
    public static function id(): string
    {
        if (self::$id !== null) {
            return self::$id;
        }

        $cookie = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (is_string($cookie) && preg_match('/^([a-f0-9]{32})\.([a-f0-9]{16})$/', $cookie, $m)) {
            [, $candidateId, $candidateSig] = $m;
            if (hash_equals(self::sign($candidateId), $candidateSig)) {
                self::$id = $candidateId;
                return self::$id;
            }
        }

        $id = bin2hex(random_bytes(16));
        self::$id = $id;
        $value = $id . '.' . self::sign($id);
        $_COOKIE[self::COOKIE_NAME] = $value;

        if (!headers_sent()) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            setcookie(self::COOKIE_NAME, $value, [
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
