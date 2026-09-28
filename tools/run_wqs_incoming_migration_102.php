<?php
/**
 * Jalankan migrasi 102_wqs_incoming_po_columns
 * Membuat tabel wqs_incoming / wqs_incoming_items jika belum ada.
 * Menambah kolom po_id (wqs_incoming) dan po_item_id (wqs_incoming_items) jika belum ada.
 *
 * Error: "Table wqs_incoming doesn't exist" atau "Kolom po_id belum tersedia" → jalankan tool ini.
 */
require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/../_shared/db.php';

$base = function_exists('auth_base_project') ? auth_base_project() : '';
$role = strtoupper(trim((string)($_SESSION['level'] ?? $_SESSION['role'] ?? '')));
if (!in_array($role, ['ADMIN', 'SUPERADMIN'], true)) {
    rmi_redirect($base . '/tools/index.php');
}

$run = isset($_POST['run']) && $_POST['run'] === '1';
if ($run && function_exists('verify_csrf')) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

$pdo = rmi_db_pdo();
$result = ['ok' => false, 'msg' => '', 'steps' => []];

function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

function index_exists(PDO $pdo, string $table, string $idx): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?");
    $st->execute([$table, $idx]);
    return (int)$st->fetchColumn() > 0;
}

if ($run) {
    try {
        // 1. Buat tabel jika belum ada
        if (!table_exists($pdo, 'wqs_incoming')) {
            $pdo->exec("CREATE TABLE wqs_incoming (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                incoming_code VARCHAR(50) NOT NULL,
                received_date DATE NOT NULL,
                po_id INT NULL,
                po_code VARCHAR(60) NULL,
                office_code VARCHAR(30) NULL,
                depo_name VARCHAR(60) NULL,
                ref_note VARCHAR(255) NULL,
                deleted_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_incoming_code (incoming_code),
                KEY idx_received_date (received_date),
                KEY idx_po_id (po_id),
                KEY idx_po (po_code),
                KEY idx_office (office_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $result['steps'][] = 'wqs_incoming: tabel dibuat';
        }
        if (!table_exists($pdo, 'wqs_incoming_items')) {
            $pdo->exec("CREATE TABLE wqs_incoming_items (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                incoming_id INT NOT NULL,
                po_item_id INT NULL,
                product_id INT NOT NULL,
                sku VARCHAR(50) NOT NULL,
                lot_number VARCHAR(80) NULL,
                serial_number VARCHAR(80) NULL,
                exp_date DATE NULL,
                qty INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_incoming (incoming_id),
                KEY idx_po_item_id (po_item_id),
                KEY idx_product (product_id),
                KEY idx_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $result['steps'][] = 'wqs_incoming_items: tabel dibuat';
        }

        // 2. Tambah kolom po_id jika belum ada
        if (!col_exists($pdo, 'wqs_incoming', 'po_id')) {
            $pdo->exec("ALTER TABLE wqs_incoming ADD COLUMN po_id INT NULL");
            $result['steps'][] = 'wqs_incoming.po_id: ditambahkan';
        } else {
            $result['steps'][] = 'wqs_incoming.po_id: sudah ada';
        }
        if (!index_exists($pdo, 'wqs_incoming', 'idx_po_id')) {
            $pdo->exec("ALTER TABLE wqs_incoming ADD INDEX idx_po_id (po_id)");
            $result['steps'][] = 'wqs_incoming.idx_po_id: ditambahkan';
        }

        // 2. wqs_incoming_items.po_item_id
        if (!col_exists($pdo, 'wqs_incoming_items', 'po_item_id')) {
            $pdo->exec("ALTER TABLE wqs_incoming_items ADD COLUMN po_item_id INT NULL");
            $result['steps'][] = 'wqs_incoming_items.po_item_id: ditambahkan';
        } else {
            $result['steps'][] = 'wqs_incoming_items.po_item_id: sudah ada';
        }
        if (!index_exists($pdo, 'wqs_incoming_items', 'idx_po_item_id')) {
            $pdo->exec("ALTER TABLE wqs_incoming_items ADD INDEX idx_po_item_id (po_item_id)");
            $result['steps'][] = 'wqs_incoming_items.idx_po_item_id: ditambahkan';
        }

        $result['ok'] = true;
        $result['msg'] = 'Migrasi 102 berhasil. Refresh halaman WQS Incoming.';
    } catch (Throwable $e) {
        $result['msg'] = $e->getMessage();
        $result['steps'][] = 'Error: ' . $e->getFile() . ':' . $e->getLine();
    }
}

// Status
$tablesExist = table_exists($pdo, 'wqs_incoming') && table_exists($pdo, 'wqs_incoming_items');
$hasPoId = $tablesExist && col_exists($pdo, 'wqs_incoming', 'po_id');
$hasPoItemId = $tablesExist && col_exists($pdo, 'wqs_incoming_items', 'po_item_id');
$status = (!$tablesExist) ? 'Tabel belum ada' : (($hasPoId && $hasPoItemId) ? 'OK' : 'Perlu migrasi');

require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('WQS Incoming Migration 102', 'tools');
?>
<div class="container py-4">
  <div class="card p-4">
    <h2>WQS Incoming Migration 102</h2>
    <p class="text-muted">Membuat tabel <code>wqs_incoming</code> &amp; <code>wqs_incoming_items</code> jika belum ada. Menambah kolom <code>po_id</code>, <code>po_item_id</code> untuk validasi PO.</p>
    <div class="alert alert-info small">
      <strong>SQL langsung (SSH/NAS):</strong><br>
      <code>cd /volume4/web/ERP_RMI_SOFULL && mysql -u root -p erp_rmi_sofull &lt; sql/migrations/102_wqs_incoming_po_columns.sql</code><br>
      <span class="text-muted">Jika error syntax, gunakan <code>102_wqs_incoming_legacy.sql</code></span>
    </div>

    <div class="mb-3">
      <strong>Status:</strong>
      <span class="badge <?= $status === 'OK' ? 'bg-success' : ($status === 'Tabel belum ada' ? 'bg-danger' : 'bg-warning') ?>"><?= htmlspecialchars($status) ?></span>
      <ul class="mt-2 mb-0">
        <li>wqs_incoming: <?= table_exists($pdo, 'wqs_incoming') ? '✓ Ada' : '✗ Belum ada' ?></li>
        <li>wqs_incoming_items: <?= table_exists($pdo, 'wqs_incoming_items') ? '✓ Ada' : '✗ Belum ada' ?></li>
        <?php if ($tablesExist): ?>
        <li>wqs_incoming.po_id: <?= $hasPoId ? '✓ Ada' : '✗ Belum ada' ?></li>
        <li>wqs_incoming_items.po_item_id: <?= $hasPoItemId ? '✓ Ada' : '✗ Belum ada' ?></li>
        <?php endif; ?>
      </ul>
    </div>

    <?php if ($result['msg']): ?>
      <div class="alert alert-<?= $result['ok'] ? 'success' : 'danger' ?>">
        <?= htmlspecialchars($result['msg']) ?>
        <?php if (!empty($result['steps'])): ?>
          <ul class="mb-0 mt-2">
            <?php foreach ($result['steps'] as $s): ?>
              <li><?= htmlspecialchars($s) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$tablesExist || !$hasPoId || !$hasPoItemId): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '') ?>">
        <input type="hidden" name="run" value="1">
        <button type="submit" class="btn btn-primary">Jalankan Migrasi</button>
      </form>
    <?php else: ?>
      <p class="text-success">Kolom sudah tersedia. Tidak perlu migrasi.</p>
    <?php endif; ?>

    <hr class="my-4">
    <a href="<?= htmlspecialchars($base) ?>/stock/wqs_incoming.php" class="btn btn-outline-secondary">Kembali ke WQS Incoming</a>
    <a href="<?= htmlspecialchars($base) ?>/tools/index.php" class="btn btn-outline-secondary">Tools</a>
  </div>
</div>
<?php rmi_footer(); ?>
