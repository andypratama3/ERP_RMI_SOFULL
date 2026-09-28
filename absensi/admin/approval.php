<?php
$title="Admin HR • Approval";
require_once __DIR__ . "/../_inc/bootstrap.php";
require_once __DIR__ . "/../../master/_audit_master.php";

// Static scan: explicit auth/RBAC guard in this file
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.APPROVE');

require_once __DIR__ . "/../_layout_top.php";

if (!absensi_is_hr_admin($pdo, $ABS_USER)) { http_response_code(403); die('Akses ditolak'); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify_or_die();
  $id = (int)($_POST['id'] ?? 0);
  $act = (string)($_POST['act'] ?? '');
  $note = trim((string)($_POST['note'] ?? ''));

  if ($id>0 && in_array($act,['APPROVE','REJECT'], true)) {
    $status = $act==='APPROVE' ? 'APPROVED' : 'REJECTED';
    $stmt = $pdo->prepare("UPDATE absensi_requests SET status=?, approver_id=?, approver_name=?, note=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([$status,(int)$ABS_USER['id'],(string)$ABS_USER['username'],$note,$id]);
    absensi_audit($pdo, $ABS_USER, 'REQUEST_'.$act, ['id'=>$id,'note'=>$note]);
    if (function_exists('master_audit')) {
      $stR = $pdo->prepare("SELECT username, req_type, start_date, end_date FROM absensi_requests WHERE id=? LIMIT 1");
      $stR->execute([$id]);
      $r = $stR->fetch(PDO::FETCH_ASSOC);
      $code = $r ? ($r['username'] . '#' . $r['req_type'] . '#' . $id) : "REQ#{$id}";
      master_audit($pdo, 'absensi_admin', 'absensi_requests', $act, $id, $code, "Absensi request {$act}: #{$id}", ['note' => $note]);
    }
    absensi_flash_set('ok','Request berhasil di-'.$status.'.');
  }
  rmi_redirect('approval.php');
}

$stmt = $pdo->query("SELECT * FROM absensi_requests ORDER BY FIELD(status,'PENDING','APPROVED','REJECTED'), id DESC LIMIT 200");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="grid">
  <div class="card col-12">
    <div class="row" style="justify-content:space-between">
      <div>
        <div class="h1">Approval Request</div>
        <div class="muted small">Fase 3 • Izin/Sakit/Dinas</div>
      </div>
      <div class="row">
        <a class="btn" href="rekap.php">Rekap</a>
        <a class="btn" href="offices.php">Office</a>
        <a class="btn" href="users.php">Users</a>
      </div>
    </div>

    <table class="table" style="margin-top:12px">
      <thead><tr><th>ID</th><th>User</th><th>Tipe</th><th>Rentang</th><th>Alasan</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['username'].' (#'.$r['user_id'].')',ENT_QUOTES,'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['req_type'],ENT_QUOTES,'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['start_date'].' → '.$r['end_date'],ENT_QUOTES,'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$r['reason'],ENT_QUOTES,'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['status'],ENT_QUOTES,'UTF-8') ?></td>
          <td>
            <?php if ($r['status']==='PENDING'): ?>
              <form method="post" class="row" style="gap:6px">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="text" name="note" placeholder="Catatan" style="width:180px">
                <button class="btn ok" name="act" value="APPROVE" type="submit">Approve</button>
                <button class="btn bad" name="act" value="REJECT" type="submit">Reject</button>
              </form>
            <?php else: ?>
              <span class="muted small"><?= htmlspecialchars($r['approver_name'] ?: '-',ENT_QUOTES,'UTF-8') ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . "/../_layout_bottom.php"; ?>
