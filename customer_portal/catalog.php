<?php
/**
 * customer_portal/catalog.php
 * Katalog produk untuk Customer Portal.
 */
declare(strict_types=1);
require_once __DIR__ . '/../_shared/rmi_icons.php';

require_once __DIR__ . '/_bootstrap.php';
require_portal_login();

$pdo = rmi_db_pdo();
$user = portal_user();
$cc = $user['customers_code'];

// Pricelist per customer (prioritas: customer-specific > general)
$pricelist = [];
try {
    $st = $pdo->prepare("
        SELECT sku, sell_price FROM master_pricelist
        WHERE (customers_code = ? OR customers_code IS NULL) AND status = 1 AND (deleted_at IS NULL)
        ORDER BY (customers_code = ?) DESC
    ");
    $st->execute([$cc, $cc]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $sku = strtoupper(trim((string)($r['sku'] ?? '')));
        if ($sku !== '' && !isset($pricelist[$sku])) {
            $pricelist[$sku] = (float)$r['sell_price'];
        }
    }
} catch (Throwable $e) {
    $pricelist = [];
}

// Produk (semua) + foto
$allProducts = [];
try {
    $st = $pdo->query("
        SELECT id, sku, products_name, unit, price,
               COALESCE(photo_front,'')  AS photo_front,
               COALESCE(photo_back,'')   AS photo_back,
               COALESCE(general_name,'') AS general_name,
               COALESCE(licence_number,'') AS licence_number
        FROM master_products
        WHERE status = 'active'
        ORDER BY products_name
    ");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $sku = strtoupper(trim((string)($r['sku'] ?? '')));
        $price = isset($pricelist[$sku]) ? $pricelist[$sku] : (float)$r['price'];
        $r['effective_price'] = $price;
        $allProducts[] = $r;
    }
} catch (Throwable $e) {
    $allProducts = [];
}

// Filter pencarian
$search = trim((string)($_GET['q'] ?? ''));
$products = $allProducts;
if ($search !== '') {
    $q = strtoupper($search);
    $products = array_filter($allProducts, function ($p) use ($q) {
        return strpos(strtoupper((string)($p['products_name'] ?? '')), $q) !== false
            || strpos(strtoupper((string)($p['sku'] ?? '')), $q) !== false;
    });
}

// Handle add to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_cart'])) {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    }
    $product_id = (int)($_POST['product_id'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 1);
    if ($product_id > 0 && $qty > 0 && $qty <= 1000) {
        foreach ($products as $p) {
            if ((int)$p['id'] === $product_id) {
                $cart = $_SESSION['portal_cart'] ?? [];
                if (!is_array($cart)) $cart = [];
                $key = 'p' . $product_id;
                if (isset($cart[$key])) {
                    $cart[$key]['qty'] += $qty;
                } else {
                    $cart[$key] = [
                        'product_id' => $product_id,
                        'sku' => $p['sku'],
                        'name' => $p['products_name'],
                        'qty' => $qty,
                        'unit_price' => (float)$p['effective_price'],
                        'unit' => $p['unit'] ?? 'unit',
                    ];
                }
                $_SESSION['portal_cart'] = $cart;
                break;
            }
        }
    }
    $base = portal_base();
    rmi_redirect($base . '/customer_portal/cart.php');
}

$base = portal_base();
$pageTitle = 'Katalog Produk';
$csrf = function_exists('csrf_token') ? csrf_token() : '';
ob_start();
?>

<!-- Search -->
<div class="cp-card mb-4">
  <div class="p-3">
    <form method="get" class="d-flex gap-2">
      <input type="text" name="q" class="cp-search flex-1" placeholder="<?= rmi_icon('search') ?> Cari nama produk atau SKU..." value="<?= rmi_h($search) ?>">
      <button type="submit" class="btn btn-rmi px-4">Cari</button>
      <?php if ($search !== ''): ?>
        <a href="<?= rmi_h($base) ?>/customer_portal/catalog.php" class="btn btn-outline-secondary">Reset</a>
      <?php endif; ?>
    </form>
    <?php if ($search !== ''): ?>
      <div class="text-muted small mt-2">Ditemukan <strong><?= count($products) ?></strong> produk untuk "<?= rmi_h($search) ?>"</div>
    <?php else: ?>
      <div class="text-muted small mt-2">Total <strong><?= count($products) ?></strong> produk tersedia</div>
    <?php endif; ?>
  </div>
</div>

<!-- Product Grid -->
<?php if (empty($products)): ?>
  <div class="text-center py-5 text-muted">
    <div style="font-size:48px;margin-bottom:12px"><?= rmi_icon('search') ?></div>
    <h5>Produk tidak ditemukan</h5>
    <p>Coba kata kunci yang berbeda</p>
  </div>
<?php else: ?>
  <div class="row g-3">
  <?php foreach ($products as $p):
    $photoF   = trim((string)($p['photo_front'] ?? ''));
    $photoB   = trim((string)($p['photo_back']  ?? ''));
    $hasF     = $photoF !== '' && (str_starts_with($photoF,'http') || str_starts_with($photoF,'/'));
    $hasB     = $photoB !== '' && (str_starts_with($photoB,'http') || str_starts_with($photoB,'/'));
    $hasAny   = $hasF || $hasB;
  ?>
    <div class="col-6 col-md-4 col-lg-3">
      <div class="product-card h-100 d-flex flex-column">

        <!-- Foto produk: hover front→back -->
        <div class="prod-img-wrap" style="width:100%;aspect-ratio:1;border-radius:10px;overflow:hidden;background:#f8fafc;margin-bottom:10px;border:1px solid #e2e8f0;position:relative;cursor:zoom-in">
          <?php if ($hasF || $hasB): ?>
            <!-- Foto depan -->
            <img class="prod-img-front"
                 src="<?= rmi_h($hasF ? $photoF : $photoB) ?>"
                 alt="Depan — <?= rmi_h($p['products_name']) ?>"
                 style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain;transition:opacity .3s"
                 onerror="this.style.display='none'">
            <?php if ($hasF && $hasB): ?>
            <!-- Foto belakang (muncul saat hover) -->
            <img class="prod-img-back"
                 src="<?= rmi_h($photoB) ?>"
                 alt="Belakang — <?= rmi_h($p['products_name']) ?>"
                 style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain;opacity:0;transition:opacity .3s"
                 onerror="this.style.display='none'">
            <!-- Badge hover hint -->
            <div class="prod-flip-badge" style="position:absolute;bottom:6px;right:6px;background:rgba(0,0,0,.45);color:#fff;font-size:9px;font-weight:700;padding:2px 7px;border-radius:10px;opacity:0;transition:opacity .3s">
              FRONT / BACK
            </div>
            <?php endif; ?>
          <?php else: ?>
            <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;color:#cbd5e1;height:100%;position:absolute;inset:0">
              <span style="font-size:40px"><?= rmi_icon('box') ?></span>
              <span style="font-size:10px;margin-top:4px">No Image</span>
            </div>
          <?php endif; ?>
        </div>

        <div class="product-sku"><?= rmi_h($p['sku']) ?></div>
        <div class="product-name flex-grow-1"><?= rmi_h($p['products_name']) ?></div>
        <?php if (!empty($p['general_name'])): ?>
          <div style="font-size:11px;color:#94a3b8;margin-bottom:4px"><?= rmi_h($p['general_name']) ?></div>
        <?php endif; ?>
        <?php if (!empty($p['licence_number'])): ?>
          <div style="font-size:10px;color:#cbd5e1;margin-bottom:6px;font-family:monospace">AKL: <?= rmi_h($p['licence_number']) ?></div>
        <?php endif; ?>
        <div class="product-price">Rp <?= number_format($p['effective_price'], 0, ',', '.') ?><span style="font-size:11px;color:#64748b;font-weight:400"> / <?= rmi_h($p['unit']) ?></span></div>
        <form method="post" class="d-flex gap-2 align-items-center">
          <input type="hidden" name="csrf_token" value="<?= rmi_h($csrf) ?>">
          <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
          <input type="number" name="qty" value="1" min="1" max="1000"
                 class="form-control form-control-sm text-center" style="width:64px">
          <button type="submit" name="add_cart" class="btn btn-rmi btn-sm flex-grow-1">
            <?= rmi_icon('cart') ?> Tambah
          </button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<style>
.prod-img-wrap:hover .prod-img-front { opacity: 0 }
.prod-img-wrap:hover .prod-img-back  { opacity: 1 }
.prod-img-wrap:hover .prod-flip-badge{ opacity: 1 }
</style>

<?php
$content = ob_get_clean();
$flash = [];
require __DIR__ . '/layout.php';
