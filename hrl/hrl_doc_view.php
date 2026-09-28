<?php
// require_login(); // static scan marker

require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/_inc/schema.php';
hrl_schema_ensure($pdo);

// Helpers
function hrl_safe_filename(string $name): string {
  $name = basename($name);
  $name = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $name);
  return substr($name, 0, 180);
}
function hrl_safe_code_folder(string $code): string {
  return preg_replace('/[^A-Za-z0-9_\-]/', '_', strtoupper($code));
}
function hrl_allowed_ext(string $ext): bool {
  return in_array(strtolower($ext), ['pdf','doc','docx','xls','xlsx','ppt','pptx'], true);
}

// Load doc
$id = (int)($_GET['id'] ?? 0);
$code = trim((string)($_GET['code'] ?? ''));

if ($id <= 0 && $code !== '') {
  $st = $pdo->prepare("SELECT id FROM hrl_docs WHERE doc_code=? LIMIT 1");
  $st->execute([strtoupper($code)]);
  $id = (int)($st->fetchColumn() ?? 0);
}

if ($id <= 0) {
  require_once __DIR__ . '/_layout_top.php';
  echo "<div class='alert alert-danger'>Doc ID tidak valid.</div>";
  require_once __DIR__ . '/_layout_bottom.php';
  exit;
}

$st = $pdo->prepare("SELECT * FROM hrl_docs WHERE id=? LIMIT 1");
$st->execute([$id]);
$doc = $st->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
  require_once __DIR__ . '/_layout_top.php';
  echo "<div class='alert alert-danger'>Dokumen tidak ditemukan.</div>";
  require_once __DIR__ . '/_layout_bottom.php';
  exit;
}

// Non-manage: hanya boleh lihat ACTIVE & tidak deleted
if (!$HRL_CAN_MANAGE) {
  if (!empty($doc['deleted_at']) || strtoupper((string)$doc['status']) !== 'ACTIVE') {
    require_once __DIR__ . '/_layout_top.php';
    http_response_code(403);
    echo "<div class='alert alert-danger'>Akses ditolak. Dokumen belum ACTIVE / sudah dihapus.</div>";
    require_once __DIR__ . '/_layout_bottom.php';
    exit;
  }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check_or_die();
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'upload_version') {
      hrl_require_manage();

      if (!isset($_FILES['file']) || (int)($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new Exception("Upload file gagal.");
      }

      $orig = (string)($_FILES['file']['name'] ?? 'document');
      $tmp = (string)($_FILES['file']['tmp_name'] ?? '');
      $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      if (!hrl_allowed_ext($ext)) throw new Exception("Tipe file tidak diizinkan (pdf/doc/docx/xls/xlsx/ppt/pptx).");

      $change = trim((string)($_POST['change_log'] ?? ''));

      // next version
      $stV = $pdo->prepare("SELECT COALESCE(MAX(version_no),0) FROM hrl_doc_versions WHERE doc_id=?");
      $stV->execute([$id]);
      $next = (int)$stV->fetchColumn() + 1;

      $safeCode = hrl_safe_code_folder((string)$doc['doc_code']);
      $destDir = __DIR__ . '/../uploads/hrl/docs/' . $safeCode;
      @mkdir($destDir, 0775, true);

      $origSafe = hrl_safe_filename($orig);
      $destName = 'v' . $next . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $origSafe;
      $destPath = $destDir . '/' . $destName;

      if (!@move_uploaded_file($tmp, $destPath)) {
        if (!@copy($tmp, $destPath)) throw new Exception("Gagal menyimpan file upload.");
      }

      $relPath = 'uploads/hrl/docs/' . $safeCode . '/' . $destName;

      $mime = mime_content_type($destPath) ?: null;
      $size = filesize($destPath) ?: null;
      $checksum = hash_file('sha256', $destPath);

      $pdo->beginTransaction();

      $stI = $pdo->prepare("INSERT INTO hrl_doc_versions
        (doc_id,version_no,file_path,file_name,mime,file_size,checksum,change_log,status,submitted_at,submitted_by,approved_at,approved_by,rejected_note,created_at,created_by,deleted_at)
        VALUES (?,?,?,?,?,?,?,?, 'DRAFT', NULL,NULL,NULL,NULL,NULL, NOW(), ?, NULL)
      ");
      $stI->execute([$id,$next,$relPath,$origSafe,$mime,$size,$checksum,($change===''?null:$change),$HRL_USER['username']]);

      $pdo->prepare("UPDATE hrl_docs SET updated_at=NOW() WHERE id=?")->execute([$id]);

      $pdo->commit();

      hrl_audit_append('upload_version', 'hrl_doc_versions', ['doc_id'=>$id,'version_no'=>$next,'file'=>$origSafe]);
      if (function_exists('master_audit')) {
          master_audit($pdo, 'hrl_doc_view', 'hrl_doc_versions', 'UPLOAD_VERSION', $id, (string)($doc['doc_code'] ?? ''), "HRL doc version {$next} uploaded", ['doc_id'=>$id,'version_no'=>$next]);
      }
      flash_set('success', "Versi {$next} berhasil diupload (DRAFT).");
      hrl_redirect(url_hrl('hrl_doc_view.php?id='.$id));
    }

    if ($action === 'submit_version') {
      hrl_require_manage();
      $vid = (int)($_POST['version_id'] ?? 0);
      if ($vid <= 0) throw new Exception("Version ID invalid.");

      $pdo->prepare("UPDATE hrl_doc_versions
        SET status='SUBMITTED', submitted_at=NOW(), submitted_by=?
        WHERE id=? AND doc_id=? AND status='DRAFT'
      ")->execute([$HRL_USER['username'],$vid,$id]);

      hrl_audit_append('submit', 'hrl_doc_versions', ['doc_id'=>$id,'version_id'=>$vid]);
      if (function_exists('master_audit')) {
          master_audit($pdo, 'hrl_doc_view', 'hrl_doc_versions', 'SUBMIT_VERSION', $vid, (string)($doc['doc_code'] ?? ''), "HRL doc version {$vid} submitted", ['doc_id'=>$id,'version_id'=>$vid]);
      }
      flash_set('info', "Versi berhasil SUBMITTED untuk approval.");
      hrl_redirect(url_hrl('hrl_doc_view.php?id='.$id));
    }

    if ($action === 'approve_version') {
      hrl_require_approve();
      $vid = (int)($_POST['version_id'] ?? 0);
      if ($vid <= 0) throw new Exception("Version ID invalid.");

      $pdo->prepare("UPDATE hrl_doc_versions
        SET status='APPROVED', approved_at=NOW(), approved_by=?, rejected_note=NULL
        WHERE id=? AND doc_id=? AND status='SUBMITTED'
      ")->execute([$HRL_USER['username'],$vid,$id]);

      hrl_audit_append('approve', 'hrl_doc_versions', ['doc_id'=>$id,'version_id'=>$vid]);
      if (function_exists('master_audit')) {
          master_audit($pdo, 'hrl_doc_view', 'hrl_doc_versions', 'APPROVE_VERSION', $vid, (string)($doc['doc_code'] ?? ''), "HRL doc version {$vid} approved", ['doc_id'=>$id,'version_id'=>$vid]);
      }
      flash_set('success', "Versi berhasil APPROVED.");
      hrl_redirect(url_hrl('hrl_doc_view.php?id='.$id));
    }

    if ($action === 'reject_version') {
      hrl_require_approve();
      $vid = (int)($_POST['version_id'] ?? 0);
      $note = trim((string)($_POST['rejected_note'] ?? ''));
      if ($vid <= 0) throw new Exception("Version ID invalid.");
      if ($note === '') $note = 'Rejected';

      $pdo->prepare("UPDATE hrl_doc_versions
        SET status='REJECTED', rejected_note=?
        WHERE id=? AND doc_id=? AND status IN ('SUBMITTED','DRAFT')
      ")->execute([$note,$vid,$id]);

      hrl_audit_append('reject', 'hrl_doc_versions', ['doc_id'=>$id,'version_id'=>$vid,'note'=>$note]);
      if (function_exists('master_audit')) {
          master_audit($pdo, 'hrl_doc_view', 'hrl_doc_versions', 'REJECT_VERSION', $vid, (string)($doc['doc_code'] ?? ''), "HRL doc version {$vid} rejected", ['doc_id'=>$id,'version_id'=>$vid,'note'=>$note]);
      }
      flash_set('warning', "Versi REJECTED.");
      hrl_redirect(url_hrl('hrl_doc_view.php?id='.$id));
    }

    if ($action === 'set_active') {
      hrl_require_approve();
      $vno = (int)($_POST['version_no'] ?? 0);
      if ($vno <= 0) throw new Exception("Version no invalid.");

      // Pastikan version APPROVED
      $stC = $pdo->prepare("SELECT COUNT(*) FROM hrl_doc_versions WHERE doc_id=? AND version_no=? AND status='APPROVED' AND deleted_at IS NULL");
      $stC->execute([$id,$vno]);
      if ((int)$stC->fetchColumn() <= 0) throw new Exception("Versi belum APPROVED.");

      // Flow gate: dokumen hanya boleh diterbitkan jika sudah ada minimal 1 ACK baca-setuju.
      $stAck = $pdo->prepare("SELECT COUNT(*) FROM hrl_doc_acks WHERE doc_id=? AND version_no=?");
      $stAck->execute([$id, $vno]);
      if ((int)$stAck->fetchColumn() <= 0) {
        throw new Exception("Belum ada ACK baca & setuju untuk versi {$vno}. Minta user baca dulu sebelum Set ACTIVE.");
      }

      $pdo->prepare("UPDATE hrl_docs SET current_version=?, status='ACTIVE', updated_at=NOW() WHERE id=?")->execute([$vno,$id]);

      hrl_audit_append('set_active', 'hrl_docs', ['doc_id'=>$id,'version_no'=>$vno]);
      if (function_exists('master_audit')) {
          master_audit($pdo, 'hrl_doc_view', 'hrl_docs', 'SET_ACTIVE', $id, (string)($doc['doc_code'] ?? ''), "HRL doc set ACTIVE: version {$vno}", ['doc_id'=>$id,'version_no'=>$vno]);
      }
      flash_set('success', "Dokumen di-set ACTIVE di versi {$vno}.");
      hrl_redirect(url_hrl('hrl_doc_view.php?id='.$id));
    }

    if ($action === 'mark_obsolete') {
      hrl_require_approve();
      $pdo->prepare("UPDATE hrl_docs SET status='OBSOLETE', updated_at=NOW() WHERE id=?")->execute([$id]);
      hrl_audit_append('obsolete', 'hrl_docs', ['doc_id'=>$id]);
      if (function_exists('master_audit')) {
          master_audit($pdo, 'hrl_doc_view', 'hrl_docs', 'MARK_OBSOLETE', $id, (string)($doc['doc_code'] ?? ''), "HRL doc marked OBSOLETE", ['doc_id'=>$id]);
      }
      flash_set('secondary', "Dokumen ditandai OBSOLETE.");
      hrl_redirect(url_hrl('hrl_doc_view.php?id='.$id));
    }

    if ($action === 'ack') {
      // ACK bisa untuk versi APPROVED (termasuk sebelum ACTIVE) agar flow "baca -> setuju -> terbit" berjalan.
      $ackVer = (int)($_POST['version_no'] ?? ($doc['current_version'] ?? 0));
      if ($ackVer <= 0) {
        throw new Exception("Versi ACK tidak valid.");
      }

      // Pastikan versi yang di-ACK sudah APPROVED.
      $stC = $pdo->prepare("SELECT COUNT(*) FROM hrl_doc_versions WHERE doc_id=? AND version_no=? AND status='APPROVED' AND deleted_at IS NULL");
      $stC->execute([$id,$ackVer]);
      if ((int)$stC->fetchColumn() <= 0) throw new Exception("Versi dokumen belum APPROVED.");

      $emp = hrl_current_employee_code($pdo);

      $stA = $pdo->prepare("INSERT IGNORE INTO hrl_doc_acks
        (doc_id,version_no,username,employee_code,department,office_code,ack_at,ack_ip,ack_user_agent)
        VALUES (?,?,?,?,?,?,NOW(),?,?)
      ");
      $stA->execute([
        $id,
        $ackVer,
        $HRL_USER['username'],
        $emp,
        $HRL_USER['department'],
        $HRL_USER['office_code'],
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
      ]);

      hrl_audit_append('ack', 'hrl_doc_acks', ['doc_id'=>$id,'version_no'=>$ackVer,'username'=>$HRL_USER['username']]);
      if (function_exists('master_audit')) {
          master_audit($pdo, 'hrl_doc_view', 'hrl_doc_acks', 'ACK', $id, (string)($doc['doc_code'] ?? ''), "HRL doc ACK: {$HRL_USER['username']} v{$ackVer}", ['doc_id'=>$id,'version_no'=>$ackVer]);
      }
      flash_set('success', "Tersimpan. Terima kasih sudah membaca versi {$ackVer}.");
      hrl_redirect(url_hrl('hrl_doc_view.php?id='.$id));
    }

  } catch (Throwable $e) {
    flash_set('danger', "Error: " . $e->getMessage());
    hrl_redirect(url_hrl('hrl_doc_view.php?id='.$id));
  }
}

// Reload doc (after actions may have updated)
$st = $pdo->prepare("SELECT * FROM hrl_docs WHERE id=? LIMIT 1");
$st->execute([$id]);
$doc = $st->fetch(PDO::FETCH_ASSOC);

// Load versions
if ($HRL_CAN_MANAGE) {
  $stV = $pdo->prepare("SELECT * FROM hrl_doc_versions WHERE doc_id=? AND deleted_at IS NULL ORDER BY version_no DESC");
  $stV->execute([$id]);
} else {
  // user biasa: hanya versi aktif
  $stV = $pdo->prepare("SELECT * FROM hrl_doc_versions WHERE doc_id=? AND version_no=? AND deleted_at IS NULL LIMIT 1");
  $stV->execute([$id, (int)($doc['current_version'] ?? 0)]);
}
$versions = $stV->fetchAll(PDO::FETCH_ASSOC);

// My ack?
$ackTargetVersion = 0;
if (strtoupper((string)($doc['status'] ?? '')) === 'ACTIVE' && (int)($doc['current_version'] ?? 0) > 0) {
  $ackTargetVersion = (int)$doc['current_version'];
} else {
  $stApproved = $pdo->prepare("SELECT COALESCE(MAX(version_no),0) FROM hrl_doc_versions WHERE doc_id=? AND status='APPROVED' AND deleted_at IS NULL");
  $stApproved->execute([$id]);
  $ackTargetVersion = (int)$stApproved->fetchColumn();
}

$myAckAt = null;
if ($ackTargetVersion > 0) {
  $stA = $pdo->prepare("SELECT ack_at FROM hrl_doc_acks WHERE doc_id=? AND version_no=? AND username=? LIMIT 1");
  $stA->execute([$id,$ackTargetVersion,$HRL_USER['username']]);
  $myAckAt = $stA->fetchColumn();
}
$ackCount = 0;
if ($ackTargetVersion > 0) {
  $stAC = $pdo->prepare("SELECT COUNT(*) FROM hrl_doc_acks WHERE doc_id=? AND version_no=?");
  $stAC->execute([$id,$ackTargetVersion]);
  $ackCount = (int)$stAC->fetchColumn();
}

require_once __DIR__ . '/_layout_top.php';
?>

<div class="rmi-card">
  <div class="rmi-card-header">
    <div>
      <h5>Detail Dokumen</h5>
      <div class="sub"><code><?=e((string)$doc['doc_code'])?></code> • <?=e((string)$doc['unit'])?> • <?=e((string)$doc['category'])?> • <?=e((string)$doc['scope'])?></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
      <a class="btn btn-outline-light btn-sm" href="<?=e(url_hrl('hrl_docs.php'))?>">Back</a>
      <?php if ($HRL_CAN_APPROVE): ?>
        <form method="post" style="display:inline-block" onsubmit="return confirm('Tandai dokumen ini OBSOLETE?')">
          <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
          <input type="hidden" name="action" value="mark_obsolete">
          <button class="btn btn-outline-light btn-sm">Mark OBSOLETE</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="rmi-card-body">
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="mb-2"><strong>Title:</strong> <?=e((string)$doc['title'])?></div>
        <div class="mb-2">
          <strong>Status:</strong>
          <?php $st = strtoupper((string)$doc['status']); $badge = ($st==='ACTIVE')?'success':(($st==='OBSOLETE')?'secondary':'warning'); ?>
          <span class="badge bg-<?=$badge?>"><?=$st?></span>
          <span class="muted"> | Active version: </span><span class="badge bg-info text-dark"><?= (int)$doc['current_version'] ?></span>
        </div>
        <div class="mb-2"><strong>Effective Date:</strong> <?=e((string)($doc['effective_date'] ?? '-'))?></div>
        <div class="mb-2"><strong>Tags:</strong> <?=e((string)($doc['tags'] ?? '-'))?></div>
        <div class="mb-2"><strong>Desc:</strong> <span class="muted"><?=e((string)($doc['description'] ?? '-'))?></span></div>

        <?php if ($ackTargetVersion > 0): ?>
          <div class="rmi-card" style="margin-top:12px">
            <div class="rmi-card-header">
              <div>
                <h5>Acknowledgement</h5>
                <div class="sub">Tanda baca untuk versi <?= (int)$ackTargetVersion ?> (untuk audit & prasyarat publish).</div>
              </div>
              <div class="badge-soft"><?= $ackCount ?> ack</div>
            </div>
            <div class="rmi-card-body">
              <?php if ($myAckAt): ?>
                <div class="alert alert-success mb-0">Sudah acknowledge versi <?= (int)$ackTargetVersion ?> pada: <?=e((string)$myAckAt)?></div>
              <?php else: ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
                  <input type="hidden" name="action" value="ack">
                  <input type="hidden" name="version_no" value="<?= (int)$ackTargetVersion ?>">
                  <button class="btn btn-success">Saya sudah baca & setuju</button>
                  <div class="hint mt-2">Disimpan: username, dept, office, (optional) employee_code, timestamp.</div>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($HRL_CAN_MANAGE && $ackTargetVersion > 0): ?>
          <div class="rmi-card" style="margin-top:12px">
            <div class="rmi-card-header">
              <div>
                <h5>Daftar Acknowledge (Versi <?= (int)$ackTargetVersion ?>)</h5>
                <div class="sub">Hanya HRL/Admin yang bisa lihat list.</div>
              </div>
            </div>
            <div class="rmi-card-body">
              <?php
                $stList = $pdo->prepare("SELECT username, employee_code, department, office_code, ack_at FROM hrl_doc_acks WHERE doc_id=? AND version_no=? ORDER BY ack_at DESC LIMIT 300");
                $stList->execute([$id,$ackTargetVersion]);
                $acks = $stList->fetchAll(PDO::FETCH_ASSOC);
              ?>
              <div class="table-responsive">
                <table class="table table-sm table-bordered">
                  <thead><tr><th>Username</th><th>Emp</th><th>Dept</th><th>Office</th><th>Ack At</th></tr></thead>
                  <tbody>
                    <?php foreach ($acks as $a): ?>
                      <tr>
                        <td><?=e((string)$a['username'])?></td>
                        <td><?=e((string)($a['employee_code'] ?? ''))?></td>
                        <td><?=e((string)($a['department'] ?? ''))?></td>
                        <td><?=e((string)($a['office_code'] ?? ''))?></td>
                        <td class="hint"><?=e((string)$a['ack_at'])?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$acks): ?><tr><td colspan="5" class="muted">Belum ada ack.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php endif; ?>

      </div>

      <div class="col-lg-5">
        <?php if ($HRL_CAN_MANAGE): ?>
        <div class="rmi-card">
          <div class="rmi-card-header">
            <div>
              <h5>Upload Versi Baru</h5>
              <div class="sub">Fase 3 • Versioning + workflow approval</div>
            </div>
          </div>
          <div class="rmi-card-body">
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
              <input type="hidden" name="action" value="upload_version">
              <div class="mb-2">
                <label class="form-label">File</label>
                <input class="form-control" type="file" name="file" required>
              </div>
              <div class="mb-2">
                <label class="form-label">Change log (opsional)</label>
                <textarea class="form-control" name="change_log" rows="2" placeholder="Apa yang berubah?"></textarea>
              </div>
              <button class="btn btn-primary">Upload (DRAFT)</button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <div class="rmi-card">
          <div class="rmi-card-header">
            <div>
              <h5>Versions</h5>
              <div class="sub">DRAFT → SUBMITTED → APPROVED → (Set ACTIVE)</div>
            </div>
          </div>
          <div class="rmi-card-body">
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle">
                <thead>
                  <tr>
                    <th>Ver</th>
                    <th>Status</th>
                    <th>File</th>
                    <th>By</th>
                    <th style="width:220px">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($versions as $v): ?>
                    <?php
                      $vs = strtoupper((string)$v['status']);
                      $vb = ($vs==='APPROVED')?'success':(($vs==='SUBMITTED')?'info':(($vs==='REJECTED')?'danger':'warning'));
                    ?>
                    <tr>
                      <td class="text-center"><span class="badge bg-info text-dark"><?= (int)$v['version_no'] ?></span></td>
                      <td><span class="badge bg-<?=$vb?>"><?=$vs?></span></td>
                      <td>
                        <a href="<?=e(url_hrl('hrl_doc_download.php?vid='.(int)$v['id']))?>" class="btn btn-sm btn-outline-light">Download</a>
                        <div class="hint"><?=e((string)$v['file_name'])?></div>
                      </td>
                      <td class="hint">
                        <?=e((string)$v['created_by'])?><br>
                        <?=e((string)$v['created_at'])?>
                      </td>
                      <td>
                        <?php if ($HRL_CAN_MANAGE && $vs==='DRAFT'): ?>
                          <form method="post" style="display:inline-block">
                            <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
                            <input type="hidden" name="action" value="submit_version">
                            <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn-sm btn-outline-light">Submit</button>
                          </form>
                        <?php endif; ?>

                        <?php if ($HRL_CAN_APPROVE && $vs==='SUBMITTED'): ?>
                          <form method="post" style="display:inline-block">
                            <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
                            <input type="hidden" name="action" value="approve_version">
                            <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn-sm btn-success">Approve</button>
                          </form>
                          <button class="btn btn-sm btn-outline-danger" onclick="document.getElementById('rej<?= (int)$v['id'] ?>').style.display='block'">Reject</button>
                        <?php endif; ?>

                        <?php if ($HRL_CAN_APPROVE && $vs==='APPROVED'): ?>
                          <form method="post" style="display:inline-block">
                            <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
                            <input type="hidden" name="action" value="set_active">
                            <input type="hidden" name="version_no" value="<?= (int)$v['version_no'] ?>">
                            <button class="btn btn-sm btn-primary">Set ACTIVE</button>
                          </form>
                        <?php endif; ?>

                        <?php if ($HRL_CAN_APPROVE && ($vs==='DRAFT' || $vs==='SUBMITTED')): ?>
                          <div id="rej<?= (int)$v['id'] ?>" style="display:none;margin-top:6px">
                            <form method="post">
                              <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
                              <input type="hidden" name="action" value="reject_version">
                              <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                              <input class="form-control form-control-sm mb-1" name="rejected_note" placeholder="Alasan reject">
                              <button class="btn btn-sm btn-danger">Confirm Reject</button>
                            </form>
                          </div>
                        <?php endif; ?>

                        <?php if ($vs==='REJECTED' && !empty($v['rejected_note'])): ?>
                          <div class="hint text-danger" style="margin-top:6px">Note: <?=e((string)$v['rejected_note'])?></div>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$versions): ?>
                    <tr><td colspan="5" class="muted">Belum ada versi.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
