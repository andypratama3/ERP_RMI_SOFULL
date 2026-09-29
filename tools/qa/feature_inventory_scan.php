<?php
/**
 * Feature Inventory Scan — CLI. Deteksi fitur per halaman PHP secara statis.
 *
 * Output:
 *   tools/qa/ERP_FEATURE_INVENTORY.json     (mesin)
 *   tools/qa/ERP_FULL_TRACKER.md            (manusia, oleh --tracker)
 *
 * Kenapa tool ini dibuat: ERP_FEATURE_INVENTORY.json sebelumnya dibuat
 * ad-hoc tanpa generator, dan kolom filters/update_actions/workflow_states/
 * audit_events tercatat KOSONG untuk 346 fitur padahal kodenya jelas
 * punya (mis. sales/sales_do.php: 5 form filter, 42 akses $_POST). Jadi
 * tracker lama tidak bisa dipakai sebagai acuan. Tool ini meantinya
 * reproducible, dan --self-test memaksa kontrasnya terhadap ground truth.
 *
 * CLI only. Tidak menyentuh DB, tidak menebakan status: `status` di
 * keluaran selalu "UNDETECTED" sampai ada bukti manual (lihat tracker).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);

$args    = $_SERVER['argv'] ?? [];
$optSelf = in_array('--self-test', $args, true);
$optTrk  = in_array('--tracker', $args, true);
$optOut  = $root . '/tools/qa/ERP_FEATURE_INVENTORY.json';
$optTrkF = $root . '/tools/qa/ERP_FULL_TRACKER.md';

$SKIP_DIRS = ['_backup', 'vendor', 'node_modules', '.git', 'tools', 'docs', 'app'];
$SKIP_FILE = ['_auth.php']; // bukan halaman fitur

/** Modul -> grup bisnis (mengikuti labels yang sudah dipakai tracker). */
function rmi_core(string $module): string
{
    $map = [
        'master' => 'Master Data', 'sales' => 'Sales / CRM',
        'purchases' => 'Purchases / Procurement', 'stock' => 'WQS / Warehouse / Stock',
        'customer_portal' => 'Portal / API', 'manufacturer_portal' => 'Portal / API',
        'dashboards' => 'Dashboard / Help', 'kpi' => 'KPI / MPR', 'mpr' => 'KPI / MPR',
        'hrl' => 'HRL / HR', 'hrl_process' => 'HRL / HR', 'payroll' => 'HRL / Payroll',
        'absensi' => 'HRL / Absensi', 'config' => 'RBAC / Security', 'api' => 'Portal / API',
        'rbac' => 'RBAC / Security', 'chat' => 'Dashboard / Chat',
        'Fixed_Asset' => 'Fixed Asset', 'stock_opname' => 'WQS / Warehouse / Stock',
    ];
    return $map[$module] ?? ucfirst($module);
}

function rmi_walk(string $dir, array $skip, array $skipFile): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        $p = $f->getPathname();
        if ($f->isDir()) {
            $base = $f->getBasename();
            if (in_array($base, $skip, true) || str_starts_with($base, '.')) {
                $it->getInnerIterator()->getChildren() ? null : null;
            }
            continue;
        }
        if ($f->getExtension() !== 'php') { continue; }
        if (in_array($f->getBasename(), $skipFile, true)) { continue; }
        $rel = ltrim(str_replace($dir, '', $p), '/');
        $out[] = $rel;
    }
    // Buang yang direktori induknya di-skip.
    $out = array_values(array_filter($out, static function (string $rel) use ($skip): bool {
        $parts = explode('/', $rel);
        array_pop($parts);
        foreach ($parts as $p) {
            if (in_array($p, $skip, true)) { return false; }
        }
        return true;
    }));
    sort($out);
    return $out;
}

/** Ambil string literal unik dari blok preg_match_all. */
function rmi_uniq(array $a): array
{
    $a = array_values(array_unique(array_filter(array_map('trim', $a), static fn($s) => $s !== '')));
    sort($a);
    return $a;
}

function rmi_scan(string $abs, string $rel): array
{
    $src = (string)@file_get_contents($abs);
    if ($src === '') { return []; }
    // Buang blok komentar supaya string di dalam komentar tidak ikut terhitung.
    $code = preg_replace('~/\*.*?\*/~s', '', $src);
    $code = preg_replace('~^\s*//.*$~m', '', (string)$code);
    $code = preg_replace('~//[^\n]*~', '', (string)$code);

    $module = str_contains($rel, '/') ? explode('/', $rel)[0] : '(root)';

    // ---- FILTERS -------------------------------------------------------
    $filterFields = [];
    // Nama field di dalam <form ... method="get">
    if (preg_match_all('~<form\b[^>]*method\s*=\s*["\']?get["\']?[^>]*>(.*?)</form>~is', (string)$code, $fm)) {
        foreach ($fm[1] as $block) {
            if (preg_match_all('~<input\b[^>]*\bname\s*=\s*["\']([a-zA-Z0-9_]+)["\']~i', $block, $m)) {
                $filterFields = array_merge($filterFields, $m[1]);
            }
            if (preg_match_all('~<select\b[^>]*\bname\s*=\s*["\']([a-zA-Z0-9_]+)["\']~i', $block, $m)) {
                $filterFields = array_merge($filterFields, $m[1]);
            }
        }
    }
    // Kunci $_GET yang benar-benar dibaca.
    if (preg_match_all('~\$_GET\[\s*["\']([a-zA-Z0-9_]+)["\']\s*\]~', (string)$code, $m)) {
        $filterFields = array_merge($filterFields, $m[1]);
    }
    // Keyword filter lazim (dipakai walau tanpa form GET eksplisit).
    if (preg_match_all('~\b(search|keyword|filter|q|status|office|dept|department|customer|supplier|date_from|date_to|per_page|page)\b\s*=>~i', (string)$code, $m)) {
        $filterFields = array_merge($filterFields, $m[1]);
    }
    $filterFields = rmi_uniq($filterFields);

    // ---- UPDATE ACTIONS ------------------------------------------------
    $actions = [];
    if (preg_match_all('~\$_POST\[\s*["\']action["\']\s*\]~', (string)$code)) {
        if (preg_match_all('~\$(?:action|act|op)\s*(?:===|==|!==)\s*["\']([a-z0-9_]+)["\']~i', (string)$code, $m)) {
            $actions = array_merge($actions, $m[1]);
        }
        if (preg_match_all('~in_array\(\s*\$(?:action|act|op)\s*,\s*\[([^\]]+)\]~i', (string)$code, $m)) {
            foreach ($m[1] as $list) {
                if (preg_match_all('~["\']([a-z0-9_]+)["\']~i', $list, $mm)) { $actions = array_merge($actions, $mm[1]); }
            }
        }
    }
    if (preg_match_all('~case\s+["\']([a-z0-9_]+)["\']\s*:~i', (string)$code, $m)) {
        $actions = array_merge($actions, $m[1]);
    }
    if (preg_match_all('~name\s*=\s*["\']([a-z0-9_]*(?:action|act|op))["\']~i', (string)$code, $m)) {
        $actions = array_merge($actions, $m[1]);
    }
    $actions = rmi_uniq($actions);

    // ---- WORKFLOW STATES -----------------------------------------------
    $states = [];
    $stateKw = '(ready_scm|on_delivery|delivered|ready|draft|submitted|approved|rejected|cancelled|'
             . 'pending|completed|in_progress|on_hold|returned|revision|closed|open|posted|paid|void)';
    if (preg_match_all('~["\'](' . $stateKw . ')["\']~i', (string)$code, $m)) {
        $states = array_merge($states, $m[1]);
    }
    $states = rmi_uniq($states);

    // ---- AUDIT EVENTS ---------------------------------------------------
    $audit = [];
    foreach (['rmi_audit_safe', 'audit_log', 'log_audit'] as $fn) {
        if (preg_match_all('~\b' . $fn . '\s*\(\s*["\']([a-z0-9_.]+)["\']~i', (string)$code, $m)) {
            $audit = array_merge($audit, $m[1]);
        }
    }
    $audit = rmi_uniq($audit);

    // ---- PERMISSIONS ----------------------------------------------------
    // PENTING: daftar helper di bawah HARUS mengikuti konvensi repo ini.
    // Versi pertama hanya mencari rmi_require_perm/has_perm/can() dan
    // melaporkan "93% halaman tanpa cek permission" — itu FALSE NEGATIVE,
    // karena repo Dominan memakai require_any_permission()/can_any().
    $permFns = [
        'require_any_permission', 'require_permission', 'require_all_permissions',
        'can_any', 'can_dept', 'can_view_buy', 'can_edit_prices', 'can_bulk',
        'rmi_require_perm', 'has_perm', 'has_permission',
    ];
    $perms = [];
    foreach ($permFns as $fn) {
        if (preg_match_all('~\b' . $fn . '\s*\((.*?)\)~is', (string)$code, $m)) {
            foreach ($m[1] as $args) {
                // Ambil argumen string literal: konstanta perms, bukan nama fungsi.
                if (preg_match_all('~["\']([A-Za-z0-9_]+\.[A-Za-z0-9_.]+)["\']~', $args, $mm)) {
                    $perms = array_merge($perms, $mm[1]);
                }
            }
        }
    }
    $perms = rmi_uniq($perms);

    // ---- CRUD + LAINNYA -------------------------------------------------
    $hasPost   = (bool)preg_match('~\$_POST\b~', (string)$code);
    $hasCreate = (bool)preg_match('~\bINSERT\s+INTO\b~i', (string)$code);
    $hasUpdate = (bool)preg_match('~\bUPDATE\s+[\w`]+\s+SET\b~i', (string)$code);
    $hasDelete = (bool)preg_match('~\bDELETE\s+FROM\b~i', (string)$code);
    $hasSelect = (bool)preg_match('~\bSELECT\b~i', (string)$code);
    $crud = '';
    if ($hasCreate) { $crud .= 'C'; }
    if ($hasSelect) { $crud .= 'R'; }
    if ($hasUpdate || $hasPost) { $crud .= 'U'; }
    if ($hasDelete) { $crud .= 'D'; }

    $cap = [
        'filter'     => count($filterFields) > 0,
        'action'     => count($actions) > 0,
        'audit'      => count($audit) > 0,
        'permission' => count($perms) > 0,
        'workflow'   => count($states) > 1,
        'table'      => (bool)preg_match('~<table\b~i', (string)$code),
        'form'       => (bool)preg_match('~<form\b~i', (string)$code),
        'export'     => (bool)preg_match('~fputcsv|text/csv|button_export~i', (string)$code),
        'print'      => (bool)preg_match('~@media\s+print|window\.print~i', (string)$code),
        'auth_gate'  => (bool)preg_match('~rmi_require_login|require_login|session_start|401|403~i', (string)$code),
    ];

    return [
        'core'            => rmi_core((string)$module),
        'module'          => (string)$module,
        'feature'         => pathinfo($rel, PATHINFO_FILENAME),
        'route'           => '/' . $rel,
        'file'            => $rel,
        'lines'           => substr_count($src, "\n") + 1,
        'crud'            => $crud,
        'filters'         => $filterFields,
        'update_actions'  => $actions,
        'workflow_states' => $states,
        'audit_events'    => $audit,
        'permissions'     => $perms,
        'capabilities'    => $cap,
        'status'          => 'UNDETECTED', // sengaja: tidak menebak. Bukti manual = PASS.
    ];
}

// ---------------------------------------------------------------- run
$files  = rmi_walk($root, $SKIP_DIRS, $SKIP_FILE);
$rows   = [];
foreach ($files as $rel) {
    $r = rmi_scan($root . '/' . $rel, $rel);
    if ($r) { $rows[] = $r; }
}

$summary = [
    'generated_at' => gmdate('c'),
    'root'         => basename($root),
    'total_pages'  => count($rows),
    'modules'      => count(array_unique(array_column($rows, 'module'))),
    'generator'    => 'tools/qa/feature_inventory_scan.php',
    'note'         => 'status=UNDETECTED by design; tool tidak menebak. Kolom di bawah hasil deteksi statis.',
];
$payload = ['summary' => $summary, 'features' => $rows];

file_put_contents(
    $optOut,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

echo "  [scan]  {$summary['total_pages']} halaman / {$summary['modules']} modul -> tools/qa/ERP_FEATURE_INVENTORY.json\n";

// ---------------------------------------------------------------- self-test
if ($optSelf) {
    // Ground truth diverifikasi manual terhadap kode, bukan dari tool ini.
    $truth = [
        'sales/sales_do.php'      => ['filters' => 5, 'actions' => 1, 'audit' => 0, 'perms' => 5],
        'sales/scm_do_tasks.php'  => ['filters' => 2, 'actions' => 1, 'audit' => 0, 'perms' => 1],
        'stock/wqs_do_tasks.php'  => ['filters' => 3, 'actions' => 1, 'audit' => 0, 'perms' => 1],
    ];
    $fail = 0;
    echo "\n  [self-test] kontras vs ground truth:\n";
    foreach ($truth as $file => $expect) {
        $row = null;
        foreach ($rows as $r) { if ($r['file'] === $file) { $row = $r; break; } }
        if (!$row) { echo "    GAGAL  $file tidak ditemukan\n"; $fail++; continue; }
        $gotF = count($row['filters']);
        $gotA = count($row['update_actions']);
        $gotP = count($row['permissions']);
        $okF = $gotF >= $expect['filters'];
        $okA = $gotA >= $expect['actions'];
        $okP = $gotP >= $expect['perms'];
        printf("    %s  %-30s filter %d (min %d) · aksi %d (min %d) · perm %d (min %d)\n",
            ($okF && $okA && $okP) ? 'OK   ' : 'GAGAL', $file,
            $gotF, $expect['filters'], $gotA, $expect['actions'], $gotP, $expect['perms']);
        if (!$okF) { $fail++; }
        if (!$okA) { $fail++; }
        if (!$okP) { $fail++; }
    }
    if ($fail > 0) {
        echo "\n  [self-test] $fail kegagalan — inventory TIDAK boleh dipakai.\n";
        exit(1);
    }
    echo "\n  [self-test] semua kontras lolos.\n";
}

// ---------------------------------------------------------------- tracker
if ($optTrk) {
    require_once __DIR__ . '/tracker_build_lib.php';
    rmi_build_tracker($rows, $summary, $optTrkF);
    echo "  [tracker] -> tools/qa/ERP_FULL_TRACKER.md\n";
}
