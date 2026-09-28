<?php
/**
 * extract_menu_routes.php — Parse ERP_MENU_WORKFLOW_REFERENCE.md → menu_routes_extracted.json
 * Sumber kebenaran: docs/ERP_MENU_WORKFLOW_REFERENCE.md
 * Output: storage/logs/e2e_trial/<RUN_ID>/menu_routes_extracted.json (atau storage/logs/)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$refPath = $root . '/docs/ERP_MENU_WORKFLOW_REFERENCE.md';
$outputDir = $root . '/storage/logs';

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (is_string($arg) && str_starts_with($arg, '--output-dir=')) {
        $outputDir = rtrim(substr($arg, 13), '/');
        break;
    }
}
@mkdir($outputDir, 0775, true);

if (!is_file($refPath)) {
    fwrite(STDERR, "FAIL: ERP_MENU_WORKFLOW_REFERENCE.md not found\n");
    exit(1);
}

$content = file_get_contents($refPath);
$currentModule = 'MAIN';
$sectionMap = [
    '## 2. MAIN' => 'MAIN',
    '## 3. MASTER DATA' => 'MASTER_DATA',
    '## 4. CRM' => 'CRM',
    '## 5. PQP' => 'PQP',
    '## 6. WQS' => 'WQS',
    '## 7. HRL' => 'HRL',
    '## 8. FIN' => 'FIN',
    '## 9. MPR' => 'MPR',
    '## 10. ACT' => 'ACT',
    '## 11. SETTINGS' => 'SETTINGS',
];
$itemsByModule = [];
$seenPaths = [];

$lines = explode("\n", $content);
foreach ($lines as $line) {
    $trimmed = trim($line);
    foreach ($sectionMap as $header => $mod) {
        if (str_starts_with($trimmed, $header)) {
            $currentModule = $mod;
            break;
        }
    }
    if (preg_match('#\|\s*`?/?([a-zA-Z0-9_/.-]+\.php)`?\s*\|#', $trimmed, $m)) {
        $raw = ltrim($m[1], '/');
        $path = str_contains($raw, '/') ? $raw : (function ($f) {
            if (str_starts_with($f, 'master_') || str_starts_with($f, 'itc_') || str_starts_with($f, 'account_') || str_starts_with($f, 'company_')) return 'master/' . $f;
            if (str_starts_with($f, 'sales_') || str_starts_with($f, 'crm_') || str_starts_with($f, 'scm_') || str_starts_with($f, 'act_') || str_starts_with($f, 'fin_') || str_starts_with($f, 'tax_')) return 'sales/' . $f;
            if (str_starts_with($f, 'wqs_do')) return 'stock/wqs_do_tasks.php';
            if (str_starts_with($f, 'purchases_') || str_starts_with($f, 'stock_update') || str_starts_with($f, 'bank_recon') || str_starts_with($f, 'fin_gl')) return 'purchases/' . $f;
            if (str_starts_with($f, 'wqs_')) return 'stock/' . $f;
            if (str_starts_with($f, 'hrl_') || str_starts_with($f, 'hrl_doc')) return 'hrl/' . $f;
            if (in_array($f, ['request_view.php','request_print.php','my_pin.php','tower.php','download.php'], true)) return 'hrl_process/' . $f;
            if (str_starts_with($f, 'reg_alkes')) return 'hrl_reg_alkes/' . $f;
            if (in_array($f, ['checkin.php','checkout.php','request.php','approval.php']) || str_starts_with($f, 'admin/')) return 'absensi/' . $f;
            if (str_starts_with($f, 'kpi_')) return 'kpi/' . $f;
            if (str_starts_with($f, 'payroll_') || in_array($f, ['loans.php','payslip.php','audit.php','salary_matrix.php'], true)) return 'payroll/' . $f;
            if (str_starts_with($f, 'mpr_')) return 'mpr/' . $f;
            if (in_array($f, ['assets.php','ops.php','depreciation.php','tax_annual.php','disposals.php','transfers.php'], true)) return 'Fixed_Asset/' . $f;
            if ($f === 'audit.php') return 'payroll/audit.php';
            return 'master/' . $f;
        })($raw);
        if (in_array($path, $seenPaths, true)) continue;
        $seenPaths[] = $path;
        $mod = $currentModule;
        if (!isset($itemsByModule[$mod])) $itemsByModule[$mod] = [];
        $itemsByModule[$mod][] = ['label' => basename($path, '.php'), 'url' => '/' . $path];
    }
}
if (preg_match_all('#\|\s*([a-zA-Z0-9_/.-]+\.php)\s*\|#m', $content, $fileMatches)) {
    $dirFromFile = static function (string $f): string {
        if (str_contains($f, '/')) return $f;
        if (str_starts_with($f, 'master_') || str_starts_with($f, 'itc_') || str_starts_with($f, 'account_') || str_starts_with($f, 'company_')) return 'master/' . $f;
        if (str_starts_with($f, 'sales_') || str_starts_with($f, 'crm_') || str_starts_with($f, 'scm_') || str_starts_with($f, 'act_') || str_starts_with($f, 'fin_') || str_starts_with($f, 'tax_')) return 'sales/' . $f;
        if (str_starts_with($f, 'wqs_do')) return 'stock/wqs_do_tasks.php';
        if (str_starts_with($f, 'purchases_') || str_starts_with($f, 'stock_update') || str_starts_with($f, 'bank_recon') || str_starts_with($f, 'fin_gl')) return 'purchases/' . $f;
        if (str_starts_with($f, 'wqs_')) return 'stock/' . $f;
        if (str_starts_with($f, 'hrl_') || str_starts_with($f, 'hrl_doc')) return 'hrl/' . $f;
        if (in_array($f, ['request_view.php','request_print.php','my_pin.php','tower.php','download.php'], true)) return 'hrl_process/' . $f;
        if (str_starts_with($f, 'reg_alkes')) return 'hrl_reg_alkes/' . $f;
        if (in_array($f, ['checkin.php','checkout.php','request.php','approval.php']) || str_starts_with($f, 'admin/')) return 'absensi/' . $f;
        if (str_starts_with($f, 'kpi_')) return 'kpi/' . $f;
        if (str_starts_with($f, 'payroll_') || in_array($f, ['loans.php','payslip.php','audit.php','salary_matrix.php'], true)) return 'payroll/' . $f;
        if (str_starts_with($f, 'mpr_')) return 'mpr/' . $f;
        if (in_array($f, ['assets.php','ops.php','depreciation.php','tax_annual.php','disposals.php','transfers.php'], true)) return 'Fixed_Asset/' . $f;
        if ($f === 'audit.php') return 'payroll/audit.php';
        return 'master/' . $f;
    };
    foreach ($fileMatches[1] ?? [] as $file) {
        $file = trim($file);
        if ($file === '' || preg_match('/\s/', $file)) continue;
        $path = $dirFromFile($file);
        if (in_array($path, $seenPaths, true)) continue;
        $seenPaths[] = $path;
        $mod = 'MAIN';
        if (str_contains($path, 'master/')) $mod = 'MASTER_DATA';
        elseif (str_contains($path, 'sales/')) $mod = 'CRM';
        elseif (str_contains($path, 'purchases/')) $mod = 'PQP';
        elseif (str_contains($path, 'stock/') || str_contains($path, 'dashboards/warehouse')) $mod = 'WQS';
        elseif (str_contains($path, 'hrl_process/')) $mod = 'HRL';
        elseif (str_contains($path, 'hrl_reg_alkes/')) $mod = 'HRL';
        elseif (str_contains($path, 'hrl/')) $mod = 'HRL';
        elseif (str_contains($path, 'absensi/')) $mod = 'ABSENSI';
        elseif (str_contains($path, 'kpi/')) $mod = 'KPI';
        elseif (str_contains($path, 'payroll/')) $mod = 'FIN';
        elseif (str_contains($path, 'mpr/')) $mod = 'MPR';
        elseif (str_contains($path, 'Fixed_Asset/')) $mod = 'ACT';
        elseif (str_contains($path, 'rbac/')) $mod = 'RBAC';
        elseif (str_contains($path, 'tools/')) $mod = 'TOOLS';
        if (!isset($itemsByModule[$mod])) $itemsByModule[$mod] = [];
        $itemsByModule[$mod][] = ['label' => basename($path, '.php'), 'url' => '/' . $path];
    }
    if (in_array('payroll/audit.php', $seenPaths, true) && !in_array('Fixed_Asset/audit.php', $seenPaths, true)) {
        $itemsByModule['ACT'][] = ['label' => 'audit', 'url' => '/Fixed_Asset/audit.php'];
    }
}

// Build final structure per prompt
$output = [
    'generated_at' => date('c'),
    'source' => 'docs/ERP_MENU_WORKFLOW_REFERENCE.md',
    'modules' => [],
];

foreach ($itemsByModule as $mod => $items) {
    $output['modules'][] = [
        'module' => $mod,
        'items' => array_values(array_unique($items, SORT_REGULAR)),
    ];
}

$outPath = $outputDir . '/menu_routes_extracted.json';
file_put_contents($outPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "Extracted " . count($output['modules']) . " modules -> {$outPath}\n";
exit(0);
