<?php
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/tools_state_lib.php';
require_once __DIR__ . '/tools_ui_helpers.php';
require_once __DIR__ . '/tools_access_helpers.php';

if (!function_exists('opsgov_h')) {
    function opsgov_h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('opsgov_root')) {
    function opsgov_root(): string
    {
        return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    }
}

if (!function_exists('opsgov_require_admin')) {
    function opsgov_require_admin(): void
    {
        require_once __DIR__ . '/tools_remote_check.php';
        if (function_exists('require_login')) {
            require_login();
        }
        if (function_exists('require_role')) {
            require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
        }
        if (function_exists('tools_require_access')) {
            $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
            $toolsRoot = realpath(__DIR__);
            if ($script && $toolsRoot && str_starts_with($script, $toolsRoot . DIRECTORY_SEPARATOR)) {
                $toolId = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($script, strlen($toolsRoot)), DIRECTORY_SEPARATOR));
                if ($toolId !== '') {
                    tools_require_access($toolId);
                }
            }
        }
    }
}

if (!function_exists('opsgov_mask')) {
    function opsgov_mask(string $v): string
    {
        return ts_mask(tools_mask_sensitive($v));
    }
}

if (!function_exists('opsgov_env')) {
    function opsgov_env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v === false || $v === null) return $default;
        return trim((string)$v);
    }
}

if (!function_exists('opsgov_db_runtime_config')) {
    function opsgov_db_runtime_config(): array
    {
        $defaultPass = (string)(getenv('DB_PASS_DEFAULT') ?: '');
        $cfg = [
            'host' => opsgov_env('DB_HOST', opsgov_env('ERP_DB_HOST', '')),
            'port' => (int)opsgov_env('DB_PORT', opsgov_env('ERP_DB_PORT', '3306')),
            'name' => opsgov_env('DB_NAME', opsgov_env('DB_DATABASE', opsgov_env('ERP_DB_NAME', ''))),
            'user' => opsgov_env('DB_USER', opsgov_env('DB_USERNAME', opsgov_env('ERP_DB_USER', ''))),
            'pass' => opsgov_env('DB_PASS', opsgov_env('DB_PASSWORD', opsgov_env('ERP_DB_PASS', ''))),
        ];

        if (function_exists('rmi_db_config')) {
            try {
                $rmi = (array)rmi_db_config();
                if ($cfg['host'] === '' && !empty($rmi['host'])) $cfg['host'] = (string)$rmi['host'];
                if ($cfg['name'] === '' && !empty($rmi['name'])) $cfg['name'] = (string)$rmi['name'];
                if ($cfg['user'] === '' && !empty($rmi['user'])) $cfg['user'] = (string)$rmi['user'];
                if ($cfg['pass'] === '' && array_key_exists('pass', $rmi)) $cfg['pass'] = (string)$rmi['pass'];
                if ((int)$cfg['port'] <= 0 && !empty($rmi['port'])) $cfg['port'] = (int)$rmi['port'];
            } catch (Throwable $e) {
            }
        }

        if ($cfg['host'] === '' && !empty($GLOBALS['DB_HOST'])) $cfg['host'] = (string)$GLOBALS['DB_HOST'];
        if ($cfg['name'] === '' && !empty($GLOBALS['DB_NAME'])) $cfg['name'] = (string)$GLOBALS['DB_NAME'];
        if ($cfg['user'] === '' && !empty($GLOBALS['DB_USER'])) $cfg['user'] = (string)$GLOBALS['DB_USER'];
        if ($cfg['pass'] === '' && array_key_exists('DB_PASS', $GLOBALS)) $cfg['pass'] = (string)$GLOBALS['DB_PASS'];
        if ((int)$cfg['port'] <= 0 && !empty($GLOBALS['DB_PORT'])) $cfg['port'] = (int)$GLOBALS['DB_PORT'];

        if ($cfg['host'] === '') $cfg['host'] = '127.0.0.1';
        if ($cfg['name'] === '') $cfg['name'] = 'ERP_RMI_SOFULL';
        if ($cfg['user'] === '') $cfg['user'] = 'root';
        if ($cfg['pass'] === '') $cfg['pass'] = $defaultPass;
        if ((int)$cfg['port'] <= 0) $cfg['port'] = 3306;
        return $cfg;
    }
}

if (!function_exists('opsgov_safe_write_json')) {
    function opsgov_safe_write_json(string $path, array $payload): bool
    {
        if (!array_key_exists('state_version', $payload)) {
            $payload['state_version'] = 1;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return (bool)@file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('opsgov_read_json')) {
    function opsgov_read_json(string $path): array
    {
        if (!is_file($path)) return [];
        $raw = (string)@file_get_contents($path);
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }
}
