<?php
declare(strict_types=1);
/**
 * rmi_page_registry_guard.php
 *
 * Gate izin can() selaras config/page_registry.php (ACCESS / VIEW / route_any).
 * Dipanggil dari require_login() setelah require_rbac().
 *
 * Untuk URL yang punya baris di registry ini = satu-satunya lapisan yang memanggil
 * require_permission / require_any_permission (require_rbac tidak mengulang rule['perms']).
 *
 * - URL tidak di registry → no-op untuk izin registry (policy tetap: dept/level/method).
 * - route_any → require_any_permission(...)
 * - access / view → require_permission(...)
 *
 * Halaman yang perlu gate VIEW terpisah: require_content_view() di file halaman (master/auth.php).
 *
 * Nonaktifkan: define('RMI_DISABLE_PAGE_REGISTRY_GUARD', true);
 *               atau env RMI_DISABLE_PAGE_REGISTRY_GUARD=1
 */

// RMI_GUARD_DIRECT_ACCESS
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

function rmi_registry_script_rel_path(): string {
    if (!function_exists('auth_rbac_route')) {
        return '';
    }
    return ltrim(str_replace('\\', '/', auth_rbac_route()), '/');
}

/**
 * @return list<array{url:string,row:array}>
 */
function rmi_page_registry_all_rows(): array {
    static $flat = null;
    if ($flat !== null) {
        return $flat;
    }
    $flat = [];
    $root = defined('RMI_ROOT') ? RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
    $file = $root . '/config/page_registry.php';
    if (!is_file($file)) {
        return $flat;
    }
    /** @var array<string, list<array<string,mixed>>> $reg */
    $reg = require $file;
    foreach ($reg as $pages) {
        if (!is_array($pages)) {
            continue;
        }
        foreach ($pages as $row) {
            if (!is_array($row) || empty($row['url'])) {
                continue;
            }
            $flat[] = ['url' => (string) $row['url'], 'row' => $row];
        }
    }
    return $flat;
}

/**
 * @return array<string, list<array{url:string,row:array}>>
 */
function rmi_page_registry_index_by_path(): array {
    static $idx = null;
    if ($idx !== null) {
        return $idx;
    }
    $idx = [];
    foreach (rmi_page_registry_all_rows() as $item) {
        $u = $item['url'];
        $path = str_contains($u, '?') ? explode('?', $u, 2)[0] : $u;
        $path = str_replace('\\', '/', $path);
        $idx[$path] ??= [];
        $idx[$path][] = $item;
    }
    return $idx;
}

/**
 * @return array<string,mixed>|null
 */
function rmi_page_registry_resolve_row(): ?array {
    $rel = rmi_registry_script_rel_path();
    if ($rel === '') {
        return null;
    }
    $candidates = rmi_page_registry_index_by_path()[$rel] ?? [];
    if ($candidates === []) {
        return null;
    }
    if (count($candidates) === 1) {
        return $candidates[0]['row'];
    }
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $full = $qs !== '' ? ($rel . '?' . $qs) : $rel;
    foreach ($candidates as $c) {
        if ($c['url'] === $full) {
            return $c['row'];
        }
    }
    return $candidates[0]['row'];
}

function rmi_page_registry_guard_try(): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    $__uri   = $_SERVER['REQUEST_URI'] ?? '';
$__user  = strtoupper((string)($_SESSION['username'] ?? ''));
$__dept  = strtoupper((string)($_SESSION['department'] ?? ''));
$__role  = strtoupper((string)($_SESSION['role'] ?? ''));
$__level = strtoupper((string)($_SESSION['level'] ?? ''));

$__is_mgr_hrl_bdg = (
    $__user === 'MGRHRL_BDG'
    && $__dept === 'HRL'
    && ($__role === 'MANAGER' || $__level === 'MANAGER')
);

if ($__is_mgr_hrl_bdg) {
    if (
        strpos($__uri, '/master/manufactures_docs.php') !== false
    ) {
        return;
    }
}

        // ===============================
    // BYPASS KHUSUS MgrHRL_BDG (HRL DOCS)
    // ===============================
    $__uri   = $_SERVER['REQUEST_URI'] ?? '';
    $__user  = strtoupper((string)($_SESSION['username'] ?? ''));
    $__dept  = strtoupper((string)($_SESSION['department'] ?? ''));
    $__role  = strtoupper((string)($_SESSION['role'] ?? ''));
    $__level = strtoupper((string)($_SESSION['level'] ?? ''));

    $__is_mgr_hrl_bdg = (
        $__user === 'MGRHRL_BDG'
        && $__dept === 'HRL'
        && ($__role === 'MANAGER' || $__level === 'MANAGER')
    );

    if ($__is_mgr_hrl_bdg) {
        if (
            strpos($__uri, '/hrl/hrl_docs.php') !== false ||
            strpos($__uri, '/hrl/hrl_doc_view.php') !== false ||
            strpos($__uri, '/hrl/hrl_tower.php') !== false
        ) {
            return;
        }
    }
         // ===============================
    // BYPASS KHUSUS MgrFIN_BGR (PAYROLL PAGES)
    // ===============================
    $__uri   = $_SERVER['REQUEST_URI'] ?? '';
    $__user  = strtoupper((string)($_SESSION['username'] ?? ''));
    $__dept  = strtoupper((string)($_SESSION['department'] ?? ''));
    $__role  = strtoupper((string)($_SESSION['role'] ?? ''));
    $__level = strtoupper((string)($_SESSION['level'] ?? ''));

    $__is_mgr_fin_bgr = (
        $__user === 'MGRFIN_BGR'
        && $__dept === 'FIN'
        && ($__role === 'MANAGER' || $__level === 'MANAGER')
    );

    if ($__is_mgr_fin_bgr) {
        if (
            strpos($__uri, '/payroll/salary_matrix.php') !== false ||
            strpos($__uri, '/payroll/settings.php') !== false ||
            strpos($__uri, '/payroll/payroll_settings.php') !== false ||
            strpos($__uri, '/payroll/loans.php') !== false ||
            strpos($__uri, '/payroll/pengajuan_pinjaman.php') !== false ||
            strpos($__uri, '/payroll/pinjaman.php') !== false ||
            strpos($__uri, '/payroll/kasbon.php') !== false
        ) {
            return;
        }
    }

    if (PHP_SAPI === 'cli') {
        return;
    }
    if (defined('RMI_DISABLE_PAGE_REGISTRY_GUARD') && RMI_DISABLE_PAGE_REGISTRY_GUARD) {
        return;
    }
    if (function_exists('rmi_env') && rmi_env('RMI_DISABLE_PAGE_REGISTRY_GUARD') === '1') {
        return;
    }

    // ------------------------------------------------------------------
    // BRANCH DEPO RESTRICTED (KAL/JGY)
    // ------------------------------------------------------------------
    // Fail-closed: akun kerja sama Depo hanya boleh Pencapaian, DO office
    // sendiri (WQS -> SCM), dan MPR. Branch internal tidak masuk blok ini.
    $__branchGuard = __DIR__ . '/rmi_branch_guard.php';
    if (is_file($__branchGuard)) require_once $__branchGuard;
    unset($__branchGuard);

    if (function_exists('rmi_is_depo_branch_session') && rmi_is_depo_branch_session()) {
        $rel = rmi_registry_script_rel_path();
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $allow = [
            // Dashboard khusus Depo: hanya merangkum data office session dan link ke route yang sudah diizinkan.
            'dashboards/branch/depo_dashboard.php' => ['GET'],

            // Pencapaian Depo: dashboard_detail.php punya mode restricted sendiri.
            'dashboards/finance/dashboard_detail.php' => ['GET'],

            // DO office sendiri. sales_do.php dibuat read-only khusus Depo.
            'sales/sales_do.php'       => ['GET'],
            'sales/sales_do_view.php'  => ['GET'],

            // Proses DO WQS -> SCM untuk office sendiri.
            'stock/wqs_do_tasks.php'       => ['GET','POST'],
            'sales/scm_do_tasks.php'       => ['GET','POST'],
            'sales/scm_tracker_mobile.php' => ['GET','POST'],
            'api/v1/internal/sales_scm_geo_ping.php' => ['POST'],

            // MPR inti. Finance/budget MPR sengaja tidak masuk allowlist.
            'mpr/index.php'          => ['GET'],
            'mpr/mpr_dashboard.php'  => ['GET'],
            'mpr/mpr_plans.php'       => ['GET','POST'],
            'mpr/mpr_plan_view.php'   => ['GET'],
            // Sudah diaudit: list/edit/post wajib office-scope. Depo tidak boleh delete histori.
            'mpr/mpr_visits.php'      => ['GET','POST'],
            'mpr/mpr_pipeline.php'    => ['GET','POST'],
            // GPS realtime hanya helper input lokasi; data bisnis tetap disimpan oleh mpr_visits.php.
            'mpr/mpr_gps_capture.php' => ['GET'],
            'mpr/panduan.php'         => ['GET'],

            // Bantuan read-only yang tidak membuka data transaksi lain.
            'docs/help_center.php'             => ['GET'],
            'sales/panduan_do_tasks.php'       => ['GET'],
            'sales/panduan.php'                => ['GET'],
            'dashboards/warehouse/panduan.php' => ['GET'],
            'dashboards/scm/panduan.php'       => ['GET'],
        ];

        $ok = isset($allow[$rel]) && in_array($method, $allow[$rel], true);
        if (!$ok) {
            $route = function_exists('auth_rbac_route') ? auth_rbac_route() : ('/' . ltrim($rel, '/'));
            if (function_exists('auth_rbac_forbidden_exit')) {
                auth_rbac_forbidden_exit('DEPO_BRANCH_ROUTE_BLOCK', $route, 'office=' . rmi_depo_branch_office() . ' method=' . $method);
            }
            http_response_code(403);
            exit('Forbidden');
        }

        // Route sudah masuk allowlist khusus Depo. Gate bisnis + office scope
        // tetap dijalankan oleh file tujuan masing-masing.
        return;
    }

    if (!function_exists('auth_rbac_route') || !function_exists('auth_rbac_is_public')) {
        return;
    }
    $route = auth_rbac_route();
    if ($route === '' || auth_rbac_is_public($route)) {
        return;
    }

    if (!function_exists('require_permission') || !function_exists('require_any_permission')) {
        return;
    }

    // ------------------------------------------------------------------
    // PRINT CF DO — akses operasional WQS tanpa membuka SALES.PRINT global
    // ------------------------------------------------------------------
    // Endpoint aktual: /sales/sales_do_print_cf.php
    // Semua akun yang session department-nya WQS boleh melewati gate
    // Page Registry KHUSUS endpoint ini. Akun non-WQS tetap mengikuti
    // registry/RBAC normal (mis. SALES.PRINT).
    //
    // Ini sengaja tidak memberi permission SALES.PRINT kepada WQS sehingga
    // halaman Sales lain tidak ikut terbuka.
    $routeNorm = '/' . ltrim(str_replace('\\', '/', (string)$route), '/');
    $sessionDept = strtoupper(trim((string)($_SESSION['department'] ?? '')));

    if ($routeNorm === '/sales/sales_do_print_cf.php' && $sessionDept === 'WQS') {
        return;
    }

    $row = rmi_page_registry_resolve_row();
    if ($row === null) {
        return;
    }

    $routeAny = $row['route_any'] ?? null;
    if (is_array($routeAny) && $routeAny !== []) {
        $codes = [];
        foreach ($routeAny as $c) {
            $c = strtoupper(trim((string) $c));
            if ($c !== '') {
                $codes[] = $c;
            }
        }
        if ($codes !== []) {
            require_any_permission($codes);
        }
        return;
    }

    /** @var array<string, ?string> $perms */
    $perms = $row['perms'] ?? [];
    if (!is_array($perms)) {
        return;
    }
    $access = $perms['access'] ?? null;
    $view = $perms['view'] ?? null;
    $access = ($access !== null && $access !== '') ? (string) $access : null;
    $view = ($view !== null && $view !== '') ? (string) $view : null;

    if ($access !== null) {
        require_permission($access);
        return;
    }
    if ($view !== null) {
        require_permission($view);
    }
}
