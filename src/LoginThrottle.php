<?php

namespace App;

/**
 * IP-basierte Brute-Force-Bremse fuer Endpunkte, die vor jeder Session
 * greifen muessen (login.php, Admin-Konto-Anlage in install.php Stufe 2) -
 * die session-basierte Sperre in api/lock.php reicht dort nicht, weil vor
 * einem erfolgreichen Login/vor dem ersten Admin-Konto keine
 * vertrauenswuerdige Session existiert, die ein Angreifer nicht einfach
 * durch ein neues Cookie umgehen koennte.
 *
 * Nach MAX_ATTEMPTS Fehlversuchen einer IP innerhalb eines $scope (z.B.
 * "login") wird jeder weitere Versuch abgelehnt, mit exponentiell
 * wachsender Sperrzeit (gedeckelt), bis ein Versuch erfolgreich ist.
 */
final class LoginThrottle
{
    private const MAX_ATTEMPTS = 5;
    private const BASE_BLOCK_SECONDS = 30;
    private const MAX_BLOCK_SECONDS = 900; // 15 Minuten Deckel

    /** Sekunden, bis diese IP es fuer $scope wieder versuchen darf (0 = jetzt erlaubt). */
    public static function secondsUntilAllowed(string $scope): int
    {
        $row = self::row($scope);
        if (!$row || !$row['blocked_until']) {
            return 0;
        }
        return max(0, strtotime($row['blocked_until']) - time());
    }

    /** Zaehlt einen Fehlversuch fuer die aktuelle IP + $scope und sperrt bei Bedarf. */
    public static function recordFailure(string $scope): void
    {
        $row = self::row($scope);
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $blockedUntil = null;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $over = $attempts - self::MAX_ATTEMPTS;
            $blockSeconds = min(self::MAX_BLOCK_SECONDS, self::BASE_BLOCK_SECONDS * (2 ** $over));
            $blockedUntil = date('Y-m-d H:i:s', time() + $blockSeconds);
        }
        self::upsert($scope, $attempts, $blockedUntil);
    }

    /** Setzt den Zaehler nach einem erfolgreichen Versuch zurueck. */
    public static function recordSuccess(string $scope): void
    {
        self::upsert($scope, 0, null);
    }

    private static function row(string $scope): ?array
    {
        $stmt = Database::get()->prepare('SELECT attempts, blocked_until FROM login_throttle WHERE ip_hash = ? AND scope = ?');
        $stmt->execute([Util::clientIpHash(), $scope]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function upsert(string $scope, int $attempts, ?string $blockedUntil): void
    {
        $pdo = Database::get();
        $ipHash = Util::clientIpHash();
        $now = Util::now();
        if (Database::driver() === 'mysql') {
            $pdo->prepare(
                'INSERT INTO login_throttle (ip_hash, scope, attempts, blocked_until, updated_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), blocked_until = VALUES(blocked_until), updated_at = VALUES(updated_at)'
            )->execute([$ipHash, $scope, $attempts, $blockedUntil, $now]);
        } else {
            $pdo->prepare(
                'INSERT INTO login_throttle (ip_hash, scope, attempts, blocked_until, updated_at) VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(ip_hash, scope) DO UPDATE SET attempts = excluded.attempts, blocked_until = excluded.blocked_until, updated_at = excluded.updated_at'
            )->execute([$ipHash, $scope, $attempts, $blockedUntil, $now]);
        }
    }
}
