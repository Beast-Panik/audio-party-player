<?php

namespace App;

final class Util
{
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function formatDuration(?int $seconds): string
    {
        if ($seconds === null || $seconds < 0) {
            return '--:--';
        }
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return sprintf('%d:%02d', $m, $s);
    }

    public static function formatBytes(int|float|null $bytes): string
    {
        if (!$bytes) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $bytes = (float) $bytes;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $bytes, $units[$i]);
    }

    public static function clientIpHash(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $secret = Config::get('app_secret', 'x');
        return hash('sha256', $ip . '|' . $secret);
    }

    public static function isAllowedAudioExtension(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['mp3', 'flac'], true);
    }

    /** Menschenlesbare Restwartezeit, z.B. fuer "Limit erreicht, weiter in ..." */
    public static function formatWait(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'gleich';
        }
        if ($seconds < 60) {
            return $seconds . ' Sek.';
        }
        $minutes = (int) ceil($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' Min.';
        }
        $hours = intdiv($minutes, 60);
        $restMinutes = $minutes % 60;
        return $hours . ' Std.' . ($restMinutes > 0 ? ' ' . $restMinutes . ' Min.' : '');
    }
}
