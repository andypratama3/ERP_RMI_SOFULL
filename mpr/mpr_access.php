<?php
// /mpr/_inc/mpr_access.php
// Helper scope office MPR: home office + assignment/coverage office.
// Dipakai untuk kasus user office asal SLO tetapi ditugaskan kerja di MLG/Malang.

if (!function_exists('mpr_access_norm_office')) {
    function mpr_access_norm_office($office): string {
        return strtoupper(trim((string)$office));
    }
}

if (!function_exists('mpr_access_schema_ensure')) {
    function mpr_access_schema_ensure(PDO $pdo): void {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS user_office_access (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    username VARCHAR(120) NULL,
                    office_code VARCHAR(30) NOT NULL,
                    access_type VARCHAR(30) NOT NULL DEFAULT 'ASSIGNMENT',
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_user_office (user_id, office_code),
                    KEY idx_username_office (username, office_code),
                    KEY idx_office_active (office_code, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // Jika user DB tidak punya CREATE privilege, jalankan SQL manual yang disertakan di patch.
        }
    }
}

if (!function_exists('mpr_access_user_id')) {
    function mpr_access_user_id(PDO $pdo, array $user): int {
        foreach (['id','user_id','login_id','login_user_id'] as $k) {
            if (isset($user[$k]) && (int)$user[$k] > 0) return (int)$user[$k];
        }

        $username = trim((string)($user['username'] ?? ''));
        if ($username === '') return 0;

        try {
            $st = $pdo->prepare("SELECT id FROM master_system_login WHERE username=? LIMIT 1");
            $st->execute([$username]);
            return (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('mpr_allowed_offices')) {
    function mpr_allowed_offices(PDO $pdo, array $user, bool $is_admin = false): array {
        if ($is_admin) return ['*'];

        $offices = [];
        foreach (['office_code','office','branch_code'] as $k) {
            $v = mpr_access_norm_office($user[$k] ?? '');
            if ($v !== '') $offices[] = $v;
        }

        $userId = mpr_access_user_id($pdo, $user);
        $username = trim((string)($user['username'] ?? ''));

        try {
            if ($userId > 0 && $username !== '') {
                $st = $pdo->prepare("
                    SELECT office_code
                    FROM user_office_access
                    WHERE is_active = 1
                      AND (user_id = ? OR username = ?)
                ");
                $st->execute([$userId, $username]);
            } elseif ($userId > 0) {
                $st = $pdo->prepare("
                    SELECT office_code
                    FROM user_office_access
                    WHERE is_active = 1 AND user_id = ?
                ");
                $st->execute([$userId]);
            } elseif ($username !== '') {
                $st = $pdo->prepare("
                    SELECT office_code
                    FROM user_office_access
                    WHERE is_active = 1 AND username = ?
                ");
                $st->execute([$username]);
            } else {
                $st = null;
            }

            if ($st) {
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $office) {
                    $office = mpr_access_norm_office($office);
                    if ($office !== '') $offices[] = $office;
                }
            }
        } catch (Throwable $e) {
            // Tabel belum ada / privilege tidak cukup: fallback ke office akun.
        }

        $offices = array_values(array_unique(array_filter($offices, fn($v) => $v !== '')));
        return $offices ?: [mpr_access_norm_office($user['office_code'] ?? '')];
    }
}

if (!function_exists('mpr_office_is_allowed')) {
    function mpr_office_is_allowed(PDO $pdo, array $user, bool $is_admin, ?string $office): bool {
        if ($is_admin) return true;
        $office = mpr_access_norm_office($office);
        if ($office === '') return true;
        $allowed = mpr_allowed_offices($pdo, $user, $is_admin);
        return in_array('*', $allowed, true) || in_array($office, $allowed, true);
    }
}

if (!function_exists('mpr_apply_allowed_office_filter')) {
    function mpr_apply_allowed_office_filter(PDO $pdo, array $user, bool $is_admin, string $columnSql, array &$params): string {
        if ($is_admin) return '1=1';
        $allowed = mpr_allowed_offices($pdo, $user, $is_admin);
        $allowed = array_values(array_filter($allowed, fn($v) => $v !== '' && $v !== '*'));

        if (!$allowed) return '1=0';
        $ph = implode(',', array_fill(0, count($allowed), '?'));
        foreach ($allowed as $office) $params[] = $office;
        return "UPPER(COALESCE({$columnSql},'')) IN ({$ph})";
    }
}

if (!function_exists('mpr_customer_scope_office_or_default')) {
    function mpr_customer_scope_office_or_default(PDO $pdo, array $user, bool $is_admin, ?array $customer = null): string {
        $home = mpr_access_norm_office($user['office_code'] ?? '');
        if ($is_admin) return $home;

        $custOffice = mpr_access_norm_office($customer['office_code'] ?? '');
        if ($custOffice !== '' && mpr_office_is_allowed($pdo, $user, false, $custOffice)) {
            return $custOffice;
        }
        return $home;
    }
}
