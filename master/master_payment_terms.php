<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_audit_master.php';
require_login();
// Master_Data: ADMIN & SUPERADMIN only
if (function_exists('require_level')) {
    require_level(['admin','superadmin']);
} elseif (function_exists('require_roles')) {
    require_roles(['SYS', 'ADMIN','SUPERADMIN']);
}

// master_payment_terms.php
// Master Payment Terms ERP_RMI_SOFULL


// --------------------------------------------------------
//  KONEKSI DB
// --------------------------------------------------------
// --- DB (centralized) ---
$pdo = db_pdo();

// --------------------------------------------------------
//  AUTO CREATE / ALTER TABEL master_payment_terms
// --------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `master_payment_terms` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `payment_terms_code` varchar(30) NOT NULL,
          `payment_terms_name` varchar(150) NOT NULL,
          `days_due` int(11) DEFAULT 0,
          `description` text DEFAULT NULL,
          `status` varchar(20) NOT NULL DEFAULT 'active',
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_payment_terms_code` (`payment_terms_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    function mpt_ensure_column(PDO $pdo, $table, $column, $definition)
    {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
        $stmt->execute([':col' => $column]);
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    }

    mpt_ensure_column($pdo, 'master_payment_terms', 'payment_terms_code', " `payment_terms_code` varchar(30) NOT NULL");
    mpt_ensure_column($pdo, 'master_payment_terms', 'payment_terms_name', " `payment_terms_name` varchar(150) NOT NULL");
    mpt_ensure_column($pdo, 'master_payment_terms', 'days_due',          " `days_due` int(11) DEFAULT 0");
    mpt_ensure_column($pdo, 'master_payment_terms', 'description',       " `description` text DEFAULT NULL");
    mpt_ensure_column($pdo, 'master_payment_terms', 'status',            " `status` varchar(20) NOT NULL DEFAULT 'active'");
    mpt_ensure_column($pdo, 'master_payment_terms', 'created_at',        " `created_at` datetime DEFAULT CURRENT_TIMESTAMP");
    mpt_ensure_column($pdo, 'master_payment_terms', 'updated_at',        " `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
} catch (PDOException $e) {
    // jangan matikan halaman
}

// --------------------------------------------------------
//  SESSION & FLASH
// --------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function set_flash($type, $message)
{
    $_SESSION['flash_mpt'] = [
        'type'    => $type,
        'message' => $message
    ];
}

function get_flash()
{
    if (!empty($_SESSION['flash_mpt'])) {
        $flash = $_SESSION['flash_mpt'];
        unset($_SESSION['flash_mpt']);
        return $flash;
    }
    return null;
}

$flash = get_flash();

// --------------------------------------------------------
//  HANDLE CREATE / UPDATE
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_terms'])) {
    require_post();
    verify_csrf();
    $id                 = trim($_POST['id'] ?? '');
    $payment_terms_code = trim($_POST['payment_terms_code'] ?? '');
    $payment_terms_name = trim($_POST['payment_terms_name'] ?? '');
    $days_due_raw       = trim($_POST['days_due'] ?? '0');
    $description        = trim($_POST['description'] ?? '');
    $status             = trim($_POST['status'] ?? 'active');

    $errors = [];

    if ($payment_terms_code === '') {
        $errors[] = 'Kode Payment Terms wajib diisi.';
    }
    if ($payment_terms_name === '') {
        $errors[] = 'Nama Payment Terms wajib diisi.';
    }

    $days_due = is_numeric($days_due_raw) ? (int)$days_due_raw : 0;
    if ($days_due < 0) {
        $errors[] = 'Days Due tidak boleh negatif.';
    }

    if (!empty($errors)) {
        set_flash('danger', implode('<br>', $errors));
        rmi_redirect("master_payment_terms.php");
    }

    try {
        if ($id === '') {
            // NEW
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_payment_terms WHERE payment_terms_code = :code");
            $cek->execute([':code' => $payment_terms_code]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Kode Payment Terms sudah digunakan, silakan pakai kode lain.');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO master_payment_terms
                    (payment_terms_code, payment_terms_name, days_due, description, status, created_at, updated_at)
                    VALUES
                    (:code, :name, :days, :description, :status, NOW(), NOW())
                ");
                $stmt->execute([
                    ':code'        => $payment_terms_code,
                    ':name'        => $payment_terms_name,
                    ':days'        => $days_due,
                    ':description' => $description,
                    ':status'      => $status ?: 'active',
                ]);
                $newId = (int)$pdo->lastInsertId();
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_payment_terms', 'master_payment_terms', 'CREATE', $newId, $payment_terms_code, "Payment terms created: {$payment_terms_code} - {$payment_terms_name}", []);
                }
                set_flash('success', 'Payment Terms berhasil ditambahkan.');
            }
        } else {
            // UPDATE
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_payment_terms WHERE payment_terms_code = :code AND id <> :id");
            $cek->execute([':code' => $payment_terms_code, ':id' => $id]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Kode Payment Terms sudah digunakan oleh data lain.');
            } else {
                $stmt = $pdo->prepare("
                    UPDATE master_payment_terms
                    SET
                      payment_terms_code = :code,
                      payment_terms_name = :name,
                      days_due           = :days,
                      description        = :description,
                      status             = :status,
                      updated_at         = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':code'        => $payment_terms_code,
                    ':name'        => $payment_terms_name,
                    ':days'        => $days_due,
                    ':description' => $description,
                    ':status'      => $status ?: 'active',
                    ':id'          => $id,
                ]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_payment_terms', 'master_payment_terms', 'UPDATE', (int)$id, $payment_terms_code, "Payment terms updated: {$payment_terms_code} - {$payment_terms_name}", []);
                }
                set_flash('success', 'Payment Terms berhasil diperbarui.');
            }
        }
    } catch (PDOException $e) {
        set_flash('danger', 'Error DB: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_payment_terms.php");
}

// --------------------------------------------------------
//  HANDLE DELETE (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_post();
    verify_csrf();

    $id = (int) ($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        try {
            $stCode = $pdo->prepare("SELECT payment_terms_code, payment_terms_name FROM master_payment_terms WHERE id = :id");
            $stCode->execute([':id' => $id]);
            $row = $stCode->fetch(PDO::FETCH_ASSOC);
            $code = (string)($row['payment_terms_code'] ?? '');
            $del = $pdo->prepare("DELETE FROM master_payment_terms WHERE id = :id");
            $del->execute([':id' => $id]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_payment_terms', 'master_payment_terms', 'DELETE', $id, $code, "Payment terms deleted: {$code}", []);
            }
            set_flash('success', 'Payment Terms berhasil dihapus.');
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal menghapus data: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_payment_terms.php");
}

// --------------------------------------------------------
//  AMBIL DATA EDIT
// --------------------------------------------------------
$edit_data = [
    'id'                 => '',
    'payment_terms_code' => '',
    'payment_terms_name' => '',
    'days_due'           => 0,
    'description'        => '',
    'status'             => 'active',
];

if (isset($_GET['edit'])) {
    $id = (int) ($_GET['edit'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM master_payment_terms WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $edit_data = [
                'id'                 => $row['id'],
                'payment_terms_code' => $row['payment_terms_code'],
                'payment_terms_name' => $row['payment_terms_name'],
                'days_due'           => $row['days_due'],
                'description'        => $row['description'],
                'status'             => $row['status'],
            ];
        }
    }
}

// --------------------------------------------------------
//  LIST DATA + FILTER
// --------------------------------------------------------
$search        = trim($_GET['search'] ?? '');
$filter_status = trim($_GET['filter_status'] ?? '');

$where  = " WHERE 1=1 ";
$params = [];

if ($search !== '') {
    $where .= " AND (
        t.payment_terms_code LIKE :search OR
        t.payment_terms_name LIKE :search
    )";
    $params[':search'] = '%' . $search . '%';
}
if ($filter_status !== '') {
    $where .= " AND t.status = :st ";
    $params[':st'] = $filter_status;
}

$list_sql = "
    SELECT t.*
    FROM master_payment_terms t
    {$where}
    ORDER BY t.days_due ASC, t.payment_terms_name ASC
";
$list_stmt = $pdo->prepare($list_sql);
foreach ($params as $k => $v) {
    $list_stmt->bindValue($k, $v);
}
$list_stmt->execute();
$terms = $list_stmt->fetchAll();

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Payment Terms', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Payment Terms',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>


<div class="rmi-container">

    <!-- HEADER -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>MASTER PAYMENT TERMS</h5>
                <small class="text-muted">
                    Standarisasi syarat pembayaran (COD, CBD, TOP 7/14/30, dll) untuk DO / Invoice.
                </small>
            </div>
            <div>
                <a href="master_data.php" class="btn btn-sm btn-secondary">
                    &laquo; Kembali ke Master Data
                </a>
            </div>
        </div>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= function_exists('rmi_h') ? rmi_h($flash['message'] ?? '') : htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- FORM INPUT / EDIT -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5><?= $edit_data['id'] ? 'Edit Payment Terms' : 'Tambah Payment Terms' ?></h5>
        </div>
        <div class="rmi-card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="id" value="<?= htmlspecialchars($edit_data['id']) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div class="col-md-3">
                    <label class="form-label">Kode<span class="text-danger">*</span></label>
                    <input type="text" name="payment_terms_code" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['payment_terms_code']) ?>"
                           placeholder="Contoh: COD, CBD, TOP30">
                    <div class="text-muted-small">
                        Dipakai di DO/Invoice (kode pendek).
                    </div>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Nama Payment Terms<span class="text-danger">*</span></label>
                    <input type="text" name="payment_terms_name" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['payment_terms_name']) ?>"
                           placeholder="Cash on Delivery, TOP 30 Hari, dll">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Days Due</label>
                    <input type="number" name="days_due" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['days_due']) ?>">
                    <div class="text-muted-small">
                        0 = hari H invoice (COD/CBD).
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="active"   <?= $edit_data['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $edit_data['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Deskripsi</label>
                    <textarea name="description" rows="2" class="form-control form-control-sm"
                              placeholder="Catatan tambahan, misalnya syarat khusus internal / customers tertentu."><?= htmlspecialchars($edit_data['description']) ?></textarea>
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <button type="submit" name="save_terms" class="btn btn-sm btn-primary">
                        <?= $edit_data['id'] ? 'Update Payment Terms' : 'Simpan Payment Terms' ?>
                    </button>
                    <a href="master_payment_terms.php" class="btn btn-sm btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- LIST -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>LIST PAYMENT TERMS</h5>
                <small class="text-muted">
                    Digunakan di DO/Invoice untuk hitung jatuh tempo otomatis.
                </small>
            </div>
        </div>
        <div class="rmi-card-body">

            <!-- FILTER -->
            <form method="get" class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label mb-1">Cari (Kode / Nama)</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($search) ?>"
                           placeholder="Ketik kata kunci...">
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1">Filter Status</label>
                    <select name="filter_status" class="form-select form-select-sm">
                        <option value="">-- Semua Status --</option>
                        <option value="active"   <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end justify-content-end">
                    <button type="submit" class="btn btn-sm btn-primary me-2">Terapkan</button>
                    <a href="master_payment_terms.php" class="btn btn-sm btn-secondary">Reset</a>
                </div>
            </form>

            <!-- TABEL -->
            <div class="table-responsive">
                <table id="table-terms" class="table table-sm table-striped table-hover align-middle table-dark-custom" style="width:100%">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Days Due</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($terms)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">Belum ada data Payment Terms.</td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; ?>
                        <?php foreach ($terms as $t): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><?= htmlspecialchars($t['payment_terms_code']) ?></td>
                                <td><?= htmlspecialchars($t['payment_terms_name']) ?></td>
                                <td><?= (int)$t['days_due'] ?></td>
                                <td>
                                    <?php if (($t['status'] ?? 'active') === 'active'): ?>
                                        <span class="badge badge-status active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-status inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="master_payment_terms.php?edit=<?= (int)$t['id'] ?>"
                                       class="btn btn-sm btn-outline-primary mb-1">
                                        Edit
                                    </a>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Yakin hapus Payment Terms ini?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="delete_id" value="<?= (int)$t['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>

<!-- JS: jQuery, Bootstrap, DataTables + Buttons -->
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209"></script>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>

<script>
    $(function () {
        $('#table-terms').DataTable({
            dom: 'Bfrtip',
            paging: true,
            responsive: true,
            lengthChange: true,
            pageLength: 10,
            order: [[3, 'asc']],
            buttons: [
                {extend: 'copyHtml5',  className: 'btn btn-sm btn-outline-light'},
                {extend: 'csvHtml5',   className: 'btn btn-sm btn-outline-light'},
                {extend: 'excelHtml5', className: 'btn btn-sm btn-outline-light'},
                {extend: 'pdfHtml5',   className: 'btn btn-sm btn-outline-light'},
                {extend: 'print',      className: 'btn btn-sm btn-outline-light'}
            ]
        });
    });
</script>
<?php rmi_footer(); ?>
