<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_state_lib.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

function pca_read_json(string $path): array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function pca_status_from_gate(array $gate): string
{
    return strtoupper((string)($gate['gate'] ?? 'UNKNOWN'));
}

$root = dirname(__DIR__, 2);
$logs = $root . '/storage/logs';

$smoke = pca_read_json($logs . '/smoke_http_last.json');
$gate = pca_read_json($logs . '/release_gate_last.json');
$sop = pca_read_json($logs . '/sop_link_verify_last.json');
$evidence = pca_read_json($logs . '/evidence_status_last.json');
$rbac = pca_read_json($logs . '/rbac_effective_permissions.last.json');
$b3 = pca_read_json($logs . '/batch3_governance_audit_last.json');

$closeout = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'program' => 'ERP_RMI_SOFULL Ops & Governance Batch 1-3',
    'summary' => [
        'smoke' => [
            'status' => ((int)($smoke['fail'] ?? 1) === 0) ? 'PASS' : 'FAIL',
            'total' => (int)($smoke['total'] ?? 0),
            'pass' => (int)($smoke['pass'] ?? 0),
            'fail' => (int)($smoke['fail'] ?? 0),
        ],
        'release_gate' => [
            'status' => pca_status_from_gate($gate),
            'lint_fail_count' => (int)($gate['lint_fail_count'] ?? 0),
        ],
        'sop_verify' => [
            'ok' => (int)($sop['summary']['ok'] ?? 0),
            'warn' => (int)($sop['summary']['warn'] ?? 0),
            'fail' => (int)($sop['summary']['fail'] ?? 0),
        ],
        'evidence_pack' => [
            'OK_packs' => (int)($evidence['OK_packs'] ?? 0),
            'WARN_packs' => (int)($evidence['WARN_packs'] ?? 0),
            'FAIL_packs' => (int)($evidence['FAIL_packs'] ?? 0),
            'latest_pack' => (string)($evidence['latest_pack'] ?? ''),
        ],
        'rbac_effective' => [
            'dept_role_rows' => count((array)($rbac['by_dept_role'] ?? [])),
            'top_permissions' => count((array)($rbac['top_permissions'] ?? [])),
        ],
        'batch3_governance' => [
            'ok' => (int)($b3['summary']['ok'] ?? 0),
            'warn' => (int)($b3['summary']['warn'] ?? 0),
            'fail' => (int)($b3['summary']['fail'] ?? 0),
        ],
    ],
    'evidence_paths_masked' => [
        '[APP_ROOT]/storage/logs/smoke_http_last.json',
        '[APP_ROOT]/storage/logs/release_gate_last.json',
        '[APP_ROOT]/storage/logs/sop_link_verify_last.json',
        '[APP_ROOT]/storage/logs/evidence_status_last.json',
        '[APP_ROOT]/storage/logs/rbac_effective_permissions.last.json',
        '[APP_ROOT]/storage/logs/batch3_governance_audit_last.json',
        '[APP_ROOT]/storage/logs/tools_run_history.jsonl',
    ],
];

$jsonOut = $logs . '/program_closeout_audit_last.json';
@file_put_contents($jsonOut, json_encode($closeout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$md = [];
$md[] = '# Program Closeout Audit';
$md[] = '';
$md[] = '- Generated at: ' . $closeout['generated_at'];
$md[] = '- Program: ' . $closeout['program'];
$md[] = '';
$md[] = '## Summary';
$md[] = '- Smoke: ' . $closeout['summary']['smoke']['status'] . ' (total=' . $closeout['summary']['smoke']['total'] . ', pass=' . $closeout['summary']['smoke']['pass'] . ', fail=' . $closeout['summary']['smoke']['fail'] . ')';
$md[] = '- Release Gate: ' . $closeout['summary']['release_gate']['status'] . ' (lint_fail=' . $closeout['summary']['release_gate']['lint_fail_count'] . ')';
$md[] = '- SOP Verify: ok=' . $closeout['summary']['sop_verify']['ok'] . ', warn=' . $closeout['summary']['sop_verify']['warn'] . ', fail=' . $closeout['summary']['sop_verify']['fail'];
$md[] = '- Evidence Pack: OK=' . $closeout['summary']['evidence_pack']['OK_packs'] . ', WARN=' . $closeout['summary']['evidence_pack']['WARN_packs'] . ', FAIL=' . $closeout['summary']['evidence_pack']['FAIL_packs'];
$md[] = '- RBAC Effective Rows: ' . $closeout['summary']['rbac_effective']['dept_role_rows'];
$md[] = '- Batch3 Governance: ok=' . $closeout['summary']['batch3_governance']['ok'] . ', warn=' . $closeout['summary']['batch3_governance']['warn'] . ', fail=' . $closeout['summary']['batch3_governance']['fail'];
$md[] = '';
$md[] = '## Evidence Paths';
foreach ($closeout['evidence_paths_masked'] as $p) {
    $md[] = '- `' . $p . '`';
}
@file_put_contents($logs . '/program_closeout_audit_last.md', implode("\n", $md) . "\n");

ts_append_run_history('program_closeout_audit', 'OK', [
    'module' => 'tools.qa',
    'action' => 'program_closeout_audit',
    'result' => 'OK',
    'request_id' => 'req-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 10),
]);

echo json_encode(['ok' => true, 'path' => '[APP_ROOT]/storage/logs/program_closeout_audit_last.json'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
