<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (!function_exists('mobile_jwt_secret')) {
    function mobile_jwt_secret(): string
    {
        $secret = (string)rmi_env('MOBILE_JWT_SECRET', '');
        if ($secret === '') $secret = (string)rmi_env('APP_KEY', '');
        if ($secret === '') $secret = 'mobile-dev-secret-change-me';
        return $secret;
    }
}

if (!function_exists('mobile_b64url_encode')) {
    function mobile_b64url_encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

if (!function_exists('mobile_b64url_decode')) {
    function mobile_b64url_decode(string $raw): string
    {
        $pad = strlen($raw) % 4;
        if ($pad > 0) $raw .= str_repeat('=', 4 - $pad);
        return (string)base64_decode(strtr($raw, '-_', '+/'));
    }
}

if (!function_exists('mobile_jwt_issue')) {
    function mobile_jwt_issue(array $claims, int $ttlSec = 900): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttlSec,
            'iss' => 'ERP_RMI_SOFULL',
        ]);
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $h = mobile_b64url_encode((string)json_encode($header, JSON_UNESCAPED_SLASHES));
        $p = mobile_b64url_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = hash_hmac('sha256', $h . '.' . $p, mobile_jwt_secret(), true);
        return $h . '.' . $p . '.' . mobile_b64url_encode($sig);
    }
}

if (!function_exists('mobile_jwt_verify')) {
    function mobile_jwt_verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            mobile_err('ERR_UNAUTHENTICATED', 'Invalid token.', 401);
        }
        [$h, $p, $s] = $parts;
        $calc = mobile_b64url_encode(hash_hmac('sha256', $h . '.' . $p, mobile_jwt_secret(), true));
        if (!hash_equals($calc, $s)) {
            mobile_err('ERR_UNAUTHENTICATED', 'Invalid token signature.', 401);
        }
        $payload = json_decode(mobile_b64url_decode($p), true);
        if (!is_array($payload)) {
            mobile_err('ERR_UNAUTHENTICATED', 'Invalid token payload.', 401);
        }
        $exp = (int)($payload['exp'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            mobile_err('ERR_TOKEN_EXPIRED', 'Token expired.', 401);
        }
        return $payload;
    }
}

if (!function_exists('mobile_auth_header_bearer')) {
    function mobile_auth_header_bearer(): string
    {
        $h = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($h === '' || stripos($h, 'Bearer ') !== 0) {
            mobile_err('ERR_UNAUTHENTICATED', 'Missing bearer token.', 401);
        }
        return trim(substr($h, 7));
    }
}

if (!function_exists('mobile_require_auth')) {
    function mobile_require_auth(): array
    {
        $token = mobile_auth_header_bearer();
        $payload = mobile_jwt_verify($token);
        $u = [
            'user_id' => (int)($payload['uid'] ?? 0),
            'username' => (string)($payload['username'] ?? ''),
            'role' => strtoupper((string)($payload['role'] ?? '')),
            'level' => strtoupper((string)($payload['level'] ?? '')),
            'department' => strtoupper((string)($payload['department'] ?? '')),
            'office_code' => strtoupper((string)($payload['office_code'] ?? '')),
        ];
        if ($u['user_id'] <= 0 || $u['username'] === '') {
            mobile_err('ERR_UNAUTHENTICATED', 'Invalid auth subject.', 401);
        }
        return $u;
    }
}

if (!function_exists('mobile_refresh_issue')) {
    function mobile_refresh_issue(PDO $pdo, int $userId, string $deviceId, string $ipMasked, string $ua): string
    {
        $bytes = random_bytes(48);
        $token = mobile_b64url_encode($bytes);
        $hash = hash('sha256', $token);
        $st = $pdo->prepare("INSERT INTO mobile_refresh_tokens (user_id, token_hash, issued_at, expires_at, device_id, ip_masked, user_agent, created_at, updated_at) VALUES (?,?,NOW(),DATE_ADD(NOW(), INTERVAL 30 DAY),?,?,?,NOW(),NOW())");
        $st->execute([$userId, $hash, $deviceId, $ipMasked, $ua]);
        return $token;
    }
}

if (!function_exists('mobile_refresh_rotate')) {
    function mobile_refresh_rotate(PDO $pdo, string $refreshToken, string $deviceId, string $ipMasked, string $ua): array
    {
        $hash = hash('sha256', $refreshToken);
        $st = $pdo->prepare("SELECT * FROM mobile_refresh_tokens WHERE token_hash=? LIMIT 1");
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            mobile_err('ERR_UNAUTHENTICATED', 'Invalid refresh token.', 401);
        }
        if (!empty($row['revoked_at']) || strtotime((string)$row['expires_at']) < time()) {
            mobile_err('ERR_TOKEN_EXPIRED', 'Refresh token expired/revoked.', 401);
        }

        $pdo->prepare("UPDATE mobile_refresh_tokens SET revoked_at=NOW(), updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
        $newToken = mobile_refresh_issue($pdo, (int)$row['user_id'], $deviceId, $ipMasked, $ua);
        return ['user_id' => (int)$row['user_id'], 'refresh_token' => $newToken];
    }
}

if (!function_exists('mobile_find_user_by_username')) {
    function mobile_find_user_by_username(PDO $pdo, string $username): ?array
    {
        $st = $pdo->prepare("SELECT id, username, full_name, password_hash, role, level, department, office_code, status FROM master_system_login WHERE username=? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$username]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u ?: null;
    }
}

if (!function_exists('mobile_permissions')) {
    function mobile_norm_code(string $v): string
    {
        return strtoupper(trim($v));
    }
}

if (!function_exists('mobile_is_admin_user')) {
    function mobile_is_admin_user(array $u): bool
    {
        $role = mobile_norm_code((string)($u['role'] ?? ''));
        $level = mobile_norm_code((string)($u['level'] ?? ''));
        return in_array($role, ['SYS', 'ADMIN', 'SUPERADMIN'], true) || in_array($level, ['SYS', 'ADMIN', 'SUPERADMIN'], true);
    }
}

if (!function_exists('mobile_rbac_ready')) {
    function mobile_rbac_ready(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('rbac_permissions','rbac_dept_role_permissions')");
            $ready = ((int)$st->fetchColumn() >= 2);
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('mobile_rbac_can')) {
    function mobile_rbac_can(PDO $pdo, array $u, string $permCode): bool
    {
        $permCode = mobile_norm_code($permCode);
        if ($permCode === '') return false;
        if (mobile_is_admin_user($u)) return true;
        if (!mobile_rbac_ready($pdo)) return false;

        $userId = (int)($u['user_id'] ?? 0);
        $dept = mobile_norm_code((string)($u['department'] ?? ''));
        $role = mobile_norm_code((string)($u['role'] ?? ''));
        $level = mobile_norm_code((string)($u['level'] ?? ''));
        if ($dept === '') return false;

        try {
            if ($userId > 0) {
                $st = $pdo->prepare("SELECT allow_flag FROM rbac_user_permissions WHERE user_id=? AND perm_code=? LIMIT 1");
                $st->execute([$userId, $permCode]);
                $v = $st->fetchColumn();
                if ($v !== false) {
                    return ((int)$v) === 1;
                }
            }

            $roles = array_values(array_unique(array_filter([$role, $level], static fn($x) => $x !== '')));
            if (!$roles) return false;
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $sql = "SELECT allow_flag FROM rbac_dept_role_permissions WHERE dept_code=? AND role_code IN ({$placeholders}) AND perm_code=? ORDER BY allow_flag DESC LIMIT 1";
            $params = array_merge([$dept], $roles, [$permCode]);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $v = $st->fetchColumn();
            return ($v !== false) && ((int)$v === 1);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('mobile_rbac_can_any')) {
    function mobile_rbac_can_any(PDO $pdo, array $u, array $permCodes): bool
    {
        foreach ($permCodes as $p) {
            if (mobile_rbac_can($pdo, $u, (string)$p)) return true;
        }
        return false;
    }
}

if (!function_exists('mobile_permissions')) {
    function mobile_permissions(array $u): array
    {
        $isAdmin = mobile_is_admin_user($u);
        $pdo = function_exists('mobile_pdo') ? mobile_pdo() : null;
        $rbacReady = ($pdo instanceof PDO) ? mobile_rbac_ready($pdo) : false;
        if ($rbacReady && $pdo instanceof PDO) {
            $canSales = mobile_rbac_can_any($pdo, $u, [
                'SALES.VIEW',
                'SALES.CREATE',
                'SALES.EDIT',
                'SALES.EDIT',
                'SALES.EDIT',
                'SALES.EDIT',
                'SALES.EDIT',
            ]);
            $canPurchases = mobile_rbac_can_any($pdo, $u, [
                'PURCHASES.VIEW',
                'PURCHASES.PO_VIEW', 'PURCHASES.PO_CREATE', 'PURCHASES.PO_EDIT',
                'PURCHASES.AP_INVOICE_VIEW', 'PURCHASES.AP_INVOICE_CREATE', 'PURCHASES.AP_INVOICE_EDIT',
                'PURCHASES.AP_PAYMENT_VIEW', 'PURCHASES.AP_PAYMENT_CREATE', 'PURCHASES.AP_PAYMENT_EDIT',
                'PURCHASES.GR_PROCESS',
            ]);
            $canStock = mobile_rbac_can_any($pdo, $u, [
                'STOCK.VIEW',
                'STOCK.CREATE',
                'WQS.VIEW',
                'WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT',
                'WQS.PICKING_VIEW', 'WQS.PICKING_CREATE', 'WQS.PICKING_EDIT',
                'WQS.ALLOCATION_VIEW', 'WQS.ALLOCATION_CREATE',
                'WQS.PR_VIEW', 'WQS.PR_CREATE', 'WQS.PR_EDIT',
            ]);
            return [
                'admin' => $isAdmin,
                'sales' => $canSales,
                'purchases' => $canPurchases,
                'stock' => $canStock,
                // Keep chat/notif open until dedicated permission codes are defined.
                'chat' => true,
                'master_read' => true,
                'notif' => true,
            ];
        }

        $role = mobile_norm_code((string)($u['role'] ?? ''));
        $level = mobile_norm_code((string)($u['level'] ?? ''));
        $isManager = in_array($role, ['MANAGER'], true) || in_array($level, ['MANAGER'], true);
        $isStaff = in_array($role, ['STAFF'], true) || in_array($level, ['STAFF'], true);
        return [
            'admin' => $isAdmin,
            'sales' => $isAdmin || $isManager || $isStaff,
            'purchases' => $isAdmin || $isManager || $isStaff,
            'stock' => $isAdmin || $isManager || $isStaff,
            'chat' => true,
            'master_read' => true,
            'notif' => true,
        ];
    }
}

if (!function_exists('mobile_require_perm')) {
    function mobile_require_perm(array $u, string $perm): void
    {
        $p = mobile_permissions($u);
        if (empty($p[$perm])) {
            mobile_err('ERR_FORBIDDEN', 'Forbidden.', 403);
        }
    }
}
