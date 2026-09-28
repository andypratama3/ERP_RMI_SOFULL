<?php
/**
 * dashboards/hrl/absensi_manual_alpha.php
 * Koreksi absensi manual HRL:
 * - ALPA resmi
 * - HADIR_MANUAL / SERVER_DOWN agar tidak dianggap alpa saat server error
 * Tidak mengubah absensi_logs asli.
 */
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();
$pdo = $GLOBALS['pdo'] ?? null;
$bp  = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
function hrl_manual_url(string $path): string {
    $bp = $GLOBALS['BASE_PROJECT'] ?? '';
    return rtrim($bp, '/') . '/' . ltrim($path, '/');
}
function hrl_manual_table_exists(PDO $pdo, string $t): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute([$t]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function hrl_manual_col_exists(PDO $pdo, string $t, string $c): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function hrl_manual_me_username(): string {
    foreach (['username','user_name','name'] as $k) {
        if (!empty($_SESSION[$k])) return (string)$_SESSION[$k];
        if (!empty($_SESSION['user'][$k])) return (string)$_SESSION['user'][$k];
    }
    return 'SYSTEM';
}
function hrl_manual_me_dept(): string {
    foreach (['dept_code','department','dept'] as $k) {
        if (!empty($_SESSION[$k])) return strtoupper((string)$_SESSION[$k]);
        if (!empty($_SESSION['user'][$k])) return strtoupper((string)$_SESSION['user'][$k]);
    }
    return '';
}
function hrl_manual_me_level(): string {
    foreach (['level','role','role_code'] as $k) {
        if (!empty($_SESSION[$k])) return strtoupper((string)$_SESSION[$k]);
        if (!empty($_SESSION['user'][$k])) return strtoupper((string)$_SESSION['user'][$k]);
    }
    return '';
}

$dept = function_exists('auth_dept') ? auth_dept() : hrl_manual_me_dept();
$level = function_exists('auth_level') ? strtoupper((string)auth_level()) : hrl_manual_me_level();

/*
 * Akses halaman:
 * - HRL: STAFF atau MANAGER
 * - SYS/OWNER: tetap mendapat akses
 * Gate RBAC global, permission, dan CSRF tetap dijalankan oleh require_login().
 */
$isHrlUser = ($dept === 'HRL' && in_array($level, ['STAFF','MANAGER','MGR'], true));
$isPrivileged = in_array($dept, ['SYS'], true) || in_array($level, ['SYS','OWNER'], true);

if (!$isHrlUser && !$isPrivileged) {
    http_response_code(403);
    exit('Forbidden: hanya HRL STAFF/MANAGER atau SYS yang boleh input koreksi absensi manual.');
}

if (!$pdo instanceof PDO) {
    exit('Database connection not ready.');
}

$messages = [];
$errors = [];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS absensi_manual_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            employee_id INT NULL,
            employee_code VARCHAR(50) NULL,
            employee_name VARCHAR(150) NULL,
            user_id INT NULL,
            username VARCHAR(100) NULL,
            office_code VARCHAR(30) NULL,
            status ENUM('ALPA','HADIR_MANUAL','SERVER_DOWN','IZIN','SAKIT','CUTI') NOT NULL DEFAULT 'ALPA',
            reason TEXT NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'HRL_MANUAL',
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) NULL,
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_manual_attendance (tanggal, employee_code, status, deleted_at),
            KEY idx_tanggal_status (tanggal, status),
            KEY idx_employee_code (employee_code),
            KEY idx_username (username),
            KEY idx_office_code (office_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    $errors[] = 'Gagal memastikan tabel: ' . $e->getMessage();
}

$filter_date = trim((string)($_GET['d'] ?? $_POST['tanggal'] ?? ''));
if ($filter_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $filter_date = date('Y-m-d');
}
$filter_office = strtoupper(trim((string)($_GET['office'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (function_exists('verify_csrf')) verify_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID tidak valid.');
            $st = $pdo->prepare("UPDATE absensi_manual_attendance SET deleted_by=?, deleted_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL");
            $st->execute([hrl_manual_me_username(), hrl_manual_me_username(), $id]);
            $messages[] = 'Data koreksi berhasil dihapus.';
        }

        if ($action === 'save') {
            $tanggal = trim((string)($_POST['tanggal'] ?? ''));
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $status = strtoupper(trim((string)($_POST['status'] ?? 'ALPA')));
            $reason = trim((string)($_POST['reason'] ?? ''));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) throw new RuntimeException('Tanggal tidak valid.');
            if ($employeeId <= 0) throw new RuntimeException('Karyawan wajib dipilih.');
            if (!in_array($status, ['ALPA','HADIR_MANUAL','SERVER_DOWN','IZIN','SAKIT','CUTI'], true)) throw new RuntimeException('Status tidak valid.');
            if ($reason === '') throw new RuntimeException('Alasan wajib diisi.');

            $emp = null;
            if (hrl_manual_table_exists($pdo, 'master_employees')) {
                $st = $pdo->prepare("SELECT * FROM master_employees WHERE id=? LIMIT 1");
                $st->execute([$employeeId]);
                $emp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$emp) throw new RuntimeException('Data karyawan tidak ditemukan.');

            $employeeCode = (string)($emp['employee_code'] ?? $emp['code'] ?? '');
            $employeeName = (string)($emp['employee_name'] ?? $emp['name'] ?? '');
            $officeCode = strtoupper((string)($emp['office_code'] ?? ''));

            $userId = null;
            $username = null;
            if ($employeeCode !== '' && hrl_manual_table_exists($pdo, 'master_system_login')) {
                $loginCodeCol = hrl_manual_col_exists($pdo, 'master_system_login', 'holder_employee_code') ? 'holder_employee_code' : '';
                if ($loginCodeCol !== '') {
                    $st = $pdo->prepare("SELECT id, username FROM master_system_login WHERE UPPER(TRIM({$loginCodeCol}))=UPPER(TRIM(?)) AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
                    $st->execute([$employeeCode]);
                    $u = $st->fetch(PDO::FETCH_ASSOC);
                    if ($u) {
                        $userId = (int)$u['id'];
                        $username = (string)$u['username'];
                    }
                }
            }

            // ALPA tidak boleh dibuat bila pada tanggal yang sama sudah ada check-in normal.
            if ($status === 'ALPA' && $userId && hrl_manual_table_exists($pdo, 'absensi_logs')) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM absensi_logs
                    WHERE user_id=? AND deleted_at IS NULL
                      AND UPPER(TRIM(action_type))='IN'
                      AND DATE(created_at)=?");
                $st->execute([$userId, $tanggal]);
                if ((int)$st->fetchColumn() > 0) {
                    throw new RuntimeException('Tidak dapat menetapkan ALPA karena karyawan memiliki check-in normal pada tanggal tersebut.');
                }
            }

            $actor = hrl_manual_me_username();
            $pdo->beginTransaction();
            try {
                // Satu karyawan hanya boleh memiliki satu koreksi aktif per tanggal.
                // Status lama diperbarui, bukan membuat baris aktif baru yang saling bertentangan.
                $st = $pdo->prepare("SELECT id FROM absensi_manual_attendance
                    WHERE tanggal=? AND employee_code=? AND deleted_at IS NULL
                    ORDER BY id DESC");
                $st->execute([$tanggal, $employeeCode]);
                $activeIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

                if ($activeIds) {
                    $keepId = array_shift($activeIds);
                    $st = $pdo->prepare("UPDATE absensi_manual_attendance SET
                        employee_id=?, employee_name=?, user_id=?, username=?, office_code=?,
                        status=?, reason=?, source='HRL_MANUAL', updated_by=?, updated_at=NOW(),
                        deleted_by=NULL, deleted_at=NULL
                        WHERE id=?");
                    $st->execute([$employeeId, $employeeName, $userId, $username, $officeCode, $status, $reason, $actor, $keepId]);

                    if ($activeIds) {
                        $ph = implode(',', array_fill(0, count($activeIds), '?'));
                        $params = array_merge([$actor, $actor], $activeIds);
                        $pdo->prepare("UPDATE absensi_manual_attendance
                            SET deleted_by=?, deleted_at=NOW(), updated_by=?, updated_at=NOW()
                            WHERE id IN ({$ph})")->execute($params);
                    }
                    $messages[] = 'Koreksi absensi berhasil diperbarui.';
                } else {
                    $st = $pdo->prepare("INSERT INTO absensi_manual_attendance
                        (tanggal, employee_id, employee_code, employee_name, user_id, username, office_code, status, reason, source, created_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,'HRL_MANUAL',?,NOW())");
                    $st->execute([$tanggal, $employeeId, $employeeCode, $employeeName, $userId, $username, $officeCode, $status, $reason, $actor]);
                    $messages[] = 'Koreksi absensi berhasil disimpan.';
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $filter_date = $tanggal;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$employees = [];
try {
    if (hrl_manual_table_exists($pdo, 'master_employees')) {
        $where = "WHERE LOWER(COALESCE(status,''))='active'";
        $params = [];
        if ($filter_office !== '') {
            $where .= " AND UPPER(COALESCE(office_code,''))=?";
            $params[] = $filter_office;
        }
        $sql = "SELECT id, employee_code, employee_name, dept_code, office_code
                FROM master_employees {$where}
                ORDER BY office_code, employee_name";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $employees = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

$rows = [];
try {
    $where = "WHERE deleted_at IS NULL AND tanggal=?";
    $params = [$filter_date];
    if ($filter_office !== '') {
        $where .= " AND UPPER(COALESCE(office_code,''))=?";
        $params[] = $filter_office;
    }
    $st = $pdo->prepare("SELECT * FROM absensi_manual_attendance {$where} ORDER BY office_code, employee_name, id DESC");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Gagal membaca data: ' . $e->getMessage();
}

$csrf = function_exists('csrf_field') ? csrf_field() : '';

require_once __DIR__ . '/../../_shared/rmi_layout.php';
rmi_header('Koreksi Absensi Manual HRL', [
    'active' => 'hrl',
    'subtitle' => 'ALPA resmi, hadir manual, dan server down tanpa merusak absensi asli',
    'actions' => [
        ['label' => '← HRL Dashboard', 'url' => hrl_manual_url('/dashboards/hrl/hrl_dashboard.php')],
        ['label' => 'Rekap Absensi', 'url' => hrl_manual_url('/absensi/admin/rekap.php')],
    ],
]);
?>
<style>
.manual-grid{display:grid;grid-template-columns:minmax(280px,420px) 1fr;gap:16px}
.manual-card{background:#141f36;border:1px solid rgba(255,255,255,.09);border-radius:14px;padding:16px}
.manual-card label{font-size:11px;font-weight:800;color:#93a4bd;text-transform:uppercase;margin-bottom:5px;display:block}
.manual-card input,.manual-card select,.manual-card textarea{width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:9px;color:#e8ecf4;padding:9px}
.manual-card textarea{min-height:90px}
.manual-alert{border-radius:10px;padding:10px 12px;margin-bottom:10px;font-size:13px}
.ok{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.25);color:#86efac}
.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.25);color:#fca5a5}
.muted{color:#93a4bd;font-size:12px}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th,.tbl td{border-bottom:1px solid rgba(255,255,255,.08);padding:8px;text-align:left}
.tbl th{font-size:11px;color:#93a4bd;text-transform:uppercase}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);font-size:11px;font-weight:800}
@media(max-width:900px){.manual-grid{grid-template-columns:1fr}}
</style>

<?php foreach ($messages as $m): ?><div class="manual-alert ok"><?= h($m) ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="manual-alert err"><?= h($e) ?></div><?php endforeach; ?>

<div class="manual-grid">
  <div class="manual-card">
    <h3 style="margin-top:0">Input Koreksi</h3>
    <p class="muted">Gunakan <b>ALPA</b> hanya jika HRL sudah memastikan karyawan tidak hadir. Untuk server mati/error gunakan <b>SERVER_DOWN</b> atau <b>HADIR_MANUAL</b>, supaya tidak dianggap alpa.</p>
    <form method="post">
      <?= $csrf ?>
      <input type="hidden" name="action" value="save">
      <div style="margin-bottom:10px">
        <label>Tanggal</label>
        <input type="date" name="tanggal" value="<?= h($filter_date) ?>" required>
      </div>
      <div style="margin-bottom:10px">
        <label>Karyawan</label>
        <select name="employee_id" required>
          <option value="">-- pilih karyawan --</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= (int)$e['id'] ?>">
              <?= h(($e['office_code'] ?? '') . ' - ' . ($e['employee_code'] ?? '') . ' - ' . ($e['employee_name'] ?? '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:10px">
        <label>Status</label>
        <select name="status" required>
          <option value="ALPA">ALPA resmi</option>
          <option value="HADIR_MANUAL">HADIR MANUAL</option>
          <option value="SERVER_DOWN">SERVER DOWN / ERROR SISTEM</option>
          <option value="IZIN">IZIN</option>
          <option value="SAKIT">SAKIT</option>
          <option value="CUTI">CUTI</option>
        </select>
      </div>
      <div style="margin-bottom:12px">
        <label>Alasan / Catatan</label>
        <textarea name="reason" placeholder="Contoh: Server absensi mati pukul 07:00-09:00 / karyawan tidak hadir tanpa keterangan" required></textarea>
      </div>
      <button class="btn btn-rmi" type="submit">Simpan Koreksi</button>
    </form>
  </div>

  <div class="manual-card">
    <form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:12px">
      <div>
        <label>Tanggal</label>
        <input type="date" name="d" value="<?= h($filter_date) ?>">
      </div>
      <div>
        <label>Office</label>
        <input type="text" name="office" value="<?= h($filter_office) ?>" placeholder="BGR / BDG / SLO">
      </div>
      <button class="btn btn-ghost" type="submit">Filter</button>
    </form>

    <h3>Data Koreksi Tanggal <?= h($filter_date) ?></h3>
    <?php if (!$rows): ?>
      <p class="muted">Belum ada koreksi manual pada tanggal ini.</p>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table class="tbl">
          <thead><tr><th>Karyawan</th><th>Office</th><th>Status</th><th>Alasan</th><th>Dibuat</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <b><?= h($r['employee_name'] ?? '—') ?></b><br>
                <span class="muted"><?= h($r['employee_code'] ?? '') ?> <?= h($r['username'] ?? '') ?></span>
              </td>
              <td><?= h($r['office_code'] ?? '') ?></td>
              <td><span class="badge"><?= h($r['status'] ?? '') ?></span></td>
              <td><?= h($r['reason'] ?? '') ?></td>
              <td class="muted"><?= h($r['created_by'] ?? '') ?><br><?= h($r['created_at'] ?? '') ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Hapus koreksi ini?')">
                  <?= $csrf ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="manual-card" style="margin-top:16px">
  <h3>Alur aman payroll</h3>
  <div class="muted" style="line-height:1.7">
    1. Tidak check-in karena server mati/error <b>tidak otomatis ALPA</b>.<br>
    2. HRL input <b>SERVER_DOWN</b> atau <b>HADIR_MANUAL</b> sebagai bukti koreksi.<br>
    3. Jika karyawan benar-benar tidak hadir tanpa keterangan, HRL input <b>ALPA resmi</b>.<br>
    4. Payroll hanya boleh memakai data ALPA yang sudah diinput/ditetapkan HRL, bukan hasil tebakan dari absensi kosong.
  </div>
</div>

<?php rmi_footer(); ?>
