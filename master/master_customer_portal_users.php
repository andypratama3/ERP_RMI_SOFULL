<?php
/**
 * master/master_customer_portal_users.php
 * Kelola user Customer Portal (Hermina, dll).
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_audit_master.php';
require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

if (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.CUSTOMER_VIEW', 'MASTER.CUSTOMER_CREATE', 'MASTER.CUSTOMER_EDIT', 'MASTER.VIEW']);
} else {
    require_role(['SYS', 'SUPERADMIN', 'ADMIN', 'CRM']);
}

$pdo = db_pdo();

// Ensure table exists (+ master_mpr_id jika migration 139 belum jalan)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_portal_users (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          username VARCHAR(50) NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          full_name VARCHAR(120) DEFAULT NULL,
          email VARCHAR(150) DEFAULT NULL,
          phone VARCHAR(50) DEFAULT NULL,
          customers_code VARCHAR(50) NOT NULL,
          office_code VARCHAR(30) DEFAULT NULL,
          master_mpr_id INT DEFAULT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'active',
          last_login_at DATETIME DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uk_username (username),
          KEY idx_customers_code (customers_code),
          KEY idx_status (status),
          KEY idx_master_mpr_id (master_mpr_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $chk = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customer_portal_users' AND column_name='master_mpr_id'");
    if ($chk && (int)$chk->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE customer_portal_users ADD COLUMN master_mpr_id INT DEFAULT NULL AFTER office_code");
    }
} catch (Throwable $e) {
    // table may already exist
}

$flash = null;
if (!empty($_SESSION['flash_portal_users'])) {
    $flash = $_SESSION['flash_portal_users'];
    unset($_SESSION['flash_portal_users']);
}

// Customers & Offices
$customers = [];
$offices = [];
$pics = []; // PIC dari master_mpr per customer
try {
    $customers = $pdo->query("SELECT customers_code, customers_name FROM master_customers WHERE LOWER(TRIM(COALESCE(status,''))) = 'active' AND TRIM(COALESCE(customers_code,'')) != '' ORDER BY customers_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
try {
    $offices = $pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active=1 ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
try {
    $pics = $pdo->query("SELECT m.id, m.customers_code, m.contact_name, m.role_title, m.phone, m.email, c.customers_name FROM master_mpr m LEFT JOIN master_customers c ON c.customers_code = m.customers_code WHERE m.status='active' ORDER BY c.customers_name, m.contact_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Pre-fill dari master_mpr (?from_mpr=ID)
$from_mpr = null;
$form_prefill = ['full_name'=>'','email'=>'','phone'=>'','customers_code'=>'','office_code'=>'','master_mpr_id'=>''];
if (isset($_GET['from_mpr']) && ctype_digit($_GET['from_mpr'])) {
    $mpr_id = (int)$_GET['from_mpr'];
    $st = $pdo->prepare("SELECT m.id, m.customers_code, m.contact_name, m.phone, m.email, c.office_code FROM master_mpr m LEFT JOIN master_customers c ON c.customers_code = m.customers_code WHERE m.id = ? AND m.status='active'");
    $st->execute([$mpr_id]);
    $from_mpr = $st->fetch(PDO::FETCH_ASSOC);
    if ($from_mpr) {
        $form_prefill = [
            'full_name' => (string)($from_mpr['contact_name'] ?? ''),
            'email' => (string)($from_mpr['email'] ?? ''),
            'phone' => (string)($from_mpr['phone'] ?? ''),
            'customers_code' => (string)($from_mpr['customers_code'] ?? ''),
            'office_code' => (string)($from_mpr['office_code'] ?? ''),
            'master_mpr_id' => (string)$mpr_id,
        ];
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $master_mpr_id = (int)($_POST['master_mpr_id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password_confirm = (string)($_POST['password_confirm'] ?? '');
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $customers_code = trim((string)($_POST['customers_code'] ?? ''));
        $office_code = trim((string)($_POST['office_code'] ?? ''));

        $err = [];
        if ($username === '') $err[] = 'Username wajib.';
        if (strlen($username) < 3) $err[] = 'Username minimal 3 karakter.';
        if ($password === '') $err[] = 'Password wajib.';
        if (strlen($password) < 6) $err[] = 'Password minimal 6 karakter.';
        if ($password !== $password_confirm) $err[] = 'Password dan konfirmasi tidak sama.';
        if ($customers_code === '') $err[] = 'Customer wajib dipilih.';

        if (empty($err)) {
            $st = $pdo->prepare("SELECT 1 FROM customer_portal_users WHERE username = ?");
            $st->execute([$username]);
            if ($st->fetch()) {
                $err[] = 'Username sudah dipakai.';
            }
        }

        if (empty($err)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $hasMprCol = false;
            try {
                $chk = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customer_portal_users' AND column_name='master_mpr_id'");
                if ($chk && (int)$chk->fetchColumn() > 0) $hasMprCol = true;
            } catch (Throwable $e) {}
            $cols = 'username, password_hash, full_name, email, phone, customers_code, office_code, status';
            $vals = '?, ?, ?, ?, ?, ?, ?, ?';
            $params = [$username, $hash, $full_name, $email, $phone, $customers_code, $office_code ?: null, 'active'];
            if ($hasMprCol && $master_mpr_id > 0) {
                $cols .= ', master_mpr_id';
                $vals .= ', ?';
                $params[] = $master_mpr_id;
            }
            $st = $pdo->prepare("INSERT INTO customer_portal_users ($cols) VALUES ($vals)");
            $st->execute($params);
            $newId = (int)$pdo->lastInsertId();
            if (function_exists('master_audit')) {
                master_audit($pdo, 'customer_portal_users', 'customer_portal_users', 'CREATE', $newId, $username, "Customer portal user created: {$username} ({$customers_code})", []);
            }
            $_SESSION['flash_portal_users'] = ['type' => 'success', 'msg' => 'User <strong>' . htmlspecialchars($username) . '</strong> berhasil ditambah. Silakan login di Customer Portal dengan username dan password yang Anda masukkan.'];
            rmi_redirect($_SERVER['PHP_SELF']);
        } else {
            $_SESSION['flash_portal_users'] = ['type' => 'danger', 'msg' => implode(' ', $err)];
        }
    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $newpass = (string)($_POST['new_password'] ?? '');
        if ($id > 0 && strlen($newpass) >= 6) {
            $hash = password_hash($newpass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE customer_portal_users SET password_hash = ?, updated_at = NOW() WHERE id = ?")->execute([$hash, $id]);
            $_SESSION['flash_portal_users'] = ['type' => 'success', 'msg' => 'Password berhasil direset. User dapat login dengan password baru di Customer Portal.'];
        } else {
            $_SESSION['flash_portal_users'] = ['type' => 'danger', 'msg' => 'ID invalid atau password minimal 6 karakter.'];
        }
        rmi_redirect($_SERVER['PHP_SELF']);
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $pdo->prepare("SELECT status FROM customer_portal_users WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch();
            if ($row) {
                $newStatus = strtolower((string)$row['status']) === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE customer_portal_users SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
                if (function_exists('master_audit')) {
                    $stU = $pdo->prepare("SELECT username FROM customer_portal_users WHERE id=? LIMIT 1");
                    $stU->execute([$id]);
                    $uname = (string)($stU->fetchColumn() ?: "USER#{$id}");
                    master_audit($pdo, 'customer_portal_users', 'customer_portal_users', 'TOGGLE_STATUS', $id, $uname, "Customer portal status: {$uname} -> {$newStatus}", []);
                }
                $_SESSION['flash_portal_users'] = ['type' => 'success', 'msg' => 'Status diubah ke ' . $newStatus . '.'];
            }
        }
        rmi_redirect($_SERVER['PHP_SELF']);
    }
}

// List users
$users = [];
try {
    $chk = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customer_portal_users' AND column_name='master_mpr_id'");
    $hasMprCol = $chk && (int)$chk->fetchColumn() > 0;
    $users = $pdo->query("
        SELECT u.id, u.username, u.full_name, u.email, u.phone, u.customers_code, u.office_code, u.status, u.last_login_at, u.created_at, c.customers_name"
        . ($hasMprCol ? ", u.master_mpr_id, m.contact_name AS mpr_contact_name" : "") . "
        FROM customer_portal_users u
        LEFT JOIN master_customers c ON c.customers_code = u.customers_code
        " . ($hasMprCol ? "LEFT JOIN master_mpr m ON m.id = u.master_mpr_id" : "") . "
        ORDER BY u.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Customer Portal Users', [
    'active' => 'master',
    'breadcrumbs' => [
        ['label' => 'Master Data', 'url' => $baseProject . '/master/master_data.php'],
        'Customer Portal Users',
    ],
    'actions' => [
        ['label' => 'Master Data', 'url' => $baseProject . '/master/master_data.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Portal Login', 'url' => $baseProject . '/customer_portal/login.php', 'class' => 'btn btn-sm btn-outline-light', 'attrs' => 'target="_blank"'],
    ],
]);

if ($flash): ?>
<div class="alert alert-<?= rmi_h($flash['type']) ?>"><?= rmi_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="rmi-card p-3 mb-4">
    <h5 class="mb-3">Tambah User Portal</h5>
    <?php if ($from_mpr): ?>
        <div class="alert alert-info py-2 mb-3">Buat user portal untuk PIC: <strong><?= rmi_h($from_mpr['contact_name']) ?></strong> (<?= rmi_h($from_mpr['customers_code']) ?>)</div>
    <?php endif; ?>
    <form method="post" class="row g-2 align-items-end" id="form-portal-create">
        <input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="master_mpr_id" id="master_mpr_id" value="<?= rmi_h($form_prefill['master_mpr_id']) ?>">
        <div class="col-md-2"><label class="form-label small">Username *</label><input type="text" name="username" class="form-control" placeholder="Username" required minlength="3" autocomplete="username"></div>
        <div class="col-md-2"><label class="form-label small">Password *</label><input type="password" name="password" class="form-control" placeholder="Password" required minlength="6" autocomplete="new-password"></div>
        <div class="col-md-2"><label class="form-label small">Konfirmasi Password *</label><input type="password" name="password_confirm" class="form-control" placeholder="Ulangi password" required minlength="6" autocomplete="new-password"></div>
        <div class="col-md-2"><label class="form-label small">Nama Lengkap</label><input type="text" name="full_name" id="full_name" class="form-control" placeholder="Nama Lengkap" value="<?= rmi_h($form_prefill['full_name']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">Email</label><input type="email" name="email" id="email" class="form-control" placeholder="Email" value="<?= rmi_h($form_prefill['email']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">Telepon</label><input type="text" name="phone" id="phone" class="form-control" placeholder="Telepon" value="<?= rmi_h($form_prefill['phone']) ?>"></div>
        <div class="col-md-2"><label class="form-label small">Customer *</label>
            <select name="customers_code" id="customers_code" class="form-select" required>
                <option value="">-- Pilih --</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= rmi_h($c['customers_code']) ?>" <?= $form_prefill['customers_code'] === $c['customers_code'] ? 'selected' : '' ?>><?= rmi_h($c['customers_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small">Office</label>
            <select name="office_code" id="office_code" class="form-select">
                <option value="">-- Pilih --</option>
                <?php foreach ($offices as $o): ?>
                    <option value="<?= rmi_h($o['office_code']) ?>" <?= $form_prefill['office_code'] === $o['office_code'] ? 'selected' : '' ?>><?= rmi_h($o['office_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small">Atau dari PIC</label>
            <select id="pic_select" class="form-select">
                <option value="">-- Pilih PIC (auto-fill) --</option>
                <?php foreach ($pics as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-name="<?= rmi_h($p['contact_name']) ?>" data-email="<?= rmi_h($p['email']) ?>" data-phone="<?= rmi_h($p['phone']) ?>" data-cc="<?= rmi_h($p['customers_code']) ?>"><?= rmi_h($p['contact_name']) ?> — <?= rmi_h($p['customers_name'] ?? $p['customers_code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary">Tambah</button></div>
    </form>
</div>
<script>
(function(){
    var pic = document.getElementById('pic_select');
    if (!pic) return;
    pic.addEventListener('change', function(){
        var o = this.options[this.selectedIndex];
        if (!o || o.value === '') return;
        document.getElementById('full_name').value = o.dataset.name || '';
        document.getElementById('email').value = o.dataset.email || '';
        document.getElementById('phone').value = o.dataset.phone || '';
        document.getElementById('customers_code').value = o.dataset.cc || '';
        document.getElementById('master_mpr_id').value = o.value;
    });
})();
</script>

<div class="rmi-card p-3">
    <h5 class="mb-3">Daftar User Portal</h5>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Username</th><th>Nama</th><th>Customer</th><th>Office</th><th>PIC</th><th>Status</th><th>Login Terakhir</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= rmi_h($u['username']) ?></td>
                    <td><?= rmi_h($u['full_name']) ?></td>
                    <td><?= rmi_h($u['customers_name'] ?? $u['customers_code']) ?></td>
                    <td><?= rmi_h($u['office_code']) ?></td>
                    <td><?= isset($u['mpr_contact_name']) && $u['mpr_contact_name'] !== '' ? rmi_h($u['mpr_contact_name']) : '<span class="text-muted">-</span>' ?></td>
                    <td><span class="badge bg-<?= ($u['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= rmi_h($u['status']) ?></span></td>
                    <td><?= rmi_h($u['last_login_at']) ?></td>
                    <td>
                        <form method="post" class="d-inline" onsubmit="return confirm('Reset password?');">
                            <input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <input type="password" name="new_password" placeholder="Password baru" class="form-control form-control-sm d-inline-block" style="width:100px" required minlength="6">
                            <button type="submit" class="btn btn-sm btn-outline-warning">Reset</button>
                        </form>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ($u['status'] ?? '') === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (empty($users)): ?>
        <p class="text-muted mb-0">Belum ada user portal. Tambah user di form atas.</p>
    <?php endif; ?>
</div>

<?php rmi_footer(); ?>
