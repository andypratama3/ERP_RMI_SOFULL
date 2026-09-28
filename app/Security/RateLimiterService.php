<?php
declare(strict_types=1);

namespace App\Security;

use PDO;

final class RateLimiterService
{
    private array $policyCache = [];

    public function __construct(private PDO $pdo)
    {
    }

    public function hit(string $scope, string $actorKey, int $windowSeconds = 60, int $maxHits = 20): array
    {
        $scope = strtoupper(trim($scope));
        $actorKey = trim($actorKey);
        [$windowSeconds, $maxHits] = $this->resolvePolicy($scope, $windowSeconds, $maxHits);
        if ($scope === '' || $actorKey === '') {
            return ['allowed' => true, 'remaining' => $maxHits, 'reset_at' => time() + $windowSeconds];
        }

        $now = time();
        $windowStart = date('Y-m-d H:i:s', $now);
        $resetAtTs = $now + $windowSeconds;
        $resetAt = date('Y-m-d H:i:s', $resetAtTs);

        $this->pdo->prepare(
            "INSERT INTO api_rate_limits (scope_key, actor_key, counter, window_started_at, reset_at, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
               counter =
                 CASE
                   WHEN reset_at <= NOW() THEN 1
                   ELSE counter + 1
                 END,
               window_started_at =
                 CASE
                   WHEN reset_at <= NOW() THEN VALUES(window_started_at)
                   ELSE window_started_at
                 END,
               reset_at =
                 CASE
                   WHEN reset_at <= NOW() THEN VALUES(reset_at)
                   ELSE reset_at
                 END,
               updated_at = NOW()"
        )->execute([$scope, $actorKey, $windowStart, $resetAt]);

        $st = $this->pdo->prepare(
            "SELECT counter, UNIX_TIMESTAMP(reset_at) AS reset_ts
             FROM api_rate_limits
             WHERE scope_key = ? AND actor_key = ?
             LIMIT 1"
        );
        $st->execute([$scope, $actorKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['counter' => 0, 'reset_ts' => $resetAtTs];

        $counter = (int)($row['counter'] ?? 0);
        $resetTs = (int)($row['reset_ts'] ?? $resetAtTs);
        $remaining = max(0, $maxHits - $counter);
        return [
            'allowed' => $counter <= $maxHits,
            'remaining' => $remaining,
            'reset_at' => $resetTs,
            'counter' => $counter,
        ];
    }

    private function resolvePolicy(string $scope, int $defaultWindow, int $defaultMax): array
    {
        if ($scope === '') return [$defaultWindow, $defaultMax];
        if (isset($this->policyCache[$scope])) return $this->policyCache[$scope];

        $window = $defaultWindow;
        $max = $defaultMax;
        try {
            $st = $this->pdo->prepare(
                "SELECT window_seconds, max_hits
                 FROM api_rate_limit_policies
                 WHERE scope_key = ? AND is_active = 1
                 LIMIT 1"
            );
            $st->execute([$scope]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $window = max(1, (int)($row['window_seconds'] ?? $defaultWindow));
                $max = max(1, (int)($row['max_hits'] ?? $defaultMax));
            }
        } catch (\Throwable $e) {
            // fallback to defaults if policy table not available yet
        }
        $this->policyCache[$scope] = [$window, $max];
        return [$window, $max];
    }
}
