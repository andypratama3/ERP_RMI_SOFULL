<?php
declare(strict_types=1);

require_once __DIR__ . '/_lib/ops_helpers.php';
require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("Method Not Allowed\n");
}

$root = ops_root();
$findings = [];
$argv = $_SERVER['argv'] ?? [];
$scope = 'ops';
$baselineReset = false;
$baselineDryRun = false;
foreach ($argv as $a) {
    if (strpos((string)$a, '--scope=') === 0) {
        $scope = strtolower(trim(substr((string)$a, 8)));
    }
    if ((string)$a === '--baseline-reset') {
        $baselineReset = true;
    }
    if ((string)$a === '--baseline-dry-run') {
        $baselineDryRun = true;
    }
}
$scanRoots = $scope === 'full'
    ? [$root]
    : [
        $root . '/tools',
        $root . '/rbac',
    ];
$nowIso = date(DateTimeInterface::ATOM);

$isCliOnly = static function (string $code): bool {
    return (bool)preg_match("/PHP_SAPI\\s*!==\\s*'cli'/", $code) && (bool)preg_match('/Method Not Allowed/', $code);
};
$hasPostGate = static function (string $code): bool {
    return (bool)(
        preg_match('/REQUEST_METHOD[^\\n\\r]{0,120}POST/i', $code) ||
        preg_match('/\\$_SERVER\\s*\\[\\s*[\'"]REQUEST_METHOD[\'"]\\s*\\]\\s*===\\s*[\'"]POST[\'"]/i', $code) ||
        preg_match('/strtolower\\s*\\(\\s*\\$_SERVER\\s*\\[\\s*[\'"]REQUEST_METHOD[\'"]\\s*\\]\\s*\\)\\s*===\\s*[\'"]post[\'"]/i', $code) ||
        preg_match('/\\brequire_post\\s*\\(/', $code) ||
        preg_match('/\\bif\\s*\\(\\s*\\$_POST\\b/', $code)
    );
};
$hasMutationHint = static function (string $code): bool {
    return (bool)(
        preg_match('/\\b(INSERT\\s+INTO|UPDATE\\s+\\w+|DELETE\\s+FROM|REPLACE\\s+INTO)\\b/i', $code) ||
        preg_match('/\\b(move_uploaded_file|unlink|rename|mkdir|rmdir|exec|system|shell_exec|file_put_contents)\\s*\\(/i', $code)
    );
};
$hasCsrf = static function (string $code): bool {
    // Allow variant naming used across legacy modules.
    if (preg_match('/\\b(verify_csrf|rmi_csrf_validate|rmi_csrf_verify|csrf_verify|csrf_validate|csrf_check|csrf_verify_or_die|check_csrf|internal_api_require_write_guard)\\s*\\(/i', $code)) {
        return true;
    }
    return (bool)preg_match('/\\bcsrf\\w*\\b/i', $code) && (bool)preg_match('/\\b(verify|validate|check)\\w*\\b/i', $code);
};
$isToolsWebEntrypoint = static function (string $path, string $code, bool $fullMode): bool {
    $p = str_replace('\\', '/', $path);
    if (!$fullMode) {
        if (!str_contains($p, '/tools/')) return false;
        if (str_contains($p, '/tools/qa/') || str_contains($p, '/tools/dev/') || str_contains($p, '/tools/_lib/') || str_contains($p, '/tools/ops/_lib/')) return false;
        if (preg_match('/(helpers|matrix|_lib|_state)\\.php$/i', $p)) return false;
    }
    if ((bool)preg_match("/PHP_SAPI\\s*!==\\s*'cli'/", $code) && (bool)preg_match('/Method Not Allowed/', $code)) return false;
    $hasWebSignals = stripos($code, 'rmi_header(') !== false
        || stripos($code, 'require_login(') !== false
        || stripos($code, 'tools_require_access(') !== false
        || stripos($code, '$_SERVER[\'REMOTE_ADDR\']') !== false;
    if (!$hasWebSignals) return false;
    return true;
};

foreach ($scanRoots as $scanRoot) {
    if (!is_dir($scanRoot)) continue;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) continue;
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php')) continue;
        if (str_contains($path, '/vendor/') || str_contains($path, '/storage/') || str_contains($path, '/docs/') || str_contains($path, '/node_modules/')) continue;
        $raw = (string)@file_get_contents($path);
        if ($raw === '') continue;
        $postGate = $hasPostGate($raw);
        $mutHint = $hasMutationHint($raw);
        if ($postGate && $mutHint) {
            if (!$hasCsrf($raw)) {
                $findings[] = [
                    'severity' => 'HIGH',
                    'type' => 'csrf_missing',
                    'file_masked' => ops_mask($path),
                    'detail' => 'POST mutating endpoint detected without explicit CSRF validation',
                ];
            }
        }
        $isToolsOrRbacPath = str_contains(str_replace('\\', '/', $path), '/tools/')
            || str_contains(str_replace('\\', '/', $path), '/rbac/');
        if ($isToolsOrRbacPath && $isToolsWebEntrypoint($path, $raw, false) && !$isCliOnly($raw)) {
            $hasAdminGuard = stripos($raw, 'require_admin_critical(') !== false
                || (stripos($raw, 'require_login(') !== false && preg_match("/require_role\\s*\\(\\s*\\[\\s*'ADMIN'\\s*,\\s*'SUPERADMIN'\\s*\\]\\s*\\)/", $raw))
                || stripos($raw, 'tools_require_access(') !== false
                || stripos($raw, 'rbac_is_privileged_session(') !== false
                || stripos($raw, 'require_any_permission(') !== false
                || (stripos($raw, 'SUPERADMIN') !== false && stripos($raw, 'ADMIN') !== false && stripos($raw, 'http_response_code(403)') !== false);
            if (!$hasAdminGuard) {
                $findings[] = [
                    'severity' => 'MEDIUM',
                    'type' => 'admin_guard_missing',
                    'file_masked' => ops_mask($path),
                    'detail' => 'Tools web endpoint missing strict admin guard',
                ];
            }
        }
    }
}

$findingSignature = static function (array $f): string {
    return strtoupper((string)($f['severity'] ?? '')) . '|' . (string)($f['type'] ?? '') . '|' . (string)($f['file_masked'] ?? '');
};
usort($findings, static function (array $a, array $b): int {
    $x = strtoupper((string)($a['severity'] ?? '')) . '|' . (string)($a['type'] ?? '') . '|' . (string)($a['file_masked'] ?? '');
    $y = strtoupper((string)($b['severity'] ?? '')) . '|' . (string)($b['type'] ?? '') . '|' . (string)($b['file_masked'] ?? '');
    return strcmp($x, $y);
});

$delta = null;
$baselinePath = ops_state_path('ops_hardening_full_baseline.json');
$baselineWritten = false;
if ($scope === 'full') {
    $prev = ops_read_json_safe($baselinePath);
    $prevMap = [];
    foreach ((array)($prev['data']['findings'] ?? []) as $f) {
        if (!is_array($f)) continue;
        $prevMap[$findingSignature($f)] = $f;
    }
    $currMap = [];
    foreach ($findings as $f) {
        $currMap[$findingSignature($f)] = $f;
    }
    if ($baselineReset) {
        // Reset means "use current as fresh baseline", so delta becomes zero.
        $prevMap = $currMap;
    }
    $newFindings = [];
    foreach ($currMap as $sig => $f) {
        if (!isset($prevMap[$sig])) $newFindings[] = $f;
    }
    $resolvedFindings = [];
    foreach ($prevMap as $sig => $f) {
        if (!isset($currMap[$sig])) $resolvedFindings[] = $f;
    }
    usort($newFindings, static fn(array $a, array $b): int => strcmp((string)($a['file_masked'] ?? ''), (string)($b['file_masked'] ?? '')));
    usort($resolvedFindings, static fn(array $a, array $b): int => strcmp((string)($a['file_masked'] ?? ''), (string)($b['file_masked'] ?? '')));
    $delta = [
        'state_version' => 1,
        'ts' => $nowIso,
        'scope' => 'full',
        'total_findings' => count($findings),
        'new_count' => count($newFindings),
        'resolved_count' => count($resolvedFindings),
        'open_count' => count($findings),
        'new_findings' => $newFindings,
        'resolved_findings' => $resolvedFindings,
    ];
    if (!$baselineDryRun) {
        ops_write_json($baselinePath, [
            'state_version' => 1,
            'ts' => $nowIso,
            'scope' => 'full',
            'findings' => $findings,
            'summary' => [
                'finding_count' => count($findings),
                'new_count' => (int)$delta['new_count'],
                'resolved_count' => (int)$delta['resolved_count'],
            ],
        ]);
        $baselineWritten = true;
    }
}

$matrix = [
    ['action' => 'backup run', 'audit_event' => 'BACKUP_MANUAL_TRIGGER / backup_now_run', 'actor' => 'admin user', 'meta_fields' => 'actor_username, source'],
    ['action' => 'restore run', 'audit_event' => 'RESTORE_NOW / restore_now_run', 'actor' => 'admin user', 'meta_fields' => 'package, source'],
    ['action' => 'retention cleanup', 'audit_event' => 'BACKUP_RETENTION_CLEANUP / backup_retention_cleanup', 'actor' => 'admin user', 'meta_fields' => 'deleted_count, source'],
    ['action' => 'readiness generate', 'audit_event' => 'readiness_generate', 'actor' => 'admin user/system', 'meta_fields' => 'source, score'],
    ['action' => 'smoke schedule run', 'audit_event' => 'smoke_schedule_*', 'actor' => 'admin user', 'meta_fields' => 'enabled, cron'],
    ['action' => 'alert evaluation run', 'audit_event' => 'OPS_ALERT_EVALUATED / ops_alert_evaluation_run', 'actor' => 'SYSTEM', 'meta_fields' => 'active_counts'],
    ['action' => 'findings status change', 'audit_event' => 'OPS_FINDING_STATUS_CHANGED / ops_finding_status_changed', 'actor' => 'admin user', 'meta_fields' => 'finding_id, to_status, note_masked'],
];

$md = [];
$md[] = '# OPS Hardening Report';
$md[] = '';
$md[] = 'Generated at: ' . $nowIso;
if ($scope === 'full') {
    $md[] = 'Scan scope: `[APP_ROOT]` (full observability mode)';
} else {
    $md[] = 'Scan scope: `[APP_ROOT]/tools` and `[APP_ROOT]/rbac` (phase-2 minimal-change scope)';
}
$md[] = 'Flags: `--baseline-reset=' . ($baselineReset ? '1' : '0') . '`, `--baseline-dry-run=' . ($baselineDryRun ? '1' : '0') . '`';
$md[] = '';
$md[] = '## Mutation Endpoint Scan Findings';
$md[] = '';
$md[] = '| Severity | Type | File | Detail |';
$md[] = '|---|---|---|---|';
if (!$findings) {
    $md[] = '| LOW | none | - | No high-confidence finding from static scan |';
} else {
    foreach ($findings as $f) {
        $md[] = '| ' . $f['severity'] . ' | ' . $f['type'] . ' | `' . $f['file_masked'] . '` | ' . $f['detail'] . ' |';
    }
}
$md[] = '';
$md[] = '## Delta vs Previous Baseline';
$md[] = '';
if ($scope === 'full' && is_array($delta)) {
    $md[] = '- Current open findings: **' . (int)$delta['open_count'] . '**';
    $md[] = '- New findings: **' . (int)$delta['new_count'] . '**';
    $md[] = '- Resolved findings: **' . (int)$delta['resolved_count'] . '**';
    $md[] = '- OPS_HARDENING Snapshot: `[APP_ROOT]/storage/logs/ops_hardening_full_baseline.json` (docs/BASELINES.md)';
    $md[] = '- Snapshot write: **' . ($baselineWritten ? 'yes' : 'no') . '**';
} else {
    $md[] = '- Delta vs Hardening Snapshot hanya tersedia di `--scope=full` mode.';
}
$md[] = '';
$md[] = '## Audit Event Consistency Matrix';
$md[] = '';
$md[] = '| Action | Audit Event | Actor | Meta Fields |';
$md[] = '|---|---|---|---|';
foreach ($matrix as $m) {
    $md[] = '| ' . $m['action'] . ' | `' . $m['audit_event'] . '` | ' . $m['actor'] . ' | ' . $m['meta_fields'] . ' |';
}
$md[] = '';
$md[] = '## Applied Patches';
$md[] = '';
$md[] = '- Ops pack adds strict guards for `tools/ops/findings.php` and `tools/ops/executive_ops_summary.php`.';
$md[] = '- All mutating web actions in ops findings require POST + CSRF validation.';
$md[] = '- Alert/finding workflows append to `tools_run_history.jsonl` and ERP audit.';
$md[] = '';

$reportPath = $scope === 'full'
    ? $root . '/docs/governance/OPS_HARDENING_REPORT_FULL.md'
    : $root . '/docs/governance/OPS_HARDENING_REPORT.md';
@file_put_contents($reportPath, implode(PHP_EOL, $md) . PHP_EOL);

ts_append_run_history('ops_mutation_hardening_audit', 'ok', [
    'at' => $nowIso,
    'finding_count' => count($findings),
    'scan_scope' => $scope,
    'delta_new' => (int)($delta['new_count'] ?? 0),
    'delta_resolved' => (int)($delta['resolved_count'] ?? 0),
    'baseline_reset' => $baselineReset,
    'baseline_dry_run' => $baselineDryRun,
    'baseline_written' => $baselineWritten,
]);
try {
    $pdo = rmi_db_pdo();
    erp_audit_ensure($pdo);
    erp_audit($pdo, 'OPS', 'HARDENING', 'OPS_HARDENING_AUDIT_RUN', [
        'finding_count' => count($findings),
        'scan_scope' => $scope,
        'delta_new' => (int)($delta['new_count'] ?? 0),
        'delta_resolved' => (int)($delta['resolved_count'] ?? 0),
        'baseline_reset' => $baselineReset ? 1 : 0,
        'baseline_dry_run' => $baselineDryRun ? 1 : 0,
        'baseline_written' => $baselineWritten ? 1 : 0,
    ]);
} catch (Throwable $e) {
}

echo json_encode([
    'ok' => true,
    'finding_count' => count($findings),
    'scan_scope' => $scope,
    'delta_new' => (int)($delta['new_count'] ?? 0),
    'delta_resolved' => (int)($delta['resolved_count'] ?? 0),
    'baseline_reset' => $baselineReset,
    'baseline_dry_run' => $baselineDryRun,
    'baseline_written' => $baselineWritten,
    'baseline' => $scope === 'full' ? '[APP_ROOT]/storage/logs/ops_hardening_full_baseline.json' : null,
    'report' => $scope === 'full'
        ? '[APP_ROOT]/docs/governance/OPS_HARDENING_REPORT_FULL.md'
        : '[APP_ROOT]/docs/governance/OPS_HARDENING_REPORT.md',
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
