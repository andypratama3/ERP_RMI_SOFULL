<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/_audit_master.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.PRODUCT_PACKAGE_VIEW', 'MASTER.PRODUCT_PACKAGE_CREATE', 'MASTER.PRODUCT_PACKAGE_EDIT', 'MASTER.VIEW']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN']);
}
// master_products_package.php
// Kelola Produk tipe PAKET (header + komposisi item) di tabel master_products.
// NOTE: File ini ditulis ulang agar tidak bergantung ke config/koneksi.php (yang tidak ada di ZIP).


if (session_status() === PHP_SESSION_NONE) { session_start(); }

// --------------------------------------------------------
//  KONEKSI DB - samakan dengan master_* lain
// --------------------------------------------------------
// --- DB (centralized) ---
$pdo = db_pdo();

if (!function_exists('h')) {

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

}


// --------------------------------------------------------
// FLASH
// --------------------------------------------------------
function set_flash_pkg($type, $msg){
    $_SESSION['flash_master_products_pkg'] = ['type'=>$type,'message'=>$msg];
}
function get_flash_pkg(){
    if (!empty($_SESSION['flash_master_products_pkg'])) {
        $f = $_SESSION['flash_master_products_pkg'];
        unset($_SESSION['flash_master_products_pkg']);
        return $f;
    }
    return null;
}

// --------------------------------------------------------
// LOAD OPTIONS: Produk SINGLE (untuk isi paket)
// --------------------------------------------------------
$singleProducts = [];
try {
    $singleProducts = $pdo->query("SELECT id, sku, products_name FROM master_products WHERE product_type='SINGLE' AND status='active' ORDER BY products_name")->fetchAll();
} catch (Throwable $e) {
    $singleProducts = [];
}

$singleMap = [];
foreach ($singleProducts as $sp) {
    $singleMap[(int)$sp['id']] = $sp;
}

// --------------------------------------------------------
// DELETE (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_post();
    verify_csrf();

    $delete_id = (int)($_POST['delete_id'] ?? 0);
    if ($delete_id > 0) {
        $stCode = $pdo->prepare("SELECT sku, products_name FROM master_products WHERE id=:id AND product_type='PAKET' LIMIT 1");
        $stCode->execute([':id' => $delete_id]);
        $row = $stCode->fetch(PDO::FETCH_ASSOC);
        $code = $row ? ($row['sku'] ?? 'PKG#' . $delete_id) : 'PKG#' . $delete_id;
        $pdo->prepare("UPDATE master_products SET status='inactive' WHERE id=:id AND product_type='PAKET'")
            ->execute([':id' => $delete_id]);
        if (function_exists('master_audit')) {
            master_audit($pdo, 'master_products_package', 'master_products', 'DEACTIVATE', $delete_id, $code, "Package deactivated: {$code}", []);
        }
        set_flash_pkg('success', 'Paket berhasil dinonaktifkan.');
    }
    rmi_redirect('master_products_package.php');
}

// --------------------------------------------------------
// EDIT LOAD
// --------------------------------------------------------
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit = null;
$editItems = [];
if ($edit_id > 0) {
    $st = $pdo->prepare("SELECT * FROM master_products WHERE id=:id AND product_type='PAKET' LIMIT 1");
    $st->execute([':id'=>$edit_id]);
    $edit = $st->fetch() ?: null;
    if ($edit && !empty($edit['package_items'])) {
        $decoded = json_decode($edit['package_items'], true);
        if (is_array($decoded)) $editItems = $decoded;
    }
}

// --------------------------------------------------------
// SAVE
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_package') {
    require_post();
    verify_csrf();
    try {
        $package_id    = isset($_POST['package_id']) && $_POST['package_id'] !== '' ? (int)$_POST['package_id'] : null;
        $sku           = strtoupper(trim((string)($_POST['sku'] ?? '')));
        $products_name = function_exists('rmi_product_name') ? rmi_product_name($_POST['products_name'] ?? '') : strtoupper(trim((string)($_POST['products_name'] ?? '')));
        $price_raw     = str_replace([','], [''], (string)($_POST['price'] ?? '0'));
        $price         = is_numeric($price_raw) ? (float)$price_raw : 0;
        $status        = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $pkg_category  = strtoupper(trim((string)($_POST['pkg_category'] ?? 'BMHP')));
        if (!in_array($pkg_category, ['BMHP','ALKES','AKSESORIS'], true)) $pkg_category = 'BMHP';

        $detail_product_id = $_POST['detail_product_id'] ?? [];
        $detail_qty        = $_POST['detail_qty'] ?? [];

        $errors = [];
        if ($sku === '') $errors[] = 'SKU / Kode Paket wajib diisi.';
        if ($products_name === '') $errors[] = 'Nama Paket wajib diisi.';
        if ($price_raw === '' || !is_numeric($price_raw)) $errors[] = 'Harga Paket tidak valid.';

        $items = [];
        if (is_array($detail_product_id)) {
            foreach ($detail_product_id as $idx => $pid) {
                $pid_int = (int)$pid;
                $qty_val = isset($detail_qty[$idx]) ? (float)$detail_qty[$idx] : 0;
                if ($pid_int > 0 && $qty_val > 0) {
                    $items[] = ['product_id'=>$pid_int, 'qty'=>$qty_val];
                }
            }
        }
        if (empty($items)) $errors[] = 'Minimal 1 produk isi paket dengan qty > 0.';

        if (!empty($errors)) {
            throw new Exception(implode("\n", $errors));
        }

        // Unique SKU
        if ($package_id === null) {
            $chk = $pdo->prepare("SELECT id FROM master_products WHERE sku=:sku LIMIT 1");
            $chk->execute([':sku'=>$sku]);
            if ($chk->fetch()) throw new Exception('SKU sudah dipakai. Gunakan SKU lain.');
        } else {
            $chk = $pdo->prepare("SELECT id FROM master_products WHERE sku=:sku AND id<>:id LIMIT 1");
            $chk->execute([':sku'=>$sku, ':id'=>$package_id]);
            if ($chk->fetch()) throw new Exception('SKU sudah dipakai produk lain.');
        }

        // merge duplicate items by product_id
        $merged = [];
        foreach ($items as $it) {
            $pid = (int)$it['product_id'];
            $qty = (float)$it['qty'];
            if (!isset($merged[$pid])) $merged[$pid] = 0;
            $merged[$pid] += $qty;
        }
        $items = [];
        foreach ($merged as $pid=>$qty) {
            if ($qty > 0) $items[] = ['product_id'=>$pid, 'qty'=>$qty];
        }

        $package_items_json = json_encode($items, JSON_UNESCAPED_UNICODE);

        if ($package_id === null) {
            $st = $pdo->prepare("INSERT INTO master_products
                (sku, products_name, price, unit, status, product_type, category, package_items, created_at, updated_at)
                VALUES
                (:sku, :products_name, :price, 'PAKET', :status, 'PAKET', :category, :package_items, NOW(), NOW())");
            $st->execute([
                ':sku'=>$sku,
                ':products_name'=>$products_name,
                ':price'=>$price,
                ':status'=>$status,
                ':category'=>$pkg_category,
                ':package_items'=>$package_items_json,
            ]);
        } else {
            $st = $pdo->prepare("UPDATE master_products SET
                sku=:sku,
                products_name=:products_name,
                price=:price,
                unit='PAKET',
                status=:status,
                product_type='PAKET',
                category=:category,
                package_items=:package_items,
                updated_at=NOW()
                WHERE id=:id AND product_type='PAKET'");
            $st->execute([
                ':id'=>$package_id,
                ':sku'=>$sku,
                ':products_name'=>$products_name,
                ':price'=>$price,
                ':status'=>$status,
                ':category'=>$pkg_category,
                ':package_items'=>$package_items_json,
            ]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_products_package', 'master_products', 'UPDATE', $package_id, $sku, "Package updated: {$sku} - {$products_name}", []);
            }
        }

        set_flash_pkg('success', 'Paket berhasil disimpan.');
        rmi_redirect('master_products_package.php');

    } catch (Throwable $e) {
        set_flash_pkg('danger', 'Gagal simpan paket: ' . nl2br(h($e->getMessage())));
        $back = 'master_products_package.php' . (isset($_POST['package_id']) && $_POST['package_id'] !== '' ? ('?edit_id=' . (int)$_POST['package_id']) : '');
        rmi_redirect($back);
    }
}

// --------------------------------------------------------
// LIST PAKET
// --------------------------------------------------------
$packages = [];
try {
    $packages = $pdo->query("SELECT id, sku, products_name, price, status, package_items, created_at, updated_at
        FROM master_products
        WHERE product_type='PAKET'
        ORDER BY updated_at DESC, id DESC")->fetchAll();
} catch (Throwable $e) {
    $packages = [];
}

$flash = get_flash_pkg();

// form default
$form = [
    'package_id' => $edit['id'] ?? '',
    'sku'        => $edit['sku'] ?? '',
    'products_name' => $edit['products_name'] ?? '',
    'price'      => $edit['price'] ?? 0,
    'status'     => $edit['status'] ?? 'active',
    'category'   => strtoupper(trim((string)($edit['category'] ?? 'BMHP'))),
];

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Products - Paket', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Products - Paket',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>

<div class="rmi-container">

    <div class="rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>Master Products - Paket</h5>
                <div class="muted">Kelola produk tipe PAKET + komposisi item (mengambil produk SINGLE aktif).</div>
            </div>
            <div class="d-flex gap-2">
                <a href="master_products.php" class="btn btn-sm btn-soft">← Master Products</a>
                <a href="master_data.php" class="btn btn-sm btn-soft">Master Data</a>
                <a href="master_products_package.php" class="btn btn-sm btn-soft">Refresh</a>
            </div>
        </div>
        <div class="rmi-card-body">

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?>" style="border-radius:12px;">
                    <?= function_exists('rmi_h') ? rmi_h($flash['message'] ?? '') : htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <div class="rmi-card" style="margin-bottom:0;">
                <div class="rmi-card-header">
                    <h5><?= $edit ? 'Edit Paket' : 'Tambah Paket' ?></h5>
                    <div class="muted">Unit otomatis: <b>PAKET</b>. Minimal 1 item isi paket.</div>
                </div>
                <div class="rmi-card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="action" value="save_package">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="package_id" value="<?= h($form['package_id']) ?>">

                        <div class="col-md-3">
                            <label class="form-label">SKU Paket <span class="text-danger">*</span></label>
                            <input name="sku" class="form-control" value="<?= h($form['sku']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nama Paket <span class="text-danger">*</span></label>
                            <input name="products_name" class="form-control" value="<?= h($form['products_name']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= $form['status']==='active'?'selected':''; ?>>active</option>
                                <option value="inactive" <?= $form['status']==='inactive'?'selected':''; ?>>inactive</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Kategori Paket <span class="text-danger">*</span></label>
                            <?php $pkgCatVal = strtoupper(trim((string)($form['category'] ?? 'BMHP'))); if (!in_array($pkgCatVal,['BMHP','ALKES','AKSESORIS'],true)) $pkgCatVal='BMHP'; ?>
                            <select name="pkg_category" class="form-select">
                                <option value="BMHP"      <?= $pkgCatVal==='BMHP'?'selected':''      ?>><?= rmi_icon('box') ?> BMHP — Habis Pakai</option>
                                <option value="ALKES"     <?= $pkgCatVal==='ALKES'?'selected':''     ?>><?= rmi_icon('cross') ?> ALKES — Alat Kesehatan</option>
                                <option value="AKSESORIS" <?= $pkgCatVal==='AKSESORIS'?'selected':'' ?>><?= rmi_icon('zap') ?> AKSESORIS</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Harga Paket <span class="text-danger">*</span></label>
                            <input name="price" type="number" step="0.01" class="form-control" value="<?= h($form['price']) ?>" required>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label">Komposisi Item (produk SINGLE)</label>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-detail align-middle">
                                    <thead>
                                        <tr>
                                            <th style="width:55%;">Produk</th>
                                            <th style="width:25%;">Qty</th>
                                            <th style="width:20%;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pkgItems">
                                        <?php
                                        $rowsItems = !empty($editItems) ? $editItems : [['product_id'=>'','qty'=>'']];
                                        foreach ($rowsItems as $it):
                                            $pid = (int)($it['product_id'] ?? 0);
                                            $qty = (float)($it['qty'] ?? 0);
                                        ?>
                                            <tr>
                                                <td>
                                                    <select name="detail_product_id[]" class="form-select" required>
                                                        <option value="">- pilih produk -</option>
                                                        <?php foreach ($singleProducts as $sp): ?>
                                                            <option value="<?= (int)$sp['id'] ?>" <?= $pid===(int)$sp['id']?'selected':''; ?>>
                                                                <?= h(strtoupper($sp['sku'] ?? '') . ' - ' . strtoupper($sp['products_name'] ?? '')) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input name="detail_qty[]" type="number" step="0.01" min="0" class="form-control" value="<?= h($qty) ?>" required>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">Hapus</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-soft" onclick="addRow()">+ Tambah Item</button>
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary" type="submit">Simpan Paket</button>
                            <a href="master_products_package.php" class="btn btn-soft">Reset</a>
                            <a href="master_products.php" class="btn btn-warning">Kembali</a>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <div class="rmi-card">
        <div class="rmi-card-header">
            <h5>Daftar Paket</h5>
            <div class="muted">Paket yang inactive tidak dipakai di transaksi (filter di sales).</div>
        </div>
        <div class="rmi-card-body">
            <div class="table-responsive">
                <table id="tblPkg" class="table table-striped align-middle" style="width:100%">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Nama Paket</th>
                            <th>Harga</th>
                            <th>Status</th>
                            <th>Items</th>
                            <th>Updated</th>
                            <th style="width:170px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($packages as $p):
                        $items = [];
                        if (!empty($p['package_items'])) {
                            $d = json_decode($p['package_items'], true);
                            if (is_array($d)) $items = $d;
                        }
                        $itemsCount = count($items);
                    ?>
                        <tr>
                            <td><code><?= h(strtoupper($p['sku'] ?? '')) ?></code></td>
                            <td><?= h(strtoupper($p['products_name'] ?? '')) ?></td>
                            <td><?= number_format((float)$p['price'], 2, '.', ',') ?></td>
                            <td><?= $p['status']==='active' ? '<span class="badge text-bg-success">ACTIVE</span>' : '<span class="badge text-bg-secondary">INACTIVE</span>' ?></td>
                            <td>
                                <span class="badge text-bg-info"><?= (int)$itemsCount ?></span>
                                <?php if ($itemsCount > 0): ?>
                                    <div class="muted" style="font-size:12px; margin-top:4px;">
                                        <?php
                                        $shown = 0;
                                        foreach ($items as $it) {
                                            $pid = (int)($it['product_id'] ?? 0);
                                            $qty = (float)($it['qty'] ?? 0);
                                            if ($pid <= 0) continue;
                                            $label = isset($singleMap[$pid]) ? (strtoupper($singleMap[$pid]['sku'] ?? '') . ' ' . strtoupper($singleMap[$pid]['products_name'] ?? '')) : ('ID #' . $pid);
                                            echo h($label) . ' x ' . h($qty);
                                            $shown++;
                                            if ($shown >= 2) { echo ' …'; break; }
                                            echo '<br>';
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= h($p['updated_at']) ?></td>
                            <td>
                                <a class="btn btn-sm btn-primary" href="master_products_package.php?edit_id=<?= (int)$p['id'] ?>">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Nonaktifkan paket ini?')">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="delete_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Nonaktif</button>
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

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209"></script>

<script>
function addRow(){
    const tbody = document.getElementById('pkgItems');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <select name="detail_product_id[]" class="form-select" required>
                <option value="">- pilih produk -</option>
                <?php foreach ($singleProducts as $sp): ?>
                    <option value="<?= (int)$sp['id'] ?>"><?= h(strtoupper($sp['sku'] ?? '') . ' - ' . strtoupper($sp['products_name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input name="detail_qty[]" type="number" step="0.01" min="0" class="form-control" value="1" required></td>
        <td><button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">Hapus</button></td>
    `;
    tbody.appendChild(tr);
}
function removeRow(btn){
    const tr = btn.closest('tr');
    const tbody = document.getElementById('pkgItems');
    if (tbody.children.length <= 1) {
        alert('Minimal 1 baris item.');
        return;
    }
    tr.remove();
}

$(function(){
    $('#tblPkg').DataTable({
        pageLength: 25,
        order: [[5,'desc']],
    });
});
</script>
<?php rmi_footer(); ?>
