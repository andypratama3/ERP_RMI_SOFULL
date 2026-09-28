<?php
/**
 * _shared/error_handler.php
 *
 * Error/Exception handler terpusat:
 * - APP_DEBUG=true  -> tampil detail (dev)
 * - APP_DEBUG=false -> tampil pesan aman (prod) + log ke file
 *
 * Uncaught exception & fatal shutdown -> rmi_log_module_error() (file log + redaksi + opsional master_audit).
 */

$__rmiErrLogBootstrap = __DIR__ . '/rmi_error_logger.php';
if (is_file($__rmiErrLogBootstrap)) {
    require_once $__rmiErrLogBootstrap;
}

if (!function_exists('rmi_is_debug')) {
    function rmi_is_debug(): bool
    {
        if (defined('APP_DEBUG')) return (bool)APP_DEBUG;
        $v = getenv('APP_DEBUG');
        if ($v === false) return false;
        return in_array(strtolower(trim((string)$v)), ['1','true','yes','on'], true);
    }
}

if (!function_exists('rmi_register_error_handlers')) {
    function rmi_register_error_handlers(): void
    {
        static $registered = false;
        if ($registered) return;
        $registered = true;

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            $msg = function_exists('rmi_redact_sensitive_string')
                ? rmi_redact_sensitive_string($message)
                : $message;

            $ctx = ['php_severity' => $severity, 'file' => $file, 'line' => $line];
            if (function_exists('rmi_redact_sensitive_context')) {
                $ctx = rmi_redact_sensitive_context($ctx);
            }

            // E_USER_ERROR: fatal dari trigger_error — log lalu hentikan (jangan lanjut eksekusi).
            if ($severity === E_USER_ERROR) {
                if (function_exists('rmi_log_module_error')) {
                    rmi_log_module_error('', new Error($msg), array_merge($ctx, ['caught_by' => 'php_error_handler']));
                } elseif (function_exists('rmi_log')) {
                    rmi_log('ERROR', $msg, $ctx);
                }
                if (rmi_is_debug()) {
                    return false;
                }
                if (!headers_sent()) {
                    http_response_code(500);
                }
                echo 'Internal Server Error';
                exit(1);
            }

            if ($severity === E_RECOVERABLE_ERROR) {
                if (function_exists('rmi_log_module_warn')) {
                    rmi_log_module_warn('', $msg, array_merge($ctx, ['caught_by' => 'php_recoverable_error']));
                }
            } elseif (function_exists('rmi_log_module_warn')) {
                rmi_log_module_warn('', $msg, $ctx);
            } elseif (function_exists('rmi_log')) {
                rmi_log('WARN', $msg, $ctx);
            }

            // Notice/warning: jangan cetak body 500 — biarkan debug tampil lewat handler bawaan PHP.
            if (rmi_is_debug()) {
                return false;
            }

            return true;
        });

        set_exception_handler(function (Throwable $e): void {
            // Tulis ke global log
            if (function_exists('rmi_log_exception')) {
                rmi_log_exception($e);
            } elseif (function_exists('rmi_log')) {
                $em = function_exists('rmi_redact_sensitive_string')
                    ? rmi_redact_sensitive_string($e->getMessage())
                    : $e->getMessage();
                rmi_log('ERROR', 'Unhandled exception', ['message' => $em]);
            }

            // Tulis ke module-specific error log (auto-detect modul dari script filename)
            if (function_exists('rmi_log_module_error')) {
                rmi_log_module_error('', $e, ['caught_by' => 'global_exception_handler']);
            } else {
                // Fallback minimal jika rmi_error_logger belum diload
                $root = defined('RMI_ROOT') ? rtrim((string)RMI_ROOT, '/\\') : dirname(__DIR__);
                $dir  = $root . '/storage/logs';
                if (!is_dir($dir)) @mkdir($dir, 0770, true);
                $line = date('[Y-m-d H:i:s]') . ' UNHANDLED_EXCEPTION | ' . $e->getMessage()
                      . ' | ' . $e->getFile() . ':' . $e->getLine() . "\n";
                @file_put_contents($dir . '/unhandled_errors.log', $line, FILE_APPEND | LOCK_EX);
                @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
            }

            if (rmi_is_debug()) {
                if (!headers_sent()) http_response_code(500);
                echo "<pre>Unhandled Exception: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "\n" .
                     htmlspecialchars($e->getFile(), ENT_QUOTES) . ":" . (int)$e->getLine() . "\n\n" .
                     htmlspecialchars($e->getTraceAsString(), ENT_QUOTES) . "</pre>";
            } else {
                if (!headers_sent()) http_response_code(500);
                echo "Internal Server Error";
            }
        });

        register_shutdown_function(function (): void {
            $err = error_get_last();
            if (!$err) return;

            $type = $err['type'] ?? 0;
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
            if (!in_array($type, $fatalTypes, true)) return;

            $rawMsg = (string)($err['message'] ?? 'Fatal error');
            $msg = function_exists('rmi_redact_sensitive_string') ? rmi_redact_sensitive_string($rawMsg) : $rawMsg;
            $ctx = [
                'fatal_file' => (string)($err['file'] ?? ''),
                'fatal_line' => (int)($err['line'] ?? 0),
                'fatal_type' => $type,
                'caught_by' => 'shutdown_handler',
            ];

            if (function_exists('rmi_log')) {
                rmi_log('ERROR', $msg, function_exists('rmi_redact_sensitive_context') ? rmi_redact_sensitive_context($ctx) : $ctx);
            }

            if (function_exists('rmi_log_module_error')) {
                try {
                    rmi_log_module_error('', new Error($msg), $ctx);
                } catch (Throwable $t) {
                    // fallback file write
                }
            }

            if (!function_exists('rmi_log_module_error') && function_exists('rmi_write_error_line')) {
                $module  = function_exists('rmi_detect_module') ? rmi_detect_module() : 'app';
                $logFile = function_exists('rmi_module_to_log_file')
                         ? rmi_module_to_log_file($module) . '_errors.log'
                         : $module . '_errors.log';
                rmi_write_error_line($logFile, 'FATAL', 'FATAL_ERROR', $msg, $ctx);
            } elseif (!function_exists('rmi_log_module_error') && !function_exists('rmi_write_error_line')) {
                $root = defined('RMI_ROOT') ? rtrim((string)RMI_ROOT, '/\\') : dirname(__DIR__);
                $dir  = $root . '/storage/logs';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0770, true);
                }
                $line = date('[Y-m-d H:i:s]') . ' FATAL_ERROR | ' . $msg
                      . ' | ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? 0) . "\n";
                @file_put_contents($dir . '/unhandled_errors.log', $line, FILE_APPEND | LOCK_EX);
            }

            if (!headers_sent()) {
                http_response_code(500);
            }

            if (rmi_is_debug()) {
                echo "<pre>Fatal Error: " . htmlspecialchars($rawMsg, ENT_QUOTES) . "\n" .
                     htmlspecialchars((string)($err['file'] ?? ''), ENT_QUOTES) . ":" . (int)($err['line'] ?? 0) . "</pre>";
            } else {
                echo "Internal Server Error";
            }
        });
    }
}
