<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/../../master/_audit_master.php';

tools_require_access('ops/reset_for_golive_ui.php');
require_login();

// SYS ONLY — tidak ada pengecualian
if (function_exists('require_any_permission')) {
    require_once __DIR__ . '/../../_shared/rbac.php';
    require_any_permission(['SYSTEM.USER_MANAGE']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

$root    = defined('APP_ROOT') ? APP_ROOT : (realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
$logsDir = ts_storage_logs_dir();
$flash   = ''; $flashType = 'info'; $result = [];

// ── POST: Execute reset ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));

    $mode        = trim((string)($_POST['mode']         ?? ''));
    $newPass     = trim((string)($_POST['new_password'] ?? ''));
    $confirmPass = trim((string)($_POST['confirm_password'] ?? ''));
    $understood  = (string)($_POST['understood'] ?? '') === '1';
    $dryRun      = (string)($_POST['dry_run']    ?? '') === '1';
    $skipBackup  = (string)($_POST['skip_backup']?? '') === '1';

    // Validasi
    $errors = [];
    if (!in_array($mode, ['full','transactions','testdata'], true)) {
        $errors[] = 'Pilih mode reset yang valid.';
    }
    if (!$understood) {
        $errors[] = 'Centang konfirmasi "Saya memahami bahwa aksi ini tidak bisa di-undo".';
    }
    if (strlen($newPass) < 10) {
        $errors[] = 'Password admin baru minimal 10 karakter.';
    }
    if ($newPass !== $confirmPass) {
        $errors[] = 'Password baru dan konfirmasi tidak cocok.';
    }

    if ($errors) {
        $flash = implode('<br>', $errors);
        $flashType = 'danger';
    } else {
        // Build CLI command and run inline
        $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (PHP_BINARY ?: 'php');
        $resetScript = $root . '/tools/ops/reset_for_golive.php';

        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($resetScript)
             . ' --mode=' . escapeshellarg($mode)
             . ' --new-admin-password=' . escapeshellarg($newPass)
             . ' --i-understand-this-is-irreversible'
             . ($dryRun     ? ' --dry-run'      : '')
             . ($skipBackup ? ' --skip-backup'  : '')
             . ' 2>&1';

        $t0 = microtime(true);
        $lines = []; $code = 1;
        @exec($cmd, $lines, $code);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $output = implode("\n", $lines);
        $ok = ((int)$code === 0);

        // Log aksi
        if (function_exists('master_audit')) {
            master_audit($GLOBALS['pdo'] ?? null, 'tools', 'reset_for_golive', 'RESET',
                null, "mode={$mode},dry_run=" . ($dryRun?'1':'0'),
                'Reset for go-live executed', ['mode'=>$mode,'dry_run'=>$dryRun,'ok'=>$ok]);
        }

        $result = [
            'ok'     => $ok,
            'mode'   => $mode,
            'dry'    => $dryRun,
            'output' => $output,
            'ms'     => $ms,
            'code'   => $code,
        ];

        $flash = $ok
            ? ($dryRun ? '✅ Dry-run selesai. Lihat output di bawah.' : '✅ Reset berhasil! Lihat output di bawah.')
            : '❌ Reset gagal dengan kode ' . $code . '. Lihat output di bawah.';
        $flashType = $ok ? 'success' : 'danger';
    }
}

// ── Read last reset artifact ──────────────────────────────────────────────────
$lastReset = [];
$lastResetPath = $logsDir . '/reset_for_golive_last.json';
if (is_file($lastResetPath)) {
    $d = @json_decode((string)@file_get_contents($lastResetPath), true);
    if (is_array($d)) $lastReset = $d;
}

$baseProject = rmi_layout_base_project();
rmi_header('Reset for Go-Live', [
    'active'      => 'tools',
    'subtitle'    => 'Reset data ERP sebelum mulai produksi — SYS only',
    'breadcrumbs' => [
        ['label'=>'Tools','url'=>$baseProject.'/tools/index.php'],
        ['label'=>'Ops','url'=>$baseProject.'/tools/ops/'],
        'Reset for Go-Live',
    ],
]);
?>

<style>
.rgl-warning{background:rgba(239,68,68,.1);border:2px solid rgba(239,68,68,.4);border-radius:12px;padding:16px 20px;margin-bottom:16px}
.rgl-warning-title{font-size:16px;font-weight:800;color:#f87171;margin-bottom:6px}
.rgl-mode-card{border:2px solid rgba(255,255,255,.08);border-radius:12px;padding:16px;cursor:pointer;transition:all .2s;background:rgba(17,24,39,.6);margin-bottom:8px}
.rgl-mode-card:hover{border-color:rgba(255,255,255,.2)}
.rgl-mode-card.selected{border-color:#3b82f6;background:rgba(59,130,246,.1)}
.rgl-mode-card input[type=radio]{margin-right:8px}
.rgl-mode-title{font-weight:700;color:#e2e8f0;margin-bottom:4px}
.rgl-mode-desc{font-size:12px;color:#64748b;line-height:1.5}
.rgl-mode-badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:700;margin-left:6px}
.rgl-mode-badge.danger{background:rgba(239,68,68,.2);color:#f87171}
.rgl-mode-badge.warning{background:rgba(245,158,11,.2);color:#fbbf24}
.rgl-mode-badge.info{background:rgba(59,130,246,.2);color:#60a5fa}
.rgl-output{background:#0b1220;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:14px;font-family:monospace;font-size:12px;color:#94a3b8;white-space:pre-wrap;max-height:500px;overflow-y:auto;margin-top:12px}
.rgl-output .done{color:#4ade80}
.rgl-output .fail{color:#f87171}
.rgl-output .dry{color:#fbbf24}
.rgl-output .skip{color:#64748b}
.rgl-last{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:14px;font-size:12px}
</style>

<?php if ($flash !== ''): ?>
<div class="alert alert-<?= h($flashType) ?> alert-dismissible fade show py-2 mb-3">
  <?= $flash ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Danger Banner -->
<div class="rgl-warning">
  <div class="rgl-warning-title">⚠️ PERINGATAN — Aksi Tidak Bisa Di-Undo</div>
  <div style="font-size:13px;color:#fca5a5;line-height:1.7">
    Halaman ini hanya untuk <strong>SYS</strong>. Tool ini akan menghapus data ERP secara permanen.<br>
    Backup otomatis akan dibuat sebelum reset, tapi pastikan Anda memahami konsekuensinya.<br>
    <strong>Gunakan --dry-run dulu</strong> untuk melihat apa yang akan terjadi tanpa mengubah data.
  </div>
</div>

<div class="row g-3">

  <!-- Form Reset -->
  <div class="col-lg-6">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-3">🔄 Reset Configuration</div>

      <form method="post" id="formReset">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">

        <!-- Mode Selection -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Mode Reset</label>

          <label class="rgl-mode-card d-block" id="card-transactions">
            <input type="radio" name="mode" value="transactions" onchange="selectMode(this)">
            <span class="rgl-mode-title">📋 Transactions Only
              <span class="rgl-mode-badge warning">RECOMMENDED</span>
            </span>
            <div class="rgl-mode-desc">
              Hapus semua transaksi bisnis (PR/PO/GR/AP, Sales DO, Stock, Payroll, dll.)<br>
              <strong>Pertahankan:</strong> Master data, user login, RBAC, config sistem
            </div>
          </label>

          <label class="rgl-mode-card d-block" id="card-testdata">
            <input type="radio" name="mode" value="testdata" onchange="selectMode(this)">
            <span class="rgl-mode-title">🧹 Test Data Cleanup
              <span class="rgl-mode-badge info">MODERATE</span>
            </span>
            <div class="rgl-mode-desc">
              Hapus transaksi + data demo/uji coba<br>
              <strong>Pertahankan:</strong> Master data real, user, RBAC
            </div>
          </label>

          <label class="rgl-mode-card d-block" id="card-full">
            <input type="radio" name="mode" value="full" onchange="selectMode(this)">
            <span class="rgl-mode-title">💥 Full Reset
              <span class="rgl-mode-badge danger">PALING BERBAHAYA</span>
            </span>
            <div class="rgl-mode-desc">
              Drop database → Import ulang schema → Seed master data<br>
              <strong>SEMUA data hilang</strong> termasuk master data, user, dan config
            </div>
          </label>
        </div>

        <!-- Password -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Password Admin Baru <span style="color:#ef4444">*</span></label>
          <input class="form-control" type="password" name="new_password" id="newPass"
                 placeholder="Minimal 10 karakter" required minlength="10"
                 oninput="checkPassMatch()">
          <div class="form-text">Akun: <code>admin</code>, <code>superadmin</code>, <code>RizqullahMediskaSYS</code></div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Konfirmasi Password <span style="color:#ef4444">*</span></label>
          <input class="form-control" type="password" name="confirm_password" id="confirmPass"
                 placeholder="Ulangi password di atas" required minlength="10"
                 oninput="checkPassMatch()">
          <div id="passMatchMsg" class="form-text"></div>
        </div>

        <!-- Options -->
        <div class="mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="dry_run" value="1" id="dryRun" checked>
            <label class="form-check-label" for="dryRun">
              <strong>Dry-run</strong> — tampilkan rencana tanpa eksekusi nyata
            </label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="skip_backup" value="1" id="skipBackup">
            <label class="form-check-label text-warning" for="skipBackup">
              Skip backup otomatis (⚠️ tidak disarankan)
            </label>
          </div>
        </div>

        <!-- Confirmation -->
        <div class="mb-3 p-3 rounded" style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2)">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="understood" value="1"
                   id="understood" onchange="toggleSubmit()">
            <label class="form-check-label" for="understood" style="color:#fca5a5;font-size:13px">
              Saya memahami bahwa aksi ini <strong>tidak bisa di-undo</strong>
              dan backup otomatis akan dibuat sebelum reset dilakukan.
            </label>
          </div>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" id="btnSubmit" class="btn btn-danger" disabled>
            🚀 Jalankan Reset
          </button>
          <button type="reset" class="btn btn-outline-light" onclick="resetForm()">
            Reset Form
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Info Panel -->
  <div class="col-lg-6">

    <!-- Last Reset Status -->
    <?php if (!empty($lastReset)): ?>
    <div class="rmi-card p-3 mb-3">
      <div class="fw-semibold mb-2">📋 Reset Terakhir</div>
      <div class="rgl-last">
        <div><span style="color:#64748b">Waktu:</span> <?= h((string)($lastReset['run_at'] ?? '-')) ?></div>
        <div><span style="color:#64748b">Mode:</span> <strong><?= h(strtoupper((string)($lastReset['mode'] ?? '-'))) ?></strong></div>
        <div><span style="color:#64748b">Status:</span>
          <?php $lOk=(bool)($lastReset['ok']??false); ?>
          <span style="color:<?= $lOk?'#4ade80':'#f87171' ?>">
            <?= $lOk ? '✅ Berhasil' : '❌ Gagal' ?>
          </span>
        </div>
        <div><span style="color:#64748b">Dry-run:</span> <?= ($lastReset['dry_run']??false)?'Ya':'Tidak' ?></div>
        <div style="margin-top:6px;color:#64748b;font-size:11px"><?= h((string)($lastReset['note'] ?? '')) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- What will be deleted -->
    <div class="rmi-card p-3 mb-3">
      <div class="fw-semibold mb-2" id="infoTitle">📋 Pilih mode untuk melihat detail</div>
      <div id="infoContent" style="font-size:12px;color:#64748b">
        Pilih mode reset di sebelah kiri untuk melihat apa yang akan dihapus.
      </div>
    </div>

    <!-- Checklist -->
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">✅ Checklist Sebelum Reset</div>
      <div style="font-size:12px;line-height:2;color:#94a3b8">
        <label class="d-flex align-items-center gap-2"><input type="checkbox"> UAT/uji coba semua user sudah selesai</label>
        <label class="d-flex align-items-center gap-2"><input type="checkbox"> Master data sudah diverifikasi &amp; bersih</label>
        <label class="d-flex align-items-center gap-2"><input type="checkbox"> Backup terakhir sudah diverifikasi</label>
        <label class="d-flex align-items-center gap-2"><input type="checkbox"> Password baru sudah disiapkan &amp; dicatat</label>
        <label class="d-flex align-items-center gap-2"><input type="checkbox"> Tim sudah diberi tahu waktu go-live</label>
        <label class="d-flex align-items-center gap-2"><input type="checkbox"> Dry-run sudah dijalankan &amp; direview</label>
      </div>
    </div>
  </div>

  <!-- Output Panel -->
  <?php if (!empty($result)): ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-semibold">
          <?= $result['dry'] ? '🔍 Dry-run Output' : '🔄 Reset Output' ?>
          — Mode: <strong><?= h(strtoupper($result['mode'])) ?></strong>
          <span class="ms-2 badge <?= $result['ok']?'text-bg-success':'text-bg-danger' ?>">
            <?= $result['ok']?'BERHASIL':'GAGAL' ?> (<?= (int)$result['ms'] ?>ms)
          </span>
        </div>
        <button class="btn btn-xs btn-outline-light" onclick="copyOutput()">📋 Copy</button>
      </div>
      <div class="rgl-output" id="outputBox"><?php
        $rawOut = (string)($result['output'] ?? '');
        // Color-code output
        $lines = explode("\n", $rawOut);
        foreach ($lines as $line) {
            $esc = h($line);
            if (str_contains($line,'  DONE')) echo "<span class='done'>{$esc}</span>\n";
            elseif (str_contains($line,'  FAIL') || str_contains($line,'FAIL:')) echo "<span class='fail'>{$esc}</span>\n";
            elseif (str_contains($line,'  DRY')) echo "<span class='dry'>{$esc}</span>\n";
            elseif (str_contains($line,'  SKIP') || str_contains($line,'  WARN')) echo "<span class='skip'>{$esc}</span>\n";
            else echo $esc . "\n";
        }
      ?></div>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
var modeInfo = {
  transactions: {
    title: '📋 Mode: Transactions Only',
    html: '<strong style="color:#4ade80">Yang DIHAPUS:</strong><br>'
      + '• PR, PO, GR, Invoice AP, Payment AP<br>'
      + '• Sales DO, Tax Invoice<br>'
      + '• Stock (wqs_stock, incoming, picking, opname, transfer, adjustments)<br>'
      + '• GL Journal, Bank Reconciliation<br>'
      + '• Payroll runs, Absensi logs, MPR plans<br>'
      + '• FA dep_runs, KPI snapshots, CRM leads<br>'
      + '• System audit logs, Auth login attempts<br><br>'
      + '<strong style="color:#fbbf24">Yang DIPERTAHANKAN:</strong><br>'
      + '• Master customers, products, vendors, employees<br>'
      + '• User login & RBAC<br>'
      + '• System config, GL accounts mapping<br>'
      + '• Payroll salary matrix<br>'
      + '• Chat channels (pesan dihapus)<br>'
      + '• Fixed assets register'
  },
  testdata: {
    title: '🧹 Mode: Test Data Cleanup',
    html: '<strong style="color:#4ade80">Yang DIHAPUS:</strong><br>'
      + '• Semua yang di-delete oleh mode Transactions, PLUS:<br>'
      + '• Data demo/seed: crm_lead_dedupe_rules, migration errors<br>'
      + '• Migration runs & targets<br><br>'
      + '<strong style="color:#fbbf24">Yang DIPERTAHANKAN:</strong><br>'
      + '• Sama seperti mode Transactions'
  },
  full: {
    title: '💥 Mode: Full Reset',
    html: '<strong style="color:#f87171">Yang DIHAPUS:</strong><br>'
      + '• SEMUA data — database di-drop dan dibuat ulang<br>'
      + '• Master data, user login, RBAC, config<br>'
      + '• Semua transaksi dan log<br><br>'
      + '<strong style="color:#fbbf24">Yang DIPERTAHANKAN:</strong><br>'
      + '• File kode aplikasi (tidak ada yang berubah di filesystem)<br>'
      + '• Backup yang sudah ada di storage/backups/<br><br>'
      + '<strong style="color:#fbbf24">Setelah full reset:</strong><br>'
      + '• Login: admin / password yang di-set<br>'
      + '• Seed files dijalankan otomatis<br>'
      + '• Input ulang semua master data'
  }
};

function selectMode(el) {
  document.querySelectorAll('.rgl-mode-card').forEach(c => c.classList.remove('selected'));
  el.closest('.rgl-mode-card').classList.add('selected');
  var info = modeInfo[el.value];
  if (info) {
    document.getElementById('infoTitle').textContent = info.title;
    document.getElementById('infoContent').innerHTML = info.html;
  }
  toggleSubmit();
}

function checkPassMatch() {
  var p1 = document.getElementById('newPass').value;
  var p2 = document.getElementById('confirmPass').value;
  var msg = document.getElementById('passMatchMsg');
  if (p2 === '') { msg.textContent = ''; return; }
  if (p1 === p2 && p1.length >= 10) {
    msg.style.color = '#4ade80'; msg.textContent = '✅ Password cocok';
  } else if (p1 !== p2) {
    msg.style.color = '#f87171'; msg.textContent = '❌ Password tidak cocok';
  } else {
    msg.style.color = '#fbbf24'; msg.textContent = '⚠️ Minimal 10 karakter';
  }
  toggleSubmit();
}

function toggleSubmit() {
  var mode = document.querySelector('input[name="mode"]:checked');
  var understood = document.getElementById('understood').checked;
  var p1 = document.getElementById('newPass').value;
  var p2 = document.getElementById('confirmPass').value;
  var ok = mode && understood && p1.length >= 10 && p1 === p2;
  document.getElementById('btnSubmit').disabled = !ok;
}

function resetForm() {
  document.querySelectorAll('.rgl-mode-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('infoTitle').textContent = '📋 Pilih mode untuk melihat detail';
  document.getElementById('infoContent').textContent = 'Pilih mode reset di sebelah kiri.';
  document.getElementById('passMatchMsg').textContent = '';
  toggleSubmit();
}

function copyOutput() {
  var txt = document.getElementById('outputBox')?.innerText || '';
  navigator.clipboard?.writeText(txt).then(function(){
    alert('Output disalin ke clipboard.');
  });
}

// Scroll output to bottom
var ob = document.getElementById('outputBox');
if (ob) ob.scrollTop = ob.scrollHeight;
</script>

<style>.btn-xs{padding:2px 8px;font-size:11px;border-radius:5px}</style>

<?php rmi_footer(); ?>
