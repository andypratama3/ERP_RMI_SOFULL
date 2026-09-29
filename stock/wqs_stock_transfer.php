<?php
declare(strict_types=1);
/**
 * stock/wqs_stock_transfer.php
 * Transfer / Mutasi antar kantor dengan bukti otentik:
 * - Foto kartu stok sebelum & sesudah (pengirim & penerima)
 * - Foto fisik produk detail saat keluar & masuk
 */
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_login();
require_once __DIR__ . '/../master/auth.php';
// Transfer hanya untuk SYS. Kantor & Depo pakai Pembelian (PO + Sales DO).
if (function_exists('require_any_permission')) {
    require_any_permission(['WQS.TRANSFER_VIEW', 'WQS.TRANSFER_CREATE']);
} else {
    $allowRole = function_exists('auth_role') ? auth_role() : ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
    if (strtoupper(trim((string)$allowRole)) !== 'SYS') {
        http_response_code(403);
        die('Transfer Antar Kantor hanya untuk SYS. Kantor & Depo gunakan Pembelian (PO + Sales DO).');
    }
}

require_once __DIR__ . '/_stock_office_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_audit_helper.php'; // rmi_audit_safe() used by transfer_create/send/receive

if (!function_exists('h')) { function h($v) { return rmi_h($v); } }
function up($v) { return strtoupper(trim((string)($v ?? ''))); }

$pdo = db_pdo();


/**
 * FIX FIFO MULTI-ROW:
 * Pastikan tabel wqs_stock_by_office punya kolom id unik untuk stok per lot/baris.
 * Aman dijalankan berulang; jika id sudah ada, dilewati.
 */
function wqs_transfer_ensure_wqs_stock_by_office_id(PDO $pdo): void
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wqs_stock_by_office'");
        $st->execute();
        if ((int)$st->fetchColumn() === 0) return;

        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wqs_stock_by_office' AND column_name = 'id'");
        $st->execute();
        if ((int)$st->fetchColumn() > 0) return;

        $pdo->exec("ALTER TABLE `wqs_stock_by_office` ADD COLUMN `id` BIGINT UNSIGNED NULL FIRST");
        $pdo->exec("SET @rmi_wso_id := 0");
        $pdo->exec("UPDATE `wqs_stock_by_office` SET `id` = (@rmi_wso_id := @rmi_wso_id + 1) WHERE `id` IS NULL ORDER BY `office_code`, `product_id`");
        $pdo->exec("ALTER TABLE `wqs_stock_by_office` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL");
        try { $pdo->exec("ALTER TABLE `wqs_stock_by_office` ADD UNIQUE KEY `uq_wso_id` (`id`)"); } catch (Throwable $e) {}
        $pdo->exec("ALTER TABLE `wqs_stock_by_office` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
        try { $pdo->exec("ALTER TABLE `wqs_stock_by_office` ADD INDEX `idx_wso_office_product` (`office_code`, `product_id`)"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE `wqs_stock_by_office` ADD INDEX `idx_wso_product_office_qty` (`product_id`, `office_code`, `stock_qty`)"); } catch (Throwable $e) {}
    } catch (Throwable $e) {
        // Fail-soft: halaman tetap dibuka, tetapi Sales DO tetap membutuhkan SQL fix jika auto ALTER diblokir.
    }
}

try { wqs_transfer_ensure_wqs_stock_by_office_id($pdo); } catch (Throwable $e) {}

// Ensure migration 131
$migFile = __DIR__ . '/../sql/migrations/131_wqs_stock_transfer.sql';
if (is_file($migFile)) {
    foreach (array_filter(array_map('trim', explode(';', file_get_contents($migFile)))) as $stmt) {
        if ($stmt !== '' && stripos($stmt, '--') !== 0) {
            try { $pdo->exec($stmt); } catch (Throwable $e) {}
        }
    }
}

function upload_file(string $field, string $dirRel): ?string {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $tmp = $f['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) return null;
    $name = (string)($f['name'] ?? 'file');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allow = ['jpg','jpeg','png','webp','pdf'];
    if (!in_array($ext, $allow, true)) return null;
    $base = realpath(__DIR__ . '/..');
    if ($base === false) return null;
    $dirAbs = $base . '/' . trim($dirRel, '/');
    if (!is_dir($dirAbs)) @mkdir($dirAbs, 0775, true);
    $clean = function_exists('safe_filename') ? safe_filename($name) : preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    $basePart = pathinfo($clean, PATHINFO_FILENAME) ?: 'file';
    $ext2 = strtolower(pathinfo($clean, PATHINFO_EXTENSION)) ?: $ext;
    $fname = $basePart . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext2;
    $destAbs = $dirAbs . '/' . $fname;
    if (!@move_uploaded_file($tmp, $destAbs)) return null;
    return '/' . trim($dirRel, '/') . '/' . $fname;
}

function next_transfer_code(PDO $pdo): string {
    $date = date('Ymd');
    $prefix = "TRF-$date-";
    $stmt = $pdo->prepare("SELECT transfer_code FROM wqs_stock_transfer WHERE transfer_code LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix.'%']);
    $last = $stmt->fetch();
    if (!$last) return $prefix.'0001';
    $n = (int)substr($last['transfer_code'], -4) + 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function get_product(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT id, sku, products_name, unit FROM master_products WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $action = trim((string)($_POST['action'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'create') {
        $from_office = up($_POST['from_office'] ?? '');
        $to_office = up($_POST['to_office'] ?? '');
        $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d');
        $note = trim($_POST['note'] ?? '');
        $items = $_POST['items'] ?? [];

        if ($from_office === '' || $to_office === '') {
            $flash = ['type' => 'danger', 'msg' => 'Kantor asal & tujuan wajib diisi.'];
        } elseif ($from_office === $to_office) {
            $flash = ['type' => 'danger', 'msg' => 'Kantor asal dan tujuan harus berbeda.'];
        } else {
            $clean = [];
            foreach ($items as $it) {
                $pid = (int)($it['product_id'] ?? 0);
                $qty = (float)($it['qty'] ?? 0);
                if ($pid <= 0 || $qty <= 0) continue;
                $clean[] = ['product_id' => $pid, 'qty' => $qty];
            }
            if (empty($clean)) {
                $flash = ['type' => 'danger', 'msg' => 'Minimal 1 item dengan qty > 0.'];
            } else {
                $userOffice = function_exists('auth_office_code') ? up(trim(auth_office_code())) : '';
                $userDept = function_exists('auth_dept') ? up(auth_dept()) : '';
                if ($userDept === 'BRANCH' && $userOffice !== '' && $from_office !== $userOffice) {
                    $flash = ['type' => 'danger', 'msg' => 'Staff BRANCH hanya boleh transfer dari kantor ' . $userOffice . '.'];
                } else {
                    $pdo->beginTransaction();
                    try {
                        $code = next_transfer_code($pdo);
                        $pdo->prepare("INSERT INTO wqs_stock_transfer (transfer_code, from_office, to_office, transfer_date, status, note, created_by) VALUES (?,?,?,?,'DRAFT',?,?)")
                            ->execute([$code, $from_office, $to_office, $transfer_date, $note ?: null, $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system']);
                        $tid = (int)$pdo->lastInsertId();
                        $ins = $pdo->prepare("INSERT INTO wqs_stock_transfer_items (transfer_id, product_id, sku, product_name, unit, qty) VALUES (?,?,?,?,?,?)");
                        foreach ($clean as $it) {
                            $prod = get_product($pdo, $it['product_id']);
                            $ins->execute([$tid, $it['product_id'], $prod['sku'] ?? '', $prod['products_name'] ?? '', $prod['unit'] ?? '', $it['qty']]);
                        }
                        $pdo->commit();
                        rmi_audit_safe('CREATE', 'STOCK.TRANSFER', $tid, null, null, ['event' => 'transfer_create', 'code' => $code, 'from' => $from_office, 'to' => $to_office]);
                        if (function_exists('master_audit')) {
                            master_audit($pdo, 'wqs_stock_transfer', 'wqs_stock_transfer', 'CREATE', $tid, $code, "Transfer created: {$code} ({$from_office} → {$to_office})", ['from_office' => $from_office, 'to_office' => $to_office]);
                        }
                        $flash = ['type' => 'success', 'msg' => "Transfer {$code} dibuat. Silakan upload foto & kirim."];
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        if (function_exists('rmi_log_module_error')) rmi_log_module_error('wqs_stock_transfer', $e, ['action' => 'TRANSFER_CREATE', 'from' => $from_office ?? null, 'to' => $to_office ?? null]);
                        $flash = ['type' => 'danger', 'msg' => 'Gagal: ' . h($e->getMessage())];
                    }
                }
            }
        }
    }

    if ($action === 'send' && $id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM wqs_stock_transfer WHERE id=? AND status='DRAFT' LIMIT 1");
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if (!$t) {
            $flash = ['type' => 'danger', 'msg' => 'Transfer tidak ditemukan atau sudah dikirim.'];
        } else {
            $before = upload_file('wqs_stock_before', 'uploads/wqs_transfer') ?: $t['wqs_stock_before'] ?? '';
            $after = upload_file('wqs_stock_after', 'uploads/wqs_transfer') ?: $t['wqs_stock_after'] ?? '';
            $fotoFisik = upload_file('foto_fisik_keluar', 'uploads/wqs_transfer') ?: $t['foto_fisik_keluar'] ?? '';
            if (!$before || !$after) {
                $flash = ['type' => 'danger', 'msg' => 'Wajib upload foto kartu stok SEBELUM & SESUDAH (pengirim).'];
            } elseif (!$fotoFisik) {
                $flash = ['type' => 'danger', 'msg' => 'Wajib upload foto fisik produk detail saat keluar.'];
            } else {
                $pdo->beginTransaction();
                try {
                    $items = $pdo->prepare("SELECT * FROM wqs_stock_transfer_items WHERE transfer_id=?");
                    $items->execute([$id]);
                    $rows = $items->fetchAll(PDO::FETCH_ASSOC);
                    $fromOffice = up($t['from_office'] ?? '');
                    if (function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo) && function_exists('wqs_stock_office_reduce')) {
                        foreach ($rows as $r) {
                            $avail = wqs_stock_office_get($pdo, (int)$r['product_id'], $fromOffice);
                            if ($avail < (float)$r['qty']) {
                                throw new Exception("Stok " . ($r['sku'] ?? '') . " tidak cukup di {$fromOffice}. Tersedia: {$avail}. Dibutuhkan: {$r['qty']}");
                            }
                            wqs_stock_office_reduce($pdo, (int)$r['product_id'], (float)$r['qty'], $fromOffice);
                        }
                    }
                    $actor = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system';
                    $pdo->prepare("UPDATE wqs_stock_transfer SET status='SENT', wqs_stock_before=?, wqs_stock_after=?, foto_fisik_keluar=?, sent_at=NOW(), sent_by=? WHERE id=?")
                        ->execute([$before, $after, $fotoFisik, $actor, $id]);
                    $pdo->commit();
                    $tc = (string)($t['transfer_code'] ?? '');
                    rmi_audit_safe('UPDATE', 'STOCK.TRANSFER', $id, null, null, ['event' => 'transfer_send', 'code' => $tc, 'from_office' => $fromOffice ?? '']);
                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'wqs_stock_transfer', 'wqs_stock_transfer', 'SEND', $id, $tc, "Transfer sent: {$tc}", []);
                    }
                    $flash = ['type' => 'success', 'msg' => 'Transfer dikirim. Stock berkurang di kantor pengirim.'];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if (function_exists('rmi_log_module_error')) rmi_log_module_error('wqs_stock_transfer', $e, ['action' => 'TRANSFER_SEND', 'id' => $id, 'transfer_code' => $t['transfer_code'] ?? null]);
                    $flash = ['type' => 'danger', 'msg' => 'Gagal: ' . h($e->getMessage())];
                }
            }
        }
    }

    if ($action === 'receive' && $id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM wqs_stock_transfer WHERE id=? AND status='SENT' LIMIT 1");
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if (!$t) {
            $flash = ['type' => 'danger', 'msg' => 'Transfer tidak ditemukan atau belum dikirim.'];
        } else {
            $before = upload_file('wqs_stock_before_penerima', 'uploads/wqs_transfer') ?: $t['wqs_stock_before_penerima'] ?? '';
            $after = upload_file('wqs_stock_after_penerima', 'uploads/wqs_transfer') ?: $t['wqs_stock_after_penerima'] ?? '';
            $fotoFisik = upload_file('foto_fisik_masuk', 'uploads/wqs_transfer') ?: $t['foto_fisik_masuk'] ?? '';
            if (!$before || !$after) {
                $flash = ['type' => 'danger', 'msg' => 'Wajib upload foto kartu stok SEBELUM & SESUDAH (penerima).'];
            } elseif (!$fotoFisik) {
                $flash = ['type' => 'danger', 'msg' => 'Wajib upload foto fisik produk detail saat masuk.'];
            } else {
                $pdo->beginTransaction();
                try {
                    $items = $pdo->prepare("SELECT * FROM wqs_stock_transfer_items WHERE transfer_id=?");
                    $items->execute([$id]);
                    $rows = $items->fetchAll(PDO::FETCH_ASSOC);
                    $toOffice = up($t['to_office'] ?? '');
                    if (function_exists('wqs_stock_by_office_exists') && wqs_stock_by_office_exists($pdo) && function_exists('wqs_stock_office_add')) {
                        foreach ($rows as $r) {
                            wqs_stock_office_add($pdo, (int)$r['product_id'], (float)$r['qty'], $toOffice);
                        }
                    }
                    $actor = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system';
                    $pdo->prepare("UPDATE wqs_stock_transfer SET status='RECEIVED', wqs_stock_before_penerima=?, wqs_stock_after_penerima=?, foto_fisik_masuk=?, received_at=NOW(), received_by=? WHERE id=?")
                        ->execute([$before, $after, $fotoFisik, $actor, $id]);
                    $pdo->commit();
                    $tc2 = (string)($t['transfer_code'] ?? '');
                    rmi_audit_safe('POSTING', 'STOCK.TRANSFER', $id, null, null, ['event' => 'transfer_receive', 'code' => $tc2, 'to_office' => $toOffice ?? '']);
                    if (function_exists('master_audit')) {
                        master_audit($pdo, 'wqs_stock_transfer', 'wqs_stock_transfer', 'RECEIVE', $id, $tc2, "Transfer received: {$tc2}", []);
                    }
                    $flash = ['type' => 'success', 'msg' => 'Transfer diterima. Stock bertambah di kantor penerima.'];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if (function_exists('rmi_log_module_error')) rmi_log_module_error('wqs_stock_transfer', $e, ['action' => 'TRANSFER_RECEIVE', 'id' => $id, 'transfer_code' => $t['transfer_code'] ?? null]);
                    $flash = ['type' => 'danger', 'msg' => 'Gagal: ' . h($e->getMessage())];
                }
            }
        }
    }
}

$filterOffice = up($_GET['office'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$offices = [];
try {
    $offices = $pdo->query("SELECT office_code, office_name FROM master_office WHERE office_code IS NOT NULL AND office_code != '' AND office_code != 'HO' ORDER BY office_code")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$where = ["1=1"];
$params = [];
if ($filterOffice !== '') {
    $where[] = "(from_office = ? OR to_office = ?)";
    $params[] = $filterOffice;
    $params[] = $filterOffice;
}
if ($filterStatus !== '') {
    $where[] = "status = ?";
    $params[] = $filterStatus;
}
$sql = "SELECT t.*, (SELECT COUNT(*) FROM wqs_stock_transfer_items i WHERE i.transfer_id = t.id) AS item_count
        FROM wqs_stock_transfer t WHERE " . implode(' AND ', $where) . " ORDER BY t.created_at DESC, t.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$products = [];
try {
    $products = $pdo->query("
        SELECT id, sku, products_name, unit
        FROM master_products
        WHERE LOWER(status) = 'active'
        ORDER BY sku ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $products = [];
}
$audit_rows = [];
if (function_exists('master_audit')) {
    try {
        $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='wqs_stock_transfer' ORDER BY created_at DESC LIMIT 50");
        $st->execute();
        $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$bp = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : (string)($GLOBALS['BASE_PROJECT'] ?? '');
require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('Transfer Antar Kantor', [
    'active' => 'stock',
    'breadcrumbs' => [
        ['label' => 'Stock (WQS)', 'url' => $bp . '/stock/wqs_stock.php'],
        'Transfer Antar Kantor',
    ],
]);
?>
<?php if ($flash['msg']): ?>
<div class="alert alert-<?= h($flash['type']) ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4>Transfer Antar Kantor</h4>
    <div class="text-muted small">Mutasi antar kantor dengan bukti otentik: foto kartu stok sebelum/sesudah + foto fisik produk.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock.php">Stock</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_incoming.php">Incoming</a>
    <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock_opname_report.php">Report Opname</a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <h6 class="mb-2">Buat Transfer Baru</h6>
    <form method="post" id="createForm">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="row g-2">
        <div class="col-md-2">
          <label class="form-label small">Tanggal</label>
          <input class="form-control form-control-sm" type="date" name="transfer_date" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small">Dari Kantor <span class="text-danger">*</span></label>
          <select class="form-select form-select-sm" name="from_office" required>
            <option value="">-- pilih --</option>
            <?php foreach ($offices as $o): $oc = up($o['office_code'] ?? ''); ?>
            <option value="<?= h($oc) ?>"><?= h($oc) ?><?= !empty($o['office_name']) ? ' - ' . h($o['office_name']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small">Ke Kantor <span class="text-danger">*</span></label>
          <select class="form-select form-select-sm" name="to_office" required>
            <option value="">-- pilih --</option>
            <?php foreach ($offices as $o): $oc = up($o['office_code'] ?? ''); ?>
            <option value="<?= h($oc) ?>"><?= h($oc) ?><?= !empty($o['office_name']) ? ' - ' . h($o['office_name']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label small">Catatan</label>
          <input class="form-control form-control-sm" name="note" placeholder="Opsional">
        </div>
      </div>
      <div class="mt-2">
        <div class="fw-semibold small mb-1">Items</div>
        <div id="itemsContainer"></div>
        <button type="button" class="btn btn-outline-light btn-sm" onclick="addItemRow()">+ Tambah Item</button>
      </div>
      <div class="mt-2">
        <button type="submit" class="btn btn-primary btn-sm">Buat Transfer</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <form method="get" class="d-flex gap-2 mb-2">
      <select class="form-select form-select-sm" name="office" style="width:auto" onchange="this.form.submit()">
        <option value="">Semua Kantor</option>
        <?php foreach ($offices as $o): $oc = up($o['office_code'] ?? ''); ?>
        <option value="<?= h($oc) ?>" <?= $filterOffice === $oc ? 'selected' : '' ?>><?= h($oc) ?></option>
        <?php endforeach; ?>
      </select>
      <select class="form-select form-select-sm" name="status" style="width:auto" onchange="this.form.submit()">
        <option value="">Semua Status</option>
        <option value="DRAFT" <?= $filterStatus === 'DRAFT' ? 'selected' : '' ?>>Draft</option>
        <option value="SENT" <?= $filterStatus === 'SENT' ? 'selected' : '' ?>>Dikirim</option>
        <option value="RECEIVED" <?= $filterStatus === 'RECEIVED' ? 'selected' : '' ?>>Diterima</option>
      </select>
    </form>
    <div class="table-responsive">
      <table class="table table-dark table-sm">
        <thead>
          <tr>
            <th>Kode</th>
            <th>Tanggal</th>
            <th>Dari</th>
            <th>Ke</th>
            <th>Status</th>
            <th>Items</th>
            <th>Bukti</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $r): ?>
          <tr>
            <td><b><?= h($r['transfer_code']) ?></b></td>
            <td><?= h($r['transfer_date']) ?></td>
            <td><?= h($r['from_office']) ?></td>
            <td><?= h($r['to_office']) ?></td>
            <td>
              <?php
              $st = $r['status'] ?? 'DRAFT';
              $cls = $st === 'RECEIVED' ? 'text-success' : ($st === 'SENT' ? 'text-warning' : 'text-muted');
              ?><span class="<?= $cls ?>"><?= h($st) ?></span>
            </td>
            <td><?= (int)($r['item_count'] ?? 0) ?> item</td>
            <td class="small">
              <?php
              $hasBefore = !empty(trim((string)($r['wqs_stock_before'] ?? '')));
              $hasAfter = !empty(trim((string)($r['wqs_stock_after'] ?? '')));
              $hasFisik = !empty(trim((string)($r['foto_fisik_keluar'] ?? '')));
              $hasRecv = !empty(trim((string)($r['wqs_stock_before_penerima'] ?? '')));
              $hasRecvAfter = !empty(trim((string)($r['wqs_stock_after_penerima'] ?? '')));
              $hasFisikMasuk = !empty(trim((string)($r['foto_fisik_masuk'] ?? '')));
              ?>
              Pengirim: <?= $hasBefore && $hasAfter ? '<span class="text-success">'.rmi_icon('tick').'</span>' : '<span class="text-warning">'.rmi_icon('x').'</span>' ?>
              Fisik keluar: <?= $hasFisik ? '<span class="text-success">'.rmi_icon('tick').'</span>' : '<span class="text-warning">'.rmi_icon('x').'</span>' ?>
              <?php if ($st === 'SENT' || $st === 'RECEIVED'): ?>
              <br>Penerima: <?= $hasRecv && $hasRecvAfter ? '<span class="text-success">'.rmi_icon('tick').'</span>' : '<span class="text-warning">'.rmi_icon('x').'</span>' ?>
              Fisik masuk: <?= $hasFisikMasuk ? '<span class="text-success">'.rmi_icon('tick').'</span>' : '<span class="text-warning">'.rmi_icon('x').'</span>' ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($st === 'DRAFT'): ?>
              <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#sendModal<?= $r['id'] ?>">Kirim</button>
              <?php elseif ($st === 'SENT'): ?>
              <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#receiveModal<?= $r['id'] ?>">Terima</button>
              <?php endif; ?>
              <a class="btn btn-outline-light btn-sm" href="?view=<?= $r['id'] ?>">Detail</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (empty($list)): ?>
    <p class="text-muted mb-0">Belum ada transfer.</p>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($list as $r): ?>
<?php $st = $r['status'] ?? 'DRAFT'; ?>
<?php if ($st === 'DRAFT'): ?>
<div class="modal fade" id="sendModal<?= $r['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content bg-dark">
      <div class="modal-header">
        <h6 class="modal-title">Kirim Transfer — <?= h($r['transfer_code']) ?></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="id" value="<?= $r['id'] ?>">
        <div class="modal-body">
          <p class="small text-muted">Wajib upload foto kartu stok & foto fisik produk sebelum kirim.</p>
          <div class="mb-2">
            <label class="form-label small">Foto kartu stok SEBELUM (pengirim)</label>
            <input class="form-control form-control-sm" type="file" name="wqs_stock_before" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
          <div class="mb-2">
            <label class="form-label small">Foto kartu stok SESUDAH (pengirim)</label>
            <input class="form-control form-control-sm" type="file" name="wqs_stock_after" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
          <div class="mb-2">
            <label class="form-label small">Foto fisik produk detail (saat keluar)</label>
            <input class="form-control form-control-sm" type="file" name="foto_fisik_keluar" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-warning btn-sm">Kirim</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($st === 'SENT'): ?>
<div class="modal fade" id="receiveModal<?= $r['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content bg-dark">
      <div class="modal-header">
        <h6 class="modal-title">Terima Transfer — <?= h($r['transfer_code']) ?></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="receive">
        <input type="hidden" name="id" value="<?= $r['id'] ?>">
        <div class="modal-body">
          <p class="small text-muted">Wajib upload foto kartu stok & foto fisik produk saat terima.</p>
          <div class="mb-2">
            <label class="form-label small">Foto kartu stok SEBELUM (penerima)</label>
            <input class="form-control form-control-sm" type="file" name="wqs_stock_before_penerima" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
          <div class="mb-2">
            <label class="form-label small">Foto kartu stok SESUDAH (penerima)</label>
            <input class="form-control form-control-sm" type="file" name="wqs_stock_after_penerima" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
          <div class="mb-2">
            <label class="form-label small">Foto fisik produk detail (saat masuk)</label>
            <input class="form-control form-control-sm" type="file" name="foto_fisik_masuk" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-success btn-sm">Terima</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php if (isset($_GET['view']) && (int)$_GET['view'] > 0): ?>
<?php
$vid = (int)$_GET['view'];
$vSt = $pdo->prepare("SELECT * FROM wqs_stock_transfer WHERE id=? LIMIT 1");
$vSt->execute([$vid]);
$v = $vSt->fetch();
if ($v):
  $vItems = $pdo->prepare("SELECT * FROM wqs_stock_transfer_items WHERE transfer_id=? ORDER BY sku");
  $vItems->execute([$vid]);
  $vItems = $vItems->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="modal fade show d-block" id="viewModal" tabindex="-1" style="background:rgba(0,0,0,.7)">
  <div class="modal-dialog modal-lg">
    <div class="modal-content bg-dark">
      <div class="modal-header">
        <h6 class="modal-title"><?= h($v['transfer_code']) ?> — <?= h($v['from_office']) ?> → <?= h($v['to_office']) ?></h6>
        <a href="?" class="btn-close btn-close-white"></a>
      </div>
      <div class="modal-body">
        <div class="row mb-2">
          <div class="col-md-6">
            <strong>Pengirim:</strong> foto kartu stok
            <?php if (!empty($v['wqs_stock_before'])): ?><a href="<?= h($v['wqs_stock_before']) ?>" target="_blank">Before</a><?php endif; ?>
            <?php if (!empty($v['wqs_stock_after'])): ?> | <a href="<?= h($v['wqs_stock_after']) ?>" target="_blank">After</a><?php endif; ?>
            <br><strong>Foto fisik keluar:</strong> <?php echo !empty($v['foto_fisik_keluar']) ? '<a href="'.h($v['foto_fisik_keluar']).'" target="_blank">lihat</a>' : '-'; ?>
          </div>
          <div class="col-md-6">
            <strong>Penerima:</strong> foto kartu stok
            <?php if (!empty($v['wqs_stock_before_penerima'])): ?><a href="<?= h($v['wqs_stock_before_penerima']) ?>" target="_blank">Before</a><?php endif; ?>
            <?php if (!empty($v['wqs_stock_after_penerima'])): ?> | <a href="<?= h($v['wqs_stock_after_penerima']) ?>" target="_blank">After</a><?php endif; ?>
            <br><strong>Foto fisik masuk:</strong> <?php echo !empty($v['foto_fisik_masuk']) ? '<a href="'.h($v['foto_fisik_masuk']).'" target="_blank">lihat</a>' : '-'; ?>
          </div>
        </div>
        <table class="table table-dark table-sm">
          <thead><tr><th>SKU</th><th>Produk</th><th>Qty</th></tr></thead>
          <tbody>
          <?php foreach ($vItems as $i): ?>
          <tr><td><?= h($i['sku']) ?></td><td><?= h($i['product_name']) ?></td><td><?= number_format((float)$i['qty'], 0) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
const products = <?= json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'sku'=>strtoupper($p['sku']??''),'name'=>$p['products_name']??''], $products), JSON_UNESCAPED_UNICODE) ?>;
let itemIdx = 0;
function addItemRow() {
  const c = document.getElementById('itemsContainer');
  const d = document.createElement('div');
  d.className = 'row g-1 mb-1 align-items-center';
  d.innerHTML = `
    <div class="col-md-6">
      <select class="form-select form-select-sm" name="items[${itemIdx}][product_id]" required>
        <option value="">-- pilih produk --</option>
        ${products.map(p=>`<option value="${p.id}">${p.sku} — ${p.name}</option>`).join('')}
      </select>
    </div>
    <div class="col-md-3">
      <input class="form-control form-control-sm" type="number" name="items[${itemIdx}][qty]" min="1" required placeholder="Qty">
    </div>
    <div class="col-md-1">
      <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.parentElement.parentElement.remove()">×</button>
    </div>
  `;
  c.appendChild(d);
  itemIdx++;
}
addItemRow();
</script>

<?php if (!empty($audit_rows)): ?>
<div class="card mt-3">
  <div class="card-header">Audit Log (Last 50 events)</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-dark table-sm mb-0">
        <thead><tr><th>Time</th><th>Action</th><th>Code</th><th>User</th><th>Description</th></tr></thead>
        <tbody>
          <?php foreach ($audit_rows as $a): ?>
            <tr>
              <td><?= h($a['created_at'] ?? '') ?></td>
              <td><?= h($a['action'] ?? '') ?></td>
              <td><?= h($a['record_code'] ?? '') ?></td>
              <td><?= h($a['username'] ?? '') ?></td>
              <td><?= h($a['description'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>
