<?php
declare(strict_types=1);

require_once __DIR__ . '/executive_summary_lib.php';

if (!function_exists('aap_load_arch_audit_for_run')) {
    /**
     * Load arch_audit data deterministic by run_id.
     * 1) Try arch_audit_<RUN_ID>.json - if exists and run_id matches => use
     * 2) Else try arch_audit_last.json - if run_id inside != current => DATA_MISSING RUN_ID_MISMATCH
     * 3) If none => DATA_MISSING
     */
    function aap_load_arch_audit_for_run(string $runId): array
    {
        $pipe = exs_pipeline_dir();
        $root = exs_root();
        $runId = trim($runId);
        if ($runId === '') {
            return ['ok' => false, 'reason' => 'EMPTY_RUN_ID', 'data' => [], 'path_used' => null];
        }
        $pathByRunId = $pipe . '/arch_audit_' . $runId . '.json';
        if (is_file($pathByRunId)) {
            $r = exs_read_json_strict($pathByRunId);
            if ($r['ok']) {
                $data = (array)$r['data'];
                $fileRunId = trim((string)($data['run_id'] ?? ''));
                if ($fileRunId === $runId) {
                    return [
                        'ok' => true,
                        'reason' => '',
                        'data' => $data,
                        'path_used' => $pathByRunId,
                        'age_s' => exs_age_seconds($pathByRunId),
                    ];
                }
            }
        }
        $pathLast = $pipe . '/arch_audit_last.json';
        if (is_file($pathLast)) {
            $r = exs_read_json_strict($pathLast);
            if (!$r['ok']) {
                return ['ok' => false, 'reason' => 'INVALID_JSON', 'data' => [], 'path_used' => $pathLast];
            }
            $data = (array)$r['data'];
            $fileRunId = trim((string)($data['run_id'] ?? ''));
            if ($fileRunId !== $runId) {
                return ['ok' => false, 'reason' => 'RUN_ID_MISMATCH', 'data' => [], 'path_used' => $pathLast];
            }
            return [
                'ok' => true,
                'reason' => '',
                'data' => $data,
                'path_used' => $pathLast,
                'age_s' => exs_age_seconds($pathLast),
            ];
        }
        return ['ok' => false, 'reason' => 'MISSING', 'data' => [], 'path_used' => null];
    }
}

if (!function_exists('aap_domain_note')) {
    function aap_domain_note(string $domainKey, string $status): string
    {
        $notes = [
            'broker' => [
                'PRESENT' => 'Ada kurir pesan internal untuk event lintas layanan.',
                'PARTIAL' => 'Ada indikasi antrian/worker, broker penuh belum terdeteksi.',
                'NOT_FOUND' => 'Sistem belum punya kurir pesan untuk event lintas layanan.',
                'UNKNOWN' => 'Tidak bisa dipastikan (scan terbatas).',
            ],
            'data_gov' => [
                'PRESENT' => 'Governance perubahan dan kualitas data terdeteksi.',
                'PARTIAL' => 'Ada governance perubahan, tapi rule kualitas data belum lengkap.',
                'NOT_FOUND' => 'Belum terdeteksi governance data atau kualitas.',
                'UNKNOWN' => 'Tidak bisa dipastikan (scan terbatas).',
            ],
            'iam' => [
                'PRESENT' => 'Login dan role/permission terdeteksi.',
                'PARTIAL' => 'Ada login/guard, RBAC belum lengkap.',
                'NOT_FOUND' => 'Belum terdeteksi RBAC atau permission engine.',
                'UNKNOWN' => 'Tidak bisa dipastikan (scan terbatas).',
            ],
            'monitoring' => [
                'PRESENT' => 'Health check dan monitoring terdeteksi.',
                'PARTIAL' => 'Ada health/smoke internal, observability eksternal belum terdeteksi.',
                'NOT_FOUND' => 'Belum terdeteksi monitoring atau health check.',
                'UNKNOWN' => 'Tidak bisa dipastikan (scan terbatas).',
            ],
            'backup_dr' => [
                'PRESENT' => 'Backup dan restore tool terdeteksi.',
                'PARTIAL' => 'Ada indikasi backup, verifikasi belum lengkap.',
                'NOT_FOUND' => 'Belum terdeteksi tool backup atau restore.',
                'UNKNOWN' => 'Tidak bisa dipastikan (scan terbatas).',
            ],
        ];
        $st = strtoupper(trim($status));
        return (string)($notes[$domainKey][$st] ?? '');
    }
}

if (!function_exists('aap_build')) {
    /**
     * Build normalized Architecture panel for Executive Summary.
     */
    function aap_build(string $runId, string $env): array
    {
        $load = aap_load_arch_audit_for_run($runId);
        $domainLabels = [
            'broker' => 'Message Broker / Event Streaming',
            'data_gov' => 'Data Governance & Quality',
            'iam' => 'IAM (Auth/RBAC/MFA/SSO)',
            'monitoring' => 'Monitoring & Alerting',
            'backup_dr' => 'Backup & Disaster Recovery',
        ];
        $base = [
            'state_version' => 1,
            'status' => 'DATA_MISSING',
            'arch_audit_run_id' => null,
            'arch_audit_age_hours' => null,
            'domains' => [],
            'recommendations' => [],
            'commands' => [
                'php tools/qa/arch_audit.php --run-id=' . $runId . ' --write-last',
                'cat storage/logs/pipeline/arch_audit_last_one_pager.md',
            ],
            'data_missing' => [],
        ];
        if (!$load['ok']) {
            $base['data_missing'][] = ['reason' => $load['reason']];
            $base['commands'][0] = 'php tools/qa/arch_audit.php --run-id=' . $runId . ' --write-last';
            return $base;
        }
        $data = (array)$load['data'];
        $domainsRaw = (array)($data['domains'] ?? []);
        $ageS = (float)($load['age_s'] ?? 0);
        $ageHours = $ageS > 0 ? round($ageS / 3600, 1) : null;
        $base['arch_audit_run_id'] = trim((string)($data['run_id'] ?? ''));
        $base['arch_audit_age_hours'] = $ageHours;
        $recommendations = [];
        foreach ($domainLabels as $key => $label) {
            $d = (array)($domainsRaw[$key] ?? []);
            $st = strtoupper(trim((string)($d['status'] ?? 'UNKNOWN')));
            $conf = isset($d['confidence']) ? (int)$d['confidence'] : 0;
            $note = aap_domain_note($key, $st);
            if ($note === '') $note = 'Status: ' . $st;
            $base['domains'][] = [
                'name' => $label,
                'status' => $st,
                'confidence' => min(100, max(0, $conf)),
                'note' => $note,
            ];
            foreach (array_slice((array)($d['recommendations'] ?? []), 0, 1) as $rec) {
                if (trim((string)$rec) !== '') $recommendations[] = trim((string)$rec);
            }
        }
        $base['recommendations'] = array_values(array_unique(array_slice($recommendations, 0, 3)));
        if ($base['recommendations'] === [] && $base['domains'] !== []) {
            $backupSt = '';
            $monSt = '';
            foreach ($base['domains'] as $dom) {
                if (str_contains($dom['name'], 'Backup')) $backupSt = $dom['status'];
                if (str_contains($dom['name'], 'Monitoring')) $monSt = $dom['status'];
            }
            if ($backupSt === 'NOT_FOUND') $base['recommendations'][] = 'Tambahkan tool backup dan retention policy.';
            if ($monSt === 'NOT_FOUND') $base['recommendations'][] = 'Tambahkan health endpoint dan smoke test.';
        }
        $base['status'] = aap_compute_panel_status($base['domains'], $ageHours);
        return $base;
    }
}

if (!function_exists('aap_compute_panel_status')) {
    /**
     * Compute panel status: OK | ATTENTION | CRITICAL | DATA_MISSING
     */
    function aap_compute_panel_status(array $domains, ?float $ageHours): string
    {
        $backupSt = '';
        $monSt = '';
        $iamSt = '';
        foreach ($domains as $d) {
            $name = (string)($d['name'] ?? '');
            $st = strtoupper(trim((string)($d['status'] ?? '')));
            if (str_contains($name, 'Backup')) $backupSt = $st;
            if (str_contains($name, 'Monitoring')) $monSt = $st;
            if (str_contains($name, 'IAM')) $iamSt = $st;
        }
        if ($backupSt === 'NOT_FOUND') return 'CRITICAL';
        if ($monSt === 'NOT_FOUND') return 'CRITICAL';
        if ($backupSt === 'PARTIAL' || $monSt === 'PARTIAL') return 'ATTENTION';
        if (in_array($iamSt, ['PARTIAL', 'NOT_FOUND'], true)) return 'ATTENTION';
        if ($ageHours !== null && $ageHours > 168) return 'ATTENTION';
        return 'OK';
    }
}
