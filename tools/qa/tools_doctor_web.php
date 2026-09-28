<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/tools_doctor_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$statePath = ts_storage_logs_dir() . '/tools_doctor_web.last.json';
$msg = '';
$msgType = 'info';
$runOutput = [];
$runExit = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator unavailable');
    }

    $root = ts_root();
    $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
    $script = $root . '/tools/qa/tools_doctor.php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --write-last';
    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $doctor = ts_read_json(ts_storage_logs_dir() . '/tools_doctor_last.json');
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'doctor' => $doctor,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    $msg = ((int)$runExit === 0) ? 'Tools Doctor selesai: OK.' : 'Tools Doctor selesai: ada findings.';
    $msgType = ((int)$runExit === 0) ? 'success' : 'warning';
}

$state = tools_read_state_json($statePath, ['overall_ok', 'doctor', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$doctor = is_array($data['doctor'] ?? null) ? (array)$data['doctor'] : [];
if (empty($doctor)) {
    $doctor = ts_read_json(ts_storage_logs_dir() . '/tools_doctor_last.json');
    $doctor = is_array($doctor) ? $doctor : [];
}
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];
$findings = (array)($doctor['findings'] ?? []);
if (!isset($data['overall_ok']) && isset($doctor['overall_ok'])) $data['overall_ok'] = $doctor['overall_ok'];
if (empty($data['run_at']) && !empty($doctor['generated_at'])) $data['run_at'] = $doctor['generated_at'];

$baseProject = rmi_layout_base_project();
rmi_header('Tools Doctor (Web)', [
    'active' => 'tools',
    'subtitle' => 'Scan tools untuk bootstrap, CSRF, admin guard, path leak. Hasil CLI juga tampil di sini.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Tools Doctor Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
        <button class="btn btn-rmi btn-sm" type="submit">Run Tools Doctor</button>
      </form>
      <div class="small mt-2">CLI: <code>php tools/qa/tools_doctor.php --write-last</code></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok'] || !empty($doctor)): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'OK') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ''))) ?></b></div>
        <div class="small">base_url: <b><?= !empty($doctor['base_url_present']) ? 'OK' : 'MISSING' ?></b></div>
        <div class="small">Critical: <b><?= (int)($doctor['critical_fail_count'] ?? 0) ?></b> · Findings: <b><?= count($findings) ?></b></div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada run. Jalankan dari web atau CLI.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Findings</div>
      <?php if ($findings): ?>
        <?php foreach ($findings as $f): ?>
          <div class="small border-bottom py-1">
            <?= !empty($f['severity']) ? tools_badge((string)$f['severity']) : '' ?>
            <code><?= h((string)($f['file'] ?? '-')) ?></code> ·
            <?= h((string)($f['code'] ?? '')) ?> — <?= h((string)($f['message'] ?? '')) ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="small rmi-muted">Tidak ada findings. Jalankan dari web atau CLI untuk scan.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Latest Output</div>
      <pre class="small mb-0" style="white-space:pre-wrap"><?= h($output ? implode("\n", $output) : 'Jalankan dari web atau CLI untuk melihat output.') ?></pre>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
