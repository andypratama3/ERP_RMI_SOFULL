<?php
declare(strict_types=1);

/**
 * Monitoring & Control Center — ringkasan audit DB, file log, dan shortcut operasional.
 * Akses sama dengan Audit Log terpusat (RBAC configurable).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_login();

if (function_exists('require_any_permission')) {
    require_any_permission([
        'SYSTEM.AUDIT_LOG_VIEW',
        'SYSTEM.JOBS_MONITOR',
        'SYSTEM.USER_MANAGE',
        'SYSTEM.CONFIG_MANAGE',
        'MASTER.ADMIN_CENTER',
    ]);
} else {
    if (function_exists('auth_is_admin') && !auth_is_admin()) {
        http_response_code(403);
        echo '<h3>Akses Ditolak</h3><p>Halaman ini hanya untuk SYS atau permission audit sistem.</p>';
        exit;
    }
}

require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

$pdo = db_pdo();
if (function_exists('master_audit_ensure_table')) {
    master_audit_ensure_table($pdo);
}

$base = rmi_layout_base_project();
$rootFs = defined('RMI_ROOT') ? rtrim((string)RMI_ROOT, '/\\') : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
$logDir = $rootFs . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';

/**
 * @return array{exists:bool, today:int, d7:int}
 */
function mc_audit_counts(PDO $pdo, string $table, bool $exists): array {
    $allowed = ['erp_audit_log' => true, 'kpi_audit_log' => true];
    $out = ['exists' => $exists, 'today' => 0, 'd7' => 0];
    if (!$exists || !isset($allowed[$table])) {
        return $out;
    }
    try {
        $st = $pdo->query(
            "SELECT
               SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) AS today_cnt,
               SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS d7_cnt
             FROM `" . $table . "`"
        );
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if (is_array($row)) {
            $out['today'] = (int)($row['today_cnt'] ?? 0);
            $out['d7'] = (int)($row['d7_cnt'] ?? 0);
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

function mc_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function mc_fmt_bytes(int $bytes): string {
    if ($bytes < 1024) {
        return (string) $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $v = (float) $bytes;
    $u = 0;
    while ($v >= 1024 && $u < count($units) - 1) {
        $v /= 1024;
        $u++;
    }
    return sprintf('%.1f %s', $v, $units[$u]);
}

$hasSystemAudit = mc_table_exists($pdo, 'system_audit_logs');
$hasErpAudit = mc_table_exists($pdo, 'erp_audit_log');
$hasKpiAudit = mc_table_exists($pdo, 'kpi_audit_log');

$cntToday = 0;
$cnt7 = 0;
$cnt30 = 0;
$topModules = [];
$latestRows = [];

if ($hasSystemAudit) {
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM system_audit_logs WHERE created_at >= CURDATE()"
        );
        $cntToday = (int) ($st ? $st->fetchColumn() : 0);
    } catch (Throwable $e) {
        $cntToday = 0;
    }
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM system_audit_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
        );
        $cnt7 = (int) ($st ? $st->fetchColumn() : 0);
    } catch (Throwable $e) {
        $cnt7 = 0;
    }
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM system_audit_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        );
        $cnt30 = (int) ($st ? $st->fetchColumn() : 0);
    } catch (Throwable $e) {
        $cnt30 = 0;
    }
    try {
        $st = $pdo->query(
            "SELECT module, COUNT(*) AS c FROM system_audit_logs
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY module ORDER BY c DESC LIMIT 8"
        );
        $topModules = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $topModules = [];
    }
    try {
        $st = $pdo->query(
            "SELECT created_at, module, action, username, ip
             FROM system_audit_logs ORDER BY created_at DESC LIMIT 8"
        );
        $latestRows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $latestRows = [];
    }
}

$erpC = mc_audit_counts($pdo, 'erp_audit_log', $hasErpAudit);
$kpiC = mc_audit_counts($pdo, 'kpi_audit_log', $hasKpiAudit);

$logFiles = [];
$logsTotalBytes = 0;
if (is_dir($logDir)) {
    $dh = @opendir($logDir);
    if ($dh !== false) {
        while (($name = readdir($dh)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $logDir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($full) || !str_ends_with(strtolower($name), '.log')) {
                continue;
            }
            $sz = @filesize($full);
            $mt = @filemtime($full);
            if ($sz !== false && $mt !== false) {
                $logsTotalBytes += (int) $sz;
                $logFiles[] = ['name' => $name, 'bytes' => (int) $sz, 'mtime' => (int) $mt];
            }
        }
        closedir($dh);
    }
    usort($logFiles, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    $logFiles = array_slice($logFiles, 0, 18);
}

$appEnv = defined('APP_ENV') ? (string) APP_ENV : (string) (getenv('APP_ENV') ?: 'local');

rmi_header('Monitoring & Control', [
    'active' => 'monitoring_center',
    'subtitle' => 'Ringkasan audit, file log, dan pintasan monitoring operasional',
    'breadcrumbs' => [
        ['label' => 'Master Data', 'url' => $base . '/master/index.php'],
        'Monitoring & Control',
    ],
    'actions' => [
        ['label' => 'Audit Log (detail)', 'url' => $base . '/master/audit_logs.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Master Data', 'url' => $base . '/master/index.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Panduan', 'url' => $base . '/docs/monitoring_guide.php', 'class' => 'btn btn-sm btn-outline-light', 'attrs' => 'target="_blank" rel="noopener"'],
    ],
]);
?>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="rmi-card p-3 h-100">
      <div class="small text-muted mb-1">Lingkungan</div>
      <div class="fs-5 fw-semibold"><?= rmi_h($appEnv) ?></div>
      <div class="small text-muted mt-2">Bukan semua aktivitas ERP otomatis masuk audit — lihat panduan di bawah.</div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">system_audit_logs (terpusat)</div>
      <?php if (!$hasSystemAudit): ?>
        <div class="text-warning">Tabel tidak ditemukan — jalankan migrasi / master audit.</div>
      <?php else: ?>
        <div class="row text-center g-2">
          <div class="col-4">
            <div class="small text-muted">Hari ini</div>
            <div class="fs-4 fw-bold"><?= rmi_h((string) $cntToday) ?></div>
          </div>
          <div class="col-4">
            <div class="small text-muted">7 hari</div>
            <div class="fs-4 fw-bold"><?= rmi_h((string) $cnt7) ?></div>
          </div>
          <div class="col-4">
            <div class="small text-muted">30 hari</div>
            <div class="fs-4 fw-bold"><?= rmi_h((string) $cnt30) ?></div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Audit tambahan (jika tabel ada)</div>
      <table class="<?= rmi_h(rmi_ui_table_class()) ?> mb-0">
        <thead><tr><th>Sumber</th><th class="text-end">Hari ini</th><th class="text-end">7 hari</th></tr></thead>
        <tbody>
          <tr>
            <td><code>erp_audit_log</code></td>
            <td class="text-end"><?= $erpC['exists'] ? rmi_h((string) $erpC['today']) : '—' ?></td>
            <td class="text-end"><?= $erpC['exists'] ? rmi_h((string) $erpC['d7']) : '—' ?></td>
          </tr>
          <tr>
            <td><code>kpi_audit_log</code></td>
            <td class="text-end"><?= $kpiC['exists'] ? rmi_h((string) $kpiC['today']) : '—' ?></td>
            <td class="text-end"><?= $kpiC['exists'] ? rmi_h((string) $kpiC['d7']) : '—' ?></td>
          </tr>
        </tbody>
      </table>
      <div class="small text-muted mt-2">Dual-write beberapa modul juga mengisi <code>system_audit_logs</code>.</div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">File log (<?= rmi_h(mc_fmt_bytes($logsTotalBytes)) ?> total, <?= rmi_h((string) count($logFiles)) ?> file terbaru)</div>
      <div class="small text-muted mb-2">Folder: <code>storage/logs/</code> — dari <code>rmi_log_module_error()</code> &amp; cron/backup.</div>
      <?php if ($logFiles === []): ?>
        <div class="text-muted">Belum ada file <code>.log</code> atau folder tidak terbaca.</div>
      <?php else: ?>
        <div class="table-responsive" style="max-height:220px;overflow:auto">
          <table class="<?= rmi_h(rmi_ui_table_class()) ?> mb-0">
            <thead><tr><th>File</th><th class="text-end">Ukuran</th><th>Diubah</th></tr></thead>
            <tbody>
              <?php foreach ($logFiles as $lf): ?>
                <tr>
                  <td><code class="small"><?= rmi_h($lf['name']) ?></code></td>
                  <td class="text-end small"><?= rmi_h(mc_fmt_bytes($lf['bytes'])) ?></td>
                  <td class="small text-muted"><?= rmi_h(date('Y-m-d H:i', $lf['mtime'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($topModules !== []): ?>
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-2">Modul tersibuk (7 hari) — <code>system_audit_logs</code></div>
  <div class="table-responsive">
    <table class="<?= rmi_h(rmi_ui_table_class()) ?> mb-0">
      <thead><tr><th>Module</th><th class="text-end">Jumlah event</th></tr></thead>
      <tbody>
        <?php foreach ($topModules as $tm): ?>
          <tr>
            <td><code><?= rmi_h((string)($tm['module'] ?? '')) ?></code></td>
            <td class="text-end"><?= rmi_h((string)($tm['c'] ?? '0')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($latestRows !== []): ?>
<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">Aktivitas terbaru (cuplikan)</div>
    <a class="btn btn-sm btn-primary" href="<?= rmi_h($base . '/master/audit_logs.php') ?>">Buka filter penuh →</a>
  </div>
  <div class="table-responsive">
    <table class="<?= rmi_h(rmi_ui_table_class()) ?> mb-0">
      <thead>
        <tr><th>Waktu</th><th>Module</th><th>Action</th><th>User</th><th>IP</th></tr>
      </thead>
      <tbody>
        <?php foreach ($latestRows as $r): ?>
          <tr>
            <td class="small text-muted" style="white-space:nowrap"><?= rmi_h(substr((string)($r['created_at'] ?? ''), 0, 19)) ?></td>
            <td><code class="small"><?= rmi_h((string)($r['module'] ?? '')) ?></code></td>
            <td class="small"><?= rmi_h((string)($r['action'] ?? '')) ?></td>
            <td class="small"><?= rmi_h((string)($r['username'] ?? '')) ?></td>
            <td class="small font-monospace text-muted"><?= rmi_h((string)($r['ip'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold mb-3">Pintasan controlling &amp; monitoring</div>
  <div class="row g-2">
    <?php
    $links = [
        [rmi_icon('clipboard'), 'Audit Log (filter)', $base . '/master/audit_logs.php', 'Detail semua baris system_audit_logs'],
        [rmi_icon('receipt'), 'Enterprise Audit (static)', $base . '/tools/enterprise_audit.php', 'Scan keamanan &amp; coverage (perlu TOOLS)'],
        [rmi_icon('gear'), 'ITC Dashboard', $base . '/dashboards/itc/itc_dashboard.php', 'Ringkasan ITC'],
        [rmi_icon('gear'), 'Tools', $base . '/tools/index.php', 'Smoke, backup, QA, ops'],
        [rmi_icon('tower'), 'Sales Control Tower', $base . '/sales/sales_control_tower.php', 'Status DO per tahap'],
        [rmi_icon('target'), 'Import Control Tower', $base . '/purchases/purchases_import_control_tower.php', 'Pipeline import'],
        [rmi_icon('trend'), 'KPI Center', $base . '/kpi/kpi_center.php', 'Metrik &amp; snapshot'],
        [rmi_icon('chart'), 'Exec Summary', $base . '/dashboards/owner/exec_summary.php', 'Ringkasan owner / lintas modul'],
        [rmi_icon('calendar'), 'Backup Schedule', $base . '/tools/backup_schedule.php', 'Jadwal &amp; tes backup'],
        [rmi_icon('check'), 'Health', $base . '/tools/health.php', 'Cek cepat app/DB'],
    ];
    foreach ($links as $L):
        [$icon, $label, $href, $desc] = $L;
    ?>
    <div class="col-md-6 col-lg-4">
      <a class="text-decoration-none d-block rmi-card p-3 h-100 border border-secondary border-opacity-25" href="<?= rmi_h($href) ?>">
        <div class="fw-semibold"><?= rmi_h($icon . ' ' . $label) ?></div>
        <div class="small text-muted mt-1"><?= rmi_h($desc) ?></div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="small text-muted mt-3">
    Panduan lengkap: <a href="<?= rmi_h($base . '/docs/monitoring_guide.php') ?>" target="_blank" rel="noopener">Monitoring &amp; Control (panduan)</a>
    — CLI NAS: <code>php tools/smoke_http.php</code>, <code>php tools/rbac_diff_config_db.php</code>.
  </div>
</div>

<?php rmi_footer(); ?>
