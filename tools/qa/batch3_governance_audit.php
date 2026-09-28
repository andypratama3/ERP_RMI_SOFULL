<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_state_lib.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

function b3_read(string $path): string
{
    $raw = @file_get_contents($path);
    return is_string($raw) ? $raw : '';
}

function b3_scan_group(string $root, array $files, string $group): array
{
    $rows = [];
    foreach ($files as $rel) {
        $abs = $root . '/' . $rel;
        if (!is_file($abs)) {
            $rows[] = ['file' => $rel, 'status' => 'WARN', 'reason' => 'missing_file'];
            continue;
        }
        $src = b3_read($abs);
        $hasPost = str_contains($src, '$_POST');
        $hasCsrf = preg_match('/verify_csrf|rmi_csrf_verify|csrf_check_or_die|fa_csrf_verify/', $src) === 1;
        $hasAuth = preg_match('/require_login|auth_require_login|hrl_require_manage|rbac_require/', $src) === 1;
        $hasAudit = preg_match('/fa_log|rmi_audit_safe|erp_audit|hrl_audit_append|p_audit/', $src) === 1;
        if ($hasPost && (!$hasCsrf || !$hasAuth)) {
            $rows[] = ['file' => $rel, 'status' => 'FAIL', 'reason' => 'missing_csrf_or_auth'];
            continue;
        }
        if ($hasPost && !$hasAudit) {
            $rows[] = ['file' => $rel, 'status' => 'WARN', 'reason' => 'audit_not_detected'];
            continue;
        }
        $rows[] = ['file' => $rel, 'status' => 'OK', 'reason' => 'baseline_ok'];
    }
    return ['group' => $group, 'rows' => $rows];
}

function b3_scan_api(string $root, array $files): array
{
    $rows = [];
    foreach ($files as $rel) {
        $abs = $root . '/' . $rel;
        if (!is_file($abs)) continue;
        $src = b3_read($abs);
        if (!str_contains($src, 'json_encode')) continue;
        $hasOk = str_contains($src, "'ok'") || str_contains($src, '"ok"');
        $hasRequest = str_contains($src, 'request_id');
        if (!$hasOk || !$hasRequest) {
            $rows[] = ['file' => $rel, 'status' => 'WARN', 'reason' => 'envelope_incomplete'];
        } else {
            $rows[] = ['file' => $rel, 'status' => 'OK', 'reason' => 'envelope_detected'];
        }
    }
    return ['group' => 'api', 'rows' => $rows];
}

$root = dirname(__DIR__, 2);
$hrFiles = [
    'hrl/hrl_doc_view.php',
    'hrl/hrl_docs.php',
    'hrl/hrl_tower.php',
];
$faFiles = [
    'Fixed_Asset/ops.php',
    'Fixed_Asset/assets.php',
    'Fixed_Asset/depreciation.php',
];
$apiFiles = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/api', FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
    RecursiveIteratorIterator::SELF_FIRST,
    RecursiveIteratorIterator::CATCH_GET_CHILD
);
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    if (strtolower($f->getExtension()) !== 'php') continue;
    $p = str_replace('\\', '/', $f->getPathname());
    $apiFiles[] = ltrim(str_replace(str_replace('\\', '/', $root) . '/', '', $p), '/');
}
$apiFiles = array_values(array_unique($apiFiles));

$groups = [];
$groups[] = b3_scan_group($root, $hrFiles, 'hrl');
$groups[] = b3_scan_group($root, $faFiles, 'fixed_asset');
$groups[] = b3_scan_api($root, $apiFiles);

$ok = 0; $warn = 0; $fail = 0;
foreach ($groups as $g) {
    foreach ($g['rows'] as $r) {
        if ($r['status'] === 'OK') $ok++;
        if ($r['status'] === 'WARN') $warn++;
        if ($r['status'] === 'FAIL') $fail++;
    }
}

$payload = [
    'state_version' => 1,
    'checked_at' => date(DateTimeInterface::ATOM),
    'summary' => ['ok' => $ok, 'warn' => $warn, 'fail' => $fail],
    'groups' => $groups,
];
@file_put_contents($root . '/storage/logs/batch3_governance_audit_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$md = [];
$md[] = '# Batch 3 Governance Audit';
$md[] = '';
$md[] = '- Checked at: ' . $payload['checked_at'];
$md[] = '- OK: ' . $ok . ' | WARN: ' . $warn . ' | FAIL: ' . $fail;
$md[] = '';
foreach ($groups as $g) {
    $md[] = '## ' . strtoupper((string)$g['group']);
    foreach ($g['rows'] as $r) {
        $md[] = '- [' . $r['status'] . '] `' . $r['file'] . '` => ' . $r['reason'];
    }
    $md[] = '';
}
@file_put_contents($root . '/storage/logs/batch3_governance_audit_last.md', implode("\n", $md) . "\n");

ts_append_run_history('batch3_governance_audit', $fail > 0 ? 'WARN' : 'OK', [
    'module' => 'tools.qa',
    'action' => 'batch3_governance_audit',
    'result' => $fail > 0 ? 'WARN' : 'OK',
    'warn_count' => $warn,
    'fail_count' => $fail,
]);

echo json_encode(['ok' => true, 'summary' => $payload['summary']], JSON_UNESCAPED_SLASHES) . PHP_EOL;
