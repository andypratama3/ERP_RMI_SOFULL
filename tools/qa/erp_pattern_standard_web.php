<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/erp_pattern_standard_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/erp_pattern_standard_web.last.json';
$msg = '';
$msgType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $root = ts_root();
    $dirs = ['sales', 'purchases', 'stock', 'chat', 'hrl', 'payroll', 'absensi', 'Fixed_Asset', 'tools/qa'];
    $rows = [];
    $ok = 0;
    $warn = 0;
    $fail = 0;
    foreach ($dirs as $relDir) {
        $absDir = $root . '/' . $relDir;
        if (!is_dir($absDir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            if (strtolower($f->getExtension()) !== 'php') continue;
            $path = str_replace($root . '/', '', str_replace('\\', '/', $f->getPathname()));
            $src = (string)@file_get_contents($f->getPathname());
            if ($src === '') continue;
            $hasStrict = str_contains($src, 'declare(strict_types=1);');
            $usesPost = str_contains($src, '$_POST');
            $hasCsrf = (preg_match('/verify_csrf|csrf_token|rmi_csrf_validate|rmi_csrf_verify/i', $src) === 1);
            if (!$hasStrict) {
                $rows[] = ['file' => $path, 'status' => 'WARN', 'reason' => 'missing_strict_types'];
                $warn++;
                continue;
            }
            if ($usesPost && !$hasCsrf) {
                $rows[] = ['file' => $path, 'status' => 'FAIL', 'reason' => 'post_without_csrf_pattern'];
                $fail++;
                continue;
            }
            $ok++;
        }
    }

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'overall_ok' => ($fail === 0),
        'summary' => ['ok' => $ok, 'warn' => $warn, 'fail' => $fail],
        'rows' => $rows,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('erp_pattern_standard_web_run', ($fail === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/erp_pattern_standard_web.php',
        'ok' => $ok, 'warn' => $warn, 'fail' => $fail,
    ]);
    $msg = ($fail === 0) ? 'ERP Pattern Standard selesai: PASS/WARN.' : 'ERP Pattern Standard selesai: FAIL.';
    $msgType = ($fail === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'summary', 'rows']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$summary = is_array($data['summary'] ?? null) ? (array)$data['summary'] : [];
$rows = is_array($data['rows'] ?? null) ? (array)$data['rows'] : [];

$baseProject = rmi_layout_base_project();
rmi_header('ERP Pattern Standard (Web)', [
    'active' => 'tools',
    'subtitle' => 'Validasi pattern coding standar ERP (strict types + CSRF pattern).',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'ERP Pattern Standard'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <button class="btn btn-rmi btn-sm" type="submit">Run Pattern Standard</button>
      </form>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Summary</div>
      <?php if ($state['ok']): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">OK: <b><?= (int)($summary['ok'] ?? 0) ?></b> · WARN: <b><?= (int)($summary['warn'] ?? 0) ?></b> · FAIL: <b><?= (int)($summary['fail'] ?? 0) ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada run valid.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Findings</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h($rows ? json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'Belum ada finding.') ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
