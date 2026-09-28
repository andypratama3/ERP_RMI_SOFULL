<?php
// require_login(); // static scan marker

// hrl/hrl_tower.php
// HRL Control Tower (pengajuan lintas departemen + TTD digital via approve)
// EnterprisePPP - tambahan tanpa mengganggu modul HRL Docs yang sudah ada.

require_once __DIR__ . '/_layout_top.php';

$CSRF_TOKEN = (string)($_SESSION['csrf_token'] ?? '');

// Permission:
// - Pengajuan boleh untuk MANAGER (departemen mana pun) + ADMIN/SYS
// - Approve/Reject/Set Active tetap pakai aturan HRL_CAN_APPROVE (manager HRL / admin)
$CAN_REQUEST = ($HRL_IS_ADMIN || $HRL_IS_MANAGER);

// ---- Local helpers (dibuat di sini agar tidak mengubah file lain) ----
function hrl_tower_clean_code(string $code): string {
  $code = strtoupper(trim($code));
  $code = preg_replace('/[^A-Z0-9_\-]/', '_', $code);
  $code = preg_replace('/_+/', '_', $code);
  $code = trim($code, '_');
  if ($code === '') $code = 'HRL_DOC';
  return substr($code, 0, 50);
}
function hrl_tower_auto_code(string $unit, string $category): string {
  $seed = bin2hex(random_bytes(3));
  return hrl_tower_clean_code('HRL-' . strtoupper($unit) . '-' . strtoupper($category) . '-' . date('Ymd') . '-' . $seed);
}
function hrl_tower_safe_filename(string $name): string {
  $name = basename($name);
  $name = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $name);
  return substr($name, 0, 180);
}
function hrl_tower_safe_code_folder(string $code): string {
  return preg_replace('/[^A-Za-z0-9_\-]/', '_', strtoupper($code));
}
function hrl_tower_allowed_ext(string $ext): bool {
  return in_array(strtolower($ext), ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg'], true);
}
function hrl_tower_badge(string $label, string $cls = 'secondary'): string {
  $cls = preg_replace('/[^a-z0-9_\-]/i','', $cls);
  return '<span class="badge bg-'.$cls.' me-1">'.$label.'</span>';
}

function hrl_tower_require_request(): void {
  if (!(bool)($GLOBALS['CAN_REQUEST'] ?? false)) {
    http_response_code(403);
    echo "<h3>Akses ditolak</h3><p>Hanya MANAGER atau ADMIN yang boleh membuat pengajuan.</p>";
    exit;
  }
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check_or_die();
  $action = (string)($_POST['action'] ?? '');
  $return = (string)($_POST['return'] ?? url_hrl('hrl_tower.php'));

  try {
    // 1) Pengajuan baru (auto SUBMITTED + version 1)
    if ($action === 'request_new') {
      hrl_tower_require_request();

      $unit = strtoupper(trim((string)($_POST['unit'] ?? 'HR')));
      $category = strtoupper(trim((string)($_POST['category'] ?? 'SOP')));
      $scope = strtoupper(trim((string)($_POST['scope'] ?? 'INTERNAL')));
      $title = trim((string)($_POST['title'] ?? ''));
      $doc_code_in = trim((string)($_POST['doc_code'] ?? ''));
      $tags = trim((string)($_POST['tags'] ?? ''));
      $desc = trim((string)($_POST['description'] ?? ''));
      $effective_date = trim((string)($_POST['effective_date'] ?? ''));

      $allowed_units = ['HR','LEGAL'];
      $allowed_cat = ['PP','SOP','FORM','CHECKLIST','PKS','CONTRACT','DOC','OTHER'];
      $allowed_scope = ['INTERNAL','EXTERNAL'];

      if (!in_array($unit, $allowed_units, true)) $unit = 'HR';
      if (!in_array($category, $allowed_cat, true)) $category = 'SOP';
      if (!in_array($scope, $allowed_scope, true)) $scope = 'INTERNAL';

      if ($title === '') throw new RuntimeException("Judul wajib diisi.");

      $doc_code = $doc_code_in !== '' ? hrl_tower_clean_code($doc_code_in) : hrl_tower_auto_code($unit, $category);

      // Upload file wajib
      if (empty($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("File dokumen wajib di-upload (PDF/DOCX/...).");
      }
      $tmp = (string)$_FILES['file']['tmp_name'];
      $orig = (string)$_FILES['file']['name'];
      $size = (int)$_FILES['file']['size'];
      $safeOrig = hrl_tower_safe_filename($orig);
      $ext = strtolower(pathinfo($safeOrig, PATHINFO_EXTENSION));
      if (!hrl_tower_allowed_ext($ext)) throw new RuntimeException("Ekstensi file tidak diizinkan: .$ext");

      // Pastikan doc_code unik
      $st = $pdo->prepare("SELECT COUNT(*) FROM hrl_docs WHERE doc_code=?");
      $st->execute([$doc_code]);
      if ((int)$st->fetchColumn() > 0) {
        throw new RuntimeException("Doc Code sudah dipakai. Silakan kosongkan Doc Code agar sistem auto-generate, atau pakai kode lain.");
      }

      // Owner dept: untuk manager departemen lain => pakai dept akun
      $owner_dept = strtoupper((string)($HRL_USER['department'] ?? ''));
      if ($owner_dept === '') $owner_dept = 'SYS';

      // Admin boleh override owner_dept via dropdown (opsional)
      if ($HRL_IS_ADMIN) {
        $od = strtoupper(trim((string)($_POST['owner_dept'] ?? '')));
        if ($od !== '') $owner_dept = preg_replace('/[^A-Z0-9_]/','', $od);
      }

      $folder = hrl_tower_safe_code_folder($doc_code);
      $dir = __DIR__ . '/../uploads/hrl/docs/' . $folder;
      if (!is_dir($dir)) @mkdir($dir, 0775, true);

      $newName = 'v1_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '_' . $safeOrig;
      $abs = $dir . '/' . $newName;
      if (!move_uploaded_file($tmp, $abs)) {
        throw new RuntimeException("Gagal menyimpan file upload.");
      }

      $rel = 'uploads/hrl/docs/' . $folder . '/' . $newName;
      $mime = @mime_content_type($abs) ?: '';
      $checksum = hash_file('sha256', $abs);

      $pdo->beginTransaction();

      $stI = $pdo->prepare("INSERT INTO hrl_docs
        (doc_code,title,unit,category,scope,owner_dept,status,current_version,effective_date,tags,description,created_by,created_at,updated_at,deleted_at)
        VALUES
        (?,?,?,?,?,?,'DRAFT',0,?,?,?, ?,NOW(),NOW(),NULL)
      ");
      $stI->execute([
        $doc_code,
        $title,
        $unit,
        $category,
        $scope,
        $owner_dept,
        ($effective_date === '' ? null : $effective_date),
        ($tags === '' ? null : $tags),
        ($desc === '' ? null : $desc),
        $HRL_USER['username']
      ]);
      $doc_id = (int)$pdo->lastInsertId();

      $stV = $pdo->prepare("INSERT INTO hrl_doc_versions
        (doc_id,version_no,file_name,file_path,mime_type,file_size,checksum,status,change_log,submitted_at,submitted_by,approved_at,approved_by,rejected_note,created_at,created_by,deleted_at)
        VALUES
        (?,?,?,?,?,?,?,'SUBMITTED',?,NOW(),?,NULL,NULL,NULL,NOW(),?,NULL)
      ");
      $change_log = trim((string)($_POST['change_log'] ?? 'Pengajuan baru'));
      $stV->execute([
        $doc_id, 1,
        $safeOrig,
        $rel,
        $mime,
        $size,
        $checksum,
        ($change_log === '' ? null : $change_log),
        $HRL_USER['username'],
        $HRL_USER['username'],
      ]);

      hrl_audit_append('tower_request_new', 'hrl_docs', [
        'doc_id'=>$doc_id,
        'doc_code'=>$doc_code,
        'owner_dept'=>$owner_dept,
        'unit'=>$unit,
        'category'=>$category,
      ]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'hrl_docs', 'hrl_docs', 'TOWER_REQUEST', $doc_id, $doc_code, "HRL tower request: {$doc_code}", []);
      }

      $pdo->commit();

      flash_set('success', "Pengajuan tersimpan. Status: SUBMITTED (menunggu TTD/approve HRL).");
      hrl_redirect($return);
    }

    // 2) Upload revisi (buat versi baru + auto SUBMITTED)
    if ($action === 'upload_revision') {
      hrl_tower_require_request();

      $doc_id = (int)($_POST['doc_id'] ?? 0);
      if ($doc_id <= 0) throw new RuntimeException("doc_id invalid.");

      // cek ownership (admin boleh semua)
      $st = $pdo->prepare("SELECT * FROM hrl_docs WHERE id=? AND deleted_at IS NULL LIMIT 1");
      $st->execute([$doc_id]);
      $doc = $st->fetch(PDO::FETCH_ASSOC);
      if (!$doc) throw new RuntimeException("Dokumen tidak ditemukan.");

      if (!$HRL_IS_ADMIN) {
        $owner = strtoupper((string)($doc['owner_dept'] ?? ''));
        $myDept = strtoupper((string)($HRL_USER['department'] ?? ''));
        if ($owner === '' || $myDept === '' || $owner !== $myDept) {
          throw new RuntimeException("Anda tidak punya akses untuk upload revisi dokumen departemen lain.");
        }
      }

      if (empty($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("File revisi wajib di-upload.");
      }
      $tmp = (string)$_FILES['file']['tmp_name'];
      $orig = (string)$_FILES['file']['name'];
      $size = (int)$_FILES['file']['size'];
      $safeOrig = hrl_tower_safe_filename($orig);
      $ext = strtolower(pathinfo($safeOrig, PATHINFO_EXTENSION));
      if (!hrl_tower_allowed_ext($ext)) throw new RuntimeException("Ekstensi file tidak diizinkan: .$ext");

      $doc_code = (string)$doc['doc_code'];
      $folder = hrl_tower_safe_code_folder($doc_code);
      $dir = __DIR__ . '/../uploads/hrl/docs/' . $folder;
      if (!is_dir($dir)) @mkdir($dir, 0775, true);

      // version_no berikutnya
      $stN = $pdo->prepare("SELECT COALESCE(MAX(version_no),0) FROM hrl_doc_versions WHERE doc_id=? AND deleted_at IS NULL");
      $stN->execute([$doc_id]);
      $next = (int)$stN->fetchColumn() + 1;

      $newName = 'v'.$next.'_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '_' . $safeOrig;
      $abs = $dir . '/' . $newName;
      if (!move_uploaded_file($tmp, $abs)) throw new RuntimeException("Gagal menyimpan file upload.");

      $rel = 'uploads/hrl/docs/' . $folder . '/' . $newName;
      $mime = @mime_content_type($abs) ?: '';
      $checksum = hash_file('sha256', $abs);

      $change_log = trim((string)($_POST['change_log'] ?? 'Revisi'));
      if ($change_log === '') $change_log = 'Revisi';

      $pdo->beginTransaction();

      $stV = $pdo->prepare("INSERT INTO hrl_doc_versions
        (doc_id,version_no,file_name,file_path,mime_type,file_size,checksum,status,change_log,submitted_at,submitted_by,approved_at,approved_by,rejected_note,created_at,created_by,deleted_at)
        VALUES
        (?,?,?,?,?,?,?,'SUBMITTED',?,NOW(),?,NULL,NULL,NULL,NOW(),?,NULL)
      ");
      $stV->execute([
        $doc_id, $next,
        $safeOrig, $rel, $mime, $size, $checksum,
        $change_log,
        $HRL_USER['username'],
        $HRL_USER['username'],
      ]);

      $pdo->prepare("UPDATE hrl_docs SET updated_at=NOW() WHERE id=?")->execute([$doc_id]);

      hrl_audit_append('tower_upload_revision', 'hrl_doc_versions', [
        'doc_id'=>$doc_id,
        'doc_code'=>$doc_code,
        'version_no'=>$next,
      ]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'hrl_docs', 'hrl_doc_versions', 'TOWER_REVISION', $doc_id, $doc_code, "HRL tower revision: {$doc_code} v{$next}", []);
      }

      $pdo->commit();

      flash_set('success', "Revisi ter-upload. Status: SUBMITTED (menunggu TTD/approve HRL).");
      hrl_redirect($return);
    }

    // 3) Approve / Reject / Set Active (TTD digital)
    if ($action === 'approve_version') {
      hrl_require_approve();

      $version_id = (int)($_POST['version_id'] ?? 0);
      $note = trim((string)($_POST['note'] ?? ''));

      $st = $pdo->prepare("SELECT * FROM hrl_doc_versions WHERE id=? AND deleted_at IS NULL LIMIT 1");
      $st->execute([$version_id]);
      $v = $st->fetch(PDO::FETCH_ASSOC);
      if (!$v) throw new RuntimeException("Versi tidak ditemukan.");

      if ($v['status'] !== 'SUBMITTED') throw new RuntimeException("Hanya versi SUBMITTED yang bisa di-approve.");

      $pdo->prepare("UPDATE hrl_doc_versions
        SET status='APPROVED', approved_at=NOW(), approved_by=?, rejected_note=?
        WHERE id=?")->execute([$HRL_USER['username'], ($note===''?null:$note), $version_id]);

      hrl_audit_append('tower_approve', 'hrl_doc_versions', ['version_id'=>$version_id, 'doc_id'=>$v['doc_id']]);
      if (function_exists('master_audit')) {
        $stD = $pdo->prepare("SELECT doc_code FROM hrl_docs WHERE id=?");
        $stD->execute([$v['doc_id']]);
        $dc = (string)($stD->fetchColumn() ?: '');
        master_audit($pdo, 'hrl_docs', 'hrl_doc_versions', 'TOWER_APPROVE', (int)$v['doc_id'], $dc, "HRL tower approve: {$dc}", []);
      }
      flash_set('success', "Approved (TTD digital) berhasil.");
      hrl_redirect($return);
    }

    if ($action === 'reject_version') {
      hrl_require_approve();

      $version_id = (int)($_POST['version_id'] ?? 0);
      $note = trim((string)($_POST['note'] ?? ''));
      if ($note === '') $note = 'Perlu perbaikan.';

      $st = $pdo->prepare("SELECT * FROM hrl_doc_versions WHERE id=? AND deleted_at IS NULL LIMIT 1");
      $st->execute([$version_id]);
      $v = $st->fetch(PDO::FETCH_ASSOC);
      if (!$v) throw new RuntimeException("Versi tidak ditemukan.");

      if ($v['status'] !== 'SUBMITTED') throw new RuntimeException("Hanya versi SUBMITTED yang bisa di-reject.");

      $pdo->prepare("UPDATE hrl_doc_versions
        SET status='REJECTED', rejected_note=?, approved_at=NULL, approved_by=NULL
        WHERE id=?")->execute([$note, $version_id]);

      hrl_audit_append('tower_reject', 'hrl_doc_versions', ['version_id'=>$version_id, 'doc_id'=>$v['doc_id']]);
      if (function_exists('master_audit')) {
        $stD = $pdo->prepare("SELECT doc_code FROM hrl_docs WHERE id=?");
        $stD->execute([$v['doc_id']]);
        $dc = (string)($stD->fetchColumn() ?: '');
        master_audit($pdo, 'hrl_docs', 'hrl_doc_versions', 'TOWER_REJECT', (int)$v['doc_id'], $dc, "HRL tower reject: {$dc}", []);
      }
      flash_set('warning', "Versi ditolak. Pengaju perlu upload revisi.");
      hrl_redirect($return);
    }

    if ($action === 'set_active') {
      hrl_require_approve();

      $doc_id = (int)($_POST['doc_id'] ?? 0);
      $version_no = (int)($_POST['version_no'] ?? 0);
      if ($doc_id <= 0 || $version_no <= 0) throw new RuntimeException("doc_id/version_no invalid.");

      // pastikan versi approved
      $st = $pdo->prepare("SELECT status FROM hrl_doc_versions WHERE doc_id=? AND version_no=? AND deleted_at IS NULL LIMIT 1");
      $st->execute([$doc_id, $version_no]);
      $stt = (string)($st->fetchColumn() ?? '');
      if ($stt !== 'APPROVED') throw new RuntimeException("Hanya versi APPROVED yang bisa diaktifkan.");

      // Flow gate: publish hanya jika ada bukti baca-setuju minimal 1 user.
      $stAck = $pdo->prepare("SELECT COUNT(*) FROM hrl_doc_acks WHERE doc_id=? AND version_no=?");
      $stAck->execute([$doc_id, $version_no]);
      if ((int)$stAck->fetchColumn() <= 0) {
        throw new RuntimeException("Belum ada ACK baca & setuju untuk versi ini. Minta user baca dulu sebelum Set ACTIVE.");
      }

      $pdo->prepare("UPDATE hrl_docs SET current_version=?, status='ACTIVE', updated_at=NOW() WHERE id=?")->execute([$version_no, $doc_id]);

      hrl_audit_append('tower_set_active', 'hrl_docs', ['doc_id'=>$doc_id, 'version_no'=>$version_no]);
      if (function_exists('master_audit')) {
        $stD = $pdo->prepare("SELECT doc_code FROM hrl_docs WHERE id=?");
        $stD->execute([$doc_id]);
        $dc = (string)($stD->fetchColumn() ?: '');
        master_audit($pdo, 'hrl_docs', 'hrl_docs', 'TOWER_SET_ACTIVE', $doc_id, $dc, "HRL tower set active: {$dc} v{$version_no}", []);
      }
      flash_set('success', "Dokumen diaktifkan (ACTIVE) ke versi {$version_no}.");
      hrl_redirect($return);
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('danger', "Error: " . $e->getMessage());
    hrl_redirect($return);
  }
}

// ---- Filters ----
$unit = strtoupper(trim((string)($_GET['unit'] ?? '')));
$status = strtoupper(trim((string)($_GET['vstatus'] ?? '')));
$dept = strtoupper(trim((string)($_GET['dept'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));

// Base query: latest version per doc
$params = [];
$where = "WHERE d.deleted_at IS NULL";

// Scope visibility:
// - HRL manage/approve: lihat semua
// - Selain itu: hanya lihat dokumen owner_dept = dept user (agar tidak bocor antar departemen)
if (!$HRL_CAN_MANAGE && !$HRL_CAN_APPROVE) {
  $where .= " AND d.owner_dept = :mydept";
  $params[':mydept'] = $HRL_USER['department'];
}

if ($unit !== '' && in_array($unit, ['HR','LEGAL'], true)) {
  $where .= " AND d.unit = :unit";
  $params[':unit'] = $unit;
}
if ($dept !== '') {
  $where .= " AND d.owner_dept = :dept";
  $params[':dept'] = preg_replace('/[^A-Z0-9_]/','', $dept);
}
if ($status !== '' && in_array($status, ['DRAFT','SUBMITTED','APPROVED','REJECTED'], true)) {
  $where .= " AND v.status = :vstatus";
  $params[':vstatus'] = $status;
}
if ($q !== '') {
  $where .= " AND (d.doc_code LIKE :q OR d.title LIKE :q OR d.category LIKE :q OR d.owner_dept LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}

$sql = "
  SELECT
    d.id AS doc_id,
    d.doc_code, d.title, d.unit, d.category, d.scope, d.owner_dept,
    d.status AS doc_status, d.current_version, d.updated_at,
    v.id AS version_id,
    v.version_no, v.status AS version_status,
    v.submitted_at, v.submitted_by,
    v.approved_at, v.approved_by,
    v.rejected_note,
    (SELECT COUNT(*) FROM hrl_doc_acks a WHERE a.doc_id=d.id AND a.version_no=d.current_version) AS ack_count
  FROM hrl_docs d
  LEFT JOIN hrl_doc_versions v ON v.doc_id=d.id
    AND v.deleted_at IS NULL
    AND v.version_no = (
      SELECT MAX(v2.version_no) FROM hrl_doc_versions v2
      WHERE v2.doc_id=d.id AND v2.deleted_at IS NULL
    )
  $where
  ORDER BY d.updated_at DESC, d.id DESC
  LIMIT 2000
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="row g-3">
  <?php if ($CAN_REQUEST): ?>
  <div class="col-lg-4">
    <div class="rmi-card">
      <div class="rmi-card-header">
        <div class="fw-semibold">Pengajuan Dokumen (Departemen)</div>
        <div class="small text-muted">Upload dokumen & otomatis SUBMITTED. HRL akan TTD digital (approve) lalu set ACTIVE.</div>
      </div>
      <div class="rmi-card-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
          <input type="hidden" name="action" value="request_new">
          <input type="hidden" name="return" value="<?=e(url_hrl('hrl_tower.php'))?>">

          <div class="mb-2">
            <label class="form-label">Doc Code (opsional)</label>
            <input class="form-control" name="doc_code" placeholder="kosongkan untuk auto">
          </div>

          <div class="mb-2">
            <label class="form-label">Judul *</label>
            <input class="form-control" name="title" required>
          </div>

          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Unit</label>
              <select class="form-select" name="unit">
                <option value="HR">HR</option>
                <option value="LEGAL">LEGAL</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Kategori</label>
              <select class="form-select" name="category">
                <?php foreach (['PP','SOP','FORM','CHECKLIST','PKS','CONTRACT','DOC','OTHER'] as $c): ?>
                  <option value="<?=e($c)?>"><?=e($c)?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="row g-2 mt-0">
            <div class="col-6">
              <label class="form-label">Scope</label>
              <select class="form-select" name="scope">
                <option value="INTERNAL">INTERNAL</option>
                <option value="EXTERNAL">EXTERNAL</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Effective Date (opsional)</label>
              <input class="form-control" name="effective_date" placeholder="YYYY-MM-DD">
            </div>
          </div>

          <?php if ($HRL_IS_ADMIN): ?>
            <div class="mt-2">
              <label class="form-label">Owner Dept (optional override)</label>
              <input class="form-control" name="owner_dept" placeholder="contoh: CRM / FIN / ITC / HRL">
              <div class="small text-muted">Jika kosong, pakai dept akun pengaju.</div>
            </div>
          <?php endif; ?>

          <div class="mt-2">
            <label class="form-label">Tags (opsional)</label>
            <input class="form-control" name="tags" placeholder="contoh: sop, internal, audit">
          </div>

          <div class="mt-2">
            <label class="form-label">Deskripsi (opsional)</label>
            <textarea class="form-control" name="description" rows="3"></textarea>
          </div>

          <div class="mt-2">
            <label class="form-label">Change log (opsional)</label>
            <input class="form-control" name="change_log" placeholder="contoh: pengajuan SOP baru">
          </div>

          <div class="mt-2">
            <label class="form-label">File *</label>
            <input type="file" class="form-control" name="file" required>
            <div class="small text-muted">Disarankan PDF/DOCX. File disimpan ke uploads/hrl/docs/...</div>
          </div>

          <button class="btn btn-primary w-100 mt-3">Kirim Pengajuan</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="<?= $CAN_REQUEST ? 'col-lg-8' : 'col-12' ?>">
    <div class="rmi-card">
      <div class="rmi-card-header d-flex justify-content-between align-items-center">
        <div>
          <div class="fw-semibold">HRL Control Tower</div>
          <div class="small text-muted">Pantau pengajuan lintas departemen → TTD digital (approve) → ACTIVE.</div>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-outline-light btn-sm" href="<?=e(url_hrl('hrl_docs.php'))?>">Ke HRL Docs</a>
        </div>
      </div>

      <div class="rmi-card-body">
        <form class="row g-2 mb-3" method="get">
          <div class="col-md-2">
            <select class="form-select form-select-sm" name="unit">
              <option value="">Unit (All)</option>
              <option value="HR" <?= $unit==='HR'?'selected':'' ?>>HR</option>
              <option value="LEGAL" <?= $unit==='LEGAL'?'selected':'' ?>>LEGAL</option>
            </select>
          </div>
          <div class="col-md-2">
            <select class="form-select form-select-sm" name="vstatus">
              <option value="">Status (All)</option>
              <?php foreach (['DRAFT','SUBMITTED','APPROVED','REJECTED'] as $s): ?>
                <option value="<?=e($s)?>" <?= $status===$s?'selected':'' ?>><?=e($s)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <input class="form-control form-control-sm" name="dept" placeholder="Dept (optional)" value="<?=e($dept)?>">
          </div>
          <div class="col-md-4">
            <input class="form-control form-control-sm" name="q" placeholder="Search (code/title/category/dept)" value="<?=e($q)?>">
          </div>
          <div class="col-md-2 d-grid">
            <button class="btn btn-sm btn-outline-light">Apply</button>
          </div>
        </form>

        <div class="table-responsive">
          <table id="towerTable" class="table table-sm table-dark table-striped align-middle">
            <thead>
              <tr>
                <th>Doc</th>
                <th>Dept</th>
                <th>Unit</th>
                <th>Status</th>
                <th>Milestone</th>
                <th>TTD Digital</th>
                <th>Ack</th>
                <th>Updated</th>
                <th style="width:210px">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                $docId = (int)$r['doc_id'];
                $verId = (int)($r['version_id'] ?? 0);
                $verNo = (int)($r['version_no'] ?? 0);
                $vst = (string)($r['version_status'] ?? 'DRAFT');
                $dst = (string)($r['doc_status'] ?? 'DRAFT');

                $submitted_ok = !empty($r['submitted_at']) || in_array($vst, ['SUBMITTED','APPROVED','REJECTED'], true);
                $active_ok = ($dst === 'ACTIVE' && (int)$r['current_version'] === $verNo && $verNo > 0);
                $approve_ok = ($vst === 'APPROVED' || $active_ok);

                $needs_approve = ($vst === 'SUBMITTED');
                $needs_active = ($vst === 'APPROVED' && (int)$r['current_version'] !== $verNo);

                $mil = '';
                $mil .= hrl_tower_badge('SUBMIT', $submitted_ok ? 'success' : 'secondary');
                $mil .= hrl_tower_badge('TTD', $approve_ok ? 'success' : 'secondary');
                $mil .= hrl_tower_badge('ACTIVE', $active_ok ? 'success' : ($needs_active ? 'warning' : 'secondary'));
              ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?=e($r['doc_code'])?></div>
                    <div class="small text-muted"><?=e($r['title'])?></div>
                    <div class="small text-muted">v<?= (int)$verNo ?> • <?=e($r['category'])?> • <?=e($r['scope'])?></div>
                  </td>
                  <td><?=e((string)$r['owner_dept'])?></td>
                  <td><?=e((string)$r['unit'])?></td>
                  <td>
                    <?= hrl_tower_badge('DOC:'.$dst, $dst==='ACTIVE'?'success':($dst==='OBSOLETE'?'secondary':'warning')) ?>
                    <?= hrl_tower_badge('VER:'.$vst, $vst==='SUBMITTED'?'warning':($vst==='APPROVED'?'success':($vst==='REJECTED'?'danger':'secondary'))) ?>
                  </td>
                  <td><?= $mil ?></td>
                  <td class="small">
                    <?php if (!empty($r['approved_by'])): ?>
                      <div class="fw-semibold"><?=e((string)$r['approved_by'])?></div>
                      <div class="text-muted"><?=e((string)$r['approved_at'])?></div>
                    <?php else: ?>
                      <span class="text-muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td><?= (int)($r['ack_count'] ?? 0) ?></td>
                  <td class="small"><?=e((string)$r['updated_at'])?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-light" href="<?=e(url_hrl('hrl_doc_view.php?id='.$docId))?>">Open</a>

                    <?php if ($HRL_CAN_APPROVE && $needs_approve && $verId>0): ?>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
                        <input type="hidden" name="action" value="approve_version">
                        <input type="hidden" name="return" value="<?=e(url_hrl('hrl_tower.php'))?>">
                        <input type="hidden" name="version_id" value="<?= (int)$verId ?>">
                        <button class="btn btn-sm btn-success" onclick="return confirm('TTD digital / Approve versi ini?')">TTD</button>
                      </form>
                      <button class="btn btn-sm btn-danger" onclick="hrlTowerReject(<?= (int)$verId ?>)">Reject</button>
                    <?php endif; ?>

                    <?php if ($HRL_CAN_APPROVE && $needs_active && $verNo>0): ?>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
                        <input type="hidden" name="action" value="set_active">
                        <input type="hidden" name="return" value="<?=e(url_hrl('hrl_tower.php'))?>">
                        <input type="hidden" name="doc_id" value="<?= (int)$docId ?>">
                        <input type="hidden" name="version_no" value="<?= (int)$verNo ?>">
                        <button class="btn btn-sm btn-warning" onclick="return confirm('Set ACTIVE ke versi <?= (int)$verNo ?>?')">Set Active</button>
                      </form>
                    <?php endif; ?>

                    <?php if ($CAN_REQUEST && $vst==='REJECTED'): ?>
                      <button class="btn btn-sm btn-outline-info" onclick="hrlTowerUpload(<?= (int)$docId ?>,'<?=e($r['doc_code'])?>')">Upload Revisi</button>
                    <?php endif; ?>

                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content bg-dark text-white">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Reject (catatan)</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
          <input type="hidden" name="action" value="reject_version">
          <input type="hidden" name="return" value="<?=e(url_hrl('hrl_tower.php'))?>">
          <input type="hidden" name="version_id" id="reject_version_id" value="">
          <label class="form-label">Catatan perbaikan</label>
          <textarea class="form-control" name="note" rows="3" placeholder="contoh: perbaiki format, tambahkan pasal, dll" required></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Batal</button>
          <button class="btn btn-danger">Reject</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Upload revisi modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content bg-dark text-white">
      <form method="post" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title">Upload Revisi</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
          <input type="hidden" name="action" value="upload_revision">
          <input type="hidden" name="return" value="<?=e(url_hrl('hrl_tower.php'))?>">
          <input type="hidden" name="doc_id" id="upload_doc_id" value="">
          <div class="mb-2 small text-muted" id="upload_doc_label"></div>

          <label class="form-label">Change log</label>
          <input class="form-control" name="change_log" placeholder="contoh: revisi sesuai catatan HRL" required>

          <label class="form-label mt-2">File revisi</label>
          <input type="file" class="form-control" name="file" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Batal</button>
          <button class="btn btn-info">Upload & Submit</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function hrlTowerReject(versionId){
  document.getElementById('reject_version_id').value = versionId;
  var m = new bootstrap.Modal(document.getElementById('rejectModal'));
  m.show();
}
function hrlTowerUpload(docId, docCode){
  document.getElementById('upload_doc_id').value = docId;
  document.getElementById('upload_doc_label').innerText = 'Doc: ' + docCode + ' (ID ' + docId + ')';
  var m = new bootstrap.Modal(document.getElementById('uploadModal'));
  m.show();
}

$(function(){
  try {
    $('#towerTable').DataTable({
      pageLength: 25,
      order: [[7,'desc']],
      dom: 'Bfrtip',
      buttons: ['copy','csv','excel','pdf','print']
    });
  } catch(e){}
});
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
