<?php
declare(strict_types=1);
/**
 * tools/view_error_log.php — Error Log Center
 * Menampilkan error log per-modul dalam format kartu yang mudah dibaca.
 * SYS / Admin only.
 */
require_once __DIR__ . '/../master/auth.php';
require_login();

$_level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
$_role  = strtoupper(trim((string)($_SESSION['role'] ?? '')));
if (!in_array($_level, ['SYS','ADMIN','SUPERADMIN'], true) &&
    !in_array($_role,  ['SYS','ADMIN','SUPERADMIN'], true)) {
    http_response_code(403);
    echo '<h3 style="font-family:sans-serif;padding:20px;color:#ef4444">403 — Akses ditolak.</h3>';
    exit;
}

$elPath = __DIR__ . '/../_shared/rmi_error_logger.php';
if (is_file($elPath)) require_once $elPath;

function vel_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$logDir = defined('RMI_ROOT')
    ? rtrim((string)RMI_ROOT, '/\\') . '/storage/logs'
    : dirname(__DIR__) . '/storage/logs';

/** Hitung baris tanpa memuat seluruh file ke memori */
function vel_count_lines(string $path): int {
    if (!is_file($path) || filesize($path) === 0) {
        return 0;
    }
    $fp = @fopen($path, 'rb');
    if (!$fp) {
        return 0;
    }
    $n = 0;
    while (!feof($fp)) {
        $buf = fread($fp, 65536);
        if ($buf === false || $buf === '') {
            break;
        }
        $n += substr_count($buf, "\n");
    }
    fclose($fp);
    return $n;
}

/** Label & grup sidebar — selaras bucket rmi_module_to_log_file */
function vel_bucket_present(): array {
    return [
        'sales'               => ['label' => 'Sales / CRM / DO',           'group' => 'Operasional'],
        'purchases'           => ['label' => 'Purchases / AP / RFQ',        'group' => 'Operasional'],
        'stock'               => ['label' => 'Stock / WQS / PR',          'group' => 'Operasional'],
        'mpr'                 => ['label' => 'MPR',                        'group' => 'Operasional'],
        'hrl'                 => ['label' => 'HRL / process / regulasi',  'group' => 'SDM & legal'],
        'fixed_asset'         => ['label' => 'Fixed Asset',                'group' => 'Aset'],
        'finance'             => ['label' => 'Finance / dashboard',        'group' => 'Keuangan'],
        'payroll'             => ['label' => 'Payroll',                    'group' => 'Keuangan'],
        'master'              => ['label' => 'Master / MFA / Nav',         'group' => 'Sistem'],
        'rbac'                => ['label' => 'RBAC Center',                'group' => 'Sistem'],
        'auth'                => ['label' => 'Login / Auth',               'group' => 'Sistem'],
        'chat'                => ['label' => 'Chat',                       'group' => 'Sistem'],
        'absensi'             => ['label' => 'Absensi',                    'group' => 'SDM & legal'],
        'kpi'                 => ['label' => 'KPI',                        'group' => 'Sistem'],
        'api'                 => ['label' => 'API router',                 'group' => 'Sistem'],
        'tools'               => ['label' => 'Tools / scripts',            'group' => 'Sistem'],
        'dashboards'          => ['label' => 'Dashboards',                 'group' => 'Sistem'],
        'docs'                => ['label' => 'Docs',                       'group' => 'Sistem'],
        'customer_portal'     => ['label' => 'Customer portal',            'group' => 'Portal'],
        'manufacturer_portal' => ['label' => 'Manufacturer portal',        'group' => 'Portal'],
    ];
}

/* ── Auto-discover log files ── */
$allLogs = [];
if (is_dir($logDir)) {
    foreach (glob($logDir . '/*_errors.log') ?: [] as $f) {
        $key = basename($f, '.log');
        $sz  = filesize($f) ?: 0;
        $allLogs[$key] = [
            'path'  => $f,
            'size'  => $sz,
            'mtime' => (int)(filemtime($f) ?: 0),
            'lines' => vel_count_lines($f),
        ];
    }
}

/* Gabungkan bucket terdaftar (modul baru / belum pernah error) */
if (function_exists('rmi_errlog_known_bucket_names')) {
    foreach (rmi_errlog_known_bucket_names() as $bucket) {
        $key = $bucket . '_errors';
        if (isset($allLogs[$key])) {
            continue;
        }
        $path = $logDir . '/' . $key . '.log';
        $exists = is_file($path);
        $allLogs[$key] = [
            'path'  => $path,
            'size'  => $exists ? (filesize($path) ?: 0) : 0,
            'mtime' => $exists ? (int)(filemtime($path) ?: 0) : 0,
            'lines' => $exists ? vel_count_lines($path) : 0,
            'stub'  => true,
        ];
    }
}

if (!empty($allLogs)) {
    uasort($allLogs, static function (array $a, array $b): int {
        $stubA = !empty($a['stub']);
        $stubB = !empty($b['stub']);
        if ($stubA !== $stubB) {
            return $stubA <=> $stubB;
        }
        $ma = (int)($a['mtime'] ?? 0);
        $mb = (int)($b['mtime'] ?? 0);
        if ($ma !== $mb) {
            return $mb <=> $ma;
        }
        return strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
    });
}

$bucketMeta = vel_bucket_present();

$appLogs = [];
foreach (glob($logDir . '/app-*.log') ?: [] as $f) {
    $key = basename($f, '.log');
    $appLogs[$key] = ['path' => $f, 'size' => filesize($f) ?: 0, 'mtime' => (int)(filemtime($f) ?: 0)];
}
uasort($appLogs, fn($a,$b) => $b['mtime'] <=> $a['mtime']);

/* ── Select active file ── */
$fileParam  = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['file'] ?? ''));
$logType    = ($_GET['type'] ?? '') === 'app' ? 'app' : 'module';
$searchQ    = trim((string)($_GET['q'] ?? ''));
$filterSev  = trim((string)($_GET['sev'] ?? ''));   // ERROR | WARN | FATAL | all

$selectedKey  = '';
$selectedFile = '';
if ($logType === 'app') {
    if ($fileParam !== '' && isset($appLogs[$fileParam])) {
        $selectedKey = $fileParam; $selectedFile = $appLogs[$fileParam]['path'];
    } elseif (!empty($appLogs)) {
        $selectedKey = array_key_first($appLogs); $selectedFile = $appLogs[$selectedKey]['path'];
        $fileParam = $selectedKey;
    }
} else {
    if ($fileParam !== '' && isset($allLogs[$fileParam])) {
        $selectedKey = $fileParam; $selectedFile = $allLogs[$fileParam]['path'];
    } elseif (!empty($allLogs)) {
        $selectedKey = array_key_first($allLogs); $selectedFile = $allLogs[$selectedKey]['path'];
        $fileParam = $selectedKey;
    }
}

/* ── Clear action ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = trim((string)($_POST['action'] ?? ''));
    if ($act === 'clear' && $selectedFile && file_exists($selectedFile)) {
        if (!function_exists('verify_csrf') || !verify_csrf()) { http_response_code(403); die('CSRF'); }
        file_put_contents($selectedFile, '');
        rmi_redirect($_SERVER['PHP_SELF'] . '?' . http_build_query([
            'file'=>$fileParam,'type'=>$logType,'cleared'=>1,'q'=>$searchQ,'sev'=>$filterSev
        ]));
    }
}

/* ── Parse a log line into structured parts ── */
function vel_parse_line(string $raw): array
{
    // Format: [YYYY-MM-DD HH:ii:ss] LEVEL ACTION | user=xxx | ip=xxx | message | uri=xxx | {...json}
    $ts = $level = $action = $user = $ip = $uri = $message = '';
    $ctx = [];

    // Timestamp
    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $raw, $m)) {
        $ts  = $m[1];
        $raw = ltrim(substr($raw, strlen($m[0])));
    }

    // Level + Action (first two tokens before pipe)
    $parts = array_map('trim', explode('|', $raw));
    $head  = array_shift($parts) ?? '';
    $tokens = preg_split('/\s+/', $head, 3);
    if (count($tokens) >= 2) {
        $level  = strtoupper($tokens[0]);
        $action = $tokens[1] ?? '';
    } elseif (count($tokens) === 1) {
        $action = $tokens[0];
    }

    // Remaining pipes: key=value or plain message or JSON
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (str_starts_with($part, 'user='))     { $user    = ltrim(substr($part, 5)); continue; }
        if (str_starts_with($part, 'ip='))       { $ip      = ltrim(substr($part, 3)); continue; }
        if (str_starts_with($part, 'uri='))      { $uri     = ltrim(substr($part, 4)); continue; }
        if (str_starts_with($part, '{'))         {
            try { $ctx = json_decode($part, true) ?: []; } catch (\Throwable $e) {}
            continue;
        }
        // Plain message segment
        if ($message === '') $message = $part;
    }

    // Severity class
    $sev = match(true) {
        str_contains(strtoupper($level),'FATAL')                   => 'FATAL',
        in_array(strtoupper($level),['ERROR','ERR'], true),
        str_contains($action,'ERROR') || str_contains($action,'FAILED') => 'ERROR',
        str_contains(strtoupper($level),'WARN')                    => 'WARN',
        default => 'INFO',
    };

    return compact('ts','level','action','user','ip','uri','message','ctx','sev');
}

/* ── Read + filter lines ── */
$rawLines = [];
if ($selectedFile && file_exists($selectedFile) && filesize($selectedFile) > 0) {
    $content  = file_get_contents($selectedFile) ?: '';
    $rawLines = array_reverse(array_filter(explode("\n", $content), fn($l) => trim($l) !== ''));
}

$parsed = [];
foreach ($rawLines as $raw) {
    $p = vel_parse_line($raw);
    if ($searchQ !== '' && !str_contains(strtolower($raw), strtolower($searchQ))) continue;
    if ($filterSev !== '' && $filterSev !== 'all' && strcasecmp($p['sev'], $filterSev) !== 0) continue;
    $parsed[] = $p;
}

/* ── Helpers ── */
$bp = function_exists('rmi_layout_base_project')
    ? rmi_layout_base_project()
    : (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');

function vel_sev_style(string $sev): array {
    return match($sev) {
        'FATAL' => ['#fca5a5','rgba(239,68,68,.15)','#7f1d1d','🔴'],
        'ERROR' => ['#fbbf24','rgba(251,191,36,.12)','#78350f','🟡'],
        'WARN'  => ['#60a5fa','rgba(59,130,246,.12)','#1e3a5f','🔵'],
        default => ['#94a3b8','rgba(148,163,184,.08)','#1e293b','⚪'],
    };
}
function vel_sz(int $b): string {
    if ($b<1024) return $b.'B';
    if ($b<1048576) return round($b/1024,1).'KB';
    return round($b/1048576,1).'MB';
}
function vel_ago(int $ts): string {
    $d = time()-$ts;
    if ($d<60)    return $d.'d lalu';
    if ($d<3600)  return intdiv($d,60).'mnt lalu';
    if ($d<86400) return intdiv($d,3600).'j lalu';
    return intdiv($d,86400).'hr lalu';
}

$totalErrors = array_sum(array_column($allLogs,'lines'));
$sevCounts = array_count_values(array_column($parsed,'sev'));
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Error Log Center — ERP RMI</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;background:#030712;color:#e2e8f0;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;font-size:13px}
a{color:#60a5fa;text-decoration:none}
a:hover{text-decoration:underline}
code,pre,tt{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}

/* Layout 3-panel */
.layout{display:flex;height:100vh;overflow:hidden}
.sidebar{width:220px;flex-shrink:0;background:#080f20;border-right:1px solid #1e293b;display:flex;flex-direction:column;overflow:hidden}
.sidebar-scroll{overflow-y:auto;flex:1}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.topbar{flex-shrink:0;padding:12px 18px;background:#0a1628;border-bottom:1px solid #1e293b;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.filter-bar{flex-shrink:0;padding:8px 18px;background:#050d1c;border-bottom:1px solid #1e293b;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.scroll-area{flex:1;overflow-y:auto;padding:14px 18px 24px}

/* Sidebar items */
.sb-brand{padding:14px 14px 10px;border-bottom:1px solid #1e293b}
.sb-brand-title{font-size:13px;font-weight:800;color:#f1f5f9}
.sb-brand-sub{font-size:10px;color:#475569;margin-top:3px}
.sb-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#334155;padding:10px 12px 5px}
.sb-link{display:flex;align-items:center;gap:7px;padding:6px 12px;border-radius:0;font-size:11.5px;color:#94a3b8;cursor:pointer;transition:all .12s;border:none;background:none;width:100%;text-align:left;font-family:inherit}
.sb-link:hover{background:rgba(255,255,255,.04);color:#e2e8f0}
.sb-link.active{background:rgba(59,130,246,.14);color:#93c5fd;border-left:2px solid #3b82f6}
.sb-link .sb-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sb-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.sb-cnt{font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;flex-shrink:0}
.sb-cnt.has{background:rgba(239,68,68,.2);color:#f87171}
.sb-cnt.ok{background:rgba(34,197,94,.12);color:#4ade80;font-size:10px}
.sb-footer{padding:12px;border-top:1px solid #1e293b;display:flex;flex-direction:column;gap:6px}

/* Topbar */
.tb-title{font-size:14px;font-weight:700;color:#f1f5f9}
.tb-meta{font-size:11px;color:#475569}
.tb-actions{margin-left:auto;display:flex;gap:6px}

/* Filter bar */
.fi-label{font-size:11px;color:#64748b;white-space:nowrap}
.fi-input{background:#0b1a2e;border:1px solid #1e3a5f;border-radius:6px;color:#e2e8f0;padding:4px 10px;font-size:12px;outline:none;font-family:inherit}
.fi-input:focus{border-color:#3b82f6}
.fi-btn{padding:4px 10px;border-radius:6px;border:1px solid;font-size:11px;cursor:pointer;font-family:inherit;font-weight:600;transition:all .1s}
.fi-btn.primary{background:rgba(59,130,246,.15);border-color:#3b82f6;color:#93c5fd}
.fi-btn.danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.35);color:#f87171}
.fi-btn.neutral{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.1);color:#94a3b8}
.sev-chip{padding:3px 10px;border-radius:12px;border:1px solid;font-size:11px;cursor:pointer;font-family:inherit;font-weight:600;transition:all .1s;background:transparent}
.sev-chip:hover,.sev-chip.on{background:rgba(255,255,255,.08)}
.sev-sep{color:#1e293b;font-size:14px}

/* Stats row */
.stats{display:flex;gap:16px;padding:10px 18px 0;flex-wrap:wrap}
.stat-box{background:#0a1628;border:1px solid #1e293b;border-radius:8px;padding:8px 14px;display:flex;align-items:center;gap:8px}
.stat-val{font-size:18px;font-weight:800;line-height:1}
.stat-lbl{font-size:10px;color:#64748b;margin-top:2px}

/* Error cards */
.err-card{border-radius:10px;border:1px solid;margin-bottom:10px;overflow:hidden;transition:border-color .1s}
.err-card:hover{border-color:rgba(255,255,255,.15)!important}
.err-head{display:flex;align-items:flex-start;gap:10px;padding:10px 14px}
.err-icon{font-size:18px;flex-shrink:0;margin-top:1px}
.err-main{flex:1;min-width:0}
.err-action{font-size:12px;font-weight:700;font-family:monospace;margin-bottom:3px}
.err-msg{font-size:13px;color:#e2e8f0;line-height:1.5;word-break:break-word}
.err-meta{font-size:11px;color:#475569;margin-top:6px;display:flex;flex-wrap:wrap;gap:10px}
.err-meta span{display:flex;align-items:center;gap:3px}
.err-ts{flex-shrink:0;text-align:right;min-width:90px}
.err-ts-date{font-size:12px;font-weight:600;color:#94a3b8}
.err-ts-time{font-size:11px;color:#475569;margin-top:1px}
.err-ctx{padding:0 14px 10px}
.err-ctx-toggle{font-size:11px;color:#60a5fa;cursor:pointer;background:none;border:none;font-family:inherit;padding:0;display:flex;align-items:center;gap:4px;margin-bottom:6px}
.err-ctx-toggle:hover{text-decoration:underline}
.err-ctx-body{background:#020912;border:1px solid #1e293b;border-radius:6px;padding:10px 12px;font-family:monospace;font-size:11px;color:#94a3b8;white-space:pre-wrap;word-break:break-all;line-height:1.6}
.err-ctx-body .ctx-key{color:#7dd3fc}
.err-ctx-body .ctx-val{color:#86efac}
.err-ctx-body .ctx-str{color:#fca5a5}
.err-ctx-body .ctx-num{color:#c4b5fd}

/* Empty state */
.empty{text-align:center;padding:60px 24px}
.empty-icon{font-size:48px;margin-bottom:12px}
.empty-title{font-size:16px;font-weight:700;color:#4ade80;margin-bottom:6px}
.empty-sub{font-size:12px;color:#374151}

/* Sev badge inline */
.badge{display:inline-block;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:800;letter-spacing:.04em}

/* Toast/notice */
.notice-ok{background:#052e16;border:1px solid #14532d;color:#4ade80;padding:10px 18px;font-size:12px;display:flex;align-items:center;gap:8px}
</style>
</head>
<body>
<div class="layout">

<!-- ══ SIDEBAR ══════════════════════════════════════════════════ -->
<nav class="sidebar">
  <div class="sb-brand">
    <div class="sb-brand-title">⚠️ Error Log Center</div>
    <div class="sb-brand-sub"><?= count($allLogs) ?> modul · <?= $totalErrors ?> total error</div>
  </div>

  <div class="sidebar-scroll">
    <div class="sb-section-label">Modul / bucket error</div>
    <?php if (empty($allLogs)): ?>
      <div style="padding:8px 12px;color:#374151;font-size:11px">Belum ada log.</div>
    <?php else: ?>
      <?php foreach ($allLogs as $key => $info):
        $hasErr  = $info['lines'] > 0;
        $isActive= ($logType === 'module' && $key === $selectedKey);
        $bucket  = str_replace('_errors', '', $key);
        $label   = $bucketMeta[$bucket]['label'] ?? ucwords(str_replace('_', ' ', $bucket));
        if (!empty($info['stub'])) {
            $label .= ' · belum ada file';
        }
        $url     = '?'.http_build_query(['file'=>$key,'type'=>'module','q'=>$searchQ,'sev'=>$filterSev]);
      ?>
        <a class="sb-link <?= $isActive?'active':'' ?>" href="<?= vel_h($url) ?>" title="<?= vel_h($key . '.log') ?>">
          <span class="sb-dot" style="background:<?= $hasErr?'#ef4444':'#1e3a5f' ?>"></span>
          <span class="sb-name"><?= vel_h($label) ?></span>
          <?php if ($hasErr): ?>
            <span class="sb-cnt has"><?= $info['lines'] ?></span>
          <?php else: ?>
            <span class="sb-cnt ok">✓</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($appLogs)): ?>
      <div class="sb-section-label" style="margin-top:6px;padding-top:10px;border-top:1px solid #1e293b">App Log Harian</div>
      <?php foreach ($appLogs as $key => $info):
        $isActive = ($logType==='app' && $key===$selectedKey);
        $url = '?'.http_build_query(['file'=>$key,'type'=>'app','q'=>$searchQ,'sev'=>$filterSev]);
      ?>
        <a class="sb-link <?= $isActive?'active':'' ?>" href="<?= vel_h($url) ?>">
          <span class="sb-dot" style="background:#1e3a5f"></span>
          <span class="sb-name"><?= vel_h($key) ?></span>
          <span class="sb-cnt ok"><?= vel_sz($info['size']) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="sb-footer">
    <a href="<?= vel_h($bp) ?>/master/audit_logs.php" class="sb-link" style="padding:5px 0">
      📋 Audit Log DB →
    </a>
    <a href="<?= vel_h($bp) ?>/tools/index.php" class="sb-link" style="padding:5px 0">
      🔧 Tools Center →
    </a>
  </div>
</nav>

<!-- ══ MAIN ══════════════════════════════════════════════════════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div>
      <div class="tb-title">
        <?php
          $lbl = '—';
          if ($selectedKey) {
              $buck = str_replace('_errors', '', $selectedKey);
              $lbl = $bucketMeta[$buck]['label'] ?? ucwords(str_replace('_', ' ', $buck));
          }
        ?>
        <?= vel_h($lbl) ?>
        <?php if ($selectedFile && file_exists($selectedFile)): ?>
          <span style="font-size:11px;color:#334155;font-weight:400;margin-left:8px">
            <?= vel_h(vel_sz(filesize($selectedFile)?:0)) ?> · diperbarui <?= vel_h(vel_ago(filemtime($selectedFile)?:time())) ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="tb-meta"><?= count($parsed) ?> entri ditampilkan · terbaru di atas</div>
    </div>
    <div class="tb-actions">
      <?php if ($selectedFile && file_exists($selectedFile) && filesize($selectedFile)>0): ?>
        <form method="post" onsubmit="return confirm('Hapus semua isi log ini?\nTidak bisa dikembalikan.')" style="display:inline">
          <input type="hidden" name="action" value="clear">
          <?php if (function_exists('csrf_token')): ?>
          <input type="hidden" name="csrf_token" value="<?= vel_h(csrf_token()) ?>">
          <?php endif; ?>
          <button class="fi-btn danger">🗑 Clear Log</button>
        </form>
      <?php endif; ?>
      <button class="fi-btn neutral" onclick="location.reload()">↻ Refresh</button>
    </div>
  </div>

  <!-- Filter bar -->
  <form class="filter-bar" method="get">
    <input type="hidden" name="file" value="<?= vel_h($fileParam) ?>">
    <input type="hidden" name="type" value="<?= vel_h($logType) ?>">
    <span class="fi-label">🔍</span>
    <input class="fi-input" style="width:240px" name="q" value="<?= vel_h($searchQ) ?>"
           placeholder="Cari pesan, action, user, IP…">
    <span class="sev-sep">|</span>
    <span class="fi-label">Severity:</span>
    <?php foreach (['all'=>'Semua','FATAL'=>'🔴 Fatal','ERROR'=>'🟡 Error','WARN'=>'🔵 Warn','INFO'=>'⚪ Info'] as $val=>$label): ?>
      <button type="submit" name="sev" value="<?= $val ?>"
              class="sev-chip <?= ($filterSev===''&&$val==='all')||$filterSev===$val?'on':'' ?>"
              style="color:<?= match($val){'FATAL'=>'#f87171','ERROR'=>'#fbbf24','WARN'=>'#60a5fa',default=>'#64748b'} ?>">
        <?= $label ?>
        <?php if ($val !== 'all' && !empty($sevCounts[$val])): ?>
          <span style="font-size:10px;opacity:.7">(<?= $sevCounts[$val] ?>)</span>
        <?php endif; ?>
      </button>
    <?php endforeach; ?>
    <?php if ($searchQ !== '' || ($filterSev !== '' && $filterSev !== 'all')): ?>
      <a href="?file=<?= vel_h($fileParam) ?>&type=<?= vel_h($logType) ?>"
         class="fi-btn neutral" style="font-size:11px">✕ Reset</a>
    <?php endif; ?>
  </form>

  <?php if (isset($_GET['cleared'])): ?>
    <div class="notice-ok">✅ Log berhasil dikosongkan.</div>
  <?php endif; ?>

  <!-- Stats row -->
  <?php if ($logType === 'module' && !empty($allLogs)): ?>
  <div class="stats">
    <div class="stat-box">
      <div><div class="stat-val"><?= count($allLogs) ?></div><div class="stat-lbl">Total Modul</div></div>
    </div>
    <div class="stat-box" style="border-color:rgba(239,68,68,.3)">
      <div><div class="stat-val" style="color:#f87171"><?= count(array_filter($allLogs,fn($l)=>$l['lines']>0)) ?></div><div class="stat-lbl">Ada Error</div></div>
    </div>
    <div class="stat-box" style="border-color:rgba(34,197,94,.2)">
      <div><div class="stat-val" style="color:#4ade80"><?= count(array_filter($allLogs,fn($l)=>$l['lines']===0)) ?></div><div class="stat-lbl">Bersih</div></div>
    </div>
    <?php if (!empty($sevCounts['FATAL'] ?? 0)): ?>
    <div class="stat-box" style="border-color:rgba(239,68,68,.5)">
      <div><div class="stat-val" style="color:#f87171"><?= $sevCounts['FATAL'] ?></div><div class="stat-lbl">Fatal</div></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($sevCounts['ERROR'] ?? 0)): ?>
    <div class="stat-box" style="border-color:rgba(251,191,36,.3)">
      <div><div class="stat-val" style="color:#fbbf24"><?= $sevCounts['ERROR'] ?></div><div class="stat-lbl">Error</div></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($sevCounts['WARN'] ?? 0)): ?>
    <div class="stat-box" style="border-color:rgba(59,130,246,.3)">
      <div><div class="stat-val" style="color:#60a5fa"><?= $sevCounts['WARN'] ?></div><div class="stat-lbl">Warning</div></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Error cards -->
  <div class="scroll-area" style="margin-top:<?= (!empty($allLogs)&&$logType==='module') ? '12px' : '0' ?>">
    <?php if (empty($parsed)): ?>
      <div class="empty">
        <div class="empty-icon"><?= empty($rawLines) ? '✅' : '🔍' ?></div>
        <?php if (empty($rawLines)): ?>
          <div class="empty-title">Log kosong — tidak ada error</div>
          <div class="empty-sub">Semua operasi modul ini berjalan normal.</div>
        <?php else: ?>
          <div class="empty-title" style="color:#fbbf24">Tidak ada hasil</div>
          <div class="empty-sub">Coba ubah filter pencarian.</div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php foreach ($parsed as $i => $p):
        [$clr,$bg,$darkBg,$icon] = vel_sev_style($p['sev']);
        $hasCtx = !empty($p['ctx']);
        $ctxId  = 'ctx_' . $i;
      ?>
        <div class="err-card" style="border-color:<?= $clr ?>33;background:<?= $bg ?>">
          <div class="err-head">
            <span class="err-icon"><?= $icon ?></span>
            <div class="err-main">
              <div class="err-action">
                <span class="badge" style="background:<?= $clr ?>22;color:<?= $clr ?>;border:1px solid <?= $clr ?>44">
                  <?= vel_h($p['sev']) ?>
                </span>
                &nbsp;<span style="color:<?= $clr ?>"><?= vel_h($p['action']) ?></span>
              </div>
              <div class="err-msg"><?= vel_h($p['message']) ?></div>
              <div class="err-meta">
                <?php if ($p['user'] && $p['user'] !== 'guest'): ?>
                  <span>👤 <?= vel_h($p['user']) ?></span>
                <?php endif; ?>
                <?php if ($p['ip']): ?>
                  <span>🌐 <tt><?= vel_h($p['ip']) ?></tt></span>
                <?php endif; ?>
                <?php if ($p['uri']): ?>
                  <span title="<?= vel_h($p['uri']) ?>">📄 <?= vel_h(basename(strtok($p['uri'],'?'))) ?></span>
                <?php endif; ?>
                <?php
                  // Tampilkan konteks singkat (exception class, file:line)
                  if (!empty($p['ctx']['exception_class'])):
                ?>
                  <span style="color:#7c3aed">⚡ <?= vel_h($p['ctx']['exception_class']) ?></span>
                <?php endif; ?>
                <?php if (!empty($p['ctx']['file']) && !empty($p['ctx']['line'])): ?>
                  <span style="color:#475569;font-family:monospace">
                    <?= vel_h(basename((string)$p['ctx']['file'])) ?>:<?= vel_h((string)$p['ctx']['line']) ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
            <div class="err-ts">
              <div class="err-ts-date"><?= vel_h(date('d M Y', strtotime($p['ts']))) ?></div>
              <div class="err-ts-time">⏰ <?= vel_h(date('H:i:s', strtotime($p['ts']))) ?></div>
              <?php if ($p['ts']): ?>
                <div style="font-size:10px;color:#334155;margin-top:2px"><?= vel_h(vel_ago(strtotime($p['ts'])?:time())) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($hasCtx): ?>
            <div class="err-ctx">
              <button class="err-ctx-toggle" onclick="
                var b=document.getElementById('<?= $ctxId ?>');
                b.hidden=!b.hidden;
                this.innerHTML=b.hidden?'▶ Lihat detail konteks':'▼ Sembunyikan';
              ">▶ Lihat detail konteks</button>
              <div id="<?= $ctxId ?>" class="err-ctx-body" hidden><?php
                // Pretty-print JSON dengan warna sederhana
                $json = json_encode($p['ctx'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                // Highlight keys, strings, numbers
                $json = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
                $json = preg_replace('/"([^"]+)":/', '<span class="ctx-key">"$1"</span>:', $json);
                $json = preg_replace('/: "([^"]*)"/', ': <span class="ctx-str">"$1"</span>', $json);
                $json = preg_replace('/: (\d+)/', ': <span class="ctx-num">$1</span>', $json);
                echo $json;
              ?></div>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div><!-- /scroll-area -->
</div><!-- /main -->

</div><!-- /layout -->
</body>
</html>
