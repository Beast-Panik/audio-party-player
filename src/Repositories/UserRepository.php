<?php

namespace App\Repositories;

use App\Database;
use App\Util;

final class UserRepository
{
    public function findByUsername(string $username): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function all(): array
    {
        return Database::get()->query('SELECT id, username, role, created_at, last_login_at FROM users ORDER BY username')->fetchAll();
    }

    public function count(): int
    {
        return (int) Database::get()->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
    }

    public function create(string $username, string $password, string $role = 'admin'): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, Util::now()]);
        return (int) Database::get()->lastInsertId();
    }

    public function updatePassword(int $id, string $password): void
    {
        $stmt = Database::get()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function touchLogin(int $id): void
    {
        $stmt = Database::get()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
        $stmt->execute([Util::now(), $id]);
    }

    public function usernameExists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }
}
