<?php
/**
 * customer_portal/orders.php
 * Riwayat order.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_portal_login();

$pdo = rmi_db_pdo();
$user = portal_user();
$cc = $user['customers_code'];

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterDateFrom = trim((string)($_GET['date_from'] ?? ''));
$filterDateTo = trim((string)($_GET['date_to'] ?? ''));

$sql = "SELECT id, do_code, do_date, status, grand_total, created_at FROM sales_do WHERE customers_code = ?";
$params = [$cc];
if ($filterStatus !== '') {
    $sql .= " AND status = ?";
    $params[] = $filterStatus;
}
if ($filterDateFrom !== '' && strtotime($filterDateFrom) !== false) {
    $sql .= " AND do_date >= ?";
    $params[] = $filterDateFrom;
}
if ($filterDateTo !== '' && strtotime($filterDateTo) !== false) {
    $sql .= " AND do_date <= ?";
    $params[] = $filterDateTo;
}
$sql .= " ORDER BY created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Daftar status unik untuk filter
$statuses = [];
try {
    $stS = $pdo->prepare("SELECT DISTINCT status FROM sales_do WHERE customers_code = ? ORDER BY status");
    $stS->execute([$cc]);
    while ($r = $stS->fetch(PDO::FETCH_ASSOC)) {
        $statuses[] = $r['status'];
    }
} catch (Throwable $e) {}

$base = portal_base();
$pageTitle = 'Riwayat Order';
ob_start();

function ord_status_badge(string $status): string {
    $s = strtoupper(trim($status));
    $map = ['PAID'=>['status-paid','✅','Lunas'],'OPEN'=>['status-open','🟡','Open'],'PARTIAL'=>['status-partial','🔵','Partial'],'CANCELLED'=>['status-cancel','❌','Batal'],'DONE'=>['status-paid','✅','Selesai']];
    $d = $map[$s] ?? ['status-default','⚪',$status];
    return '<span class="status-badge '.$d[0].'">'.$d[1].' '.$d[2].'</span>';
}
?>

<div class="cp-card mb-4">
  <div class="cp-card-header">📋 Riwayat Order</div>
  <div class="p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-auto">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">Semua Status</option>
          <?php foreach ($statuses as $s): ?>
            <option value="<?= rmi_h($s) ?>" <?= $filterStatus===$s?'selected':'' ?>><?= rmi_h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-auto">
        <label class="form-label small fw-semibold mb-1">Dari</label>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= rmi_h($filterDateFrom) ?>">
      </div>
      <div class="col-6 col-md-auto">
        <label class="form-label small fw-semibold mb-1">Sampai</label>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= rmi_h($filterDateTo) ?>">
      </div>
      <div class="col-12 col-md-auto d-flex gap-2">
        <button type="submit" class="btn btn-rmi btn-sm px-4">Filter</button>
        <a href="<?= rmi_h($base) ?>/customer_portal/orders.php" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>
</div>

<?php if (empty($orders)): ?>
  <div class="text-center py-5 text-muted">
    <div style="font-size:48px;margin-bottom:12px">📭</div>
    <h5>Belum ada order</h5>
    <p>Mulai pesan produk dari <a href="<?= rmi_h($base) ?>/customer_portal/catalog.php">Katalog →</a></p>
  </div>
<?php else: ?>
  <div class="cp-card">
    <div class="p-0">
      <div class="table-responsive">
        <table class="cp-table">
          <thead>
            <tr>
              <th>DO Code</th>
              <th>Tanggal</th>
              <th>Status</th>
              <th class="text-end">Total</th>
              <th class="text-center">Aksi</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td><strong style="color:#1d4ed8"><?= rmi_h($o['do_code']) ?></strong></td>
              <td style="color:#64748b"><?= date('d M Y', strtotime($o['do_date'])) ?></td>
              <td><?= ord_status_badge((string)$o['status']) ?></td>
              <td class="text-end fw-bold">Rp <?= number_format((float)$o['grand_total'],0,',','.') ?></td>
              <td class="text-center">
                <a href="<?= rmi_h($base) ?>/customer_portal/order_detail.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-success">
                  Detail
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="text-muted small mt-2">Menampilkan <?= count($orders) ?> order</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
$flash = [];
require __DIR__ . '/layout.php';
