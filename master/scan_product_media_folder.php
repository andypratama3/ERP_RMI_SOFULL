<?php
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/auth.php';
require_login();
require_any_permission(['MASTER.PRODUCT_MEDIA_UPLOAD', 'MASTER.PRODUCT_EDIT']);

$pdo = db_pdo();

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

function spmf_safe_folder(string $sku): string {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($sku));
    $safe = preg_replace('/_{2,}/', '_', (string)$safe);
    return trim((string)$safe, '_');
}
function spmf_normalize(string $v): string {
    return strtoupper(trim(preg_replace('/\s+/', ' ', $v)));
}
function spmf_media_type(string $ext): ?string {
    $ext = strtolower($ext);
    if (in_array($ext, ['jpg','jpeg','png','webp'], true)) return 'image';
    if (in_array($ext, ['mp4','mov','mkv','avi','webm'], true)) return 'video';
    return null;
}
function spmf_slot(string $filename): ?string {
    $base = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    return in_array($base, ['front','back','box','unpacked'], true) ? $base : null;
}

$root = dirname(__DIR__) . '/uploads/products/';
$success = [];
$updated = [];
$failed = [];
$skipped = [];
$preview = [];

// Cache master SKU untuk exact/raw/safe mapping. Tidak pakai LIKE agar tidak salah produk.
$products = $pdo->query("SELECT id, sku, products_name FROM master_products WHERE sku IS NOT NULL AND TRIM(sku)<>''")->fetchAll(PDO::FETCH_ASSOC);
$byRaw = [];
$bySafe = [];
foreach ($products as $p) {
    $rawKey = spmf_normalize((string)$p['sku']);
    $safeKey = spmf_normalize(spmf_safe_folder((string)$p['sku']));
    $byRaw[$rawKey] = $p;
    if (!isset($bySafe[$safeKey])) $bySafe[$safeKey] = $p;
}

function spmf_register(PDO $pdo, array $product, string $file, string $type, string $path, array &$success, array &$updated): void {
    $sku = (string)$product['sku'];
    $check = $pdo->prepare("SELECT id, file_path, file_type FROM master_product_media WHERE sku=? AND file_name=? LIMIT 1");
    $check->execute([$sku, $file]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ((string)$row['file_path'] !== $path || strtolower((string)$row['file_type']) !== $type) {
            $up = $pdo->prepare("UPDATE master_product_media SET file_type=?, file_path=? WHERE id=?");
            $up->execute([$type, $path, (int)$row['id']]);
            $updated[] = $sku . ' => ' . $file;
        }
        return;
    }
    $ins = $pdo->prepare("INSERT INTO master_product_media (sku,file_name,file_type,file_path,uploaded_by) VALUES (?,?,?,?,?)");
    $ins->execute([$sku, $file, $type, $path, $_SESSION['user_id'] ?? null]);
    $success[] = $sku . ' => ' . $file;
}

function spmf_scan_per_sku(PDO $pdo, string $root, array $byRaw, array $bySafe, bool $apply, array &$success, array &$updated, array &$failed, array &$skipped, array &$preview): void {
    if (!is_dir($root)) { $failed[] = 'Folder tidak ditemukan: uploads/products/'; return; }
    foreach (scandir($root) ?: [] as $folder) {
        if ($folder === '.' || $folder === '..' || in_array(strtolower($folder), ['images','videos'], true)) continue;
        $dir = $root . $folder;
        if (!is_dir($dir)) continue;

        $product = $byRaw[spmf_normalize($folder)] ?? $bySafe[spmf_normalize($folder)] ?? null;
        if (!$product) {
            $failed[] = "Folder tidak cocok SKU master: {$folder}";
            continue;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..' || !is_file($dir . '/' . $file)) continue;
            $type = spmf_media_type(pathinfo($file, PATHINFO_EXTENSION));
            $slot = spmf_slot($file);
            if (!$type || !$slot) {
                $skipped[] = $folder . '/' . $file . ' (nama/format tidak dipakai)';
                continue;
            }
            $path = '/uploads/products/' . rawurlencode($folder) . '/' . rawurlencode($file);
            $preview[] = [(string)$product['sku'], $folder, $file, $type, $path];
            if ($apply) spmf_register($pdo, $product, $file, $type, $path, $success, $updated);
        }
    }
}

// Backward compatibility: legacy flat folders images/videos dengan nama SKU_front.jpg dst.
function spmf_scan_legacy_flat(PDO $pdo, string $dir, string $type, array $products, bool $apply, array &$success, array &$updated, array &$failed, array &$skipped, array &$preview): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..' || !is_file($dir . '/' . $file)) continue;
        $actualType = spmf_media_type(pathinfo($file, PATHINFO_EXTENSION));
        if ($actualType !== $type) { $skipped[] = basename($dir) . '/' . $file; continue; }
        $base = pathinfo($file, PATHINFO_FILENAME);
        $found = null;
        foreach ($products as $p) {
            foreach ([(string)$p['sku'], spmf_safe_folder((string)$p['sku'])] as $prefix) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '[_-](front|back|box|unpacked)$/i', $base)) { $found = $p; break 2; }
            }
        }
        if (!$found) { $failed[] = 'Legacy media tidak cocok SKU/slot: ' . $file; continue; }
        $path = '/uploads/products/' . basename($dir) . '/' . rawurlencode($file);
        $preview[] = [(string)$found['sku'], basename($dir), $file, $type, $path];
        if ($apply) spmf_register($pdo, $found, $file, $type, $path, $success, $updated);
    }
}

$apply = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['scan_media']);
if ($apply) verify_csrf((string)($_POST['csrf_token'] ?? ''));

spmf_scan_per_sku($pdo, $root, $byRaw, $bySafe, $apply, $success, $updated, $failed, $skipped, $preview);
spmf_scan_legacy_flat($pdo, $root . 'images/', 'image', $products, $apply, $success, $updated, $failed, $skipped, $preview);
spmf_scan_legacy_flat($pdo, $root . 'videos/', 'video', $products, $apply, $success, $updated, $failed, $skipped, $preview);

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();
rmi_header('Scan Product Media Folder', [
    'active' => 'master',
    'breadcrumbs' => [
        ['label'=>'Master Data','url'=>$baseProject . '/master/index.php'],
        ['label'=>'Master Products','url'=>'master_products.php'],
        'Scan Media Folder',
    ],
]);
?>
<div class="rmi-container">
  <div class="card mb-3"><div class="card-body">
    <h5>Scan Media dari Folder NAS</h5>
    <p class="mb-2">Format utama: <code>uploads/products/&lt;SKU atau SAFE_SKU&gt;/front.jpg</code>, <code>back.jpg</code>, <code>box.jpg</code>, <code>unpacked.jpg</code> dan versi video seperti <code>front.mp4</code>.</p>
    <p class="text-muted mb-3">GET hanya preview. Database baru disinkronkan setelah tombol <b>Scan &amp; Sync</b> ditekan. Folder lama <code>images/</code> dan <code>videos/</code> tetap didukung.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <button class="btn btn-primary" name="scan_media" value="1" onclick="return confirm('Scan folder media dan sinkronkan ke database?')">Scan &amp; Sync</button>
      <a class="btn btn-outline-secondary" href="master_products.php">Kembali</a>
    </form>
  </div></div>

  <?php if ($apply): ?>
  <div class="alert alert-info">Selesai: baru <?= count($success) ?>, diperbarui <?= count($updated) ?>, gagal <?= count($failed) ?>, dilewati <?= count($skipped) ?>.</div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-body">
    <h5><?= $apply ? 'Hasil Scan' : 'Preview Media Terdeteksi' ?> (<?= count($preview) ?>)</h5>
    <div class="table-responsive"><table class="table table-sm align-middle">
      <thead><tr><th>SKU Master</th><th>Folder</th><th>File</th><th>Tipe</th><th>Path DB</th></tr></thead>
      <tbody><?php foreach ($preview as $r): ?><tr><?php foreach ($r as $v): ?><td><?= h($v) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table></div>
  </div></div>

  <?php if ($success): ?><div class="card mb-3"><div class="card-body"><h5>Baru didaftarkan</h5><?php foreach ($success as $v): ?><div class="text-success"><?= h($v) ?></div><?php endforeach; ?></div></div><?php endif; ?>
  <?php if ($updated): ?><div class="card mb-3"><div class="card-body"><h5>Path diperbarui</h5><?php foreach ($updated as $v): ?><div class="text-info"><?= h($v) ?></div><?php endforeach; ?></div></div><?php endif; ?>
  <?php if ($failed): ?><div class="card mb-3"><div class="card-body"><h5>Perlu diperiksa</h5><?php foreach ($failed as $v): ?><div class="text-danger"><?= h($v) ?></div><?php endforeach; ?></div></div><?php endif; ?>
  <?php if ($skipped): ?><div class="card mb-3"><div class="card-body"><h5>Dilewati</h5><?php foreach (array_slice($skipped,0,100) as $v): ?><div class="text-muted"><?= h($v) ?></div><?php endforeach; ?><?php if(count($skipped)>100): ?><div class="text-muted">... <?= count($skipped)-100 ?> lainnya</div><?php endif; ?></div></div><?php endif; ?>
</div>
<?php rmi_footer(); ?>
