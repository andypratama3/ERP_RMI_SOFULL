<?php
/**
 * Round 2: Process remaining files with CDN references.
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

$statePath = ts_storage_logs_dir() . '/migration_migrate_cdn_round2.state.json';
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
echo "Script: migrate_cdn_round2.php\n";
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
    'script' => 'migrate_cdn_round2.php',
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

$replacements = [
    'https://code.jquery.com/jquery-3.7.1.min.js'
        => '%%BASE%%/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js'
        => '%%BASE%%/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'
        => '%%BASE%%/public/assets/vendor/bootstrap/5.3.3/css/bootstrap.min.css?v=20260209',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'
        => '%%BASE%%/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209',
    'https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209',
    'https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209',
    'https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/css/jquery.dataTables.min.css?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/css/dataTables.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209',
    'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209',
    'https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209',
    'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209',
    'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net@1.13.8/js/jquery.dataTables.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/js/dataTables.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.dataTables.min.css?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209',
    'https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-buttons@2.4.2/js/dataTables.buttons.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-buttons-bs5@2.4.2/js/buttons.bootstrap5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-buttons@2.4.2/js/buttons.html5.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-buttons@2.4.2/js/buttons.print.min.js'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/datatables.net-buttons-bs5@2.4.2/css/buttons.bootstrap5.min.css'
        => '%%BASE%%/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209',
    'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js'
        => '%%BASE%%/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js'
        => '%%BASE%%/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209',
    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209',
    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/pdfmake@0.2.7/build/pdfmake.min.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/pdfmake@0.2.7/build/vfs_fonts.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/pdfmake@0.2.9/build/pdfmake.min.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209',
    'https://cdn.jsdelivr.net/npm/pdfmake@0.2.9/build/vfs_fonts.js'
        => '%%BASE%%/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209',
];

$extraFiles = [
    'master/master_data.php',
    'master/master_products_doc.php',
    'master/master_system_config.php',
    'master/itc_reset_password.php',
    'stock/wqs_pr_view.php',
    'dashboards/quality/qc_complaint_dashboard.php',
    'dashboards/warehouse/wqs_dashboard.php',
    'dashboards/finance/ar_ap_cash_dashboard.php',
    'dashboards/regulatory/license_docs_dashboard.php',
    'dashboards/owner/exec_summary.php',
    'hrl_reg_alkes/reg_alkes.php',
    'hrl_reg_alkes/reg_alkes_control_tower.php',
    'hrl_reg_alkes/reg_alkes_sku_by_nie.php',
    'hrl_reg_alkes/reg_alkes_case.php',
    'purchases/purchases_ceisa_pib_view.php',
    'purchases/purchases_import_control_view.php',
    'purchases/purchases_invoice_ap_edit.php',
    'purchases/purchases_po_view.php',
    'purchases/purchases_gr.php',
    'purchases/fin_gl_auto.php',
    'purchases/stock_update_from_gr.php',
    'kpi/kpi_dashboard_daily.php',
    'kpi/kpi_dashboard_monthly.php',
    'absensi/admin/rekap.php',
    'Fixed_Asset/_inc/bootstrap.php',
];

function getRelativeShared2(string $relPath): string {
    $depth = substr_count($relPath, '/');
    if ($depth === 0) return "__DIR__ . '/_shared/assets.php'";
    return "__DIR__ . '" . str_repeat('/..', $depth) . "/_shared/assets.php'";
}

$totalUpdated = 0;
echo "Round 2 — Additional files\n";
echo "===========================\n\n";

foreach ($extraFiles as $relPath) {
    $file = $root . '/' . $relPath;
    if (!is_file($file)) {
        echo "SKIP (not found): {$relPath}\n";
        continue;
    }

    $content = file_get_contents($file);
    $original = $content;

    // Check CDN presence
    $hasCdn = false;
    foreach ($replacements as $old => $new) {
        if (strpos($content, $old) !== false) { $hasCdn = true; break; }
    }
    if (!$hasCdn) {
        echo "SKIP (already clean): {$relPath}\n";
        continue;
    }

    // Inject require_once
    if (strpos($content, '_shared/assets.php') === false) {
        $phpPos = strpos($content, '<?php');
        if ($phpPos !== false) {
            $afterPhp = $phpPos + 5;
            $nextNewline = strpos($content, "\n", $afterPhp);
            if ($nextNewline !== false) {
                $requirePath = getRelativeShared2($relPath);
                $content = substr($content, 0, $nextNewline + 1)
                         . "require_once {$requirePath}; // RMI asset loader\n"
                         . substr($content, $nextNewline + 1);
            }
        }
    }

    // Replace CDN URLs
    foreach ($replacements as $old => $new) {
        if (strpos($content, $old) !== false) {
            $localUrl = str_replace('%%BASE%%', "<?= rmi_assets_base() ?>", $new);
            $count = substr_count($content, $old);
            $content = str_replace($old, $localUrl, $content);
            echo "  Replaced in {$relPath}: {$old} ({$count}x)\n";
        }
    }

    if ($content !== $original) {
        file_put_contents($file, $content);
        echo "UPDATED: {$relPath}\n";
        $totalUpdated++;
    }
}

echo "\nAdditional files updated: {$totalUpdated}\n";
echo "=== Round 2 complete ===\n";
ts_write_json($statePath, [
    'script' => 'migrate_cdn_round2.php',
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
