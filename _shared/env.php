<?php
/**
 * _shared/env.php
 *
 * Dotenv-lite loader (tanpa composer) untuk ERP_RMI_SOFULL.
 * Aman dipanggil berulang (idempotent).
 *
 * Tujuan:
 * - Menghilangkan hardcode credential/config di file page.
 * - Meniru best-practice Laravel (.env) tapi tetap ringan.
 *
 * Cara pakai:
 *   require_once __DIR__ . '/env.php';
 *   rmi_env_load(); // load .env dari root project
 *   $dbHost = rmi_env('DB_HOST', '127.0.0.1');
 */

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('rmi_env_load')) {
    function rmi_env_load(?string $path = null): void
    {
        static $loaded = false;
        if ($loaded) return;

        $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $envPath = $path ?: ($root . DIRECTORY_SEPARATOR . '.env');

        if (!is_file($envPath) || !is_readable($envPath)) {
            $loaded = true;
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            // support: export KEY=VALUE
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            if ($key === '') continue;

            // strip surrounding quotes
            if (strlen($val) >= 2) {
                $q = $val[0];
                if (($q === '"' || $q === "'") && $val[strlen($val) - 1] === $q) {
                    $val = substr($val, 1, -1);
                }
            }

            // APP_ENV: selalu pakai nilai dari .env (supaya production di NAS terbaca benar)
            $forceFromEnv = ($key === 'APP_ENV' && trim($val) !== '');
            if (!$forceFromEnv) {
                // do not override existing env (hanya skip jika sudah ada nilai non-kosong)
                $existing = getenv($key);
                if ($existing !== false && trim((string)$existing) !== '') continue;
            }

            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
            @putenv($key . '=' . $val);
        }

        $loaded = true;
    }
}

if (!function_exists('rmi_env')) {
    function rmi_env(string $key, $default = null)
    {
        $v = getenv($key);
        if ($v === false) {
            $v = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        if ($v === null || $v === '') return $default;
        return $v;
    }
}

if (!function_exists('rmi_env_bool')) {
    function rmi_env_bool(string $key, bool $default = false): bool
    {
        $v = rmi_env($key, null);
        if ($v === null) return $default;
        $s = strtolower(trim((string)$v));
        if (in_array($s, ['1','true','yes','on'], true)) return true;
        if (in_array($s, ['0','false','no','off'], true)) return false;
        return $default;
    }
}
