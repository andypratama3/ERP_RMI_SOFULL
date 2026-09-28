<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

opsgov_require_admin();

$logPath = opsgov_root() . '/storage/logs/dr_drill.jsonl';
@mkdir(dirname($logPath), 0775, true);

$msg = '';
$type = 'info';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $drillType = strtoupper(trim((string)($_POST['drill_type'] ?? 'TABLETOP')));
    $result = strtoupper(trim((string)($_POST['result'] ?? 'PASS')));
    if (!in_array($drillType, ['TABLETOP', 'REAL'], true)) $drillType = 'TABLETOP';
    if (!in_array($result, ['PASS', 'WARN', 'FAIL'], true)) $result = 'PASS';
    $mappedType = $drillType === 'REAL' ? 'restore_apply' : 'restore_dry_run';
    $requestId = 'req-' . date('YmdHis') . '-' . substr(sha1($drillType . microtime(true)), 0, 10);
    $entry = [
        'state_version' => 1,
        'ts' => date(DateTimeInterface::ATOM),
        'type' => $mappedType,
        'scenario' => trim((string)($_POST['scenario'] ?? '')),
        'rto_minutes' => round(((float)($_POST['rto_sec'] ?? 0)) / 60, 2),
        'rpo_minutes' => round(((float)($_POST['rpo_sec'] ?? 0)) / 60, 2),
        'result' => $result,
        'notes_masked' => opsgov_mask(trim((string)($_POST['notes'] ?? ''))),
        'actor_username' => (string)($_SESSION['username'] ?? 'SYSTEM'),
        'request_id' => $requestId,
    ];
    @file_put_contents($logPath, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    ts_append_run_history('dr_drill_logged', 'OK', [
        'module' => 'tools.dr',
        'action' => 'drill_log',
        'result' => 'OK',
        'source' => 'tools/dr/dr_log.php',
        'type' => $entry['type'],
        'request_id' => $requestId,
    ]);
    $msg = 'DR drill log tersimpan.';
    $type = 'success';
}

$history = function_exists('tools_tail_jsonl') ? tools_tail_jsonl($logPath, 200) : ['exists' => false, 'items' => []];
$items = array_reverse((array)($history['items'] ?? []));
$baseProject = rmi_layout_base_project();
rmi_header('DR Drill Log', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'DR Drill Log']]);
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card p-3">
      <?php if ($msg !== ''): ?><div class="alert alert-<?= opsgov_h($type) ?>"><?= opsgov_h($msg) ?></div><?php endif; ?>
      <div class="fw-semibold mb-2">Catat hasil drill</div>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
        <div class="col-6"><label class="form-label small">Type</label><select class="form-select form-select-sm" name="drill_type"><option>TABLETOP</option><option>REAL</option></select></div>
        <div class="col-6"><label class="form-label small">Result</label><select class="form-select form-select-sm" name="result"><option>PASS</option><option>WARN</option><option>FAIL</option></select></div>
        <div class="col-12"><label class="form-label small">Scenario</label><input class="form-control form-control-sm" name="scenario" required></div>
        <div class="col-6"><label class="form-label small">RTO (sec)</label><input class="form-control form-control-sm" name="rto_sec" type="number" step="0.01"></div>
        <div class="col-6"><label class="form-label small">RPO (sec)</label><input class="form-control form-control-sm" name="rpo_sec" type="number" step="0.01"></div>
        <div class="col-12"><label class="form-label small">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="3"></textarea></div>
        <div class="col-12"><button class="btn btn-sm btn-rmi">Simpan Log</button></div>
      </form>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Riwayat DR Drill</div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom">
          <thead><tr><th>Time</th><th>Type</th><th>Scenario</th><th>RTO</th><th>RPO</th><th>Result</th></tr></thead>
          <tbody>
          <?php if (!$items): ?><tr><td colspan="6" class="text-center muted">Belum ada log.</td></tr><?php endif; ?>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= opsgov_h(tools_fmt_ts((string)($it['ts'] ?? ''))) ?></td>
              <td><?= opsgov_h((string)($it['type'] ?? '-')) ?></td>
              <td><?= opsgov_h((string)($it['scenario'] ?? '-')) ?></td>
              <td><?= opsgov_h((string)($it['rto_minutes'] ?? '-')) ?> min</td>
              <td><?= opsgov_h((string)($it['rpo_minutes'] ?? '-')) ?> min</td>
              <td><?= tools_badge((string)($it['result'] ?? 'UNKNOWN')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
