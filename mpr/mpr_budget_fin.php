<?php
if (!function_exists('rmi_icon')) { require_once __DIR__ . '/../_shared/rmi_icons.php'; }

// --- Auth guard ---
$__rmi_guard_dir = __DIR__;
for ($__rmi_guard_i = 0; $__rmi_guard_i < 5; $__rmi_guard_i++) {
    $__rmi_guard_auth = $__rmi_guard_dir . '/master/auth.php';
    if (file_exists($__rmi_guard_auth)) {
        require_once $__rmi_guard_auth;
        if (function_exists('require_login')) require_login();
        break;
    }
    $__rmi_guard_parent = dirname($__rmi_guard_dir);
    if ($__rmi_guard_parent === $__rmi_guard_dir) break;
    $__rmi_guard_dir = $__rmi_guard_parent;
}
unset($__rmi_guard_dir, $__rmi_guard_i, $__rmi_guard_auth, $__rmi_guard_parent);
// --- /Auth guard ---

require_once __DIR__ . '/_layout_top.php';
require_once __DIR__ . '/../master/_audit_master.php';

function mpr_is_manager(array $user): bool {
    return in_array($user['role'], ['MANAGER'], true) || in_array($user['level'], ['MANAGER'], true);
}
function mpr_fin_money($n): string {
    if ($n === null || $n === '') return '-';
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}
function mpr_fin_trim(string $s, int $w = 60): string {
    if (function_exists('mb_strimwidth')) return (string)mb_strimwidth($s, 0, $w, '…');
    return strlen($s) <= $w ? $s : substr($s, 0, max(0,$w-1)) . '…';
}

// Access guard
$can_fin_approve = $MPR_IS_ADMIN || ($MPR_IS_FIN && mpr_is_manager($MPR_USER));
if (!$can_fin_approve) {
    http_response_code(403);
    echo "<div class='rmi-card'><div class='rmi-card-body'><b>Akses Ditolak.</b><br>Halaman ini hanya untuk <b>FIN Manager</b> atau <b>Admin</b>.</div></div>";
    require_once __DIR__ . '/_layout_bottom.php';
    exit;
}

// -------- EXPORT (before output) --------
if ((string)($_GET['export'] ?? '') === '1') {
    $f_status_ex  = strtoupper(trim((string)($_GET['status'] ?? '')));
    $f_q_ex       = trim((string)($_GET['q'] ?? ''));
    $f_date_from  = (string)($_GET['date_from'] ?? '');
    $f_date_to    = (string)($_GET['date_to']   ?? '');

    $p_ex = []; $w_ex = 'br.deleted_at IS NULL';
    if ($f_status_ex && $f_status_ex !== 'ALL') { $w_ex .= ' AND br.status=?'; $p_ex[] = $f_status_ex; }
    if ($f_q_ex !== '') {
        $w_ex .= ' AND (br.request_code LIKE ? OR p.plan_code LIKE ? OR p.title LIKE ? OR br.vendor_name LIKE ?)';
        $like = "%$f_q_ex%"; array_push($p_ex,$like,$like,$like,$like);
    }
    if (!$MPR_IS_ADMIN) { $w_ex .= ' AND br.office_code=?'; $p_ex[] = $MPR_USER['office_code']; }
    if ($f_date_from) { $w_ex .= ' AND DATE(br.request_date) >= ?'; $p_ex[] = $f_date_from; }
    if ($f_date_to)   { $w_ex .= ' AND DATE(br.request_date) <= ?'; $p_ex[] = $f_date_to; }

    $stEx = $pdo->prepare("SELECT br.*, p.plan_code, p.title FROM mpr_budget_requests br JOIN mpr_plans p ON p.id=br.plan_id WHERE {$w_ex} ORDER BY br.created_at DESC");
    $stEx->execute($p_ex);
    $rows_ex = $stEx->fetchAll();

    $fname = 'mpr_budget_fin_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Pragma: no-cache');
    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($fh, ['request_code','plan_code','plan_title','office_code','amount','status','vendor_name','purpose','request_date','created_by','approved_by','approved_at','approval_note']);
    foreach ($rows_ex as $r) {
        fputcsv($fh, [
            $r['request_code'], $r['plan_code'], $r['title'],
            $r['office_code'], $r['amount'], $r['status'],
            $r['vendor_name'], $r['purpose'], $r['request_date'],
            $r['created_by'], $r['approved_by'], $r['approved_at'], $r['approval_note'],
        ]);
    }
    fclose($fh);
    exit;
}

// -------- POST: Approve / Reject --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    $action = (string)($_POST['action'] ?? '');
    $rid    = (int)($_POST['rid'] ?? 0);
    $note   = trim((string)($_POST['note'] ?? ''));
    try {
        $st = $pdo->prepare("
            SELECT br.*, p.plan_code, p.title
            FROM mpr_budget_requests br
            JOIN mpr_plans p ON p.id=br.plan_id
            WHERE br.id=? AND br.deleted_at IS NULL LIMIT 1
        ");
        $st->execute([$rid]);
        $br = $st->fetch();
        if (!$br) throw new Exception("Budget request tidak ditemukan.");
        if (!$MPR_IS_ADMIN && strtoupper((string)$br['office_code']) !== strtoupper((string)$MPR_USER['office_code'])) {
            throw new Exception("Scope office tidak sesuai.");
        }
        $cur = strtoupper((string)$br['status']);
        if ($cur !== 'SUBMITTED') throw new Exception("Hanya status SUBMITTED yang bisa di-approve/reject. Status saat ini: {$cur}.");
        if (!in_array($action, ['approve','reject'], true)) throw new Exception("Action tidak dikenal.");

        $newStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';
        $pdo->prepare("UPDATE mpr_budget_requests SET status=?, approved_at=NOW(), approved_by=?, approval_note=? WHERE id=?")
            ->execute([$newStatus, $MPR_USER['username'], $note ?: null, $rid]);

        $auditAction = $action === 'approve' ? 'FIN_APPROVE' : 'FIN_REJECT';
        mpr_audit($pdo,$MPR_USER,$auditAction,'mpr_budget_requests',$rid,(string)$br['request_code'],'FIN '.ucfirst($action).' budget',['plan'=>$br['plan_code'],'amount'=>$br['amount'],'note'=>$note]);
        if (function_exists('master_audit')) master_audit($pdo,'mpr_budget_fin','mpr_budget_requests',$auditAction,$rid,(string)$br['request_code'],'FIN '.ucfirst($action).' budget',['plan'=>$br['plan_code'],'amount'=>$br['amount']]);
        flash_set($action==='approve'?'success':'warning', "Budget <b>".e($br['request_code'])."</b> {$newStatus}.");
        rmi_redirect(url_mpr('mpr_budget_fin.php'));
    } catch (Throwable $e) {
        if (function_exists('rmi_log_module_error')) rmi_log_module_error('mpr_budget_fin', $e, ['action' => 'MPR_BUDGET_' . strtoupper($action ?? ''), 'rid' => $rid ?? null]);
        flash_set('danger', "Error: " . e($e->getMessage()));
        rmi_redirect(url_mpr('mpr_budget_fin.php'));
    }
}

// -------- Filters --------
$f_status    = strtoupper(trim((string)($_GET['status']    ?? 'SUBMITTED')));
$f_q         = trim((string)($_GET['q']         ?? ''));
$f_date_from = (string)($_GET['date_from'] ?? '');
$f_date_to   = (string)($_GET['date_to']   ?? '');
$f_page      = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 50;

$where  = 'br.deleted_at IS NULL';
$params = [];
if ($f_status !== '' && $f_status !== 'ALL') { $where .= ' AND br.status=?'; $params[] = $f_status; }
if ($f_q !== '') {
    $where .= ' AND (br.request_code LIKE ? OR p.plan_code LIKE ? OR p.title LIKE ? OR br.vendor_name LIKE ?)';
    $like = "%$f_q%"; array_push($params,$like,$like,$like,$like);
}
if (!$MPR_IS_ADMIN) { $where .= ' AND br.office_code=?'; $params[] = $MPR_USER['office_code']; }
if ($f_date_from) { $where .= ' AND DATE(br.request_date) >= ?'; $params[] = $f_date_from; }
if ($f_date_to)   { $where .= ' AND DATE(br.request_date) <= ?'; $params[] = $f_date_to; }

// Total count
$stCount = $pdo->prepare("SELECT COUNT(*) FROM mpr_budget_requests br JOIN mpr_plans p ON p.id=br.plan_id WHERE {$where}");
$stCount->execute($params);
$total_rows = (int)$stCount->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$f_page = min($f_page, $total_pages);
$offset = ($f_page - 1) * $per_page;

$st = $pdo->prepare("
    SELECT br.*, p.plan_code, p.title
    FROM mpr_budget_requests br
    JOIN mpr_plans p ON p.id=br.plan_id
    WHERE {$where}
    ORDER BY br.created_at DESC, br.id DESC
    LIMIT {$per_page} OFFSET {$offset}
");
$st->execute($params);
$rows = $st->fetchAll();

// -------- Summary totals --------
$summary_params = $MPR_IS_ADMIN ? [] : [$MPR_USER['office_code']];
$summary_where  = 'br.deleted_at IS NULL' . ($MPR_IS_ADMIN ? '' : ' AND br.office_code=?');
$stSum = $pdo->prepare("SELECT status, COUNT(*) c, COALESCE(SUM(amount),0) total FROM mpr_budget_requests br WHERE {$summary_where} GROUP BY status");
$stSum->execute($summary_params);
$summary = ['SUBMITTED'=>['c'=>0,'total'=>0],'APPROVED'=>['c'=>0,'total'=>0],'REJECTED'=>['c'=>0,'total'=>0],'DRAFT'=>['c'=>0,'total'=>0]];
foreach ($stSum->fetchAll() as $r) {
    $s = strtoupper((string)$r['status']);
    if (isset($summary[$s])) { $summary[$s]['c'] = (int)$r['c']; $summary[$s]['total'] = (float)$r['total']; }
}

$BIG_AMOUNT = 10_000_000; // threshold highlight merah

// Build page URL helper
function mpr_fin_page_url(int $page, array $extras = []): string {
    $q = array_merge([
        'status'    => $_GET['status']    ?? 'SUBMITTED',
        'q'         => $_GET['q']         ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to'   => $_GET['date_to']   ?? '',
        'page'      => $page,
    ], $extras);
    return url_mpr('mpr_budget_fin.php?' . http_build_query(array_filter($q, fn($v) => $v !== '')));
}
?>

<div class="rmi-card">
  <div class="rmi-card-header d-flex flex-wrap gap-2 justify-content-between align-items-start">
    <div>
      <h5>FIN Approval &bull; Budget Requests</h5>
      <div class="sub">Approve/Reject budget request MPR — hanya FIN Manager &amp; Admin</div>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <a class="btn btn-sm btn-outline-light"
         href="<?= e(url_mpr('mpr_budget_fin.php?export=1&status=' . urlencode($f_status) . '&q=' . urlencode($f_q) . '&date_from=' . urlencode($f_date_from) . '&date_to=' . urlencode($f_date_to))) ?>">
        <?=rmi_icon('outbox')?> Export CSV
      </a>
    </div>
  </div>
  <div class="rmi-card-body">

    <!-- Summary Bar -->
    <div class="d-flex flex-wrap gap-2 mb-3">
      <?php
      $colors = ['SUBMITTED'=>'#f59e0b','APPROVED'=>'#22c55e','REJECTED'=>'#ef4444','DRAFT'=>'#64748b'];
      foreach ($summary as $s => $d):
        if ($d['c'] === 0) continue;
      ?>
        <a href="<?= e(url_mpr('mpr_budget_fin.php?status=' . $s)) ?>"
           class="text-decoration-none px-3 py-2 rounded"
           style="background:rgba(17,24,39,.9);border:1px solid rgba(255,255,255,.1);min-width:140px">
          <div style="font-size:10px;color:<?= $colors[$s] ?? '#94a3b8' ?>;font-weight:700;text-transform:uppercase"><?= e($s) ?></div>
          <div style="font-size:18px;font-weight:800;color:#fff"><?= $d['c'] ?></div>
          <div style="font-size:11px;color:#94a3b8"><?= e(mpr_fin_money($d['total'])) ?></div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form class="row g-2 mb-3" method="get">
      <div class="col-md-2">
        <label class="mini">Status</label>
        <select name="status" class="form-select form-select-sm">
          <?php foreach (['SUBMITTED','APPROVED','REJECTED','DRAFT','ALL'] as $o): ?>
            <option value="<?= e($o) ?>" <?= $f_status===$o?'selected':'' ?>><?= e($o) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="mini">Search</label>
        <input name="q" class="form-control form-control-sm" value="<?= e($f_q) ?>" placeholder="request_code / plan / vendor">
      </div>
      <div class="col-md-2">
        <label class="mini">Tanggal Dari</label>
        <input name="date_from" type="date" class="form-control form-control-sm" value="<?= e($f_date_from) ?>">
      </div>
      <div class="col-md-2">
        <label class="mini">Tanggal Sampai</label>
        <input name="date_to" type="date" class="form-control form-control-sm" value="<?= e($f_date_to) ?>">
      </div>
      <div class="col-md-3 d-flex align-items-end gap-2">
        <button class="btn btn-sm btn-primary">Filter</button>
        <a class="btn btn-sm btn-outline-light" href="<?= e(url_mpr('mpr_budget_fin.php')) ?>">Reset</a>
      </div>
    </form>

    <!-- Tabel -->
    <div class="table-responsive">
      <table id="tblFin" class="table table-sm table-striped align-middle" style="font-size:12px">
        <thead>
          <tr>
            <th>Request</th>
            <th>Plan</th>
            <th>Office</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Vendor / Purpose</th>
            <th style="width:100px">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $stt = strtoupper((string)$r['status']);
            $amt = (float)$r['amount'];
            $isBig = $amt >= $BIG_AMOUNT;
          ?>
            <tr>
              <td>
                <div class="code"><?= e($r['request_code']) ?></div>
                <div class="mini" style="color:#64748b"><?= e($r['request_date'] ?: '-') ?></div>
                <div class="mini" style="color:var(--rmi-muted)">by <?= e($r['created_by'] ?: '-') ?></div>
              </td>
              <td class="mini">
                <div class="code"><?= e($r['plan_code']) ?></div>
                <div><?= e(mpr_fin_trim((string)$r['title'],50)) ?></div>
              </td>
              <td><?= e($r['office_code']) ?></td>
              <td style="white-space:nowrap">
                <span style="font-weight:700;color:<?= $isBig ? '#f87171' : '#e2e8f0' ?>">
                  <?= e(mpr_fin_money($amt)) ?>
                </span>
                <?php if ($isBig): ?>
                  <div class="mini" style="color:#f87171"><?=rmi_icon('warn')?> Nominal besar</div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge-soft"><?= e($stt) ?></span>
                <?php if (!empty($r['approved_by'])): ?>
                  <div class="mini mt-1" style="color:#64748b">By: <?= e($r['approved_by']) ?></div>
                  <?php if (!empty($r['approved_at'])): ?>
                    <div class="mini" style="color:var(--rmi-muted)"><?= e(date('d/m/Y', strtotime((string)$r['approved_at']))) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="mini">
                <div><?= e($r['vendor_name'] ?: '-') ?></div>
                <div style="color:#64748b"><?= e(mpr_fin_trim((string)($r['purpose'] ?: ''),60)) ?></div>
                <?php if (!empty($r['attachment_path'])): ?>
                  <div><a class="mini" href="<?= e(base_project().'/'.ltrim((string)$r['attachment_path'],'/')) ?>" target="_blank"><?=rmi_icon('doc')?> Attachment</a></div>
                <?php endif; ?>
                <?php if (!empty($r['approval_note'])): ?>
                  <div class="mini mt-1" style="color:#94a3b8">Note: <?= e(mpr_fin_trim((string)$r['approval_note'],60)) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($stt === 'SUBMITTED' || $MPR_IS_ADMIN): ?>
                  <!-- Tombol buka modal -->
                  <button class="btn btn-sm btn-outline-light w-100"
                          data-bs-toggle="modal"
                          data-bs-target="#modalApprove"
                          data-rid="<?= (int)$r['id'] ?>"
                          data-code="<?= e($r['request_code']) ?>"
                          data-plan="<?= e($r['plan_code']) ?>"
                          data-amount="<?= e(mpr_fin_money($amt)) ?>">
                    <?=rmi_icon('check')?> Review
                  </button>
                <?php else: ?>
                  <span class="mini" style="color:var(--rmi-muted)">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center" style="color:#64748b;padding:24px">Tidak ada data.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav class="d-flex justify-content-between align-items-center mt-2" style="font-size:12px">
      <div style="color:#64748b">
        Menampilkan <?= number_format(($f_page-1)*$per_page+1) ?>–<?= number_format(min($f_page*$per_page,$total_rows)) ?> dari <?= number_format($total_rows) ?> data
      </div>
      <ul class="pagination pagination-sm mb-0">
        <?php if ($f_page > 1): ?>
          <li class="page-item"><a class="page-link" href="<?= e(mpr_fin_page_url($f_page-1)) ?>">‹</a></li>
        <?php endif; ?>
        <?php for ($pg = max(1,$f_page-2); $pg <= min($total_pages,$f_page+2); $pg++): ?>
          <li class="page-item <?= $pg===$f_page?'active':'' ?>">
            <a class="page-link" href="<?= e(mpr_fin_page_url($pg)) ?>"><?= $pg ?></a>
          </li>
        <?php endfor; ?>
        <?php if ($f_page < $total_pages): ?>
          <li class="page-item"><a class="page-link" href="<?= e(mpr_fin_page_url($f_page+1)) ?>">›</a></li>
        <?php endif; ?>
      </ul>
    </nav>
    <?php endif; ?>

    <div class="mini mt-2" style="color:var(--rmi-muted)">
      Rule: FIN Manager hanya bisa proses budget office-nya sendiri. Admin bisa override semua.
    </div>
  </div>
</div>

<!-- Modal Approve/Reject (single, diisi via JS) -->
<div class="modal fade" id="modalApprove" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="background:#0b1220;color:#e5e7eb;border:1px solid rgba(255,255,255,.12)">
      <div class="modal-header py-2">
        <h6 class="modal-title">Review Budget Request</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <div class="mini mb-1">Request: <span id="modalCode" class="code"></span></div>
        <div class="mini mb-1">Plan: <span id="modalPlan" class="code"></span></div>
        <div class="mini mb-2">Amount: <span id="modalAmount" style="font-weight:700;color:#22c55e"></span></div>
        <form id="formModalApprove" method="post">
          <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
          <input type="hidden" name="rid" id="modalRid" value="">
          <textarea class="form-control mb-3" name="note" rows="3" placeholder="Catatan (opsional)"></textarea>
          <div class="d-flex gap-2">
            <button type="submit" name="action" value="approve" class="btn btn-primary flex-fill"><?=rmi_icon('check')?> Approve</button>
            <button type="submit" name="action" value="reject"  class="btn btn-outline-danger flex-fill"><?=rmi_icon('cross')?> Reject</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Populate modal fields on open
document.getElementById('modalApprove')?.addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('modalRid').value    = btn.getAttribute('data-rid');
  document.getElementById('modalCode').textContent   = btn.getAttribute('data-code');
  document.getElementById('modalPlan').textContent   = btn.getAttribute('data-plan');
  document.getElementById('modalAmount').textContent = btn.getAttribute('data-amount');
  // Clear note each open
  this.querySelector('textarea[name="note"]').value = '';
});
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
