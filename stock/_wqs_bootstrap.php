<?php
// stock/_wqs_bootstrap.php
// WQS module bootstrap (M2) - central auth + RBAC guard + PDO + common UI helpers.
//
// Goals:
// - Single, consistent entry: load _shared/bootstrap.php (+ helpers) once.
// - Enforce login + dept guard for WQS pages.
// - Provide shared PDO via wqs_pdo() (no more per-file config scanning + new PDO).
// - Provide WQS navbar buttons (role-aware).
// - Provide safe helpers for folder/code usage.

declare(strict_types=1);

// ---------------------------------------------------------------------
// Load shared bootstrap (preferred)
// ---------------------------------------------------------------------
$__RMI_ROOT = dirname(__DIR__);
$__SHARED_BOOTSTRAP = $__RMI_ROOT . '/_shared/bootstrap.php';
if (is_file($__SHARED_BOOTSTRAP)) {
    require_once $__SHARED_BOOTSTRAP;
} else {
    // Fallback (legacy): try load config.php + auth.php directly
    foreach ([
        $__RMI_ROOT . '/config.php',
        $__RMI_ROOT . '/../config.php',
        __DIR__ . '/../config.php',
    ] as $__f) {
        if (is_file($__f)) { require_once $__f; break; }
    }
    if (is_file($__RMI_ROOT . '/master/auth.php')) {
        require_once $__RMI_ROOT . '/master/auth.php';
    }
}

// Shared helpers (M2)
$__SHARED_HELPERS = $__RMI_ROOT . '/_shared/helpers.php';
if (is_file($__SHARED_HELPERS)) {
    require_once $__SHARED_HELPERS;
}

// Shared DB helpers (table_cols, pick_col, ensure_col, ...)
// Some WQS/Stock pages rely on these helpers but legacy bootstrap may not load them.
$__SHARED_DB = $__RMI_ROOT . '/_shared/db.php';
if (is_file($__SHARED_DB)) {
    require_once $__SHARED_DB;
}

// ---------------------------------------------------------------------
// Fallback schema helpers (in case shared db helpers are not loaded)
// ---------------------------------------------------------------------
if (!function_exists('table_cols')) {
    function table_cols(PDO $pdo, string $table): array {
        $table = trim($table);
        if ($table === '') return [];
        try {
            $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
            $st->execute([$table]);
            $cols = $st->fetchAll(PDO::FETCH_COLUMN, 0);
            return is_array($cols) ? $cols : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('pick_col')) {
    function pick_col(array $cols, array $candidates, $fallback = null) {
        $map = [];
        foreach ($cols as $c) {
            if (!is_string($c)) continue;
            $map[strtolower($c)] = $c;
        }
        foreach ($candidates as $cand) {
            if (!is_string($cand) || $cand === '') continue;
            $k = strtolower($cand);
            if (isset($map[$k])) return $map[$k];
        }
        return $fallback;
    }
}

if (!function_exists('ensure_table')) {
    function ensure_table(PDO $pdo, string $sqlOrTable, ?string $createSql = null): void {
        $sql = ($createSql === null) ? $sqlOrTable : $createSql;
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }
}

if (!function_exists('ensure_col')) {
    function ensure_col(PDO $pdo, string $table, string $col, string $definition): void {
        $table = trim($table);
        $col = trim($col);
        if ($table === '' || $col === '') return;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
            $st->execute([$table, $col]);
            $exists = (int)$st->fetchColumn();
            if ($exists > 0) return;
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        } catch (Throwable $e) {}
    }
}



// Ensure session (some legacy pages may include this file before auth.php)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// ---------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------
if (!defined('RMI_BASE')) {
    // RMI_BASE is normally set by _shared/bootstrap.php. Fallback to BASE_PROJECT when missing.
    define('RMI_BASE', defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
}

if (!function_exists('wqs_is_api_request')) {
    function wqs_is_api_request(): bool {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script && stripos($script, '_api.php') !== false) return true;
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if ($accept && stripos($accept, 'application/json') !== false) return true;
        return false;
    }
}

if (!function_exists('wqs_safe_code')) {
    /**
     * Make a value safe for use as a single directory segment or file stem.
     * Keeps A-Z a-z 0-9 _ - . and converts everything else to underscore.
     */
    function wqs_safe_code(string $raw, int $maxLen = 80): string {
        $s = trim($raw);
        if ($s === '') return 'NA';
        $s = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        $s = trim((string)$s, '._-');
        if ($s === '') return 'NA';
        return substr($s, 0, $maxLen);
    }
}

if (!function_exists('wqs_url')) {
    function wqs_url(string $path): string {
        $base = (string)(defined('RMI_BASE') ? RMI_BASE : '');
        if ($base === '') {
            $base = defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : (string)($GLOBALS['BASE_PROJECT'] ?? '');
        }
        if ($base === '') return $path;
        return $base . $path;
    }
}

if (!function_exists('wqs_can_manage')) {
    // "Manage" = baseline lock, adjustment, audit.
    function wqs_can_manage(): bool {
        $role = '';
        if (function_exists('auth_user')) {
            $u = auth_user();
            $role = strtoupper((string)($u['role'] ?? ''));
        } elseif (isset($_SESSION['role'])) {
            $role = strtoupper((string)$_SESSION['role']);
        }
        return in_array($role, ['MANAGER', 'ADMIN', 'SUPERADMIN'], true);
    }
}

if (!function_exists('wqs_nav_buttons')) {
    function wqs_nav_buttons(string $active = ''): void {
        $active = strtolower(trim($active));
        $btn = function(string $key, string $label, string $href) use ($active): void {
            $isActive = ($active !== '' && strtolower($key) === $active);
            $cls = $isActive ? 'btn btn-light' : 'btn btn-outline-light';
            echo '<a class="' . $cls . '" style="margin-right:6px" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        };

        echo '<div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;margin:10px 0 18px">';
        $btn('incoming',   'Incoming',    wqs_url('/stock/wqs_incoming.php'));
        $btn('allocation', 'Allocation',  wqs_url('/stock/wqs_allocation.php'));
        $btn('picking',    'Picking',     wqs_url('/stock/wqs_picking.php'));
        $btn('do_tasks',   'WQS DO Tasks',wqs_url('/stock/wqs_do_tasks.php'));
        $btn('opname',     'Stock Opname', wqs_url('/stock/wqs_stock_opname.php'));
        $btn('opname_report', 'Opname Report', wqs_url('/stock/wqs_stock_opname_report.php'));

        if (wqs_can_manage()) {
            $btn('adjustment', 'Adjustment', wqs_url('/stock/wqs_stock_adjustment.php'));
            $btn('audit',      'Audit',      wqs_url('/stock/wqs_stock_audit.php'));
        }
        echo '</div>';
    }
}

// ---------------------------------------------------------------------
// Auth / RBAC guard
// ---------------------------------------------------------------------
if (function_exists('require_login')) {
    require_login();
} elseif (function_exists('auth_require_login')) {
    auth_require_login();
}

// Guard: RBAC permission atau dept WQS (bukan department-only)
$wqs_ok = false;
if (function_exists('auth_is_admin') && auth_is_admin()) {
    $wqs_ok = true;
} elseif (function_exists('auth_dept') && strtoupper((string)auth_dept()) === 'WQS') {
    $wqs_ok = true;
} elseif (function_exists('can')) {
    foreach (['STOCK.VIEW', 'SALES.EDIT', 'SALES.EDIT'] as $p) {
        if (can($p)) { $wqs_ok = true; break; }
    }
}
if (!$wqs_ok) {
    if (function_exists('auth_deny')) {
        auth_deny('Akses modul WQS/Stock memerlukan permission STOCK.VIEW atau SALES.EDIT.', 403);
    }
    http_response_code(403);
    die('Forbidden');
}

// ---------------------------------------------------------------------
// PDO: single shared instance for WQS pages
// ---------------------------------------------------------------------
if (!function_exists('wqs_pdo')) {
    function wqs_pdo(): PDO {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }

        $pdo = null;
        if (function_exists('rmi_db_pdo')) {
            $pdo = rmi_db_pdo();
        } elseif (function_exists('db_pdo')) {
            $pdo = db_pdo();
        } elseif (function_exists('auth_pdo')) {
            $pdo = auth_pdo();
        }

        if (!$pdo instanceof PDO) {
            if (wqs_is_api_request()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode(['ok' => false, 'msg' => 'PDO not available'], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo '<pre style="padding:12px;border:1px solid #ddd;border-radius:8px;background:#fff">PDO not available</pre>';
            }
            exit;
        }

        // Make sure defaults are set (safe even if already set)
        try {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // ignore (some drivers may not support)
        }

        $GLOBALS['pdo'] = $pdo;
        return $pdo;
    }
}


// ---------------------------------------------------------------------
// RBAC enforcement (Stage-1): require module access
// ---------------------------------------------------------------------
if (!isset($GLOBALS['__WQS_RBAC_GUARDED'])) {
    $GLOBALS['__WQS_RBAC_GUARDED'] = true;

    if (is_file($__RMI_ROOT . '/_shared/rbac.php')) {
        require_once $__RMI_ROOT . '/_shared/rbac.php';
    }

    try {
        $pdo__wqs = wqs_pdo();
        if (function_exists('rbac_ensure_tables')) {
            rbac_ensure_tables($pdo__wqs);
        }
        if (function_exists('rbac_require')) {
            rbac_require($pdo__wqs, 'STOCK.VIEW');
        }
    } catch (Throwable $e) {
        // fail-closed for non-admin
        if (function_exists('auth_deny')) {
            auth_deny('RBAC/DB belum siap untuk modul STOCK.', 403);
        }
        http_response_code(403);
        die('Forbidden');
    }
}


// ---------------------------------------------------------------------
// Debug badge (HTML only)
// ---------------------------------------------------------------------
if (!function_exists('wqs_debug_badge')) {
    function wqs_debug_badge(string $module = 'WQS'): void {
        if (wqs_is_api_request()) return;

        $u = function_exists('auth_user') ? auth_user() : [];
        $username = (string)($u['username'] ?? ($_SESSION['username'] ?? ''));
        $role     = strtoupper((string)($u['role'] ?? ($_SESSION['role'] ?? '')));
        $dept     = strtoupper((string)($u['department'] ?? ($_SESSION['department'] ?? '')));
        $office   = strtoupper((string)($u['office_code'] ?? ($_SESSION['office_code'] ?? '')));

        echo '<div style="position:fixed;top:12px;left:12px;z-index:99999;background:#111827;color:#f9fafb;border:1px solid #374151;padding:8px 10px;border-radius:10px;font-size:12px;opacity:.95">';
        echo '<b>' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . '</b><br>';
        if ($username !== '') echo '👤 ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '<br>';
        echo '🎭 ' . htmlspecialchars($role ?: '-', ENT_QUOTES, 'UTF-8');
        echo ' • 📁 ' . htmlspecialchars($dept ?: '-', ENT_QUOTES, 'UTF-8');
        echo ' • 🏢 ' . htmlspecialchars($office ?: '-', ENT_QUOTES, 'UTF-8');
        echo '</div>';
    }
}

if (isset($_GET['debug']) && !wqs_is_api_request()) {
    wqs_debug_badge('WQS');
}
