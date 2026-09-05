<?php

namespace App\Repositories;

use App\Database;
use App\Util;

final class LibraryRepository
{
    public function all(): array
    {
        return Database::get()->query('SELECT * FROM libraries ORDER BY name')->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM libraries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $name, string $path, bool $recursive = true): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO libraries (name, path, recursive, track_count, created_at) VALUES (?, ?, ?, 0, ?)'
        );
        $stmt->execute([$name, $path, $recursive ? 1 : 0, Util::now()]);
        return (int) Database::get()->lastInsertId();
    }

    public function update(int $id, string $name, string $path, bool $recursive): void
    {
        $stmt = Database::get()->prepare(
            'UPDATE libraries SET name = ?, path = ?, recursive = ? WHERE id = ?'
        );
        $stmt->execute([$name, $path, $recursive ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM tracks WHERE library_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM libraries WHERE id = ?')->execute([$id]);
    }

    public function markScanned(int $id, int $trackCount): void
    {
        $stmt = Database::get()->prepare('UPDATE libraries SET last_scanned_at = ?, track_count = ? WHERE id = ?');
        $stmt->execute([Util::now(), $trackCount, $id]);
    }
}
