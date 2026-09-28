<?php
declare(strict_types=1);

require_once __DIR__ . '/pipeline_lib.php';

if (!function_exists('apfa_build')) {
    /**
     * Build Architecture panel from arch_audit JSON for plan summary.
     * Minimal normalizer: reads arch_audit_<RUN_ID>.json, returns 5-row domain panel.
     */
    function apfa_build(string $runId): array
    {
        $pipe = ppl_pipeline_dir();
        $pathByRunId = $pipe . '/arch_audit_' . $runId . '.json';
        $domainLabels = [
            'broker' => 'Message Broker / Event Streaming',
            'data_gov' => 'Data Governance & Quality',
            'iam' => 'IAM (Auth/RBAC/MFA/SSO)',
            'monitoring' => 'Monitoring & Alerting',
            'backup_dr' => 'Backup & Disaster Recovery',
        ];
        $base = [
            'status' => 'DATA_MISSING',
            'arch_audit_run_id' => null,
            'domains' => [],
            'commands' => ['php tools/qa/arch_audit.php --run-id=' . $runId . ' --scope=repo --write-last'],
            'data_missing' => [],
        ];
        if (!is_file($pathByRunId)) {
            $base['data_missing'][] = ['reason' => 'MISSING'];
            return $base;
        }
        $r = ppl_read_json_strict($pathByRunId);
        if (!$r['ok']) {
            $base['data_missing'][] = ['reason' => 'INVALID_JSON'];
            return $base;
        }
        $data = (array)$r['data'];
        $fileRunId = trim((string)($data['run_id'] ?? ''));
        if ($fileRunId !== $runId) {
            $base['data_missing'][] = ['reason' => 'RUN_ID_MISMATCH'];
            return $base;
        }
        $base['arch_audit_run_id'] = $fileRunId;
        $domainsRaw = (array)($data['domains'] ?? []);
        foreach ($domainLabels as $key => $label) {
            $d = (array)($domainsRaw[$key] ?? []);
            $st = strtoupper(trim((string)($d['status'] ?? 'UNKNOWN')));
            $conf = isset($d['confidence']) ? (int)$d['confidence'] : 0;
            $base['domains'][] = [
                'name' => $label,
                'status' => $st,
                'confidence' => min(100, max(0, $conf)),
            ];
        }
        $base['status'] = apfa_compute_status($base['domains']);
        return $base;
    }
}

if (!function_exists('apfa_compute_status')) {
    function apfa_compute_status(array $domains): string
    {
        $backupSt = '';
        $monSt = '';
        foreach ($domains as $d) {
            $name = (string)($d['name'] ?? '');
            $st = strtoupper(trim((string)($d['status'] ?? '')));
            if (str_contains($name, 'Backup')) $backupSt = $st;
            if (str_contains($name, 'Monitoring')) $monSt = $st;
        }
        if ($backupSt === 'NOT_FOUND') return 'CRITICAL';
        if ($monSt === 'NOT_FOUND') return 'CRITICAL';
        if ($backupSt === 'PARTIAL' || $monSt === 'PARTIAL') return 'ATTENTION';
        return 'OK';
    }
}
