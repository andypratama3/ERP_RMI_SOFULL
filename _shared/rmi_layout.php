<?php
require_once __DIR__ . '/assets.php';
// _shared/rmi_layout.php
// Unified layout: topbar + menu drawer (offcanvas) + help panel + theme toggle.
// NO sidebar. ONE source of truth for all module pages.
//
// OWNERSHIP: UI-only. Jangan taruh business logic / mutasi data di sini.

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/hrlp_process_rbac.php';
require_once __DIR__ . '/rmi_panduan_helper.php';
require_once __DIR__ . '/rmi_branch_guard.php';

// ─── Base project detection ───
if (!function_exists('rmi_layout_base_project')) {
  function rmi_layout_base_project(): string {
    if (defined('BASE_PROJECT') && (string)BASE_PROJECT !== '') {
      $bp = rtrim((string)BASE_PROJECT, '/');
      return ($bp === '/' ? '' : $bp);
    }
    if (function_exists('auth_base_project')) {
      $bp = rtrim((string)auth_base_project(), '/');
      return ($bp === '/' ? '' : $bp);
    }
    global $BASE_PROJECT;
    if (isset($BASE_PROJECT) && is_string($BASE_PROJECT) && $BASE_PROJECT !== '') {
      $bp = rtrim($BASE_PROJECT, '/');
      return ($bp === '/' ? '' : $bp);
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $script = preg_replace('~/+~', '/', $script);
    $known = '(master|stock|dashboards|kpi|purchases|sales|hrl|hrl_process|hrl_reg_alkes|absensi|payroll|mpr|Fixed_Asset|rbac|tools|chat|docs|api|assets)';
    if (preg_match('~^(.*?)/' . $known . '(?:/|$)~i', $script, $m)) {
      $bp = rtrim((string)($m[1] ?? ''), '/');
      return ($bp === '/' ? '' : $bp);
    }
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '' || $dir === '/' || $dir === '.') return '';
    return $dir;
  }
}

// ─── Tiny helpers ───
if (!function_exists('rmi_ui_h')) {
  function rmi_ui_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('rmi_bool_env')) {
  function rmi_bool_env(string $key, ?bool $default = null): ?bool {
    $raw = getenv($key);
    if ($raw === false || trim((string)$raw) === '') return $default;
    $v = strtolower(trim((string)$raw));
    if (in_array($v, ['1','true','yes','on'], true)) return true;
    if (in_array($v, ['0','false','no','off'], true)) return false;
    return $default;
  }
}

if (!function_exists('rmi_app_env')) {
  function rmi_app_env(): string {
    if (defined('APP_ENV')) return strtolower(trim((string)APP_ENV));
    $e = getenv('APP_ENV');
    if ($e === false || trim((string)$e) === '') return 'local';
    return strtolower(trim((string)$e));
  }
}

if (!function_exists('rmi_env_watermark_enabled')) {
  function rmi_env_watermark_enabled(): bool {
    $forced = rmi_bool_env('RMI_ENV_WATERMARK_ENABLED', null);
    if ($forced !== null) return $forced;
    // File .production di root = production (tidak bergantung .env)
    $root = defined('RMI_ROOT') ? RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
    if (is_file($root . DIRECTORY_SEPARATOR . '.production')) return false;
    // Fallback: APP_ENV
    return rmi_app_env() !== 'production';
  }
}

if (!function_exists('rmi_env_watermark_class')) {
  function rmi_env_watermark_class(): string {
    $env = rmi_app_env();
    if (in_array($env, ['production', 'prod'], true)) return 'is-prod';
    if (in_array($env, ['staging', 'stage', 'uat'], true)) return 'is-staging';
    if (in_array($env, ['qa', 'test', 'testing'], true)) return 'is-qa';
    return 'is-dev';
  }
}

if (!function_exists('rmi_env_watermark_text')) {
  function rmi_env_watermark_text(): string {
    $env = strtoupper(rmi_app_env());
    if (rmi_env_watermark_class() === 'is-prod') {
      return $env . ' ENV';
    }
    return $env . ' NON-PRODUCTION';
  }
}

// ─── Shared state ───
$GLOBALS['_rmi_layout_state'] = $GLOBALS['_rmi_layout_state'] ?? [
  'base_project' => '',
  'extra_js'     => '',
  'bootstrap_css'=> '',
  'bootstrap_js' => '',
  'nav_html'     => '',
];

if (!function_exists('rmi_ui_set_extra_js')) {
  function rmi_ui_set_extra_js(string $html): void {
    if ($html === '') return;
    $GLOBALS['_rmi_layout_state']['extra_js'] = ($GLOBALS['_rmi_layout_state']['extra_js'] ?? '') . "\n" . $html;
  }
}

// ─── UI component helpers ───
if (!function_exists('rmi_ui_breadcrumb_html')) {
  function rmi_ui_breadcrumb_html(array $items): string {
    if (empty($items)) return '';
    $out = '<nav aria-label="breadcrumb"><ol class="breadcrumb rmi-breadcrumb mb-0">';
    $count = count($items);
    foreach ($items as $i => $it) {
      $isLast = ($i === $count - 1);
      if (is_array($it)) {
        $label = $it['label'] ?? ($it['title'] ?? '');
        $url   = $it['url'] ?? ($it['href'] ?? '');
      } else {
        $label = (string)$it;
        $url   = '';
      }
      $labelEsc = rmi_ui_h($label);
      if (!$isLast && $url !== '') {
        $out .= '<li class="breadcrumb-item"><a href="' . rmi_ui_h($url) . '">' . $labelEsc . '</a></li>';
      } elseif (!$isLast) {
        $out .= '<li class="breadcrumb-item">' . $labelEsc . '</li>';
      } else {
        $out .= '<li class="breadcrumb-item active" aria-current="page">' . $labelEsc . '</li>';
      }
    }
    $out .= '</ol></nav>';
    return $out;
  }
}

if (!function_exists('rmi_ui_alert')) {
  function rmi_ui_alert(string $type, string $message, array $opts = []): void {
    $type = strtolower(trim($type)) ?: 'info';
    if (trim($message) === '' && empty($opts['html'])) return;
    $dismiss = (bool)($opts['dismissible'] ?? false);
    $class = 'alert rmi-alert alert-' . rmi_ui_h($type) . ($dismiss ? ' alert-dismissible fade show' : '');
    if (!empty($opts['class'])) $class .= ' ' . trim((string)$opts['class']);
    echo '<div class="' . $class . '" role="alert">';
    if (!empty($opts['title'])) echo '<div class="fw-semibold mb-1">' . rmi_ui_h($opts['title']) . '</div>';
    echo !empty($opts['html']) ? (string)$opts['html'] : rmi_ui_h($message);
    if ($dismiss) echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
    echo '</div>';
  }
}

if (!function_exists('rmi_ui_alert_from_flash')) {
  function rmi_ui_alert_from_flash($flash, array $opts = []): void {
    if (empty($flash)) return;
    if (is_array($flash)) {
      $type = (string)($flash['type'] ?? $flash['level'] ?? $flash['status'] ?? 'info');
      $msg  = (string)($flash['msg'] ?? $flash['message'] ?? $flash['text'] ?? '');
    } else {
      $type = (string)($opts['type'] ?? 'info');
      $msg  = (string)$flash;
    }
    if ($msg !== '') rmi_ui_alert($type, $msg, $opts);
  }
}

if (!function_exists('rmi_ui_actions_html')) {
  function rmi_ui_actions_html(array $actions, array $opts = []): string {
    if (empty($actions)) return '';
    $out = '';
    foreach ($actions as $a) {
      if (!is_array($a)) continue;
      $label = (string)($a['label'] ?? '');
      $url   = (string)($a['url'] ?? $a['href'] ?? '#');
      if ($label === '') continue;
      $class = trim((string)($a['class'] ?? 'btn btn-sm btn-outline-light'));
      $attrs = trim((string)($a['attrs'] ?? ''));
      $icon  = (string)($a['icon'] ?? '');
      $out .= '<a class="' . rmi_ui_h($class) . '" href="' . rmi_ui_h($url) . '" ' . $attrs . '>';
      if ($icon !== '') $out .= '<span class="me-1">' . $icon . '</span>';
      $out .= rmi_ui_h($label) . '</a>';
    }
    return $out;
  }
}

if (!function_exists('rmi_ui_card_open')) {
  function rmi_ui_card_open(string $title = '', array $opts = []): void {
    $class = 'card rmi-card';
    if (!empty($opts['class'])) $class .= ' ' . trim((string)$opts['class']);
    echo '<div class="' . rmi_ui_h($class) . '">';
    if (!empty($opts['header_html']) || $title !== '') {
      echo '<div class="card-header rmi-card-header">';
      echo !empty($opts['header_html']) ? (string)$opts['header_html'] : '<div class="fw-semibold">' . rmi_ui_h($title) . '</div>';
      echo '</div>';
    }
    $bodyClass = 'card-body';
    if (!empty($opts['body_class'])) $bodyClass .= ' ' . trim((string)$opts['body_class']);
    echo '<div class="' . rmi_ui_h($bodyClass) . '">';
  }
}

if (!function_exists('rmi_ui_card_close')) {
  function rmi_ui_card_close(array $opts = []): void {
    echo '</div>';
    if (!empty($opts['footer_html'])) echo '<div class="card-footer rmi-card-footer">' . (string)$opts['footer_html'] . '</div>';
    echo '</div>';
  }
}

if (!function_exists('rmi_ui_table_class')) {
  function rmi_ui_table_class(string $extra = ''): string {
    $c = 'table table-sm table-hover align-middle rmi-table';
    return trim($extra) !== '' ? $c . ' ' . trim($extra) : $c;
  }
}

if (!function_exists('rmi_ui_form_row')) {
  function rmi_ui_form_row(string $label, string $fieldHtml, array $opts = []): void {
    $id       = (string)($opts['id'] ?? '');
    $required = (bool)($opts['required'] ?? false);
    $help     = (string)($opts['help'] ?? '');
    $error    = (string)($opts['error'] ?? '');
    echo '<div class="rmi-form-row row g-2 mb-2">';
    echo '<label class="col-sm-3 col-form-label"' . ($id !== '' ? ' for="' . rmi_ui_h($id) . '"' : '') . '>';
    echo rmi_ui_h($label);
    if ($required) echo ' <span class="text-danger">*</span>';
    echo '</label><div class="col-sm-9">' . $fieldHtml;
    if ($help !== '')  echo '<div class="form-text">' . rmi_ui_h($help) . '</div>';
    if ($error !== '') echo '<div class="invalid-feedback d-block">' . rmi_ui_h($error) . '</div>';
    echo '</div></div>';
  }
}


// ═══════════════════════════════════════════════════════════
// rmi_header() — open page
// ═══════════════════════════════════════════════════════════
function rmi_header(string $title = 'RMI ERP', $active = '', array $opts = []): void
{
    // Compat: arg#2 can be array (legacy callers)
    if (is_array($active)) {
        $opts   = array_merge($active, $opts);
        $active = $active['active'] ?? ($opts['active'] ?? '');
    }
    $activeKey = is_string($active) ? $active : '';

    $baseProject = rmi_layout_base_project();
    $GLOBALS['_rmi_layout_state']['base_project'] = $baseProject;

    if (!empty($opts['extra_js'])) rmi_ui_set_extra_js((string)$opts['extra_js']);

    // ── User context ──
    $user   = function_exists('auth_user') ? auth_user() : [];
    $dept   = strtoupper((string)($user['department'] ?? ''));
    $role   = strtoupper((string)($user['role'] ?? ''));
    $level  = strtoupper((string)($user['level'] ?? ''));
    $office = strtoupper((string)($user['office_code'] ?? ''));
    $isDepoBranchUi = function_exists('rmi_is_depo_branch_session') && rmi_is_depo_branch_session();

    // ── Menu URLs ──
    // URL map: key → URL (dipakai jika nav_config item tidak punya 'url' eksplisit)
    $u = [
        // MAIN
        'dashboard'           => $baseProject . '/dashboards/index.php',
        'absensi'             => $baseProject . '/absensi/index.php',
        // Legacy nav keys (override Nav Manager tanpa 'url')
        'absensi_admin'       => $baseProject . '/absensi/admin.php',
        'kpi'                 => $baseProject . '/kpi/kpi_center.php',
        'chat'                => $baseProject . '/chat/index.php',
        'exec_summary'        => $baseProject . '/dashboards/owner/exec_summary.php',
        'quality'             => $baseProject . '/dashboards/quality/qc_complaint_dashboard.php',
        // CRM
        'sales'               => $baseProject . '/sales/sales_dashboard.php',
        'wqs_do_tasks'        => $baseProject . '/stock/wqs_do_tasks.php',
        'wqs_picking'         => $baseProject . '/stock/wqs_picking.php',
        // SCM
        'scm_do_tasks'        => $baseProject . '/sales/scm_do_tasks.php',
        'purchases_forwarding'=> $baseProject . '/purchases/purchases_forwarding_tasks.php',
        // WQS
        'stock'               => $baseProject . '/dashboards/warehouse/wqs_dashboard.php',
        'stock_opname'        => $baseProject . '/stock/wqs_stock_opname.php',
        // PQP
        'purchases'           => $baseProject . '/purchases/purchases_dashboard.php',
        'purchases_rfq'       => $baseProject . '/purchases/pqp_rfq.php',
        'purchases_po'        => $baseProject . '/purchases/purchases_po.php',
        'purchases_invoice_ap'=> $baseProject . '/purchases/purchases_invoice_ap.php',
        'purchases_ap'        => $baseProject . '/purchases/purchases_payment_ap.php',
        'hrl_reg_alkes'       => $baseProject . '/hrl_reg_alkes/index.php',
        // FIN
        'company_bank'        => $baseProject . '/master/company_bank_accounts.php',
        'payroll'             => $baseProject . '/payroll/index.php',
        'hrl_payroll'         => $baseProject . '/payroll/index.php',
        'mpr'                 => $baseProject . '/mpr/mpr_dashboard.php',
        // ACT
        'fixed_asset'         => $baseProject . '/Fixed_Asset/index.php',
        // HRL
        'hrl'                 => $baseProject . '/hrl/hrl_docs.php',
        'hrl_process'         => $baseProject . '/hrl_process/tower.php',
        // ITC
        'rbac'                => $baseProject . '/rbac/index.php',
        'nav_manager'         => $baseProject . '/master/nav_manager.php',
        'tools'               => $baseProject . '/tools/index.php',
        // SYSTEM
        'master'              => $baseProject . '/master/master_data.php',
    ];

    // ── User context (lanjutan) ──
    $roleView = $dept !== '' ? $dept : ($role !== '' ? $role : 'USER');
    // dept valid: FIN (finance) atau SYS (privileged). ADMIN/SUPERADMIN bukan dept.
    // role valid: SYS saja yang privileged. FIN/ADMIN/SUPERADMIN bukan role.
    // level valid: SYS saja yang privileged.
    $canFin = in_array($dept,  ['FIN', 'SYS'], true)
           || in_array($role,  ['SYS'], true)
           || in_array($level, ['SYS'], true);

    $breadcrumbs = $opts['breadcrumbs'] ?? ($opts['breadcrumb'] ?? []);
    if (!is_array($breadcrumbs)) $breadcrumbs = [];

    if (empty($opts['skip_panduan_link'])) {
        $pAct = rmi_panduan_header_action($baseProject);
        if ($pAct !== null) {
            $opts['actions'] = isset($opts['actions']) && is_array($opts['actions'])
                ? array_merge($opts['actions'], [$pAct])
                : [$pAct];
        }
    }

    $actionsHtml = (string)($opts['actions_html'] ?? '');
    if ($actionsHtml === '' && !empty($opts['actions']) && is_array($opts['actions'])) {
        $actionsHtml = rmi_ui_actions_html($opts['actions']);
    }

    $bodyClass = 'rmi-body';
    if (!empty($opts['body_class'])) $bodyClass .= ' ' . trim((string)$opts['body_class']);

    // ── Bootstrap assets ──
    $rootFs   = defined('RMI_ROOT') ? RMI_ROOT : realpath(__DIR__ . '/..');
    $localCss = $rootFs ? ($rootFs . '/TEMPLATES/bootstrap.min.css') : '';
    $localJs  = $rootFs ? ($rootFs . '/TEMPLATES/bootstrap.bundle.min.js') : '';
    $hasLocal = ($localCss && is_file($localCss) && $localJs && is_file($localJs));

    if ($hasLocal) {
        $bootstrapCss = $baseProject . '/TEMPLATES/bootstrap.min.css';
        $bootstrapJs  = $baseProject . '/TEMPLATES/bootstrap.bundle.min.js';
    } else {
        $ab = function_exists('rmi_assets_base') ? rmi_assets_base() : $baseProject;
        $bootstrapCss = $ab . '/public/assets/vendor/bootstrap/5.3.3/css/bootstrap.min.css?v=20260209';
        $bootstrapJs  = $ab . '/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209';
    }

    $GLOBALS['_rmi_layout_state']['bootstrap_css'] = $bootstrapCss;
    $GLOBALS['_rmi_layout_state']['bootstrap_js']  = $bootstrapJs;

    // ── Capture menu nav HTML (for drawer offcanvas in rmi_footer) ──
    ob_start();
    // SYS adalah satu-satunya privileged yang valid. ADMIN/SUPERADMIN bukan dept/role/level canonical.
    $isAdminUi = in_array($role, ['SYS'], true)
              || in_array($dept, ['SYS'], true)
              || in_array($level, ['SYS'], true);

    // Sidebar selalu dari nav_config['default'] supaya Nav Manager (nav_overrides.default)
    // mengatur menu yang sama untuk semua user — variant 'branch' di nav_config tidak dipakai
    // untuk render (menghindari section yang "hilang" untuk staff cabang).
?>
    <div class="rmi-brand">
      <div class="rmi-brand-title">RMI ERP</div>
      <div class="rmi-brand-sub">Unified Layout</div>
    </div>
    <div class="rmi-rolefilter mb-2">
      <div class="small opacity-75 mb-1">Menu view</div>
      <select id="rmiRoleView" class="form-select form-select-sm">
        <option value="AUTO">Sesuai Dept (<?= rmi_ui_h($roleView) ?>)</option>
<?php if ($isAdminUi): ?>
        <option value="ALL">Semua Modul</option>
        <option value="CRM">CRM / Sales</option>
        <option value="PQP">PQP / Purchases</option>
        <option value="SCM">SCM / Import</option>
        <option value="WQS">WQS / Warehouse</option>
        <option value="FIN">FIN / Finance</option>
        <option value="HRL">HRL / HR</option>
        <option value="ACT">ACT / Asset</option>
        <option value="ITC">ITC / IT</option>
        <option value="MPR">MPR</option>
        <option value="BRANCH">BRANCH</option>
        <option value="SYS">SYS</option>
<?php endif; ?>
      </select>
      <div class="form-text rmi-muted">Tip: Tekan <span class="rmi-kbd">F1</span> untuk bantuan.</div>
    </div>
    <nav class="nav flex-column rmi-nav">
<?php
    // Baca config + override — atur lewat master/nav_manager.php
    $_navCfg   = require __DIR__ . '/nav_config.php';
    $_navItems = $_navCfg['default'] ?? [];

    // Akun Depo KAL/JGY memakai menu fail-closed khusus.
    // Branch internal tetap memakai pohon default + Nav Manager seperti sebelumnya.
    if ($isDepoBranchUi) {
        $_navItems = [
            ['section' => 'DEPO'],
            ['key'=>'depo_home','url'=>'{base}/dashboards/branch/depo_dashboard.php','icon'=>'🏢','label'=>'Dashboard Depo','roles'=>'ALL'],
            ['key'=>'depo_achievement','url'=>'{base}/dashboards/finance/dashboard_detail.php','icon'=>'🎯','label'=>'Pencapaian','roles'=>'ALL'],
            ['key'=>'depo_do','url'=>'{base}/sales/sales_do.php','icon'=>'📋','label'=>'Delivery Order','roles'=>'ALL'],

            ['section' => 'PROSES DO'],
            ['key'=>'depo_wqs_do','url'=>'{base}/stock/wqs_do_tasks.php','icon'=>'⚡','label'=>'Task DO — WQS','roles'=>'ALL'],
            ['key'=>'depo_scm_do','url'=>'{base}/sales/scm_do_tasks.php','icon'=>'🚚','label'=>'Task DO — SCM','roles'=>'ALL'],

            ['section' => 'MPR'],
            ['key'=>'depo_mpr_dashboard','url'=>'{base}/mpr/mpr_dashboard.php','icon'=>'🧩','label'=>'MPR Dashboard','roles'=>'ALL'],
            ['key'=>'depo_mpr_plans','url'=>'{base}/mpr/mpr_plans.php','icon'=>'🗺️','label'=>'MPR Plans','roles'=>'ALL'],
            ['key'=>'depo_mpr_visits','url'=>'{base}/mpr/mpr_visits.php','icon'=>'📍','label'=>'MPR Kunjungan','roles'=>'ALL'],
            ['key'=>'depo_mpr_pipeline','url'=>'{base}/mpr/mpr_pipeline.php','icon'=>'🎯','label'=>'MPR Pipeline','roles'=>'ALL'],

            ['section' => 'BANTUAN'],
            ['key'=>'depo_help','url'=>'{base}/docs/help_center.php','icon'=>'❓','label'=>'Help Center','roles'=>'ALL'],
        ];
    }

    // Override: default (Nav Manager) + branch (opsional, menimpa per-key jika ada)
    $_ovFile = __DIR__ . '/nav_overrides.json';
    if (is_file($_ovFile)) {
        $_ovRaw = json_decode(file_get_contents($_ovFile), true);
        if (is_array($_ovRaw)) {
            $_ovDefault = is_array($_ovRaw['default'] ?? null) ? $_ovRaw['default'] : [];
            $_ovBranch  = is_array($_ovRaw['branch'] ?? null) ? $_ovRaw['branch'] : [];
            $_ovVariant = array_merge($_ovDefault, $_ovBranch);
            if (!empty($_ovVariant)) {
                foreach ($_navItems as &$_ni) {
                    $__k = $_ni['key'] ?? null;
                    if ($__k === null || $__k === '' || $__k === '_info') {
                        continue;
                    }
                    if (isset($_ovVariant[$__k])) {
                        $__ent = $_ovVariant[$__k];
                        if (is_array($__ent) && isset($__ent['roles'])) {
                            $_ni['roles'] = (string)$__ent['roles'];
                            // Paksa gate roles: kalau tidak, item dengan 'perm' mengabaikan Nav Manager
                            $_ni['_nav_ov_roles'] = true;
                        }
                        if (is_array($__ent) && !empty($__ent['hidden'])) {
                            $_ni['_hidden'] = true;
                        }
                    }
                }
                unset($_ni);
            }
        }
    }

    // ── Server-side build: filter dulu, baru render ──────────────────────
    // Section hanya tampil jika ada item yang lolos filter di bawahnya.
    $_renderItems   = [];
    $_pendingSection = null;

    foreach ($_navItems as $_ni) {
        if (!empty($_ni['_hidden'])) continue;

        // Buffer section — emit hanya jika ada item lolos di bawahnya
        if (isset($_ni['section'])) {
            $_pendingSection = $_ni;
            continue;
        }

        // Resolve URL
        if (isset($_ni['url'])) {
            $_href = str_replace('{base}', $baseProject, $_ni['url']);
        } elseif (isset($_ni['key']) && isset($u[$_ni['key']])) {
            $_href = $u[$_ni['key']];
        } else {
            continue;
        }

        // ── PERMISSION check (primary) + ROLES check (fallback) ─────────────
        // Prioritas: jika item punya permission → cek permission dulu.
        //   Jika user PUNYA permission → tampilkan (bypass roles).
        //   Jika user TIDAK punya permission → sembunyikan.
        // Jika item TIDAK punya permission → gunakan roles sebagai fallback.
        // SYS/Admin selalu lolos semua.
        $_permCfg = $_ni['perm'] ?? null;
        if ($_permCfg === null && !empty($_ni['hrl_tower_type']) && is_string($_ni['hrl_tower_type'])) {
            $hrlpRb = __DIR__ . '/hrlp_process_rbac.php';
            if (is_file($hrlpRb)) {
                require_once $hrlpRb;
            }
            if (function_exists('hrlp_req_type_nav_permissions')) {
                $_permCfg = hrlp_req_type_nav_permissions((string)$_ni['hrl_tower_type']);
            }
        }
        if ($_permCfg === null && isset($_ni['key'])) {
            $_permByKey = [
                'chat' => ['CHAT.VIEW'],
                'kpi' => ['KPI.VIEW'],
                'sales' => ['SALES.VIEW'],
                // ── MAIN ──────────────────────────────────────────────────────────
                'dashboard'                      => ['DASHBOARD.VIEW'],
                'branch_dashboard'               => ['DASHBOARD.BRANCH_VIEW'],
                'branch_panduan'                 => ['DASHBOARD.BRANCH_VIEW'],
                'absensi'                        => ['ABSENSI.VIEW','ABSENSI.CHECKIN'],
                'absensi_checkin'                => ['ABSENSI.CHECKIN'],
                'absensi_checkout'               => ['ABSENSI.CHECKIN'],
                'absensi_history'                => ['ABSENSI.VIEW'],
                'absensi_request'                => ['ABSENSI.REQUEST','ABSENSI.VIEW'],
                'absensi_izin'                   => ['ABSENSI.REQUEST','ABSENSI.VIEW'],
                'absensi_approval'             => ['ABSENSI.APPROVE'],
                'absensi_panduan'                => ['ABSENSI.VIEW'],
                'absensi_admin'                  => ['ABSENSI.ADMIN_EDIT','ABSENSI.OFFICE_SETTINGS','ABSENSI.RECAP','ABSENSI.ADMIN_USERS'],
                'absensi_admin_panel'            => ['ABSENSI.ADMIN_USERS'],
                'absensi_admin_rekap'            => ['ABSENSI.RECAP'],
                'absensi_admin_approval'         => ['ABSENSI.APPROVE','ABSENSI.VIEW'],
                'absensi_admin_offices'         => ['ABSENSI.OFFICE_SETTINGS'],
                'absensi_admin_pins'             => ['ABSENSI.ADMIN_PINS'],
                'absensi_admin_user_map'         => ['ABSENSI.ADMIN_USERS','ABSENSI.ADMIN_EDIT'],
                'absensi_admin_shifts'           => ['ABSENSI.ADMIN_EDIT'],
                'absensi_admin_settings'         => ['ABSENSI.ADMIN_EDIT'],
                'absensi_admin_broadcast'        => ['ABSENSI.ADMIN_EDIT'],
                'absensi_admin_payroll_gate'     => ['ABSENSI.ADMIN_EDIT'],
                'absensi_kiosk'                  => ['ABSENSI.CHECKIN','ABSENSI.VIEW'],
                'absensi_kiosk_poster'           => ['ABSENSI.ADMIN_EDIT'],
                'kpi'                            => ['KPI.VIEW'],
                'kpi_dashboard_daily'            => ['KPI.VIEW','DASHBOARD.KPI_VIEW'],
                'kpi_dashboard_monthly'          => ['KPI.VIEW','DASHBOARD.KPI_VIEW'],
                'kpi_do_audit'                   => ['KPI.DO_AUDIT'],
                'kpi_do_sla'                     => ['KPI.DO_VIEW'],
                'kpi_purchases'                  => ['KPI.PURCHASES_VIEW'],
                'kpi_stock'                      => ['KPI.STOCK_VIEW'],
                'kpi_employee'                   => ['KPI.EMPLOYEE_VIEW'],
                'kpi_office'                     => ['KPI.OFFICE_VIEW'],
                'kpi_snapshot'                   => ['KPI.VIEW'],
                'kpi_audit'                      => ['KPI.DO_AUDIT'],
                'kpi_panduan'                    => ['KPI.VIEW'],
                'chat'                           => ['CHAT.VIEW'],
                'help_center'                    => ['DOCS.VIEW'],
                'help_center_b'                  => ['DOCS.VIEW'],
                'exec_summary'                   => ['DASHBOARD.OWNER_SUMMARY'],

                // ── CRM ────────────────────────────────────────────────────────
                'sales'                          => ['SALES.VIEW'],
                'crm_control_tower'              => ['SALES.CONTROL_TOWER_VIEW','SALES.VIEW'],
                'crm_control_tower_panduan'      => ['SALES.VIEW'],
                'crm_sales_panduan'              => ['PANDUAN.SALES_VIEW','SALES.VIEW'],
                'crm_do'                         => ['SALES.VIEW'],
                'wqs_do_tasks'                   => ['SALES.VIEW','SALES.EDIT','WQS.DO_TASKS'],
                'wqs_picking'                    => ['WQS.PICKING_VIEW','SALES.VIEW'],

                // ── SCM ────────────────────────────────────────────────────────
                'scm_dashboard'                  => ['DASHBOARD.SCM_VIEW'],
                'scm_panduan'                    => ['DASHBOARD.SCM_VIEW'],
                'scm_import_ct'                  => ['PURCHASES.IMPORT_CONTROL'],
                'purchases_forwarding'           => ['PURCHASES.FORWARDING_VIEW'],
                'scm_do_tasks'                   => ['SALES.VIEW','SALES.EDIT'],
                'scm_po'                         => ['PURCHASES.PO_VIEW'],
                'scm_gr'                         => ['PURCHASES.GR_VIEW'],
                'scm_pr'                         => ['WQS.PR_VIEW'],

                // ── WQS ────────────────────────────────────────────────────────
                'stock'                          => ['WQS.VIEW','STOCK.VIEW'],
                'wqs_incoming'                   => ['WQS.INCOMING_VIEW'],
                'wqs_stock_view'                 => ['STOCK.VIEW'],
                'wqs_allocation'                 => ['WQS.ALLOCATION_VIEW'],
                'stock_opname'                   => ['STOCK.VIEW','STOCK.CREATE'],
                'wqs_stock_opname_report'        => ['STOCK.CREATE','WQS.INCOMING_VIEW','WQS.INCOMING_CREATE','WQS.INCOMING_EDIT','WQS.PICKING_VIEW','WQS.PICKING_CREATE','WQS.PICKING_EDIT'],
                'wqs_stock_adjustment'           => ['STOCK.CREATE'],
                'wqs_pr'                         => ['WQS.PR_VIEW'],

                // ── PQP ────────────────────────────────────────────────────────
                'purchases'                      => ['PURCHASES.VIEW'],
                'purchases_rfq'                  => ['PQP.VIEW'],
                'purchases_po'                   => ['PURCHASES.PO_VIEW'],
                'pqp_gr'                         => ['PURCHASES.GR_VIEW'],
                'pqp_import_ct'                  => ['PURCHASES.IMPORT_CONTROL'],
                'pqp_import_ct_panduan'          => ['PANDUAN.PURCHASES_VIEW','PURCHASES.VIEW'],
                'pqp_purchases_panduan'          => ['PANDUAN.PURCHASES_VIEW','PURCHASES.VIEW'],
                'hrl_reg_alkes'                  => ['HRL.REG_ALKES_VIEW'],
                'hrl_reg_alkes_dashboard'        => ['HRL.REG_ALKES_VIEW'],
                'hrl_reg_alkes_data'             => ['HRL.REG_ALKES_VIEW'],
                'hrl_reg_alkes_case'             => ['HRL.REG_ALKES_VIEW'],
                'hrl_reg_alkes_control_tower'    => ['HRL.REG_ALKES_VIEW'],
                'hrl_reg_alkes_expiry'           => ['HRL.REG_ALKES_VIEW'],
                'hrl_reg_alkes_export_compliance'=> ['HRL.COMPLIANCE_EXPORT'],
                'hrl_reg_alkes_sku_nie'          => ['HRL.REG_ALKES_VIEW'],
                'hrl_reg_alkes_panduan'          => ['HRL.REG_ALKES_VIEW'],

                // ── FIN ────────────────────────────────────────────────────────
                'fin_dashboard'                  => ['DASHBOARD.FINANCE_VIEW'],
                'fin_finance_ap_rekap'           => ['DASHBOARD.FINANCE_VIEW'],
                'fin_finance_gl_rekap'           => ['DASHBOARD.FINANCE_VIEW'],
                'fin_finance_dashboard_detail'   => ['DASHBOARD.FINANCE_DETAIL'],
                'fin_finance_sales_do_rekap'     => ['DASHBOARD.FINANCE_VIEW'],
                'fin_finance_target_rekap'       => ['DASHBOARD.FINANCE_VIEW'],
                'fin_finance_panduan'            => ['DASHBOARD.FINANCE_VIEW'],
                'fin_do_tasks'                   => ['SALES.VIEW','SALES.EDIT'],
                'purchases_invoice_ap'           => ['PURCHASES.AP_INVOICE_VIEW'],
                'purchases_ap'                   => ['PURCHASES.AP_PAYMENT_VIEW'],
                'tax_invoice'                    => ['SALES.VIEW'],
                'bank_recon'                     => ['PURCHASES.AP_PAYMENT_VIEW'],
                'act_bank_recon'                 => ['PURCHASES.AP_PAYMENT_VIEW'],
                'company_bank'                   => ['MASTER.COMPANY_BANK_VIEW'],
                'payroll'                        => ['PAYROLL.VIEW'],
                'payroll_run'                    => ['PAYROLL.VIEW'],
                'payroll_salary_matrix'          => ['PAYROLL.MATRIX_VIEW'],
                'payroll_loans'                  => ['PAYROLL.LOANS_VIEW'],
                'payroll_payslip'                => ['PAYROLL.PAYSLIP_VIEW'],
                'payroll_settings'               => ['PAYROLL.SETTINGS'],
                'payroll_audit'                  => ['PAYROLL.AUDIT'],
                'payroll_panduan'                => ['PAYROLL.VIEW'],
                'mpr'                            => ['MPR.VIEW'],

                // ── ACT ────────────────────────────────────────────────────────
                'act_dashboard'                  => ['DASHBOARD.ACT_VIEW'],
                'act_do_tasks'                   => ['SALES.VIEW','SALES.EDIT'],
                'act_ap_invoice'                 => ['PURCHASES.AP_INVOICE_VIEW'],
                'act_ap_payment'                 => ['PURCHASES.AP_PAYMENT_VIEW'],
                'act_tax'                        => ['SALES.VIEW'],
                'fixed_asset'                    => ['FIXED_ASSET.VIEW'],
                'fixed_asset_assets'             => ['FIXED_ASSET.ASSET_CRUD','FIXED_ASSET.ASSET_VIEW'],
                'fixed_asset_ops'                => ['FIXED_ASSET.OPERATIONS','FIXED_ASSET.OPS_VIEW','FIXED_ASSET.OPS_EDIT'],
                'fixed_asset_depreciation'       => ['FIXED_ASSET.DEPRECIATION_RUN','FIXED_ASSET.VIEW'],
                'fixed_asset_tax_annual'         => ['FIXED_ASSET.TAX_ANNUAL','FIXED_ASSET.TAX_ANNUAL_VIEW','FIXED_ASSET.TAX_ANNUAL_EDIT'],
                'fixed_asset_audit'              => ['FIXED_ASSET.AUDIT_VIEW','FIXED_ASSET.AUDIT'],
                'fixed_asset_panduan'            => ['FIXED_ASSET.VIEW'],
                'master_tax'                     => ['MASTER.TAX_VIEW'],

                // ── HRL ────────────────────────────────────────────────────────
                'hrl_dashboard'                  => ['DASHBOARD.HRL_VIEW'],
                'hrl'                            => ['HRL.VIEW','HRL.DOC_VIEW'],
                'hrl_rekap'                      => ['ABSENSI.RECAP'],
                'hrl_payroll'                    => ['PAYROLL.VIEW'],
                'master_employees'               => ['MASTER.EMPLOYEE_VIEW'],

                // ── HRL PROCESS ────────────────────────────────────────────────
                'hrl_process'                    => ['HRL.PROCESS_VIEW'],
                'hrl_tower_cuti'                 => ['HRL.REQ_CUTI_VIEW'],
                'hrl_tower_izin'                 => ['HRL.REQ_IZIN_VIEW'],
                'hrl_tower_lembur'               => ['HRL.REQ_LEMBUR_VIEW'],
                'hrl_tower_perjadin'             => ['HRL.REQ_PERJADIN_VIEW'],
                'hrl_tower_permintaan_karyawan'  => ['HRL.REQ_PERMINTAAN_KARYAWAN_VIEW'],
                'hrl_tower_kenaikan_gaji'        => ['HRL.REQ_KENAIKAN_GAJI_VIEW'],
                'hrl_tower_rekrutmen'            => ['HRL.REQ_REKRUTMEN_VIEW'],

                // ── ITC ────────────────────────────────────────────────────────
                'itc_dashboard'                  => ['DASHBOARD.ITC_VIEW'],
                'itc_users'                      => ['TOOLS.ITC_RESET_PASSWORD','SYSTEM.USER_MANAGE'],
                'rbac'                           => ['SYSTEM.RBAC_MANAGE','SYSTEM.RBAC_VIEW'],
                'nav_manager'                    => ['SYSTEM.CONFIG_MANAGE'],
                'tools'                          => ['TOOLS.VIEW'],
                'system_config'                  => ['SYSTEM.CONFIG_MANAGE'],

                // ── MPR ────────────────────────────────────────────────────────
                'mpr_main'                       => ['MPR.VIEW'],
                'mpr_plans'                      => ['MPR.PLAN_VIEW','MPR.PLAN_CREATE','MPR.VIEW'],
                'mpr_pipeline'                   => ['MPR.VIEW'],
                'mpr_visits'                     => ['MPR.VIEW','MPR.PLAN_CREATE'],
                'mpr_budget_fin'                 => ['MPR.VIEW'],
                'mpr_ops_daily_fin'              => ['MPR.VIEW'],
                'mpr_panduan'                    => ['MPR.VIEW'],

                // ── PQP tambahan (mod_card / import) ──────────────────────────
                'purchases_ceisa_pib'            => ['PURCHASES.CEISA_PIB','PURCHASES.CEISA_VIEW','PURCHASES.CEISA_EDIT'],
                'purchases_reports'              => ['PURCHASES.REPORTS_VIEW','PURCHASES.AP_INVOICE_CRUD','PURCHASES.AP_PAYMENT_CRUD'],

                // ── MASTER HUB (pintasan Master Data Center) ─────────────────
                'docs_modules_hub'               => ['DOCS.VIEW'],
                'mh_master_products'             => ['MASTER.PRODUCT_VIEW','MASTER.VIEW'],
                'mh_master_office'               => ['MASTER.OFFICE_VIEW','MASTER.VIEW'],
                'mh_master_user'                 => ['MASTER.PIC_CUSTOMER_VIEW','MASTER.VIEW'],
                'mh_master_emailcompany'         => ['MASTER.EMAIL_COMPANY_VIEW','MASTER.VIEW'],
                'mh_master_export_customers'     => ['MASTER.CUSTOMER_EXPORT','MASTER.CUSTOMER_VIEW'],
                'mh_import_rekening_final'       => ['HRL.IMPORT_REKENING','MASTER.COMPANY_BANK_VIEW','PURCHASES.AP_PAYMENT_VIEW','PURCHASES.AP_PAYMENT_CREATE','PURCHASES.AP_PAYMENT_EDIT'],
                'mh_api_partner_keys'            => ['SYSTEM.API_PARTNER_KEYS','SYSTEM.CONFIG_MANAGE'],
                'mh_mfa_policy'                  => ['SYSTEM.MFA_POLICY_MANAGE','SYSTEM.USER_MANAGE','SYSTEM.CONFIG_MANAGE'],

                // ── SYSTEM ─────────────────────────────────────────────────────
                'master'                         => ['MASTER.VIEW'],
                'monitoring_center'              => ['SYSTEM.AUDIT_LOG_VIEW','SYSTEM.JOBS_MONITOR','SYSTEM.USER_MANAGE','SYSTEM.CONFIG_MANAGE','MASTER.ADMIN_CENTER'],
                'audit_log'                      => ['SYSTEM.AUDIT_LOG_VIEW'],

                // ── BRANCH ─────────────────────────────────────────────────────
                'branch_home'                    => ['DASHBOARD.BRANCH_VIEW'],
                'branch_do'                      => ['SALES.VIEW'],
                'branch_ct'                      => ['SALES.CONTROL_TOWER_VIEW','SALES.VIEW'],
                'branch_sales_panduan'           => ['PANDUAN.SALES_VIEW','SALES.VIEW'],
                'branch_stok'                    => ['STOCK.VIEW'],
                'branch_pr'                      => ['WQS.PR_VIEW'],
                'branch_incoming'                => ['WQS.INCOMING_VIEW'],
                'branch_po'                      => ['PURCHASES.PO_VIEW'],
                'branch_gr'                      => ['PURCHASES.GR_VIEW'],
                'branch_purchases_panduan'       => ['PANDUAN.PURCHASES_VIEW','PURCHASES.VIEW'],
            ];
            $_permCfg = $_permByKey[(string)$_ni['key']] ?? null;
        }
        if ($isAdminUi) {
            // SYS/Admin → selalu tampil, skip semua check
        } elseif ($_permCfg !== null) {
            // Ada permission dikonfigurasi → cek permission dulu.
            // Bila Nav Manager punya override 'roles' untuk key ini, tetap wajibkan dept/role/level
            // masuk daftar roles (supaya "matikan menu untuk BRANCH" benar-benar terasa).
            $_perms  = is_array($_permCfg) ? $_permCfg : [$_permCfg];
            $_permOk = false;
            if (function_exists('can_any')) {
                $_permOk = can_any($_perms);
            } elseif (function_exists('can')) {
                foreach ($_perms as $_p) { if (can($_p)) { $_permOk = true; break; } }
            } else {
                $_permOk = true; // fallback jika fungsi belum tersedia
            }
            if (!$_permOk) {
                continue;
            }
            if (!empty($_ni['_nav_ov_roles'])) {
                $_itemRoles = $_ni['roles'] ?? 'ALL';
                if ($_itemRoles !== 'ALL') {
                    $_allowed = array_map('strtoupper', array_map('trim', explode(',', (string)$_itemRoles)));
                    $_pass    = in_array($dept,  $_allowed, true)
                             || in_array($role,  $_allowed, true)
                             || in_array($level, $_allowed, true);
                    if (!$_pass) {
                        continue;
                    }
                }
            }
        } else {
            // Tidak ada permission → fallback ke roles (dept-based) untuk kompatibilitas
            $_itemRoles = $_ni['roles'] ?? 'ALL';
            if ($_itemRoles !== 'ALL') {
                $_allowed = array_map('strtoupper', array_map('trim', explode(',', (string)$_itemRoles)));
                $_pass    = in_array($dept,  $_allowed, true)
                         || in_array($role,  $_allowed, true)
                         || in_array($level, $_allowed, true);
                if (!$_pass) continue;
            }
        }

        // Item lolos — emit pending section dulu
        if ($_pendingSection !== null) {
            $_renderItems[] = $_pendingSection;
            $_pendingSection = null;
        }
        $_ni['_href'] = $_href;
        $_renderItems[] = $_ni;
    }

    // ── Render ───────────────────────────────────────────────────────────
    foreach ($_renderItems as $_ni):
        if (isset($_ni['section'])) {
            echo '      <div class="rmi-nav-section">' . htmlspecialchars($_ni['section'], ENT_QUOTES) . "</div>\n";
            continue;
        }
        $_key      = $_ni['key'] ?? '';
        $_roles    = $_ni['roles'] ?? 'ALL';
        $_icon     = $_ni['icon'] ?? '';
        $_label    = htmlspecialchars($_ni['label'] ?? '', ENT_QUOTES);
        $_isActive = ($_key !== '' && $activeKey === $_key) ? 'active' : '';
        $_hrefEsc  = htmlspecialchars($_ni['_href'], ENT_QUOTES);
        $_rolesEsc = htmlspecialchars($_roles, ENT_QUOTES);

        echo "      <a class=\"nav-link {$_isActive}\" data-roles=\"{$_rolesEsc}\" href=\"{$_hrefEsc}\">{$_icon} {$_label}</a>\n";
    endforeach;
?>
    </nav>
    <div class="rmi-user">
      <div class="small opacity-75">Login: <strong><?= rmi_ui_h($user['username'] ?? '-') ?></strong></div>
      <div class="small opacity-75">Role: <?= rmi_ui_h($role ?: '-') ?> | Dept: <?= rmi_ui_h($dept ?: '-') ?></div>
      <div class="small opacity-75">Level: <?= rmi_ui_h($level ?: '-') ?><?php if ($office !== ''): ?> | Office: <?= rmi_ui_h($office) ?><?php endif; ?></div>
      <form method="post" action="<?= $baseProject ?>/master/logout.php" class="mt-2">
        <button class="btn btn-outline-light btn-sm w-100" type="submit">Logout</button>
      </form>
    </div>
<?php
    $navHtml = ob_get_clean();
    $GLOBALS['_rmi_layout_state']['nav_html'] = $navHtml;

    // ── Output HTML ──
?><!doctype html>
<html lang="id" data-theme="dark" data-rmi-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#1e293b">
  <title><?= rmi_ui_h($title) ?></title>
  <link rel="manifest" href="<?= rmi_ui_h($baseProject) ?>/public/manifest.json">
  <link rel="stylesheet" href="<?= rmi_ui_h($bootstrapCss) ?>">
  <link rel="stylesheet" href="<?= $baseProject ?>/_shared/rmi.css">
  <style>
@media (max-width: 768px){
  html, body {
    overflow-x: hidden;
    touch-action: manipulation;
  }

  .rmi-topbar{
    position: relative !important;
    z-index: 10 !important;
  }

  .rmi-shell,
  .rmi-main,
  .rmi-content,
  .rmi-container,
  .rmi-surface{
    position: relative;
    z-index: 1;
  }

  input, select, textarea, button{
    pointer-events: auto !important;
    touch-action: manipulation;
    font-size: 16px !important;
    min-height: 44px;
  }
}
</style>
<?php if (!empty($opts['extra_head'])) echo $opts['extra_head']; ?>
</head>
<body class="<?= rmi_ui_h($bodyClass) ?>" data-base-project="<?= rmi_ui_h($baseProject) ?>" data-user-role="<?= rmi_ui_h($roleView) ?>" data-user-role-raw="<?= rmi_ui_h($role) ?>" data-user-dept="<?= rmi_ui_h($dept) ?>" data-user-level="<?= rmi_ui_h($level) ?>" data-user-office="<?= rmi_ui_h($office) ?>">
<?php if (rmi_env_watermark_enabled()): ?>
<style>
  .rmi-env-watermark{
    position:fixed;right:14px;bottom:10px;z-index:1045;
    font-size:11px;letter-spacing:.6px;text-transform:uppercase;
    padding:5px 8px;border-radius:6px;color:#f8fafc;
    box-shadow:0 2px 8px rgba(0,0,0,.25);pointer-events:none;
  }
  .rmi-env-watermark.is-dev{
    background:rgba(37,99,235,.88);
    border:1px solid rgba(30,64,175,.75);
  }
  .rmi-env-watermark.is-qa{
    background:rgba(8,145,178,.9);
    border:1px solid rgba(14,116,144,.78);
  }
  .rmi-env-watermark.is-staging{
    background:rgba(234,88,12,.9);
    border:1px solid rgba(194,65,12,.78);
  }
  .rmi-env-watermark.is-prod{
    background:rgba(220,38,38,.9);
    border:1px solid rgba(153,27,27,.8);
  }
</style>
<div class="rmi-env-watermark <?= rmi_ui_h(rmi_env_watermark_class()) ?>"><?= rmi_ui_h(rmi_env_watermark_text()) ?></div>
<?php endif; ?>

<div class="rmi-shell rmi-shell-topnav">
  <main class="rmi-main">
    <div class="rmi-topbar">
      <div class="rmi-topbar-lead flex-grow-1 min-w-0 pe-md-2">
        <div class="rmi-page-title"><?= rmi_ui_h($title) ?></div>
<?php if (!empty($opts['subtitle'])): ?>
        <div class="rmi-page-subtitle"><?= rmi_ui_h((string)$opts['subtitle']) ?></div>
<?php endif; ?>
<?php if (!empty($breadcrumbs)): ?>
        <?= rmi_ui_breadcrumb_html($breadcrumbs) ?>
<?php endif; ?>
      </div>
      <div class="rmi-topbar-actions">
        <button class="btn btn-outline-light btn-sm rmi-topbar-icon-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#rmiMenuDrawer" aria-label="Buka menu ERP" title="Menu ERP"><?= rmi_icon('menu') ?> <span class="rmi-topbar-btn-label">Menu</span></button>
        <!-- Satu-satunya tombol tema: gelap/terang. (Toggle kontras dihapus agar konsisten.) -->
        <button class="btn btn-outline-light btn-sm rmi-topbar-icon-btn" type="button" id="rmiThemeToggle" aria-label="Ganti tema terang/gelap" title="Tema"
                data-icon-dark="<?= rmi_ui_h(rmi_icon('moon')) ?>" data-icon-light="<?= rmi_ui_h(rmi_icon('sun')) ?>"><?= rmi_icon('moon') ?></button>
        <button class="btn btn-outline-light btn-sm rmi-topbar-icon-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#rmiHelpCanvas" aria-label="Bantuan halaman ini" title="Bantuan (F1)"><?= rmi_icon('question') ?> <span class="rmi-topbar-btn-label">Help</span> <span class="rmi-kbd rmi-topbar-kbd-f1">F1</span></button>
        <a class="btn btn-outline-light btn-sm rmi-topbar-icon-btn" href="<?= $baseProject ?>/docs/help_center.php" target="_blank" rel="noopener" title="Help Center / Manual" aria-label="Buka Help Center"><span aria-hidden="true"><?= rmi_icon('books') ?></span> <span class="rmi-topbar-btn-label">Manual</span></a>
        <div class="dropdown rmi-topbar-user-dd">
          <button class="btn btn-outline-light btn-sm dropdown-toggle text-truncate rmi-topbar-user-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"
                  title="<?= rmi_ui_h(($user['username'] ?? '-') . ' / ' . ($role ?: '-')) ?>"><?= rmi_icon('user', 'rmi-topbar-user-avatar') ?> <span class="rmi-topbar-user-name"><?= rmi_ui_h($user['username'] ?? '-') ?></span> <span class="rmi-topbar-user-meta"><span class="rmi-topbar-user-sep">/</span> <?= rmi_ui_h($role ?: '-') ?></span></button>
          <ul class="dropdown-menu dropdown-menu-end rmi-dd-menu">
            <li class="dropdown-header rmi-dd-header">Akun ERP</li>
            <li class="dropdown-item-text rmi-dd-identity">
              <div class="rmi-dd-user">
                <span class="rmi-dd-user-avatar"><?= rmi_icon('user') ?></span>
                <span class="rmi-dd-user-body">
                  <span class="rmi-dd-user-name"><?= rmi_ui_h($user['username'] ?? '-') ?></span>
                  <span class="rmi-dd-user-full"><?= rmi_ui_h($user['full_name'] ?? '') ?></span>
                </span>
              </div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><span class="dropdown-item-text rmi-dd-row"><?= rmi_icon('shield') ?><span>Peran</span><b><?= rmi_ui_h($role ?: '-') ?></b></span></li>
            <?php if ($dept !== ''): ?>
            <li><span class="dropdown-item-text rmi-dd-row"><?= rmi_icon('office') ?><span>Departemen</span><b><?= rmi_ui_h($dept) ?></b></span></li>
            <?php endif; ?>
            <?php if ($office !== ''): ?>
            <li><span class="dropdown-item-text rmi-dd-row"><?= rmi_icon('pin') ?><span>Unit</span><b><?= rmi_ui_h($office) ?></b></span></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li class="px-3 py-1"><form method="post" action="<?= $baseProject ?>/master/logout.php"><?php if (function_exists('csrf_field')) echo csrf_field(); ?><button class="btn btn-sm btn-outline-light w-100 rmi-dd-logout" type="submit"><?= rmi_icon('logout') ?> Logout</button></form></li>
          </ul>
        </div>
<?php if ($actionsHtml !== '') echo $actionsHtml; ?>
      </div>
    </div>
    <div class="rmi-content">
      <div class="rmi-container">
        <div class="rmi-surface">
<?php
}


// ═══════════════════════════════════════════════════════════
// rmi_footer() — close page
// ═══════════════════════════════════════════════════════════
function rmi_footer(): void
{
    $state       = $GLOBALS['_rmi_layout_state'] ?? [];
    $baseProject = (string)($state['base_project'] ?? rmi_layout_base_project());
    $extraJs     = (string)($state['extra_js'] ?? '');
    $navHtml     = (string)($state['nav_html'] ?? '');
    $navHtml     = $navHtml ?? '';
    $bootstrapJs = (string)($state['bootstrap_js'] ?? '');

    if ($bootstrapJs === '') {
        $ab = function_exists('rmi_assets_base') ? rmi_assets_base() : $baseProject;
        $bootstrapJs = $ab . '/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209';
    }
    if (trim($navHtml) === '') {
        $navHtml = '<div class="rmi-brand"><div class="rmi-brand-title">RMI ERP</div><div class="rmi-brand-sub">Unified Layout</div></div>'
            . '<nav class="nav flex-column rmi-nav">'
            . '<div class="rmi-nav-section">MAIN</div>'
            . '<a class="nav-link" href="' . rmi_ui_h($baseProject . '/dashboards/index.php') . '">📊 Dashboards</a>'
            . '<a class="nav-link" href="' . rmi_ui_h($baseProject . '/docs/help_center.php') . '">❓ Help Center</a>'
            . '</nav>';
    }
?>
        </div><!-- /.rmi-surface -->
      </div><!-- /.rmi-container -->
    </div><!-- /.rmi-content -->
  </main>
</div><!-- /.rmi-shell -->

<!-- Menu Drawer (offcanvas) -->
<div class="offcanvas offcanvas-start text-bg-dark rmi-apps-canvas" tabindex="-1" id="rmiMenuDrawer" aria-labelledby="rmiMenuDrawerLabel">
  <div class="offcanvas-header rmi-drawer-head">
    <h5 class="offcanvas-title" id="rmiMenuDrawerLabel">Menu ERP</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    <div class="rmi-drawer-search">
      <span class="rmi-drawer-search-icon" aria-hidden="true"><?= rmi_icon('search') ?></span>
      <input type="search" id="rmiMenuSearch" class="rmi-drawer-search-input"
             placeholder="Cari menu… (press /)" aria-label="Cari menu"
             autocomplete="off" spellcheck="false">
      <button type="button" id="rmiMenuSearchClear" class="rmi-drawer-search-clear" aria-label="Bersihkan pencarian" hidden>&times;</button>
    </div>
    <div class="rmi-drawer-count" id="rmiMenuCount" aria-live="polite"></div>
  </div>
  <div class="offcanvas-body rmi-drawer-body"><?= $navHtml ?>
    <div class="rmi-drawer-empty" id="rmiMenuEmpty" hidden>
      <p class="rmi-drawer-empty-title">Menu tidak ditemukan</p>
      <p class="rmi-drawer-empty-text">Tidak ada menu yang cocok dengan kata kunci itu.</p>
      <button type="button" class="btn btn-sm btn-outline-light rmi-drawer-empty-btn" id="rmiMenuReset">Tampilkan semua menu</button>
    </div>
  </div>
</div>

<?php
// Help F1: render konten langsung di server agar selalu tampil (tanpa bergantung JS/fetch)
$helpMapPath = defined('RMI_ROOT') ? (RMI_ROOT . '/docs/help_sop_map.json') : (__DIR__ . '/../docs/help_sop_map.json');
$helpMap = null;
if (is_file($helpMapPath)) {
  $raw = @file_get_contents($helpMapPath);
  if ($raw !== false) {
    $helpMap = json_decode($raw, true);
  }
}
$helpRelPath = '';
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
if ($baseProject !== '' && strpos($scriptName, $baseProject) === 0) {
  $helpRelPath = substr($scriptName, strlen($baseProject));
  if ($helpRelPath === '' || $helpRelPath[0] !== '/') {
    $helpRelPath = '/' . ltrim($helpRelPath, '/');
  }
} else {
  $helpRelPath = $scriptName ?: '/';
}
$helpRelPath = rtrim($helpRelPath, '/') ?: '/';
$helpEntry = null;
if ($helpMap && is_array($helpMap['map'] ?? null)) {
  $arr = $helpMap['map'];
  $best = null;
  foreach ($arr as $e) {
    if (empty($e['path'])) continue;
    $p = rtrim($e['path'], '/');
    $match = ($helpRelPath === $p || strpos($helpRelPath, $p) === 0);
    if ($match && ($best === null || strlen($p) > strlen($best['path'] ?? ''))) {
      $best = $e;
    }
  }
  if (!$best && strpos($helpRelPath, '/') > 0) {
    $parts = explode('/', trim($helpRelPath, '/'));
    $known = ['dashboards','sales','purchases','stock','master','kpi','hrl','hrl_process','hrl_reg_alkes','tools','chat','docs','api','absensi','payroll','mpr','rbac','fixed_asset','Fixed_Asset'];
    if (count($parts) > 1 && !in_array(strtolower($parts[0]), $known, true)) {
      $relAlt = '/' . implode('/', array_slice($parts, 1));
      foreach ($arr as $e) {
        if (empty($e['path'])) continue;
        $p = rtrim($e['path'], '/');
        if ($relAlt === $p || strpos($relAlt, $p) === 0) {
          if ($best === null || strlen($p) > strlen($best['path'] ?? '')) {
            $best = $e;
          }
        }
      }
    }
  }
  $helpEntry = $best ?? ($helpMap['default'] ?? null);
} elseif ($helpMap && isset($helpMap['default'])) {
  $helpEntry = $helpMap['default'];
}
$helpHtml = '';
if ($helpEntry) {
  $links = $helpEntry['links'] ?? [];
  if (empty($links)) {
    if (!empty($helpEntry['sop'])) $links[] = ['label' => 'SOP', 'url' => $helpEntry['sop']];
    if (!empty($helpEntry['manual'])) $links[] = ['label' => 'Manual', 'url' => $helpEntry['manual']];
    if (!empty($helpEntry['quick_start'])) $links[] = ['label' => 'Quick Start', 'url' => $helpEntry['quick_start']];
    if (!empty($helpEntry['sop_keluhan'])) $links[] = ['label' => 'SOP Keluhan', 'url' => $helpEntry['sop_keluhan']];
    if (!empty($helpEntry['sop_recall'])) $links[] = ['label' => 'SOP Recall', 'url' => $helpEntry['sop_recall']];
  }
  $title = $helpEntry['title'] ?? 'Bantuan';
  $purpose = $helpEntry['purpose'] ?? 'Panduan singkat untuk halaman ini.';
  $diagram = $helpEntry['diagram'] ?? '';
  $diagramTitle = trim($helpEntry['diagram_title'] ?? '') ?: 'Alur proses';
  $steps = $helpEntry['steps'] ?? [];
  $tips = $helpEntry['tips'] ?? [];
  $base = $baseProject ?: '';
  $diagramUrl = $diagram ? ($base . (strpos($diagram, '/') === 0 ? $diagram : '/' . $diagram)) : '';
  $helpHtml = '<h6>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h6>';
  $helpHtml .= '<div class="rmi-muted small mb-2">' . htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8') . '</div>';
  if ($diagramUrl) {
    $helpHtml .= '<div class="small fw-semibold mb-1">' . htmlspecialchars($diagramTitle, ENT_QUOTES, 'UTF-8') . '</div>';
    $helpHtml .= '<div class="rmi-help-diagram-link" data-diagram-url="' . htmlspecialchars($diagramUrl, ENT_QUOTES, 'UTF-8') . '" role="button" tabindex="0" title="Klik untuk buka diagram full size">';
    $helpHtml .= '<img class="rmi-help-diagram" src="' . htmlspecialchars($diagramUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($diagramTitle, ENT_QUOTES, 'UTF-8') . '"></div>';
    $helpHtml .= '<a class="rmi-help-link mt-2 d-inline-block" href="' . htmlspecialchars($diagramUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">↗ Buka diagram full size</a>';
  }
  if (!empty($steps)) {
    $helpHtml .= '<div class="small fw-semibold mt-2 mb-1">Langkah kerja</div><ul class="small mb-0">';
    foreach ($steps as $s) {
      $helpHtml .= '<li>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $helpHtml .= '</ul>';
  }
  if (!empty($tips)) {
    $helpHtml .= '<div class="small fw-semibold mt-2 mb-1">Tips</div><ul class="small mb-0">';
    foreach ($tips as $s) {
      $helpHtml .= '<li>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $helpHtml .= '</ul>';
  }
  if (!empty($links)) {
    $helpHtml .= '<div class="small fw-semibold mt-2 mb-1">Dokumen lengkap</div><div class="d-flex flex-column gap-1">';
    foreach ($links as $l) {
      if (empty($l['url'])) continue;
      $url = $base . (strpos($l['url'], '/') === 0 ? $l['url'] : '/' . $l['url']);
      $label = $l['label'] ?? $l['url'];
      $helpHtml .= '<a class="rmi-help-link" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">🔗 ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    $helpHtml .= '</div>';
  }
  $panduanPath = $helpRelPath;
  if ($panduanPath !== '' && $panduanPath[0] !== '/') {
    $panduanPath = '/' . $panduanPath;
  }
  if ($panduanPath !== '' && preg_match('#\.php$#i', $panduanPath)) {
    $panduanUrl = $base . '/docs/panduan_view.php?p=' . rawurlencode($panduanPath);
    $helpHtml .= '<div class="small fw-semibold mt-2 mb-1">Panduan file (docs/panduan/)</div>';
    $helpHtml .= '<a class="rmi-help-link" href="' . htmlspecialchars($panduanUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">📄 Buka panduan halaman ini</a>';
  }
  $helpHtml .= '<div class="rmi-muted small mt-3">Halaman: <code>' . htmlspecialchars($helpRelPath, ENT_QUOTES, 'UTF-8') . '</code></div>';
} else {
  $base = $baseProject ?: '';
  $helpHtml = '<h6>Bantuan</h6><div class="rmi-muted small mb-2">Tidak dapat memuat mapping SOP. Gunakan link di bawah.</div>';
  $helpHtml .= '<ul class="small mb-0"><li>Gunakan tombol ☰ Menu untuk navigasi.</li><li>Tekan 📚 Manual untuk Help Center.</li></ul>';
  $helpHtml .= '<div class="small fw-semibold mt-2 mb-1">Dokumen lengkap</div><div class="d-flex flex-column gap-1">';
  $helpHtml .= '<a class="rmi-help-link" href="' . htmlspecialchars($base . '/docs/help_center.php', ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">🔗 Help Center / indeks panduan</a></div>';
  $helpHtml .= '<div class="rmi-muted small mt-3">Halaman: <code>' . htmlspecialchars($helpRelPath, ENT_QUOTES, 'UTF-8') . '</code></div>';
}
?>
<!-- Help Offcanvas (F1) - Lebar 560px agar diagram proses terbaca -->
<div class="offcanvas offcanvas-end text-bg-dark" tabindex="-1" id="rmiHelpCanvas" aria-labelledby="rmiHelpCanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="rmiHelpCanvasLabel">Bantuan Halaman Ini</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <div id="rmiHelpBody" class="small rmi-help-box"><?= $helpHtml ?></div>
  </div>
</div>
<script src="<?= rmi_ui_h($bootstrapJs) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){

  if(window.innerWidth <= 768){

    document.querySelectorAll('input, textarea, select').forEach(el=>{
      el.style.pointerEvents = 'auto';
      el.removeAttribute('readonly');

      el.addEventListener('touchstart', ()=>{
        el.focus();
      }, {passive:true});
    });

    document.querySelectorAll('button').forEach(btn=>{
      btn.style.pointerEvents = 'auto';
    });

    // Fix offcanvas backdrop
    document.querySelectorAll('.offcanvas').forEach(el=>{
      el.addEventListener('hidden.bs.offcanvas', function(){
        document.querySelectorAll('.offcanvas-backdrop').forEach(b=>b.remove());
      });
    });

  }

});
</script>
<script src="<?= rmi_ui_h($baseProject) ?>/_shared/rmi_assist.js?v=20260929"></script>
<script>
(function(){
  var b=document.body.dataset.baseProject||'';if('serviceWorker'in navigator){navigator.serviceWorker.register(b+'/public/sw.js').catch(function(){});}
  // Theme + Contrast — inline agar pasti jalan di semua halaman
  function rmiApplyTheme(m){
    document.documentElement.setAttribute('data-theme',m);
    document.documentElement.setAttribute('data-rmi-theme',m);
    var btn=document.getElementById('rmiThemeToggle');if(btn)btn.textContent=m==='light'?'\u2600\uFE0F':'\uD83C\uDF19';
  }
  function rmiApplyContrast(m){
    if(m==='high')document.body.classList.add('theme-contrast');else document.body.classList.remove('theme-contrast');
    var btn=document.getElementById('rmiContrastToggle');
    if(btn){btn.textContent=m==='high'?'\uD83D\uDD06':'\u25D0';btn.setAttribute('aria-pressed',m==='high'?'true':'false');}
  }
  function rmiBindToggles(){
    var themeBtn=document.getElementById('rmiThemeToggle');
    var contrastBtn=document.getElementById('rmiContrastToggle');
    if(themeBtn){
      var t='dark';try{var s=localStorage.getItem('rmi_theme')||localStorage.getItem('rmiTheme');if(s==='light'||s==='dark')t=s;}catch(e){}
      rmiApplyTheme(t);
      themeBtn.onclick=function(){
        var next=(document.documentElement.getAttribute('data-rmi-theme')==='light')?'dark':'light';
        rmiApplyTheme(next);
        try{localStorage.setItem('rmi_theme',next);localStorage.setItem('rmiTheme',next);}catch(e){}
      };
    }
    if(contrastBtn){
      var c='normal';try{var s=localStorage.getItem('ui_contrast');if(s==='high'||s==='normal')c=s;}catch(e){}
      rmiApplyContrast(c);
      contrastBtn.onclick=function(){
        var mode=document.body.classList.contains('theme-contrast')?'normal':'high';
        rmiApplyContrast(mode);
        try{localStorage.setItem('ui_contrast',mode);}catch(e){}
      };
    }
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',rmiBindToggles);else rmiBindToggles();
})();
</script>
<?php if (trim($extraJs) !== ''): ?>
<?= $extraJs ?>
<?php endif; ?>
</body>
</html>
<?php
}
