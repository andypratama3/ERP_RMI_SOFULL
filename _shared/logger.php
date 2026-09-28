<?php
/**
 * _shared/logger.php
 *
 * Logger sederhana (tanpa composer) untuk ERP_RMI_SOFULL.
 * Output default: storage/logs/erp.log
 */

if (!function_exists('rmi_log_path')) {
    function rmi_log_path(): string
    {
        $root = defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
        $custom = getenv('LOG_PATH');
        if ($custom !== false && trim($custom) !== '') return (string)$custom;
        return $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log';
    }
}

if (!function_exists('rmi_log')) {
    function rmi_log(string $level, string $message, array $context = []): void
    {
        $level = strtoupper(trim($level));
        if ($level === '') $level = 'INFO';

        $path = rmi_log_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $ts = date('c');
        $requestId = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['RMI_REQUEST_ID'] ?? '');
        if ($requestId !== '' && !isset($context['request_id'])) {
            $context['request_id'] = $requestId;
        }
        $ctx = '';
        if (!empty($context)) {
            $ctx = ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $line = sprintf("%s [%s] %s%s\n", $ts, $level, $message, $ctx);
        @file_put_contents($path, $line, FILE_APPEND);
    }
}

if (!function_exists('rmi_log_exception')) {
    function rmi_log_exception(Throwable $e, array $context = []): void
    {
        $msg = $e->getMessage();
        if (function_exists('rmi_redact_sensitive_string')) {
            $msg = rmi_redact_sensitive_string($msg);
        }
        $context = array_merge($context, [
            'exception' => get_class($e),
            'message' => $msg,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        if (function_exists('rmi_redact_sensitive_context')) {
            $context = rmi_redact_sensitive_context($context);
        }
        rmi_log('ERROR', 'Unhandled exception', $context);
    }
}
