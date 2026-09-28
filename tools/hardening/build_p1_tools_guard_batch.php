<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';

$state = tools_json_read_safe(APP_ROOT . '/storage/logs/erp_hardening_triage_web.last.json');
$files = [];
foreach ((array)($state['findings'] ?? []) as $finding) {
    if (strtoupper((string)($finding['severity'] ?? '')) !== 'P1') {
        continue;
    }
    if (!str_contains(strtolower((string)($finding['title'] ?? '')), 'require_login')) {
        continue;
    }
    foreach ((array)($finding['files'] ?? []) as $file) {
        $masked = (string)($file['path_masked'] ?? '');
        $rel = ltrim(str_replace('[APP_ROOT]/', '', $masked), '/');
        if (!str_starts_with($rel, 'tools/')) continue;
        if ($rel === 'tools/index.php') continue;
        if (preg_match('#^(tools/(sales|purchases|stock|fin|act|scm|wqs)/|api/)#i', $rel) === 1) continue;
        $files[$rel] = $rel;
    }
}
ksort($files);
$out = [
    'files' => array_values($files),
    'count' => count($files),
    'generated_at' => date(DateTimeInterface::ATOM),
];
tools_json_write_atomic(APP_ROOT . '/storage/logs/P1_TOOLS_GUARD_BATCH_v1.json', $out);
echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
