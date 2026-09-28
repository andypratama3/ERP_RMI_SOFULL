<?php
declare(strict_types=1);

$pathMask = __DIR__ . '/../app/Support/path_mask.php';
if (is_file($pathMask)) {
    require_once $pathMask;
}

if (!function_exists('tools_app_root')) {
    function tools_app_root(): string
    {
        return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    }
}

if (!function_exists('tools_php_bin')) {
    /**
     * PHP binary dengan pdo_mysql untuk CLI subprocess.
     * Priority: ERP_PHP_BIN env > PHP_BINARY constant > php
     * Note: PHP_BINARY under PHP-FPM points to php*-fpm; we need CLI (php84, php82, etc.)
     */
    function tools_php_bin(): string
    {
        $bin = trim((string)(getenv('ERP_PHP_BIN') ?: (defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php')));
        if (stripos($bin, 'fpm') !== false) {
            $cli = preg_replace('/-?fpm$/i', '', $bin);
            if ($cli !== '' && @is_executable($cli)) {
                return $cli;
            }
            foreach (['/usr/local/bin/php84', '/usr/local/bin/php82', '/usr/local/bin/php81', '/usr/bin/php'] as $c) {
                if (@is_executable($c)) {
                    return $c;
                }
            }
        }
        if ($bin !== '' && @is_executable($bin)) {
            return $bin;
        }
        if ($bin !== '') {
            $resolved = @trim((string)exec('which ' . escapeshellarg($bin) . ' 2>/dev/null'));
            if ($resolved !== '' && @is_executable($resolved) && stripos($resolved, 'fpm') === false) {
                return $resolved;
            }
        }
        return 'php';
    }
}

if (!function_exists('tools_mask_sensitive')) {
    function tools_mask_sensitive(string $text): string
    {
        if (function_exists('app_mask_sensitive')) {
            return app_mask_sensitive($text);
        }
        $root = defined('APP_ROOT') ? (string)APP_ROOT : tools_app_root();
        if ($root !== '') {
            $text = str_replace($root, '[APP_ROOT]', $text);
        }
        $text = preg_replace('/(--password=)([^\s]+)/i', '$1[REDACTED]', $text ?? '') ?? $text;
        $text = preg_replace('/(password\s*=\s*)([^\s&]+)/i', '$1[REDACTED]', $text) ?? $text;
        return $text;
    }
}

if (!function_exists('tools_badge')) {
    function tools_badge(string $level, ?string $label = null): string
    {
        $s = strtoupper(trim($level));
        $map = [
            'HEALTHY' => ['success', 'HEALTHY'],
            'OK' => ['success', 'OK'],
            'PASS' => ['success', 'PASS'],
            'ENABLED' => ['success', 'ENABLED'],
            'PRESENT' => ['success', 'PRESENT'],
            'VALID' => ['success', 'VALID'],
            'ATTENTION' => ['warning', 'ATTENTION'],
            'UNKNOWN' => ['warning', 'UNKNOWN'],
            'N/A' => ['warning', 'N/A'],
            'CRITICAL' => ['danger', 'CRITICAL'],
            'FAIL' => ['danger', 'FAIL'],
            'FAILED' => ['danger', 'FAIL'],
            'ERROR' => ['danger', 'ERROR'],
            'DISABLED' => ['danger', 'DISABLED'],
            'MISSING' => ['danger', 'MISSING'],
            'INVALID' => ['danger', 'INVALID'],
            'GO' => ['success', 'GO'],
            'NO-GO' => ['danger', 'NO-GO'],
            'DATA_MISSING' => ['danger', 'DATA_MISSING'],
        ];
        [$cls, $defLabel] = $map[$s] ?? ['warning', $s !== '' ? $s : 'UNKNOWN'];
        $final = $label !== null && trim($label) !== '' ? $label : $defLabel;
        return '<span class="badge rmi-badge ' . $cls . '">' . htmlspecialchars($final, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('tools_age_hours')) {
    function tools_age_hours(string $path): ?float
    {
        if (!is_file($path)) return null;
        $mtime = (int)@filemtime($path);
        if ($mtime <= 0) return null;
        return round(max(0, time() - $mtime) / 3600, 1);
    }
}

if (!function_exists('tools_fmt_ts')) {
    function tools_fmt_ts(?string $ts, string $tz = 'Asia/Jakarta'): string
    {
        $raw = trim((string)$ts);
        if ($raw === '') return '-';
        try {
            $dt = new DateTimeImmutable($raw);
            $dt = $dt->setTimezone(new DateTimeZone($tz));
            return $dt->format('Y-m-d H:i:s T');
        } catch (Throwable $e) {
            return $raw;
        }
    }
}

if (!function_exists('tools_status_wording')) {
    function tools_status_wording(): array
    {
        return [
            'read' => 'READ',
            'mutating' => 'MUTATING',
            'cli_only' => 'CLI ONLY',
            'web' => 'WEB',
            'levels' => ['HEALTHY', 'ATTENTION', 'CRITICAL', 'UNKNOWN'],
        ];
    }
}

if (!function_exists('tools_safe_json_decode')) {
    function tools_safe_json_decode(string $json, ?string &$err = null): ?array
    {
        $err = null;
        $raw = trim($json);
        if ($raw === '') {
            $err = 'empty';
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                $err = 'not_object';
                return null;
            }
            return $decoded;
        } catch (Throwable $e) {
            $err = 'invalid_json';
            return null;
        }
    }
}

if (!function_exists('tools_read_state_json')) {
    function tools_read_state_json(string $path, array $required_keys = []): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'data' => null, 'error' => 'missing'];
        }
        $raw = (string)@file_get_contents($path);
        // Fallback: fopen/fread jika file_get_contents gagal (open_basedir atau PHP-FPM restriction)
        if ($raw === '') {
            $fh = @fopen($path, 'rb');
            if ($fh !== false) {
                $raw = (string)@stream_get_contents($fh);
                @fclose($fh);
            }
        }
        $decodeErr = null;
        $decoded = tools_safe_json_decode($raw, $decodeErr);
        if ($decoded === null) {
            return ['ok' => false, 'data' => null, 'error' => $decodeErr ?? 'invalid_json'];
        }
        foreach ($required_keys as $k) {
            if (!array_key_exists($k, $decoded)) {
                return ['ok' => false, 'data' => $decoded, 'error' => 'schema_mismatch'];
            }
        }
        if (!array_key_exists('state_version', $decoded)) {
            $decoded['state_version'] = 0;
        }
        return ['ok' => true, 'data' => $decoded, 'error' => ''];
    }
}

if (!function_exists('tools_tail_jsonl')) {
    function tools_tail_jsonl(string $path, int $maxLines = 200): array
    {
        if (!is_file($path)) {
            return ['exists' => false, 'items' => [], 'corrupt_lines' => 0];
        }
        $lines = [];
        try {
            $f = new SplFileObject($path, 'r');
            $f->seek(PHP_INT_MAX);
            $lastIdx = (int)$f->key();
            $start = max(0, $lastIdx - max(1, $maxLines) + 1);
            for ($i = $start; $i <= $lastIdx; $i++) {
                $f->seek($i);
                $line = trim((string)$f->current());
                if ($line === '') {
                    continue;
                }
                $lines[] = $line;
            }
        } catch (Throwable $e) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
            if (count($lines) > $maxLines) {
                $lines = array_slice($lines, -$maxLines);
            }
        }
        $items = [];
        $corrupt = 0;
        foreach ($lines as $line) {
            $e = null;
            $json = tools_safe_json_decode((string)$line, $e);
            if (!is_array($json)) {
                $corrupt++;
                continue;
            }
            $items[] = $json;
        }
        return ['exists' => true, 'items' => $items, 'corrupt_lines' => $corrupt];
    }
}

if (!function_exists('tools_cutover_one_pager_link')) {
    function tools_cutover_one_pager_link(string $baseProject = ''): string
    {
        $root = tools_app_root();
        $govPath = $root . '/docs/governance/CUTOVER_ONE_PAGER.md';
        $legacyPath = $root . '/CUTOVER_ONE_PAGER.md';
        $path = is_file($govPath) ? $govPath : $legacyPath;
        if (!is_file($path)) {
            $placeholder = "# CUTOVER ONE PAGER\n\n";
            $placeholder .= "- Status: draft\n";
            $placeholder .= "- Owner: Release Team\n";
            $placeholder .= "- Notes: update runbook, rollback, and verification checklist.\n";
            @file_put_contents($path, $placeholder);
        }
        $prefix = rtrim($baseProject, '/');
        $rel = is_file($govPath) ? '/docs/governance/CUTOVER_ONE_PAGER.md' : '/CUTOVER_ONE_PAGER.md';
        return ($prefix !== '' ? $prefix : '') . $rel;
    }
}

if (!function_exists('tools_get_env_db')) {
    function tools_get_env_db(string $key): string
    {
        $norm = strtoupper(trim($key));
        $map = [
            'HOST' => ['ERP_DB_HOST', 'DB_HOST'],
            'PORT' => ['ERP_DB_PORT', 'DB_PORT'],
            'NAME' => ['ERP_DB_NAME', 'DB_NAME', 'DB_DATABASE'],
            'USER' => ['ERP_DB_USER', 'DB_USER', 'DB_USERNAME'],
            'PASS' => ['ERP_DB_PASS', 'DB_PASS', 'DB_PASSWORD'],
        ];
        $keys = $map[$norm] ?? [$norm];
        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false && trim((string)$v) !== '') {
                return (string)$v;
            }
            if (isset($_ENV[$k]) && trim((string)$_ENV[$k]) !== '') {
                return (string)$_ENV[$k];
            }
            if (isset($_SERVER[$k]) && trim((string)$_SERVER[$k]) !== '') {
                return (string)$_SERVER[$k];
            }
        }
        return '';
    }
}
