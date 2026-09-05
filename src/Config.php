<?php

namespace App;

/**
 * Laedt config/config.php einmalig und stellt die Werte bereit.
 */
final class Config
{
    private static ?array $data = null;

    public static function isInstalled(): bool
    {
        return is_file(dirname(__DIR__) . '/config/config.php');
    }

    private static function load(): array
    {
        if (self::$data === null) {
            $path = dirname(__DIR__) . '/config/config.php';
            if (!is_file($path)) {
                self::$data = [];
            } else {
                self::$data = require $path;
            }
        }
        return self::$data;
    }

    /**
     * @param string $key Punkt-Notation moeglich, z.B. "db.driver"
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $data = self::load();
        $parts = explode('.', $key);
        $cursor = $data;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$part];
        }
        return $cursor;
    }

    public static function all(): array
    {
        return self::load();
    }
}
