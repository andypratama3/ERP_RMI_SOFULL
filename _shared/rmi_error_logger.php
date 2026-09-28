<?php
/**
 * _shared/rmi_error_logger.php
 *
 * Centralized module-aware error logger untuk ERP_RMI_SOFULL.
 *
 * Cara pakai (di dalam catch block tiap modul):
 *
 *   require_once __DIR__ . '/../_shared/rmi_error_logger.php';
 *   // ...
 *   } catch (Throwable $e) {
 *       rmi_log_module_error('purchases_po', $e, ['do_id' => $id]);
 *       // lanjutkan error handling seperti biasa
 *   }
 *
 * Auto-detected dari SCRIPT_FILENAME jika module tidak disebut eksplisit:
 *   rmi_log_module_error('', $e);   // module = 'sales_do', 'purchases_po', dst.
 *
 * File log ditulis ke: storage/logs/{module}_errors.log
 * Juga ditulis ke:     storage/logs/app-YYYY-MM-DD.log (global)
 */

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403); exit('Forbidden');
}

// ──────────────────────────────────────────────────────────────
// HELPERS
// ──────────────────────────────────────────────────────────────

if (!function_exists('rmi_errlog_dir')) {
    function rmi_errlog_dir(): string
    {
        $root = defined('RMI_ROOT') ? rtrim((string)RMI_ROOT, '/\\')
              : rtrim(realpath(__DIR__ . '/..') ?: dirname(__DIR__), '/\\');
        return $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    }
}

if (!function_exists('rmi_errlog_ensure_dir')) {
    function rmi_errlog_ensure_dir(): void
    {
        $dir = rmi_errlog_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
    }
}

/**
 * Kunci konteks yang mengandung string ini (case-insensitive) di-redaksi sebelum log/audit.
 */
if (!function_exists('rmi_redact_sensitive_key')) {
    function rmi_redact_sensitive_key(string $key): bool
    {
        $k = strtolower($key);
        $needles = [
            'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'authorization',
            'cookie', 'credit_card', 'cvv', 'pin', 'private_key', 'refresh_token', 'access_token',
            'mfa_secret', 'backup_code', 'backup_codes', 'otp', 'bearer', 'auth_header',
            'session_id', 'csrf', 'api-key',
        ];
        foreach ($needles as $n) {
            if (str_contains($k, $n)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('rmi_redact_sensitive_string')) {
    function rmi_redact_sensitive_string(string $s, int $maxLen = 2000): string
    {
        $out = $s;
        $out = preg_replace('/(?i)(password|pwd|pass)\s*=\s*([\'"])[^\'"]*\2/u', '$1=$2[REDACTED]$2', $out) ?? $out;
        $out = preg_replace('/(?i)(api[_-]?key|bearer|authorization)\s*[:=]\s*\S+/u', '$1: [REDACTED]', $out) ?? $out;
        // Raw API partner keys (format rpk_ + hex)
        $out = preg_replace('/\brpk_[a-f0-9]{32,}\b/i', 'rpk_[REDACTED]', $out) ?? $out;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($out) > $maxLen) {
                return mb_substr($out, 0, $maxLen) . '…[truncated]';
            }
        } elseif (strlen($out) > $maxLen) {
            return substr($out, 0, $maxLen) . '…[truncated]';
        }
        return $out;
    }
}

if (!function_exists('rmi_redact_sensitive_context')) {
    /**
     * @return mixed
     */
    function rmi_redact_sensitive_context(mixed $data, int $depth = 0, int $maxDepth = 6): mixed
    {
        if ($depth > $maxDepth) {
            return '[MAX_DEPTH]';
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $keyStr = is_string($k) ? $k : (string) $k;
                if (rmi_redact_sensitive_key($keyStr)) {
                    $out[$keyStr] = '[REDACTED]';
                    continue;
                }
                $out[$keyStr] = rmi_redact_sensitive_context($v, $depth + 1, $maxDepth);
            }
            return $out;
        }
        if (is_string($data)) {
            return rmi_redact_sensitive_string($data);
        }
        return $data;
    }
}

/**
 * Deteksi nama modul dari path skrip yang sedang berjalan.
 * Contoh: /volume4/.../sales/sales_do.php → "sales_do"
 *         /volume4/.../purchases/purchases_po.php → "purchases_po"
 */
if (!function_exists('rmi_detect_module')) {
    function rmi_detect_module(string $script = ''): string
    {
        if ($script === '') {
            $script = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        }
        // Gunakan basename tanpa ekstensi
        $base = basename($script, '.php');
        if ($base === '' || $base === 'index') {
            // Fallback ke directory name
            $dir = basename(dirname($script));
            return $dir ?: 'app';
        }
        return $base;
    }
}

/**
 * PDO untuk audit DB (master_audit): coba $GLOBALS['pdo'], db_pdo(), rmi_db_pdo().
 */
if (!function_exists('rmi_resolve_audit_pdo')) {
    function rmi_resolve_audit_pdo(): ?PDO
    {
        $g = $GLOBALS['pdo'] ?? null;
        if ($g instanceof PDO) {
            return $g;
        }
        try {
            if (function_exists('db_pdo')) {
                return db_pdo();
            }
        } catch (Throwable $e) {
        }
        try {
            if (function_exists('rmi_db_pdo')) {
                return rmi_db_pdo();
            }
        } catch (Throwable $e) {
        }

        return null;
    }
}

if (!function_exists('rmi_module_to_log_file')) {
    function rmi_module_to_log_file(string $module): string
    {
        static $map = [
            // Sales
            'sales_do'          => 'sales',
            'sales_do_view'     => 'sales',
            'tax_invoices'      => 'sales',
            'sales_dashboard'   => 'sales',
            'wqs_do_tasks'      => 'stock',
            'scm_do_tasks'      => 'sales',
            'act_do_tasks'      => 'sales',
            'fin_do_tasks'      => 'sales',
            'sales_control_tower' => 'sales',
            // Purchases
            'purchases_po'           => 'purchases',
            'purchases_payment_ap'   => 'purchases',
            'purchases_invoice_ap'   => 'purchases',
            'bank_recon'             => 'purchases',
            'gl_reversal_approvals'  => 'purchases',
            'pqp_rfq'                => 'purchases',
            'purchases_forwarding_tasks' => 'purchases',
            'purchases_gr'           => 'purchases',
            'purchases_ceisa_pib'    => 'purchases',
            'purchases_import_control_tower' => 'purchases',
            // Stock / WQS
            'wqs_stock_adjustment'  => 'stock',
            'wqs_picking'           => 'stock',
            'wqs_incoming'          => 'stock',
            'wqs_stock_transfer'    => 'stock',
            'wqs_stock_opname'      => 'stock',
            'wqs_stock'             => 'stock',
            'wqs_allocation'        => 'stock',
            'wqs_pr'                => 'stock',
            // MPR
            'mpr_plans'     => 'mpr',
            'mpr_visits'    => 'mpr',
            'mpr_pipeline'  => 'mpr',
            'mpr_budget_fin'=> 'mpr',
            'mpr_dashboard' => 'mpr',
            // HRL
            'hrl_docs'          => 'hrl',
            'hrl_dashboard'     => 'hrl',
            'hrl_process'       => 'hrl',
            'hrl_reg_alkes'     => 'hrl',
            // Fixed Asset
            'assets'            => 'fixed_asset',
            'ops'               => 'fixed_asset',
            'depreciation'      => 'fixed_asset',
            'tax_annual'        => 'fixed_asset',
            'fixed_asset'       => 'fixed_asset',  // explicit name passthrough
            // Finance
            'ar_ap_cash_dashboard' => 'finance',
            'dashboard_detail'     => 'finance',
            'act_dashboard'        => 'finance',
            'fin_do_tasks'         => 'finance',
            'payroll'              => 'finance',
            // Auth / Master
            'login'              => 'auth',
            'logout'             => 'auth',
            'master_system_login'=> 'master',
            'master_customers'   => 'master',
            'master_vendors'     => 'master',
            'master_products'    => 'master',
            'nav_manager'        => 'master',
            'mfa_settings'       => 'master',
            'mfa_admin_reset'    => 'master',
            'customer_portal'    => 'customer_portal',
            'manufacturer_portal'=> 'manufacturer_portal',
            // RBAC
            'rbac'               => 'rbac',
            'index'              => 'rbac',   // rbac/index.php
            // Chat
            'chat_context'       => 'chat',
            // Payroll
            'payroll'            => 'payroll',
            // Absensi
            'absensi'   => 'absensi',
            // KPI
            'kpi_center'    => 'kpi',
            'kpi_snapshot'  => 'kpi',
            'kpi_employee'  => 'kpi',
            // API
            '_router'   => 'api',
        ];
        return $map[$module] ?? $module;
    }
}

/**
 * Nama bucket file log (*_errors.log) yang dikenal ERP — untuk UI (Error Log Center)
 * dan agar modul baru tampil di sidebar meski file belum terbentuk.
 *
 * @return list<string> nilai unik lowercase, tanpa suffix _errors
 */
if (!function_exists('rmi_errlog_known_bucket_names')) {
    function rmi_errlog_known_bucket_names(): array
    {
        static $scriptHints = [
            'sales_do', 'sales_do_view', 'tax_invoices', 'sales_dashboard', 'wqs_do_tasks',
            'scm_do_tasks', 'act_do_tasks', 'fin_do_tasks', 'sales_control_tower',
            'purchases_po', 'purchases_invoice_ap', 'purchases_payment_ap', 'pqp_rfq',
            'purchases_gr', 'purchases_forwarding_tasks', 'purchases_ceisa_pib',
            'purchases_import_control_tower', 'bank_recon', 'gl_reversal_approvals',
            'wqs_pr', 'wqs_incoming', 'wqs_picking', 'wqs_stock', 'wqs_allocation',
            'wqs_stock_adjustment', 'wqs_stock_transfer', 'wqs_stock_opname',
            'mpr_plans', 'mpr_visits', 'mpr_pipeline', 'mpr_budget_fin', 'mpr_dashboard',
            'hrl_docs', 'hrl_dashboard', 'hrl_process', 'hrl_reg_alkes',
            'assets', 'ops', 'depreciation', 'tax_annual', 'fixed_asset',
            'ar_ap_cash_dashboard', 'dashboard_detail', 'act_dashboard', 'payroll',
            'login', 'logout', 'master_system_login', 'master_customers', 'master_vendors',
            'master_products', 'nav_manager', 'mfa_settings', 'mfa_admin_reset',
            'rbac', 'index', 'absensi', 'kpi_center', 'kpi_snapshot', 'kpi_employee',
            '_router', 'chat_context', 'customer_portal', 'manufacturer_portal',
        ];
        $out = [];
        foreach ($scriptHints as $s) {
            $out[] = rmi_module_to_log_file($s);
        }
        // Bucket eksplisit yang sering dipakai nama folder (catch-all logger)
        foreach (['tools', 'dashboards', 'docs', 'dashboard', 'hrl_process', 'customer_portal'] as $b) {
            $out[] = $b;
        }
        $out = array_values(array_unique(array_filter(array_map(static fn ($v) => strtolower(trim((string) $v)), $out))));
        sort($out);
        return $out;
    }
}

/**
 * Tulis satu baris ke log file modul tertentu.
 * Format: [YYYY-MM-DD HH:ii:ss] ACTION | actor | message | context
 */
if (!function_exists('rmi_write_error_line')) {
    function rmi_write_error_line(string $logFile, string $level, string $action, string $message, array $context = []): void
    {
        $dir = rmi_errlog_dir();
        rmi_errlog_ensure_dir();

        $actor = '';
        if (session_status() === PHP_SESSION_ACTIVE) {
            $actor = (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? '');
        }
        $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $page = (string)($_SERVER['REQUEST_URI'] ?? '');

        $ctx = '';
        if (!empty($context)) {
            $safeCtx = function_exists('rmi_redact_sensitive_context')
                ? rmi_redact_sensitive_context($context)
                : $context;
            $ctx = ' | ' . json_encode($safeCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        $line = date('[Y-m-d H:i:s]') . ' ' . strtoupper($level)
              . ' ' . $action
              . ' | user=' . ($actor ?: 'guest')
              . ' | ip=' . $ip
              . ' | ' . $message
              . ($page ? ' | uri=' . $page : '')
              . $ctx . "\n";

        // Tulis ke file modul
        @file_put_contents($dir . DIRECTORY_SEPARATOR . $logFile, $line, FILE_APPEND | LOCK_EX);

        // Tulis juga ke global daily log
        $globalLog = $dir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log';
        @file_put_contents($globalLog, $line, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Fungsi utama: log error dari Throwable ke file modul.
 *
 * @param string    $module   Nama modul (kosong = auto-detect dari SCRIPT_FILENAME)
 * @param Throwable $e        Exception/Error yang ditangkap
 * @param array     $context  Data tambahan (do_id, customers_code, dsb.)
 */
if (!function_exists('rmi_log_module_error')) {
    function rmi_log_module_error(string $module, Throwable $e, array $context = []): void
    {
        try {
            if ($module === '') {
                $module = rmi_detect_module();
            }
            $logFile  = rmi_module_to_log_file($module) . '_errors.log';
            $action   = strtoupper($module) . '_ERROR';
            $message  = rmi_redact_sensitive_string($e->getMessage());
            $traceRaw = $e->getTraceAsString();
            $traceRed = rmi_redact_sensitive_string($traceRaw, 6000);
            if (strlen($traceRed) > 4000) {
                $traceRed = substr($traceRed, 0, 4000) . '…[trace truncated]';
            }
            $context  = array_merge($context, [
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $traceRed,
            ]);
            $context  = rmi_redact_sensitive_context($context);
            rmi_write_error_line($logFile, 'ERROR', $action, $message, $context);

            // DB audit (opsional): hanya jika env mengizinkan — mengurangi noise di system_audit_logs
            $auditDb = getenv('RMI_UNHANDLED_ERROR_AUDIT_DB');
            $auditDb = ($auditDb !== false && in_array(strtolower(trim((string)$auditDb)), ['1', 'true', 'yes', 'on'], true));

            $pdo = rmi_resolve_audit_pdo();
            if ($pdo instanceof PDO && $auditDb) {
                $auditFile = dirname(__DIR__) . '/master/_audit_master.php';
                if (!function_exists('master_audit') && is_file($auditFile)) {
                    require_once $auditFile;
                }
                if (function_exists('master_audit')) {
                    try {
                        master_audit(
                            $pdo,
                            'app_error',
                            $module,
                            $action,
                            (int)($context['id'] ?? $context['record_id'] ?? 0) ?: null,
                            (string)($context['code'] ?? $context['do_code'] ?? '') ?: null,
                            $message,
                            $context
                        );
                    } catch (Throwable $dbEx) {
                    }
                }
            }
        } catch (Throwable $logEx) {
            // Jangan pernah crash karena error di logger
        }
    }
}

/**
 * Log non-exception error (WARNING, NOTICE, INFO) ke modul tertentu.
 */
if (!function_exists('rmi_log_module_warn')) {
    function rmi_log_module_warn(string $module, string $message, array $context = []): void
    {
        try {
            if ($module === '') $module = rmi_detect_module();
            $logFile = rmi_module_to_log_file($module) . '_errors.log';
            rmi_write_error_line($logFile, 'WARN', strtoupper($module) . '_WARN', $message, $context);
        } catch (Throwable $e) {}
    }
}

/**
 * Kumpulkan semua file log yang ada di storage/logs/
 * Return: ['filename' => 'full_path', ...]
 */
if (!function_exists('rmi_list_error_logs')) {
    function rmi_list_error_logs(): array
    {
        $dir = rmi_errlog_dir();
        if (!is_dir($dir)) return [];
        $files = glob($dir . DIRECTORY_SEPARATOR . '*_errors.log') ?: [];
        $result = [];
        foreach ($files as $f) {
            $key = basename($f, '.log');
            $result[$key] = [
                'path'  => $f,
                'size'  => filesize($f) ?: 0,
                'mtime' => filemtime($f) ?: 0,
                'lines' => max(0, substr_count(file_get_contents($f) ?: '', "\n")),
            ];
        }
        // Sort by mtime desc (paling baru dulu)
        uasort($result, fn($a,$b) => $b['mtime'] <=> $a['mtime']);
        return $result;
    }
}
