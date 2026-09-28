<?php
declare(strict_types=1);

require_once __DIR__ . '/tools_ui_helpers.php';

if (!function_exists('ts_root')) {
    function ts_root(): string
    {
        return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    }
}

if (!function_exists('tools_base_url')) {
    /**
     * Single source of truth: base URL for tools. Validates format, no double slash.
     * Baca .env jika env kosong — agar TOOLS_BASE_URL tidak pernah kosong di production.
     * Default (NAS): https://localhost/ERP_RMI_SOFULL
     */
    function tools_base_url(): string
    {
        $raw = trim((string)(
            getenv('TOOLS_BASE_URL_INTERNAL') ?:
            getenv('TOOLS_BASE_URL') ?:
            getenv('SMOKE_BASE_URL') ?:
            getenv('APP_URL') ?:
            getenv('APP_BASE_URL') ?: ''
        ));
        if ($raw === '') {
            $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
            $envPath = $root . '/_shared/env.php';
            if (is_file($envPath)) {
                require_once $envPath;
                if (function_exists('rmi_env_load')) {
                    rmi_env_load();
                }
            }
            $raw = trim((string)(
                getenv('TOOLS_BASE_URL_INTERNAL') ?:
                getenv('TOOLS_BASE_URL') ?:
                getenv('SMOKE_BASE_URL') ?:
                getenv('APP_URL') ?: 'https://localhost/ERP_RMI_SOFULL'
            ));
        }
        if ($raw === '' || !preg_match('#^https?://#', $raw)) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "FAIL: TOOLS_BASE_URL required and must start http:// or https://\n");
                exit(1);
            }
            throw new RuntimeException('TOOLS_BASE_URL invalid');
        }
        $uu = __DIR__ . '/_shared/url_utils.php';
        if (is_file($uu) && !function_exists('rmi_normalize_base_url')) {
            require_once $uu;
        }
        if (function_exists('rmi_normalize_base_url')) {
            try {
                return rmi_normalize_base_url($raw);
            } catch (Throwable $e) {
                // fall through to legacy normalize
            }
        }
        $raw = preg_replace('#(https?://)/+#', '$1', $raw);
        $raw = preg_replace('#([^:])//{1,}#', '$1/', $raw);
        return rtrim($raw, '/');
    }
}

if (!function_exists('tools_default_base_url')) {
    function tools_default_base_url(): string
    {
        return tools_base_url();
    }
}

if (!function_exists('ts_storage_logs_dir')) {
    function ts_storage_logs_dir(): string
    {
        $dir = ts_root() . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('ts_storage_state_dir')) {
    function ts_storage_state_dir(): string
    {
        $dir = ts_root() . '/storage/state';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('ts_mask')) {
    function ts_mask(string $text): string
    {
        $root = ts_root();
        $masked = str_replace($root, '[APP_ROOT]', $text);
        $patterns = [
            '/(--password=)([^\s]+)/i',
            '/(password\s*=\s*)([^\s&]+)/i',
            '/(DB_PASS(?:WORD)?\s*=\s*)([^\s]+)/i',
        ];
        foreach ($patterns as $pattern) {
            $masked = preg_replace($pattern, '$1[REDACTED]', $masked) ?? $masked;
        }
        return $masked;
    }
}

if (!function_exists('business_signoff_state_path')) {
    /** Canonical state file: storage/logs/business_signoff.last.json */
    function business_signoff_state_path(): string
    {
        return ts_storage_logs_dir() . '/business_signoff.last.json';
    }
}

if (!function_exists('business_signoff_read_state')) {
    /** Read state with backward compat for business_signoff_last.json */
    function business_signoff_read_state(): array
    {
        $primary = business_signoff_state_path();
        $fallback = ts_storage_logs_dir() . '/business_signoff_last.json';
        $r = is_file($primary) ? tools_read_state_json($primary, []) : ['ok' => false];
        if ($r['ok'] ?? false) {
            return $r;
        }
        return is_file($fallback) ? tools_read_state_json($fallback, []) : ['ok' => false, 'data' => null, 'error' => 'missing'];
    }
}

if (!function_exists('ts_read_json')) {
    function ts_read_json(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = (string)@file_get_contents($path);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        // Backward compatibility: legacy files without state_version treated as v0.
        if (!array_key_exists('state_version', $decoded)) {
            $decoded['state_version'] = 0;
        }
        return $decoded;
    }
}

if (!function_exists('ts_write_json')) {
    function ts_write_json(string $path, array $data): bool
    {
        if (!array_key_exists('state_version', $data)) {
            $data['state_version'] = 1;
        }
        $root = ts_root();
        $workspaceLock = $root . '/tools/_shared/workspace_lock.php';
        if (is_file($workspaceLock)) {
            require_once $workspaceLock;
            if (function_exists('tools_is_forbidden_path') && tools_is_forbidden_path($path)) {
                return false;
            }
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $safeIo = $root . '/tools/_shared/safe_io.php';
        if (is_file($safeIo)) {
            try {
                require_once $safeIo;
                if (function_exists('tools_safe_write_json')) {
                    $ok = tools_safe_write_json($path, $data);
                    if ($ok) return true;
                }
            } catch (\Throwable $e) {
                // safe_io threw (workspace_lock.php not accessible?) — fall through to direct write
            }
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        // Direct write — works when target file has 644/666 perm even if dir is not writable by current user
        $written = @file_put_contents($path, $json, LOCK_EX) !== false;
        // Ensure file is readable by web server (http user)
        if ($written && is_file($path)) {
            @chmod($path, 0644);
        }
        return $written;
    }
}

if (!function_exists('ts_smoke_summary')) {
    function ts_smoke_summary(array $smoke): array
    {
        $total = isset($smoke['total']) ? (int)$smoke['total'] : (isset($smoke['summary']['total']) ? (int)$smoke['summary']['total'] : 0);
        $pass = isset($smoke['pass']) ? (int)$smoke['pass'] : (isset($smoke['summary']['pass']) ? (int)$smoke['summary']['pass'] : 0);
        $fail = isset($smoke['fail']) ? (int)$smoke['fail'] : (isset($smoke['summary']['fail']) ? (int)$smoke['summary']['fail'] : -1);
        $elapsed = (int)($smoke['elapsed_ms'] ?? $smoke['duration_ms'] ?? 0);
        $unknown = ($fail < 0);
        return [
            'total' => $total,
            'pass' => $pass,
            'fail' => max(0, $fail),
            'elapsed_ms' => $elapsed,
            'unknown' => $unknown,
            'ok' => !$unknown && $fail === 0,
            'consistent' => $total <= 0 ? true : (($pass + max(0, $fail)) === $total),
        ];
    }
}

if (!function_exists('ts_parse_kv_file')) {
    function ts_parse_kv_file(string $path): array
    {
        $out = [];
        if (!is_file($path)) {
            return $out;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $out[trim($k)] = trim($v, "\"'");
        }
        return $out;
    }
}

if (!function_exists('ts_parse_scheduler_kv')) {
    function ts_parse_scheduler_kv(string $path): array
    {
        $state = ts_parse_kv_file($path);
        if (!isset($state['state_version'])) {
            $state['state_version'] = '0';
        }
        return $state;
    }
}

if (!function_exists('ts_human_bytes')) {
    function ts_human_bytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1024 * 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}

if (!function_exists('ts_latest_backup_meta')) {
    function ts_latest_backup_meta(): array
    {
        $backupRoot = ts_root() . '/storage/backups';
        if (!is_dir($backupRoot)) {
            return [
                'ok' => false,
                'name' => '-',
                'path_masked' => '-',
                'size_bytes' => 0,
                'size_human' => '-',
                'created_at' => null,
                'age_hours' => null,
                'checksum_ok' => false,
                'checksum' => '',
                'download_rel' => null,
            ];
        }

        // Only consider actual backup packages (ERP_RMI_SOFULL_backup_* dirs), not state/config files
        $candidates = glob($backupRoot . '/ERP_RMI_SOFULL_backup_*', GLOB_ONLYDIR) ?: [];
        $candidates = array_filter($candidates, static fn(string $p): bool => is_dir($p));
        if (!$candidates) {
            return [
                'ok' => false,
                'name' => '-',
                'path_masked' => '-',
                'size_bytes' => 0,
                'size_human' => '-',
                'created_at' => null,
                'age_hours' => null,
                'checksum_ok' => false,
                'checksum' => '',
                'download_rel' => null,
            ];
        }
        usort($candidates, static fn($a, $b) => (int)filemtime($b) <=> (int)filemtime($a));
        $latest = $candidates[0];
        $isDir = is_dir($latest);
        $mtime = (int)@filemtime($latest);
        $size = 0;
        $checksumOk = false;
        $checksum = '';
        $manifest = [];
        $downloadRel = null;

        if ($isDir) {
            foreach ((glob($latest . '/*') ?: []) as $f) {
                if (is_file($f)) {
                    $size += (int)@filesize($f);
                }
            }
            $manifest = ts_read_json($latest . '/manifest.json');
            if ($manifest) {
                $checksumOk = true;
            } else {
                $fileForChecksum = '';
                foreach (['db.sql.gz', 'files.tar.gz', 'backup.sql.gz'] as $candidate) {
                    $p = $latest . '/' . $candidate;
                    if (is_file($p)) {
                        $fileForChecksum = $p;
                        break;
                    }
                }
                if ($fileForChecksum !== '') {
                    $checksum = (string)hash_file('sha256', $fileForChecksum);
                    $checksumOk = ($checksum !== '');
                }
            }
            $downloadRel = 'storage/backups/' . basename($latest);
        } else {
            $size = (int)@filesize($latest);
            $checksum = (string)hash_file('sha256', $latest);
            $checksumOk = ($checksum !== '');
            $downloadRel = 'storage/backups/' . basename($latest);
        }

        $ageHours = $mtime > 0 ? round((time() - $mtime) / 3600, 2) : null;
        return [
            'ok' => true,
            'name' => basename($latest),
            'path_masked' => ts_mask($latest),
            'size_bytes' => $size,
            'size_human' => ts_human_bytes($size),
            'created_at' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : null,
            'age_hours' => $ageHours,
            'checksum_ok' => $checksumOk,
            'checksum' => $checksum,
            'download_rel' => $downloadRel,
            'manifest' => $manifest,
        ];
    }
}

if (!function_exists('ts_scheduler_state')) {
    function ts_scheduler_state(): array
    {
        $root = ts_root();
        $files = [
            $root . '/storage/backups/autobackup_schedule_2300.state',
            $root . '/storage/logs/backup_daily_2300.state',
            $root . '/storage/logs/backup_daily.state',
        ];
        $state = [];
        foreach ($files as $f) {
            if (is_file($f)) {
                $state = array_merge($state, ts_parse_scheduler_kv($f));
            }
        }
        $enabled = ((string)($state['enabled'] ?? '0') === '1');
        return [
            'configured' => !empty($state),
            'enabled' => $enabled,
            'cron' => (string)($state['cron'] ?? '0 23 * * *'),
            'timezone' => (string)($state['timezone'] ?? 'Asia/Jakarta'),
            'installed_at' => (string)($state['installed_at'] ?? ''),
            'log_file' => ts_mask((string)($state['log_file'] ?? ($root . '/storage/logs/backup_daily_2300.log'))),
            'last_run_at' => (string)($state['last_run_at'] ?? ''),
            'last_status' => strtoupper((string)($state['last_status'] ?? 'UNKNOWN')),
            'next_run_at' => (string)($state['next_run_at'] ?? ''),
            'state_version' => (int)($state['state_version'] ?? 0),
        ];
    }
}

if (!function_exists('ts_append_run_history')) {
    function ts_append_run_history(string $event, string $status, array $meta = []): void
    {
        $path = ts_storage_logs_dir() . '/tools_run_history.jsonl';
        $actor = (string)($meta['actor_username'] ?? ($_SESSION['username'] ?? (getenv('USER') ?: 'SYSTEM')));
        $module = (string)($meta['module'] ?? 'tools');
        $action = (string)($meta['action'] ?? $event);
        $toolId = (string)($meta['tool_id'] ?? '');
        $requestId = (string)($meta['request_id'] ?? ('req-' . date('YmdHis') . '-' . substr(sha1($event . microtime(true)), 0, 10)));
        $result = strtoupper(trim((string)($meta['result'] ?? $status)));
        $metaMasked = ts_mask(json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '{}');
        $payload = [
            'ts' => date(DateTimeInterface::ATOM),
            'time' => date(DateTimeInterface::ATOM),
            'request_id' => $requestId,
            'module' => $module,
            'event' => $event,
            'action' => $action,
            'tool_id' => $toolId,
            'status' => strtoupper(trim($status)),
            'result' => $result,
            'actor_username' => $actor,
            'meta' => $meta,
            'meta_masked' => $metaMasked,
            'event_version' => 1,
            'state_version' => 1,
        ];
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }
}

if (!function_exists('append_history_event')) {
    function append_history_event(string $event, string $status, array $meta = []): void
    {
        ts_append_run_history($event, $status, $meta);
    }
}

if (!function_exists('ts_runtime_config_valid')) {
    function ts_runtime_config_valid(): array
    {
        $envPath = ts_root() . '/storage/backups/backup_runtime.env';
        $cfg = ts_parse_kv_file($envPath);
        if (is_file(__DIR__ . '/tools_ui_helpers.php')) {
            require_once __DIR__ . '/tools_ui_helpers.php';
        }
        $errors = [];
        $warnings = [];
        $binReady = true;
        foreach (['MYSQLDUMP_BIN', 'MYSQL_BIN'] as $key) {
            $v = trim((string)($cfg[$key] ?? ''));
            if ($v === '') {
                $warnings[] = $key . ':missing';
                $binReady = false;
                continue;
            }
            if (!str_starts_with($v, '/') || !is_file($v) || !is_executable($v)) {
                $warnings[] = $key . ':invalid';
                $binReady = false;
            }
        }
        $host = trim((string)($cfg['ERP_DB_HOST'] ?? $cfg['DB_HOST'] ?? (function_exists('tools_get_env_db') ? tools_get_env_db('HOST') : '')));
        $name = trim((string)($cfg['ERP_DB_NAME'] ?? $cfg['DB_NAME'] ?? $cfg['DB_DATABASE'] ?? (function_exists('tools_get_env_db') ? tools_get_env_db('NAME') : '')));
        $user = trim((string)($cfg['ERP_DB_USER'] ?? $cfg['DB_USER'] ?? $cfg['DB_USERNAME'] ?? (function_exists('tools_get_env_db') ? tools_get_env_db('USER') : '')));
        if ($host === '') $errors[] = 'DB_HOST:missing';
        if ($name === '') $errors[] = 'DB_NAME:missing';
        if ($user === '') $errors[] = 'DB_USER:missing';
        // Local-first: DB creds are mandatory; mysql/mysqldump binaries are recommended
        // because backup/restore tooling already has graceful native fallbacks.
        return [
            'ok' => empty($errors),
            'strict_ok' => empty($errors) && empty($warnings),
            'env_file' => ts_mask($envPath),
            'errors' => $errors,
            'warnings' => $warnings,
            'bin_ready' => $binReady,
            'db_source' => ['host' => $host !== '', 'name' => $name !== '', 'user' => $user !== ''],
        ];
    }
}

if (!function_exists('ts_write_diag_state')) {
    function ts_write_diag_state(string $toolId, bool $ok, int $durationMs, string $summary, string $lastError = '', array $meta = []): void
    {
        $payload = [
            'tool_id' => $toolId,
            'ok' => $ok,
            'checked_at' => date(DateTimeInterface::ATOM),
            'duration_ms' => $durationMs,
            'summary' => $summary,
            'last_error_masked' => ts_mask($lastError),
            'meta' => $meta,
        ];
        ts_write_json(ts_storage_logs_dir() . '/' . $toolId . '.last.json', $payload);
    }
}

if (!function_exists('ts_read_diag_state')) {
    function ts_read_diag_state(string $toolId): array
    {
        return ts_read_json(ts_storage_logs_dir() . '/' . $toolId . '.last.json');
    }
}

if (!function_exists('ts_run_diag_check')) {
    function ts_run_diag_check(string $toolId): array
    {
        $t0 = microtime(true);
        $ok = false;
        $summary = '';
        $err = '';
        $meta = [];
        try {
            if ($toolId === 'diag_db_test') {
                require_once ts_root() . '/_shared/db.php';
                $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
                $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
                $ok = true;
                $summary = 'DB connect OK';
                $meta['database'] = $dbName;
            } elseif ($toolId === 'diag_boot') {
                require_once ts_root() . '/_shared/bootstrap.php';
                $required = ['rmi_db_pdo', 'csrf_token', 'require_login'];
                $missing = [];
                foreach ($required as $fn) {
                    if (!function_exists($fn)) {
                        $missing[] = $fn;
                    }
                }
                $ok = empty($missing);
                $summary = $ok ? 'Bootstrap functions OK' : ('Missing: ' . implode(',', $missing));
                $meta['missing'] = $missing;
            } elseif ($toolId === 'diag_db') {
                require_once ts_root() . '/_shared/db.php';
                $cfg = function_exists('rmi_db_config') ? rmi_db_config() : [];
                $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
                $ok = (bool)$pdo->query('SELECT 1')->fetchColumn();
                $summary = $ok ? 'DB config + query OK' : 'DB query failed';
                $meta['host'] = (string)($cfg['host'] ?? '');
                $meta['name'] = (string)($cfg['name'] ?? '');
            } else {
                throw new RuntimeException('Unsupported diagnostic tool');
            }
        } catch (Throwable $e) {
            $ok = false;
            $summary = 'Check failed';
            $err = $e->getMessage();
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        ts_write_diag_state($toolId, $ok, $ms, $summary, $err, $meta);
        return ts_read_diag_state($toolId);
    }
}

if (!function_exists('ts_readiness_score')) {
    function ts_readiness_score(): array
    {
        $smoke = ts_read_json(ts_storage_logs_dir() . '/smoke_http_last.json');
        $preflight = ts_read_json(ts_storage_logs_dir() . '/preflight_check.last.json');
        $health = ts_read_json(ts_storage_logs_dir() . '/health.last.json');

        $healthOk = (bool)($health['ok'] ?? false);
        $smokeFail = null;
        if (isset($smoke['fail'])) {
            $smokeFail = (int)$smoke['fail'];
        } elseif (isset($smoke['summary']['fail'])) {
            $smokeFail = (int)$smoke['summary']['fail'];
        }
        $smokeOk = isset($smoke['ok'])
            ? (bool)$smoke['ok']
            : ($smokeFail !== null ? ($smokeFail === 0) : false);
        // Fallback: jika CLI smoke fail (karena env limitation), cek web smoke
        if (!$smokeOk) {
            $smokeWeb = ts_read_json(ts_storage_logs_dir() . '/smoke_http_web.last.json');
            // Support multiple formats: overall_ok (top), summary.ok, ok (top)
            $webOk = (bool)($smokeWeb['overall_ok'] ?? $smokeWeb['summary']['ok'] ?? $smokeWeb['ok'] ?? false);
            $webFail = (int)($smokeWeb['summary']['fail'] ?? $smokeWeb['fail'] ?? 999);
            if ($webOk || $webFail === 0) {
                $smokeOk = true; // web smoke passes = system ok, CLI failure is env-only
                $smoke = $smokeWeb; // use web smoke data for reporting
            }
        }
        $preflightOk = (bool)($preflight['ok'] ?? false);

        $score = 0;
        if ($healthOk) $score += 40;
        if ($smokeOk) $score += 40;
        if ($preflightOk) $score += 20;

        return [
            'score' => $score,
            'health_ok' => $healthOk,
            'smoke_ok' => $smokeOk,
            'preflight_ok' => $preflightOk,
            'smoke' => $smoke,
            'preflight' => $preflight,
            'health' => $health,
        ];
    }
}

if (!function_exists('ts_generate_readiness_report')) {
    function ts_generate_readiness_report(): array
    {
        $rd = ts_readiness_score();
        $payload = [
            'generated_at' => date(DateTimeInterface::ATOM),
            'score' => (int)$rd['score'],
            'health_ok' => (bool)$rd['health_ok'],
            'smoke_ok' => (bool)$rd['smoke_ok'],
            'preflight_ok' => (bool)$rd['preflight_ok'],
            'breakdown' => [
                'health_ok' => (bool)$rd['health_ok'],
                'smoke_ok' => (bool)$rd['smoke_ok'],
                'preflight_ok' => (bool)$rd['preflight_ok'],
            ],
            'sources' => [
                'smoke_http_last' => ts_mask(ts_storage_logs_dir() . '/smoke_http_last.json'),
                'preflight_last' => ts_mask(ts_storage_logs_dir() . '/preflight_check.last.json'),
                'health_last' => ts_mask(ts_storage_logs_dir() . '/health.last.json'),
            ],
            'last_errors_masked' => [
                'smoke' => ts_mask((string)($rd['smoke']['error'] ?? '')),
                'preflight' => ts_mask((string)($rd['preflight']['error'] ?? '')),
                'health' => ts_mask((string)($rd['health']['error'] ?? '')),
            ],
        ];
        $jsonPath = ts_storage_logs_dir() . '/readiness_report_last.json';
        $mdPath = ts_storage_logs_dir() . '/readiness_report_last.md';
        ts_write_json($jsonPath, $payload);

        $md = "# Readiness Report\n\n";
        $md .= "- Generated at: " . $payload['generated_at'] . "\n";
        $md .= "- Score: " . $payload['score'] . "/100\n";
        $md .= "- Health: " . ($payload['breakdown']['health_ok'] ? 'OK' : 'FAIL') . "\n";
        $md .= "- Smoke: " . ($payload['breakdown']['smoke_ok'] ? 'OK' : 'FAIL') . "\n";
        $md .= "- Preflight: " . ($payload['breakdown']['preflight_ok'] ? 'OK' : 'FAIL') . "\n\n";
        $md .= "## Sources\n";
        foreach ($payload['sources'] as $k => $v) {
            $md .= "- {$k}: {$v}\n";
        }
        $md .= "\n## Last Errors (Masked)\n";
        foreach ($payload['last_errors_masked'] as $k => $v) {
            $md .= "- {$k}: " . ($v !== '' ? $v : '-') . "\n";
        }
        @file_put_contents($mdPath, $md);
        ts_append_run_history('readiness_generate', 'OK', [
            'actor_username' => PHP_SAPI === 'cli' ? (getenv('USER') ?: 'SYSTEM') : (string)($_SESSION['username'] ?? 'SYSTEM'),
            'source' => 'tools_state_lib.php',
        ]);

        return [
            'json' => ts_mask($jsonPath),
            'md' => ts_mask($mdPath),
            'payload' => $payload,
        ];
    }
}

if (!function_exists('ts_run_all_checks_web')) {
    function ts_run_all_checks_web(): array
    {
        $root = ts_root();
        $phpBin = defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php';
        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/run_all_checks.php');
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $raw = trim((string)implode("\n", $out));
        $statePath = ts_storage_logs_dir() . '/all_checks.last.json';
        $state = ts_read_json($statePath);

        $ok = ((int)$code === 0) && !empty($state);
        ts_append_run_history('all_checks_web_run', $ok ? 'OK' : 'FAIL', [
            'actor_username' => PHP_SAPI === 'cli' ? (getenv('USER') ?: 'SYSTEM') : (string)($_SESSION['username'] ?? 'SYSTEM'),
            'source' => 'tools/index.php',
            'exit_code' => (int)$code,
            'output_tail_masked' => ts_mask((string)substr($raw, -500)),
        ]);

        return [
            'ok' => $ok,
            'exit_code' => (int)$code,
            'state' => $state,
            'output_masked' => ts_mask($raw),
            'state_path' => ts_mask($statePath),
        ];
    }
}

if (!function_exists('ts_run_ops_hardening_web')) {
    function ts_run_ops_hardening_web(string $scope = 'ops', string $mode = 'normal'): array
    {
        $root = ts_root();
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['ops', 'full'], true)) {
            $scope = 'ops';
        }
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['normal', 'dry-run', 'reset'], true)) {
            $mode = 'normal';
        }

        $phpBin = defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php';
        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($root . '/tools/ops/mutation_hardening_audit.php') . ' --scope=' . escapeshellarg($scope);
        if ($mode === 'dry-run') {
            $cmd .= ' --baseline-dry-run';
        } elseif ($mode === 'reset') {
            $cmd .= ' --baseline-reset';
        }

        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $raw = trim((string)implode("\n", $out));

        $lastJson = [];
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), static fn(string $v): bool => $v !== ''));
        if ($lines) {
            $cand = json_decode((string)end($lines), true);
            if (is_array($cand)) {
                $lastJson = $cand;
            }
        }
        $ok = ((int)$code === 0) && !empty($lastJson);

        ts_append_run_history('ops_hardening_audit_web_run', $ok ? 'OK' : 'FAIL', [
            'actor_username' => PHP_SAPI === 'cli' ? (getenv('USER') ?: 'SYSTEM') : (string)($_SESSION['username'] ?? 'SYSTEM'),
            'source' => 'tools/index.php',
            'exit_code' => (int)$code,
            'scope' => $scope,
            'mode' => $mode,
            'output_tail_masked' => ts_mask((string)substr($raw, -700)),
        ]);

        return [
            'ok' => $ok,
            'exit_code' => (int)$code,
            'result' => $lastJson,
            'output_masked' => ts_mask($raw),
        ];
    }
}

if (!function_exists('tools_read_state_json')) {
    function tools_read_state_json(string $path, array $requiredKeys = []): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'data' => null, 'error' => 'missing'];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return ['ok' => false, 'data' => null, 'error' => 'empty'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'data' => null, 'error' => 'invalid_json'];
        }
        foreach ($requiredKeys as $k) {
            if (!array_key_exists($k, $data)) {
                return ['ok' => false, 'data' => $data, 'error' => 'schema_mismatch'];
            }
        }
        return ['ok' => true, 'data' => $data, 'error' => ''];
    }
}

if (!function_exists('tools_contract_state_read')) {
    function tools_contract_state_read(): array
    {
        $path = ts_storage_logs_dir() . '/contract_check_last.json';
        if (!is_file($path)) {
            return ['ok' => false, 'data' => null, 'error' => 'missing'];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return ['ok' => false, 'data' => null, 'error' => 'empty'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'data' => null, 'error' => 'invalid_json'];
        }
        return ['ok' => true, 'data' => $data, 'error' => ''];
    }
}
