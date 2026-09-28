<?php
declare(strict_types=1);

require_once __DIR__ . '/pipeline_lib.php';
require_once __DIR__ . '/pipeline_lock.php';
require_once __DIR__ . '/pipeline_steps.php';
require_once __DIR__ . '/pipeline_report_render.php';
require_once __DIR__ . '/pipeline_plans_lib.php';
require_once __DIR__ . '/architecture_panel_from_arch_audit.php';
require_once __DIR__ . '/plan_exec_summary_lib.php';

if (!function_exists('ppl_manifest_load')) {
    function ppl_manifest_load(): array
    {
        $path = ppl_root() . '/tools/qa/stage_pipeline_manifest_v1.json';
        $r = ppl_read_json_strict($path);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => 'manifest_unavailable', 'manifest' => [], 'path_masked' => ppl_mask($path)];
        }
        $m = (array)$r['data'];
        if (!isset($m['stages']) || !is_array($m['stages'])) {
            return ['ok' => false, 'error' => 'manifest_schema_invalid', 'manifest' => [], 'path_masked' => ppl_mask($path)];
        }
        return ['ok' => true, 'error' => '', 'manifest' => $m, 'path_masked' => ppl_mask($path)];
    }
}

if (!function_exists('ppl_stage_to_summary')) {
    function ppl_stage_to_summary(string $stage, string $runId, bool $ok, string $reason, array $artifacts): array
    {
        $reasons = $reason !== '' ? [$reason] : [];
        return [
            'stage' => $stage,
            'run_id' => $runId,
            'overall_ok' => $ok,
            'level' => $ok ? 'HEALTHY' : 'CRITICAL',
            'reasons' => $reasons,
            'artifacts' => $artifacts,
        ];
    }
}

if (!function_exists('ppl_write_stage_payload')) {
    function ppl_write_stage_payload(string $runId, array $payload): string
    {
        $path = ppl_pipeline_dir() . '/pipeline_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) . '.json';
        ppl_write_json($path, $payload);
        return $path;
    }
}

if (!function_exists('ppl_run_stage_once')) {
    function ppl_run_stage_once(array $plan, string $stage, string $subRunId, bool $writeLast): array
    {
        $manifestRes = ppl_manifest_load();
        $payload = [
            'state_version' => 1,
            'generated_at' => date(DateTimeInterface::ATOM),
            'plan_id' => (string)($plan['id'] ?? ''),
            'env' => (string)($plan['env'] ?? 'staging'),
            'mode' => (string)($plan['mode'] ?? 'draft'),
            'stage' => $stage,
            'run_id' => $subRunId,
            'overall_ok' => false,
            'stop_reason' => '',
            'steps' => [],
        ];
        if (!$manifestRes['ok']) {
            $payload['stop_reason'] = (string)$manifestRes['error'];
            $path = ppl_write_stage_payload($subRunId, $payload);
            return ['ok' => false, 'reason' => (string)$payload['stop_reason'], 'payload_path' => $path, 'payload' => $payload];
        }
        $manifest = (array)$manifestRes['manifest'];
        $stageCfg = (array)($manifest['stages'][$stage] ?? []);
        if ($stageCfg === []) {
            $payload['stop_reason'] = 'manifest_stage_missing_' . $stage;
            $path = ppl_write_stage_payload($subRunId, $payload);
            return ['ok' => false, 'reason' => (string)$payload['stop_reason'], 'payload_path' => $path, 'payload' => $payload];
        }

        $profile = strtolower(trim((string)($plan['profile'] ?? '')));
        if ($profile !== '' && function_exists('ppl_profile_steps') && function_exists('ppl_run_profile_steps')) {
            $profileSteps = ppl_profile_steps($profile, $subRunId, $plan);
            if ($profileSteps !== []) {
                $stepsRes = ppl_run_profile_steps($profileSteps, $subRunId, $plan);
                foreach ((array)$stepsRes['steps'] as $st) $payload['steps'][] = $st;
                $payload['overall_ok'] = (bool)$stepsRes['ok'];
                $payload['stop_reason'] = (string)$stepsRes['stop_reason'];
                $path = ppl_write_stage_payload($subRunId, $payload);
                return ['ok' => (bool)$payload['overall_ok'], 'reason' => (string)$payload['stop_reason'], 'payload_path' => $path, 'payload' => $payload];
            }
        }

        $lock = ppl_lock_validate_stage(
            $stage,
            (string)($plan['env'] ?? 'staging'),
            $subRunId,
            $writeLast,
            (bool)($plan['allow_autogen_whitelists'] ?? false)
        );
        $payload['steps'][] = $lock;
        if (!(bool)$lock['ok']) {
            $payload['stop_reason'] = 'manifest_lock_failed_stage_' . $stage;
            $path = ppl_write_stage_payload($subRunId, $payload);
            return ['ok' => false, 'reason' => (string)$payload['stop_reason'], 'payload_path' => $path, 'payload' => $payload];
        }

        $stepsRes = ppl_run_stage_steps($stageCfg, $stage, $subRunId, $plan);
        foreach ((array)$stepsRes['steps'] as $st) $payload['steps'][] = $st;
        $payload['overall_ok'] = (bool)$stepsRes['ok'];
        $payload['stop_reason'] = (string)$stepsRes['stop_reason'];

        if ((bool)$stepsRes['ok'] && (bool)($plan['generate_plan_exec_summary'] ?? false)) {
            $php = (string)(PHP_BINARY ?: 'php');
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg(ppl_root() . '/tools/ops/generate_executive_summary.php')
                . ' --env=' . escapeshellarg((string)$plan['env'])
                . ' --run-id=' . escapeshellarg($subRunId)
                . ' --write-last';
            $exec = ppl_exec($cmd);
            $payload['steps'][] = [
                'name' => 'executive_summary',
                'ok' => (bool)$exec['ok'],
                'duration_ms' => (int)$exec['duration_ms'],
                'output_tail_masked' => (string)$exec['output_tail_masked'],
            ];
            if (!(bool)$exec['ok']) {
                $payload['overall_ok'] = false;
                $payload['stop_reason'] = 'executive_summary_generation_failed';
            }
        }

        $path = ppl_write_stage_payload($subRunId, $payload);
        return ['ok' => (bool)$payload['overall_ok'], 'reason' => (string)$payload['stop_reason'], 'payload_path' => $path, 'payload' => $payload];
    }
}

if (!function_exists('ppl_write_plan_summary')) {
    function ppl_write_plan_summary(array $summary): array
    {
        $planId = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$summary['plan_id']);
        $runId = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$summary['master_run_id']);
        $base = ppl_pipeline_dir() . '/plan_' . $planId . '_' . $runId;
        $json = $base . '.json';
        $md = $base . '.md';
        $html = $base . '.html';
        ppl_write_json($json, $summary);
        @file_put_contents($md, ppl_render_plan_md($summary));
        @file_put_contents($html, ppl_render_plan_html($summary));
        ppl_write_json(ppl_pipeline_dir() . '/plan_last.json', $summary);
        return ['json' => ppl_mask($json), 'md' => ppl_mask($md), 'html' => ppl_mask($html)];
    }
}

if (!function_exists('ppl_run_arch_audit_plan_end')) {
    function ppl_run_arch_audit_plan_end(array $plan, string $masterRunId): array
    {
        $cfg = ppl_arch_audit_config($plan);
        if (!$cfg['enabled'] || $cfg['run_scope'] !== 'plan_end') {
            return ['ok' => true, 'status' => 'SKIP', 'run_id' => $masterRunId, 'notes' => []];
        }
        $php = (string)(PHP_BINARY ?: 'php');
        $root = ppl_root();
        $strictArg = $cfg['strict'] ? ' --strict' : '';
        $cmd = $php . ' ' . escapeshellarg($root . '/tools/qa/arch_audit.php')
            . ' --run-id=' . escapeshellarg($masterRunId)
            . ' --scope=repo --max-files=' . (int)$cfg['max_files']
            . ' --write-last' . $strictArg;
        $res = ppl_exec_arch_audit_step($cmd, $masterRunId, $cfg);
        $status = (bool)($res['ok'] ?? false) ? 'OK' : 'FAIL';
        $notes = [];
        if (isset($res['error_code'])) {
            $notes[] = (string)$res['error_code'];
        }
        return [
            'ok' => (bool)($res['ok'] ?? false),
            'status' => $status,
            'run_id' => $masterRunId,
            'notes' => $notes,
        ];
    }
}

if (!function_exists('ppl_run_plan')) {
    function ppl_run_plan(array $plan, string $masterRunId, bool $writeLast): array
    {
        $type = strtolower((string)($plan['type'] ?? 'single'));
        $stages = [];
        if ($type === 'single') {
            $stages[] = (string)($plan['stage'] ?? '');
        } else {
            $stages = array_values(array_map(static fn($v): string => (string)$v, (array)($plan['stages'] ?? [])));
        }
        $perStageSuffix = (bool)($plan['per_stage_run_id_suffix'] ?? true);
        $stopOnFail = (bool)($plan['stop_on_fail'] ?? true);

        $archCfg = ppl_arch_audit_config($plan);
        $stagePlanOverride = $plan;
        if ($archCfg['enabled'] && $archCfg['run_scope'] === 'plan_end') {
            $stagePlanOverride = $plan;
            $stagePlanOverride['arch_audit'] = array_merge((array)($plan['arch_audit'] ?? []), ['enabled' => false, 'run_scope' => null]);
            $stagePlanOverride['arch_audit_enabled'] = false;
        }

        $stageRows = [];
        $overallOk = true;
        $failedStage = null;
        foreach ($stages as $stage) {
            $subRunId = $perStageSuffix ? ($masterRunId . '_S' . $stage) : $masterRunId;
            $run = ppl_run_stage_once($stagePlanOverride, $stage, $subRunId, $writeLast);
            $artifacts = [
                'pipeline_json' => ppl_mask((string)$run['payload_path']),
                'exec_summary_html' => is_file(ppl_root() . '/storage/logs/pipeline/executive_ops_summary_last.html')
                    ? ppl_mask(ppl_root() . '/storage/logs/pipeline/executive_ops_summary_last.html')
                    : null,
                'release_pack_zip' => null,
            ];
            $stageRows[] = ppl_stage_to_summary($stage, $subRunId, (bool)$run['ok'], (string)$run['reason'], $artifacts);
            if (!(bool)$run['ok']) {
                $overallOk = false;
                $failedStage = $stage;
                if ($stopOnFail) {
                    ppl_append_assumption(
                        (string)$plan['id'],
                        (string)$plan['env'],
                        $masterRunId,
                        'stage list truncated due to stop_on_fail at stage ' . $stage,
                        'MED',
                        'fix failing stage then rerun plan'
                    );
                    break;
                }
            }
        }

        $planSteps = [];
        $architecturePanel = ['status' => 'DATA_MISSING', 'arch_audit_run_id' => null, 'domains' => [], 'commands' => [], 'data_missing' => []];

        if ($archCfg['enabled'] && $archCfg['run_scope'] === 'plan_end') {
            $archRes = ppl_run_arch_audit_plan_end($plan, $masterRunId);
            $planSteps[] = [
                'name' => 'ARCH_AUDIT_PLAN_END',
                'status' => $archRes['status'],
                'run_id' => $archRes['run_id'],
                'notes' => $archRes['notes'],
            ];
            if (!$archRes['ok'] && !empty($archRes['notes'])) {
                ppl_append_assumption(
                    (string)$plan['id'],
                    (string)$plan['env'],
                    $masterRunId,
                    'arch_audit plan_end failed: ' . implode(', ', $archRes['notes']),
                    'MED',
                    'run: php tools/qa/arch_audit.php --run-id=' . $masterRunId . ' --scope=repo --write-last'
                );
            }
        }

        if (function_exists('apfa_build')) {
            $architecturePanel = apfa_build($masterRunId);
        }

        $summary = [
            'state_version' => 1,
            'generated_at' => date(DateTimeInterface::ATOM),
            'plan_id' => (string)$plan['id'],
            'env' => (string)$plan['env'],
            'mode' => (string)$plan['mode'],
            'master_run_id' => $masterRunId,
            'stop_on_fail' => $stopOnFail,
            'overall_ok' => $overallOk,
            'failed_stage' => $failedStage,
            'stages' => $stageRows,
            'plan_steps' => $planSteps,
            'architecture_panel' => $architecturePanel,
            'notes' => [],
        ];
        $art = ppl_write_plan_summary($summary);
        if (strtolower((string)($plan['type'] ?? 'single')) === 'multi' && function_exists('generate_plan_exec_summary')) {
            $execArt = generate_plan_exec_summary((string)$plan['id'], $masterRunId);
            $art['plan_exec_summary_json'] = $execArt['json'] ?? null;
            $art['plan_exec_summary_html'] = $execArt['html'] ?? null;
        }
        return [
            'ok' => $overallOk,
            'summary' => $summary,
            'artifacts' => $art,
            'failed_stage' => $failedStage,
        ];
    }
}

