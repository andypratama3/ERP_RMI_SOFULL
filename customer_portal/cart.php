<?php
/**
 * customer_portal/cart.php
 * Keranjang belanja.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_portal_login();

$base = portal_base();
$cart = $_SESSION['portal_cart'] ?? [];
if (!is_array($cart)) $cart = [];

// Update qty / remove
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'update') {
        foreach ($_POST['qty'] ?? [] as $key => $val) {
            $qty = (int)$val;
            if (isset($cart[$key])) {
                if ($qty <= 0) {
                    unset($cart[$key]);
                } else {
                    $cart[$key]['qty'] = min($qty, 1000);
                }
            }
        }
        $_SESSION['portal_cart'] = $cart;
    } elseif ($action === 'remove' && isset($_POST['key'])) {
        $key = (string)$_POST['key'];
        unset($cart[$key]);
        $_SESSION['portal_cart'] = $cart;
    } elseif ($action === 'clear') {
        $_SESSION['portal_cart'] = [];
        $cart = [];
    }
    rmi_redirect($base . '/customer_portal/cart.php');
}

$total = 0;
foreach ($cart as $it) {
    $total += ($it['qty'] ?? 0) * ($it['unit_price'] ?? 0);
}

$pageTitle = 'Keranjang Belanja';
$csrf = function_exists('csrf_token') ? csrf_token() : '';
ob_start();
?>

<?php if (empty($cart)): ?>
  <div class="text-center py-5">
    <div style="font-size:64px;margin-bottom:16px">🛒</div>
    <h4 class="fw-bold mb-2">Keranjang Kosong</h4>
    <p class="text-muted mb-4">Belum ada produk di keranjang. Yuk mulai belanja!</p>
    <a href="<?= rmi_h($base) ?>/customer_portal/catalog.php" class="btn btn-rmi px-5 py-2">
      📦 Lihat Katalog Produk
    </a>
  </div>

<?php else: ?>
  <div class="row g-4">

    <!-- Cart Items -->
    <div class="col-lg-8">
      <div class="cp-card">
        <div class="cp-card-header">
          🛒 Keranjang Belanja
          <span class="text-muted" style="font-size:13px;font-weight:400"><?= count($cart) ?> produk</span>
        </div>
        <form method="post" id="updateForm">
          <input type="hidden" name="csrf_token" value="<?= rmi_h($csrf) ?>">
          <input type="hidden" name="action" value="update">
          <div class="p-3">
            <?php foreach ($cart as $key => $it): $sub = ($it['qty']??0)*($it['unit_price']??0); ?>
            <div class="d-flex align-items-center gap-3 py-3" style="border-bottom:1px solid #f1f5f9">
              <!-- Icon -->
              <div style="width:44px;height:44px;background:#f0fdf4;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">📦</div>
              <!-- Info -->
              <div class="flex-grow-1 min-width-0">
                <div class="fw-semibold" style="font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= rmi_h($it['name']??'') ?></div>
                <div style="font-size:11px;color:#94a3b8;font-family:monospace"><?= rmi_h($it['sku']??'') ?></div>
                <div style="font-size:13px;color:#64748b">Rp <?= number_format((float)($it['unit_price']??0),0,',','.') ?> / <?= rmi_h($it['unit']??'unit') ?></div>
              </div>
              <!-- Qty -->
              <div style="flex-shrink:0">
                <input type="number" name="qty[<?= rmi_h($key) ?>]"
                       value="<?= (int)($it['qty']??0) ?>" min="1" max="1000"
                       class="form-control form-control-sm text-center"
                       style="width:70px" onchange="document.getElementById('updateForm').submit()">
              </div>
              <!-- Subtotal -->
              <div style="min-width:100px;text-align:right;flex-shrink:0">
                <div class="fw-bold" style="color:#16a34a">Rp <?= number_format($sub,0,',','.') ?></div>
              </div>
              <!-- Remove -->
              <div style="flex-shrink:0">
                <form method="post" class="d-inline" onsubmit="return confirm('Hapus item ini?')">
                  <input type="hidden" name="csrf_token" value="<?= rmi_h($csrf) ?>">
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="key" value="<?= rmi_h($key) ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger border-0" title="Hapus">✕</button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <!-- Cart actions -->
          <div class="px-3 pb-3 d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-outline-secondary btn-sm">🔄 Update Qty</button>
            <form method="post" class="d-inline" onsubmit="return confirm('Kosongkan keranjang?')">
              <input type="hidden" name="csrf_token" value="<?= rmi_h($csrf) ?>">
              <input type="hidden" name="action" value="clear">
              <button type="submit" class="btn btn-outline-danger btn-sm">🗑️ Kosongkan</button>
            </form>
            <a href="<?= rmi_h($base) ?>/customer_portal/catalog.php" class="btn btn-outline-secondary btn-sm ms-auto">+ Tambah Produk</a>
          </div>
        </form>
      </div>
    </div>

    <!-- Summary -->
    <div class="col-lg-4">
      <div class="cp-card" style="position:sticky;top:20px">
        <div class="cp-card-header">📊 Ringkasan Pesanan</div>
        <div class="p-3">
          <?php foreach ($cart as $it): ?>
            <div class="d-flex justify-content-between mb-2" style="font-size:13px">
              <span class="text-muted" style="flex:1;padding-right:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= rmi_h($it['name']??'') ?> ×<?= (int)($it['qty']??0) ?></span>
              <span class="fw-semibold">Rp <?= number_format(($it['qty']??0)*($it['unit_price']??0),0,',','.') ?></span>
            </div>
          <?php endforeach; ?>
          <hr style="border-color:#f1f5f9">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <span class="fw-bold">Total</span>
            <span class="fw-bold" style="font-size:18px;color:#16a34a">Rp <?= number_format($total,0,',','.') ?></span>
          </div>
          <a href="<?= rmi_h($base) ?>/customer_portal/checkout.php" class="btn btn-rmi w-100 py-3 fw-bold">
            Checkout →
          </a>
          <div class="text-center mt-2 text-muted" style="font-size:12px">
            Staff CRM akan memproses pesanan Anda
          </div>
        </div>
      </div>
    </div>

  </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
$flash = [];
require __DIR__ . '/layout.php';
