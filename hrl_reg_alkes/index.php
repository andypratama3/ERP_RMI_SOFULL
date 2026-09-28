<?php
// hrl_reg_alkes/index.php — Hub Dashboard Registrasi Alkes
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['HRL.REG_ALKES_VIEW', 'HRL.VIEW', 'PQP.VIEW']);
}

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$pdo = db_pdo();

// ── Summary stats ──────────────────────────────────────────────────────────
$stats = ['open' => 0, 'closed' => 0, 'total' => 0, 'nie_done' => 0];
$stageBreakdown = [];   // stage_no => count
$stageDefs = [
    1  => ['label' => 'PQP cari principal',         'who' => 'PQP',   'color' => '#3b82f6'],
    2  => ['label' => 'PQP quotation & deal',        'who' => 'PQP',   'color' => '#3b82f6'],
    3  => ['label' => 'PQP minta PKS + LOA',         'who' => 'PQP',   'color' => '#3b82f6'],
    4  => ['label' => 'Legal cek PKS/LOA + KBRI',    'who' => 'HRL',   'color' => '#7c3aed'],
    5  => ['label' => 'PQP minta berkas registrasi', 'who' => 'PQP',   'color' => '#3b82f6'],
    6  => ['label' => 'PQP siapkan pendukung',       'who' => 'PQP',   'color' => '#3b82f6'],
    7  => ['label' => 'Legal cek kelengkapan',       'who' => 'HRL',   'color' => '#7c3aed'],
    8  => ['label' => 'Legal OSS / permohonan baru', 'who' => 'HRL',   'color' => '#7c3aed'],
    9  => ['label' => 'Legal ttd manajemen + CDAKB', 'who' => 'HRL',   'color' => '#7c3aed'],
    10 => ['label' => 'Legal isi data + submit',     'who' => 'HRL',   'color' => '#7c3aed'],
    11 => ['label' => 'Revisi diminta (maks 10 hr)', 'who' => 'PQP',   'color' => '#f59e0b'],
    12 => ['label' => 'PQP lengkapi revisi',         'who' => 'PQP',   'color' => '#f59e0b'],
    13 => ['label' => 'Legal submit revisi',         'who' => 'HRL',   'color' => '#f59e0b'],
    14 => ['label' => 'Legal review draft NIE',      'who' => 'HRL',   'color' => '#0d9488'],
    15 => ['label' => 'NIE terbit → GO LIVE',        'who' => 'PQP',   'color' => '#059669'],
];

try {
    // Check tables exist
    $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_cases'");
    $tableExists = (bool)$chk->fetchColumn();
} catch (Throwable $e) {
    $tableExists = false;
}

$recentCases = [];
if ($tableExists) {
    try {
        // Open/Closed counts
        $st = $pdo->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN UPPER(COALESCE(status,'OPEN'))='OPEN' THEN 1 ELSE 0 END) as open_cnt,
            SUM(CASE WHEN UPPER(COALESCE(status,'OPEN'))='CLOSED' THEN 1 ELSE 0 END) as closed_cnt,
            SUM(CASE WHEN nie_no IS NOT NULL AND nie_no <> '' THEN 1 ELSE 0 END) as nie_cnt
            FROM hrl_reg_alkes_cases");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $stats['total']    = (int)($row['total']      ?? 0);
        $stats['open']     = (int)($row['open_cnt']   ?? 0);
        $stats['closed']   = (int)($row['closed_cnt'] ?? 0);
        $stats['nie_done'] = (int)($row['nie_cnt']    ?? 0);
    } catch (Throwable $e) {}

    try {
        $st = $pdo->query("SELECT stage_no, COUNT(*) cnt FROM hrl_reg_alkes_cases
            WHERE UPPER(COALESCE(status,'OPEN'))='OPEN'
            GROUP BY stage_no ORDER BY stage_no");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $stageBreakdown[(int)$r['stage_no']] = (int)$r['cnt'];
        }
    } catch (Throwable $e) {}

    try {
        $st = $pdo->query("SELECT case_code, product_name, manufacture_name, stage_no,
            UPPER(COALESCE(status,'OPEN')) as status_norm, nie_no, updated_at
            FROM hrl_reg_alkes_cases ORDER BY updated_at DESC LIMIT 8");
        $recentCases = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

// Expiry data from JSON log (if exists)
$expiryData = null;
$expiryFile = __DIR__ . '/../storage/logs/reg_alkes_expiry_last.json';
if (file_exists($expiryFile)) {
    $raw = @json_decode((string)file_get_contents($expiryFile), true);
    if (is_array($raw)) $expiryData = $raw;
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('REG Alkes — Dashboard', [
    'active'     => 'hrl_reg_alkes',
    'subtitle'   => 'Registrasi Alat Kesehatan (NIE/AKL/AKD) · Tracking 15 Tahap · PQP & HRL Legal',
    'breadcrumbs' => ['REG Alkes Dashboard'],
    'actions'    => [
        ['label' => '📖 Panduan', 'url' => $baseProject . '/hrl_reg_alkes/panduan.php', 'class' => 'btn-ghost'],
        ['label' => '🗼 Control Tower', 'url' => $baseProject . '/hrl_reg_alkes/reg_alkes_control_tower.php', 'class' => 'btn-soft'],
    ],
]);
?>

<style>
/* ── Summary cards ── */
.ra-summary { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:22px }
@media(max-width:700px){ .ra-summary{ grid-template-columns:repeat(2,1fr) } }
.ra-sum { background:var(--rmi-card,#1a2235); border:1px solid var(--rmi-border,rgba(255,255,255,.1));
    border-radius:12px; padding:16px 18px; display:flex; flex-direction:column; gap:4px }
.ra-sum-num { font-size:30px; font-weight:700; line-height:1 }
.ra-sum-lbl { font-size:11px; color:var(--rmi-muted,#9ca3af); text-transform:uppercase; letter-spacing:.5px }
.ra-sum.open    .ra-sum-num { color:#f59e0b }
.ra-sum.done    .ra-sum-num { color:#10b981 }
.ra-sum.nie     .ra-sum-num { color:#3b82f6 }
.ra-sum.total   .ra-sum-num { color:#a78bfa }

/* ── Module cards ── */
.ra-modules { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:22px }
@media(max-width:800px){ .ra-modules{ grid-template-columns:1fr 1fr } }
@media(max-width:480px){ .ra-modules{ grid-template-columns:1fr } }
.ra-mod { background:var(--rmi-card,#1a2235); border:1px solid var(--rmi-border,rgba(255,255,255,.1));
    border-radius:14px; padding:20px; display:flex; flex-direction:column; gap:10px;
    transition:border-color .15s, box-shadow .15s }
.ra-mod:hover { border-color:rgba(99,102,241,.5); box-shadow:0 4px 20px rgba(0,0,0,.3) }
.ra-mod-ico { font-size:28px; line-height:1 }
.ra-mod-title { font-weight:700; font-size:14px; color:var(--rmi-text,#e8ecf4) }
.ra-mod-desc { font-size:12px; color:var(--rmi-muted,#9ca3af); line-height:1.6; flex:1 }

/* ── Stage pipeline strip ── */
.ra-pipe-wrap { background:var(--rmi-card,#1a2235); border:1px solid var(--rmi-border,rgba(255,255,255,.1));
    border-radius:14px; padding:18px 20px; margin-bottom:22px }
.ra-pipe-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:8px }
@media(max-width:700px){ .ra-pipe-grid{ grid-template-columns:repeat(3,1fr) } }
.ra-stage { border-radius:10px; padding:10px 12px; display:flex; flex-direction:column; gap:4px;
    border:1px solid rgba(255,255,255,.06); position:relative }
.ra-stage-num { font-size:10px; color:var(--rmi-muted,#9ca3af); font-weight:600 }
.ra-stage-bar { height:4px; border-radius:2px; background:rgba(255,255,255,.1); margin:4px 0 }
.ra-stage-bar-fill { height:100%; border-radius:2px; transition:width .3s }
.ra-stage-cnt { font-size:18px; font-weight:700; line-height:1 }
.ra-stage-lbl { font-size:10px; color:var(--rmi-muted,#9ca3af); line-height:1.4 }
.ra-stage-who { font-size:9px; font-weight:600; padding:1px 6px; border-radius:8px;
    display:inline-block; margin-top:2px }
.ra-who-pqp  { background:rgba(59,130,246,.2);  color:#60a5fa }
.ra-who-hrl  { background:rgba(124,58,237,.2);  color:#a78bfa }
.ra-who-revisi{ background:rgba(245,158,11,.2); color:#fbbf24 }
.ra-who-done { background:rgba(5,150,105,.2);   color:#34d399 }

/* ── Recent cases table ── */
.ra-recent { background:var(--rmi-card,#1a2235); border:1px solid var(--rmi-border,rgba(255,255,255,.1));
    border-radius:14px; overflow:hidden }
.ra-recent-head { padding:14px 18px; border-bottom:1px solid var(--rmi-border,rgba(255,255,255,.08));
    font-weight:600; font-size:14px; display:flex; align-items:center; justify-content:space-between }
.ra-tbl { width:100%; border-collapse:collapse }
.ra-tbl th { padding:8px 14px; font-size:11px; font-weight:600; color:var(--rmi-muted,#9ca3af);
    text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid var(--rmi-border,rgba(255,255,255,.08)) }
.ra-tbl td { padding:10px 14px; font-size:13px; border-bottom:1px solid rgba(255,255,255,.04) }
.ra-tbl tr:last-child td { border-bottom:none }
.ra-tbl tr:hover td { background:rgba(255,255,255,.02) }

/* ── Status badge ── */
.ra-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 9px;
    border-radius:20px; font-size:11px; font-weight:600 }
.ra-badge.open   { background:rgba(245,158,11,.15); color:#fbbf24 }
.ra-badge.closed { background:rgba(16,185,129,.15); color:#34d399 }
</style>

<!-- ── Summary Strip ── -->
<div class="ra-summary">
  <div class="ra-sum open">
    <div class="ra-sum-num"><?= $stats['open'] ?></div>
    <div class="ra-sum-lbl">Proses Aktif</div>
  </div>
  <div class="ra-sum done">
    <div class="ra-sum-num"><?= $stats['closed'] ?></div>
    <div class="ra-sum-lbl">Selesai / NIE</div>
  </div>
  <div class="ra-sum nie">
    <div class="ra-sum-num"><?= $stats['nie_done'] ?></div>
    <div class="ra-sum-lbl">Punya Nomor NIE</div>
  </div>
  <div class="ra-sum total">
    <div class="ra-sum-num"><?= $stats['total'] ?></div>
    <div class="ra-sum-lbl">Total Case</div>
  </div>
</div>

<!-- ── Modul Cards ── -->
<div class="ra-modules">

  <div class="ra-mod">
    <div class="ra-mod-ico">🗼</div>
    <div class="ra-mod-title">Control Tower</div>
    <div class="ra-mod-desc">Buat case baru, tracking 15 tahap proses NIE, update stage, dossier per-case, filter & export.</div>
    <a class="btn btn-rmi btn-sm" href="<?= h($baseProject) ?>/hrl_reg_alkes/reg_alkes_control_tower.php">Buka Control Tower →</a>
  </div>

  <div class="ra-mod">
    <div class="ra-mod-ico">📦</div>
    <div class="ra-mod-title">Import SKU → Master Products</div>
    <div class="ra-mod-desc">Upload dossier HRL, preview AKL/AKD CSV/XLSX, import SKU ke master_products secara idempotent.</div>
    <a class="btn btn-soft btn-sm" href="<?= h($baseProject) ?>/hrl_reg_alkes/reg_alkes.php">Buka Import SKU →</a>
  </div>

  <div class="ra-mod">
    <div class="ra-mod-ico">🔍</div>
    <div class="ra-mod-title">SKU by NIE</div>
    <div class="ra-mod-desc">Cari dan tampilkan semua SKU di master_products berdasarkan nomor NIE/AKL/AKD tertentu.</div>
    <a class="btn btn-ghost btn-sm" href="<?= h($baseProject) ?>/hrl_reg_alkes/reg_alkes_sku_by_nie.php">Buka SKU by NIE →</a>
  </div>

  <div class="ra-mod">
    <div class="ra-mod-ico">⏳</div>
    <div class="ra-mod-title">Expiry Check NIE</div>
    <div class="ra-mod-desc">Scan NIE yang akan expired dalam 30/90 hari. Dijalankan via cron atau manual dari sini.
      <?php if ($expiryData): ?>
        <br><span style="color:#f59e0b;font-size:11px">⚠ <?= (int)($expiryData['counts']['expiring_30d'] ?? 0) ?> NIE akan expired ≤30 hari</span>
      <?php endif; ?>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= h($baseProject) ?>/hrl_reg_alkes/reg_alkes_expiry_check.php">Buka Expiry Check →</a>
  </div>

  <div class="ra-mod">
    <div class="ra-mod-ico">📊</div>
    <div class="ra-mod-title">Export Compliance</div>
    <div class="ra-mod-desc">Export laporan kepatuhan Reg Alkes: daftar NIE aktif, status, tanggal terbit, dan pabrikan.</div>
    <a class="btn btn-ghost btn-sm" href="<?= h($baseProject) ?>/hrl_reg_alkes/reg_alkes_export_compliance.php">Buka Export →</a>
  </div>

  <div class="ra-mod" style="border-color:rgba(99,102,241,.25)">
    <div class="ra-mod-ico">📖</div>
    <div class="ra-mod-title">Panduan REG Alkes</div>
    <div class="ra-mod-desc">Panduan lengkap 15 tahap proses registrasi NIE, siapa mengerjakan apa, dan cara pakai sistem.</div>
    <a class="btn btn-ghost btn-sm" href="<?= h($baseProject) ?>/hrl_reg_alkes/panduan.php">Baca Panduan →</a>
  </div>

</div>

<!-- ── Stage Breakdown (Open cases) ── -->
<?php if (!empty($stageBreakdown) || $tableExists): ?>
<?php $maxCnt = max(1, max($stageBreakdown ?: [1])); ?>
<div class="ra-pipe-wrap">
  <div style="font-weight:600;font-size:14px;margin-bottom:4px">Distribusi Case Aktif per Tahap</div>
  <div style="font-size:12px;color:var(--rmi-muted,#9ca3af);margin-bottom:14px">
    Klik tahap untuk membuka Control Tower dengan filter stage tersebut
  </div>
  <div class="ra-pipe-grid">
    <?php for ($sn = 1; $sn <= 15; $sn++):
      $cnt  = $stageBreakdown[$sn] ?? 0;
      $def  = $stageDefs[$sn];
      $pct  = $maxCnt > 0 ? round($cnt / $maxCnt * 100) : 0;
      $who  = $def['who'];
      $whoCls = match($who) {
          'PQP' => 'ra-who-pqp',
          'HRL' => 'ra-who-hrl',
          default => ($sn >= 11 && $sn <= 13) ? 'ra-who-revisi' : 'ra-who-done',
      };
      if ($sn === 15) $whoCls = 'ra-who-done';
    ?>
    <a href="<?= h($baseProject) ?>/hrl_reg_alkes/reg_alkes_control_tower.php?stage=<?= $sn ?>&status=OPEN"
       class="ra-stage" style="text-decoration:none;color:inherit"
       title="<?= h($def['label']) ?>">
      <div class="ra-stage-num">Tahap <?= $sn ?></div>
      <div class="ra-stage-cnt" style="color:<?= h($def['color']) ?>"><?= $cnt ?: '—' ?></div>
      <div class="ra-stage-bar">
        <div class="ra-stage-bar-fill" style="width:<?= $pct ?>%;background:<?= h($def['color']) ?>"></div>
      </div>
      <div class="ra-stage-lbl"><?= h($def['label']) ?></div>
      <span class="ra-stage-who <?= $whoCls ?>"><?= h($who) ?></span>
    </a>
    <?php endfor; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Recent Cases ── -->
<?php if (!empty($recentCases)): ?>
<div class="ra-recent">
  <div class="ra-recent-head">
    <span>📋 Case Terbaru</span>
    <a href="<?= h($baseProject) ?>/hrl_reg_alkes/reg_alkes_control_tower.php" class="btn btn-ghost btn-sm">Semua Case →</a>
  </div>
  <div style="overflow-x:auto">
    <table class="ra-tbl">
      <thead>
        <tr>
          <th>Case Code</th>
          <th>Produk</th>
          <th>Pabrikan</th>
          <th>Tahap</th>
          <th>NIE</th>
          <th>Status</th>
          <th>Update</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentCases as $c):
          $st  = strtoupper((string)($c['status_norm'] ?? 'OPEN'));
          $sn  = (int)($c['stage_no'] ?? 1);
          $def = $stageDefs[$sn] ?? ['label' => "Tahap {$sn}", 'color' => '#9ca3af', 'who' => '?'];
        ?>
        <tr>
          <td class="mono" style="font-size:12px"><?= h($c['case_code'] ?? '—') ?></td>
          <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= h($c['product_name'] ?? '—') ?>
          </td>
          <td style="font-size:12px;color:var(--rmi-muted,#9ca3af)"><?= h($c['manufacture_name'] ?? '—') ?></td>
          <td>
            <span style="font-size:11px;font-weight:600;color:<?= h($def['color']) ?>">
              <?= $sn ?>. <?= h($def['label']) ?>
            </span>
          </td>
          <td class="mono" style="font-size:12px">
            <?= ($c['nie_no'] ?? '') !== '' ? h($c['nie_no']) : '<span style="color:var(--rmi-muted)">—</span>' ?>
          </td>
          <td>
            <span class="ra-badge <?= $st === 'CLOSED' ? 'closed' : 'open' ?>">
              <?= $st === 'CLOSED' ? '✓ Selesai' : '● Aktif' ?>
            </span>
          </td>
          <td class="mono" style="font-size:11px;color:var(--rmi-muted,#9ca3af)">
            <?= h(substr((string)($c['updated_at'] ?? ''), 0, 10)) ?>
          </td>
          <td>
            <a class="btn btn-sm btn-ghost"
               href="<?= h($baseProject) ?>/hrl_reg_alkes/reg_alkes_case.php?case=<?= h($c['case_code'] ?? '') ?>">
              Buka
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif ($tableExists && $stats['total'] === 0): ?>
<div style="background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));
            border-radius:14px;padding:48px;text-align:center;color:var(--rmi-muted,#9ca3af)">
  <div style="font-size:40px;margin-bottom:14px">🗂</div>
  <div style="font-size:15px;font-weight:600;margin-bottom:6px">Belum ada case</div>
  <div style="font-size:13px;margin-bottom:18px">Buat case registrasi pertama di Control Tower.</div>
  <a class="btn btn-rmi btn-sm" href="<?= h($baseProject) ?>/hrl_reg_alkes/reg_alkes_control_tower.php">
    Buka Control Tower →
  </a>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>
