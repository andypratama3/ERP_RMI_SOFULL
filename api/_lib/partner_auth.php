<?php
/**
 * api/_lib/partner_auth.php
 * Validasi API Key untuk partner eksternal.
 * Header: X-API-Key atau Authorization: Bearer <key>
 */
declare(strict_types=1);

if (!function_exists('partner_api_key_validate')) {
    /**
     * Validasi API key, return partner row atau null.
     * Filter by environment: production key hanya valid di APP_ENV=production;
     * development key valid di local/development/staging (tidak di production).
     * @return array{id:int,partner_name:string,scopes:string,rate_limit_per_hour:int,environment?:string}|null
     */
    function partner_api_key_validate(PDO $pdo, string $providedKey): ?array
    {
        $key = trim($providedKey);
        if ($key === '') return null;

        $appEnv = strtolower(trim((string)(defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'local'))));
        $isProduction = ($appEnv === 'production');

        $hasEnvColumn = true;
        try {
            $st = $pdo->prepare("SELECT id, partner_name, environment, api_key_hash, scopes, rate_limit_per_hour, status
                                FROM api_partner_keys WHERE status='active' LIMIT 100");
            $st->execute();
        } catch (Throwable $e) {
            $hasEnvColumn = false;
            $st = $pdo->prepare("SELECT id, partner_name, api_key_hash, scopes, rate_limit_per_hour, status
                                FROM api_partner_keys WHERE status='active' LIMIT 100");
            $st->execute();
        }

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($hasEnvColumn) {
                $env = strtolower(trim((string)($row['environment'] ?? 'production')));
                if ($env === 'production' && !$isProduction) continue;
                if ($env === 'development' && $isProduction) continue;
            }

            if (password_verify($key, (string)($row['api_key_hash'] ?? ''))) {
                $out = [
                    'id' => (int)$row['id'],
                    'partner_name' => (string)$row['partner_name'],
                    'scopes' => (string)($row['scopes'] ?? ''),
                    'rate_limit_per_hour' => (int)($row['rate_limit_per_hour'] ?? 1000),
                ];
                if ($hasEnvColumn && isset($row['environment'])) {
                    $out['environment'] = strtolower(trim((string)$row['environment']));
                }
                return $out;
            }
        }
        return null;
    }
}

if (!function_exists('partner_api_key_from_request')) {
    function partner_api_key_from_request(): string
    {
        $h = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
        if ($h !== '') return $h;
        $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return '';
    }
}

if (!function_exists('partner_has_scope')) {
    function partner_has_scope(array $partner, string $scope): bool
    {
        $scopes = array_map('trim', explode(',', (string)($partner['scopes'] ?? '')));
        $scope = strtolower(trim($scope));
        foreach ($scopes as $s) {
            if (strtolower($s) === $scope) return true;
            if (str_ends_with(strtolower($s), '.*') && str_starts_with($scope, substr(strtolower($s), 0, -2))) return true;
        }
        return false;
    }
}

if (!function_exists('partner_update_last_used')) {
    function partner_update_last_used(PDO $pdo, int $partnerId): void
    {
        try {
            $pdo->prepare("UPDATE api_partner_keys SET last_used_at=NOW() WHERE id=?")->execute([$partnerId]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}
