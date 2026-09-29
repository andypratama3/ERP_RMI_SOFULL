<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once dirname(__DIR__, 1) . '/master/auth.php';
require_login();
// Salinan lama products_media_view.php. Tanpa gate ini file ini bisa dibaca
// user login mana pun (tanpa MASTER.PRODUCT_VIEW) via direct URL. Gate disamakan
// dengan pasangannya; tidak ada kode izin baru.
require_once __DIR__ . '/../_shared/rbac.php';
require_any_permission(['MASTER.PRODUCT_VIEW', 'MASTER_PRODUCTS.MEDIA_VIEW']);
// -------------------------------------------------------------

// products_media_view.php
// View media produk (foto & video) berdasarkan SKU (products_code)
// --- DB (centralized) ---
$pdo = db_pdo();

// --------------------------------------------------------
//  PARAMETER products_code (SKU)
// --------------------------------------------------------
$products_code = isset($_GET['products_code']) ? trim($_GET['products_code']) : '';

if ($products_code === '') {
    die("products_code tidak ditemukan. Akses halaman ini dari tombol <strong>Media</strong> di Master Products.");
}

// Ambil data produk
$stmt = $pdo->prepare("
    SELECT
        p.*,
        m.manufacture_name,
        v.vendors_name AS vendor_name
    FROM master_products p
    LEFT JOIN master_manufactures m ON m.id = p.manufacture_id
    LEFT JOIN master_vendors      v ON v.id = p.vendor_id
    WHERE p.sku = :sku
");
$stmt->execute([':sku' => $products_code]);
$product = $stmt->fetch();

if (!$product) {
    die("Data produk dengan code/SKU <strong>" . htmlspecialchars($products_code) . "</strong> tidak ditemukan.");
}

// --------------------------------------------------------
//  KONFIGURASI MEDIA
//  Folder fisik:   uploads/products/[SKU]/
//  URL di browser: uploads/products/[SKU]/
// --------------------------------------------------------
$mediaBaseDir = dirname(__DIR__) . '/uploads/products/';
$mediaBaseUrl = '../uploads/products/';

$productDir = $mediaBaseDir . $products_code . '/';
$productUrl = $mediaBaseUrl . rawurlencode($products_code) . '/';

$imageExts = ['jpg', 'jpeg', 'png', 'webp'];
$videoExts = ['mp4', 'mov', 'mkv', 'avi', 'webm'];

$bases = [
    'front'    => 'Front',
    'back'     => 'Back',
    'box'      => 'Box',
    'unpacked' => 'Unpacked',
];

// helper cari file (1 file saja) untuk gambar / video
function pmv_find_file($dir, $base, $exts)
{
    foreach ($exts as $ext) {
        $path = $dir . $base . '.' . $ext;
        if (file_exists($path)) {
            return basename($path);
        }
    }
    return null;
}

// buat array data media per posisi (front/back/box/unpacked)
$mediaRows = [];
foreach ($bases as $baseKey => $baseLabel) {
    $imgFile   = is_dir($productDir) ? pmv_find_file($productDir, $baseKey, $imageExts) : null;
    $videoFile = is_dir($productDir) ? pmv_find_file($productDir, $baseKey, $videoExts) : null;

    $mediaRows[] = [
        'key'        => $baseKey,
        'label'      => $baseLabel,
        'img_file'   => $imgFile,
        'video_file' => $videoFile,
    ];
}

// --------------------------------------------------------
//  FRONTEND DARK THEME
// --------------------------------------------------------
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Products Media View', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Products Media View',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>


<div class="rmi-container">

    <!-- Header Produk -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>MEDIA PRODUK</h5>
                <small class="text-muted">
                    SKU: <?= htmlspecialchars(strtoupper($product['sku'] ?? '')) ?> ·
                    <?= htmlspecialchars(strtoupper($product['products_name'] ?? '')) ?>
                </small>
            </div>
            <div>
                <a href="master_products.php" class="btn btn-sm btn-secondary">
                    &laquo; Kembali ke Master Products
                </a>
            </div>
        </div>
        <div class="rmi-card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div><strong>Product Group:</strong>
                        <?= htmlspecialchars($product['product_group'] ?: '-') ?>
                    </div>
                    <div><strong>No AKL:</strong>
                        <?= htmlspecialchars($product['no_akl'] ?: '-') ?>
                    </div>
                    <div><strong>Kategori:</strong>
                        <?= htmlspecialchars($product['category'] ?: '-') ?>
                    </div>
                    <div><strong>Satuan:</strong>
                        <?= htmlspecialchars($product['unit'] ?: '-') ?>
                    </div>
                    <div><strong>Harga:</strong>
                        <?= number_format((float)$product['price'], 2, ',', '.') ?>
                    </div>
                    <div class="mt-1">
                        <strong>Status:</strong>
                        <?php if (($product['status'] ?? 'active') === 'active'): ?>
                            <span class="badge badge-status active">Active</span>
                        <?php else: ?>
                            <span class="badge badge-status inactive">Inactive</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div><strong>Manufacture:</strong>
                        <?= htmlspecialchars($product['manufacture_name'] ?: '-') ?>
                    </div>
                    <div><strong>Vendor:</strong>
                        <?= htmlspecialchars($product['vendor_name'] ?: '-') ?>
                    </div>
                    <div><strong>Exp Date:</strong>
                        <?php if (!empty($product['exp_date']) && $product['exp_date'] !== '0000-00-00'): ?>
                            <?= date('d-m-Y', strtotime($product['exp_date'])) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </div>
                    <div><strong>Barcode:</strong>
                        <?= htmlspecialchars($product['barcode'] ?: '-') ?>
                    </div>
                    <div class="text-muted-small mt-2">
                        Folder media: <code>uploads/products/<?= htmlspecialchars($products_code) ?>/</code><br>
                        Nama file dipakai: <strong>front</strong>, <strong>back</strong>, <strong>box</strong>, <strong>unpacked</strong>
                        dengan ekstensi gambar (jpg, png, webp) atau video (mp4, dll).
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Grid Media -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5>MEDIA FOTO & VIDEO</h5>
        </div>
        <div class="rmi-card-body">
            <div class="row g-4">
                <?php foreach ($mediaRows as $row): ?>
                    <div class="col-md-6">
                        <h6 class="mb-2"><?= htmlspecialchars($row['label']) ?></h6>

                        <div class="mb-2">
                            <strong>Foto <?= htmlspecialchars($row['label']) ?>:</strong><br>
                            <?php if ($row['img_file']): ?>
                                <a href="<?= $productUrl . rawurlencode($row['img_file']) ?>" target="_blank">
                                    <img src="<?= $productUrl . rawurlencode($row['img_file']) ?>"
                                         class="thumb-img-big"
                                         alt="Foto <?= htmlspecialchars($row['label']) ?>">
                                </a>
                            <?php else: ?>
                                <span class="text-muted">Belum ada foto.</span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <strong>Video <?= htmlspecialchars($row['label']) ?>:</strong><br>
                            <?php if ($row['video_file']): ?>
                                <video controls>
                                    <source src="<?= $productUrl . rawurlencode($row['video_file']) ?>">
                                    Browser tidak mendukung video tag.
                                </video>
                            <?php else: ?>
                                <span class="text-muted">Belum ada video.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!is_dir($productDir)): ?>
                <div class="mt-3 text-warning">
                    Folder media belum dibuat untuk produk ini.
                    Upload foto/video dulu melalui modul <strong>Master Products</strong>.
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<?php rmi_footer(); ?>
