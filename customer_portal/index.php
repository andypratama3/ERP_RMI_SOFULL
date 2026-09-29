<?php
/**
 * customer_portal/index.php
 * Dashboard Customer Portal.
 */
declare(strict_types=1);
require_once __DIR__ . '/../_shared/rmi_icons.php';

require_once __DIR__ . '/_bootstrap.php';
require_portal_login();

$pdo = rmi_db_pdo();
$user = portal_user();
$cc = $user['customers_code'];

// Nama customer
$custName = '';
$st = $pdo->prepare("SELECT customers_name FROM master_customers WHERE customers_code = ? LIMIT 1");
$st->execute([$cc]);
$row = $st->fetch();
if ($row) $custName = (string)$row['customers_name'];

// Statistik order bulan ini
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total), 0) AS total
    FROM sales_do
    WHERE customers_code = ? AND do_date >= DATE_FORMAT(NOW(), '%Y-%m-01')
");
$stmt->execute([$cc]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Order terakhir
$stmt = $pdo->prepare("
    SELECT id, do_code, do_date, status, grand_total
    FROM sales_do
    WHERE customers_code = ?
    ORDER BY created_at DESC LIMIT 5
");
$stmt->execute([$cc]);
$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Outstanding (belum lunas)
$outstanding = 0;
try {
    $stO = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE customers_code=? AND status NOT IN ('PAID','CANCELLED','DONE') AND do_date >= DATE_FORMAT(NOW(),'%Y-%m-01')");
    $stO->execute([$cc]);
    $outstanding = (int)$stO->fetchColumn();
} catch (Throwable $e) {}

$base    = portal_base();
$pageTitle = 'Dashboard';
// Hitung cart count sebelum ob_start (layout.php belum di-load)
$cartNow = $_SESSION['portal_cart'] ?? [];
$cartCnt = is_array($cartNow) ? array_sum(array_column($cartNow, 'qty')) : 0;
ob_start();

function cp_status_badge(string $status): string {
    $s = strtoupper(trim($status));
    $map = [
        'PAID'      => ['class'=>'status-paid',    'icon' => rmi_icon('check'), 'label'=>'Lunas'],
        'OPEN'      => ['class'=>'status-open',    'icon' => rmi_icon('warn'), 'label'=>'Open'],
        'PARTIAL'   => ['class'=>'status-partial', 'icon' => rmi_icon('refresh'), 'label'=>'Partial'],
        'CANCELLED' => ['class'=>'status-cancel',  'icon' => rmi_icon('cross'), 'label'=>'Batal'],
        'DONE'      => ['class'=>'status-paid',    'icon' => rmi_icon('check'), 'label'=>'Selesai'],
    ];
    $d = $map[$s] ?? ['class'=>'status-default','icon' => rmi_icon('question'),'label'=>$status];
    return '<span class="status-badge '.$d['class'].'">'.$d['icon'].' '.$d['label'].'</span>';
}
?>

<!-- Welcome Banner -->
<div class="welcome-banner">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
      <h2>Selamat datang, <?= rmi_h($user['full_name'] ?: $user['username']) ?>! <?= rmi_icon('user') ?></h2>
      <p><?= rmi_h($custName) ?> &nbsp;·&nbsp; <code style="color:rgba(255,255,255,.7)"><?= rmi_h($cc) ?></code></p>
    </div>
    <a href="<?= rmi_h($base) ?>/customer_portal/catalog.php" class="btn btn-light fw-bold px-4">
      <?= rmi_icon('cart') ?> Buat Order Baru
    </a>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#dcfce7"><?= rmi_icon('box') ?></div>
      <div class="kpi-val" style="color:#16a34a"><?= (int)($stats['cnt'] ?? 0) ?></div>
      <div class="kpi-label">Order Bulan Ini</div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#dbeafe"><?= rmi_icon('money') ?></div>
      <div class="kpi-val" style="color:#1d4ed8; font-size:18px">Rp <?= number_format((float)($stats['total'] ?? 0)/1e6, 1, ',', '.') ?>jt</div>
      <div class="kpi-label">Nilai Bulan Ini</div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#fef9c3"><?= rmi_icon('calendar') ?></div>
      <div class="kpi-val" style="color:#854d0e"><?= $outstanding ?></div>
      <div class="kpi-label">Belum Lunas</div>
    </div>
  </div>
</div>

<!-- Quick Actions -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <a href="<?= rmi_h($base) ?>/customer_portal/catalog.php" class="btn btn-rmi w-100 py-3 d-flex flex-column align-items-center gap-1 text-decoration-none">
      <span style="font-size:22px"><?= rmi_icon('box') ?></span><span style="font-size:13px">Katalog Produk</span>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= rmi_h($base) ?>/customer_portal/cart.php" class="btn btn-rmi-outline w-100 py-3 d-flex flex-column align-items-center gap-1 text-decoration-none">
      <span style="font-size:22px"><?= rmi_icon('cart') ?></span><span style="font-size:13px">Keranjang <?php if ($cartCnt > 0): ?>(<?= $cartCnt ?>)<?php endif; ?></span>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= rmi_h($base) ?>/customer_portal/orders.php" class="btn btn-rmi-outline w-100 py-3 d-flex flex-column align-items-center gap-1 text-decoration-none">
      <span style="font-size:22px"><?= rmi_icon('clipboard') ?></span><span style="font-size:13px">Riwayat Order</span>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= rmi_h($base) ?>/customer_portal/orders.php?status=OPEN" class="btn btn-rmi-outline w-100 py-3 d-flex flex-column align-items-center gap-1 text-decoration-none">
      <span style="font-size:22px"><?= rmi_icon('calendar') ?></span><span style="font-size:13px">Order Pending</span>
    </a>
  </div>
</div>

<!-- Recent Orders -->
<div class="cp-card">
  <div class="cp-card-header">
    <span><?= rmi_icon('clipboard') ?> Order Terakhir</span>
    <a href="<?= rmi_h($base) ?>/customer_portal/orders.php" style="font-size:13px;color:#16a34a;text-decoration:none;font-weight:600">Lihat semua →</a>
  </div>
  <div class="p-3">
    <?php if (empty($recentOrders)): ?>
      <div class="text-center py-4 text-muted">
        <div style="font-size:40px;margin-bottom:8px"><?= rmi_icon('inbox') ?></div>
        <div>Belum ada order. <a href="<?= rmi_h($base) ?>/customer_portal/catalog.php">Mulai pesan sekarang →</a></div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="cp-table">
          <thead>
            <tr>
              <th>DO Code</th>
              <th>Tanggal</th>
              <th>Status</th>
              <th class="text-end">Total</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recentOrders as $o): ?>
            <tr>
              <td><strong><?= rmi_h($o['do_code']) ?></strong></td>
              <td style="color:#64748b"><?= rmi_h($o['do_date']) ?></td>
              <td><?= cp_status_badge((string)$o['status']) ?></td>
              <td class="text-end fw-semibold">Rp <?= number_format((float)$o['grand_total'], 0, ',', '.') ?></td>
              <td>
                <a href="<?= rmi_h($base) ?>/customer_portal/order_detail.php?id=<?= (int)$o['id'] ?>"
                   class="btn btn-sm btn-outline-success">Detail</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
$flash = [];
require __DIR__ . '/layout.php';
