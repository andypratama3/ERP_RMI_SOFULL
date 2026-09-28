<?php
/**
 * Jalankan migrasi 125_wqs_stock_per_office
 * Membuat tabel wqs_stock_by_office, migrasi data dari wqs_stock ke BGR, tambah kolom office_code di wqs_stock_adjustments.
 * NOTE: Tidak menggunakan HO. Office code hanya BGR, BDG, BKS, TGR, SLO, SMG, KAL, JGY, SYS.
 *
 * Stock konsisten per branch — tidak lintas branch tanpa serah terima.
 */
require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/../_shared/db.php';

$base = function_exists('auth_base_project') ? auth_base_project() : '';
$role = strtoupper(trim((string)($_SESSION['level'] ?? $_SESSION['role'] ?? '')));
if (!in_array($role, ['ADMIN', 'SUPERADMIN', 'SYS'], true)) {
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

if ($run) {
    try {
        // 1. Buat wqs_stock_by_office jika belum ada
        if (!table_exists($pdo, 'wqs_stock_by_office')) {
            $pdo->exec("CREATE TABLE wqs_stock_by_office (
                product_id INT NOT NULL,
                office_code VARCHAR(30) NOT NULL DEFAULT 'BGR',
                stock_qty DECIMAL(18,2) NOT NULL DEFAULT 0,
                updated_at DATETIME NULL,
                PRIMARY KEY (product_id, office_code),
                KEY idx_office (office_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $result['steps'][] = 'wqs_stock_by_office: tabel dibuat';
        } else {
            $result['steps'][] = 'wqs_stock_by_office: sudah ada';
        }

        // 2. Migrasi data dari wqs_stock ke BGR
        if (table_exists($pdo, 'wqs_stock') && table_exists($pdo, 'wqs_stock_by_office')) {
            $pdo->exec("INSERT INTO wqs_stock_by_office (product_id, office_code, stock_qty, updated_at)
                SELECT product_id, 'BGR', COALESCE(stock_qty, 0), updated_at FROM wqs_stock
                ON DUPLICATE KEY UPDATE stock_qty = VALUES(stock_qty), updated_at = VALUES(updated_at)");
            $result['steps'][] = 'Data wqs_stock dipindahkan ke office BGR';
        }

        // 2b. Jika ada data HO (legacy), pindahkan ke BGR
        if (table_exists($pdo, 'wqs_stock_by_office')) {
            $st = $pdo->query("SELECT COUNT(*) FROM wqs_stock_by_office WHERE office_code='HO'");
            if ($st && (int)$st->fetchColumn() > 0) {
                $pdo->exec("INSERT INTO wqs_stock_by_office (product_id, office_code, stock_qty, updated_at)
                    SELECT product_id, 'BGR', stock_qty, updated_at FROM wqs_stock_by_office WHERE office_code='HO'
                    ON DUPLICATE KEY UPDATE stock_qty = stock_qty + VALUES(stock_qty), updated_at = GREATEST(updated_at, VALUES(updated_at))");
                $pdo->exec("DELETE FROM wqs_stock_by_office WHERE office_code='HO'");
                $result['steps'][] = 'Data office HO dipindahkan ke BGR';
            }
        }

        // 3. Tambah office_code di wqs_stock_adjustments jika belum ada
        if (table_exists($pdo, 'wqs_stock_adjustments') && !col_exists($pdo, 'wqs_stock_adjustments', 'office_code')) {
            $pdo->exec("ALTER TABLE wqs_stock_adjustments ADD COLUMN office_code VARCHAR(30) NULL DEFAULT 'BGR'");
            $result['steps'][] = 'wqs_stock_adjustments.office_code: ditambahkan';
        } else {
            $result['steps'][] = 'wqs_stock_adjustments.office_code: sudah ada atau tabel tidak ada';
        }

        $result['ok'] = true;
        $result['msg'] = 'Migrasi 125 berhasil. Stock per office aktif.';
    } catch (Throwable $e) {
        $result['msg'] = $e->getMessage();
        $result['steps'][] = 'Error: ' . $e->getFile() . ':' . $e->getLine();
    }
}

$tablesExist = table_exists($pdo, 'wqs_stock_by_office');
$hasOfficeCol = table_exists($pdo, 'wqs_stock_adjustments') && col_exists($pdo, 'wqs_stock_adjustments', 'office_code');
$status = $tablesExist ? 'OK' : 'Perlu migrasi';

require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('WQS Stock Migration 125', 'tools');
?>
<div class="container py-4">
  <div class="card p-4">
    <h2>WQS Stock Migration 125</h2>
    <p class="text-muted">Stock per office (branch). Membuat tabel <code>wqs_stock_by_office</code>, migrasi data dari <code>wqs_stock</code> ke office BGR, tambah kolom <code>office_code</code> di <code>wqs_stock_adjustments</code>. <strong>NOTE:</strong> Tidak menggunakan HO.</p>
    <div class="alert alert-info small">
      <strong>SQL langsung (SSH/NAS):</strong><br>
      <code>cd /volume4/web/ERP_RMI_SOFULL && mysql -u root -p erp_rmi_sofull &lt; sql/migrations/125_wqs_stock_per_office.sql</code>
    </div>

    <div class="mb-3">
      <strong>Status:</strong>
      <span class="badge <?= $status === 'OK' ? 'bg-success' : 'bg-warning' ?>"><?= htmlspecialchars($status) ?></span>
      <ul class="mt-2 mb-0">
        <li>wqs_stock_by_office: <?= $tablesExist ? '✓ Ada' : '✗ Belum ada' ?></li>
        <?php if ($tablesExist): ?>
        <li>wqs_stock_adjustments.office_code: <?= $hasOfficeCol ? '✓ Ada' : '✗ Belum ada' ?></li>
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

    <?php if (!$tablesExist || !$hasOfficeCol): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '') ?>">
        <input type="hidden" name="run" value="1">
        <button type="submit" class="btn btn-primary">Jalankan Migrasi</button>
      </form>
    <?php else: ?>
      <p class="text-success">Migrasi sudah selesai. Stock per office aktif.</p>
    <?php endif; ?>

    <hr class="my-4">
    <a href="<?= htmlspecialchars($base) ?>/stock/wqs_stock.php" class="btn btn-outline-secondary">WQS Stock</a>
    <a href="<?= htmlspecialchars($base) ?>/stock/wqs_stock_opname.php" class="btn btn-outline-secondary">Stock Opname</a>
    <a href="<?= htmlspecialchars($base) ?>/tools/index.php" class="btn btn-outline-secondary">Tools</a>
  </div>
</div>
<?php rmi_footer(); ?>
