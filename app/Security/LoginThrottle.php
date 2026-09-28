<?php
declare(strict_types=1);

namespace App\Security;

use PDO;

final class LoginThrottle
{
    public function __construct(private PDO $pdo)
    {
    }

    public function isLocked(string $ip, string $username): array
    {
        $st = $this->pdo->prepare(
            "SELECT failed_count, locked_until
             FROM auth_login_attempts
             WHERE ip_address = ? AND username = ?
             LIMIT 1"
        );
        $st->execute([$ip, $this->normalize($username)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['locked' => false, 'seconds_left' => 0];
        }
        $lockedUntil = (string)($row['locked_until'] ?? '');
        if ($lockedUntil === '') {
            return ['locked' => false, 'seconds_left' => 0];
        }
        $left = strtotime($lockedUntil) - time();
        return ['locked' => $left > 0, 'seconds_left' => max(0, $left)];
    }

    public function recordFailure(string $ip, string $username, int $maxFails = 5, int $lockMinutes = 10): void
    {
        $username = $this->normalize($username);
        $this->pdo->prepare(
            "INSERT INTO auth_login_attempts (ip_address, username, failed_count, last_attempt_at, locked_until, created_at, updated_at)
             VALUES (?, ?, 1, NOW(), NULL, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                failed_count = failed_count + 1,
                last_attempt_at = NOW(),
                updated_at = NOW()"
        )->execute([$ip, $username]);

        $st = $this->pdo->prepare("SELECT failed_count FROM auth_login_attempts WHERE ip_address=? AND username=? LIMIT 1");
        $st->execute([$ip, $username]);
        $count = (int)$st->fetchColumn();
        if ($count >= $maxFails) {
            $this->pdo->prepare(
                "UPDATE auth_login_attempts
                 SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE), updated_at = NOW()
                 WHERE ip_address = ? AND username = ?"
            )->execute([$lockMinutes, $ip, $username]);
        }
    }

    public function clear(string $ip, string $username): void
    {
        $this->pdo->prepare("DELETE FROM auth_login_attempts WHERE ip_address = ? AND username = ?")
            ->execute([$ip, $this->normalize($username)]);
    }

    private function normalize(string $username): string
    {
        return strtolower(trim($username));
    }
}
