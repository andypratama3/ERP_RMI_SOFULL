<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_state_lib.php';
require_once __DIR__ . '/../../tools_ui_helpers.php';

if (!function_exists('ppl_root')) {
    function ppl_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('ppl_pipeline_dir')) {
    function ppl_pipeline_dir(): string
    {
        $dir = ppl_root() . '/storage/logs/pipeline';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('ppl_mask')) {
    function ppl_mask(string $value): string
    {
        return ts_mask(tools_mask_sensitive($value));
    }
}

if (!function_exists('ppl_actor')) {
    function ppl_actor(): string
    {
        $actor = trim((string)(getenv('CI_ACTOR') ?: getenv('USER') ?: 'SYSTEM'));
        return ppl_mask($actor === '' ? 'SYSTEM' : $actor);
    }
}

if (!function_exists('ppl_fmt_ts')) {
    function ppl_fmt_ts(string $iso): string
    {
        $raw = trim($iso);
        if ($raw === '') return 'N/A';
        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s T');
        } catch (Throwable) {
            return ppl_mask($raw);
        }
    }
}

if (!function_exists('ppl_read_json_strict')) {
    function ppl_read_json_strict(string $path): array
    {
        $out = [
            'ok' => false,
            'error' => 'missing',
            'path_masked' => ppl_mask($path),
            'data' => [],
        ];
        if (!is_file($path)) return $out;
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            $out['error'] = 'empty';
            return $out;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                $out['error'] = 'schema_mismatch';
                return $out;
            }
            $out['ok'] = true;
            $out['error'] = '';
            $out['data'] = $decoded;
            return $out;
        } catch (Throwable $e) {
            $out['error'] = 'invalid_json';
            $out['error_masked'] = ppl_mask($e->getMessage());
            return $out;
        }
    }
}

if (!function_exists('ppl_write_json')) {
    function ppl_write_json(string $path, array $data): bool
    {
        if (!array_key_exists('state_version', $data)) $data['state_version'] = 1;
        return ts_write_json($path, $data);
    }
}

if (!function_exists('ppl_append_assumption')) {
    function ppl_append_assumption(string $planId, string $env, string $masterRunId, string $assumption, string $impact, string $howToRemove): void
    {
        $line = '- ts: ' . date(DateTimeInterface::ATOM)
            . ' | plan_id: ' . ppl_mask($planId)
            . ' | env: ' . ppl_mask($env)
            . ' | master_run_id: ' . ppl_mask($masterRunId)
            . ' | assumption: ' . ppl_mask($assumption)
            . ' | impact: ' . ppl_mask(strtoupper($impact))
            . ' | how_to_remove: ' . ppl_mask($howToRemove);
        $path = ppl_root() . '/docs/governance/ASSUMPTIONS.md';
        if (is_file($path) || is_dir(dirname($path))) {
            if (!is_file($path)) @file_put_contents($path, "# Assumptions Log\n\n");
            $ok = @file_put_contents($path, $line . "\n", FILE_APPEND);
            if ($ok !== false) return;
        }
        $fallback = ppl_pipeline_dir() . '/assumptions_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $masterRunId) . '.md';
        if (!is_file($fallback)) @file_put_contents($fallback, "# Assumptions Log (Fallback)\n\n");
        @file_put_contents($fallback, $line . "\n", FILE_APPEND);
    }
}

if (!function_exists('ppl_exec')) {
    function ppl_exec(string $cmd): array
    {
        $out = [];
        $code = 1;
        $t0 = microtime(true);
        @exec($cmd . ' 2>&1', $out, $code);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        return [
            'ok' => ((int)$code === 0),
            'code' => (int)$code,
            'duration_ms' => $ms,
            'output_tail_masked' => ppl_mask(implode(' | ', array_slice($out, -4))),
            'output_lines' => $out,
        ];
    }
}

