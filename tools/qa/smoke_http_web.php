<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('qa/smoke_http_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h($v) { return rmi_h($v); }
}

$statePath = ts_storage_logs_dir() . '/smoke_http_web.last.json';
$msg = '';
$msgType = 'info';
$runOutput = [];
$runExit = null;

$defaultBase = tools_default_base_url();
$baseInput = (string)($_POST['smoke_base_url'] ?? $defaultBase);
$adminUser = (string)($_POST['smoke_admin_user'] ?? 'SmokeSYS_SYS');
$staffUser = (string)($_POST['smoke_staff_user'] ?? 'SmokeBRANCH_SYS');
$useCreds = isset($_POST['use_credentials']) ? ((string)$_POST['use_credentials'] === '1') : false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator unavailable');
    }

    $baseInput = trim((string)($_POST['smoke_base_url'] ?? $defaultBase));
    $adminUser = trim((string)($_POST['smoke_admin_user'] ?? 'SmokeSYS_SYS'));
    $adminPass = (string)($_POST['smoke_admin_pass'] ?? '');
    $staffUser = trim((string)($_POST['smoke_staff_user'] ?? 'SmokeBRANCH_SYS'));
    $staffPass = (string)($_POST['smoke_staff_pass'] ?? '');
    $useCreds = ((string)($_POST['use_credentials'] ?? '0') === '1');

    $root = ts_root();
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) rmi_env_load();
    $phpBin = function_exists('tools_php_bin') ? tools_php_bin() : (string)(getenv('ERP_PHP_BIN') ?: 'php');
    if (is_file($root . '/tools/audit/_lib/php_static_analysis_lib.php')) {
        require_once $root . '/tools/audit/_lib/php_static_analysis_lib.php';
        $phpBin = function_exists('psal_resolve_php_cli') ? psal_resolve_php_cli($phpBin) : $phpBin;
    }
    $cmdParts = ['TOOLS_BASE_URL_INTERNAL=' . escapeshellarg(tools_default_base_url())];
    if ($baseInput !== '') $cmdParts[] = 'SMOKE_BASE_URL=' . escapeshellarg($baseInput);
    if ($useCreds && $adminUser !== '' && $adminPass !== '' && $staffUser !== '' && $staffPass !== '') {
        $cmdParts[] = 'SMOKE_ADMIN_USER=' . escapeshellarg($adminUser);
        $cmdParts[] = 'SMOKE_ADMIN_PASS=' . escapeshellarg($adminPass);
        $cmdParts[] = 'SMOKE_STAFF_USER=' . escapeshellarg($staffUser);
        $cmdParts[] = 'SMOKE_STAFF_PASS=' . escapeshellarg($staffPass);
    }
    $script = $root . '/tools/smoke_http.php';
    $cmdParts[] = escapeshellarg($phpBin) . ' ' . escapeshellarg($script);
    $cmd = implode(' ', $cmdParts);

    @exec($cmd . ' 2>&1', $runOutput, $runExit);
    $runOutput = array_map('tools_mask_sensitive', $runOutput);

    $smoke = ts_read_json(ts_storage_logs_dir() . '/smoke_http_last.json');
    $summary = ts_smoke_summary($smoke);
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'exit_code' => (int)$runExit,
        'overall_ok' => ((int)$runExit === 0),
        'smoke_base_url' => $baseInput,
        'summary' => $summary,
        'output' => $runOutput,
    ];
    ts_write_json($statePath, $payload);
    ts_append_run_history('smoke_http_web_run', ((int)$runExit === 0 ? 'OK' : 'FAIL'), [
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        'source' => 'tools/qa/smoke_http_web.php',
        'smoke_base_url' => $baseInput,
        'exit_code' => (int)$runExit,
    ]);

    if ((int)$runExit === 0) {
        $msg = 'Smoke HTTP selesai: PASS.';
        $msgType = 'success';
    } else {
        $msg = 'Smoke HTTP selesai: FAIL.';
        $msgType = 'warning';
    }
}

$state = tools_read_state_json($statePath, ['overall_ok', 'summary', 'output']);
$data = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$summary = is_array($data['summary'] ?? null) ? (array)$data['summary'] : [];
$output = is_array($data['output'] ?? null) ? (array)$data['output'] : [];
// Fallback: baca dari smoke_http_last.json (hasil run CLI)
if (empty($summary)) {
    $smokeCli = ts_read_json(ts_storage_logs_dir() . '/smoke_http_last.json');
    if (is_array($smokeCli)) {
        $summary = (array)($smokeCli['checks'] ?? $smokeCli);
        if (isset($smokeCli['pass'], $smokeCli['fail'], $smokeCli['total'])) {
            $summary = ['total' => (int)$smokeCli['total'], 'pass' => (int)$smokeCli['pass'], 'fail' => (int)$smokeCli['fail']];
        }
        if (!isset($data['overall_ok'])) $data['overall_ok'] = ((int)($smokeCli['fail'] ?? 0) === 0);
        if (empty($data['run_at']) && !empty($smokeCli['generated_at'])) $data['run_at'] = $smokeCli['generated_at'];
    }
}

$baseProject = rmi_layout_base_project();
rmi_header('Smoke HTTP (Web)', [
    'active' => 'tools',
    'subtitle' => 'Jalankan runtime smoke dari UI tanpa terminal.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Smoke HTTP Web'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
        <div class="col-lg-4">
          <label class="form-label">SMOKE_BASE_URL <small class="text-muted">(gunakan https://)</small></label>
          <input class="form-control form-control-sm" name="smoke_base_url" value="<?= h($baseInput) ?>" placeholder="https://10.10.60.20/ERP_RMI_SOFULL">
        </div>
        <div class="col-lg-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="use_credentials" value="1" id="useCredsSmoke" <?= $useCreds ? 'checked' : '' ?>>
            <label class="form-check-label small" for="useCredsSmoke">Use custom creds</label>
          </div>
        </div>
        <div class="col-lg-2">
          <label class="form-label">SmokeSYS_SYS_USER <small class="text-muted">(SmokeSYS_SYS)</small></label>
          <input class="form-control form-control-sm" name="smoke_admin_user" value="<?= h($adminUser) ?>">
        </div>
        <div class="col-lg-2">
          <label class="form-label">SmokeSYS_SYS_PASS </label>
          <input class="form-control form-control-sm" type="password" name="SmokeSYS_SYS_PASS" value="">
        </div>
        <div class="col-lg-1">
          <label class="form-label">SmokeBRANCH_SYS_USER <small class="text-muted">(SmokeBRANCH_SYS)</small></label>
          <input class="form-control form-control-sm" name="smoke_staff_user" value="<?= h($staffUser) ?>">
        </div>
        <div class="col-lg-1">
          <label class="form-label">SmokeBRANCH_SYS_PASS</label>
          <input class="form-control form-control-sm" type="password" name="SmokeBRANCH_SYS_PASS" value="">
        </div>
        <div class="col-12 d-flex justify-content-end">
          <button class="btn btn-rmi btn-sm" type="submit">Run Smoke HTTP</button>
        </div>
      </form>
      <div class="small mt-2 text-muted">
        Jika "Use custom creds" dicentang, isi <b>semua 4 field</b> (admin user+pass, staff user+pass). Username admin biasanya <code>admin</code> (huruf kecil).
      </div>
      <div class="small mt-1">
        CLI: <code>php tools/smoke_http.php</code> · State: <code><?= h(ts_mask($statePath)) ?></code>
      </div>
    </div>
  </div>
  <?php
  // Baca checks detail dari smoke_http_last.json
  $smokeLastJson  = ts_read_json(ts_storage_logs_dir() . '/smoke_http_last.json');
  $allChecks      = is_array($smokeLastJson['checks'] ?? null) ? (array)$smokeLastJson['checks'] : [];
  $failedChecks   = array_filter($allChecks, fn($c) => !(bool)($c['ok'] ?? true));

  // Deteksi apakah mayoritas failure disebabkan 301 (HTTP→HTTPS redirect)
  $fail301Count = 0;
  foreach ($failedChecks as $fc) {
      if (preg_match('/code=301/', (string)($fc['detail'] ?? ''))) $fail301Count++;
  }
  $mostly301 = $fail301Count > 3; // ≥4 failure code=301 = root cause adalah redirect

  // Mapping error yang diketahui → penyebab & fix
  $knownErrors = [
    'http_to_https_redirect' => [
      'penyebab' => 'SMOKE_BASE_URL menggunakan http:// tapi server redirect ke https:// (301). Auto-detect diaktifkan dan base URL sudah diperbarui ke HTTPS untuk run ini.',
      'fix'      => 'Ubah SMOKE_BASE_URL di form ke https://... — atau set SMOKE_BASE_URL=https://10.10.60.20/ERP_RMI_SOFULL di .env agar konsisten.',
    ],
    'lifecycle_with_valid_csrf' => [
              'penyebab' => 'SmokeSYS_SYS di-redirect sebelum mendapat CSRF token (biasanya dept salah atau CSRF token tidak terbaca dari halaman).',
              'fix'      => 'Pastikan SmokeSYS_SYS di tools/smoke_http.php di-seed dengan dept=SYS. Jalankan ulang smoke untuk re-seed DB.',
    ],
    'lifecycle_without_csrf_rejected' => [
      'penyebab' => 'POST tanpa CSRF seharusnya ditolak (403), tapi tidak.',
      'fix'      => 'Periksa verify_csrf() di master/master_system_login.php — pastikan validasi CSRF aktif untuk semua POST.',
    ],
    'backup_manager_without_csrf_rejected' => [
      'penyebab' => 'POST ke backup_manager.php tanpa CSRF tidak menghasilkan 403.',
      'fix'      => 'Periksa verify_csrf() di tools/backup_manager.php.',
    ],
    'backup_now_with_valid_csrf' => [
      'penyebab' => 'Backup tidak berjalan atau file backup tidak bertambah setelah POST.',
      'fix'      => 'Cek permission folder storage/backups, pastikan php84 bisa menulis. Cek juga backup_now.php di tools_access_matrix.php sudah allow SYS/ADMIN.',
    ],
    'db_connect' => [
      'penyebab' => 'Koneksi database gagal dari smoke script.',
      'fix'      => 'Periksa config-db.php / .env — host, user, password, port. Pastikan MySQL berjalan.',
    ],
    'storage_logs_writable' => [
      'penyebab' => 'Folder storage/logs tidak bisa ditulis.',
      'fix'      => 'Jalankan: chmod -R 775 storage/logs dari NAS.',
    ],
    'seed_smoke_users' => [
              'penyebab' => 'Gagal INSERT/UPDATE SmokeSYS_SYS atau SmokeBRANCH_SYS di DB.',
      'fix'      => 'Periksa koneksi DB dan struktur tabel master_system_login (kolom role, level, department, deleted_at).',
    ],
    'health_json_structure' => [
      'penyebab' => 'Response /api/v1/health.php tidak mengandung key db, storage, cron, atau worker_queue.',
      'fix'      => 'Buka api/v1/health.php dan pastikan semua key tersebut ada di array data.',
    ],
    'health_ui_masked_paths' => [
      'penyebab' => 'Halaman /tools/health.php tidak mem-mask path dengan [APP_ROOT].',
      'fix'      => 'Pastikan tools_mask_sensitive() digunakan sebelum output path sensitif di tools/health.php.',
    ],

    // ── Session & Cookie Security ──────────────────────────────────────────
    'login_cookie_httponly' => [
      'penyebab' => 'Session cookie tidak memiliki flag HttpOnly — bisa dibaca JavaScript (XSS risk).',
      'fix'      => 'Pastikan session_set_cookie_params([..., "httponly" => true]) dipanggil SEBELUM session_start() di _shared/bootstrap.php. Cek juga master/auth.php.',
    ],
    'login_cookie_samesite' => [
      'penyebab' => 'Session cookie tidak memiliki SameSite=Lax — rentan CSRF dari domain lain.',
      'fix'      => 'Pastikan session_set_cookie_params([..., "samesite" => "Lax"]) dipanggil sebelum session_start() di _shared/bootstrap.php.',
    ],
    'login_autocomplete_attr' => [
      'penyebab' => 'Form login tidak punya autocomplete="username" di input username — password manager tidak bisa autofill di mobile/browser.',
      'fix'      => 'Di master/login.php: tambahkan autocomplete="username" di input username, autocomplete="current-password" di input password, form autocomplete="on".',
    ],

    // ── Hard Gate Security ────────────────────────────────────────────────
    'admin_allow_master_system_config' => [
      'penyebab' => 'Admin (SYS) tidak bisa akses /master/master_system_config.php padahal seharusnya diizinkan.',
      'fix'      => 'Cek hard gate di master_system_config.php — pastikan $isPrivileged benar untuk role/level SYS.',
    ],
    'staff_block_master_system_config' => [
      'penyebab' => 'Staff bisa akses /master/master_system_config.php padahal seharusnya diblokir (SYS only).',
      'fix'      => 'Cek hard gate di master_system_config.php — blok di baris 54+ harus aktif untuk non-SYS user yang tidak punya MASTER_SYSTEM.CONFIG_VIEW.',
    ],

    // ── Audit Log UI ──────────────────────────────────────────────────────
    'audit_log_ip_column' => [
      'penyebab' => 'Kolom IP tidak tampil di halaman /master/audit_logs.php.',
      'fix'      => 'Pastikan query di master/audit_logs.php mengambil kolom ip dan ada <th>IP</th> + <td> ip di tabel.',
    ],

    // ── Absensi Admin ─────────────────────────────────────────────────────
    'absensi_settings_form' => [
      'penyebab' => 'Halaman /absensi/admin/settings.php crash atau tidak menampilkan form jam kerja.',
      'fix'      => 'Cek absensi/admin/settings.php — pastikan form memiliki input checkin_std_time. Pastikan tabel absensi_settings ada dan Bootstrap include benar.',
    ],
    'absensi_shifts_page' => [
      'penyebab' => 'Halaman /absensi/admin/shifts.php crash atau tidak memuat konten shift.',
      'fix'      => 'Cek absensi/admin/shifts.php — pastikan absensi_shift_ensure_tables() berjalan dan _inc/shift_helper.php ter-include. Cek tabel absensi_shifts.',
    ],
  ];
  ?>

  <div class="col-lg-4">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Status</div>
      <?php if ($state['ok'] || !empty($summary)): ?>
        <div class="small mb-1"><?= !empty($data['overall_ok']) ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($data['run_at'] ?? ($smokeLastJson['generated_at'] ?? '')))) ?></b></div>
        <div class="small">Total: <b><?= (int)($smokeLastJson['total'] ?? $summary['total'] ?? 0) ?></b>
          · <span class="text-success fw-semibold">Pass: <?= (int)($smokeLastJson['pass'] ?? $summary['pass'] ?? 0) ?></span>
          · <span class="<?= (int)($smokeLastJson['fail'] ?? $summary['fail'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>">Fail: <?= (int)($smokeLastJson['fail'] ?? $summary['fail'] ?? 0) ?></span>
        </div>
      <?php else: ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada run valid.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Raw Output</div>
      <pre class="small mb-0" style="white-space:pre-wrap;max-height:160px;overflow-y:auto"><?= h($output ? implode("\n", $output) : 'Belum ada output.') ?></pre>
    </div>
  </div>

  <?php if (!empty($failedChecks)): ?>
  <div class="col-12">
    <div class="rmi-card p-3" style="border-left:4px solid #dc3545">
      <div class="fw-semibold mb-3 text-danger">⛔ Error Detail (<?= count($failedChecks) ?> gagal)</div>
      <?php if ($mostly301): ?>
      <div class="alert alert-warning py-2 mb-3">
        <strong>Root Cause Terdeteksi:</strong> <?= $fail301Count ?> dari <?= count($failedChecks) ?> failure mengembalikan <code>code=301</code>.
        Ini berarti <b>SMOKE_BASE_URL</b> pakai <code>http://</code> tapi server redirect ke <code>https://</code>.
        <br>Ubah field <b>SMOKE_BASE_URL</b> di form ke <code>https://10.10.60.20/ERP_RMI_SOFULL</code> lalu <b>Run ulang</b>.
        Smoke sekarang memiliki auto-detect preflight — jika 301 terdeteksi, base URL otomatis diperbarui ke HTTPS.
      </div>
      <?php endif; ?>
      <?php foreach ($failedChecks as $c):
        $name   = (string)($c['name'] ?? '');
        $cat    = (string)($c['category'] ?? '');
        $detail = (string)($c['detail'] ?? '');
        $info   = $knownErrors[$name] ?? null;
      ?>
      <?php
        // Deteksi HTTP→HTTPS redirect (code=301) dari detail string
        $is301 = (bool)preg_match('/code=301/', $detail);
      ?>
      <div class="mb-3 p-3 rounded" style="background:#2a1a1a;border:1px solid #dc3545">
        <div class="mb-1">
          <span class="badge bg-danger me-1">FAIL</span>
          <code class="text-warning"><?= h($cat) ?> :: <?= h($name) ?></code>
          <span class="text-muted small ms-2">(<?= h($detail) ?>)</span>
        </div>
        <?php if ($is301): ?>
        <div class="small mt-2">
          <div class="mb-1"><span class="text-danger fw-semibold">Penyebab:</span> <span class="text-light">Server mengembalikan HTTP 301 (redirect HTTP→HTTPS). SMOKE_BASE_URL menggunakan <code>http://</code> tapi server memaksa <code>https://</code>.</span></div>
          <div><span class="text-success fw-semibold">Fix:</span> <span class="text-light">Ubah <b>SMOKE_BASE_URL</b> di field di atas ke <code>https://...</code> lalu run ulang. Atau set <code>SMOKE_BASE_URL=https://10.10.60.20/ERP_RMI_SOFULL</code> di <code>.env</code>. Smoke sekarang juga auto-detect dan update base URL jika 301 terdeteksi di preflight check.</span></div>
        </div>
        <?php elseif ($info): ?>
        <div class="small mt-2">
          <div class="mb-1"><span class="text-danger fw-semibold">Penyebab:</span> <span class="text-light"><?= h($info['penyebab']) ?></span></div>
          <div><span class="text-success fw-semibold">Fix:</span> <span class="text-light"><?= h($info['fix']) ?></span></div>
        </div>
        <?php else: ?>
        <div class="small mt-2 text-muted fst-italic">Tidak ada mapping penyebab/fix untuk check ini. Cek output raw di atas.</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($allChecks)): ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Semua Check (<?= count($allChecks) ?>)</div>
      <table class="table table-sm table-dark mb-0 small">
        <thead><tr><th>Status</th><th>Kategori</th><th>Check</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($allChecks as $c):
          $ok = (bool)($c['ok'] ?? false);
        ?>
          <tr class="<?= $ok ? '' : 'table-danger' ?>">
            <td><?= $ok ? '<span class="text-success fw-bold">✓ PASS</span>' : '<span class="text-danger fw-bold">✗ FAIL</span>' ?></td>
            <td><?= h((string)($c['category'] ?? '')) ?></td>
            <td><?= h((string)($c['name'] ?? '')) ?></td>
            <td class="text-muted"><?= h((string)($c['detail'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>
<?php rmi_footer(); ?>
