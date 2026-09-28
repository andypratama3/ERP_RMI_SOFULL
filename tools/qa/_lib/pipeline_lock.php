<?php
declare(strict_types=1);

require_once __DIR__ . '/pipeline_lib.php';

if (!function_exists('ppl_lock_validate_stage')) {
    function ppl_lock_validate_stage(string $stage, string $env, string $runId, bool $writeLast, bool $suggestOnFail): array
    {
        $root = ppl_root();
        $php = (string)(PHP_BINARY ?: 'php');
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/qa/validate_stage_assets.php')
            . ' --stage=' . escapeshellarg($stage)
            . ' --env=' . escapeshellarg($env)
            . ' --run-id=' . escapeshellarg($runId)
            . ($writeLast ? ' --write-last' : '');
        $res = ppl_exec($cmd);
        $out = [
            'name' => 'manifest_lock',
            'ok' => (bool)$res['ok'],
            'duration_ms' => (int)$res['duration_ms'],
            'output_tail_masked' => (string)$res['output_tail_masked'],
            'artifacts' => [],
        ];
        if ($res['ok']) return $out;
        if (!$suggestOnFail) return $out;

        $suggestCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/qa/manifest_lock_suggest.php')
            . ' --stage=' . escapeshellarg($stage)
            . ' --env=' . escapeshellarg($env)
            . ' --run-id=' . escapeshellarg($runId)
            . ($writeLast ? ' --write-last' : '');
        $sg = ppl_exec($suggestCmd);
        $art = [
            'json' => ppl_mask($root . '/storage/logs/pipeline/manifest_suggest_stage' . $stage . '_' . $runId . '.json'),
            'md' => ppl_mask($root . '/storage/logs/pipeline/manifest_suggest_stage' . $stage . '_' . $runId . '.md'),
        ];
        if ($writeLast) {
            $art['json_last'] = ppl_mask($root . '/storage/logs/pipeline/manifest_suggest_stage' . $stage . '_last.json');
            $art['md_last'] = ppl_mask($root . '/storage/logs/pipeline/manifest_suggest_stage' . $stage . '_last.md');
        }
        $out['suggest'] = [
            'ok' => (bool)$sg['ok'],
            'duration_ms' => (int)$sg['duration_ms'],
            'output_tail_masked' => (string)$sg['output_tail_masked'],
            'artifacts' => $art,
        ];
        return $out;
    }
}

