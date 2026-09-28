<?php
/**
 * Stock Opname — Input qty fisik, apply adjustment
 * Terjadwal setiap awal minggu oleh tim WQS
 * Bukti otentik: traceability ke wqs_stock_adjustments, foto fisik, tanda tangan, verifikasi dua pihak
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_login();
require_once __DIR__ . '/_wqs_bootstrap.php';
require_any_permission(['STOCK.CREATE', 'WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT', 'WQS.PICKING_VIEW', 'WQS.PICKING_CREATE', 'WQS.PICKING_EDIT']);

$pdo = wqs_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } }

// Run migrations (pakai query+fetchAll agar result dari EXECUTE/SELECT 1 tidak meninggalkan unbuffered cursor)
foreach (['111_wqs_stock_opname_per_kantor_depo', '125_wqs_stock_per_office', '128_wqs_stock_opname_evidence', '129_wqs_stock_adjustments_opname_trace'] as $mig) {
    $f = __DIR__ . '/../sql/migrations/' . $mig . '.sql';
    if (is_file($f)) {
        foreach (array_filter(array_map('trim', explode(';', file_get_contents($f)))) as $stmt) {
            if ($stmt !== '' && stripos($stmt, '--') !== 0) {
                try {
                    $st = $pdo->query($stmt);
                    if ($st instanceof \PDOStatement) {
                        $st->fetchAll();
                    }
                } catch (Throwable $e) {}
            }
        }
    }
}
require_once __DIR__ . '/_stock_office_helper.php';

$opCols = function_exists('table_cols') ? (table_cols($pdo, 'wqs_stock_opname') ?: []) : [];
$hasDepo = in_array('depo_name', $opCols, true);
$hasVerify = in_array('verified_by', $opCols, true);

// Helper: upload file (foto fisik)
if (!function_exists('opname_upload_file')) {
    function opname_upload_file(string $field, string $subdir): ?string {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;
        $f = $_FILES[$field];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        $tmp = $f['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp)) return null;
        $name = (string)($f['name'] ?? 'file');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allow = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allow, true)) return null;
        $base = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
        $dirAbs = $base . '/uploads/stock_opname/' . trim($subdir, '/');
        if (!is_dir($dirAbs)) @mkdir($dirAbs, 0775, true);
        $safe = function_exists('safe_filename') ? safe_filename($name) : preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $fname = ($safe ?: 'file') . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . ($ext ?: 'jpg');
        $dest = $dirAbs . '/' . $fname;
        if (!@move_uploaded_file($tmp, $dest)) return null;
        return '/uploads/stock_opname/' . trim($subdir, '/') . '/' . $fname;
    }
}

$id = (int)($_GET['id'] ?? 0);
$action = $_POST['action'] ?? '';

$opname = null;
$items = [];
$attachments = [];

if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM wqs_stock_opname WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $opname = $st->fetch(PDO::FETCH_ASSOC);
    if ($opname) {
        $st = $pdo->prepare("SELECT * FROM wqs_stock_opname_items WHERE opname_id=? ORDER BY sku");
        $st->execute([$id]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        try {
            $st = $pdo->prepare("SELECT * FROM wqs_stock_opname_attachments WHERE opname_id=? ORDER BY uploaded_at");
            $st->execute([$id]);
            $attachments = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
}

$bp = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : (string)($GLOBALS['BASE_PROJECT'] ?? '');

$officesForOpname = [];
try {
    $officesForOpname = $pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active=1 ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (empty($officesForOpname)) {
    try {
        $officesForOpname = $pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// CSRF for all POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    if (function_exists('verify_csrf')) verify_csrf();
}

// --- POST: Upload foto fisik ---
if ($action === 'upload_photo' && $opname && in_array($opname['status'], ['DRAFT', 'PENDING_VERIFY'], true)) {
    $path = opname_upload_file('photo', 'photos');
    if ($path) {
        try {
            $pdo->prepare("INSERT INTO wqs_stock_opname_attachments (opname_id, file_path, original_filename, mime_type, file_size, caption, uploaded_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([
                    $id,
                    $path,
                    $_FILES['photo']['name'] ?? null,
                    $_FILES['photo']['type'] ?? null,
                    (int)($_FILES['photo']['size'] ?? 0),
                    trim((string)($_POST['photo_caption'] ?? '')),
                    $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system',
                ]);
        } catch (Throwable $e) {}
    }
    rmi_redirect('wqs_stock_opname.php?id=' . $id);
}

// --- POST: Submit untuk verifikasi (DRAFT -> PENDING_VERIFY) ---
if ($action === 'submit' && $opname && $opname['status'] === 'DRAFT' && $hasVerify) {
    $pdo->prepare("UPDATE wqs_stock_opname SET status='PENDING_VERIFY', submitted_by=?, submitted_at=NOW() WHERE id=?")
        ->execute([$_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system', $id]);
    rmi_redirect('wqs_stock_opname.php?id=' . $id);
}

// --- POST: Create new opname ---
if ($action === 'create' && $id <= 0) {
    $office_code = strtoupper(trim((string)($_POST['office_code'] ?? '')));
    if ($office_code === '') {
        rmi_redirect('wqs_stock_opname.php?err=invalid');
    }
    $opname_date = date('Y-m-d');
    $yyymmdd = date('ymd');
    $prefix = 'OPN-' . $office_code . '-' . $yyymmdd . '-';
    $seq = 1;
    try {
        $st = $pdo->prepare("SELECT opname_code FROM wqs_stock_opname WHERE office_code=? AND opname_date=? AND opname_code LIKE ? ORDER BY id DESC LIMIT 1");
        $st->execute([$office_code, $opname_date, $prefix . '%']);
        $last = $st->fetch(PDO::FETCH_ASSOC);
        if ($last && preg_match('/-(\d+)$/', (string)$last['opname_code'], $m)) {
            $seq = (int)$m[1] + 1;
        }
        $opname_code = $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        $opname_code = $prefix . '001';
    }
    try {
        $st = $pdo->prepare("INSERT INTO wqs_stock_opname (opname_code, opname_date, office_code, status, created_by" . ($hasDepo ? ", depo_name" : "") . ") VALUES (?, ?, ?, 'DRAFT', ?" . ($hasDepo ? ", ?" : "") . ")");
        $params = [$opname_code, $opname_date, $office_code, $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system'];
        if ($hasDepo) $params[] = null;
        $st->execute($params);
        $newId = (int)$pdo->lastInsertId();
        rmi_redirect('wqs_stock_opname.php?id=' . $newId);
    } catch (Throwable $e) {
        if (function_exists('rmi_log_module_error')) rmi_log_module_error('wqs_stock_opname', $e, ['action' => 'OPNAME_CREATE', 'opname_code' => $opname_code ?? null, 'office' => $office_code ?? null]);
        rmi_redirect('wqs_stock_opname.php?err=dup');
    }
}

// --- POST: Add items from wqs_stock (per office jika wqs_stock_by_office ada) ---
if ($action === 'add_items' && $opname && $opname['status'] === 'DRAFT') {
    $office = strtoupper(trim((string)($opname['office_code'] ?? '')));
    if ($office === '') $office = wqs_stock_default_office();
    $stocks = [];
    if (function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo)) {
        $st = $pdo->prepare("SELECT s.product_id, p.sku, p.products_name AS product_name, p.unit, s.stock_qty AS qty_system
            FROM wqs_stock_by_office s
            LEFT JOIN master_products p ON p.id = s.product_id
            WHERE s.office_code = ? AND s.stock_qty > 0 ORDER BY p.sku");
        $st->execute([$office]);
        $stocks = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare("SELECT s.product_id, p.sku, p.products_name AS product_name, p.unit, s.stock_qty AS qty_system
            FROM wqs_stock s LEFT JOIN master_products p ON p.id = s.product_id
            WHERE s.stock_qty > 0 ORDER BY p.sku");
        $st->execute();
        $stocks = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $existing = [];
    foreach ($items as $it) { $existing[(int)$it['product_id']] = true; }
    foreach ($stocks as $r) {
        if (isset($existing[(int)$r['product_id']])) continue;
        $pdo->prepare("INSERT INTO wqs_stock_opname_items (opname_id, product_id, sku, product_name, unit, qty_system) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$id, $r['product_id'], $r['sku'] ?? '', $r['product_name'] ?? '', $r['unit'] ?? '', $r['qty_system'] ?? 0]);
    }
    rmi_redirect('wqs_stock_opname.php?id=' . $id);
}

// --- POST: Save qty fisik ---
if ($action === 'save' && $opname && $opname['status'] === 'DRAFT') {
    foreach ($_POST['qty_fisik'] ?? [] as $itemId => $val) {
        $itemId = (int)$itemId;
        $qty = trim((string)$val);
        $delta = null;
        $qtyFisik = null;
        if ($qty !== '' && is_numeric($qty)) {
            $qtyFisik = (float)$qty;
            $st = $pdo->prepare("SELECT qty_system FROM wqs_stock_opname_items WHERE id=? AND opname_id=?");
            $st->execute([$itemId, $id]);
            $row = $st->fetch();
            if ($row) $delta = $qtyFisik - (float)$row['qty_system'];
        }
        $pdo->prepare("UPDATE wqs_stock_opname_items SET qty_fisik=?, delta_qty=?, updated_at=NOW() WHERE id=? AND opname_id=?")
            ->execute([$qtyFisik, $delta, $itemId, $id]);
    }
    $code = (string)($opname['opname_code'] ?? 'OPN-' . $id);
    rmi_audit_safe('UPDATE', 'STOCK.OPNAME', $id, null, null, ['event' => 'opname_save', 'opname_code' => $code]);
    if (function_exists('master_audit')) {
        master_audit($pdo, 'wqs_stock_opname', 'wqs_stock_opname', 'SAVE', $id, $code, "Opname qty saved: {$code}", []);
    }
    rmi_redirect('wqs_stock_opname.php?id=' . $id);
}

// --- POST: Verify & Apply (PENDING_VERIFY -> APPLIED, dengan tanda tangan) ---
if ($action === 'verify_apply' && $opname && $opname['status'] === 'PENDING_VERIFY' && $hasVerify) {
    $sigData = trim((string)($_POST['signature_data'] ?? ''));
    $sigPath = null;
    if (preg_match('/^data:image\/png;base64,(.+)$/', $sigData, $m)) {
        $base = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
        $dir = $base . '/uploads/stock_opname/signatures';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $fname = 'sig_' . $id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';
        $dest = $dir . '/' . $fname;
        $bin = base64_decode($m[1], true);
        if ($bin !== false && strlen($bin) > 0 && @file_put_contents($dest, $bin)) {
            $sigPath = '/uploads/stock_opname/signatures/' . $fname;
        }
    }
    $office = strtoupper(trim((string)($opname['office_code'] ?? '')));
    if ($office === '') $office = wqs_stock_default_office();
    $opnameCode = (string)($opname['opname_code'] ?? 'OPN-' . $id);
    $reason = 'opname ' . $opnameCode;
    $username = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system';

    $st = $pdo->prepare("SELECT * FROM wqs_stock_opname_items WHERE opname_id=? AND delta_qty IS NOT NULL AND delta_qty != 0");
    $st->execute([$id]);
    $deltas = $st->fetchAll(PDO::FETCH_ASSOC);

    $adjCols = function_exists('table_cols') ? (table_cols($pdo, 'wqs_stock_adjustments') ?: []) : [];
    $hasAdjCode = in_array('adj_code', $adjCols, true);
    $hasOfficeAdj = in_array('office_code', $adjCols, true);
    $hasCreatedBy = in_array('created_by', $adjCols, true);

    $pdo->beginTransaction();
    try {
        if (function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo) && function_exists('wqs_stock_office_apply_delta')) {
            foreach ($deltas as $d) {
                wqs_stock_office_apply_delta($pdo, (int)$d['product_id'], (float)$d['delta_qty'], $office);
            }
        } else {
            foreach ($deltas as $d) {
                $pid = (int)$d['product_id'];
                $delta = (float)$d['delta_qty'];
                $pdo->prepare("UPDATE wqs_stock SET stock_qty = stock_qty + ? WHERE product_id=?")->execute([$delta, $pid]);
                if ($pdo->rowCount() === 0) {
                    $pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty) VALUES (?, ?)")->execute([$pid, $delta]);
                }
            }
        }
        foreach ($deltas as $d) {
            $cols = ['product_id', 'sku', 'delta_qty', 'reason'];
            $vals = [(int)$d['product_id'], $d['sku'] ?? '', (float)$d['delta_qty'], $reason];
            if ($hasAdjCode) { $cols[] = 'adj_code'; $vals[] = $opnameCode; }
            if ($hasOfficeAdj) { $cols[] = 'office_code'; $vals[] = $office; }
            if ($hasCreatedBy) { $cols[] = 'created_by'; $vals[] = $username; }
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $pdo->prepare("INSERT INTO wqs_stock_adjustments (" . implode(',', $cols) . ") VALUES (" . $ph . ")")->execute($vals);
        }
        $upd = "UPDATE wqs_stock_opname SET status='APPLIED', applied_by=?, applied_at=NOW(), verified_by=?, verified_at=NOW()" . ($sigPath ? ", signature_path=?" : "") . " WHERE id=?";
        $params = array_merge([$username, $username], $sigPath ? [$sigPath] : [], [$id]);
        $pdo->prepare($upd)->execute($params);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        rmi_redirect('wqs_stock_opname.php?id=' . $id . '&err=apply');
    }
    rmi_redirect('wqs_stock_opname_report.php?id=' . $id);
}

// --- POST: Apply opname (DRAFT -> APPLIED, backward compat + traceability ke adjustments) ---
if ($action === 'apply' && $opname && $opname['status'] === 'DRAFT') {
    $office = strtoupper(trim((string)($opname['office_code'] ?? '')));
    if ($office === '') $office = wqs_stock_default_office();
    $opnameCode = (string)($opname['opname_code'] ?? 'OPN-' . $id);
    $reason = 'opname ' . $opnameCode;
    $username = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system';

    $st = $pdo->prepare("SELECT * FROM wqs_stock_opname_items WHERE opname_id=? AND delta_qty IS NOT NULL AND delta_qty != 0");
    $st->execute([$id]);
    $deltas = $st->fetchAll(PDO::FETCH_ASSOC);

    $adjCols = function_exists('table_cols') ? (table_cols($pdo, 'wqs_stock_adjustments') ?: []) : [];
    $hasAdjCode = in_array('adj_code', $adjCols, true);
    $hasOfficeAdj = in_array('office_code', $adjCols, true);
    $hasCreatedBy = in_array('created_by', $adjCols, true);

    $pdo->beginTransaction();
    try {
        if (function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo) && function_exists('wqs_stock_office_apply_delta')) {
            foreach ($deltas as $d) {
                wqs_stock_office_apply_delta($pdo, (int)$d['product_id'], (float)$d['delta_qty'], $office);
            }
        } else {
            foreach ($deltas as $d) {
                $pid = (int)$d['product_id'];
                $delta = (float)$d['delta_qty'];
                $pdo->prepare("UPDATE wqs_stock SET stock_qty = stock_qty + ? WHERE product_id=?")->execute([$delta, $pid]);
                if ($pdo->rowCount() === 0) {
                    $pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty) VALUES (?, ?)")->execute([$pid, $delta]);
                }
            }
        }
        foreach ($deltas as $d) {
            $cols = ['product_id', 'sku', 'delta_qty', 'reason'];
            $vals = [(int)$d['product_id'], $d['sku'] ?? '', (float)$d['delta_qty'], $reason];
            if ($hasAdjCode) { $cols[] = 'adj_code'; $vals[] = $opnameCode; }
            if ($hasOfficeAdj) { $cols[] = 'office_code'; $vals[] = $office; }
            if ($hasCreatedBy) { $cols[] = 'created_by'; $vals[] = $username; }
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $pdo->prepare("INSERT INTO wqs_stock_adjustments (" . implode(',', $cols) . ") VALUES (" . $ph . ")")->execute($vals);
        }
        $pdo->prepare("UPDATE wqs_stock_opname SET status='APPLIED', applied_by=?, applied_at=NOW() WHERE id=?")
            ->execute([$username, $id]);
        $pdo->commit();
        rmi_audit_safe('POSTING', 'STOCK.OPNAME', $id, null, null, ['event' => 'opname_apply', 'opname_code' => $opnameCode]);
        if (function_exists('master_audit')) {
            master_audit($pdo, 'wqs_stock_opname', 'wqs_stock_opname', 'APPLY', $id, $opnameCode, "Opname applied: {$opnameCode}", []);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (function_exists('rmi_log_module_error')) rmi_log_module_error('wqs_stock_opname', $e, ['action' => 'OPNAME_APPLY', 'id' => $id, 'opname_code' => $opnameCode ?? null]);
        rmi_redirect('wqs_stock_opname.php?id=' . $id . '&err=apply');
    }
    rmi_redirect('wqs_stock_opname_report.php?id=' . $id);
}

// --- Redirect if APPLIED (read-only, ke report) ---
if ($opname && $opname['status'] === 'APPLIED') {
    rmi_redirect('wqs_stock_opname_report.php?id=' . $id);
}

// Reload items after POST
if ($opname && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM wqs_stock_opname_items WHERE opname_id=? ORDER BY sku");
    $st->execute([$id]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
}

rmi_header('Stock Opname', [
    'active' => 'stock_opname',
    'subtitle' => 'Input qty fisik stok opname — Terjadwal setiap awal minggu',
    'breadcrumbs' => [
        ['label' => 'Stock (WQS)', 'url' => $bp . '/stock/wqs_stock.php'],
        ['label' => 'Stock Opname', 'url' => $bp . '/stock/wqs_stock_opname.php'],
        ['label' => 'Report', 'url' => $bp . '/stock/wqs_stock_opname_report.php'],
    ],
]);
?>

<?php if (($_GET['err'] ?? '') === 'invalid'): ?><div class="alert alert-warning">Kode atau tanggal tidak valid.</div><?php endif; ?>
<?php if (($_GET['err'] ?? '') === 'dup'): ?><div class="alert alert-warning">Kode opname sudah ada.</div><?php endif; ?>
<?php if (($_GET['err'] ?? '') === 'apply'): ?><div class="alert alert-danger">Gagal apply opname. Coba lagi.</div><?php endif; ?>

<?php if ($opname && $id > 0): ?>
<!-- Edit / Verify opname -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4>Opname: <?= h($opname['opname_code']) ?></h4>
    <div class="text-muted small"><?= h($opname['opname_date']) ?> · <?= h($opname['office_code'] ?? '-') ?> · <?= h($opname['depo_name'] ?? '-') ?> · <span class="badge <?= $opname['status']==='APPLIED'?'bg-success':($opname['status']==='PENDING_VERIFY'?'bg-info':'bg-warning') ?>"><?= h($opname['status']) ?></span></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light btn-sm" href="wqs_stock_opname_report.php?id=<?= $id ?>">Report</a>
    <a class="btn btn-outline-light btn-sm" href="wqs_stock_opname.php">← Daftar</a>
  </div>
</div>

<?php if ($opname['status'] === 'PENDING_VERIFY'): ?>
<!-- Verifikasi dua pihak: Tanda tangan & Apply -->
<div class="card bg-dark border mb-3">
  <div class="card-body">
    <h6>Verifikasi & Apply Opname</h6>
    <p class="text-muted small">Paraf/tanda tangan verifikator (WQS Lead/Manager) untuk validasi hasil opname.</p>
    <form method="post" id="verifyForm" onsubmit="return confirm('Verify & Apply opname? Stock akan disesuaikan.');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="verify_apply">
      <input type="hidden" name="signature_data" id="signatureData">
      <div class="mb-2">
        <label class="form-label small">Tanda tangan / Paraf</label>
        <canvas id="signaturePad" width="400" height="120" style="border:1px solid #495057; border-radius:4px; background:#fff; cursor:crosshair;"></canvas>
        <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="sigClear()">Clear</button>
      </div>
      <button type="submit" class="btn btn-success">Verify & Apply Opname</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (in_array($opname['status'], ['DRAFT', 'PENDING_VERIFY'], true)): ?>
<!-- Upload foto fisik -->
<div class="card bg-dark border mb-3">
  <div class="card-body">
    <h6>Foto fisik (bukti opname di gudang)</h6>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="upload_photo">
      <div class="row g-2">
        <div class="col-md-6"><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm"></div>
        <div class="col-md-4"><input type="text" name="photo_caption" class="form-control form-control-sm" placeholder="Keterangan (opsional)"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-outline-light btn-sm">Upload</button></div>
      </div>
    </form>
    <?php if (!empty($attachments)): ?>
    <div class="mt-2 small">
      <?php foreach ($attachments as $a): ?>
      <span class="me-2"><a href="<?= h($bp . $a['file_path']) ?>" target="_blank" class="text-info"><?= h($a['original_filename'] ?? basename($a['file_path'])) ?></a></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($opname['status'] === 'DRAFT'): ?>
<?php if (empty($items)): ?>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="add_items">
  <button type="submit" class="btn btn-outline-light">Tambah Item dari Stock</button>
</form>
<?php else: ?>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">
  <div class="table-responsive overflow-auto">
    <table class="table table-dark table-striped">
      <thead><tr><th>SKU</th><th>Produk</th><th>Unit</th><th>Qty Sistem</th><th>Qty Fisik</th><th>Selisih</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): $d = (float)($it['delta_qty'] ?? 0); ?>
        <tr>
          <td><b><?= h($it['sku']) ?></b></td>
          <td><?= h($it['product_name']) ?></td>
          <td><?= h($it['unit'] ?? '-') ?></td>
          <td><?= number_format((float)($it['qty_system'] ?? 0), 2) ?></td>
          <td><input type="number" step="0.01" name="qty_fisik[<?= (int)$it['id'] ?>]" value="<?= $it['qty_fisik'] !== null ? h($it['qty_fisik']) : '' ?>" class="form-control form-control-sm" style="width:100px"></td>
          <td><?= $d != 0 ? ($d > 0 ? '+' : '') . number_format($d, 2) : '-' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="mt-2 d-flex gap-2 flex-wrap">
    <button type="submit" class="btn btn-outline-light btn-sm">Simpan Qty Fisik</button>
    <?php if ($hasVerify): ?>
    <form method="post" class="d-inline" onsubmit="return confirm('Submit untuk verifikasi dua pihak? Verifikator akan memeriksa dan apply.');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="submit">
      <button type="submit" class="btn btn-info btn-sm">Submit untuk Verifikasi</button>
    </form>
    <?php endif; ?>
    <form method="post" class="d-inline" onsubmit="return confirm('Apply opname? Stock akan disesuaikan.');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="apply">
      <button type="submit" class="btn btn-success btn-sm">Apply Langsung</button>
    </form>
  </div>
</form>
<?php endif; ?>
<?php elseif ($opname['status'] === 'PENDING_VERIFY' && !empty($items)): ?>
<!-- Preview read-only untuk verifikator -->
<div class="table-responsive overflow-auto mb-3">
  <table class="table table-dark table-striped table-sm">
    <thead><tr><th>SKU</th><th>Produk</th><th>Unit</th><th>Qty Sistem</th><th>Qty Fisik</th><th>Selisih</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): $d = (float)($it['delta_qty'] ?? 0); ?>
      <tr>
        <td><b><?= h($it['sku']) ?></b></td>
        <td><?= h($it['product_name']) ?></td>
        <td><?= h($it['unit'] ?? '-') ?></td>
        <td><?= number_format((float)($it['qty_system'] ?? 0), 2) ?></td>
        <td><?= $it['qty_fisik'] !== null ? number_format((float)$it['qty_fisik'], 2) : '-' ?></td>
        <td><?= $d != 0 ? ($d > 0 ? '+' : '') . number_format($d, 2) : '-' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php else: ?>
<!-- List / Create -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4>Stock Opname</h4>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock_opname_report.php">Report</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock.php">← Stock</a>
  </div>
</div>

<form method="post" class="card bg-dark border p-3 mb-3">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="create">
  <h6>Buat Opname Baru</h6>
  <p class="text-muted small mb-2">Kode otomatis: OPN-{office}-{YYMMDD}-{XXX} (tanggal hari ini)</p>
  <div class="row g-2 align-items-end">
    <div class="col-md-4">
      <label class="form-label small">Kantor / Depo</label>
      <select name="office_code" class="form-select form-select-sm" required>
        <option value="">— Pilih —</option>
        <?php foreach ($officesForOpname as $oc): ?>
        <option value="<?= h($oc['office_code']) ?>"><?= h($oc['office_code']) ?> — <?= h($oc['office_name'] ?? '') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-outline-light btn-sm">Buat</button>
    </div>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-dark table-striped">
    <thead><tr><th>Kode</th><th>Tanggal</th><th>Kantor</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
      <?php
      $list = [];
      try {
        $st = $pdo->query("SELECT * FROM wqs_stock_opname ORDER BY opname_date DESC, id DESC LIMIT 50");
        $list = $st->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {}
      foreach ($list as $o): ?>
      <tr>
        <td><b><?= h($o['opname_code']) ?></b></td>
        <td><?= h($o['opname_date']) ?></td>
        <td><?= h($o['office_code'] ?? '-') ?></td>
        <td><span class="badge <?= $o['status']==='APPLIED'?'bg-success':($o['status']==='PENDING_VERIFY'?'bg-info':'bg-warning') ?>"><?= h($o['status']) ?></span></td>
        <td>
          <?php if (in_array($o['status'], ['DRAFT', 'PENDING_VERIFY'])): ?>
          <a class="btn btn-sm btn-outline-light" href="wqs_stock_opname.php?id=<?= $o['id'] ?>"><?= $o['status']==='PENDING_VERIFY'?'Verify':'Edit' ?></a>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-light" href="wqs_stock_opname_report.php?id=<?= $o['id'] ?>">Report</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if (empty($list)): ?><div class="text-muted">Belum ada opname. Buat opname baru di atas.</div><?php endif; ?>
<?php endif; ?>

<?php if ($opname && $opname['status'] === 'PENDING_VERIFY'): ?>
<script>
(function(){
  var c=document.getElementById('signaturePad');
  if(!c) return;
  var ctx=c.getContext('2d');
  var drawing=false, lastX=0, lastY=0;
  ctx.strokeStyle='#000'; ctx.lineWidth=2; ctx.lineCap='round';
  c.addEventListener('mousedown',function(e){drawing=true;lastX=e.offsetX;lastY=e.offsetY;});
  c.addEventListener('mousemove',function(e){if(!drawing)return;ctx.beginPath();ctx.moveTo(lastX,lastY);ctx.lineTo(e.offsetX,e.offsetY);ctx.stroke();lastX=e.offsetX;lastY=e.offsetY;});
  c.addEventListener('mouseup',function(){drawing=false;});
  c.addEventListener('mouseout',function(){drawing=false;});
  window.sigClear=function(){ctx.clearRect(0,0,c.width,c.height);document.getElementById('signatureData').value='';};
  document.getElementById('verifyForm').addEventListener('submit',function(){
    document.getElementById('signatureData').value=c.toDataURL('image/png');
  });
})();
</script>
<?php endif; ?>
<?php rmi_footer(); ?>
