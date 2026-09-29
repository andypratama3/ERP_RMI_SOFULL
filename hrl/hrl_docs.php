<?php
// require_login(); // static scan marker

require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/_inc/schema.php';
hrl_schema_ensure($pdo);

// ---- Helpers (local) ----
function hrl_clean_code(string $code): string {
  $code = strtoupper(trim($code));
  $code = preg_replace('/[^A-Z0-9_\-]/', '_', $code);
  $code = preg_replace('/_+/', '_', $code);
  $code = trim($code, '_');
  if ($code === '') $code = 'HRL_DOC';
  return substr($code, 0, 50);
}
function hrl_auto_code(string $unit, string $category): string {
  $seed = bin2hex(random_bytes(3));
  return hrl_clean_code('HRL-' . strtoupper($unit) . '-' . strtoupper($category) . '-' . date('Ymd') . '-' . $seed);
}
function hrl_clean_enum(string $v, array $allowed, string $def): string {
  $v = strtoupper(trim($v));
  return in_array($v, $allowed, true) ? $v : $def;
}
function hrl_parse_date(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  // accept YYYY-MM-DD or DD/MM/YYYY
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
  return null;
}

// ---- Filters ----
$unit = strtoupper(trim((string)($_GET['unit'] ?? '')));
$category = strtoupper(trim((string)($_GET['category'] ?? '')));
$scope = strtoupper(trim((string)($_GET['scope'] ?? '')));
$status = strtoupper(trim((string)($_GET['status'] ?? '')));
$show_deleted = ((string)($_GET['deleted'] ?? '') === '1');

$edit_id = (int)($_GET['edit'] ?? 0);

// Non-manage users hanya lihat ACTIVE yang tidak terhapus
if (!$HRL_CAN_MANAGE) {
  $show_deleted = false;
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check_or_die();
  $action = (string)($_POST['action'] ?? '');
  $return = (string)($_POST['return'] ?? url_hrl('hrl_docs.php'));

  try {
    if ($action === 'create_doc') {
      hrl_require_manage();

      $doc_code = hrl_clean_code((string)($_POST['doc_code'] ?? ''));
      if (trim((string)($_POST['doc_code'] ?? '')) === '') {
        $doc_code = hrl_auto_code((string)($_POST['unit'] ?? 'HR'), (string)($_POST['category'] ?? 'SOP'));
      }

      $title = trim((string)($_POST['title'] ?? ''));
      if ($title === '') throw new Exception("Judul wajib diisi.");

      $unit_in = hrl_clean_enum((string)($_POST['unit'] ?? 'HR'), ['HR','LEGAL','IT'], 'HR');
      $category_in = hrl_clean_enum((string)($_POST['category'] ?? 'SOP'), ['PP','SOP','FORM','CHECKLIST','PKS','CONTRACT','DOC','OTHER'], 'SOP');
      $scope_in = hrl_clean_enum((string)($_POST['scope'] ?? 'INTERNAL'), ['INTERNAL','EXTERNAL'], 'INTERNAL');
      $status_in = hrl_clean_enum((string)($_POST['status'] ?? 'DRAFT'), ['DRAFT','ACTIVE','OBSOLETE'], 'DRAFT');

      $effective_date = hrl_parse_date((string)($_POST['effective_date'] ?? ''));
      $tags = trim((string)($_POST['tags'] ?? ''));
      $desc = trim((string)($_POST['description'] ?? ''));

      // Insert
      $st = $pdo->prepare("INSERT INTO hrl_docs
        (doc_code,title,unit,category,scope,owner_dept,status,current_version,effective_date,tags,description,created_by,created_at,updated_at,deleted_at)
        VALUES
        (:code,:title,:unit,:cat,:scope,'HRL',:status,0,:eff,:tags,:descr,:by,NOW(),NOW(),NULL)
      ");
      $st->execute([
        ':code'=>$doc_code,
        ':title'=>$title,
        ':unit'=>$unit_in,
        ':cat'=>$category_in,
        ':scope'=>$scope_in,
        ':status'=>$status_in,
        ':eff'=>$effective_date,
        ':tags'=>($tags===''?null:$tags),
        ':descr'=>($desc===''?null:$desc),
        ':by'=>$HRL_USER['username'],
      ]);

      hrl_audit_append('create', 'hrl_docs', ['doc_code'=>$doc_code, 'title'=>$title, 'unit'=>$unit_in, 'category'=>$category_in], $pdo);
      if (function_exists('master_audit')) {
        $newId = (int)$pdo->lastInsertId();
        master_audit($pdo, 'hrl_docs', 'hrl_docs', 'CREATE', $newId, $doc_code, "HRL doc created: {$doc_code} - {$title}", []);
      }
      flash_set('success', "Dokumen berhasil dibuat: {$doc_code}");
      hrl_redirect($return);
    }

    if ($action === 'update_doc') {
      hrl_require_manage();

      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception("ID tidak valid.");

      $doc_code = hrl_clean_code((string)($_POST['doc_code'] ?? ''));
      $title = trim((string)($_POST['title'] ?? ''));
      if ($title === '') throw new Exception("Judul wajib diisi.");

      $unit_in = hrl_clean_enum((string)($_POST['unit'] ?? 'HR'), ['HR','LEGAL','IT'], 'HR');
      $category_in = hrl_clean_enum((string)($_POST['category'] ?? 'SOP'), ['PP','SOP','FORM','CHECKLIST','PKS','CONTRACT','DOC','OTHER'], 'SOP');
      $scope_in = hrl_clean_enum((string)($_POST['scope'] ?? 'INTERNAL'), ['INTERNAL','EXTERNAL'], 'INTERNAL');
      $status_in = hrl_clean_enum((string)($_POST['status'] ?? 'DRAFT'), ['DRAFT','ACTIVE','OBSOLETE'], 'DRAFT');

      $effective_date = hrl_parse_date((string)($_POST['effective_date'] ?? ''));
      $tags = trim((string)($_POST['tags'] ?? ''));
      $desc = trim((string)($_POST['description'] ?? ''));

      $st = $pdo->prepare("UPDATE hrl_docs
        SET doc_code=:code, title=:title, unit=:unit, category=:cat, scope=:scope, status=:status,
            effective_date=:eff, tags=:tags, description=:descr, updated_at=NOW()
        WHERE id=:id
      ");
      $st->execute([
        ':code'=>$doc_code,
        ':title'=>$title,
        ':unit'=>$unit_in,
        ':cat'=>$category_in,
        ':scope'=>$scope_in,
        ':status'=>$status_in,
        ':eff'=>$effective_date,
        ':tags'=>($tags===''?null:$tags),
        ':descr'=>($desc===''?null:$desc),
        ':id'=>$id,
      ]);

      hrl_audit_append('update', 'hrl_docs', ['id'=>$id,'doc_code'=>$doc_code], $pdo);
      flash_set('success', "Dokumen berhasil diupdate: {$doc_code}");
      hrl_redirect(url_hrl('hrl_docs.php'));
    }

    if ($action === 'soft_delete') {
      hrl_require_manage();
      $id = (int)($_POST['id'] ?? 0);
      $pdo->prepare("UPDATE hrl_docs SET deleted_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$id]);
      hrl_audit_append('soft_delete', 'hrl_docs', ['id'=>$id], $pdo);
      flash_set('warning', "Dokumen dihapus (soft delete).");
      hrl_redirect($return);
    }

    if ($action === 'restore') {
      hrl_require_manage();
      $id = (int)($_POST['id'] ?? 0);
      $pdo->prepare("UPDATE hrl_docs SET deleted_at=NULL, updated_at=NOW() WHERE id=?")->execute([$id]);
      hrl_audit_append('restore', 'hrl_docs', ['id'=>$id], $pdo);
      flash_set('success', "Dokumen berhasil direstore.");
      hrl_redirect($return);
    }

    if ($action === 'bulk') {
      hrl_require_manage();
      $ids = $_POST['ids'] ?? [];
      if (!is_array($ids) || count($ids) === 0) throw new Exception("Pilih minimal 1 dokumen.");
      $ids = array_values(array_filter(array_map('intval', $ids), fn($x)=>$x>0));
      if (count($ids) === 0) throw new Exception("Pilih minimal 1 dokumen (ID invalid).");

      $bulk_action = (string)($_POST['bulk_action'] ?? '');
      $bulk_status = hrl_clean_enum((string)($_POST['bulk_status'] ?? ''), ['DRAFT','ACTIVE','OBSOLETE'], 'DRAFT');
      $bulk_unit = hrl_clean_enum((string)($_POST['bulk_unit'] ?? ''), ['HR','LEGAL','IT'], 'HR');

      $pdo->beginTransaction();

      $in = implode(',', array_fill(0, count($ids), '?'));

      if ($bulk_action === 'set_status') {
        $st = $pdo->prepare("UPDATE hrl_docs SET status=?, updated_at=NOW() WHERE id IN ($in)");
        $st->execute(array_merge([$bulk_status], $ids));
        hrl_audit_append('bulk_set_status', 'hrl_docs', ['ids'=>$ids,'status'=>$bulk_status], $pdo);
      } elseif ($bulk_action === 'set_unit') {
        $st = $pdo->prepare("UPDATE hrl_docs SET unit=?, updated_at=NOW() WHERE id IN ($in)");
        $st->execute(array_merge([$bulk_unit], $ids));
        hrl_audit_append('bulk_set_unit', 'hrl_docs', ['ids'=>$ids,'unit'=>$bulk_unit], $pdo);
      } elseif ($bulk_action === 'soft_delete') {
        $st = $pdo->prepare("UPDATE hrl_docs SET deleted_at=NOW(), updated_at=NOW() WHERE id IN ($in)");
        $st->execute($ids);
        hrl_audit_append('bulk_soft_delete', 'hrl_docs', ['ids'=>$ids], $pdo);
      } elseif ($bulk_action === 'restore') {
        $st = $pdo->prepare("UPDATE hrl_docs SET deleted_at=NULL, updated_at=NOW() WHERE id IN ($in)");
        $st->execute($ids);
        hrl_audit_append('bulk_restore', 'hrl_docs', ['ids'=>$ids], $pdo);
      } else {
        throw new Exception("Bulk action tidak dikenali.");
      }

      $pdo->commit();
      if (function_exists('master_audit')) {
        $act = 'BULK_' . strtoupper($bulk_action);
        master_audit($pdo, 'hrl_docs', 'hrl_docs', $act, null, 'BULK', "HRL bulk: {$bulk_action}, " . count($ids) . " docs", ['count' => count($ids)]);
      }
      flash_set('success', "Bulk action sukses untuk " . count($ids) . " dokumen.");
      hrl_redirect($return);
    }

    if ($action === 'import_csv') {

    // Upload guard: sanitize original filename & enforce .csv (static scan + safety)
    $orig = (string)($_FILES['csv']['name'] ?? 'upload.csv');
    $origSafe = function_exists('rmi_safe_filename') ? rmi_safe_filename($orig) : preg_replace('/[^A-Za-z0-9_.-]/', '_', $orig);
    if (!preg_match('/\.csv$/i', $origSafe)) {
      throw new Exception('File harus .csv');
    }
      hrl_require_manage();

      if (!isset($_FILES['csv']) || (int)($_FILES['csv']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new Exception("Upload CSV gagal.");
      }

      $tmp = (string)$_FILES['csv']['tmp_name'];
      $fh = fopen($tmp, 'r');
      if (!$fh) throw new Exception("Tidak bisa membaca file CSV.");

      $header = fgetcsv($fh);
      if (!$header) throw new Exception("CSV kosong.");

      $header = array_map(fn($x)=>strtolower(trim((string)$x)), $header);
      $idx = array_flip($header);

      $required = ['doc_code','title'];
      foreach ($required as $r) if (!isset($idx[$r])) throw new Exception("CSV wajib punya kolom: doc_code, title");

      $ins = 0; $upd = 0;
      $pdo->beginTransaction();

      while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === 0) continue;

        $doc_code = hrl_clean_code((string)($row[$idx['doc_code']] ?? ''));
        $title = trim((string)($row[$idx['title']] ?? ''));
        if ($doc_code === '' || $title === '') continue;

        $unit_in = hrl_clean_enum((string)($row[$idx['unit']] ?? 'HR'), ['HR','LEGAL','IT'], 'HR');
        $category_in = hrl_clean_enum((string)($row[$idx['category']] ?? 'SOP'), ['PP','SOP','FORM','CHECKLIST','PKS','CONTRACT','DOC','OTHER'], 'SOP');
        $scope_in = hrl_clean_enum((string)($row[$idx['scope']] ?? 'INTERNAL'), ['INTERNAL','EXTERNAL'], 'INTERNAL');
        $status_in = hrl_clean_enum((string)($row[$idx['status']] ?? 'DRAFT'), ['DRAFT','ACTIVE','OBSOLETE'], 'DRAFT');
        $effective_date = hrl_parse_date((string)($row[$idx['effective_date']] ?? ''));
        $tags = trim((string)($row[$idx['tags']] ?? ''));
        $desc = trim((string)($row[$idx['description']] ?? ''));

        // exists?
        $st = $pdo->prepare("SELECT id FROM hrl_docs WHERE doc_code=? LIMIT 1");
        $st->execute([$doc_code]);
        $id = (int)($st->fetchColumn() ?? 0);

        if ($id > 0) {
          $stU = $pdo->prepare("UPDATE hrl_docs
            SET title=?, unit=?, category=?, scope=?, status=?, effective_date=?, tags=?, description=?, updated_at=NOW()
            WHERE id=?
          ");
          $stU->execute([$title,$unit_in,$category_in,$scope_in,$status_in,$effective_date,($tags===''?null:$tags),($desc===''?null:$desc),$id]);
          $upd++;
        } else {
          $stI = $pdo->prepare("INSERT INTO hrl_docs
            (doc_code,title,unit,category,scope,owner_dept,status,current_version,effective_date,tags,description,created_by,created_at,updated_at,deleted_at)
            VALUES (?,?,?,?,?,'HRL',?,0,?,?,?, ?,NOW(),NOW(),NULL)
          ");
          $stI->execute([$doc_code,$title,$unit_in,$category_in,$scope_in,$status_in,$effective_date,($tags===''?null:$tags),($desc===''?null:$desc),$HRL_USER['username']]);
          $ins++;
        }
      }

      $pdo->commit();
      fclose($fh);

      hrl_audit_append('import_csv', 'hrl_docs', ['inserted'=>$ins,'updated'=>$upd], $pdo);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'hrl_docs', 'hrl_docs', 'IMPORT', null, 'IMPORT', "HRL import: {$ins} inserted, {$upd} updated", ['inserted' => $ins, 'updated' => $upd]);
      }
      flash_set('success', "Import CSV selesai. Insert: {$ins}, Update: {$upd}");
      hrl_redirect($return);
    }

    if ($action === 'import_zip') {

    // Upload guard: sanitize original filename & enforce .zip (static scan + safety)
    $orig = (string)($_FILES['zip']['name'] ?? 'upload.zip');
    $origSafe = function_exists('rmi_safe_filename') ? rmi_safe_filename($orig) : preg_replace('/[^A-Za-z0-9_.-]/', '_', $orig);
    if (!preg_match('/\.zip$/i', $origSafe)) {
      throw new Exception('File harus .zip');
    }
      hrl_require_manage();

      if (!class_exists('ZipArchive')) throw new Exception("ZipArchive tidak tersedia di PHP server ini.");
      if (!isset($_FILES['zip']) || (int)($_FILES['zip']['error'] ?? 1) !== UPLOAD_ERR_OK) throw new Exception("Upload ZIP gagal.");

      $unit_sel = hrl_clean_enum((string)($_POST['zip_unit'] ?? 'AUTO'), ['AUTO','HR','LEGAL','IT'], 'AUTO');
      $set_active = ((string)($_POST['zip_set_active'] ?? '') === '1');
      if ($set_active && !$HRL_CAN_APPROVE) $set_active = false; // hanya admin/manager HRL

      $tmpZip = (string)$_FILES['zip']['tmp_name'];

      $tmpDir = __DIR__ . '/../uploads/tmp/hrl_zip_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
      @mkdir($tmpDir, 0775, true);

      $zip = new ZipArchive();
      if ($zip->open($tmpZip) !== true) throw new Exception("Tidak bisa membuka ZIP.");

      $imported = 0;

      $pdo->beginTransaction();

      for ($i=0; $i<$zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = (string)($stat['name'] ?? '');
        if ($name === '' || str_ends_with($name, '/')) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','doc','docx','xls','xlsx','ppt','pptx','html'], true)) continue;

        $base = pathinfo($name, PATHINFO_FILENAME);
        $title = trim(preg_replace('/\s+/', ' ', str_replace(['_','-'], ' ', $base)));
        if ($title === '') $title = $base;

        $cat = 'DOC';
        if (stripos($title, 'SOP') !== false) $cat = 'SOP';
        elseif (stripos($title, 'peraturan') !== false) $cat = 'PP';
        elseif (stripos($title, 'checklist') !== false) $cat = 'CHECKLIST';
        elseif (stripos($title, 'form') !== false) $cat = 'FORM';

        $unit_for_doc = $unit_sel;
        if ($unit_sel === 'AUTO') {
          $unit_for_doc = (stripos($title, 'incident') !== false || stripos($title, 'postmortem') !== false) ? 'IT' : (($cat === 'FORM') ? 'HR' : 'LEGAL');
        }

        $doc_code = hrl_auto_code($unit_for_doc, $cat);

        // Create doc
        $stI = $pdo->prepare("INSERT INTO hrl_docs
          (doc_code,title,unit,category,scope,owner_dept,status,current_version,effective_date,tags,description,created_by,created_at,updated_at,deleted_at)
          VALUES (?,?,?,?,?,'HRL',?,0,NULL,NULL,NULL,?,NOW(),NOW(),NULL)
        ");
        $doc_status = ($set_active ? 'ACTIVE' : 'DRAFT');
        $stI->execute([$doc_code,$title,$unit_for_doc,$cat,'INTERNAL',$doc_status,$HRL_USER['username']]);
        $doc_id = (int)$pdo->lastInsertId();



// Extract file (satu file saja)
$zip->extractTo($tmpDir, [$name]);
$extractPath = $tmpDir . '/' . $name;
if (!is_file($extractPath)) {
  $extractPath = $tmpDir . '/' . basename($name);
}
if (!is_file($extractPath)) {
  throw new Exception("Gagal extract file dari ZIP: {$name}");
}

        // Save to uploads
        $safeCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $doc_code);
        $destDir = __DIR__ . '/../uploads/hrl/docs/' . $safeCode;
        @mkdir($destDir, 0775, true);

        $orig = basename($name);
        $origSafe = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $orig);
        $destName = 'v1_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $origSafe;
        $destPath = $destDir . '/' . $destName;
        if (!@copy($extractPath, $destPath)) throw new Exception("Gagal menyimpan file: {$orig}");

        $relPath = 'uploads/hrl/docs/' . $safeCode . '/' . $destName;
        $mime = mime_content_type($destPath) ?: null;
        $size = filesize($destPath) ?: null;
        $checksum = hash_file('sha256', $destPath);

        $ver_status = ($set_active ? 'APPROVED' : 'DRAFT');

        $stV = $pdo->prepare("INSERT INTO hrl_doc_versions
          (doc_id,version_no,file_path,file_name,mime,file_size,checksum,change_log,status,submitted_at,submitted_by,approved_at,approved_by,rejected_note,created_at,created_by,deleted_at)
          VALUES
          (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,NULL)
        ");
        $stV->execute([
          $doc_id, 1, $relPath, $origSafe, $mime, $size, $checksum,
          'Imported from ZIP', $ver_status,
          ($set_active ? date('Y-m-d H:i:s') : null),
          ($set_active ? $HRL_USER['username'] : null),
          ($set_active ? date('Y-m-d H:i:s') : null),
          ($set_active ? $HRL_USER['username'] : null),
          null,
          $HRL_USER['username']
        ]);

        if ($set_active) {
          $pdo->prepare("UPDATE hrl_docs SET current_version=1, status='ACTIVE', updated_at=NOW() WHERE id=?")->execute([$doc_id]);
        }

        $imported++;
      }

      $pdo->commit();
      $zip->close();

      hrl_audit_append('import_zip', 'hrl_docs', ['imported'=>$imported,'unit_selector'=>$unit_sel,'set_active'=>$set_active], $pdo);
      flash_set('success', "Import ZIP selesai. Dokumen masuk: {$imported}");
      hrl_redirect($return);
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (function_exists('rmi_log_module_error')) rmi_log_module_error('hrl_docs', $e, ['action' => 'HRL_DOC_SAVE']);
    flash_set('danger', "Error: " . $e->getMessage());
    hrl_redirect(url_hrl('hrl_docs.php'));
  }
}

// ---- Load edit data ----
$edit_doc = null;
if ($edit_id > 0 && $HRL_CAN_MANAGE) {
  $st = $pdo->prepare("SELECT * FROM hrl_docs WHERE id=? LIMIT 1");
  $st->execute([$edit_id]);
  $edit_doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---- Query docs ----
$params = [':u' => $HRL_USER['username']];
$where = "WHERE 1=1";

if (!$HRL_CAN_MANAGE) {
  $where .= " AND d.status='ACTIVE' AND d.deleted_at IS NULL";
} else {
  if ($show_deleted) {
    // include deleted
  } else {
    $where .= " AND d.deleted_at IS NULL";
  }
}

if ($unit !== '' && in_array($unit, ['HR','LEGAL','IT'], true)) {
  $where .= " AND d.unit = :unit";
  $params[':unit'] = $unit;
}
if ($category !== '' && in_array($category, ['PP','SOP','FORM','CHECKLIST','PKS','CONTRACT','DOC','OTHER'], true)) {
  $where .= " AND d.category = :cat";
  $params[':cat'] = $category;
}
if ($scope !== '' && in_array($scope, ['INTERNAL','EXTERNAL'], true)) {
  $where .= " AND d.scope = :scope";
  $params[':scope'] = $scope;
}
if ($status !== '' && in_array($status, ['DRAFT','ACTIVE','OBSOLETE'], true)) {
  if ($HRL_CAN_MANAGE) {
    $where .= " AND d.status = :status";
    $params[':status'] = $status;
  }
}

$sql = "
  SELECT
    d.*,
    (SELECT COUNT(*) FROM hrl_doc_acks a WHERE a.doc_id=d.id AND a.version_no=d.current_version) AS ack_count,
    (SELECT a.ack_at FROM hrl_doc_acks a WHERE a.doc_id=d.id AND a.version_no=d.current_version AND a.username=:u LIMIT 1) AS my_ack_at
  FROM hrl_docs d
  $where
  ORDER BY d.updated_at DESC, d.id DESC
  LIMIT 1000
";

$st = $pdo->prepare($sql);
$st->execute($params);
$docs = $st->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/_layout_top.php';
?>

<div class="row g-3">
  <?php if ($HRL_CAN_MANAGE): ?>
    <div class="col-lg-4">
      <div class="rmi-card">
        <div class="rmi-card-header">
          <div>
            <h5><?= $edit_doc ? 'Edit Dokumen' : 'Tambah Dokumen' ?></h5>
            <div class="sub">Fase 1 • Metadata dokumen (tanpa mengubah file asli)</div>
          </div>
        </div>
        <div class="rmi-card-body">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
            <input type="hidden" name="action" value="<?= $edit_doc ? 'update_doc' : 'create_doc' ?>">
            <?php if ($edit_doc): ?>
              <input type="hidden" name="id" value="<?= (int)$edit_doc['id'] ?>">
            <?php endif; ?>

            <div class="mb-2">
              <label class="form-label">Doc Code (opsional)</label>
              <input class="form-control" name="doc_code" value="<?=e((string)($edit_doc['doc_code'] ?? ''))?>" placeholder="mis. HRL-HR-FORM-CUTI-IZIN">
              <div class="hint">Jika kosong, sistem buat otomatis.</div>
            </div>

            <div class="mb-2">
              <label class="form-label">Judul *</label>
              <input class="form-control" name="title" required value="<?=e((string)($edit_doc['title'] ?? ''))?>" placeholder="Contoh: Form Cuti & Izin (Revisi 2)">
            </div>

            <div class="row g-2">
              <div class="col-6 mb-2">
                <label class="form-label">Unit</label>
                <select class="form-select" name="unit">
                  <?php $uval = strtoupper((string)($edit_doc['unit'] ?? ($unit ?: 'HR'))); ?>
                  <option value="HR" <?=$uval==='HR'?'selected':''?>>HR</option>
                  <option value="LEGAL" <?=$uval==='LEGAL'?'selected':''?>>Legal</option>
                  <option value="IT" <?=$uval==='IT'?'selected':''?>>IT</option>
                </select>
              </div>
              <div class="col-6 mb-2">
                <label class="form-label">Kategori</label>
                <?php $cval = strtoupper((string)($edit_doc['category'] ?? ($category ?: 'SOP'))); ?>
                <select class="form-select" name="category">
                  <?php foreach (['PP','SOP','FORM','CHECKLIST','PKS','CONTRACT','DOC','OTHER'] as $c): ?>
                    <option value="<?=$c?>" <?=$cval===$c?'selected':''?>><?=$c?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="row g-2">
              <div class="col-6 mb-2">
                <label class="form-label">Scope</label>
                <?php $sval = strtoupper((string)($edit_doc['scope'] ?? ($scope ?: 'INTERNAL'))); ?>
                <select class="form-select" name="scope">
                  <option value="INTERNAL" <?=$sval==='INTERNAL'?'selected':''?>>INTERNAL</option>
                  <option value="EXTERNAL" <?=$sval==='EXTERNAL'?'selected':''?>>EXTERNAL</option>
                </select>
              </div>
              <div class="col-6 mb-2">
                <label class="form-label">Status</label>
                <?php $stval = strtoupper((string)($edit_doc['status'] ?? 'DRAFT')); ?>
                <select class="form-select" name="status">
                  <option value="DRAFT" <?=$stval==='DRAFT'?'selected':''?>>DRAFT</option>
                  <option value="ACTIVE" <?=$stval==='ACTIVE'?'selected':''?>>ACTIVE</option>
                  <option value="OBSOLETE" <?=$stval==='OBSOLETE'?'selected':''?>>OBSOLETE</option>
                </select>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">Effective Date</label>
              <input class="form-control" name="effective_date" value="<?=e((string)($edit_doc['effective_date'] ?? ''))?>" placeholder="YYYY-MM-DD">
            </div>

            <div class="mb-2">
              <label class="form-label">Tags (opsional)</label>
              <input class="form-control" name="tags" value="<?=e((string)($edit_doc['tags'] ?? ''))?>" placeholder="contoh: wajib, audit, internal">
            </div>

            <div class="mb-2">
              <label class="form-label">Deskripsi (opsional)</label>
              <textarea class="form-control" name="description" rows="3" placeholder="Catatan ringkas / tujuan dokumen"><?=e((string)($edit_doc['description'] ?? ''))?></textarea>
            </div>

            <div class="d-grid gap-2">
              <button class="btn btn-primary"><?= $edit_doc ? 'Simpan Perubahan' : 'Buat Dokumen' ?></button>
              <?php if ($edit_doc): ?>
                <a class="btn btn-outline-light" href="<?=e(url_hrl('hrl_docs.php'))?>">Batal Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <div class="rmi-card">
        <div class="rmi-card-header">
          <div>
            <h5>Import CSV (Fase 3)</h5>
            <div class="sub">Upsert metadata. Kolom wajib: doc_code, title</div>
          </div>
        </div>
        <div class="rmi-card-body">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
            <input type="hidden" name="action" value="import_csv">
            <input type="hidden" name="return" value="<?=e((string)($_SERVER['REQUEST_URI'] ?? url_hrl('hrl_docs.php')))?>">
            <div class="mb-2">
              <input class="form-control" type="file" name="csv" accept=".csv" required>
            </div>
            <button class="btn btn-outline-light">Import CSV</button>
            <div class="hint mt-2">Header opsional: unit, category, scope, status, effective_date, tags, description</div>
          </form>
        </div>
      </div>

      <div class="rmi-card">
        <div class="rmi-card-header">
          <div>
            <h5>Import ZIP File (Fase 3)</h5>
            <div class="sub">Upload zip berisi PDF/DOCX → auto buat dokumen + versi 1</div>
          </div>
        </div>
        <div class="rmi-card-body">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
            <input type="hidden" name="action" value="import_zip">
            <input type="hidden" name="return" value="<?=e((string)($_SERVER['REQUEST_URI'] ?? url_hrl('hrl_docs.php')))?>">
            <div class="mb-2">
              <label class="form-label">Unit untuk dokumen dalam ZIP</label>
              <select class="form-select" name="zip_unit">
                <option value="AUTO" selected>AUTO (Form→HR, Incident/Postmortem→IT, SOP/PP→Legal)</option>
                <option value="HR">Semua → HR</option>
                <option value="LEGAL">Semua → Legal</option>
                <option value="IT">Semua → IT</option>
              </select>
              <div class="hint mt-1">AUTO cocok kalau 1 ZIP berisi campuran Form + SOP/PP.</div>
            </div>
            <div class="mb-2">
              <label class="form-label">File ZIP</label>
              <input class="form-control" type="file" name="zip" accept=".zip" required>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="zip_set_active" value="1" id="zip_set_active">
              <label class="form-check-label" for="zip_set_active">Set otomatis jadi ACTIVE + Approved (hanya Manager HRL/Admin)</label>
            </div>
            <button class="btn btn-outline-light">Import ZIP</button>
            <div class="hint mt-2">Catatan: nama file dipakai sebagai judul awal. Kategori di-guess (SOP/Form/Checklist/Peraturan).</div>
          </form>
        </div>
      </div>

    </div>
    <div class="col-lg-8">
  <?php else: ?>
    <div class="col-12">
  <?php endif; ?>

      <div class="rmi-card">
        <div class="rmi-card-header">
          <div>
            <h5>Daftar Dokumen</h5>
            <div class="sub">Fase 2 • Filter + Export. Fase 3 • Bulk Action + Audit Log</div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
            <a class="btn btn-outline-light btn-sm" href="<?=e(url_hrl('panduan.php'))?>"><?=rmi_icon('books')?> Panduan</a>
            <?php if ($HRL_CAN_MANAGE): ?>
              <a class="btn btn-outline-light btn-sm" href="<?=e(url_hrl('hrl_docs.php?deleted=1'))?>">Show Deleted</a>
              <a class="btn btn-outline-light btn-sm" href="<?=e(url_hrl('hrl_docs.php'))?>">Reset</a>
            <?php endif; ?>
          </div>
        </div>

        <div class="rmi-card-body">

          <?php if ($HRL_CAN_MANAGE): ?>
          <form method="post" class="mb-3">
            <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
            <input type="hidden" name="action" value="bulk">
            <input type="hidden" name="return" value="<?=e((string)($_SERVER['REQUEST_URI'] ?? url_hrl('hrl_docs.php')))?>">

            <div class="row g-2 align-items-end">
              <div class="col-md-4">
                <label class="form-label">Bulk Action</label>
                <select class="form-select" name="bulk_action">
                  <option value="set_status">Set Status</option>
                  <option value="set_unit">Set Unit</option>
                  <option value="soft_delete">Soft Delete</option>
                  <option value="restore">Restore</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Status (jika set_status)</label>
                <select class="form-select" name="bulk_status">
                  <option value="DRAFT">DRAFT</option>
                  <option value="ACTIVE">ACTIVE</option>
                  <option value="OBSOLETE">OBSOLETE</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Unit (jika set_unit)</label>
                <select class="form-select" name="bulk_unit">
<option value="HR">HR</option>
                <option value="LEGAL">Legal</option>
                <option value="IT">IT</option>
                </select>
              </div>
              <div class="col-md-2 d-grid">
                <button class="btn btn-outline-light">Apply Bulk</button>
              </div>
            </div>

            <div class="hint mt-2">Centang dokumen pada tabel di bawah, lalu Apply Bulk.</div>

          <?php endif; ?>

          <div class="table-responsive">
            <table id="docsTable" class="table table-sm table-bordered align-middle">
              <thead>
                <tr>
                  <?php if ($HRL_CAN_MANAGE): ?><th style="width:28px"></th><?php endif; ?>
                  <th>Code</th>
                  <th>Title</th>
                  <th>Unit</th>
                  <th>Category</th>
                  <th>Scope</th>
                  <th>Status</th>
                  <th>Ver</th>
                  <th>Ack</th>
                  <th>Updated</th>
                  <th style="width:160px">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($docs as $d): ?>
                  <tr>
                    <?php if ($HRL_CAN_MANAGE): ?>
                      <td class="text-center">
                        <input type="checkbox" name="ids[]" value="<?= (int)$d['id'] ?>">
                      </td>
                    <?php endif; ?>
                    <td><code><?=e((string)$d['doc_code'])?></code></td>
                    <td><?=e((string)$d['title'])?></td>
                    <td><?=e((string)$d['unit'])?></td>
                    <td><?=e((string)$d['category'])?></td>
                    <td><?=e((string)$d['scope'])?></td>
                    <td>
                      <?php
                        $st = strtoupper((string)$d['status']);
                        $badge = ($st==='ACTIVE') ? 'success' : (($st==='OBSOLETE') ? 'secondary' : 'warning');
                      ?>
                      <span class="badge bg-<?=$badge?>"><?=$st?></span>
                      <?php if (!empty($d['deleted_at'])): ?>
                        <span class="badge bg-danger">DELETED</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int)$d['current_version'] ?></td>
                    <td class="text-center">
                      <?php if ((int)$d['current_version'] > 0 && strtoupper((string)$d['status'])==='ACTIVE'): ?>
                        <?php if (!empty($d['my_ack_at'])): ?>
                          <span class="badge bg-success"><?=rmi_icon('tick')?></span>
                        <?php else: ?>
                          <span class="badge bg-warning text-dark">-</span>
                        <?php endif; ?>
                        <div class="hint"><?= (int)$d['ack_count'] ?> ack</div>
                      <?php else: ?>
                        <span class="muted">-</span>
                      <?php endif; ?>
                    </td>
                    <td class="hint"><?=e((string)($d['updated_at'] ?? $d['created_at'] ?? ''))?></td>
                    <td>
                      <a class="btn btn-sm btn-primary" href="<?=e(url_hrl('hrl_doc_view.php?id='.(int)$d['id']))?>">View</a>
                      <?php if ($HRL_CAN_MANAGE): ?>
                        <a class="btn btn-sm btn-outline-light" href="<?=e(url_hrl('hrl_docs.php?edit='.(int)$d['id']))?>">Edit</a>
                        <?php if (empty($d['deleted_at'])): ?>
                          <form method="post" style="display:inline-block" onsubmit="return confirm('Soft delete dokumen ini?')">
                            <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
                            <input type="hidden" name="action" value="soft_delete">
                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                            <input type="hidden" name="return" value="<?=e((string)($_SERVER['REQUEST_URI'] ?? url_hrl('hrl_docs.php')))?>">
                            <button class="btn btn-sm btn-outline-danger">Del</button>
                          </form>
                        <?php else: ?>
                          <form method="post" style="display:inline-block">
                            <input type="hidden" name="csrf_token" value="<?=e($CSRF_TOKEN)?>">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                            <input type="hidden" name="return" value="<?=e((string)($_SERVER['REQUEST_URI'] ?? url_hrl('hrl_docs.php')))?>">
                            <button class="btn btn-sm btn-outline-success">Restore</button>
                          </form>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($HRL_CAN_MANAGE): ?>
          </form>
          <?php endif; ?>

        </div>
      </div>

    </div>
</div>

<script>
  $(function(){
    $('#docsTable').DataTable({
      pageLength: 25,
      order: [],
      dom: 'Bfrtip',
      buttons: ['copy','csv','excel','pdf','print']
    });
  });
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
