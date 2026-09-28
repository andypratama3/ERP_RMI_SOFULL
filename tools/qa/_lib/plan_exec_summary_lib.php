<?php
declare(strict_types=1);

require_once __DIR__ . '/pipeline_lib.php';
require_once __DIR__ . '/architecture_panel_from_arch_audit.php';
require_once __DIR__ . '/plan_exec_summary_render.php';

if (!function_exists('build_plan_exec_summary')) {
    /**
     * Build Plan Executive Summary from plan JSON and arch_audit (MASTER_RUN_ID aligned).
     * @param string $planJsonPath Path to plan_<PLAN_ID>_<MASTER_RUN_ID>.json
     * @param array $opts Optional: arch_audit_path override (normally derived from master_run_id)
     * @return array Exec summary object per schema
     */
    function build_plan_exec_summary(string $planJsonPath, array $opts = []): array
    {
        $pipe = ppl_pipeline_dir();
        $dataMissing = [];
        $planData = [];
        $planId = '';
        $masterRunId = '';
        $env = 'unknown';

        if (!is_file($planJsonPath)) {
            $dataMissing[] = ['source' => 'plan_json', 'reason' => 'MISSING'];
            $base = basename($planJsonPath, '.json');
            if (str_starts_with($base, 'plan_')) {
                $pos = strrpos($base, '_');
                if ($pos !== false) {
                    $planId = substr($base, 5, $pos - 5);
                    $masterRunId = substr($base, $pos + 1);
                }
            }
        } else {
            $r = ppl_read_json_strict($planJsonPath);
            if (!$r['ok']) {
                $dataMissing[] = ['source' => 'plan_json', 'reason' => (string)($r['error'] ?? 'INVALID_JSON')];
            } else {
                $planData = (array)$r['data'];
                $planId = trim((string)($planData['plan_id'] ?? ''));
                $masterRunId = trim((string)($planData['master_run_id'] ?? ''));
                $env = strtolower(trim((string)($planData['env'] ?? 'unknown')));
            }
        }

        $overallOk = (bool)($planData['overall_ok'] ?? false);
        $failedStage = trim((string)($planData['failed_stage'] ?? ''));
        if ($failedStage === '') $failedStage = null;

        $goNoGo = 'UNKNOWN';
        $level = 'UNKNOWN';
        $reasons = [];
        if ($planData !== []) {
            if ($overallOk && $failedStage === null) {
                $goNoGo = 'GO';
            } else {
                $goNoGo = 'NO-GO';
            }
            if ($goNoGo === 'UNKNOWN') {
                $level = 'UNKNOWN';
            } elseif ($goNoGo === 'NO-GO') {
                $level = 'CRITICAL';
                foreach ((array)($planData['stages'] ?? []) as $s) {
                    if (!(bool)($s['overall_ok'] ?? true)) {
                        $reasons = array_merge($reasons, (array)($s['reasons'] ?? []));
                        break;
                    }
                }
                if ($failedStage !== null) {
                    $reasons[] = 'stop_on_fail at stage ' . $failedStage;
                }
            } else {
                $hasAttention = false;
                $hasDataMissing = false;
                foreach ((array)($planData['stages'] ?? []) as $s) {
                    if (strtoupper((string)($s['level'] ?? '')) === 'ATTENTION') $hasAttention = true;
                }
                $archPanel = (array)($planData['architecture_panel'] ?? []);
                if ((string)($archPanel['status'] ?? '') === 'DATA_MISSING') $hasDataMissing = true;
                if ($hasAttention || $hasDataMissing) {
                    $level = 'ATTENTION';
                } else {
                    $level = 'HEALTHY';
                }
            }
        }

        $stages = (array)($planData['stages'] ?? []);
        $stagesTotal = count($stages);
        $stagesPass = 0;
        $stagesFail = 0;
        foreach ($stages as $s) {
            if ((bool)($s['overall_ok'] ?? false)) {
                $stagesPass++;
            } else {
                $stagesFail++;
            }
        }

        $architecture = [
            'status' => 'DATA_MISSING',
            'arch_audit_run_id' => null,
            'domains' => [],
            'commands' => [],
        ];
        if ($masterRunId !== '') {
            $arch = apfa_build($masterRunId);
            $architecture['status'] = (string)($arch['status'] ?? 'DATA_MISSING');
            $architecture['arch_audit_run_id'] = $arch['arch_audit_run_id'];
            $architecture['commands'] = (array)($arch['commands'] ?? []);
            foreach ((array)($arch['domains'] ?? []) as $d) {
                $architecture['domains'][] = [
                    'name' => (string)($d['name'] ?? ''),
                    'status' => (string)($d['status'] ?? 'UNKNOWN'),
                    'confidence' => min(100, max(0, (int)($d['confidence'] ?? 0))),
                    'note' => (string)($d['note'] ?? ''),
                ];
            }
        }

        $evidenceLinks = [];
        $perStageSuffix = (bool)($planData['per_stage_run_id_suffix'] ?? true);
        foreach (array_slice($stages, 0, 5) as $s) {
            $stage = (string)($s['stage'] ?? '');
            $runId = (string)($s['run_id'] ?? '');
            $art = (array)($s['artifacts'] ?? []);
            $pj = (string)($art['pipeline_json'] ?? '');
            if ($pj !== '') {
                $evidenceLinks[] = ['label' => 'Stage ' . $stage . ' pipeline json', 'path' => $pj];
            }
            $esh = (string)($art['exec_summary_html'] ?? '');
            if ($esh !== '') {
                $evidenceLinks[] = ['label' => 'Stage ' . $stage . ' exec summary html', 'path' => $esh];
            }
        }

        $nextActions = [];
        if ($failedStage !== null && $masterRunId !== '') {
            $subRunId = $perStageSuffix ? ($masterRunId . '_S' . $failedStage) : $masterRunId;
            $nextActions[] = 'php tools/qa/run_pipeline_final.php --plan=stage' . $failedStage . '_staging_draft --run-id=' . $subRunId . ' --write-last';
        }
        if ($masterRunId !== '') {
            $nextActions[] = 'php tools/qa/arch_audit.php --run-id=' . $masterRunId . ' --scope=repo --write-last';
        }
        if ($nextActions === [] && $dataMissing !== []) {
            $nextActions[] = 'php tools/qa/run_pipeline_final.php --plan=' . $planId . ' --run-id=auto --write-last';
        }

        $notes = array_map(static fn($v): string => ppl_mask((string)$v), (array)($planData['notes'] ?? []));
        $reasonsMasked = array_map(static fn($v): string => ppl_mask((string)$v), array_slice(array_unique($reasons), 0, 3));

        $stagesChecklist = [];
        foreach ($stages as $s) {
            $stagesChecklist[] = [
                'stage' => (string)($s['stage'] ?? ''),
                'ok' => (bool)($s['overall_ok'] ?? false),
            ];
        }

        return [
            'state_version' => 1,
            'generated_at' => date(DateTimeInterface::ATOM),
            'plan_id' => $planId,
            'master_run_id' => $masterRunId,
            'env' => $env,
            'decision' => [
                'go_no_go' => $goNoGo,
                'level' => $level,
                'first_failure_stage' => $failedStage,
                'reasons' => $reasonsMasked,
            ],
            'summary' => [
                'stages_total' => $stagesTotal,
                'stages_pass' => $stagesPass,
                'stages_fail' => $stagesFail,
                'stop_on_fail' => (bool)($planData['stop_on_fail'] ?? true),
                'stages_checklist' => $stagesChecklist,
            ],
            'architecture' => $architecture,
            'evidence_links' => $evidenceLinks,
            'next_actions' => $nextActions,
            'data_missing' => $dataMissing,
            'notes' => $notes,
        ];
    }
}

if (!function_exists('generate_plan_exec_summary')) {
    /**
     * Generate plan exec summary files. Call after plan summary is written.
     */
    function generate_plan_exec_summary(string $planId, string $masterRunId): array
    {
        $pipe = ppl_pipeline_dir();
        $planIdSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $planId);
        $runIdSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $masterRunId);
        $planJsonPath = $pipe . '/plan_' . $planIdSafe . '_' . $runIdSafe . '.json';

        $obj = build_plan_exec_summary($planJsonPath, []);

        $base = $pipe . '/plan_exec_summary_' . $planIdSafe . '_' . $runIdSafe;
        $jsonPath = $base . '.json';
        $mdPath = $base . '.md';
        $htmlPath = $base . '.html';

        ppl_write_json($jsonPath, $obj);
        @file_put_contents($mdPath, pes_render_md($obj));
        @file_put_contents($htmlPath, pes_render_html($obj));

        ppl_write_json($pipe . '/plan_exec_summary_last.json', $obj);
        @file_put_contents($pipe . '/plan_exec_summary_last.html', pes_render_html($obj));
        @file_put_contents($pipe . '/plan_exec_summary_last.md', pes_render_md($obj));

        return [
            'json' => ppl_mask($jsonPath),
            'md' => ppl_mask($mdPath),
            'html' => ppl_mask($htmlPath),
        ];
    }
}
