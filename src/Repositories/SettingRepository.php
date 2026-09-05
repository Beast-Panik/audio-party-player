<?php

namespace App\Repositories;

use App\Database;

final class SettingRepository
{
    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = Database::get()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
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
