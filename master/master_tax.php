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

// master_tax.php
// Master Tax / Profil Pajak ERP_RMI_SOFULL


// --------------------------------------------------------
//  KONEKSI DB
// --------------------------------------------------------
// --- DB (centralized) ---
$pdo = db_pdo();

// --------------------------------------------------------
//  AUTO CREATE / ALTER TABEL master_tax
// --------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `master_tax` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `tax_code` varchar(30) NOT NULL,
          `tax_name` varchar(150) NOT NULL,
          `tax_type` varchar(50) NOT NULL,
          `rate_percent` decimal(5,2) DEFAULT NULL,
          `level_type` varchar(30) DEFAULT 'transaction',
          `office_scope` varchar(255) DEFAULT NULL,
          `status` varchar(20) NOT NULL DEFAULT 'active',
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_tax_code` (`tax_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    function mt_ensure_column(PDO $pdo, $table, $column, $definition)
    {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
        $stmt->execute([':col' => $column]);
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    }

    mt_ensure_column($pdo, 'master_tax', 'tax_code',      " `tax_code` varchar(30) NOT NULL");
    mt_ensure_column($pdo, 'master_tax', 'tax_name',      " `tax_name` varchar(150) NOT NULL");
    mt_ensure_column($pdo, 'master_tax', 'tax_type',      " `tax_type` varchar(50) NOT NULL");
    mt_ensure_column($pdo, 'master_tax', 'rate_percent',  " `rate_percent` decimal(5,2) DEFAULT NULL");
    mt_ensure_column($pdo, 'master_tax', 'level_type',   " `level_type` varchar(30) DEFAULT 'transaction'");
    mt_ensure_column($pdo, 'master_tax', 'office_scope',  " `office_scope` varchar(255) DEFAULT NULL");
    mt_ensure_column($pdo, 'master_tax', 'status',        " `status` varchar(20) NOT NULL DEFAULT 'active'");
    mt_ensure_column($pdo, 'master_tax', 'created_at',    " `created_at` datetime DEFAULT CURRENT_TIMESTAMP");
    mt_ensure_column($pdo, 'master_tax', 'updated_at',    " `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
} catch (PDOException $e) {
    // jangan matikan halaman
}

// --------------------------------------------------------
//  SESSION & FLASH
// --------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Enforce POST-only + CSRF for all mutations on this page
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
}

function set_flash($type, $message)
{
    $_SESSION['flash_tax'] = [
        'type'    => $type,
        'message' => $message
    ];
}

function get_flash()
{
    if (!empty($_SESSION['flash_tax'])) {
        $flash = $_SESSION['flash_tax'];
        unset($_SESSION['flash_tax']);
        return $flash;
    }
    return null;
}

$flash = get_flash();

// --------------------------------------------------------
//  HANDLE CREATE / UPDATE
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tax'])) {
    $id           = trim($_POST['id'] ?? '');
    $tax_code     = trim($_POST['tax_code'] ?? '');
    $tax_name     = trim($_POST['tax_name'] ?? '');
    $tax_type     = trim($_POST['tax_type'] ?? '');
    $rate_raw     = trim($_POST['rate_percent'] ?? '');
    $level_type  = trim($_POST['level_type'] ?? 'transaction');
    $office_scope = trim($_POST['office_scope'] ?? '');
    $status       = trim($_POST['status'] ?? 'active');

    $errors = [];

    if ($tax_code === '') {
        $errors[] = 'Tax Code wajib diisi.';
    }
    if ($tax_name === '') {
        $errors[] = 'Tax Name wajib diisi.';
    }
    if ($tax_type === '') {
        $errors[] = 'Tax Type wajib diisi.';
    }

    // rate boleh kosong (NULL)
    $rate_percent = null;
    if ($rate_raw !== '') {
        // ganti koma jadi titik, buang karakter aneh
        $clean = str_replace(',', '.', $rate_raw);
        $clean = preg_replace('/[^0-9\.]/', '', $clean);
        if ($clean !== '') {
            $rate_percent = (float)$clean;
            if ($rate_percent < 0) {
                $errors[] = 'Rate Percent tidak boleh negatif.';
            }
        }
    }

    if (!in_array($level_type, ['transaction','yearly'], true)) {
        $level_type = 'transaction';
    }

    if (!empty($errors)) {
        set_flash('danger', implode('<br>', $errors));
        rmi_redirect("master_tax.php");
    }

    try {
        if ($id === '') {
            // NEW
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_tax WHERE tax_code = :code");
            $cek->execute([':code' => $tax_code]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Tax Code sudah digunakan, silakan pakai kode lain.');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO master_tax
                    (tax_code, tax_name, tax_type, rate_percent, level_type, office_scope, status, created_at, updated_at)
                    VALUES
                    (:code, :name, :type, :rate, :level, :office_scope, :status, NOW(), NOW())
                ");
                $stmt->execute([
                    ':code'         => $tax_code,
                    ':name'         => $tax_name,
                    ':type'         => $tax_type,
                    ':rate'         => $rate_percent,
                    ':level'        => $level_type,
                    ':office_scope' => $office_scope,
                    ':status'       => $status ?: 'active',
                ]);
                if (function_exists('master_audit')) {
                    $newId = (int)$pdo->lastInsertId();
                    master_audit($pdo, 'master_tax', 'master_tax', 'CREATE', $newId, $tax_code, "Tax created: {$tax_code} - {$tax_name}", [
                        'tax_type'     => $tax_type,
                        'rate_percent' => $rate_percent,
                        'level_type'   => $level_type,
                        'office_scope' => $office_scope,
                        'status'       => $status ?: 'active',
                    ]);
                }
                set_flash('success', 'Data Tax Profile berhasil ditambahkan.');
            }
        } else {
            // UPDATE
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_tax WHERE tax_code = :code AND id <> :id");
            $cek->execute([':code' => $tax_code, ':id' => $id]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Tax Code sudah digunakan oleh data lain.');
            } else {
                $stmt = $pdo->prepare("
                    UPDATE master_tax
                    SET
                      tax_code     = :code,
                      tax_name     = :name,
                      tax_type     = :type,
                      rate_percent = :rate,
                      level_type  = :level,
                      office_scope = :office_scope,
                      status       = :status,
                      updated_at   = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':code'         => $tax_code,
                    ':name'         => $tax_name,
                    ':type'         => $tax_type,
                    ':rate'         => $rate_percent,
                    ':level'        => $level_type,
                    ':office_scope' => $office_scope,
                    ':status'       => $status ?: 'active',
                    ':id'           => $id,
                ]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_tax', 'master_tax', 'UPDATE', (int)$id, $tax_code, "Tax updated: {$tax_code} - {$tax_name}", [
                        'tax_type'     => $tax_type,
                        'rate_percent' => $rate_percent,
                        'level_type'   => $level_type,
                        'office_scope' => $office_scope,
                        'status'       => $status ?: 'active',
                    ]);
                }
                set_flash('success', 'Data Tax Profile berhasil diperbarui.');
            }
        }
    } catch (PDOException $e) {
        set_flash('danger', 'Error DB: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_tax.php");
}

// --------------------------------------------------------
//  HANDLE DELETE (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        try {
            $stCode = $pdo->prepare("SELECT tax_code, tax_name FROM master_tax WHERE id = :id");
            $stCode->execute([':id' => $id]);
            $tr = $stCode->fetch(PDO::FETCH_ASSOC);
            $code = (string)($tr['tax_code'] ?? '');
            $del = $pdo->prepare("DELETE FROM master_tax WHERE id = :id");
            $del->execute([':id' => $id]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_tax', 'master_tax', 'DELETE', $id, $code, "Tax deleted: {$code}", []);
            }
            set_flash('success', 'Tax berhasil dihapus.');
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal menghapus data: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_tax.php");
}

// --------------------------------------------------------
//  AMBIL DATA EDIT
// --------------------------------------------------------
$edit_data = [
    'id'           => '',
    'tax_code'     => '',
    'tax_name'     => '',
    'tax_type'     => '',
    'rate_percent' => '',
    'level_type'  => 'transaction',
    'office_scope' => '',
    'status'       => 'active',
];

if (isset($_GET['edit'])) {
    $id = (int) ($_GET['edit'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM master_tax WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $edit_data = [
                'id'           => $row['id'],
                'tax_code'     => $row['tax_code'],
                'tax_name'     => $row['tax_name'],
                'tax_type'     => $row['tax_type'],
                'rate_percent' => $row['rate_percent'],
                'level_type'  => $row['level_type'],
                'office_scope' => $row['office_scope'],
                'status'       => $row['status'],
            ];
        }
    }
}

// --------------------------------------------------------
//  LIST DATA + FILTER
// --------------------------------------------------------
$search        = trim($_GET['search'] ?? '');
$filter_status = trim($_GET['filter_status'] ?? '');
$filter_type   = trim($_GET['filter_type'] ?? '');
$filter_level  = trim($_GET['filter_level'] ?? '');

$where  = " WHERE 1=1 ";
$params = [];

if ($search !== '') {
    $where .= " AND (
        t.tax_code LIKE :search OR
        t.tax_name LIKE :search OR
        t.tax_type LIKE :search
    )";
    $params[':search'] = '%' . $search . '%';
}
if ($filter_status !== '') {
    $where .= " AND t.status = :st ";
    $params[':st'] = $filter_status;
}
if ($filter_type !== '') {
    $where .= " AND t.tax_type = :tt ";
    $params[':tt'] = $filter_type;
}
if ($filter_level !== '') {
    $where .= " AND t.level_type = :lv ";
    $params[':lv'] = $filter_level;
}

$list_sql = "
    SELECT t.*
    FROM master_tax t
    {$where}
    ORDER BY t.level_type ASC, t.tax_type ASC, t.tax_name ASC
";
$list_stmt = $pdo->prepare($list_sql);
foreach ($params as $k => $v) {
    $list_stmt->bindValue($k, $v);
}
$list_stmt->execute();
$taxes = $list_stmt->fetchAll();

$audit_rows = [];
if (function_exists('master_audit_ensure_table')) {
    master_audit_ensure_table($pdo);
    try {
        $stA = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='master_tax' ORDER BY created_at DESC LIMIT 50");
        $stA->execute();
        $audit_rows = $stA->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Tax', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Tax',
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
                <h5>MASTER TAX PROFILE</h5>
                <small class="text-muted">
                    Konfigurasi PPN, PPh 21/23/25, PPh Final, SPT Tahunan per kantor/departemen untuk DO / Invoice.
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
            <h5><?= $edit_data['id'] ? 'Edit Tax Profile' : 'Tambah Tax Profile' ?></h5>
        </div>
        <div class="rmi-card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= htmlspecialchars($edit_data['id']) ?>">

                <div class="col-md-3">
                    <label class="form-label">Tax Code<span class="text-danger">*</span></label>
                    <input type="text" name="tax_code" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['tax_code']) ?>"
                           placeholder="NONPPN, PPN11, PPH21, dsb">
                    <div class="text-muted-small">
                        Kode pendek, dipakai di DO/Invoice.
                    </div>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Tax Name<span class="text-danger">*</span></label>
                    <input type="text" name="tax_name" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['tax_name']) ?>"
                           placeholder="PPN 11%, PPh 21 (All Office), SPT Tahunan All Cabang, dll">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Tax Type<span class="text-danger">*</span></label>
                    <select name="tax_type" class="form-select form-select-sm">
                        <?php
                        $types = ['PPN','PPh 21','PPh 23','PPh 25','PPh Final','SPT Tahunan','Lainnya'];
                        $curType = $edit_data['tax_type'];
                        ?>
                        <option value="">-- Pilih --</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $curType === $t ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Rate (%)</label>
                    <input type="text" name="rate_percent" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['rate_percent']) ?>"
                           placeholder="11, 12, 0, dll">
                    <div class="text-muted-small">
                        Boleh kosong untuk pajak non-rate.
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Level Scope</label>
                    <select name="level_type" class="form-select form-select-sm">
                        <option value="transaction" <?= $edit_data['level_type'] === 'transaction' ? 'selected' : '' ?>>Transaction</option>
                        <option value="yearly"      <?= $edit_data['level_type'] === 'yearly' ? 'selected' : '' ?>>Yearly / SPT</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="active"   <?= $edit_data['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $edit_data['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Office Scope</label>
                    <input type="text" name="office_scope" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($edit_data['office_scope']) ?>"
                           placeholder="All Office, Bogor,Bekasi,Bandung, Semarang,Solo, dsb">
                    <div class="text-muted-small">
                        Info kantor/cabang mana yang terikat pajak ini.
                    </div>
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <button type="submit" name="save_tax" class="btn btn-sm btn-primary">
                        <?= $edit_data['id'] ? 'Update Tax Profile' : 'Simpan Tax Profile' ?>
                    </button>
                    <a href="master_tax.php" class="btn btn-sm btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- LIST -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>LIST TAX PROFILE</h5>
                <small class="text-muted">
                    Profil pajak untuk dipilih di DO/Invoice dan laporan pajak.
                </small>
            </div>
        </div>
        <div class="rmi-card-body">

            <!-- FILTER -->
            <form method="get" class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label mb-1">Cari (Kode / Nama / Type)</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($search) ?>"
                           placeholder="Ketik kata kunci...">
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1">Filter Type</label>
                    <select name="filter_type" class="form-select form-select-sm">
                        <option value="">-- Semua Type --</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $filter_type === $t ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Filter Level</label>
                    <select name="filter_level" class="form-select form-select-sm">
                        <option value="">-- Semua --</option>
                        <option value="transaction" <?= $filter_level === 'transaction' ? 'selected' : '' ?>>Transaction</option>
                        <option value="yearly"      <?= $filter_level === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Filter Status</label>
                    <select name="filter_status" class="form-select form-select-sm">
                        <option value="">-- Semua --</option>
                        <option value="active"   <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end justify-content-end">
                    <button type="submit" class="btn btn-sm btn-primary">Go</button>
                </div>
            </form>

            <!-- TABEL -->
            <div class="table-responsive">
                <table id="table-tax" class="table table-sm table-striped table-hover align-middle table-dark-custom" style="width:100%">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Rate (%)</th>
                        <th>Level</th>
                        <th>Office Scope</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($taxes)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">Belum ada data Tax Profile.</td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; ?>
                        <?php foreach ($taxes as $t): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><?= htmlspecialchars($t['tax_code']) ?></td>
                                <td><?= htmlspecialchars($t['tax_name']) ?></td>
                                <td><?= htmlspecialchars($t['tax_type']) ?></td>
                                <td>
                                    <?= $t['rate_percent'] !== null
                                        ? number_format((float)$t['rate_percent'], 2, ',', '.')
                                        : '-' ?>
                                </td>
                                <td><?= htmlspecialchars(ucfirst($t['level_type'])) ?></td>
                                <td><?= htmlspecialchars($t['office_scope']) ?></td>
                                <td>
                                    <?php if (($t['status'] ?? 'active') === 'active'): ?>
                                        <span class="badge badge-status active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-status inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="master_tax.php?edit=<?= (int)$t['id'] ?>"
                                       class="btn btn-sm btn-outline-primary mb-1">
                                        Edit
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Yakin hapus Tax Profile ini?')">
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

    <?php if (!empty($audit_rows)): ?>
    <div class="card rmi-card mt-3">
        <div class="rmi-card-header"><b>Audit Log</b> <span class="text-muted">(Last 50 events)</span></div>
        <div class="table-responsive">
            <table class="table table-sm table-dark table-hover mb-0">
                <thead><tr><th style="width:160px">Time</th><th style="width:100px">Action</th><th style="width:120px">Code</th><th style="width:100px">User</th><th>Description</th></tr></thead>
                <tbody>
                <?php foreach ($audit_rows as $a): ?>
                    <tr><td><?= function_exists('rmi_h') ? rmi_h($a['created_at'] ?? '') : htmlspecialchars($a['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td><td><?= function_exists('rmi_h') ? rmi_h($a['action'] ?? '') : htmlspecialchars($a['action'] ?? '', ENT_QUOTES, 'UTF-8') ?></td><td><?= function_exists('rmi_h') ? rmi_h($a['record_code'] ?? '') : htmlspecialchars($a['record_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></td><td><?= function_exists('rmi_h') ? rmi_h($a['username'] ?? '') : htmlspecialchars($a['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></td><td><?= function_exists('rmi_h') ? rmi_h($a['description'] ?? '') : htmlspecialchars($a['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

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
        $('#table-tax').DataTable({
            dom: 'Bfrtip',
            paging: true,
            responsive: true,
            lengthChange: true,
            pageLength: 10,
            order: [[2, 'asc']],
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
