<?php
declare(strict_types=1);

require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_inc/employee_mutation_helper.php';
require_login();

if (!hrlm_can_view()) {
    http_response_code(403);
    exit('Akses ditolak.');
}
if (!hrlm_table_exists($pdo, 'hrl_employee_mutations')) {
    http_response_code(503);
    exit('Tabel mutasi belum tersedia. Jalankan migration 163_hrl_employee_mutations.sql.');
}

$page_title = 'Mutasi Karyawan';
require_once __DIR__ . '/_layout_top.php';

$err = '';
$ok = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    if (!hrlm_can_manage()) {
        $err = 'Anda tidak memiliki izin untuk mengubah data mutasi.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'create') {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                $toDept = strtoupper(trim((string)($_POST['to_dept_code'] ?? '')));
                $toOffice = strtoupper(trim((string)($_POST['to_office_code'] ?? '')));
                $toPosition = trim((string)($_POST['to_position_name'] ?? ''));
                $toRole = strtoupper(trim((string)($_POST['to_role_code'] ?? '')));
                $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
                $reason = trim((string)($_POST['reason'] ?? ''));
                $submitMode = (string)($_POST['submit_mode'] ?? 'draft');

                if ($employeeId <= 0) throw new RuntimeException('Karyawan wajib dipilih.');
                if ($toDept === '') throw new RuntimeException('Departemen tujuan wajib diisi.');
                if ($effectiveDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
                    throw new RuntimeException('Tanggal efektif tidak valid.');
                }
                if ($reason === '') throw new RuntimeException('Alasan mutasi wajib diisi.');

                $emp = hrlm_employee($pdo, $employeeId);
                if (!$emp) throw new RuntimeException('Karyawan tidak ditemukan.');
                if (hrlm_active_mutation($pdo, $employeeId)) {
                    throw new RuntimeException('Karyawan masih mempunyai mutasi aktif.');
                }

                if ($toDept === (string)$emp['dept_code']
                    && $toOffice === (string)$emp['office_code']
                    && ($toPosition === '' || $toPosition === (string)$emp['position_name'])
                    && ($toRole === '' || $toRole === (string)$emp['role_code'])) {
                    throw new RuntimeException('Tidak ada perubahan penempatan yang diajukan.');
                }

                $status = $submitMode === 'submit' ? 'SUBMITTED' : 'DRAFT';
                $st = $pdo->prepare("INSERT INTO hrl_employee_mutations
                    (employee_id,employee_code,
                     from_dept_code,from_office_code,from_position_name,from_role_code,
                     to_dept_code,to_office_code,to_position_name,to_role_code,
                     effective_date,reason,status,submitted_by,submitted_at,created_by,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,CASE WHEN ?='SUBMITTED' THEN NOW() ELSE NULL END,?,NOW())");
                $st->execute([
                    $employeeId, (string)$emp['employee_code'],
                    (string)$emp['dept_code'], (string)$emp['office_code'],
                    (string)($emp['position_name'] ?? ''), (string)($emp['role_code'] ?? ''),
                    $toDept, $toOffice, $toPosition, $toRole,
                    $effectiveDate, $reason, $status,
                    $status === 'SUBMITTED' ? hrlm_username() : null,
                    $status, hrlm_username()
                ]);
                $id = (int)$pdo->lastInsertId();
                $code = hrlm_generate_code($id);
                $pdo->prepare("UPDATE hrl_employee_mutations SET mutation_code=? WHERE id=?")->execute([$code, $id]);
                hrlm_write_audit($pdo, $status === 'SUBMITTED' ? 'SUBMIT' : 'CREATE_DRAFT', $id, [
                    'mutation_code'=>$code,'employee_id'=>$employeeId,'employee_code'=>$emp['employee_code']
                ]);
                $ok = 'Mutasi berhasil dibuat: ' . $code;
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$employees = [];
try {
    $employees = $pdo->query("SELECT id, employee_code, employee_name,
                              UPPER(COALESCE(dept_code,'')) AS dept_code,
                              UPPER(COALESCE(office_code,'')) AS office_code
                              FROM master_employees
                              WHERE LOWER(COALESCE(status,''))='active'
                              ORDER BY employee_name, employee_code
                              LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$depts = [];
try {
    $depts = $pdo->query("SELECT DISTINCT UPPER(dept_code) FROM master_departements
                          WHERE dept_code IS NOT NULL AND dept_code<>'' ORDER BY dept_code")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
$offices = [];
try {
    if (hrlm_table_exists($pdo, 'master_office')) {
        $offices = $pdo->query("SELECT UPPER(office_code) FROM master_office
                                WHERE COALESCE(is_active,1)=1 ORDER BY office_code")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) {}

$status = strtoupper(trim((string)($_GET['status'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($status !== '' && $status !== 'ALL') { $where[] = "m.status=?"; $params[] = $status; }
if ($q !== '') {
    $where[] = "(m.mutation_code LIKE ? OR m.employee_code LIKE ? OR e.employee_name LIKE ?)";
    $params[] = "%{$q}%"; $params[] = "%{$q}%"; $params[] = "%{$q}%";
}
$sql = "SELECT m.*, e.employee_name
        FROM hrl_employee_mutations m
        LEFT JOIN master_employees e ON e.id=m.employee_id";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY m.id DESC LIMIT 1000";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

function hrlm_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
?>
<style>
.mut-grid{display:grid;grid-template-columns:390px 1fr;gap:18px;align-items:start}
@media(max-width:950px){.mut-grid{grid-template-columns:1fr}}
.mut-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px}
.mut-table{width:100%;border-collapse:collapse;font-size:12px}.mut-table th,.mut-table td{padding:9px;border-bottom:1px solid rgba(255,255,255,.07);vertical-align:top}.mut-table th{text-align:left;color:#94a3b8;font-size:10px;text-transform:uppercase}
.mut-pill{display:inline-flex;padding:2px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.12);font-size:10px;font-weight:700}
</style>

<h2>🔄 Mutasi Karyawan</h2>
<p class="text-muted">Employee code dan user ID tetap. Perubahan master baru dilakukan saat status EFFECTIVE.</p>

<?php if ($err): ?><div class="alert alert-danger"><?= hrlm_h($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= hrlm_h($ok) ?></div><?php endif; ?>

<div class="mut-grid">
  <div class="mut-card">
    <h4>Buat Mutasi</h4>
    <?php if (!hrlm_can_manage()): ?>
      <div class="alert alert-warning">Mode baca saja.</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= hrlm_h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">

      <label class="form-label">Karyawan *</label>
      <select name="employee_id" class="form-select mb-2" required>
        <option value="">Pilih karyawan</option>
        <?php foreach ($employees as $e): ?>
        <option value="<?= (int)$e['id'] ?>">
          <?= hrlm_h($e['employee_name'].' — '.$e['employee_code'].' ['.$e['dept_code'].'/'.$e['office_code'].']') ?>
        </option>
        <?php endforeach; ?>
      </select>

      <div class="row g-2">
        <div class="col-6">
          <label class="form-label">Dept Tujuan *</label>
          <select name="to_dept_code" class="form-select" required>
            <option value="">Pilih</option>
            <?php foreach ($depts as $d): ?><option><?= hrlm_h($d) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label">Office Tujuan</label>
          <select name="to_office_code" class="form-select">
            <option value="">Tetap / kosong</option>
            <?php foreach ($offices as $o): ?><option><?= hrlm_h($o) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <label class="form-label mt-2">Jabatan Baru</label>
      <input name="to_position_name" class="form-control" placeholder="Kosong = tetap">

      <label class="form-label mt-2">Role Baru</label>
      <input name="to_role_code" class="form-control" placeholder="Kosong = tetap">

      <label class="form-label mt-2">Tanggal Efektif *</label>
      <input type="date" name="effective_date" class="form-control" required>

      <label class="form-label mt-2">Alasan *</label>
      <textarea name="reason" class="form-control" rows="3" required></textarea>

      <div class="d-grid gap-2 mt-3">
        <button class="btn btn-ghost" name="submit_mode" value="draft">💾 Simpan Draft</button>
        <button class="btn btn-rmi" name="submit_mode" value="submit">🚀 Submit Mutasi</button>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <div class="mut-card">
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
      <h4 style="margin:0">Daftar Mutasi</h4>
      <form method="get" style="display:flex;gap:6px">
        <select name="status" class="form-select form-select-sm">
          <option value="ALL">Semua status</option>
          <?php foreach (['DRAFT','SUBMITTED','APPROVED','SCHEDULED','EFFECTIVE','REJECTED','CANCELLED'] as $s): ?>
            <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <input name="q" class="form-control form-control-sm" value="<?= hrlm_h($q) ?>" placeholder="Cari">
        <button class="btn btn-sm btn-soft">Filter</button>
      </form>
    </div>

    <div style="overflow-x:auto;margin-top:10px">
      <table class="mut-table">
        <thead><tr><th>Code</th><th>Karyawan</th><th>Dari → Ke</th><th>Efektif</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= hrlm_h($r['mutation_code'] ?: '#'.$r['id']) ?></td>
            <td><b><?= hrlm_h($r['employee_name'] ?: $r['employee_code']) ?></b><br><span class="text-muted"><?= hrlm_h($r['employee_code']) ?></span></td>
            <td><?= hrlm_h($r['from_dept_code'].'/'.$r['from_office_code']) ?><br>→ <b><?= hrlm_h($r['to_dept_code'].'/'.$r['to_office_code']) ?></b></td>
            <td><?= hrlm_h($r['effective_date']) ?></td>
            <td><span class="mut-pill"><?= hrlm_h($r['status']) ?></span></td>
            <td><a class="btn btn-sm btn-soft" href="employee_mutation_view.php?id=<?= (int)$r['id'] ?>">Buka</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-muted">Belum ada mutasi.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
