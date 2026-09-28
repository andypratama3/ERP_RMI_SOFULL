<?php
/**
 * audit_live_web.php — Web viewer for Live Audit. Run Now via POST+CSRF.
 */
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/audit_live_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $v): string { return function_exists('rmi_h') ? rmi_h($v) : htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

$root = ts_root();
$baseProject = rmi_layout_base_project();
$statePath = ts_storage_logs_dir() . '/audit_live_last.json';
$msg = '';
$msgType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator unavailable');
    }

    $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/qa/audit_live.php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --env=production --write-last --strict 2>&1';
    $runOutput = [];
    @exec($cmd, $runOutput, $runExit);
    $runOutput = array_map(function_exists('tools_mask_sensitive') ? 'tools_mask_sensitive' : fn($x) => $x, $runOutput);

    $audit = is_file($statePath) ? json_decode((string)@file_get_contents($statePath), true) : [];
    $audit = is_array($audit) ? $audit : [];

    if (function_exists('ts_append_run_history')) {
        ts_append_run_history('audit_live_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
            'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
            'source' => 'tools/qa/audit_live_web.php',
            'exit_code' => (int)$runExit,
        ]);
    }

    if ((int)$runExit === 0) {
        $msg = 'Live audit selesai: PASS.';
        $msgType = 'success';
    } else {
        $msg = 'Live audit selesai: FAIL.';
        $msgType = 'warning';
    }
}

$audit = is_file($statePath) ? json_decode((string)@file_get_contents($statePath), true) : [];
$audit = is_array($audit) ? $audit : [];
$overallOk = (bool)($audit['overall_ok'] ?? false);
$criticalFail = (int)($audit['critical_fail_count'] ?? 0);
$warningCount = (int)($audit['warning_count'] ?? 0);
$checks = (array)($audit['checks'] ?? []);
$generatedAt = (string)($audit['generated_at'] ?? '');
$runId = (string)($audit['run_id'] ?? '');

$fails = array_filter($checks, fn($c) => ($c['status'] ?? '') === 'fail');

rmi_header('Live Audit', [
    'active' => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Live Audit',
    ],
]);
?>
<div class="container py-4">
    <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= h($msgType) ?> py-2"><?= h($msg) ?></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Live Audit</h3>
        <a class="btn btn-outline-secondary" href="<?= h($baseProject) ?>/tools/ops/audit_exec_summary.php">Exec Summary</a>
    </div>

    <form method="post" class="mb-3">
        <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
        <button class="btn btn-primary" type="submit">Run Now</button>
        <span class="small text-muted ms-2">Jalankan via SSH jika CLI capability tidak tersedia: <code>php tools/qa/audit_live.php --env=production --write-last --strict</code></span>
    </form>

    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-<?= $overallOk ? 'success' : 'danger' ?>"><?= $overallOk ? 'PASS' : 'FAIL' ?></span>
                <span>Critical: <?= $criticalFail ?></span>
                <span>Warnings: <?= $warningCount ?></span>
                <span class="small text-muted"><?= h($generatedAt) ?> | <?= h($runId) ?></span>
            </div>
            <?php if (!empty($fails)): ?>
                <div class="mt-2">
                    <strong>Critical Fails:</strong>
                    <ul class="mb-0">
                        <?php foreach ($fails as $c): ?>
                            <li><?= h($c['name'] ?? '') ?>: <?= h($c['detail_masked'] ?? '') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Artifacts</div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="<?= h($baseProject) ?>/tools/ops/audit_exec_summary.php">Exec Summary</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= h($baseProject) ?>/tools/qa/cutover_checks_web.php">Cutover Checks</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= h($baseProject) ?>/tools/qa/contract_check_web.php">Contract Check</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= h($baseProject) ?>/tools/qa/unicode_guard_web.php">Unicode Guard</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= h($baseProject) ?>/tools/qa/smoke_http_web.php">Smoke HTTP</a>
            </div>
        </div>
    </div>
</div>
<?php rmi_footer(); ?>
