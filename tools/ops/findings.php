<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/_lib/ops_helpers.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';

tools_require_access('ops/findings.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

$msg = '';
$msgType = 'info';
$path = ops_state_path('ops_findings.json');
$state = ops_read_json_safe($path);
$data = $state['ok'] ? (array)$state['data'] : ['state_version' => 1, 'findings' => []];
$findings = (array)($data['findings'] ?? []);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator missing');
    }
    $id = trim((string)($_POST['id'] ?? ''));
    $to = strtoupper(trim((string)($_POST['to_status'] ?? '')));
    $note = trim((string)($_POST['note'] ?? ''));
    $allowed = ['OPEN', 'IN_PROGRESS', 'RESOLVED'];
    if ($id === '' || !in_array($to, $allowed, true)) {
        $msg = 'Input status tidak valid.';
        $msgType = 'danger';
    } else {
        $updated = false;
        $actor = function_exists('current_actor_username') ? (string)current_actor_username() : (string)($_SESSION['username'] ?? 'SYSTEM');
        $now = date(DateTimeInterface::ATOM);
        foreach ($findings as &$f) {
            if ((string)($f['id'] ?? '') !== $id) continue;
            if ($to === 'RESOLVED' && mb_strlen($note) < 5) {
                $msg = 'Note wajib saat resolve (minimal 5 karakter).';
                $msgType = 'danger';
                break;
            }
            $f['status'] = $to;
            $f['updated_at'] = $now;
            $f['resolved_at'] = $to === 'RESOLVED' ? $now : null;
            $notes = (array)($f['notes_masked'] ?? []);
            if ($note !== '') $notes[] = ops_mask('[' . $actor . '] ' . $note);
            $f['notes_masked'] = $notes;
            $updated = true;
            $msg = 'Status finding berhasil diubah.';
            $msgType = 'success';
            ts_append_run_history('ops_finding_status_changed', 'ok', [
                'at' => $now,
                'finding_id' => $id,
                'to_status' => $to,
                'actor' => $actor,
            ]);
            try {
                $pdo = rmi_db_pdo();
                erp_audit_ensure($pdo);
                erp_audit($pdo, 'OPS', 'FINDING:' . $id, 'OPS_FINDING_STATUS_CHANGED', [
                    'to_status' => $to,
                    'actor' => $actor,
                    'note_masked' => ops_mask($note),
                ]);
            } catch (Throwable $e) {
            }
            break;
        }
        unset($f);
        if ($updated) {
            $data['state_version'] = 1;
            $data['updated_at'] = date(DateTimeInterface::ATOM);
            $data['findings'] = $findings;
            ops_write_json($path, $data);
            rmi_redirect('findings.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType));
        }
        if ($msg === '') {
            $msg = 'Finding tidak ditemukan.';
            $msgType = 'danger';
        }
    }
}

$filterSev = strtoupper(trim((string)($_GET['severity'] ?? 'ALL')));
$filterSt = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));
$msg = (string)($_GET['msg'] ?? $msg);
$msgType = (string)($_GET['type'] ?? $msgType);

$rows = array_values(array_filter($findings, static function (array $f) use ($filterSev, $filterSt): bool {
    $sev = strtoupper((string)($f['severity'] ?? 'LOW'));
    $st = strtoupper((string)($f['status'] ?? 'OPEN'));
    if ($filterSev !== 'ALL' && $filterSev !== $sev) return false;
    if ($filterSt !== 'ALL' && $filterSt !== $st) return false;
    return true;
}));

$baseProject = rmi_layout_base_project();
rmi_header('Ops Findings', [
    'active' => 'tools',
    'subtitle' => 'SLA & escalation tracking',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Ops Findings'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> mb-0 py-2"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="card p-3">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-3">
          <label class="form-label small">Severity</label>
          <select class="form-select form-select-sm" name="severity">
            <?php foreach (['ALL','CRITICAL','HIGH','MEDIUM','LOW'] as $opt): ?>
              <option value="<?= h($opt) ?>" <?= $filterSev === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small">Status</label>
          <select class="form-select form-select-sm" name="status">
            <?php foreach (['ALL','OPEN','IN_PROGRESS','RESOLVED'] as $opt): ?>
              <option value="<?= h($opt) ?>" <?= $filterSt === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3"><button class="btn btn-sm btn-outline-light">Apply Filter</button></div>
      </form>
    </div>
  </div>
  <div class="col-12">
    <div class="card p-3">
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle">
          <thead><tr><th>ID</th><th>Code</th><th>Severity</th><th>Owner/SLA</th><th>Status</th><th>Opened</th><th>Notes</th><th>Action</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center muted">No findings.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><code><?= h((string)($r['id'] ?? '-')) ?></code></td>
              <td><?= h((string)($r['code'] ?? '-')) ?></td>
              <td><?= tools_badge((string)($r['severity'] ?? 'LOW')) ?></td>
              <td><b><?= h((string)($r['owner'] ?? '-')) ?></b><div class="small muted"><?= (int)($r['sla_minutes'] ?? 0) ?> min</div></td>
              <td><?= tools_badge((string)($r['status'] ?? 'OPEN')) ?></td>
              <td class="small"><?= h(tools_fmt_ts((string)($r['opened_at'] ?? ''))) ?></td>
              <td class="small"><code><?= h((string)end($r['notes_masked']) ?: '-') ?></code></td>
              <td>
                <form method="post" class="d-flex gap-1 flex-wrap">
                  <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
                  <input type="hidden" name="id" value="<?= h((string)($r['id'] ?? '')) ?>">
                  <select class="form-select form-select-sm" name="to_status" style="width:auto">
                    <?php foreach (['OPEN','IN_PROGRESS','RESOLVED'] as $opt): ?>
                      <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input class="form-control form-control-sm" type="text" name="note" placeholder="note (required for RESOLVED)" style="min-width:180px">
                  <button class="btn btn-sm btn-rmi" type="submit">Update</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
