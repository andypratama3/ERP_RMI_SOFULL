<?php
/**
 * customer_portal/order_detail.php
 * Detail order + upload dokumen pendukung.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_portal_login();

$id = (int)($_GET['id'] ?? 0);
$created = isset($_GET['created']);

$pdo = rmi_db_pdo();
$user = portal_user();
$base = portal_base();

$stmt = $pdo->prepare("
    SELECT id, do_code, do_date, customers_code, shipping_address, customer_pic, customer_phone, status, note, total_amount, tax_amount, grand_total, created_at
    FROM sales_do
    WHERE id = ? AND customers_code = ?
");
$stmt->execute([$id, $user['customers_code']]);
$do = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$do) {
    rmi_redirect($base . '/customer_portal/orders.php');
}

$stmt = $pdo->prepare("
    SELECT line_no, sku, products_name, qty, unit, unit_price, disc_percent, subtotal
    FROM sales_do_items
    WHERE do_id = ?
    ORDER BY line_no
");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Dokumen pendukung (sales_do_portal_docs)
$portalDocs = [];
$hasPortalDocsTable = false;
try {
    $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sales_do_portal_docs'");
    $hasPortalDocsTable = $chk && $chk->fetch();
    if ($hasPortalDocsTable) {
        $st = $pdo->prepare("SELECT id, doc_type, file_name, file_rel, note, uploaded_by, created_at FROM sales_do_portal_docs WHERE do_id = ? ORDER BY id DESC");
        $st->execute([$id]);
        $portalDocs = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

function cportal_safe_filename(string $name): string {
    $name = preg_replace('/[^\w\-. ]+/u', '_', $name);
    $name = preg_replace('/\s+/', '_', $name);
    return trim($name, '._');
}

$flash = [];
if ($created) {
    $flash = ['type' => 'success', 'msg' => 'Order <strong>' . rmi_h($do['do_code']) . '</strong> berhasil dibuat. Staff CRM akan memproses pesanan Anda.'];
}

// POST: upload dokumen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasPortalDocsTable) {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'upload_doc') {
        $doc_type = strtoupper(trim((string)($_POST['doc_type'] ?? 'OTHER')));
        $doc_note = trim((string)($_POST['doc_note'] ?? ''));
        $allowed_types = ['PO', 'SURAT_PESANAN', 'LAINNYA'];
        if (!in_array($doc_type, $allowed_types, true)) {
            $doc_type = 'LAINNYA';
        }
        if (!isset($_FILES['doc_file']) || ($_FILES['doc_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash = ['type' => 'danger', 'msg' => 'File belum dipilih.'];
        } else {
            $orig = (string)($_FILES['doc_file']['name'] ?? 'file');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'zip'];
            if (!in_array($ext, $allowed_ext, true)) {
                $flash = ['type' => 'danger', 'msg' => 'Ekstensi tidak diizinkan. Allowed: ' . implode(', ', $allowed_ext)];
            } else {
                $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
                $do_code_safe = preg_replace('/[^a-zA-Z0-9\-_]/', '_', (string)($do['do_code'] ?? 'DO'));
                $upload_dir = $root . '/uploads/customer_portal/do/' . $do_code_safe;
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0775, true);
                }
                $base_name = cportal_safe_filename($doc_type . '_' . date('Ymd_His') . '_' . $orig);
                if ($base_name === '') $base_name = 'doc_' . date('Ymd_His') . '.' . $ext;
                $dest = $upload_dir . '/' . $base_name;
                if (!move_uploaded_file((string)$_FILES['doc_file']['tmp_name'], $dest)) {
                    $flash = ['type' => 'danger', 'msg' => 'Gagal menyimpan file.'];
                } else {
                    $file_rel = 'uploads/customer_portal/do/' . $do_code_safe . '/' . $base_name;
                    $pdo->prepare("INSERT INTO sales_do_portal_docs (do_id, doc_type, file_name, file_rel, note, uploaded_by, portal_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$id, $doc_type, $base_name, $file_rel, $doc_note ?: null, 'portal_' . $user['username'], $user['id'] ?? null]);
                    if (function_exists('master_audit')) {
                        $_SESSION['username'] = $_SESSION['username'] ?? 'portal_' . ($user['username'] ?? '');
                        master_audit($pdo, 'customer_portal', 'sales_do_portal_docs', 'UPLOAD_DOC', (int)$pdo->lastInsertId(), (string)($do['do_code'] ?? ''), "Portal doc: {$doc_type}", ['do_id' => $id]);
                    }
                    $flash = ['type' => 'success', 'msg' => 'Dokumen berhasil diupload.'];
                    $st = $pdo->prepare("SELECT id, doc_type, file_name, file_rel, note, uploaded_by, created_at FROM sales_do_portal_docs WHERE do_id = ? ORDER BY id DESC");
                    $st->execute([$id]);
                    $portalDocs = $st->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    }
}

$pageTitle = 'Detail Order ' . $do['do_code'];

function det_status_badge(string $status): string {
    $s = strtoupper(trim($status));
    $map = ['PAID'=>['status-paid','✅','Lunas'],'OPEN'=>['status-open','🟡','Open'],'PARTIAL'=>['status-partial','🔵','Partial'],'CANCELLED'=>['status-cancel','❌','Batal'],'DONE'=>['status-paid','✅','Selesai']];
    $d = $map[$s] ?? ['status-default','⚪',$status];
    return '<span class="status-badge '.$d[0].'">'.$d[1].' '.$d[2].'</span>';
}

ob_start();
?>

<?php if (!empty($flash)): ?>
  <div class="alert cp-alert alert-<?= rmi_h($flash['type']) ?> mb-4"><?= $flash['msg'] ?></div>
<?php endif; ?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
  <div>
    <div class="text-muted small mb-1">
      <a href="<?= rmi_h($base) ?>/customer_portal/orders.php" style="color:#16a34a;text-decoration:none">← Riwayat Order</a>
    </div>
    <h4 class="fw-bold mb-1">📋 <?= rmi_h($do['do_code']) ?></h4>
    <div><?= det_status_badge((string)$do['status']) ?></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= rmi_h($base) ?>/customer_portal/do_print.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary btn-sm">🖨️ Print DO</a>
    <a href="<?= rmi_h($base) ?>/customer_portal/catalog.php" class="btn btn-rmi btn-sm px-4">🛒 Order Lagi</a>
  </div>
</div>

<div class="row g-4">
  <!-- Order Info -->
  <div class="col-lg-4">
    <div class="cp-card mb-3">
      <div class="cp-card-header">📄 Info Order</div>
      <div class="p-3">
        <div class="mb-2" style="font-size:13px">
          <span class="text-muted">Tanggal</span><br>
          <strong><?= date('d F Y', strtotime($do['do_date'])) ?></strong>
        </div>
        <div class="mb-2" style="font-size:13px">
          <span class="text-muted">Status</span><br>
          <?= det_status_badge((string)$do['status']) ?>
        </div>
        <?php if (!empty($do['shipping_address'])): ?>
        <div class="mb-2" style="font-size:13px">
          <span class="text-muted">Alamat Pengiriman</span><br>
          <span><?= nl2br(rmi_h($do['shipping_address'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($do['customer_pic'])): ?>
        <div class="mb-2" style="font-size:13px">
          <span class="text-muted">PIC</span><br>
          <strong><?= rmi_h($do['customer_pic']) ?></strong>
          <?php if (!empty($do['customer_phone'])): ?>
            · <?= rmi_h($do['customer_phone']) ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($do['note'])): ?>
        <div style="font-size:13px;background:#fef9c3;border-radius:8px;padding:10px;margin-top:8px">
          📝 <?= rmi_h($do['note']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Total -->
    <div class="cp-card">
      <div class="p-3">
        <div class="d-flex justify-content-between mb-2" style="font-size:13px">
          <span class="text-muted">Subtotal</span>
          <span>Rp <?= number_format((float)$do['total_amount'],0,',','.') ?></span>
        </div>
        <?php if ((float)($do['tax_amount']??0) > 0): ?>
        <div class="d-flex justify-content-between mb-2" style="font-size:13px">
          <span class="text-muted">Pajak</span>
          <span>Rp <?= number_format((float)$do['tax_amount'],0,',','.') ?></span>
        </div>
        <?php endif; ?>
        <hr style="border-color:#f1f5f9">
        <div class="d-flex justify-content-between">
          <span class="fw-bold">Total</span>
          <span class="fw-bold" style="font-size:18px;color:#16a34a">Rp <?= number_format((float)$do['grand_total'],0,',','.') ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Items & Docs -->
  <div class="col-lg-8">

    <!-- Items -->
    <div class="cp-card mb-4">
      <div class="cp-card-header">📦 Item Pesanan</div>
      <div class="table-responsive">
        <table class="cp-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Produk</th>
              <th class="text-center">Qty</th>
              <th class="text-end">Harga</th>
              <th class="text-end">Subtotal</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td class="text-muted"><?= (int)$it['line_no'] ?></td>
              <td>
                <div class="fw-semibold" style="font-size:13px"><?= rmi_h($it['products_name']) ?></div>
                <div style="font-size:11px;color:#94a3b8;font-family:monospace"><?= rmi_h($it['sku']) ?></div>
              </td>
              <td class="text-center"><?= (int)$it['qty'] ?> <span class="text-muted" style="font-size:11px"><?= rmi_h($it['unit']) ?></span></td>
              <td class="text-end" style="font-size:13px">Rp <?= number_format((float)$it['unit_price'],0,',','.') ?></td>
              <td class="text-end fw-semibold" style="color:#16a34a">Rp <?= number_format((float)$it['subtotal'],0,',','.') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Dokumen -->
    <?php if ($hasPortalDocsTable): ?>
    <div class="cp-card">
      <div class="cp-card-header">📎 Dokumen Pendukung</div>
      <div class="p-3">
        <?php if (!empty($portalDocs)): ?>
          <div class="mb-3">
            <?php foreach ($portalDocs as $d): ?>
              <div class="d-flex align-items-center gap-3 p-2 mb-2" style="background:#f8fafc;border-radius:8px;font-size:13px">
                <span style="font-size:18px">📄</span>
                <div class="flex-grow-1">
                  <div class="fw-semibold"><?= rmi_h($d['file_name']) ?></div>
                  <div class="text-muted" style="font-size:11px"><?= rmi_h($d['doc_type']) ?> · <?= rmi_h($d['created_at']) ?></div>
                </div>
                <a href="<?= rmi_h($base) ?>/customer_portal/download_doc.php?id=<?= (int)$d['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success">⬇ Download</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Upload form -->
        <div style="border-top:1px solid #f1f5f9;padding-top:14px">
          <div class="fw-semibold mb-2" style="font-size:13px">+ Upload Dokumen</div>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= rmi_h(function_exists('csrf_token') ? csrf_token() : '') ?>">
            <input type="hidden" name="action" value="upload_doc">
            <div class="row g-2 align-items-end">
              <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold mb-1">Jenis Dokumen</label>
                <select name="doc_type" class="form-select form-select-sm">
                  <option value="PO">PO / Purchase Order</option>
                  <option value="SURAT_PESANAN">Surat Pesanan</option>
                  <option value="LAINNYA">Lainnya</option>
                </select>
              </div>
              <div class="col-12 col-md-5">
                <label class="form-label small fw-semibold mb-1">File (PDF, DOC, XLS, JPG, ZIP)</label>
                <input type="file" name="doc_file" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.zip" required>
              </div>
              <div class="col-12 col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-rmi btn-sm w-100">Upload</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
