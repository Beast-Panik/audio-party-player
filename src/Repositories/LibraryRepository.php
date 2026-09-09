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

    public function findByPath(string $path): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM libraries WHERE path = ?');
        $stmt->execute([$path]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Fuer Upload-Zielordner (siehe src/Uploader.php): legt fuer jeden
     *  Unterordner, in den tatsaechlich hochgeladen wird, automatisch eine
     *  eigene, nicht-rekursive Bibliothek an (statt alle Uploads in einer
     *  gemeinsamen Bibliothek zu buendeln) - so bleibt jeder Ordner separat
     *  im "Bibliotheken"-Bereich sicht- und loeschbar. Nicht-rekursiv, damit
     *  sich verschachtelte Unterordner nicht gegenseitig ueberlappen (jeder
     *  bekommt seine eigene Bibliothek). Eine bereits vorhandene Bibliothek
     *  auf denselben Pfad (z.B. aus einer frueheren Version mit rekursivem
     *  Sammel-Ordner) wird dabei automatisch auf nicht-rekursiv umgestellt.
     */
    public function findOrCreateUploadLibrary(string $path, string $name): array
    {
        $existing = $this->findByPath($path);
        if ($existing) {
            if ((int) $existing['recursive'] !== 0) {
                $this->update((int) $existing['id'], $existing['name'], $path, false);
                $existing = $this->findById((int) $existing['id']);
            }
            return $existing;
        }
        $id = $this->create($name, $path, false);
        return $this->findById($id);
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
