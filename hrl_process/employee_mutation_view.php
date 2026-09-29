<?php
declare(strict_types=1);

require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_inc/employee_mutation_helper.php';
require_login();

if (!hrlm_can_view()) { http_response_code(403); exit('Akses ditolak.'); }
if (!hrlm_table_exists($pdo, 'hrl_employee_mutations')) { http_response_code(503); exit('Migration mutasi belum dijalankan.'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID tidak valid.'); }

$err = '';
$ok = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if (!hrlm_can_manage()) {
        $err = 'Anda tidak memiliki izin untuk aksi ini.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            $actor = hrlm_username();

            $st = $pdo->prepare("SELECT * FROM hrl_employee_mutations WHERE id=?");
            $st->execute([$id]);
            $m0 = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m0) throw new RuntimeException('Mutasi tidak ditemukan.');

            if ($action === 'submit' && $m0['status'] === 'DRAFT') {
                if (hrlm_active_mutation($pdo, (int)$m0['employee_id'], $id)) throw new RuntimeException('Ada mutasi aktif lain.');
                $pdo->prepare("UPDATE hrl_employee_mutations SET status='SUBMITTED',submitted_by=?,submitted_at=NOW(),updated_at=NOW() WHERE id=?")
                    ->execute([$actor,$id]);
                hrlm_write_audit($pdo,'SUBMIT',$id,['mutation_code'=>$m0['mutation_code']]);
                $ok='Mutasi disubmit.';
            } elseif ($action === 'approve' && $m0['status'] === 'SUBMITTED') {
                if (hrlm_active_mutation($pdo, (int)$m0['employee_id'], $id)) throw new RuntimeException('Ada mutasi aktif lain.');
                $newStatus = ((string)$m0['effective_date'] > date('Y-m-d')) ? 'SCHEDULED' : 'APPROVED';
                $pdo->prepare("UPDATE hrl_employee_mutations
                               SET status=?,approved_by=?,approved_at=NOW(),
                                   scheduled_at=CASE WHEN ?='SCHEDULED' THEN NOW() ELSE scheduled_at END,
                                   updated_at=NOW()
                               WHERE id=?")
                    ->execute([$newStatus,$actor,$newStatus,$id]);
                hrlm_write_audit($pdo,'APPROVE',$id,['mutation_code'=>$m0['mutation_code'],'next_status'=>$newStatus]);
                $ok='Mutasi disetujui.';
            } elseif ($action === 'reject' && $m0['status'] === 'SUBMITTED') {
                $reason = trim((string)($_POST['reason'] ?? ''));
                if ($reason==='') throw new RuntimeException('Alasan penolakan wajib.');
                $pdo->prepare("UPDATE hrl_employee_mutations SET status='REJECTED',rejected_by=?,rejected_at=NOW(),reject_reason=?,updated_at=NOW() WHERE id=?")
                    ->execute([$actor,$reason,$id]);
                hrlm_write_audit($pdo,'REJECT',$id,['mutation_code'=>$m0['mutation_code'],'reason'=>$reason]);
                $ok='Mutasi ditolak.';
            } elseif ($action === 'cancel' && in_array($m0['status'], ['DRAFT','SUBMITTED','APPROVED','SCHEDULED'], true)) {
                $reason = trim((string)($_POST['reason'] ?? ''));
                if ($reason==='') throw new RuntimeException('Alasan pembatalan wajib.');
                $pdo->prepare("UPDATE hrl_employee_mutations SET status='CANCELLED',cancelled_by=?,cancelled_at=NOW(),cancel_reason=?,updated_at=NOW() WHERE id=?")
                    ->execute([$actor,$reason,$id]);
                hrlm_write_audit($pdo,'CANCEL',$id,['mutation_code'=>$m0['mutation_code'],'reason'=>$reason]);
                $ok='Mutasi dibatalkan.';
            } elseif ($action === 'effective') {
                $res = hrlm_apply_effective($pdo, $id, $actor, false);
                $ok = 'Mutasi efektif: ' . $res['mutation_code'] . '. User terkait perlu login ulang agar session RBAC mengikuti dept/office baru.';
            } else {
                throw new RuntimeException('Aksi tidak valid untuk status saat ini.');
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$st = $pdo->prepare("SELECT m.*, e.employee_name
                     FROM hrl_employee_mutations m
                     LEFT JOIN master_employees e ON e.id=m.employee_id
                     WHERE m.id=? LIMIT 1");
$st->execute([$id]);
$m = $st->fetch(PDO::FETCH_ASSOC);
if (!$m) { http_response_code(404); exit('Mutasi tidak ditemukan.'); }

$hist = [];
if (hrlm_table_exists($pdo, 'hrl_employee_assignment_history')) {
    $hs = $pdo->prepare("SELECT * FROM hrl_employee_assignment_history WHERE employee_id=? ORDER BY effective_from DESC,id DESC");
    $hs->execute([(int)$m['employee_id']]);
    $hist = $hs->fetchAll(PDO::FETCH_ASSOC);
}

function mh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

$page_title = 'Detail Mutasi';
require_once __DIR__ . '/_layout_top.php';
?>
<style>
.mv-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px;margin-bottom:14px}
.mv-grid{display:grid;grid-template-columns:1fr 70px 1fr;gap:12px;align-items:start}
@media(max-width:800px){.mv-grid{grid-template-columns:1fr}.mv-arrow{display:none}}
.mv-k{font-size:10px;text-transform:uppercase;color:#94a3b8}.mv-v{font-size:14px;font-weight:700;margin-bottom:10px}
</style>

<a class="btn btn-sm btn-ghost mb-3" href="employee_mutations.php">← Daftar Mutasi</a>
<h2><?=rmi_icon('refresh')?> <?= mh($m['mutation_code'] ?: ('#'.$m['id'])) ?></h2>

<?php if ($err): ?><div class="alert alert-danger"><?= mh($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= mh($ok) ?></div><?php endif; ?>

<div class="mv-card">
  <div><b><?= mh($m['employee_name'] ?: $m['employee_code']) ?></b> <span class="text-muted"><?= mh($m['employee_code']) ?></span></div>
  <div class="text-muted">Status: <b><?= mh($m['status']) ?></b> · Efektif: <b><?= mh($m['effective_date']) ?></b></div>
  <div class="mt-2"><?= nl2br(mh($m['reason'])) ?></div>
</div>

<div class="mv-grid">
  <div class="mv-card">
    <h4>Sebelum</h4>
    <div class="mv-k">Department</div><div class="mv-v"><?= mh($m['from_dept_code']) ?></div>
    <div class="mv-k">Office</div><div class="mv-v"><?= mh($m['from_office_code']) ?></div>
    <div class="mv-k">Jabatan</div><div class="mv-v"><?= mh($m['from_position_name']) ?></div>
    <div class="mv-k">Role</div><div class="mv-v"><?= mh($m['from_role_code']) ?></div>
  </div>
  <div class="mv-arrow" style="font-size:34px;text-align:center;padding-top:55px">→</div>
  <div class="mv-card">
    <h4>Sesudah</h4>
    <div class="mv-k">Department</div><div class="mv-v"><?= mh($m['to_dept_code']) ?></div>
    <div class="mv-k">Office</div><div class="mv-v"><?= mh($m['to_office_code'] ?: '(tetap/kosong)') ?></div>
    <div class="mv-k">Jabatan</div><div class="mv-v"><?= mh($m['to_position_name'] ?: '(tetap)') ?></div>
    <div class="mv-k">Role</div><div class="mv-v"><?= mh($m['to_role_code'] ?: '(tetap)') ?></div>
  </div>
</div>

<?php if (hrlm_can_manage() && $m['status'] !== 'EFFECTIVE'): ?>
<div class="mv-card">
  <h4>Aksi</h4>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($m['status']==='DRAFT'): ?>
      <form method="post"><input type="hidden" name="_csrf" value="<?= mh(csrf_token()) ?>"><input type="hidden" name="action" value="submit"><button class="btn btn-rmi">Submit</button></form>
    <?php endif; ?>
    <?php if ($m['status']==='SUBMITTED'): ?>
      <form method="post"><input type="hidden" name="_csrf" value="<?= mh(csrf_token()) ?>"><input type="hidden" name="action" value="approve"><button class="btn btn-rmi">Approve</button></form>
      <form method="post" style="display:flex;gap:5px"><input type="hidden" name="_csrf" value="<?= mh(csrf_token()) ?>"><input type="hidden" name="action" value="reject"><input name="reason" class="form-control" placeholder="Alasan reject" required><button class="btn btn-danger">Reject</button></form>
    <?php endif; ?>
    <?php if (in_array($m['status'], ['APPROVED','SCHEDULED'], true)): ?>
      <form method="post"><input type="hidden" name="_csrf" value="<?= mh(csrf_token()) ?>"><input type="hidden" name="action" value="effective"><button class="btn btn-rmi" <?= $m['effective_date']>date('Y-m-d')?'disabled title="Tanggal efektif belum tiba"':'' ?>>Jadikan EFFECTIVE</button></form>
    <?php endif; ?>
    <?php if (in_array($m['status'], ['DRAFT','SUBMITTED','APPROVED','SCHEDULED'], true)): ?>
      <form method="post" style="display:flex;gap:5px"><input type="hidden" name="_csrf" value="<?= mh(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><input name="reason" class="form-control" placeholder="Alasan cancel" required><button class="btn btn-ghost">Cancel</button></form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="mv-card">
  <h4>Histori Penempatan</h4>
  <div style="overflow-x:auto">
    <table class="table">
      <thead><tr><th>Dept</th><th>Office</th><th>Jabatan</th><th>Role</th><th>Dari</th><th>Sampai</th><th>Sumber</th></tr></thead>
      <tbody>
      <?php foreach ($hist as $h): ?>
        <tr>
          <td><?= mh($h['dept_code']) ?></td><td><?= mh($h['office_code']) ?></td><td><?= mh($h['position_name']) ?></td><td><?= mh($h['role_code']) ?></td>
          <td><?= mh($h['effective_from']) ?></td><td><?= mh($h['effective_to'] ?: 'sekarang') ?></td><td><?= mh($h['source']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$hist): ?><tr><td colspan="7" class="text-muted">Histori akan dibuat saat mutasi pertama menjadi EFFECTIVE.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
