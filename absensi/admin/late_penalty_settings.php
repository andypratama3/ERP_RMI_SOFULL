<?php
require_once __DIR__ . '/../../_shared/assets.php';
require_once __DIR__ . '/../_inc/bootstrap.php';

if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.RECAP');

require_once __DIR__ . '/../_layout_top.php';

if (!absensi_is_hr_admin($pdo, $ABS_USER)) {
    echo '<div class="alert alert-danger">Akses ditolak — hanya HRL / SYS.</div>';
    require_once __DIR__ . '/../_layout_bottom.php';
    exit;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$messages = [];
$errors = [];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS absensi_late_penalty_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            minute_from INT NOT NULL,
            minute_to INT NOT NULL,
            penalty_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            description VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_range (minute_from, minute_to)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM absensi_late_penalty_rules")->fetchColumn();
    if ($cnt === 0) {
        $st = $pdo->prepare("INSERT INTO absensi_late_penalty_rules (minute_from, minute_to, penalty_amount, description, is_active) VALUES (?,?,?,?,1)");
        $st->execute([5, 19, 5000, 'Terlambat 5-19 menit']);
        $st->execute([20, 29, 10000, 'Terlambat 20-29 menit']);
        $st->execute([30, 9999, 20000, 'Terlambat lebih dari 30 menit']);
    }
} catch (Throwable $e) { $errors[] = 'Gagal memastikan tabel: ' . $e->getMessage(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (function_exists('verify_csrf')) verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_all') {
            $ids = $_POST['id'] ?? [];
            foreach ($ids as $i => $id) {
                $id = (int)$id;
                $min = max(0, (int)($_POST['minute_from'][$i] ?? 0));
                $max = max($min, (int)($_POST['minute_to'][$i] ?? 0));
                $amt = max(0, (float)($_POST['penalty_amount'][$i] ?? 0));
                $desc = trim((string)($_POST['description'][$i] ?? ''));
                $active = isset($_POST['is_active'][$i]) ? 1 : 0;
                $st = $pdo->prepare("UPDATE absensi_late_penalty_rules SET minute_from=?, minute_to=?, penalty_amount=?, description=?, is_active=?, updated_at=NOW() WHERE id=?");
                $st->execute([$min, $max, $amt, $desc, $active, $id]);
            }
            $messages[] = 'Aturan potongan berhasil disimpan.';
        }
        if ($action === 'add') {
            $min = max(0, (int)($_POST['new_minute_from'] ?? 0));
            $max = max($min, (int)($_POST['new_minute_to'] ?? 0));
            $amt = max(0, (float)($_POST['new_penalty_amount'] ?? 0));
            $desc = trim((string)($_POST['new_description'] ?? ''));
            $st = $pdo->prepare("INSERT INTO absensi_late_penalty_rules (minute_from, minute_to, penalty_amount, description, is_active, created_at) VALUES (?,?,?,?,1,NOW())");
            $st->execute([$min, $max, $amt, $desc]);
            $messages[] = 'Aturan baru ditambahkan.';
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $st = $pdo->prepare("DELETE FROM absensi_late_penalty_rules WHERE id=?");
                $st->execute([$id]);
                $messages[] = 'Aturan dihapus.';
            }
        }
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

$rules = $pdo->query("SELECT * FROM absensi_late_penalty_rules ORDER BY minute_from ASC")->fetchAll(PDO::FETCH_ASSOC);
$csrf = function_exists('csrf_field') ? csrf_field() : '';
?>
<style>
.lp-card{background:#131d2e;border:1px solid rgba(255,255,255,.09);border-radius:14px;padding:16px;margin-bottom:14px}
.lp-table{width:100%;border-collapse:collapse}
.lp-table th,.lp-table td{border-bottom:1px solid rgba(255,255,255,.08);padding:8px;text-align:left}
.lp-table th{font-size:11px;color:#94a3b8;text-transform:uppercase}
.lp-table input{width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:8px;color:#e8ecf4;padding:7px}
.lp-alert{border-radius:10px;padding:10px 12px;margin-bottom:10px;font-size:13px}
.ok{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.25);color:#86efac}
.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.25);color:#fca5a5}
.muted{font-size:12px;color:#94a3b8}
</style>
<div class="lp-card">
  <h2 style="margin-top:0">Setting Potongan Keterlambatan</h2>
  <div class="muted">Aturan default: 5-19 menit Rp 5.000, 20-29 menit Rp 10.000, 30 menit ke atas Rp 20.000.</div>
</div>
<?php foreach ($messages as $m): ?><div class="lp-alert ok"><?= h($m) ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="lp-alert err"><?= h($e) ?></div><?php endforeach; ?>
<div class="lp-card">
  <form method="post">
    <?= $csrf ?><input type="hidden" name="action" value="save_all">
    <div style="overflow-x:auto">
      <table class="lp-table">
        <thead><tr><th>Dari Menit</th><th>Sampai Menit</th><th>Potongan</th><th>Keterangan</th><th>Aktif</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rules as $i => $r): ?>
          <tr>
            <td><input type="hidden" name="id[]" value="<?= (int)$r['id'] ?>"><input type="number" name="minute_from[]" value="<?= (int)$r['minute_from'] ?>" min="0"></td>
            <td><input type="number" name="minute_to[]" value="<?= (int)$r['minute_to'] ?>" min="0"></td>
            <td><input type="number" name="penalty_amount[]" value="<?= (float)$r['penalty_amount'] ?>" min="0"></td>
            <td><input type="text" name="description[]" value="<?= h($r['description'] ?? '') ?>"></td>
            <td style="text-align:center"><input type="checkbox" name="is_active[<?= $i ?>]" <?= (int)$r['is_active']===1?'checked':'' ?>></td>
            <td><button type="submit" form="deleteRule<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus aturan ini?')">Hapus</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px">
      <button class="btn btn-rmi" type="submit">Simpan Semua</button>
      <a class="btn btn-ghost" href="rekap.php">Kembali ke Rekap</a>
    </div>
  </form>
  <?php foreach ($rules as $r): ?>
    <form id="deleteRule<?= (int)$r['id'] ?>" method="post" style="display:none">
      <?= $csrf ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    </form>
  <?php endforeach; ?>
</div>
<div class="lp-card">
  <h3>Tambah Aturan Baru</h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;align-items:end">
    <?= $csrf ?><input type="hidden" name="action" value="add">
    <div><label class="muted">Dari menit</label><input type="number" name="new_minute_from" min="0" required></div>
    <div><label class="muted">Sampai menit</label><input type="number" name="new_minute_to" min="0" required></div>
    <div><label class="muted">Potongan</label><input type="number" name="new_penalty_amount" min="0" required></div>
    <div><label class="muted">Keterangan</label><input type="text" name="new_description" placeholder="Contoh: Telat 45-60 menit"></div>
    <button class="btn btn-rmi" type="submit">Tambah</button>
  </form>
</div>
<div class="lp-card">
  <h3>Alur yang disarankan</h3>
  <div class="muted" style="line-height:1.7">
    1. Jam masuk dan toleransi tetap diatur dari menu Jam Kerja/Shift.<br>
    2. Potongan dihitung dari menit keterlambatan setelah batas efektif.<br>
    3. Rekap dan CSV menampilkan nominal potongan.<br>
    4. Untuk payroll, sinkronkan dari rekap resmi agar tidak input manual bebas.
  </div>
</div>
<?php require_once __DIR__ . '/../_layout_bottom.php'; ?>
