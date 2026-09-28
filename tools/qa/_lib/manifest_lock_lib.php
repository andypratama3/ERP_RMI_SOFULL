<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_state_lib.php';
require_once __DIR__ . '/../../tools_ui_helpers.php';

if (!function_exists('manifest_lock_root')) {
    function manifest_lock_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('manifest_lock_mask')) {
    function manifest_lock_mask(string $value): string
    {
        return ts_mask(tools_mask_sensitive($value));
    }
}

if (!function_exists('manifest_lock_now_iso')) {
    function manifest_lock_now_iso(): string
    {
        return date(DateTimeInterface::ATOM);
    }
}

if (!function_exists('manifest_lock_stage_normalize')) {
    function manifest_lock_stage_normalize(string $stage): string
    {
        $num = preg_replace('/[^0-9]/', '', trim($stage)) ?? '';
        if ($num === '') {
            return '';
        }
        $n = (int)$num;
        if ($n < 0 || $n > 16) {
            return '';
        }
        return str_pad((string)$n, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('manifest_lock_generate_run_id')) {
    function manifest_lock_generate_run_id(string $stage, string $env): string
    {
        $seed = $stage . '|' . $env . '|' . microtime(true) . '|' . mt_rand();
        return 'manifestlock-' . strtolower($env) . '-s' . $stage . '-' . date('YmdHis') . '-' . substr(sha1($seed), 0, 10);
    }
}

if (!function_exists('manifest_lock_manifest_path')) {
    function manifest_lock_manifest_path(): string
    {
        return manifest_lock_root() . '/tools/qa/stage_pipeline_manifest_v1.json';
    }
}

if (!function_exists('manifest_lock_read_manifest')) {
    function manifest_lock_read_manifest(): array
    {
        $path = manifest_lock_manifest_path();
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'manifest_missing', 'path' => manifest_lock_mask($path), 'data' => []];
        }
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            return ['ok' => false, 'error' => 'manifest_empty', 'path' => manifest_lock_mask($path), 'data' => []];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return ['ok' => false, 'error' => 'manifest_not_object', 'path' => manifest_lock_mask($path), 'data' => []];
            }
            return ['ok' => true, 'error' => '', 'path' => manifest_lock_mask($path), 'data' => $decoded];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'manifest_invalid_json', 'path' => manifest_lock_mask($path), 'data' => []];
        }
    }
}

if (!function_exists('manifest_lock_get_stage_requirements')) {
    function manifest_lock_get_stage_requirements(array $manifest, string $stage): array
    {
        $requirements = [];
        if (isset($manifest['stages'][$stage]['requirements']) && is_array($manifest['stages'][$stage]['requirements'])) {
            $requirements = $manifest['stages'][$stage]['requirements'];
        }
        if ($requirements === []) {
            return ['ok' => false, 'error' => 'requirements_missing', 'requirements' => []];
        }
        return ['ok' => true, 'error' => '', 'requirements' => $requirements];
    }
}

if (!function_exists('manifest_lock_normalize_relpath')) {
    function manifest_lock_normalize_relpath(string $path): array
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return ['ok' => false, 'error' => 'empty_path', 'path' => ''];
        }
        $raw = str_replace('\\', '/', $trimmed);
        if (str_starts_with($raw, '/')) {
            return ['ok' => false, 'error' => 'absolute_path_not_allowed', 'path' => $raw];
        }
        $parts = explode('/', $raw);
        $safe = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return ['ok' => false, 'error' => 'path_traversal_detected', 'path' => $raw];
            }
            $safe[] = $part;
        }
        if ($safe === []) {
            return ['ok' => false, 'error' => 'invalid_path', 'path' => $raw];
        }
        return ['ok' => true, 'error' => '', 'path' => implode('/', $safe)];
    }
}

if (!function_exists('manifest_lock_resolve_relpath')) {
    function manifest_lock_resolve_relpath(string $relativePath): array
    {
        $root = manifest_lock_root();
        $norm = manifest_lock_normalize_relpath($relativePath);
        if (!$norm['ok']) {
            return [
                'ok' => false,
                'error' => (string)$norm['error'],
                'relative' => (string)$relativePath,
                'masked' => manifest_lock_mask('[APP_ROOT]/' . str_replace('\\', '/', trim($relativePath))),
                'absolute' => '',
            ];
        }
        $normalized = (string)$norm['path'];
        $absolute = $root . '/' . $normalized;
        $parent = dirname($absolute);
        $parentReal = realpath($parent);
        if ($parentReal !== false) {
            $rootReal = realpath($root) ?: $root;
            $parentNormalized = str_replace('\\', '/', $parentReal);
            $rootNormalized = str_replace('\\', '/', $rootReal);
            if (!str_starts_with($parentNormalized . '/', rtrim($rootNormalized, '/') . '/')
                && $parentNormalized !== rtrim($rootNormalized, '/')) {
                return [
                    'ok' => false,
                    'error' => 'outside_project_root',
                    'relative' => $normalized,
                    'masked' => manifest_lock_mask($absolute),
                    'absolute' => '',
                ];
            }
        }
        return [
            'ok' => true,
            'error' => '',
            'relative' => $normalized,
            'masked' => manifest_lock_mask($root . '/' . $normalized),
            'absolute' => $absolute,
        ];
    }
}

if (!function_exists('manifest_lock_file_requirement_check')) {
    function manifest_lock_file_requirement_check(string $requiredPath): array
    {
        $resolved = manifest_lock_resolve_relpath($requiredPath);
        if (!$resolved['ok']) {
            return [
                'ok' => false,
                'reason' => (string)$resolved['error'],
                'masked' => (string)$resolved['masked'],
            ];
        }
        $abs = (string)$resolved['absolute'];
        $rel = (string)$resolved['relative'];
        $expectsDir = str_ends_with(trim($requiredPath), '/');
        if ($expectsDir) {
            if (!is_dir($abs)) {
                return ['ok' => false, 'reason' => 'directory_missing', 'masked' => (string)$resolved['masked']];
            }
            $items = @scandir($abs);
            $nonDotCount = is_array($items) ? max(0, count(array_diff($items, ['.', '..']))) : 0;
            if ($nonDotCount <= 0) {
                return ['ok' => false, 'reason' => 'directory_empty', 'masked' => (string)$resolved['masked']];
            }
            return ['ok' => true, 'reason' => '', 'masked' => (string)$resolved['masked'], 'relative' => $rel];
        }
        if (!is_file($abs)) {
            return ['ok' => false, 'reason' => 'file_missing', 'masked' => (string)$resolved['masked']];
        }
        return ['ok' => true, 'reason' => '', 'masked' => (string)$resolved['masked'], 'relative' => $rel];
    }
}

if (!function_exists('manifest_lock_read_json_inside_root')) {
    function manifest_lock_read_json_inside_root(string $relativePath): array
    {
        $resolved = manifest_lock_resolve_relpath($relativePath);
        if (!$resolved['ok']) {
            return ['ok' => false, 'error' => (string)$resolved['error'], 'masked' => (string)$resolved['masked'], 'data' => []];
        }
        $abs = (string)$resolved['absolute'];
        if (!is_file($abs)) {
            return ['ok' => false, 'error' => 'file_missing', 'masked' => (string)$resolved['masked'], 'data' => []];
        }
        $raw = (string)@file_get_contents($abs);
        if (trim($raw) === '') {
            return ['ok' => false, 'error' => 'file_empty', 'masked' => (string)$resolved['masked'], 'data' => []];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return ['ok' => false, 'error' => 'invalid_json_type', 'masked' => (string)$resolved['masked'], 'data' => []];
            }
            return ['ok' => true, 'error' => '', 'masked' => (string)$resolved['masked'], 'data' => $decoded];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'invalid_json', 'masked' => (string)$resolved['masked'], 'data' => []];
        }
    }
}

if (!function_exists('manifest_lock_normalize_route')) {
    function manifest_lock_normalize_route(string $method, string $path): array
    {
        $m = strtoupper(trim($method));
        $p = trim($path);
        if ($m === '' || $p === '') {
            return ['ok' => false, 'method' => '', 'path' => '', 'key' => ''];
        }
        if (!str_starts_with($p, '/')) {
            $p = '/' . $p;
        }
        return ['ok' => true, 'method' => $m, 'path' => $p, 'key' => $m . ' ' . $p];
    }
}

if (!function_exists('manifest_lock_collect_routes_recursive')) {
    function manifest_lock_collect_routes_recursive(mixed $node, array &$routes): void
    {
        if (!is_array($node)) {
            return;
        }
        $hasMethod = array_key_exists('method', $node);
        $hasPath = array_key_exists('path', $node);
        if ($hasMethod && $hasPath && is_scalar($node['method']) && is_scalar($node['path'])) {
            $norm = manifest_lock_normalize_route((string)$node['method'], (string)$node['path']);
            if ($norm['ok']) {
                $routes[$norm['key']] = ['method' => $norm['method'], 'path' => $norm['path']];
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                manifest_lock_collect_routes_recursive($value, $routes);
            }
        }
    }
}

if (!function_exists('manifest_lock_extract_route_set_from_whitelist')) {
    function manifest_lock_extract_route_set_from_whitelist(array $json): array
    {
        $routes = [];
        manifest_lock_collect_routes_recursive($json, $routes);
        return $routes;
    }
}

if (!function_exists('manifest_lock_extract_required_routes')) {
    function manifest_lock_extract_required_routes(array $requirements, string $key): array
    {
        $input = $requirements[$key] ?? [];
        if (!is_array($input)) {
            return [];
        }
        $out = [];
        foreach ($input as $item) {
            if (is_string($item)) {
                $norm = manifest_lock_normalize_route('GET', $item);
                if ($norm['ok']) {
                    $out[$norm['key']] = ['method' => $norm['method'], 'path' => $norm['path']];
                }
                continue;
            }
            if (is_array($item) && isset($item['method'], $item['path'])) {
                $norm = manifest_lock_normalize_route((string)$item['method'], (string)$item['path']);
                if ($norm['ok']) {
                    $out[$norm['key']] = ['method' => $norm['method'], 'path' => $norm['path']];
                }
            }
        }
        return $out;
    }
}

if (!function_exists('manifest_lock_routes_missing')) {
    function manifest_lock_routes_missing(array $requiredRoutes, array $actualRoutes): array
    {
        $missing = [];
        foreach ($requiredRoutes as $key => $route) {
            if (!isset($actualRoutes[$key])) {
                $missing[] = (string)$route['method'] . ' ' . (string)$route['path'];
            }
        }
        sort($missing);
        return $missing;
    }
}

if (!function_exists('manifest_lock_pipeline_log_dir')) {
    function manifest_lock_pipeline_log_dir(): string
    {
        $dir = manifest_lock_root() . '/storage/logs/pipeline';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('manifest_lock_md_from_payload')) {
    function manifest_lock_md_from_payload(array $payload): string
    {
        $lines = [];
        $lines[] = '# Stage Manifest Lock';
        $lines[] = '';
        $lines[] = '- run_id: ' . manifest_lock_mask((string)($payload['run_id'] ?? ''));
        $lines[] = '- env: ' . manifest_lock_mask((string)($payload['env'] ?? ''));
        $lines[] = '- stage: ' . manifest_lock_mask((string)($payload['stage'] ?? ''));
        $lines[] = '- generated_at: ' . manifest_lock_mask((string)($payload['generated_at'] ?? ''));
        $lines[] = '- overall_ok: ' . (!empty($payload['overall_ok']) ? 'true' : 'false');
        $lines[] = '';
        $lines[] = '## Checks';
        foreach (($payload['checks'] ?? []) as $check) {
            $name = (string)($check['name'] ?? '-');
            $ok = !empty($check['ok']);
            $line = '- ' . $name . ': ' . ($ok ? 'OK' : 'FAIL');
            if (isset($check['missing']) && is_array($check['missing']) && $check['missing'] !== []) {
                $line .= ' | missing=' . implode(', ', array_map(static fn(string $v): string => manifest_lock_mask($v), $check['missing']));
            }
            if (isset($check['missing_env']) && is_array($check['missing_env']) && $check['missing_env'] !== []) {
                $line .= ' | missing_env=' . implode(', ', $check['missing_env']);
            }
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = '## Fails';
        $fails = $payload['fails'] ?? [];
        if (!is_array($fails) || $fails === []) {
            $lines[] = '- none';
        } else {
            foreach ($fails as $fail) {
                $code = (string)($fail['code'] ?? 'ERR_UNKNOWN');
                $message = manifest_lock_mask((string)($fail['message'] ?? ''));
                $details = manifest_lock_mask((string)($fail['details'] ?? ''));
                $lines[] = '- [' . $code . '] ' . $message . ($details !== '' ? ' | ' . $details : '');
            }
        }
        $lines[] = '';
        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('manifest_lock_write_evidence')) {
    function manifest_lock_write_evidence(array $payload, bool $writeLast = false): array
    {
        $stage = (string)($payload['stage'] ?? '');
        $runId = (string)($payload['run_id'] ?? '');
        $logDir = manifest_lock_pipeline_log_dir();
        $jsonPath = $logDir . '/manifest_lock_stage' . $stage . '_' . $runId . '.json';
        $mdPath = $logDir . '/manifest_lock_stage' . $stage . '_' . $runId . '.md';
        ts_write_json($jsonPath, $payload);
        @file_put_contents($mdPath, manifest_lock_md_from_payload($payload));

        $written = [
            'json' => manifest_lock_mask($jsonPath),
            'md' => manifest_lock_mask($mdPath),
        ];
        if ($writeLast) {
            $jsonLast = $logDir . '/manifest_lock_stage' . $stage . '_last.json';
            $mdLast = $logDir . '/manifest_lock_stage' . $stage . '_last.md';
            ts_write_json($jsonLast, $payload);
            @file_put_contents($mdLast, manifest_lock_md_from_payload($payload));
            $written['json_last'] = manifest_lock_mask($jsonLast);
            $written['md_last'] = manifest_lock_mask($mdLast);
        }
        return $written;
    }
}
