<?php
/**
 * Migration script: Replace all CDN URLs with local vendor paths.
 *
 * Run: php tools/migrate_cdn_to_local.php
 *
 * This script:
 * 1. Scans all PHP files for CDN references
 * 2. Replaces them with local paths using the shared asset loader
 * 3. Logs all changes made
 */

$root = realpath(__DIR__ . '/..');
if (!$root) die("Cannot determine project root\n");
require_once __DIR__ . '/tools_state_lib.php';

$argvList = $_SERVER['argv'] ?? [];
$actor = 'SYSTEM';
$understand = false;
$forceNoBackupCheck = false;
foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--actor=')) $actor = trim(substr($arg, 8)) ?: 'SYSTEM';
    if ($arg === '--i-understand') $understand = true;
    if ($arg === '--force-without-backup-check') $forceNoBackupCheck = true;
}
if ($actor === 'SYSTEM') {
    $actor = (string)(getenv('USER') ?: 'SYSTEM');
}

$statePath = ts_storage_logs_dir() . '/migration_migrate_cdn_to_local.state.json';
$appEnv = (string)(getenv('APP_ENV') ?: 'local');
$latestBackup = ts_latest_backup_meta();
$smoke = ts_read_json(ts_storage_logs_dir() . '/smoke_http_last.json');
$smokePass = (bool)(ts_smoke_summary($smoke)['ok'] ?? false);

$stderr = (defined('STDERR') && is_resource(STDERR)) ? STDERR : @fopen('php://stderr', 'wb');
$err = static function (string $msg) use ($stderr): void {
    if (is_resource($stderr)) {
        fwrite($stderr, $msg);
    } else {
        error_log(trim($msg));
    }
};

echo "============================================================\n";
echo "ONE-TIME SCRIPT - DO NOT RE-RUN CARELESSLY\n";
echo "Script: migrate_cdn_to_local.php\n";
echo "============================================================\n";
if (($latestBackup['age_hours'] ?? 999) > 24 && !$forceNoBackupCheck) {
    $err("Pre-run check blocked: latest backup older than 24h. Use --force-without-backup-check if intentional.\n");
    exit(2);
}
if (!$smokePass) {
    $err("Pre-run warning: last smoke is not PASS.\n");
}
if (in_array(strtolower($appEnv), ['prod', 'production'], true) && !$understand) {
    $err("Production safeguard: add --i-understand to continue.\n");
    exit(2);
}
ts_write_json($statePath, [
    'script' => 'migrate_cdn_to_local.php',
    'one_time' => true,
    'status' => 'running',
    'started_at' => date(DateTimeInterface::ATOM),
    'last_executed_by' => $actor,
    'env' => $appEnv,
    'checklist' => [
        'backup_age_hours' => $latestBackup['age_hours'] ?? null,
        'smoke_pass' => $smokePass,
    ],
]);

$log = [];

// ═══════════════════════════════════════════════════════════════════
// URL replacement map: old CDN URL → new local path placeholder
// We use {BASE} as placeholder for the rmi_assets_base() output.
// Since these are in static HTML context in PHP files, we need a strategy.
//
// STRATEGY: For files that DON'T use the PHP asset loader functions,
// we replace CDN URLs with local paths that include a PHP expression.
// ═══════════════════════════════════════════════════════════════════

// Direct string replacements (CDN URL → local path)
// These are grouped by "old version to standardize" vs "already correct version"
$replacements = [
    // ── jQuery ──
    'https://code.jquery.com/jquery-3.7.1.min.js'
        => '%%BASE%%/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js'
        => '%%BASE%%/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209',

    // ── Bootstrap CSS ──
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'
        => '%%BASE%%/public/assets/vendor/bootstrap/5.3.3/css/bootstrap.min.css?v=20260209',

    // ── Bootstrap JS ──
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'
        => '%%BASE%%/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209',

    // ── DataTables CSS (1.13.6 → 1.13.8) ──
    'https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209',
    // ── DataTables CSS (1.13.8 — same version, just localize) ──
    'https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209',
    'https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/css/jquery.dataTables.min.css?v=20260209',
    // jsdelivr variants
    'https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/css/dataTables.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209',

    // ── DataTables JS (1.13.6 → 1.13.8) ──
    'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209',
    'https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209',
    // ── DataTables JS (1.13.8 — localize) ──
    'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209',
    'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209',
    // jsdelivr variants
    'https://cdn.jsdelivr.net/npm/datatables.net@1.13.8/js/jquery.dataTables.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/js/dataTables.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209',

    // ── Buttons CSS (2.4.1 → 2.4.2) ──
    'https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209',
    // ── Buttons CSS (2.4.2 — localize) ──
    'https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.dataTables.min.css?v=20260209',
    // jsdelivr variants
    'https://cdn.jsdelivr.net/npm/datatables.net-buttons-bs5@2.4.2/css/buttons.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209',

    // ── Buttons JS (2.4.1 → 2.4.2) ──
    'https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209',
    // ── Buttons JS (2.4.2 — localize) ──
    'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209',
    // jsdelivr variants
    'https://cdn.jsdelivr.net/npm/datatables.net-buttons@2.4.2/js/dataTables.buttons.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-buttons-bs5@2.4.2/js/buttons.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-buttons@2.4.2/js/buttons.html5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-buttons@2.4.2/js/buttons.print.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209',

    // ── JSZip ──
    'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js'
        => '%%BASE%%/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js'
        => '%%BASE%%/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209',

    // ── pdfmake (0.2.7 → 0.2.9) ──
    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209',
    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/pdfmake@0.2.7/build/pdfmake.min.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/pdfmake@0.2.7/build/vfs_fonts.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209',
    // ── pdfmake (0.2.9 — localize) ──
    'https://cdn.jsdelivr.net/npm/pdfmake@0.2.9/build/pdfmake.min.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/pdfmake@0.2.9/build/vfs_fonts.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209',
];

// ═══════════════════════════════════════════════════════════════════
// Files to process
// ═══════════════════════════════════════════════════════════════════

// Phase 1: Module layout files that act as centralization points.
// These will use rmi_assets_head/foot functions.
$moduleLayoutFiles = [
    'mpr/_layout_top.php',
    'mpr/_layout_bottom.php',
    'hrl/_layout_top.php',
    'hrl/_layout_bottom.php',
    'hrl_process/_layout_top.php',
    'hrl_process/_layout_bottom.php',
    'Fixed_Asset/_inc/layout.php',
];

// Phase 2: Standalone pages that load CDN directly.
// These get their CDN URLs replaced with local paths.
$standaloneFiles = [
    'master/master_manufactures.php',
    'master/master_employees.php',
    'master/master_payment_terms.php',
    'master/master_customers.php',
    'master/master_office.php',
    'master/master_tax.php',
    'master/master_emailcompany.php',
    'master/master_vendors.php',
    'master/master_user.php',
    'master/master_departements.php',
    'master/master_products.php',
    'master/master_products_package.php',
    'master/master_pricelist.php',
    'master/master_pricelist_sell.php',
    'master/master_system_login.php',
    'master/manufactures_docs.php',
    'master/products_media_view.php',
    'sales/sales_do.php',
    'sales/sales_control_tower.php',
    'stock/wqs_allocation.php',
    'stock/wqs_stock.php',
    'stock/wqs_stock_adjustment.php',
    'stock/wqs_stock_audit.php',
    'stock/wqs_picking.php',
    'stock/wqs_incoming.php',
    'stock/wqs_pr.php',
    'stock/wqs_incoming_view.php',
    'stock/wqs_picking_view.php',
    'purchases/purchases_po.php',
    'purchases/purchases_forwarding_tasks.php',
    'purchases/purchases_forwarder_payment.php',
    'purchases/purchases_forwarder_invoice.php',
    'purchases/purchases_forwarder_quotes.php',
    'purchases/purchases_payment_ap.php',
    'purchases/purchases_invoice_ap.php',
    'purchases/purchases_ceisa_pib.php',
    'purchases/purchases_reports.php',
    'purchases/purchases_import_control_tower.php',
    'master_system_config.php',
];

// Also handle rmi_layout.php Bootstrap CDN fallback
$sharedFiles = [
    '_shared/rmi_layout.php',
];

// ═══════════════════════════════════════════════════════════════════
// Step 1: Determine each file's depth to calculate %%BASE%% injection
// ═══════════════════════════════════════════════════════════════════

/**
 * For each file, we inject a PHP snippet at the top (after opening <?php)
 * that includes _shared/assets.php if not already included.
 *
 * Then replace %%BASE%% with a PHP echo of rmi_assets_base().
 */

function getRelativeShared(string $relPath): string {
    // Calculate the relative path from the file to _shared/assets.php
    $depth = substr_count($relPath, '/');
    if ($depth === 0) {
        return "__DIR__ . '/_shared/assets.php'";
    }
    $ups = str_repeat('/..', $depth);
    return "__DIR__ . '" . $ups . "/_shared/assets.php'";
}

function processFile(string $root, string $relPath, array $replacements, array &$log): bool {
    $file = $root . '/' . $relPath;
    if (!is_file($file)) {
        $log[] = "SKIP (not found): {$relPath}";
        return false;
    }

    $content = file_get_contents($file);
    $original = $content;
    $changes = [];

    // Check if any CDN URL exists in this file
    $hasCdn = false;
    foreach ($replacements as $old => $new) {
        if (strpos($content, $old) !== false) {
            $hasCdn = true;
            break;
        }
    }

    if (!$hasCdn) {
        $log[] = "SKIP (no CDN refs): {$relPath}";
        return false;
    }

    // Step A: Inject require_once for assets.php if not already present
    $assetsInclude = '_shared/assets.php';
    if (strpos($content, $assetsInclude) === false) {
        $requirePath = getRelativeShared($relPath);
        $injectLine = "require_once {$requirePath}; // RMI asset loader";

        // Find the first <?php tag and insert after it
        $phpPos = strpos($content, '<?php');
        if ($phpPos !== false) {
            // Find the end of the first line after <?php
            $afterPhp = $phpPos + 5;
            $nextNewline = strpos($content, "\n", $afterPhp);
            if ($nextNewline !== false) {
                $content = substr($content, 0, $nextNewline + 1)
                         . $injectLine . "\n"
                         . substr($content, $nextNewline + 1);
                $changes[] = "Injected require_once for _shared/assets.php";
            }
        }
    }

    // Step B: Replace CDN URLs
    foreach ($replacements as $old => $new) {
        if (strpos($content, $old) !== false) {
            // Replace %%BASE%% with PHP expression
            $localUrl = str_replace('%%BASE%%', "<?= rmi_assets_base() ?>", $new);
            $count = substr_count($content, $old);
            $content = str_replace($old, $localUrl, $content);
            $changes[] = "Replaced {$old} ({$count}x)";
        }
    }

    if ($content !== $original) {
        file_put_contents($file, $content);
        $log[] = "UPDATED: {$relPath}";
        foreach ($changes as $c) {
            $log[] = "  - {$c}";
        }
        return true;
    }

    return false;
}

// ═══════════════════════════════════════════════════════════════════
// Execute
// ═══════════════════════════════════════════════════════════════════

echo "RMI ERP — CDN to Local Migration\n";
echo "=================================\n\n";

$totalUpdated = 0;

// Process all files
$allFiles = array_merge($moduleLayoutFiles, $standaloneFiles, $sharedFiles);

foreach ($allFiles as $relPath) {
    if (processFile($root, $relPath, $replacements, $log)) {
        $totalUpdated++;
    }
}

echo "Files updated: {$totalUpdated}\n\n";

foreach ($log as $line) {
    echo $line . "\n";
}

echo "\n=== Migration complete ===\n";
ts_write_json($statePath, [
    'script' => 'migrate_cdn_to_local.php',
    'one_time' => true,
    'last_executed_at' => date(DateTimeInterface::ATOM),
    'last_executed_by' => $actor,
    'env' => $appEnv,
    'ok' => true,
    'summary' => 'Migration completed',
    'last_error_masked' => '',
    'files_updated' => $totalUpdated,
]);
@chmod($statePath, 0644);
