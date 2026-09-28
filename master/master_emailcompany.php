<?php
require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_audit_master.php';
require_login();
// Master_Data: ADMIN & SUPERADMIN only
if (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.EMAIL_COMPANY_VIEW', 'MASTER.EMAIL_COMPANY_CREATE', 'MASTER.EMAIL_COMPANY_EDIT']);
}

// =====================================================
// master_emailcompany.php
// Master Email Company - ERP_RMI_SOFULL
// Integrasi ke:
//  - master_office (office_code)
//  - master_departements (dept_code)
// =====================================================


// Domain email perusahaan
$EMAIL_DOMAIN = 'rizqullahmediska.com';
// --- DB (centralized) ---
$pdo = db_pdo();

// --------------------------------------------------------
//  AUTO CREATE / MIGRATE TABEL master_emailcompany
// --------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `master_emailcompany` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `scope_type` varchar(20) NOT NULL DEFAULT 'department',  -- office / department
          `dept_code` varchar(20) DEFAULT NULL,
          `office_code` varchar(20) DEFAULT NULL,
          `email_local` varchar(100) NOT NULL,
          `email_domain` varchar(100) NOT NULL DEFAULT 'rizqullahmediska.com',
          `email_full` varchar(200) NOT NULL,
          `note` varchar(255) DEFAULT NULL,
          `is_primary` tinyint(1) NOT NULL DEFAULT 1,
          `status` varchar(20) NOT NULL DEFAULT 'active',
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
              ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_email_full` (`email_full`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // helper untuk tambah kolom kalau belum ada
    function mec_ensure_column(PDO $pdo, $table, $column, $definition) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
        $stmt->execute([':col' => $column]);
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    }

    mec_ensure_column($pdo, 'master_emailcompany', 'scope_type',
        " `scope_type` varchar(20) NOT NULL DEFAULT 'department' AFTER `id`");
    mec_ensure_column($pdo, 'master_emailcompany', 'dept_code',
        " `dept_code` varchar(20) DEFAULT NULL AFTER `scope_type`");
    mec_ensure_column($pdo, 'master_emailcompany', 'office_code',
        " `office_code` varchar(20) DEFAULT NULL AFTER `dept_code`");
    mec_ensure_column($pdo, 'master_emailcompany', 'email_local',
        " `email_local` varchar(100) NOT NULL AFTER `office_code`");
    mec_ensure_column($pdo, 'master_emailcompany', 'email_domain',
        " `email_domain` varchar(100) NOT NULL DEFAULT 'rizqullahmediska.com' AFTER `email_local`");
    mec_ensure_column($pdo, 'master_emailcompany', 'email_full',
        " `email_full` varchar(200) NOT NULL AFTER `email_domain`");
    mec_ensure_column($pdo, 'master_emailcompany', 'note',
        " `note` varchar(255) DEFAULT NULL AFTER `email_full`");
    mec_ensure_column($pdo, 'master_emailcompany', 'is_primary',
        " `is_primary` tinyint(1) NOT NULL DEFAULT 1 AFTER `note`");
    mec_ensure_column($pdo, 'master_emailcompany', 'status',
        " `status` varchar(20) NOT NULL DEFAULT 'active' AFTER `is_primary`");
    mec_ensure_column($pdo, 'master_emailcompany', 'created_at',
        " `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `status`");
    mec_ensure_column($pdo, 'master_emailcompany', 'updated_at',
        " `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
           ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

} catch (PDOException $e) {
    // jangan matikan halaman kalau alter gagal
}

// Normalisasi dept_code, office_code ke UPPERCASE
try {
    $pdo->exec("UPDATE master_emailcompany SET dept_code = UPPER(TRIM(dept_code)) WHERE dept_code IS NOT NULL AND dept_code != '' AND BINARY dept_code != UPPER(TRIM(dept_code))");
    $pdo->exec("UPDATE master_emailcompany SET office_code = UPPER(TRIM(office_code)) WHERE office_code IS NOT NULL AND office_code != '' AND BINARY office_code != UPPER(TRIM(office_code))");
} catch (Throwable $e) {}

// --------------------------------------------------------
//  SESSION & HELPER
// --------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Enforce POST-only + CSRF for all mutations on this page
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();
}

function set_flash($type, $message)
{
    $_SESSION['flash_emailcompany'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function get_flash()
{
    if (!empty($_SESSION['flash_emailcompany'])) {
        $f = $_SESSION['flash_emailcompany'];
        unset($_SESSION['flash_emailcompany']);
        return $f;
    }
    return null;
}

function e($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// --------------------------------------------------------
//  AMBIL MASTER DEPARTEMENTS & OFFICE
// --------------------------------------------------------
$departements = [];
$offices      = [];

try {
    // urutan dept sesuai yang kemarin
    $stmt = $pdo->query("
        SELECT dept_code, dept_name, status
        FROM master_departements
        ORDER BY FIELD(dept_code,'ACT','CRM','FIN','HRL','ITC','MPR','PQP','SCM','WQS'), dept_name ASC
    ");
    $departements = $stmt->fetchAll();
} catch (PDOException $e) {}

try {
    $stmt = $pdo->query("
        SELECT office_code, office_name, city
        FROM master_office
        ORDER BY FIELD(UPPER(office_code),'BGR','BKS','TGR','BDG','SLO','SMG','JGY','KAL'), office_name ASC
    ");
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $offices = [];
    foreach ($raw as $o) {
        $oc = strtoupper(trim((string)($o['office_code'] ?? '')));
        if ($oc !== '') $offices[] = ['office_code'=>$oc,'office_name'=>strtoupper($o['office_name']??$oc),'city'=>strtoupper($o['city']??'')];
    }
} catch (PDOException $e) {
    $offices = [];
}

// --------------------------------------------------------
//  HELPER BERSIHKAN LOCAL PART EMAIL
// --------------------------------------------------------
function sanitize_email_local($local) {
    $local = trim($local);
    $local = strtolower($local);
    // hanya huruf, angka, titik, minus, underscore
    $local = preg_replace('/[^a-z0-9\.\-\_]/', '', $local);
    return $local;
}

// --------------------------------------------------------
//  HANDLE SAVE (CREATE / UPDATE)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_email'])) {

    global $EMAIL_DOMAIN;

    $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $scope_type  = $_POST['scope_type'] ?? 'department';  // office / department
    $dept_code   = strtoupper(trim($_POST['dept_code'] ?? ''));
    $office_code = strtoupper(trim($_POST['office_code'] ?? ''));
    $email_local_raw = $_POST['email_local'] ?? '';
    $note        = trim($_POST['note'] ?? '');
    $status      = trim($_POST['status'] ?? 'active');
    $is_primary  = isset($_POST['is_primary']) ? 1 : 0;

    $errors = [];

    $scope_type = ($scope_type === 'office') ? 'office' : 'department';

    // Validasi relasi ke dept & office
    if ($scope_type === 'office') {
        if ($office_code === '') {
            $errors[] = 'Office wajib dipilih untuk email tipe Office.';
        }
    } else { // department
        if ($dept_code === '') {
            $errors[] = 'Departement wajib dipilih untuk email tipe Departement.';
        }
        if ($office_code === '') {
            $errors[] = 'Office wajib dipilih untuk email tipe Departement.';
        }
    }

    $email_local = sanitize_email_local($email_local_raw);
    if ($email_local === '') {
        $errors[] = 'Nama email (sebelum @) wajib diisi dan hanya boleh huruf/angka/titik/(-)/(_).';
    }

    if ($status !== 'inactive') {
        $status = 'active';
    }

    $email_domain = $EMAIL_DOMAIN;
    $email_full   = $email_local . '@' . $email_domain;

    if (!empty($errors)) {
        set_flash('danger', implode('<br>', $errors));
        rmi_redirect("master_emailcompany.php");
    }

    try {
        if ($id === 0) {
            // Cek duplikasi email
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_emailcompany WHERE email_full = :email");
            $cek->execute([':email' => $email_full]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Email sudah digunakan, silakan pakai nama lain.');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO master_emailcompany
                    (scope_type, dept_code, office_code,
                     email_local, email_domain, email_full,
                     note, is_primary, status, created_at, updated_at)
                    VALUES
                    (:scope_type, :dept_code, :office_code,
                     :email_local, :email_domain, :email_full,
                     :note, :is_primary, :status, NOW(), NOW())
                ");
                $stmt->execute([
                    ':scope_type'  => $scope_type,
                    ':dept_code'   => $scope_type === 'department' ? $dept_code : null,
                    ':office_code' => $office_code ?: null,
                    ':email_local' => $email_local,
                    ':email_domain'=> $email_domain,
                    ':email_full'  => $email_full,
                    ':note'        => $note,
                    ':is_primary'  => $is_primary,
                    ':status'      => $status,
                ]);
                $newId = (int)$pdo->lastInsertId();
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_emailcompany', 'master_emailcompany', 'CREATE', $newId, $email_full, "Email company created: {$email_full}", []);
                }
                set_flash('success', 'Email company baru berhasil ditambahkan.');
            }
        } else {
            // Cek duplikasi email selain dirinya
            $cek = $pdo->prepare("SELECT COUNT(*) FROM master_emailcompany WHERE email_full = :email AND id <> :id");
            $cek->execute([':email' => $email_full, ':id' => $id]);
            if ((int)$cek->fetchColumn() > 0) {
                set_flash('danger', 'Email sudah digunakan oleh record lain.');
            } else {
                $stmt = $pdo->prepare("
                    UPDATE master_emailcompany
                    SET scope_type   = :scope_type,
                        dept_code    = :dept_code,
                        office_code  = :office_code,
                        email_local  = :email_local,
                        email_domain = :email_domain,
                        email_full   = :email_full,
                        note         = :note,
                        is_primary   = :is_primary,
                        status       = :status,
                        updated_at   = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':scope_type'  => $scope_type,
                    ':dept_code'   => $scope_type === 'department' ? $dept_code : null,
                    ':office_code' => $office_code ?: null,
                    ':email_local' => $email_local,
                    ':email_domain'=> $email_domain,
                    ':email_full'  => $email_full,
                    ':note'        => $note,
                    ':is_primary'  => $is_primary,
                    ':status'      => $status,
                    ':id'          => $id,
                ]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'master_emailcompany', 'master_emailcompany', 'UPDATE', $id, $email_full, "Email company updated: {$email_full}", []);
                }
                set_flash('success', 'Email company berhasil diperbarui.');
            }
        }

    } catch (PDOException $e) {
        set_flash('danger', 'Error DB: ' . htmlspecialchars($e->getMessage()));
    }

    rmi_redirect("master_emailcompany.php");
}

// --------------------------------------------------------
//  HANDLE DELETE (POST-only + CSRF)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        try {
            $stCode = $pdo->prepare("SELECT email_full FROM master_emailcompany WHERE id = :id");
            $stCode->execute([':id' => $id]);
            $code = (string)($stCode->fetchColumn() ?: '');
            $stmt = $pdo->prepare("DELETE FROM master_emailcompany WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'master_emailcompany', 'master_emailcompany', 'DELETE', $id, $code, "Email company deleted: {$code}", []);
            }
            set_flash('success', 'Data email company berhasil dihapus.');
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal menghapus data: ' . htmlspecialchars($e->getMessage()));
        }
    }
    rmi_redirect("master_emailcompany.php");
}

// --------------------------------------------------------
//  AMBIL DATA EDIT
// --------------------------------------------------------
$edit_data = [
    'id'          => 0,
    'scope_type'  => 'office',
    'dept_code'   => '',
    'office_code' => '',
    'email_local' => '',
    'note'        => '',
    'is_primary'  => 1,
    'status'      => 'active',
];

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM master_emailcompany WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $edit_data = [
                'id'          => $row['id'],
                'scope_type'  => $row['scope_type'],
                'dept_code'   => $row['dept_code'],
                'office_code' => $row['office_code'],
                'email_local' => $row['email_local'],
                'note'        => $row['note'],
                'is_primary'  => (int)$row['is_primary'],
                'status'      => $row['status'],
            ];
        }
    }
}

// --------------------------------------------------------
//  LIST DATA EMAIL COMPANY (JOIN DEPT + OFFICE)
// --------------------------------------------------------
$list_sql = "
    SELECT e.*,
           d.dept_name,
           o.office_name,
           o.city
    FROM master_emailcompany e
    LEFT JOIN master_departements d ON d.dept_code = e.dept_code
    LEFT JOIN master_office       o ON o.office_code = e.office_code
    ORDER BY
        CASE WHEN e.scope_type = 'office' THEN 0 ELSE 1 END,
        o.office_name,
        d.dept_code,
        e.email_local
";
$list_stmt  = $pdo->query($list_sql);
$email_rows = $list_stmt->fetchAll();

$flash = get_flash();

// --------------------------------------------------------
//  FRONTEND
// --------------------------------------------------------
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Master Email Company', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Master Email Company',
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
                <h5>MASTER EMAIL COMPANY</h5>
                <small class="text-muted">
                    Email resmi perusahaan (kantor & departemen) dengan domain
                    <strong>@<?= e($EMAIL_DOMAIN) ?></strong>.<br>
                    Nantinya dikaitkan ke master_employees & alur sistem internal.
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
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= function_exists('rmi_h') ? rmi_h($flash['message'] ?? '') : htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- FORM INPUT / EDIT EMAIL -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5><?= $edit_data['id'] ? 'Edit Email Company' : 'Tambah Email Company' ?></h5>
        </div>
        <div class="rmi-card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int)$edit_data['id'] ?>">

                <div class="col-md-3">
                    <label class="form-label">Jenis Email<span class="text-danger">*</span></label>
                    <select name="scope_type" id="scope_type" class="form-select form-select-sm">
                        <option value="office" <?= $edit_data['scope_type'] === 'office' ? 'selected' : '' ?>>
                            Office (Induk Kantor)
                        </option>
                        <option value="department" <?= $edit_data['scope_type'] === 'department' ? 'selected' : '' ?>>
                            Departemen (per Kantor)
                        </option>
                    </select>
                    <div class="text-muted-small">
                        Office = email umum kantor. Departemen = email khusus departemen di kantor tertentu.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Office / Kantor<span class="text-danger">*</span></label>
                    <select name="office_code" id="office_code" class="form-select form-select-sm">
                        <option value="">-- Pilih Office --</option>
                        <?php foreach ($offices as $o): ?>
                            <option value="<?= e($o['office_code']) ?>"
                                <?= $edit_data['office_code'] === $o['office_code'] ? 'selected' : '' ?>>
                                [<?= e($o['office_code']) ?>] <?= e($o['office_name']) ?> - <?= e($o['city']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-muted-small">
                        Wajib untuk semua jenis email (Office & Departemen).
                    </div>
                </div>

                <div class="col-md-5" id="dept_wrapper">
                    <label class="form-label">Departemen</label>
                    <select name="dept_code" id="dept_code" class="form-select form-select-sm">
                        <option value="">-- Pilih Departemen --</option>
                        <?php foreach ($departements as $d): ?>
                            <option value="<?= e($d['dept_code']) ?>"
                                <?= $edit_data['dept_code'] === $d['dept_code'] ? 'selected' : '' ?>>
                                [<?= e($d['dept_code']) ?>] <?= e($d['dept_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-muted-small">
                        Wajib diisi jika jenis email = Departemen.
                    </div>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Nama Email (sebelum @)<span class="text-danger">*</span></label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="email_local" id="email_local"
                               class="form-control"
                               value="<?= e($edit_data['email_local']) ?>"
                               placeholder="contoh: info, act.bgr, finance.bks">
                        <span class="input-group-text">@<?= e($EMAIL_DOMAIN) ?></span>
                    </div>
                    <div class="text-muted-small">
                        Hanya huruf, angka, titik, minus, underscore. Domain dikunci ke perusahaan.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Catatan / Keterangan</label>
                    <input type="text" name="note" class="form-control form-control-sm"
                           value="<?= e($edit_data['note']) ?>"
                           placeholder="Contoh: Email utama kantor BGR, Email departemen ACT Bandung, dll.">
                </div>

                <div class="col-md-1">
                    <label class="form-label">Primary?</label>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" name="is_primary" id="is_primary"
                               value="1" <?= $edit_data['is_primary'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_primary">
                            Ya
                        </label>
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="active"   <?= $edit_data['status'] === 'active' ? 'selected' : '' ?>>ACTIVE</option>
                        <option value="inactive" <?= $edit_data['status'] === 'inactive' ? 'selected' : '' ?>>INACTIVE</option>
                    </select>
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <button type="submit" name="save_email" class="btn btn-sm btn-primary">
                        <?= $edit_data['id'] ? 'Update Email' : 'Simpan Email' ?>
                    </button>
                    <a href="master_emailcompany.php" class="btn btn-sm btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- LIST EMAIL COMPANY -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <h5>LIST EMAIL COMPANY</h5>
        </div>
        <div class="rmi-card-body">
            <div class="table-responsive">
                <table id="table-email" class="table table-sm table-hover align-middle table-dark-custom" style="width:100%">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Jenis</th>
                        <th>Office</th>
                        <th>Departemen</th>
                        <th>Email</th>
                        <th>Primary</th>
                        <th>Status</th>
                        <th>Catatan</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($email_rows)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">Belum ada data email company.</td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; ?>
                        <?php foreach ($email_rows as $row): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td>
                                    <?php if ($row['scope_type'] === 'office'): ?>
                                        <span class="badge bg-info text-dark">Office</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Departemen</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['office_name'])): ?>
                                        <span class="pill-office"><?= e($row['office_name']) ?></span><br>
                                        <span class="text-muted-small">[<?= e($row['office_code']) ?>] <?= e($row['city']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted-small">[<?= e($row['office_code']) ?>]</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['scope_type'] === 'office'): ?>
                                        <span class="text-muted-small">- (Office umum)</span>
                                    <?php else: ?>
                                        <?php if (!empty($row['dept_name'])): ?>
                                            <span class="pill-dept"><?= e($row['dept_name']) ?></span><br>
                                            <span class="text-muted-small">[<?= e($row['dept_code']) ?>]</span>
                                        <?php else: ?>
                                            <span class="text-muted-small">[<?= e($row['dept_code']) ?>]</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="email-pill"><?= e($row['email_full']) ?></span>
                                </td>
                                <td>
                                    <?php if ((int)$row['is_primary'] === 1): ?>
                                        <span class="badge bg-success">Yes</span>
                                    <?php else: ?>
                                        <span class="badge bg-dark">No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (($row['status'] ?? 'active') === 'active'): ?>
                                        <span class="badge-status active">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="badge-status inactive">INACTIVE</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($row['note']) ?></td>
                                <td class="text-center">
                                    <a href="master_emailcompany.php?edit=<?= (int)$row['id'] ?>"
                                       class="btn btn-sm btn-outline-light mb-1">
                                        Edit
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Yakin hapus email ini?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="delete_id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-muted-small mt-2">
                Mapping ini nanti bisa dipakai di <strong>master_employees</strong> untuk assign
                email resmi per karyawan (berdasar Departemen & Office) dan di modul internal lain.
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
        // toggle Department dropdown berdasarkan scope_type
        function toggleDept() {
            var type = $('#scope_type').val();
            if (type === 'department') {
                $('#dept_wrapper').show();
            } else {
                $('#dept_wrapper').hide();
                $('#dept_code').val('');
            }
        }
        $('#scope_type').on('change', toggleDept);
        toggleDept();

        // DataTables
        $('#table-email').DataTable({
            dom: 'Bfrtip',
            paging: true,
            responsive: true,
            lengthChange: true,
            pageLength: 20,
            order: [[2, 'asc']], // sort by Office
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
