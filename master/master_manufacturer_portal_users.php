<?php
/**
 * master/master_manufacturer_portal_users.php
 * Kelola user Manufacturer Portal (pabrikan Reg Alkes).
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_audit_master.php';
require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    }
}

if (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.MANUFACTURE_VIEW', 'MASTER.MANUFACTURE_CREATE', 'MASTER.MANUFACTURE_EDIT', 'MASTER.VIEW', 'PQP.VIEW']);
} else {
    require_role(['SYS', 'SUPERADMIN', 'ADMIN', 'PQP']);
}

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();

$flash = null;
if (!empty($_SESSION['flash_mportal_users'])) {
    $flash = $_SESSION['flash_mportal_users'];
    unset($_SESSION['flash_mportal_users']);
}

$manufactures = [];
try {
    $manufactures = $pdo->query("
        SELECT manufacture_code, manufacture_name
        FROM master_manufactures
        WHERE (deleted_at IS NULL OR deleted_at = '')
        ORDER BY manufacture_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$from_manufacture = trim((string)($_GET['from_manufacture'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password_confirm = (string)($_POST['password_confirm'] ?? '');
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $manufacture_code = trim((string)($_POST['manufacture_code'] ?? ''));

        $err = [];
        if ($username === '') $err[] = 'Username wajib.';
        if (strlen($username) < 3) $err[] = 'Username minimal 3 karakter.';
        if ($password === '') $err[] = 'Password wajib.';
        if (strlen($password) < 6) $err[] = 'Password minimal 6 karakter.';
        if ($password !== $password_confirm) $err[] = 'Password dan konfirmasi tidak sama.';
        if ($manufacture_code === '') $err[] = 'Manufacture wajib dipilih.';

        if (empty($err)) {
            try {
                $st = $pdo->prepare("SELECT 1 FROM manufacturer_portal_users WHERE username = ?");
                $st->execute([$username]);
                if ($st->fetch()) {
                    $err[] = 'Username sudah dipakai.';
                }
            } catch (Throwable $e) {
                $err[] = 'Tabel manufacturer_portal_users belum ada. Jalankan migration 140.';
            }
        }

        if (empty($err)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $manu_id = null;
            foreach ($manufactures as $m) {
                if (($m['manufacture_code'] ?? '') === $manufacture_code) {
                    $st = $pdo->prepare("SELECT id FROM master_manufactures WHERE manufacture_code = ? LIMIT 1");
                    $st->execute([$manufacture_code]);
                    $r = $st->fetch();
                    if ($r) $manu_id = (int)$r['id'];
                    break;
                }
            }
            try {
                $pdo->prepare("INSERT INTO manufacturer_portal_users (username, password_hash, full_name, email, phone, manufacture_code, manufacture_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')")
                    ->execute([$username, $hash, $full_name ?: null, $email ?: null, $phone ?: null, $manufacture_code, $manu_id]);
                $newId = (int)$pdo->lastInsertId();
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'manufacturer_portal_users', 'manufacturer_portal_users', 'CREATE', $newId, $username, "Manufacturer portal user created: {$username} ({$manufacture_code})", []);
                }
                $_SESSION['flash_mportal_users'] = ['type' => 'success', 'msg' => 'User portal berhasil ditambah.'];
            } catch (Throwable $e) {
                $_SESSION['flash_mportal_users'] = ['type' => 'danger', 'msg' => 'Gagal: ' . $e->getMessage()];
            }
            rmi_redirect($_SERVER['PHP_SELF']);
        } else {
            $_SESSION['flash_mportal_users'] = ['type' => 'danger', 'msg' => implode(' ', $err)];
        }
    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $newpass = (string)($_POST['new_password'] ?? '');
        if ($id > 0 && strlen($newpass) >= 6) {
            $hash = password_hash($newpass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE manufacturer_portal_users SET password_hash = ?, updated_at = NOW() WHERE id = ?")->execute([$hash, $id]);
            $_SESSION['flash_mportal_users'] = ['type' => 'success', 'msg' => 'Password berhasil direset.'];
        } else {
            $_SESSION['flash_mportal_users'] = ['type' => 'danger', 'msg' => 'ID invalid atau password minimal 6 karakter.'];
        }
        rmi_redirect($_SERVER['PHP_SELF']);
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $pdo->prepare("SELECT status FROM manufacturer_portal_users WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch();
            if ($row) {
                $newStatus = strtolower((string)$row['status']) === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE manufacturer_portal_users SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
                if (function_exists('master_audit')) {
                    $stU = $pdo->prepare("SELECT username FROM manufacturer_portal_users WHERE id=? LIMIT 1");
                    $stU->execute([$id]);
                    $uname = (string)($stU->fetchColumn() ?: "USER#{$id}");
                    master_audit($pdo, 'manufacturer_portal_users', 'manufacturer_portal_users', 'TOGGLE_STATUS', $id, $uname, "Manufacturer portal status: {$uname} -> {$newStatus}", []);
                }
                $_SESSION['flash_mportal_users'] = ['type' => 'success', 'msg' => 'Status diubah ke ' . $newStatus . '.'];
            }
        }
        rmi_redirect($_SERVER['PHP_SELF']);
    }
}

$users = [];
try {
    $users = $pdo->query("
        SELECT u.id, u.username, u.full_name, u.email, u.phone, u.manufacture_code, u.status, u.last_login_at, u.created_at, m.manufacture_name
        FROM manufacturer_portal_users u
        LEFT JOIN master_manufactures m ON m.manufacture_code = u.manufacture_code AND (m.deleted_at IS NULL OR m.deleted_at = '')
        ORDER BY u.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Manufacturer Portal Users', [
    'active' => 'master',
    'breadcrumbs' => [
        ['label' => 'Master Data', 'url' => $baseProject . '/master/master_data.php'],
        'Manufacturer Portal Users',
    ],
    'actions' => [
        ['label' => 'Master Data', 'url' => $baseProject . '/master/master_data.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Portal Login', 'url' => $baseProject . '/manufacturer_portal/login.php', 'class' => 'btn btn-sm btn-outline-light', 'attrs' => 'target="_blank"'],
    ],
]);

if ($flash): ?>
<div class="alert alert-<?= rmi_h($flash['type']) ?>"><?= rmi_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="rmi-card p-3 mb-4">
    <h5 class="mb-3">Tambah User Portal Pabrikan</h5>
    <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= rmi_h(function_exists('csrf_token') ? csrf_token() : '') ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-md-2"><label class="form-label small">Username *</label><input type="text" name="username" class="form-control" required minlength="3" autocomplete="username"></div>
        <div class="col-md-2"><label class="form-label small">Password *</label><input type="password" name="password" class="form-control" required minlength="6" autocomplete="new-password"></div>
        <div class="col-md-2"><label class="form-label small">Konfirmasi Password *</label><input type="password" name="password_confirm" class="form-control" required minlength="6" autocomplete="new-password"></div>
        <div class="col-md-2"><label class="form-label small">Nama Lengkap</label><input type="text" name="full_name" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small">Email</label><input type="email" name="email" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small">Telepon</label><input type="text" name="phone" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small">Manufacture *</label>
            <select name="manufacture_code" class="form-select" required>
                <option value="">-- Pilih --</option>
                <?php foreach ($manufactures as $m): ?>
                    <option value="<?= rmi_h($m['manufacture_code']) ?>" <?= $from_manufacture === ($m['manufacture_code'] ?? '') ? 'selected' : '' ?>><?= rmi_h($m['manufacture_name']) ?> (<?= rmi_h($m['manufacture_code']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary">Tambah</button></div>
    </form>
</div>

<div class="rmi-card p-3">
    <h5 class="mb-3">Daftar User Portal</h5>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Username</th><th>Nama</th><th>Manufacture</th><th>Status</th><th>Login Terakhir</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= rmi_h($u['username']) ?></td>
                    <td><?= rmi_h($u['full_name']) ?></td>
                    <td><?= rmi_h($u['manufacture_name'] ?? $u['manufacture_code']) ?></td>
                    <td><span class="badge bg-<?= ($u['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= rmi_h($u['status']) ?></span></td>
                    <td><?= rmi_h($u['last_login_at']) ?></td>
                    <td>
                        <form method="post" class="d-inline" onsubmit="return confirm('Reset password?');">
                            <input type="hidden" name="csrf_token" value="<?= rmi_h(function_exists('csrf_token') ? csrf_token() : '') ?>">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <input type="password" name="new_password" placeholder="Password baru (min 6)" class="form-control form-control-sm d-inline-block" style="width:120px" required minlength="6" autocomplete="new-password">
                            <button type="submit" class="btn btn-sm btn-outline-warning">Reset</button>
                        </form>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= rmi_h(function_exists('csrf_token') ? csrf_token() : '') ?>">
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
