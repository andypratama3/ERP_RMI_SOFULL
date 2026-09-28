<?php
/**
 * _shared/assets.php
 * Centralized Asset Loader for RMI ERP
 *
 * Standardized library versions:
 *   jQuery       3.7.1
 *   Bootstrap    5.3.3
 *   DataTables   1.13.8
 *   Buttons      2.4.2
 *   JSZip        3.10.1
 *   pdfmake      0.2.9
 *
 * Usage (HEAD):
 *   <?php require_once __DIR__.'/../_shared/assets.php'; ?>
 *   <?= rmi_assets_head(); ?>            // Bootstrap5 integration (default)
 *   <?= rmi_assets_head(['bs5'=>false]); ?> // Plain DataTables styling
 *
 * Usage (FOOT):
 *   <?= rmi_assets_foot(); ?>
 *   <?= rmi_assets_foot(['export'=>false]); ?> // skip JSZip/pdfmake
 *
 * Options for rmi_assets_head():
 *   bootstrap  (bool, default true)  — include Bootstrap CSS
 *   datatables (bool, default true)  — include DataTables + Buttons CSS
 *   bs5        (bool, default true)  — use Bootstrap5 integration (vs plain)
 *
 * Options for rmi_assets_foot():
 *   jquery     (bool, default true)  — include jQuery
 *   bootstrap  (bool, default true)  — include Bootstrap JS
 *   datatables (bool, default true)  — include DataTables + Buttons JS
 *   bs5        (bool, default true)  — use Bootstrap5 integration JS
 *   export     (bool, default true)  — include JSZip + pdfmake (for Excel/PDF)
 */

// Prevent double-inclusion
if (defined('RMI_ASSETS_LOADED')) return;
define('RMI_ASSETS_LOADED', true);

// Cache-busting version string (update on each deploy/release)
if (!defined('RMI_ASSET_VERSION')) {
    define('RMI_ASSET_VERSION', '20260209');
}

/**
 * Auto-detect the base URL path for the project.
 * Works whether the project is at web root or in a subfolder.
 */
if (!function_exists('rmi_assets_base')) {
    function rmi_assets_base(): string {
        // 1) Prefer rmi_layout_base_project() if available
        if (function_exists('rmi_layout_base_project')) {
            return rmi_layout_base_project();
        }

        // 2) Prefer BASE_PROJECT constant
        if (defined('BASE_PROJECT') && (string)BASE_PROJECT !== '') {
            $bp = rtrim((string)BASE_PROJECT, '/');
            return ($bp === '/') ? '' : $bp;
        }

        // 3) Global $BASE_PROJECT
        if (!empty($GLOBALS['BASE_PROJECT']) && is_string($GLOBALS['BASE_PROJECT'])) {
            $bp = rtrim($GLOBALS['BASE_PROJECT'], '/');
            return ($bp === '/') ? '' : $bp;
        }

        // 4) Derive from SCRIPT_NAME
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $known = '(master|stock|dashboards|kpi|purchases|sales|hrl|hrl_process|hrl_reg_alkes|absensi|payroll|mpr|Fixed_Asset|rbac|tools|chat|docs|api|assets|public)';
        if (preg_match('~^(.*?)/' . $known . '(?:/|$)~i', $script, $m)) {
            $bp = rtrim((string)($m[1] ?? ''), '/');
            return ($bp === '/') ? '' : $bp;
        }

        // 5) Fallback: dirname
        $dir = rtrim(dirname($script), '/');
        return ($dir === '/' || $dir === '.' || $dir === '') ? '' : $dir;
    }
}

/**
 * Vendor base path (browser URL).
 */
if (!function_exists('rmi_vendor_url')) {
    function rmi_vendor_url(string $path): string {
        $base = rmi_assets_base();
        $v = RMI_ASSET_VERSION;
        return $base . '/public/assets/vendor/' . ltrim($path, '/') . '?v=' . $v;
    }
}

/**
 * Emit <link> and <meta> tags for the <head> section.
 */
if (!function_exists('rmi_assets_head')) {
    function rmi_assets_head(array $opts = []): string {
        $includeBootstrap  = (bool)($opts['bootstrap']  ?? true);
        $includeDatatables = (bool)($opts['datatables'] ?? true);
        $bs5               = (bool)($opts['bs5']        ?? true);
        $out = '';

        // Bootstrap CSS
        if ($includeBootstrap) {
            $out .= '  <link rel="stylesheet" href="' . rmi_vendor_url('bootstrap/5.3.3/css/bootstrap.min.css') . '">' . "\n";
        }

        // DataTables CSS
        if ($includeDatatables) {
            if ($bs5) {
                $out .= '  <link rel="stylesheet" href="' . rmi_vendor_url('datatables/1.13.8/css/dataTables.bootstrap5.min.css') . '">' . "\n";
                $out .= '  <link rel="stylesheet" href="' . rmi_vendor_url('datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css') . '">' . "\n";
            } else {
                $out .= '  <link rel="stylesheet" href="' . rmi_vendor_url('datatables/1.13.8/css/jquery.dataTables.min.css') . '">' . "\n";
                $out .= '  <link rel="stylesheet" href="' . rmi_vendor_url('datatables-buttons/2.4.2/css/buttons.dataTables.min.css') . '">' . "\n";
            }
        }

        return $out;
    }
}

/**
 * Emit <script> tags for just before </body>.
 *
 * Correct load order:
 *   jQuery → Bootstrap JS → DataTables core → DT Bootstrap5 integration
 *   → Buttons core → Buttons BS5 integration
 *   → JSZip → pdfmake → vfs_fonts
 *   → buttons.html5 → buttons.print
 */
if (!function_exists('rmi_assets_foot')) {
    function rmi_assets_foot(array $opts = []): string {
        $includeJquery     = (bool)($opts['jquery']     ?? true);
        $includeBootstrap  = (bool)($opts['bootstrap']  ?? true);
        $includeDatatables = (bool)($opts['datatables'] ?? true);
        $bs5               = (bool)($opts['bs5']        ?? true);
        $includeExport     = (bool)($opts['export']     ?? true);
        $out = '';

        // Chart.js (optional, for BI dashboards) - local for offline-friendly
        if (!empty($opts['chartjs'])) {
            $out .= '<script src="' . rmi_vendor_url('chartjs/4.4.1/chart.umd.min.js') . '"></script>' . "\n";
        }

        // jQuery
        if ($includeJquery) {
            $out .= '<script src="' . rmi_vendor_url('jquery/3.7.1/jquery.min.js') . '"></script>' . "\n";
        }

        // Bootstrap JS
        if ($includeBootstrap) {
            $out .= '<script src="' . rmi_vendor_url('bootstrap/5.3.3/js/bootstrap.bundle.min.js') . '"></script>' . "\n";
        }

        // DataTables core
        if ($includeDatatables) {
            $out .= '<script src="' . rmi_vendor_url('datatables/1.13.8/js/jquery.dataTables.min.js') . '"></script>' . "\n";
            if ($bs5) {
                $out .= '<script src="' . rmi_vendor_url('datatables/1.13.8/js/dataTables.bootstrap5.min.js') . '"></script>' . "\n";
            }

            // Buttons core
            $out .= '<script src="' . rmi_vendor_url('datatables-buttons/2.4.2/js/dataTables.buttons.min.js') . '"></script>' . "\n";
            if ($bs5) {
                $out .= '<script src="' . rmi_vendor_url('datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js') . '"></script>' . "\n";
            }

            // Export helpers (JSZip for Excel, pdfmake for PDF)
            if ($includeExport) {
                $out .= '<script src="' . rmi_vendor_url('jszip/3.10.1/jszip.min.js') . '"></script>' . "\n";
                $out .= '<script src="' . rmi_vendor_url('pdfmake/0.2.9/pdfmake.min.js') . '"></script>' . "\n";
                $out .= '<script src="' . rmi_vendor_url('pdfmake/0.2.9/vfs_fonts.js') . '"></script>' . "\n";
            }

            // html5 + print buttons (must come AFTER jszip/pdfmake)
            $out .= '<script src="' . rmi_vendor_url('datatables-buttons/2.4.2/js/buttons.html5.min.js') . '"></script>' . "\n";
            $out .= '<script src="' . rmi_vendor_url('datatables-buttons/2.4.2/js/buttons.print.min.js') . '"></script>' . "\n";
        }

        return $out;
    }
}
