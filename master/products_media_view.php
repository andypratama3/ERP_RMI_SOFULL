<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once dirname(__DIR__, 1) . '/master/auth.php';
require_login();
require_any_permission(['MASTER.PRODUCT_VIEW', 'MASTER_PRODUCTS.MEDIA_VIEW']);
// -------------------------------------------------------------

// products_media_view.php
// View media produk (foto & video) berdasarkan SKU (products_code)
// --- DB (centralized) ---
$pdo = db_pdo();
if (!function_exists('h')) {
    function h($v) {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

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
//  KONFIGURASI MEDIA PRODUK — STRICT TERPISAH DARI FIXED ASSET
//
//  PRODUK      : /uploads/products/{SKU}/
//  FIXED ASSET : /uploads/assets/{ASSET_REGISTER}/
//
//  Halaman ini TIDAK PERNAH membaca /uploads/assets.
// --------------------------------------------------------
$isUnitAcc = strtoupper(trim((string)($product['business_group'] ?? ''))) === 'UNIT_ACC';
$mediaRootDir = dirname(__DIR__) . '/uploads/products/';
$mediaRootUrl = '../uploads/products/';

/**
 * Synology/Linux bersifat case-sensitive. Folder UNIT ACC lama di NAS bisa berupa
 * `UNIT ACC` adalah canonical. Reader juga mengenali variasi lama `unit acc`, `Unit Acc`, dan `UNIT_ACC`. Reader harus mengenali keduanya
 * supaya foto existing tidak dianggap hilang.
 */
function pmv_unit_acc_base_candidates(string $mediaRootDir, string $mediaRootUrl): array {
    $aliases = ['UNIT ACC', 'unit acc', 'Unit Acc', 'UNIT_ACC', 'unit_acc'];
    $out = [];
    $seen = [];
    foreach ($aliases as $name) {
        $dir = rtrim($mediaRootDir, '/\\') . '/' . $name . '/';
        $key = str_replace('\\', '/', $dir);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['name'=>$name,'dir'=>$dir,'url'=>rtrim($mediaRootUrl,'/') . '/' . rawurlencode($name) . '/','exists'=>is_dir($dir)];
    }
    if (is_dir($mediaRootDir)) {
        $list = @scandir($mediaRootDir);
        if (is_array($list)) {
            foreach ($list as $name) {
                if ($name==='.' || $name==='..' || !is_dir($mediaRootDir.$name)) continue;
                $norm = strtolower(str_replace(['_','-'],' ',trim($name)));
                $norm = preg_replace('/\s+/',' ',trim($norm));
                if ($norm !== 'unit acc') continue;
                $dir = rtrim($mediaRootDir, '/\\') . '/' . $name . '/';
                $key = str_replace('\\','/',$dir);
                if (isset($seen[$key])) continue;
                $seen[$key]=true;
                $out[]=['name'=>$name,'dir'=>$dir,'url'=>rtrim($mediaRootUrl,'/') . '/' . rawurlencode($name) . '/','exists'=>true];
            }
        }
    }
    return $out;
}

$unitAccBases = $isUnitAcc ? pmv_unit_acc_base_candidates($mediaRootDir, $mediaRootUrl) : [];
$mediaBaseDir = $isUnitAcc ? ($mediaRootDir . 'UNIT ACC/') : $mediaRootDir;
$mediaBaseUrl = $isUnitAcc ? ($mediaRootUrl . 'UNIT%20ACC/') : $mediaRootUrl;

function pmv_media_folder_name(string $sku): string {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($sku));
    $safe = preg_replace('/_{2,}/', '_', (string)$safe);
    return trim((string)$safe, '_');
}

/**
 * One-time migration media produk Unit ACC.
 * Target canonical Unit ACC:
 *   /uploads/products/UNIT ACC/{SKU}/
 *
 * Sumber legacy yang diperbolehkan hanya untuk migrasi satu kali:
 *   /uploads/products/{SKU}/
 *   /uploads/assets/{SKU}/
 * Setelah file dipindahkan, pembacaan media tetap STRICT dari area products.
 */
function pmv_migrate_legacy_misplaced_product_media(string $sku, bool $isUnitAcc): array {
    $safe = pmv_media_folder_name($sku);
    if ($safe === '') return ['moved'=>0, 'renamed'=>0, 'errors'=>[]];

    $root = dirname(__DIR__);
    $targetDir = $isUnitAcc
        ? ($root . '/uploads/products/UNIT ACC/' . $safe)
        : ($root . '/uploads/products/' . $safe);

    $legacyDirs = [];
    if ($isUnitAcc) {
        $legacyDirs[] = $root . '/uploads/products/' . $safe; // lokasi produk lama
        foreach (['unit acc','Unit Acc','UNIT_ACC','unit_acc'] as $legacyGroupDir) {
            $legacyDirs[] = $root . '/uploads/products/' . $legacyGroupDir . '/' . $safe;
        }
    }
    $legacyDirs[] = $root . '/uploads/assets/' . $safe; // salah lokasi historis

    $allowedBases = ['front','back','box','unpacked'];
    $allowedExts  = ['jpg','jpeg','png','webp','mp4','mov','mkv','avi','webm'];

    $moved = 0;
    $renamed = 0;
    $errors = [];

    foreach ($legacyDirs as $legacyDir) {
        if (!is_dir($legacyDir) || realpath($legacyDir) === realpath($targetDir)) continue;
        $files = @scandir($legacyDir);
        if (!is_array($files)) { $errors[] = 'legacy folder tidak dapat dibaca: ' . basename($legacyDir); continue; }

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $errors[] = 'folder target products tidak dapat dibuat';
            continue;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !is_file($legacyDir . '/' . $file)) continue;

            $lower = strtolower($file);
            $lastExt = strtolower(pathinfo($lower, PATHINFO_EXTENSION));
            if (!in_array($lastExt, $allowedExts, true)) continue;

            $stem = $lower;
            do {
                $before = $stem;
                foreach ($allowedExts as $ext) {
                    $stem = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', $stem);
                }
            } while ($stem !== $before);

            if (!in_array($stem, $allowedBases, true)) continue;

            // Untuk Unit ACC, canonical wajib 4 JPG + 2 MP4.
            if ($isUnitAcc) {
                $isImage = in_array($lastExt, ['jpg','jpeg','png','webp'], true);
                $isVideo = in_array($lastExt, ['mp4','mov','mkv','avi','webm'], true);
                if ($isImage) {
                    $canonical = $stem . '.jpg';
                } elseif ($isVideo && in_array($stem, ['front','unpacked'], true)) {
                    $canonical = $stem . '.mp4';
                } else {
                    continue;
                }
            } else {
                $canonical = $stem . '.' . $lastExt;
            }

            $src = $legacyDir . '/' . $file;
            $dst = $targetDir . '/' . $canonical;
            if (is_file($dst)) continue;

            // Rename hanya bila ekstensi tidak perlu konversi byte-content.
            // JPEG .jpeg -> .jpg aman; format PNG/WEBP/MOV tidak dipalsukan menjadi JPG/MP4.
            $sourceExt = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $targetExt = strtolower(pathinfo($canonical, PATHINFO_EXTENSION));
            $compatibleRename = ($sourceExt === $targetExt)
                || ($targetExt === 'jpg' && in_array($sourceExt, ['jpg','jpeg'], true));
            if (!$compatibleRename) continue;

            if (@rename($src, $dst)) {
                $moved++;
                if ($file !== $canonical) $renamed++;
            } else {
                $errors[] = 'gagal pindah: ' . $file;
            }
        }

        $remain = @scandir($legacyDir);
        if (is_array($remain)) {
            $remain = array_values(array_diff($remain, ['.','..']));
            if (count($remain) === 0) @rmdir($legacyDir);
        }
    }

    return ['moved'=>$moved, 'renamed'=>$renamed, 'errors'=>$errors];
}

// Jalankan migrasi sekali saat halaman produk dibuka.
// Setelah itu pembacaan media tetap STRICT dari /uploads/products saja.
$pmvLegacyMigration = pmv_migrate_legacy_misplaced_product_media($products_code, $isUnitAcc);

/** Cari folder SKU secara aman dan case-insensitive di area products saja. */
function pmv_product_dir_candidates(string $mediaBaseDir, string $mediaBaseUrl, string $sku): array {
    $safe = pmv_media_folder_name($sku);
    if ($safe === '') return [];

    $names = [$safe];

    // Toleransi case folder existing (mis. fa07 vs FA07), tetap hanya dalam uploads/products.
    if (is_dir($mediaBaseDir)) {
        $list = @scandir($mediaBaseDir);
        if (is_array($list)) {
            foreach ($list as $name) {
                if ($name === '.' || $name === '..') continue;
                if (!is_dir($mediaBaseDir . $name)) continue;
                if (strcasecmp($name, $safe) === 0 && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }
    }

    $out = [];
    foreach ($names as $folderName) {
        if ($folderName === '' || str_contains($folderName, '/') || str_contains($folderName, '\\')) continue;
        $out[] = [
            'name' => $folderName,
            'dir'  => $mediaBaseDir . $folderName . '/',
            'url'  => $mediaBaseUrl . rawurlencode($folderName) . '/',
        ];
    }
    return $out;
}

$skuSafe = pmv_media_folder_name($products_code);
$dirCandidates = [];
if ($isUnitAcc) {
    foreach ($unitAccBases as $base) {
        if (!empty($base['exists'])) {
            $dirCandidates = array_merge($dirCandidates, pmv_product_dir_candidates($base['dir'], $base['url'], $products_code));
        }
    }
    $dirCandidates = array_merge($dirCandidates, pmv_product_dir_candidates($mediaBaseDir, $mediaBaseUrl, $products_code));
    $seenCandidate=[];
    $dirCandidates=array_values(array_filter($dirCandidates,function($dc) use (&$seenCandidate){
        $key=str_replace('\\','/',(string)($dc['dir']??''));
        if($key===''||isset($seenCandidate[$key])) return false;
        $seenCandidate[$key]=true; return true;
    }));
} else {
    $dirCandidates = pmv_product_dir_candidates($mediaBaseDir, $mediaBaseUrl, $products_code);
}

$imageExts = ['jpg', 'jpeg', 'png', 'webp'];
$videoExts = ['mp4', 'mov', 'mkv', 'avi', 'webm'];

$bases = [
    'front'    => 'Front',
    'back'     => 'Back',
    'box'      => 'Box',
    'unpacked' => 'Unpacked',
];

/**
 * Cari media berdasarkan nama dasar.
 * Mendukung front.jpg, front.jpeg, front.png, front.webp
 * dan legacy double-extension seperti front.jpg.jpg atau front.jpg.png.
 *
 * Bila folder writable dan tidak bentrok, double-extension dirapikan otomatis.
 */
function pmv_find_file(string $dir, string $base, array $exts): ?string
{
    if (!is_dir($dir)) return null;

    $exts = array_values(array_unique(array_map('strtolower', $exts)));

    // Canonical persis lebih dulu.
    foreach ($exts as $ext) {
        $canonical = $base . '.' . $ext;
        if (is_file($dir . $canonical)) return $canonical;
    }

    $files = @scandir($dir);
    if (!is_array($files)) return null;

    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || !is_file($dir . $file)) continue;

        $lower = strtolower($file);
        $lastExt = strtolower(pathinfo($lower, PATHINFO_EXTENSION));
        if (!in_array($lastExt, $exts, true)) continue;

        // Buang seluruh extension media berulang:
        // front.jpg.jpg -> front ; FRONT.JPG -> front.
        $stem = $lower;
        do {
            $before = $stem;
            foreach ($exts as $ext) {
                $stem = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', $stem);
            }
        } while ($stem !== $before);

        if ($stem !== strtolower($base)) continue;

        $canonical = $base . '.' . $lastExt;
        $canonicalPath = $dir . $canonical;

        if ($file !== $canonical && !file_exists($canonicalPath) && is_writable($dir)) {
            if (@rename($dir . $file, $canonicalPath)) {
                return $canonical;
            }
        }

        // Tetap bisa ditampilkan walau rename tidak diizinkan.
        return $file;
    }

    return null;
}

/**
 * Ubah file_path DB menjadi URL hanya bila:
 * 1) path benar-benar berada di /uploads/products/,
 * 2) file fisiknya ada.
 *
 * Path /uploads/assets/ sengaja ditolak agar Produk dan Fixed Asset tidak tercampur.
 */
function pmv_valid_product_db_media_url(string $filePath, bool $isUnitAcc, string $skuSafe): ?string {
    $filePath = trim(str_replace('\\', '/', $filePath));
    if ($filePath === '') return null;
    $relative = $filePath;
    if (str_starts_with($relative, '../')) $relative = substr($relative, 3);
    if (str_starts_with($relative, '/')) $relative = ltrim($relative, '/');
    if (!str_starts_with($relative, 'uploads/products/')) return null;

    $tail = substr($relative, strlen('uploads/products/'));
    $parts = array_values(array_filter(explode('/', $tail), fn($v) => $v !== ''));
    if (count($parts) < 2) return null;
    foreach ($parts as $part) if ($part === '.' || $part === '..') return null;

    if ($isUnitAcc) {
        if (count($parts) < 3) return null;
        $groupNorm = strtolower(str_replace(['_','-'], ' ', (string)$parts[0]));
        $groupNorm = preg_replace('/\s+/', ' ', trim($groupNorm));
        if ($groupNorm !== 'unit acc') return null;
        if (strcasecmp((string)$parts[1], $skuSafe) !== 0) return null;
    } else {
        if (strcasecmp((string)$parts[0], $skuSafe) !== 0) return null;
    }

    $full = dirname(__DIR__) . '/uploads/products/' . implode('/', $parts);
    if (!is_file($full)) return null;
    $encoded = array_map('rawurlencode', $parts);
    return '../uploads/products/' . implode('/', $encoded);
}

$mediaRows = [];

$mediaFromDb = [];
try {
    $stMedia = $pdo->prepare("
        SELECT *
        FROM master_product_media
        WHERE sku = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stMedia->execute([$products_code]);
    $mediaFromDb = $stMedia->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Tabel media bersifat opsional; folder fisik tetap menjadi fallback canonical.
    $mediaFromDb = [];
}

foreach ($bases as $baseKey => $baseLabel) {
    $imgUrl = null;
    $videoUrl = null;

    // 1) DB hanya dipakai bila file_path valid, EXIST, dan berada di uploads/products.
    foreach ($mediaFromDb as $m) {
        $fn = strtolower((string)($m['file_name'] ?? ''));
        $ft = strtolower((string)($m['file_type'] ?? ''));
        $dbUrl = pmv_valid_product_db_media_url((string)($m['file_path'] ?? ''), $isUnitAcc, $skuSafe);

        if (!$dbUrl) continue;

        $matchesBase =
            str_contains($fn, '_' . $baseKey) ||
            str_contains($fn, '-' . $baseKey) ||
            str_contains($fn, $baseKey);

        if (!$matchesBase) continue;

        if ($ft === 'image' && !$imgUrl) $imgUrl = $dbUrl;
        if ($ft === 'video' && !$videoUrl) $videoUrl = $dbUrl;
    }

    // 2) Folder fisik canonical produk menjadi fallback pasti.
    foreach ($dirCandidates as $dc) {
        if (!is_dir($dc['dir'])) continue;

        if (!$imgUrl) {
            $imgFile = pmv_find_file($dc['dir'], $baseKey, $imageExts);
            if ($imgFile) $imgUrl = $dc['url'] . rawurlencode($imgFile);
        }

        if (!$videoUrl) {
            $videoFile = pmv_find_file($dc['dir'], $baseKey, $videoExts);
            if ($videoFile) $videoUrl = $dc['url'] . rawurlencode($videoFile);
        }

        if ($imgUrl && $videoUrl) break;
    }

    $mediaRows[] = [
        'key' => $baseKey,
        'label' => $baseLabel,
        'img_url' => $imgUrl,
        'video_url' => $videoUrl,
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
                        Folder media produk: <code><?= $isUnitAcc ? 'uploads/products/UNIT ACC/' : 'uploads/products/' ?><?= htmlspecialchars($skuSafe) ?>/</code><br>
                        <?php if ($isUnitAcc): ?>
                        Nama file wajib Unit ACC: <strong>front.jpg</strong>, <strong>back.jpg</strong>, <strong>box.jpg</strong>, <strong>unpacked.jpg</strong>, <strong>front.mp4</strong>, <strong>unpacked.mp4</strong>.<br>
                        <?php else: ?>
                        Nama file: <strong>front</strong>, <strong>back</strong>, <strong>box</strong>, <strong>unpacked</strong> (format media yang didukung).<br>
                        <?php endif; ?>
                        <span class="text-success">Double extension seperti <code>front.jpg.jpg</code> tetap dibaca dan akan dirapikan bila folder writable.</span><br>
                        <span class="text-warning">Media produk tidak pernah membaca <code>uploads/assets/</code> setelah migrasi.</span>
                        <?php if ($isUnitAcc): ?><br><span class="text-info">Kompatibel dengan folder lama <code>UNIT ACC</code>/<code>UNIT_ACC</code> dan canonical <code>UNIT ACC</code>.</span><?php endif; ?>
                        <?php if (!empty($pmvLegacyMigration['errors'])): ?><br><span class="text-danger">Kendala migrasi: <?= h(implode(' | ', array_slice($pmvLegacyMigration['errors'],0,3))) ?></span><?php endif; ?>
                        <?php if (($pmvLegacyMigration['moved'] ?? 0) > 0): ?><br>
                        <span class="text-success">Migrasi otomatis: <?= (int)$pmvLegacyMigration['moved'] ?> file produk dipindahkan dari lokasi legacy ke folder canonical products<?= (($pmvLegacyMigration['renamed'] ?? 0) > 0) ? ' dan dirapikan namanya' : '' ?>.</span>
                        <?php endif; ?>
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
                    <h6 class="mb-2"><?= h($row['label']) ?></h6>

                    <div class="mb-3">
                        <strong>Foto <?= h($row['label']) ?>:</strong><br>

                        <?php if (!empty($row['img_url'])): ?>
                            <a href="<?= h($row['img_url']) ?>" target="_blank">
                                <img src="<?= h($row['img_url']) ?>"
                                     class="thumb-img-big"
                                     style="max-width:100%;height:auto;border-radius:10px;"
                                     alt="Foto <?= h($row['label']) ?>">
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Belum ada foto.</span>
                        <?php endif; ?>
                    </div>

                    <div>
                        <strong>Video <?= h($row['label']) ?>:</strong><br>

                        <?php if (!empty($row['video_url'])): ?>
                            <video controls style="max-width:100%;height:auto;border-radius:10px;">
                                <source src="<?= h($row['video_url']) ?>">
                                Browser tidak mendukung video tag.
                            </video>
                        <?php else: ?>
                            <span class="text-muted">Belum ada video.</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($mediaFromDb) && !array_filter($dirCandidates, fn($dc) => is_dir($dc['dir']))): ?>
            <div class="mt-3 text-warning">
                Media belum ditemukan untuk SKU ini.
            </div>
        <?php endif; ?>
    </div>
</div>

</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<?php rmi_footer(); ?>
