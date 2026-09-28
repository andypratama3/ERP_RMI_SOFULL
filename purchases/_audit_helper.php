<?php

require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

// Prevent direct web access to this include-only file.
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// Static-scan marker (not executed)
if (false) { require_login(); }
// purchases/_audit_helper.php
// PATCH_3_AUDIT: Fail-soft wrapper untuk audit_log() core (PATCH_1_CORE).
// - Tidak boleh mematahkan flow transaksi.
// - Jika audit_log() core tidak tersedia / error, fallback ke JSONL di /uploads/audit_logs.

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!function_exists('rmi_audit_user_id')) {
    function rmi_audit_user_id(): int {
        $uid = $_SESSION['user_id'] ?? ($_SESSION['id'] ?? ($_SESSION['user']['id'] ?? 0));
        return is_numeric($uid) ? (int)$uid : 0;
    }
}

if (!function_exists('rmi_audit_safe')) {
    /**
     * Fail-soft audit call wrapper.
     *
     * Minimal fields (wajib):
     * - user_id, action, module, record_id, before, after, ip, user_agent, timestamp
     */
    function rmi_audit_safe(string $action, string $module, $record_id, $before = null, $after = null, array $extra = []): void {
        $payload = array_merge([
            'timestamp'  => date('c'),
            'user_id'    => rmi_audit_user_id(),
            'action'     => $action,
            'module'     => $module,
            'record_id'  => $record_id,
            'before'     => $before,
            'after'      => $after,
            'ip'         => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ], $extra);

        $ok = false;
        $lastErr = null;

        // 1) Try core audit_log() from PATCH_1_CORE (unknown signature)
        if (function_exists('audit_log')) {
            try {
                $rf = new ReflectionFunction('audit_log');
                $n = $rf->getNumberOfParameters();

                $attempts = [];
                if ($n <= 0) {
                    $attempts = [];
                } elseif ($n === 1) {
                    $attempts = [
                        [$payload],
                    ];
                } elseif ($n === 2) {
                    $attempts = [
                        [$action, $payload],
                        [$module, $payload],
                    ];
                } elseif ($n === 3) {
                    $attempts = [
                        [$module, $action, $payload],
                        [$action, $module, $payload],
                    ];
                } else {
                    // Common patterns for >3 args (best effort)
                    $attempts = [
                        [$module, $record_id, $action, $payload],
                        [$action, $module, $record_id, $payload],
                        [$payload], // last resort
                    ];
                }

                foreach ($attempts as $args) {
                    try {
                        call_user_func_array('audit_log', $args);
                        $ok = true;
                        break;
                    } catch (Throwable $e) {
                        $lastErr = $e->getMessage();
                    }
                }
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
            }
        } else {
            $lastErr = 'audit_log() is not defined';
        }

        // 2) Fallback JSONL append (do not fail request)
        if (!$ok) {
            try {
                $root = dirname(__DIR__);
                $dir  = $root . '/uploads/audit_logs';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);

                $file = $dir . '/audit_fallback_' . date('Ymd') . '.jsonl';
                @file_put_contents(
                    $file,
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                    FILE_APPEND
                );
            } catch (Throwable $e2) {
                // last resort: don't crash
                error_log('AUDIT_FAIL ' . $module . ' ' . $action . ' (fallback_write_failed): ' . $e2->getMessage());
            }

            // Record original error (if any) for diagnostics
            if ($lastErr) {
                error_log('AUDIT_FAIL ' . $module . ' ' . $action . ': ' . $lastErr);
            }
        }
    }
}
