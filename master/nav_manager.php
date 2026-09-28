<?php
// master/nav_manager.php — Admin UI: Kelola Sidebar Navigation
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.CONFIG_MANAGE', 'SYSTEM.USER_MANAGE', 'MASTER.ADMIN_CENTER']);
}

/** Mode Advanced (bulk role per menu): hanya akun SYS. */
$nmAdvancedSys = function_exists('auth_is_sys') && auth_is_sys();

$ovFile  = __DIR__ . '/../_shared/nav_overrides.json';
$cfgFile = __DIR__ . '/../_shared/nav_config.php';

$ovWritable = is_writable($ovFile) || (!file_exists($ovFile) && is_writable(dirname($ovFile)));
$ovFixCmd   = 'chmod 664 /volume4/web/ERP_RMI_SOFULL/_shared/nav_overrides.json';
$flash      = '';
$flashOk    = true;

// ── Dept valid (tanpa REG) ────────────────────────────────────────────────
$ALL_DEPTS  = ['CRM','SCM','WQS','PQP','FIN','ACT','HRL','ITC','MPR'];
$ALL_ROLES  = ['CRM','SCM','WQS','PQP','FIN','ACT','HRL','ITC','MPR','BRANCH',
               'MANAGER','STAFF','SYS'];

// ── Landing page presets (halaman tujuan setelah login) ───────────────────
$LANDING_PRESETS = [
    '/master/master_system_login.php'                 => '⚙️ Master System Login (kelola user)',
    '/dashboards/index.php'                           => '🏠 Dashboard Center (semua dept)',
    '/sales/sales_dashboard.php'                      => '💼 CRM — Sales Dashboard',
    '/dashboards/warehouse/wqs_dashboard.php'         => '📦 WQS — Warehouse Dashboard',
    '/purchases/purchases_dashboard.php'              => '🛒 PQP — Purchases Dashboard',
    '/dashboards/finance/ar_ap_cash_dashboard.php'    => '💰 FIN — Finance Dashboard',
    '/dashboards/act/act_dashboard.php'               => '📝 ACT — Accounting Dashboard',
    '/dashboards/hrl/hrl_dashboard.php'               => '👥 HRL — HR & Legal Dashboard',
    '/dashboards/scm/scm_dashboard.php'               => '🚢 SCM — Supply Chain Dashboard',
    '/mpr/mpr_dashboard.php'                          => '🧩 MPR — MPR Dashboard',
    '/dashboards/itc/itc_dashboard.php'               => '💻 ITC — IT Dashboard',
    '/dashboards/branch/branch_dashboard.php'         => '🏢 BRANCH — Branch Dashboard',
    '/dashboards/quality/qc_complaint_dashboard.php'  => '🔬 Quality Dashboard',
    '/dashboards/owner/exec_summary.php'              => '👑 Executive Summary',
    '/kpi/kpi_center.php'                             => '📊 KPI Center',
    '/absensi/index.php'                              => '🕐 Absensi',
];

// ── Helpers (HARUS di atas sebelum dipanggil) ─────────────────────────────
function nm_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
function nm_normalize(string $r): string {
    $r = strtoupper(trim($r));
    if ($r === 'ALL' || $r === '') return $r;
    $p = array_filter(array_unique(array_map('trim', explode(',', $r))));
    sort($p);
    return implode(',', $p);
}
function nm_has_role(string $roles, string $dept): bool {
    $roles = strtoupper(trim($roles));
    if ($roles === 'ALL') return true;
    return in_array(strtoupper($dept), array_map('trim', explode(',', $roles)), true);
}
function nm_add_role(string $roles, string $dept): string {
    if (strtoupper(trim($roles)) === 'ALL') return 'ALL';
    $p = array_filter(array_unique(array_map('trim', explode(',', strtoupper($roles)))));
    $p[] = strtoupper($dept);
    sort($p);
    return implode(',', array_unique($p));
}
function nm_remove_role(string $roles, string $dept): string {
    global $ALL_ROLES;
    if (strtoupper(trim($roles)) === 'ALL') {
        $p = array_values(array_diff($ALL_ROLES, [strtoupper($dept)]));
        sort($p);
        return implode(',', $p);
    }
    $p = array_filter(
        array_map('trim', explode(',', strtoupper($roles))),
        fn($r) => $r !== strtoupper($dept) && $r !== ''
    );
    sort($p);
    return implode(',', $p);
}
function nm_default_roles(array $cfg, string $variant, string $key): string {
    foreach ($cfg[$variant] ?? [] as $it) {
        if (($it['key'] ?? null) === $key) return $it['roles'] ?? 'ALL';
    }
    return 'ALL';
}
function nm_merged(array $items, array $ov): array {
    $out = [];
    foreach ($items as $it) {
        if (!empty($it['section'])) { $out[] = $it; continue; }
        $k = $it['key'] ?? null;
        if ($k && isset($ov[$k])) {
            if (isset($ov[$k]['roles']))  $it['roles']   = $ov[$k]['roles'];
            if (isset($ov[$k]['hidden'])) $it['_hidden'] = (bool)$ov[$k]['hidden'];
        }
        $out[] = $it;
    }
    return $out;
}

// Helper render toggle table — definisi DI ATAS sebelum dipanggil
function nm_render_toggle_table(array $items, string $dept, array $ovVariant): string {
    $out  = '<div style="overflow-x:auto">';
    $out .= '<table class="nm-tbl">';
    $out .= '<thead><tr>';
    $out .= '<th style="width:38px"></th>';
    $out .= '<th>Menu</th>';
    $out .= '<th style="width:90px;text-align:center">Tampil?</th>';
    $out .= '<th style="min-width:160px">Dept yang bisa lihat</th>';
    $out .= '</tr></thead><tbody>';

    foreach ($items as $it) {
        if (!empty($it['section'])) {
            $out .= '<tr class="nm-sect-row"><td colspan="4">' . nm_h($it['section']) . '</td></tr>';
            continue;
        }
        // Setiap item wajib punya 'key' (nav_config.php sudah dijamin punya key)
        $k = $it['key'] ?? null;
        if (!$k) continue; // item tidak valid, skip

        $roles  = $it['roles'] ?? 'ALL';
        $hidden = !empty($it['_hidden']);
        $sees   = nm_has_role($roles, $dept);
        $hasOv  = isset($ovVariant[$k]);
        // Tampilkan URL jika ada (informatif)
        $urlHint = isset($it['url']) ? str_replace('{base}', '...', (string)$it['url']) : '';

        $rowCls = $hidden ? 'nm-hidden-row' : '';
        $out .= '<tr class="' . $rowCls . '">';
        $out .= '<td class="nm-icon">' . ($it['icon'] ?? '') . '</td>';
        $out .= '<td>';
        $out .= '<div class="nm-menu-label">' . nm_h($it['label'] ?? $k) . '</div>';
        $out .= '<div class="nm-menu-key">' . nm_h($k) . '</div>';
        if ($urlHint) $out .= '<div style="font-size:10px;color:#475569;margin-top:2px">' . nm_h($urlHint) . '</div>';
        if ($hasOv)   $out .= '<span class="nm-pill nm-pill-ov">override</span>';
        if ($hidden)  $out .= '<span class="nm-pill nm-pill-hidden">hidden</span>';
        $out .= '</td>';

        // Toggle
        $out .= '<td style="text-align:center">';
        $out .= '<div class="nm-toggle-wrap">';
        $out .= '<label class="nm-toggle">';
        $out .= '<input type="checkbox" name="visible[' . nm_h($k) . ']" value="1"'
                . ($sees ? ' checked' : '') . ' class="nm-vis-chk">';
        $out .= '<span class="nm-slider"></span>';
        $out .= '</label>';
        $out .= '<span class="nm-vis-lbl ' . ($sees ? 'on' : 'off') . '">'
                . ($sees ? 'YA' : 'Tidak') . '</span>';
        $out .= '</div></td>';

        // Roles list
        $out .= '<td><div class="nm-roles-wrap">';
        if (strtoupper(trim($roles)) === 'ALL') {
            $out .= '<span class="nm-pill nm-pill-all">SEMUA</span>';
        } else {
            $parts = array_filter(array_map('trim', explode(',', strtoupper($roles))));
            foreach ($parts as $r) {
                $cls = ($r === strtoupper($dept)) ? 'nm-pill-me' : 'nm-pill-dept';
                $out .= '<span class="nm-pill ' . $cls . '">' . nm_h($r) . '</span>';
            }
        }
        $out .= '</div></td></tr>';
    }
    $out .= '</tbody></table></div>';
    return $out;
}

// ── Load config & overrides ───────────────────────────────────────────────
$navCfg = require $cfgFile;  // returns ['default'=>[...], 'branch'=>[...]]
$navOv  = ['default' => [], 'branch' => [], 'landing_pages' => []];
if (is_file($ovFile)) {
    $dec = json_decode((string)file_get_contents($ovFile), true);
    if (is_array($dec)) {
        $navOv['default']       = is_array($dec['default']       ?? null) ? $dec['default']       : [];
        $navOv['branch']        = is_array($dec['branch']        ?? null) ? $dec['branch']         : [];
        $navOv['landing_pages'] = is_array($dec['landing_pages'] ?? null) ? $dec['landing_pages']  : [];
    }
}

// Landing page defaults (dari auth.php)
$landingDefaults = function_exists('auth_landing_defaults') ? auth_landing_defaults() : [];

// Merge depts: all depts + BRANCH + SYS untuk loop landing pages
$ALL_DEPTS_FULL = array_merge($ALL_DEPTS, ['BRANCH', 'SYS']);

// ── POST: Simpan ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) verify_csrf();

    $saveMode = $_POST['save_mode'] ?? 'dept';

    if ($saveMode === 'advanced' && !$nmAdvancedSys) {
        http_response_code(403);
        echo 'Forbidden: Mode Advanced Nav Manager hanya untuk akun SYS.';
        exit;
    }

    // ── LANDING PAGE SAVE ───────────────────────────────────────────────
    if ($saveMode === 'landing_pages') {
        $newOv = $navOv;
        $newOv['landing_pages'] = [];
        foreach ($ALL_DEPTS_FULL as $d) {
            $url = trim((string)($_POST['landing'][$d] ?? ''));
            // Kalau custom dipilih, pakai custom_url field
            if ($url === '__custom__') {
                $url = trim((string)($_POST['landing_custom'][$d] ?? ''));
            }
            // Validasi: path aman (sama seperti auth_landing_override_url_safe)
            if ($url !== '' && function_exists('auth_landing_override_url_safe') && auth_landing_override_url_safe($url)) {
                $default = $landingDefaults[$d] ?? '/dashboards/index.php';
                if ($url !== $default) {
                    $newOv['landing_pages'][$d] = [
                        'url'   => $url,
                        'label' => ($LANDING_PRESETS[$url] ?? 'Custom'),
                    ];
                }
            }
        }
        $newOv['_info'] = 'Dikelola oleh master/nav_manager.php.';
        if (!$ovWritable) {
            $flash   = "File tidak bisa ditulis. SSH ke NAS dan jalankan:\n  {$ovFixCmd}";
            $flashOk = false;
        } elseif (file_put_contents($ovFile, json_encode($newOv, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            $navOv   = $newOv;
            $flash   = '✓ Landing page tersimpan.';
            $flashOk = true;
        } else {
            $flash = "Gagal menulis file."; $flashOk = false;
        }
        // Stay on landing tab; jangan kirim saved=1 saat gagal (bug: $flash error juga truthy)
        rmi_redirect('nav_manager.php?tab=landing' . ($flashOk ? '&saved=1' : '&error=1'));
    }

    $postDept = strtoupper(trim((string)($_POST['dept'] ?? 'BRANCH')));
    // BRANCH sekarang menggunakan variant 'default' — sama seperti dept lain.
    // Branch variant lama (bukan 'default') hanya dipakai jika di-override eksplisit.
    $variant  = 'default';
    if (isset($_POST['variant']) && in_array($_POST['variant'], ['default','branch'], true)) {
        $variant = $_POST['variant']; // explicit override jika perlu
    }
    $newOv    = $navOv;

    if ($saveMode === 'dept') {
        $dept   = strtoupper(trim((string)($_POST['dept'] ?? '')));
        $merged = nm_merged($navCfg[$variant] ?? [], $navOv[$variant] ?? []);
        foreach ($merged as $it) {
            $k = $it['key'] ?? null;
            if (!$k || !$dept) continue;
            $cur       = $it['roles'] ?? 'ALL';
            $shouldSee = !empty($_POST['visible'][$k]);
            $curSees   = nm_has_role($cur, $dept);
            if ($shouldSee && !$curSees)    $cur = nm_add_role($cur, $dept);
            elseif (!$shouldSee && $curSees) $cur = nm_remove_role($cur, $dept);
            $def = nm_normalize(nm_default_roles($navCfg, $variant, $k));
            if (nm_normalize($cur) !== $def) {
                $newOv[$variant][$k] = [
                    'roles'  => nm_normalize($cur),
                    'hidden' => (bool)($newOv[$variant][$k]['hidden'] ?? false),
                ];
            } else {
                unset($newOv[$variant][$k]);
            }
        }
    } else {
        // Advanced: per menu, semua roles
        foreach ($navCfg[$variant] ?? [] as $it) {
            if (!empty($it['section'])) continue;
            $k = $it['key'] ?? null; if (!$k) continue;
            $checked = (array)($_POST['roles'][$k] ?? []);
            $roles   = in_array('ALL', $checked, true)
                       ? 'ALL'
                       : implode(',', array_filter(array_map('strtoupper', $checked)));
            $hidden  = !empty($_POST['hidden'][$k]);
            $def     = nm_normalize($it['roles'] ?? 'ALL');
            if (nm_normalize($roles) !== $def || $hidden) {
                $newOv[$variant][$k] = ['roles' => nm_normalize($roles), 'hidden' => $hidden];
            } else {
                unset($newOv[$variant][$k]);
            }
        }
    }

    $newOv['_info'] = 'Dikelola oleh master/nav_manager.php.';
    if (!$ovWritable) {
        $flash = "File tidak bisa ditulis. SSH ke NAS dan jalankan:\n  {$ovFixCmd}";
        $flashOk = false;
    } elseif (file_put_contents($ovFile, json_encode($newOv, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
        $navOv   = $newOv;
        $flash   = '✓ Tersimpan.';
        $flashOk = true;
        // Audit: perubahan konfigurasi sidebar adalah perubahan sistem yang penting
        try {
            require_once __DIR__ . '/../master/_audit_master.php';
            require_once __DIR__ . '/../_shared/db.php';
            $__pdo = function_exists('db_pdo') ? db_pdo() : null;
            if ($__pdo && function_exists('master_audit')) {
                $__actor = (string)($_SESSION['username'] ?? 'system');
                $__dept  = (string)($postDept ?? 'ALL');
                $__mode  = (string)($saveMode ?? 'dept');
                master_audit($__pdo, 'nav_manager', 'nav_overrides.json', 'SAVE_NAV_CONFIG',
                    null, "NAV/{$__dept}", "Nav Manager saved: dept={$__dept} mode={$__mode} by {$__actor}",
                    ['dept' => $__dept, 'save_mode' => $__mode, 'actor' => $__actor]);
            }
        } catch (Throwable $__e) { /* fail-soft */ }
    } else {
        $flash = "Gagal menulis file."; $flashOk = false;
    }
}

// ── GET: Reset ────────────────────────────────────────────────────────────
if (isset($_GET['reset']) && in_array($_GET['reset'], ['default','branch'], true)) {
    $v = $_GET['reset'];
    $navOv[$v] = [];
    $navOv['_info'] = 'Dikelola oleh master/nav_manager.php.';
    file_put_contents($ovFile, json_encode($navOv, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $resetDept = ($_GET['dept'] ?? ($v === 'branch' ? 'BRANCH' : 'CRM'));
    rmi_redirect('nav_manager.php?dept=' . urlencode((string)$resetDept));
}

require_once __DIR__ . '/../_shared/rmi_layout.php';

// ── Pre-build semua merged items per dept (untuk sidebar preview & toggle) ─
// Semua dept (termasuk BRANCH) menggunakan 'default' variant.
$allDeptItems = [];
foreach (array_merge($ALL_DEPTS, ['BRANCH']) as $_d) {
    $allDeptItems[$_d] = nm_merged($navCfg['default'] ?? [], $navOv['default'] ?? []);
}

// Active tab (nav | landing)
$selTab  = in_array($_GET['tab'] ?? 'nav', ['nav','landing'], true) ? ($_GET['tab'] ?? 'nav') : 'nav';

// Flash setelah redirect landing save / error
if (isset($_GET['saved']) && $selTab === 'landing') {
    $flash = '✓ Landing page tersimpan.'; $flashOk = true;
}
if (isset($_GET['error']) && $selTab === 'landing') {
    $flash = 'Gagal menyimpan landing page (file tidak bisa ditulis atau penulisan gagal). Periksa hak tulis ke _shared/nav_overrides.json.';
    $flashOk = false;
}

// Selected dept dari URL
$selDept = strtoupper(trim((string)($_GET['dept'] ?? 'BRANCH')));
if (!in_array($selDept, array_merge($ALL_DEPTS, ['BRANCH','SYS']), true)) $selDept = 'BRANCH';
// BRANCH sekarang menggunakan 'default' variant — bisa dikonfigurasi seperti dept lain.
$selVariant = 'default';

$mergedItems = $allDeptItems[$selDept] ?? nm_merged($navCfg[$selVariant] ?? [], $navOv[$selVariant] ?? []);
$ovCount     = count(array_filter(array_keys($navOv[$selVariant] ?? []), fn($k) => $k !== '_info'));

if (!empty($_GET['advanced']) && !$nmAdvancedSys) {
    rmi_redirect('nav_manager.php?dept=' . rawurlencode($selDept));
}
$isAdvanced = !empty($_GET['advanced']) && $nmAdvancedSys;

// Dept meta (warna & ikon)
$deptMeta = [
    'CRM'         => ['color' => '#3b82f6', 'icon' => '💼', 'label' => 'CRM'],
    'SCM'         => ['color' => '#a78bfa', 'icon' => '🚢', 'label' => 'SCM'],
    'WQS'         => ['color' => '#f97316', 'icon' => '📦', 'label' => 'WQS'],
    'PQP'         => ['color' => '#eab308', 'icon' => '🛒', 'label' => 'PQP'],
    'FIN'         => ['color' => '#22c55e', 'icon' => '💰', 'label' => 'FIN'],
    'ACT'         => ['color' => '#fb923c', 'icon' => '📝', 'label' => 'ACT'],
    'HRL'         => ['color' => '#ec4899', 'icon' => '👥', 'label' => 'HRL'],
    'ITC'         => ['color' => '#64748b', 'icon' => '💻', 'label' => 'ITC'],
    'MPR'         => ['color' => '#14b8a6', 'icon' => '🧩', 'label' => 'MPR'],
    'BRANCH'      => ['color' => '#10b981', 'icon' => '🏢', 'label' => 'BRANCH'],
    'SYS'         => ['color' => '#f59e0b', 'icon' => '⚙️', 'label' => 'SYS'],
];

rmi_header('Nav Manager', [
    'active'      => 'nav_manager',
    'subtitle'    => 'Atur menu sidebar per departemen — server-side filtered',
    'breadcrumbs' => [['label' => 'Master Data', 'url' => 'index.php'], 'Nav Manager'],
    'actions'     => [
        ['label' => '📋 nav_config.php', 'url' => '#', 'class' => 'btn btn-sm btn-ghost'],
    ],
]);
?>

<style>
/* ── Dept tab bar ─────────────────────────────────────────────────────── */
.nm-tabbar { display:flex; align-items:center; gap:6px; flex-wrap:wrap;
    margin-bottom:16px; padding:10px 14px;
    background:var(--rmi-card,#1a2235); border:1px solid var(--rmi-border,rgba(255,255,255,.08));
    border-radius:12px }
.nm-tab { display:inline-flex; align-items:center; gap:6px; padding:6px 14px;
    border-radius:20px; cursor:pointer; text-decoration:none;
    color:var(--rmi-text,#e8ecf4); font-size:12px; font-weight:600;
    border:1.5px solid rgba(255,255,255,.1); transition:all .12s;
    background:rgba(255,255,255,.04) }
.nm-tab:hover { border-color:rgba(255,255,255,.25); color:#fff; background:rgba(255,255,255,.07) }
.nm-tab.active { color:#fff; font-weight:700 }
.nm-tab .t-ov { font-size:10px; padding:1px 6px; border-radius:10px;
    background:rgba(245,158,11,.25); color:#fbbf24; border:1px solid rgba(245,158,11,.35) }
.nm-tab-sep { width:1px; height:22px; background:rgba(255,255,255,.12); margin:0 4px }

/* ── Content topbar ────────────────────────────────────────────────────── */
.nm-topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;
    gap:10px; margin-bottom:14px; padding:12px 16px;
    background:var(--rmi-card,#1a2235); border:1px solid var(--rmi-border,rgba(255,255,255,.08));
    border-radius:12px }
.nm-topbar-left { display:flex; align-items:center; gap:10px; flex-wrap:wrap }
.nm-dept-badge { display:inline-flex; align-items:center; gap:6px; padding:5px 14px;
    border-radius:20px; font-weight:700; font-size:13px; border:1.5px solid }

/* ── Card ─────────────────────────────────────────────────────────────── */
.nm-card { background:var(--rmi-card,#1a2235); border:1px solid var(--rmi-border,rgba(255,255,255,.08));
    border-radius:14px; overflow:hidden }

/* ── Toggle table ─────────────────────────────────────────────────────── */
.nm-tbl { width:100%; border-collapse:collapse; font-size:13px }
.nm-tbl th { padding:8px 14px; font-size:10px; font-weight:700; color:var(--rmi-muted,#9ca3af);
    text-transform:uppercase; letter-spacing:.5px;
    border-bottom:1px solid var(--rmi-border,rgba(255,255,255,.08)); text-align:left }
.nm-tbl td { padding:9px 14px; border-bottom:1px solid rgba(255,255,255,.04) }
.nm-tbl tr:last-child td { border-bottom:none }
.nm-tbl tr:hover td { background:rgba(255,255,255,.02) }
.nm-sect-row td { padding:5px 14px!important; font-size:10px; font-weight:700; letter-spacing:.08em;
    color:var(--rmi-muted,#9ca3af); background:rgba(255,255,255,.03)!important;
    text-transform:uppercase; border-top:1px solid rgba(255,255,255,.06)!important }
.nm-icon  { width:36px; text-align:center; font-size:15px }
.nm-menu-label { font-weight:600; font-size:13px }
.nm-menu-key   { font-size:10px; color:var(--rmi-muted,#9ca3af); font-family:monospace; margin-top:1px }
.nm-menu-url   { font-size:10px; color:#334155; margin-top:1px; font-family:monospace }
.nm-hidden-row td { opacity:.3 }

/* ── Toggle switch ─────────────────────────────────────────────────────── */
.nm-toggle-wrap { display:flex; align-items:center; justify-content:center; gap:8px }
.nm-toggle { position:relative; width:46px; height:26px; display:inline-block; flex-shrink:0 }
.nm-toggle input { opacity:0; width:0; height:0; position:absolute }
.nm-slider { position:absolute; inset:0; border-radius:26px; cursor:pointer;
    background:rgba(255,255,255,.08); border:1.5px solid rgba(255,255,255,.1); transition:.2s }
.nm-slider:before { content:''; position:absolute; height:18px; width:18px;
    left:3px; bottom:3px; border-radius:50%; background:#475569; transition:.2s }
.nm-toggle input:checked + .nm-slider { background:#22c55e; border-color:#16a34a }
.nm-toggle input:checked + .nm-slider:before { transform:translateX(20px); background:#fff }
.nm-vis-lbl { font-size:11px; font-weight:700; min-width:34px; text-align:left }
.nm-vis-lbl.on  { color:#4ade80 }
.nm-vis-lbl.off { color:#475569 }

/* ── Pill badges ───────────────────────────────────────────────────────── */
.nm-pill       { display:inline-block; padding:2px 7px; border-radius:20px;
    font-size:10px; font-weight:600; margin:1px }
.nm-pill-all    { background:rgba(34,197,94,.12);  color:#86efac; border:1px solid rgba(34,197,94,.2) }
.nm-pill-me     { background:rgba(99,102,241,.25); color:#c7d2fe; border:1px solid #6366f1 }
.nm-pill-dept   { background:rgba(255,255,255,.07); color:var(--rmi-muted,#9ca3af) }
.nm-pill-ov     { background:rgba(245,158,11,.12); color:#fbbf24; border:1px solid rgba(245,158,11,.25) }
.nm-pill-hidden { background:rgba(239,68,68,.12);  color:#f87171; border:1px solid rgba(239,68,68,.2) }
.nm-roles-wrap  { display:flex; flex-wrap:wrap; gap:2px }

/* ── Info box ──────────────────────────────────────────────────────────── */
.nm-admin-info { text-align:center; padding:40px 24px }
.nm-admin-info .ai-icon { font-size:3rem; margin-bottom:12px }
.nm-admin-info .ai-title { font-size:16px; font-weight:700 }
.nm-admin-info .ai-desc  { font-size:13px; color:var(--rmi-muted,#9ca3af); margin-top:8px;
    max-width:380px; margin-left:auto; margin-right:auto; line-height:1.6 }

/* ── Override count badge ──────────────────────────────────────────────── */
.nm-ov-badge { background:rgba(245,158,11,.15); color:#fbbf24;
    border:1px solid rgba(245,158,11,.3); border-radius:20px;
    padding:2px 10px; font-size:11px; font-weight:600 }

/* ── Landing page manager ──────────────────────────────────────────────── */
.lp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px}
.lp-card{background:rgba(255,255,255,.04);border:1px solid var(--rmi-border,rgba(255,255,255,.08));
    border-radius:12px;padding:14px 16px;transition:.15s}
.lp-card:hover{border-color:rgba(6,182,212,.25)}
.lp-card.lp-custom{border-color:rgba(245,158,11,.3);background:rgba(245,158,11,.04)}
.lp-card-head{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.lp-dept-badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
.lp-current{font-size:11px;color:var(--rmi-muted,#94a3b8);margin-top:4px;font-family:monospace}
.lp-select{width:100%;background:rgba(255,255,255,.05)!important;
    border:1px solid rgba(255,255,255,.12)!important;color:#e2e8f0!important;
    border-radius:8px;padding:6px 10px;font-size:12px;cursor:pointer}
.lp-custom-url{width:100%;margin-top:6px;background:rgba(255,255,255,.05)!important;
    border:1px solid rgba(245,158,11,.3)!important;color:#e2e8f0!important;
    border-radius:8px;padding:6px 10px;font-size:12px;font-family:monospace}
.lp-badge-default{background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.2);
    padding:1px 7px;border-radius:6px;font-size:10px;font-weight:700}
.lp-badge-custom{background:rgba(245,158,11,.15);color:#fbbf24;border:1px solid rgba(245,158,11,.3);
    padding:1px 7px;border-radius:6px;font-size:10px;font-weight:700}
/* ── Advanced mode ─────────────────────────────────────────────────────── */
.nm-role-lbl { display:inline-flex; align-items:center; gap:4px; padding:3px 10px;
    border-radius:20px; cursor:pointer; border:1px solid rgba(255,255,255,.12);
    font-size:11px; font-weight:500; background:rgba(255,255,255,.04);
    transition:all .12s; user-select:none; margin:2px }
.nm-role-lbl input { display:none }
.nm-role-lbl.on     { background:rgba(99,102,241,.2);  border-color:#6366f1; color:#c7d2fe }
.nm-role-lbl.on-all { background:rgba(34,197,94,.15);  border-color:#22c55e; color:#86efac }
</style>

<?php if (!$ovWritable): ?>
<div class="alert alert-danger mb-3">
  <strong>⚠️ File tidak bisa ditulis.</strong> SSH ke NAS dan jalankan:
  <code style="display:block;margin-top:6px;user-select:all;background:rgba(0,0,0,.3);padding:4px 10px;border-radius:6px"><?= rmi_h($ovFixCmd) ?></code>
</div>
<?php endif; ?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flashOk ? 'success' : 'danger' ?> alert-dismissible fade show mb-3 py-2">
  <?= rmi_h($flash) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ══ MODE TAB (Nav | Landing) ══ -->
<div style="display:flex;gap:8px;margin-bottom:12px;align-items:center">
  <a href="?tab=nav&dept=<?= rmi_h($selDept) ?>"
     class="btn btn-sm <?= $selTab==='nav' ? 'btn-rmi' : 'btn-ghost' ?>"
     style="font-size:12px">🗂️ Sidebar Menu</a>
  <a href="?tab=landing"
     class="btn btn-sm <?= $selTab==='landing' ? 'btn-rmi' : 'btn-ghost' ?>"
     style="font-size:12px">
    🏠 Landing Page
    <?php $lpCustomCount = count(array_filter($navOv['landing_pages'] ?? [], fn($v)=>!empty($v['url']))); ?>
    <?php if ($lpCustomCount > 0): ?>
      <span style="font-size:10px;padding:1px 6px;border-radius:10px;background:rgba(245,158,11,.25);color:#fbbf24;border:1px solid rgba(245,158,11,.35);margin-left:4px"><?= $lpCustomCount ?></span>
    <?php endif; ?>
  </a>
</div>

<?php if ($selTab === 'nav'): ?>
<!-- ══ TAB BAR: Dept Selector ══ -->
<div class="nm-tabbar">
  <span style="font-size:11px;font-weight:700;color:var(--rmi-muted,#9ca3af);text-transform:uppercase;letter-spacing:.05em;white-space:nowrap">Dept:</span>
  <?php
  // ADMIN tab
  $m = $deptMeta['SYS'];
  $isActive = ($selDept === 'SYS');
  echo '<a href="?dept=SYS" class="nm-tab' . ($isActive ? ' active' : '') . '"'
     . ' style="' . ($isActive ? 'border-color:' . $m['color'] . ';color:' . $m['color'] . ';background:' . $m['color'] . '15' : '') . '">'
     . $m['icon'] . ' ' . $m['label']
     . '</a>';

  echo '<div class="nm-tab-sep"></div>';

  // Dept tabs
  foreach ($ALL_DEPTS as $d):
      $m  = $deptMeta[$d] ?? ['color'=>'#94a3b8','icon'=>'🏢','label'=>$d];
      $isActive = ($selDept === $d);
      $ov = $navOv['default'];
      $dOvCount = 0;
      foreach (($allDeptItems[$d] ?? []) as $it) {
          if (!empty($it['section'])) continue;
          $k = $it['key'] ?? null;
          if ($k && isset($ov[$k])) $dOvCount++;
      }
      echo '<a href="?dept=' . $d . '" class="nm-tab' . ($isActive ? ' active' : '') . '"'
         . ' style="' . ($isActive ? 'border-color:' . $m['color'] . ';color:' . $m['color'] . ';background:' . $m['color'] . '15' : '') . '">'
         . $m['icon'] . ' ' . $m['label'];
      if ($dOvCount > 0) echo ' <span class="t-ov">' . $dOvCount . '</span>';
      echo '</a>';
  endforeach;

  echo '<div class="nm-tab-sep"></div>';

  // BRANCH tab
  $m = $deptMeta['BRANCH'];
  $isActive = ($selDept === 'BRANCH');
  // BRANCH sekarang pakai default variant — hitung override dari default
  $bOvCount = 0;
  foreach (($allDeptItems['BRANCH'] ?? []) as $_bit) {
      if (!empty($_bit['section'])) continue;
      $_bk = $_bit['key'] ?? null;
      if ($_bk && isset($navOv['default'][$_bk])) $bOvCount++;
  }
  echo '<a href="?dept=BRANCH" class="nm-tab' . ($isActive ? ' active' : '') . '"'
     . ' style="' . ($isActive ? 'border-color:' . $m['color'] . ';color:' . $m['color'] . ';background:' . $m['color'] . '15' : '') . '">'
     . $m['icon'] . ' ' . $m['label'];
  if ($bOvCount > 0) echo ' <span class="t-ov">' . $bOvCount . '</span>';
  echo '</a>';

  // Reset link if needed
  if ($ovCount > 0):
  ?>
    <div style="margin-left:auto">
      <a href="?tab=nav&reset=<?= $selVariant ?>&dept=<?= rmi_h($selDept) ?>"
         style="font-size:11px;color:#f87171;text-decoration:none"
         onclick="return confirm('Reset SEMUA <?= $ovCount ?> override sidebar <?= $selVariant === "branch" ? "Branch" : "Standar" ?> ke default? Perubahan semua dept di sidebar ini akan hilang.')">
        ↩ Reset sidebar <?= $selVariant === 'branch' ? 'Branch' : 'Standar' ?> (<?= $ovCount ?> override)
      </a>
    </div>
  <?php endif; ?>
</div>

<!-- ══ CONTENT PANEL ══ -->
<div>

    <?php
    $m = $deptMeta[$selDept] ?? ['color'=>'#94a3b8','icon'=>'🏢','label'=>$selDept];
    ?>

    <!-- Topbar -->
    <div class="nm-topbar">
      <div class="nm-topbar-left">
        <span class="nm-dept-badge"
              style="color:<?= $m['color'] ?>;border-color:<?= $m['color'] ?>33;background:<?= $m['color'] ?>11">
          <?= $m['icon'] ?> <?= rmi_h($m['label']) ?>
        </span>
        <?php if ($selDept === 'SYS'): ?>
          <span style="font-size:12px;color:var(--rmi-muted,#9ca3af)"><?= $isAdvanced
            ? 'Mode Advanced — mengatur visibilitas menu per dept (override <code>nav_overrides.json</code>)'
            : 'Akses penuh — tidak dapat diubah' ?></span>
        <?php elseif ($selDept === 'BRANCH'): ?>
          <span style="font-size:12px;color:var(--rmi-muted,#9ca3af)">Sidebar: <strong>Default</strong> — tampil semua modul, atur mana saja yang terlihat untuk BRANCH</span>
        <?php else: ?>
          <span style="font-size:12px;color:var(--rmi-muted,#9ca3af)">Sidebar: <strong>Standar</strong></span>
        <?php endif; ?>
        <?php if ($ovCount > 0): ?>
          <span class="nm-ov-badge"><?= $ovCount ?> override aktif</span>
        <?php endif; ?>
      </div>
      <div class="d-flex gap-2">
        <?php if ($nmAdvancedSys && !$isAdvanced): ?>
        <a href="?dept=<?= rmi_h($selDept) ?>&advanced=1"
           class="btn btn-sm btn-ghost" style="font-size:11px" title="Hanya akun SYS — atur semua dept per menu">⚙️ Advanced</a>
        <?php endif; ?>
        <?php if (!in_array($selDept, ['SYS'], true)): ?>
        <button form="frm-main" type="submit" class="btn btn-sm btn-rmi">
          💾 Simpan <?= rmi_h($selDept) ?>
        </button>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($selDept === 'SYS' && !$isAdvanced): ?>
    <!-- ADMIN: info only -->
    <div class="nm-card">
      <div class="nm-admin-info">
        <div class="ai-icon">👑</div>
        <div class="ai-title" style="color:#f59e0b">SYS</div>
        <div class="ai-desc">
          SYS memiliki akses penuh ke <strong>semua menu</strong> tanpa terkecuali.<br><br>
          Ini di-enforce langsung di <code>rmi_layout.php</code> — tidak bisa diubah melalui Nav Manager.<br><br>
          Untuk membatasi akses halaman individual, gunakan <a href="../rbac/index.php">RBAC Center</a>.<br><br>
          <?php if ($nmAdvancedSys): ?>
          <strong>Sidebar dept lain</strong> tetap bisa diatur lewat tab CRM/WQS/… atau tombol <strong>Advanced</strong> di atas (bulk semua dept per menu).
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php elseif ($isAdvanced): ?>
    <!-- ── ADVANCED MODE (SYS only) — bisa dibuka dari tab SYS atau dept lain ── -->
    <div class="rmi-card p-3 mb-3" style="background:rgba(245,158,11,.06);border-color:rgba(245,158,11,.2)">
      <div style="font-size:12px;color:#fbbf24">
        ⚙️ <strong>Mode Advanced</strong> (SYS) — atur semua dept sekaligus per menu item.
        <a href="?dept=<?= rmi_h($selDept) ?>" style="color:#94a3b8;margin-left:8px">← Kembali<?= $selDept === 'SYS' ? ' ke info SYS' : ' ke mode normal' ?></a>
      </div>
    </div>
    <form method="post" action="?dept=<?= rmi_h($selDept) ?>&advanced=1">
      <?php if (function_exists('csrf_token')): ?>
        <input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>">
      <?php endif; ?>
      <input type="hidden" name="save_mode" value="advanced">
      <input type="hidden" name="variant"   value="<?= rmi_h($selVariant) ?>">
      <div class="nm-card mb-3" style="overflow-x:auto">
        <table class="nm-tbl" style="min-width:680px">
          <thead>
            <tr>
              <th style="width:36px"></th>
              <th style="width:180px">Menu</th>
              <th>Dept yang bisa lihat</th>
              <th style="width:56px;text-align:center">Hide</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($mergedItems as $it):
              if (!empty($it['section'])) { echo '<tr class="nm-sect-row"><td colspan="4">' . rmi_h($it['section']) . '</td></tr>'; continue; }
              $k = $it['key'] ?? null; if (!$k) continue;
              $roles  = $it['roles'] ?? 'ALL';
              $hidden = !empty($it['_hidden']);
              $hasOv  = isset($navOv[$selVariant][$k]);
              $active = array_map('trim', explode(',', strtoupper($roles)));
              $isAll  = in_array('ALL', $active, true);
          ?>
          <tr class="<?= $hidden ? 'nm-hidden-row' : '' ?>">
            <td class="nm-icon"><?= $it['icon'] ?? '' ?></td>
            <td>
              <div class="nm-menu-label"><?= rmi_h($it['label'] ?? $k) ?></div>
              <div class="nm-menu-key"><?= rmi_h($k) ?></div>
              <?php if ($hasOv): ?><span class="nm-pill nm-pill-ov">override</span><?php endif; ?>
            </td>
            <td class="py-2">
              <div style="display:flex;flex-wrap:wrap;gap:3px">
                <label class="nm-role-lbl <?= $isAll ? 'on-all' : '' ?>">
                  <input type="checkbox" name="roles[<?= rmi_h($k) ?>][]" value="ALL"
                         <?= $isAll ? 'checked' : '' ?> class="nm-chk-all" data-key="<?= rmi_h($k) ?>"> ALL
                </label>
                <?php foreach ($ALL_ROLES as $r): $chk = (!$isAll && in_array($r, $active, true)); ?>
                <label class="nm-role-lbl <?= $chk ? 'on' : '' ?>">
                  <input type="checkbox" name="roles[<?= rmi_h($k) ?>][]" value="<?= rmi_h($r) ?>"
                         <?= $chk ? 'checked' : '' ?> class="nm-chk-role" data-key="<?= rmi_h($k) ?>">
                  <?= rmi_h($r) ?>
                </label>
                <?php endforeach; ?>
              </div>
            </td>
            <td style="text-align:center">
              <input type="checkbox" name="hidden[<?= rmi_h($k) ?>]" value="1"
                     <?= $hidden ? 'checked' : '' ?> class="form-check-input">
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="d-flex justify-content-end">
        <button type="submit" class="btn btn-rmi">💾 Simpan (Advanced)</button>
      </div>
    </form>

    <?php else: // !$isAdvanced && selDept !== SYS (or unreachable SYS handled above) ?>
    <!-- ── NORMAL MODE ── -->
    <form id="frm-main" method="post" action="?dept=<?= rmi_h($selDept) ?>">
      <?php if (function_exists('csrf_token')): ?>
        <input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>">
      <?php endif; ?>
      <input type="hidden" name="save_mode" value="dept">
      <input type="hidden" name="variant"   value="<?= rmi_h($selVariant) ?>">
      <input type="hidden" name="dept"      value="<?= rmi_h($selDept) ?>">
      <input type="hidden" name="group"     value="<?= $selDept === 'BRANCH' ? 'branch' : 'dept' ?>">
      <div class="nm-card">
        <?= nm_render_toggle_table($mergedItems, $selDept, $navOv[$selVariant]) ?>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-3">
        <div style="font-size:11px;color:var(--rmi-muted,#9ca3af)">
          Override disimpan ke <code>nav_overrides.json</code> — tidak mengubah <code>nav_config.php</code>
        </div>
        <button type="submit" class="btn btn-rmi">💾 Simpan <?= rmi_h($selDept) ?></button>
      </div>
    </form>
    <?php endif; ?>

</div><!-- /content panel -->
<?php endif; // $selTab === 'nav' ?>

<?php if ($selTab === 'landing'): // LANDING PAGE TAB ?>

<!-- ══ LANDING PAGE MANAGER ══════════════════════════════════════════════ -->
<div class="nm-card p-4 mb-3" style="background:rgba(6,182,212,.04);border-color:rgba(6,182,212,.15)">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
    <span style="font-size:20px">🏠</span>
    <div>
      <div style="font-weight:700;font-size:14px">Landing Page per Departemen</div>
      <div style="font-size:12px;color:var(--rmi-muted,#94a3b8);margin-top:2px">
        Halaman pertama yang muncul setelah user login, sesuai dept-nya.
        Override disimpan ke <code>nav_overrides.json</code> → dibaca oleh <code>auth_landing_path_for_dept()</code>.
      </div>
    </div>
  </div>
</div>

<form method="post" action="?tab=landing">
  <?php if (function_exists('csrf_token')): ?>
    <input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>">
  <?php endif; ?>
  <input type="hidden" name="save_mode" value="landing_pages">

  <div class="lp-grid mb-3">
  <?php foreach ($ALL_DEPTS_FULL as $d):
    $meta    = $deptMeta[$d] ?? ['color'=>'#94a3b8','icon'=>'🏢','label'=>$d];
    $default = $landingDefaults[$d] ?? '/dashboards/index.php';
    $ovEntry = $navOv['landing_pages'][$d] ?? null;
    $current = ($ovEntry && !empty($ovEntry['url'])) ? $ovEntry['url'] : $default;
    $isCustom = ($current !== $default);
    $isPreset = isset($LANDING_PRESETS[$current]);
  ?>
  <div class="lp-card <?= $isCustom ? 'lp-custom' : '' ?>">
    <!-- Header -->
    <div class="lp-card-head">
      <span style="font-size:18px"><?= $meta['icon'] ?></span>
      <div style="flex:1">
        <div style="display:flex;align-items:center;gap:6px">
          <span class="lp-dept-badge"
                style="background:<?= $meta['color'] ?>18;color:<?= $meta['color'] ?>;border:1px solid <?= $meta['color'] ?>44">
            <?= rmi_h($meta['label']) ?>
          </span>
          <?php if ($isCustom): ?>
            <span class="lp-badge-custom">⚡ Custom</span>
          <?php else: ?>
            <span class="lp-badge-default">✓ Default</span>
          <?php endif; ?>
        </div>
        <div class="lp-current"><?= rmi_h($current) ?></div>
      </div>
    </div>

    <!-- Select -->
    <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--rmi-muted,#94a3b8);display:block;margin-bottom:4px">Pilih Halaman Tujuan</label>
    <select name="landing[<?= rmi_h($d) ?>]"
            class="lp-select"
            onchange="nmLpChange(this,'<?= rmi_h($d) ?>')">
      <!-- Option: default -->
      <option value="<?= rmi_h($default) ?>"
              <?= (!$isCustom || $current === $default) ? 'selected' : '' ?>>
        🔄 Default: <?= rmi_h($LANDING_PRESETS[$default] ?? $default) ?>
      </option>
      <!-- Separator -->
      <optgroup label="── Preset Halaman ──">
        <?php foreach ($LANDING_PRESETS as $url => $label):
          if ($url === $default) continue; // skip jika sama dengan default ?>
          <option value="<?= rmi_h($url) ?>"
                  <?= ($current === $url && $isCustom) ? 'selected' : '' ?>>
            <?= rmi_h($label) ?>
          </option>
        <?php endforeach; ?>
      </optgroup>
      <!-- Custom URL -->
      <option value="__custom__"
              <?= ($isCustom && !$isPreset && $current !== $default) ? 'selected' : '' ?>>
        ✏️ Custom URL...
      </option>
    </select>

    <!-- Custom URL input (hidden unless __custom__ selected) -->
    <input type="text" name="landing_custom[<?= rmi_h($d) ?>]"
           class="lp-custom-url"
           id="lp-custom-<?= rmi_h($d) ?>"
           placeholder="/path/ke/halaman.php"
           value="<?= ($isCustom && !$isPreset && $current !== $default) ? rmi_h($current) : '' ?>"
           style="display:<?= ($isCustom && !$isPreset && $current !== $default) ? 'block' : 'none' ?>">
    <div style="font-size:10px;color:var(--rmi-muted,#64748b);margin-top:4px;display:none" id="lp-hint-<?= rmi_h($d) ?>">
      Harus diawali dengan <code>/</code>. Contoh: <code>/kpi/kpi_center.php</code>
    </div>
  </div>
  <?php endforeach; ?>
  </div>

  <!-- SYS / privileged note -->
  <div style="padding:12px 16px;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);border-radius:10px;font-size:12px;margin-bottom:14px">
    <strong style="color:#fbbf24">👑 Privileged &amp; landing</strong>
    <span style="color:var(--rmi-muted,#94a3b8)"> — Setelah login, <strong>semua</strong> user (termasuk SYS/ADMIN/SUPERADMIN) memakai landing sesuai <strong>Departemen</strong> di kartu di atas + Nav Manager (sama dengan <code>auth_landing_path_for_dept()</code>). Default dept <strong>SYS</strong>: <code>/dashboards/index.php</code>. Ingin langsung ke kelola user? Pilih preset <strong>Master System Login</strong> atau Custom URL untuk dept terkait.</span>
  </div>

  <div class="d-flex justify-content-between align-items-center">
    <div style="font-size:11px;color:var(--rmi-muted,#9ca3af)">
      Efektif langsung setelah disimpan. Berlaku saat login berikutnya.
    </div>
    <button type="submit" class="btn btn-rmi">💾 Simpan Landing Pages</button>
  </div>
</form>

<?php endif; // landing tab ?>

<script>
// Landing page: show/hide custom URL input
function nmLpChange(sel, dept) {
  var isCustom = sel.value === '__custom__';
  var inp  = document.getElementById('lp-custom-' + dept);
  var hint = document.getElementById('lp-hint-'   + dept);
  if (inp)  inp.style.display  = isCustom ? 'block' : 'none';
  if (hint) hint.style.display = isCustom ? 'block' : 'none';
  if (isCustom && inp) inp.focus();
}

document.addEventListener('DOMContentLoaded', function(){
  // Live toggle label
  document.querySelectorAll('.nm-vis-chk').forEach(function(inp){
    inp.addEventListener('change', function(){
      var lbl = inp.closest('td')?.querySelector('.nm-vis-lbl');
      if (!lbl) return;
      lbl.textContent = inp.checked ? 'YA' : 'Tidak';
      lbl.className   = 'nm-vis-lbl ' + (inp.checked ? 'on' : 'off');
      // Highlight row yang diubah
      var row = inp.closest('tr');
      if (row) { row.style.transition = 'background .2s';
        row.style.background = inp.checked ? 'rgba(34,197,94,.05)' : 'rgba(239,68,68,.04)'; }
    });
  });

  // Advanced: role badge toggle
  document.querySelectorAll('.nm-role-lbl').forEach(function(lbl){
    lbl.addEventListener('click', function(){
      var inp = lbl.querySelector('input');
      if (!inp) return;
      setTimeout(function(){
        var isAll = inp.classList.contains('nm-chk-all');
        lbl.classList.toggle('on',     inp.checked && !isAll);
        lbl.classList.toggle('on-all', inp.checked && isAll);
        var key = inp.dataset.key;
        if (isAll && inp.checked) {
          document.querySelectorAll('.nm-chk-role[data-key="' + key + '"]').forEach(function(r){
            r.checked = false;
            r.closest('.nm-role-lbl').classList.remove('on');
          });
        }
        if (!isAll && inp.checked) {
          var allChk = document.querySelector('.nm-chk-all[data-key="' + key + '"]');
          if (allChk && allChk.checked) {
            allChk.checked = false;
            allChk.closest('.nm-role-lbl').classList.remove('on-all');
          }
        }
      }, 0);
    });
  });
});
</script>

<?php rmi_footer(); ?>
