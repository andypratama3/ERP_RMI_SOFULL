<?php
/**
 * E2E Trail Runner — ERP_RMI_SOFULL
 * Sumber: docs/ERP_MENU_WORKFLOW_REFERENCE.md
 * Output: docs/e2e/E2E_TRAIL_LAST.md, storage/logs/e2e_trail_last.json, evidence zip
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$runId = date('YmdHis');
$logsDir = $root . '/storage/logs';
$e2eDir = $root . '/docs/e2e';
@mkdir($logsDir, 0775, true);
@mkdir($e2eDir, 0775, true);

require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/_lib/url_helpers.php';
require_once $root . '/tools/_lib/tools_http.php';

$baseUrl = trim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('TOOLS_BASE_URL') ?: getenv('LAN_BASE_URL') ?: getenv('APP_URL') ?: 'http://10.10.60.20/ERP_RMI_SOFULL'));
$baseUrl = rtrim(preg_replace('#(https?://)/+#', '$1', $baseUrl), '/');

// --- 1) Extract menu routes from ERP_MENU_WORKFLOW_REFERENCE.md ---
$refPath = $root . '/docs/ERP_MENU_WORKFLOW_REFERENCE.md';
if (!is_file($refPath)) {
    fwrite(STDERR, "FAIL: ERP_MENU_WORKFLOW_REFERENCE.md not found.\n");
    exit(1);
}

$refContent = file_get_contents($refPath);
$routes = [];
$currentModule = '';

// Parse table rows: | URL | or | File | with path
preg_match_all('#\|\s*`?([^`|\s][^|]*?)\s*`?\s*\|.*?\|#m', $refContent, $m);
foreach ($m[1] ?? [] as $cell) {
    $cell = trim($cell);
    if (preg_match('#^/([a-zA-Z0-9_/.-]+\.php)#', $cell, $u)) {
        $path = $u[1];
        if (!in_array($path, array_column($routes, 'path'), true)) {
            $routes[] = ['path' => $path, 'module' => $currentModule ?: 'MAIN'];
        }
    }
}

// Manual extraction from doc structure (more reliable)
$manualRoutes = [
    ['module' => 'MAIN', 'path' => 'dashboards/index.php'],
    ['module' => 'MAIN', 'path' => 'chat/index.php'],
    ['module' => 'MAIN', 'path' => 'docs/help_center.php'],
    ['module' => 'MAIN', 'path' => 'dashboards/owner/exec_summary.php'],
    ['module' => 'MAIN', 'path' => 'dashboards/quality/qc_complaint_dashboard.php'],
    ['module' => 'MASTER', 'path' => 'master/index.php'],
    ['module' => 'MASTER', 'path' => 'master/login.php'],
    ['module' => 'MASTER', 'path' => 'master/master_products.php'],
    ['module' => 'MASTER', 'path' => 'master/master_import_products.php'],
    ['module' => 'MASTER', 'path' => 'master/master_customers.php'],
    ['module' => 'MASTER', 'path' => 'master/master_import_customers.php'],
    ['module' => 'MASTER', 'path' => 'master/master_vendors.php'],
    ['module' => 'MASTER', 'path' => 'master/master_import_vendors.php'],
    ['module' => 'MASTER', 'path' => 'master/master_manufactures.php'],
    ['module' => 'MASTER', 'path' => 'master/master_employees.php'],
    ['module' => 'MASTER', 'path' => 'master/master_pricelist_sell.php'],
    ['module' => 'MASTER', 'path' => 'master/master_pricelist.php'],
    ['module' => 'MASTER', 'path' => 'master/master_office.php'],
    ['module' => 'MASTER', 'path' => 'master/master_departements.php'],
    ['module' => 'MASTER', 'path' => 'master/master_tax.php'],
    ['module' => 'MASTER', 'path' => 'master/master_payment_terms.php'],
    ['module' => 'MASTER', 'path' => 'master/itc_reset_password.php'],
    ['module' => 'MASTER', 'path' => 'master/account_readiness.php'],
    ['module' => 'MASTER', 'path' => 'master/master_data.php'],
    ['module' => 'MASTER', 'path' => 'master/master_system_login.php'],
    ['module' => 'MASTER', 'path' => 'master/master_system_config.php'],
    ['module' => 'MASTER', 'path' => 'master/company_bank_accounts.php'],
    ['module' => 'CRM', 'path' => 'sales/sales_dashboard.php'],
    ['module' => 'CRM', 'path' => 'sales/sales_dashboard.php'],
    ['module' => 'CRM', 'path' => 'sales/sales_do.php'],
    ['module' => 'CRM', 'path' => 'sales/sales_do_view.php'],
    ['module' => 'CRM', 'path' => 'sales/sales_order.php'],
    ['module' => 'CRM', 'path' => 'sales/crm_leads.php'],
    ['module' => 'CRM', 'path' => 'sales/crm_lead_create.php'],
    ['module' => 'CRM', 'path' => 'sales/crm_lead_edit.php'],
    ['module' => 'CRM', 'path' => 'stock/wqs_do_tasks.php'],
    ['module' => 'CRM', 'path' => 'sales/scm_do_tasks.php'],
    ['module' => 'CRM', 'path' => 'sales/act_do_tasks.php'],
    ['module' => 'CRM', 'path' => 'sales/fin_do_tasks.php'],
    ['module' => 'CRM', 'path' => 'sales/tax_invoices.php'],
    ['module' => 'CRM', 'path' => 'sales/sales_control_tower.php'],
    ['module' => 'PQP', 'path' => 'purchases/index.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_dashboard.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_import_control_tower.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_po.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_po_view.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_po_print.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_gr.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_invoice_ap.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_payment_ap.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_forwarder_quotes.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_forwarding_tasks.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_forwarder_invoice.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_forwarder_payment.php'],
    ['module' => 'PQP', 'path' => 'purchases/purchases_ceisa_pib.php'],
    ['module' => 'WQS', 'path' => 'dashboards/warehouse/wqs_dashboard.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_stock_opname.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_stock.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_incoming.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_incoming_view.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_allocation.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_picking.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_picking_view.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_do_tasks.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_pr.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_pr_view.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_stock_transfer.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_stock_adjustment.php'],
    ['module' => 'WQS', 'path' => 'stock/wqs_stock_audit.php'],
    ['module' => 'HRL', 'path' => 'hrl/index.php'],
    ['module' => 'HRL', 'path' => 'hrl/hrl_docs.php'],
    ['module' => 'HRL', 'path' => 'hrl/hrl_doc_view.php'],
    ['module' => 'HRL', 'path' => 'hrl/hrl_ack_report.php'],
    ['module' => 'HRL', 'path' => 'hrl/hrl_tower.php'],
    // folder: hrl_process/ — RBAC module: HRL (sub-feature HRL.PROCESS_*)
    ['module' => 'HRL', 'path' => 'hrl_process/index.php'],
    ['module' => 'HRL', 'path' => 'hrl_process/request_view.php'],
    ['module' => 'HRL', 'path' => 'hrl_process/my_pin.php'],
    ['module' => 'HRL', 'path' => 'hrl_process/tower.php'],
    ['module' => 'HRL_REG_ALKES', 'path' => 'hrl_reg_alkes/index.php'],
    ['module' => 'HRL_REG_ALKES', 'path' => 'hrl_reg_alkes/reg_alkes.php'],
    ['module' => 'HRL_REG_ALKES', 'path' => 'hrl_reg_alkes/reg_alkes_case.php'],
    ['module' => 'HRL_REG_ALKES', 'path' => 'hrl_reg_alkes/reg_alkes_control_tower.php'],
    ['module' => 'ABSENSI', 'path' => 'absensi/index.php'],
    ['module' => 'ABSENSI', 'path' => 'absensi/checkin.php'],
    ['module' => 'ABSENSI', 'path' => 'absensi/checkout.php'],
    ['module' => 'ABSENSI', 'path' => 'absensi/request.php'],
    ['module' => 'ABSENSI', 'path' => 'absensi/admin/rekap.php'],
    ['module' => 'KPI', 'path' => 'kpi/index.php'],
    ['module' => 'KPI', 'path' => 'kpi/kpi_center.php'],
    ['module' => 'KPI', 'path' => 'kpi/kpi_dashboard_daily.php'],
    ['module' => 'KPI', 'path' => 'kpi/kpi_dashboard_monthly.php'],
    ['module' => 'KPI', 'path' => 'kpi/kpi_employee.php'],
    ['module' => 'KPI', 'path' => 'kpi/kpi_office.php'],
    ['module' => 'KPI', 'path' => 'kpi/kpi_stock.php'],
    ['module' => 'KPI', 'path' => 'kpi/kpi_purchases.php'],
    ['module' => 'PAYROLL', 'path' => 'payroll/index.php'],
    ['module' => 'PAYROLL', 'path' => 'payroll/payroll_run.php'],
    ['module' => 'PAYROLL', 'path' => 'payroll/salary_matrix.php'],
    ['module' => 'PAYROLL', 'path' => 'payroll/loans.php'],
    ['module' => 'PAYROLL', 'path' => 'payroll/payslip.php'],
    ['module' => 'MPR', 'path' => 'mpr/index.php'],
    ['module' => 'MPR', 'path' => 'mpr/mpr_dashboard.php'],
    ['module' => 'MPR', 'path' => 'mpr/mpr_plans.php'],
    ['module' => 'MPR', 'path' => 'mpr/mpr_plan_view.php'],
    ['module' => 'MPR', 'path' => 'mpr/mpr_budget_fin.php'],
    ['module' => 'FIXED_ASSET', 'path' => 'Fixed_Asset/index.php'],
    ['module' => 'FIXED_ASSET', 'path' => 'Fixed_Asset/assets.php'],
    ['module' => 'FIXED_ASSET', 'path' => 'Fixed_Asset/ops.php'],
    ['module' => 'FIXED_ASSET', 'path' => 'Fixed_Asset/depreciation.php'],
    ['module' => 'RBAC', 'path' => 'rbac/index.php'],
    ['module' => 'TOOLS', 'path' => 'tools/index.php'],
    ['module' => 'TOOLS', 'path' => 'tools/health.php'],
    ['module' => 'TOOLS', 'path' => 'tools/signoff/business_signoff.php'],
    ['module' => 'TOOLS', 'path' => 'tools/backup_manager.php'],
];

$menuRoutes = [];
foreach ($manualRoutes as $r) {
    $menuRoutes[] = [
        'module' => $r['module'],
        'menu_name' => $r['module'] . ' / ' . basename($r['path'], '.php'),
        'url' => $baseUrl . '/' . ltrim($r['path'], '/'),
        'path' => $r['path'],
        'required_role_or_perm' => 'internal',
    ];
}

$menuJsonPath = $logsDir . '/menu_routes_extracted.json';
file_put_contents($menuJsonPath, json_encode(['extracted_at' => date('c'), 'base_url' => $baseUrl, 'routes' => $menuRoutes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Extracted " . count($menuRoutes) . " routes -> " . $menuJsonPath . "\n";

// --- 2) HTTP checks (guest) ---
$httpResults = [];
$guestOk = 0;
$guestFail = 0;

// Public/guest OK: login, health
$guestExpected200 = ['/master/login.php', '/api/v1/health.php', '/'];
foreach ($menuRoutes as $r) {
    $path = '/' . ltrim($r['path'], '/');
    $url = $r['url'];
    $res = tools_http_get($url, 15);
    $code = (int)($res['status'] ?? 0);
    $isPublic = in_array($path, $guestExpected200, true);
    $expect = $isPublic ? 200 : 302;
    $ok = ($code === 200 && $isPublic) || ($code === 302 && !$isPublic);
    if ($code === 0) {
        $ok = false;
    }
    $httpResults[] = [
        'module' => $r['module'],
        'path' => $r['path'],
        'url' => $url,
        'code' => $code,
        'expected_guest' => $isPublic ? '200' : '302',
        'ok' => $ok,
        'note' => $ok ? '' : 'guest_check',
    ];
    if ($ok) {
        $guestOk++;
    } else {
        $guestFail++;
    }
}

// --- 3) Data integrity (summary only, no sensitive data) ---
$dataIntegrity = [];
$pdo = null;
try {
    if (function_exists('rmi_db_pdo')) {
        $pdo = rmi_db_pdo();
    }
} catch (Throwable $e) {
    $dataIntegrity['db_connect'] = ['ok' => false, 'error' => 'db_unavailable'];
}

if ($pdo instanceof PDO) {
    $thirtyDays = date('Y-m-d', strtotime('-30 days'));
    $ninetyDays = date('Y-m-d', strtotime('-90 days'));

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM wqs_pr WHERE created_at >= ?");
        $st->execute([$thirtyDays]);
        $prCount = (int)($st->fetchColumn() ?: 0);
        $st2 = $pdo->prepare("SELECT COUNT(*) FROM wqs_pr WHERE created_at >= ? AND (office_code IS NULL OR office_code = '' OR TRIM(office_code) = '')");
        $st2->execute([$thirtyDays]);
        $prMissing = (int)($st2->fetchColumn() ?: 0);
        $dataIntegrity['pr_30d'] = [
            'count' => $prCount,
            'missing_office_pct' => $prCount > 0 ? round(100 * $prMissing / $prCount, 2) : 0,
            'ok' => $prMissing === 0,
        ];
    } catch (Throwable $e) {
        $dataIntegrity['pr_30d'] = ['ok' => false, 'error' => 'table_or_column'];
    }

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE deleted_at IS NULL AND created_at >= ?");
        $st->execute([$thirtyDays]);
        $poCount = (int)($st->fetchColumn() ?: 0);
        $st2 = $pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE deleted_at IS NULL AND created_at >= ? AND (office_code IS NULL OR office_code = '' OR TRIM(office_code) = '')");
        $st2->execute([$thirtyDays]);
        $poMissing = (int)($st2->fetchColumn() ?: 0);
        $dataIntegrity['po_30d'] = [
            'count' => $poCount,
            'missing_office_pct' => $poCount > 0 ? round(100 * $poMissing / $poCount, 2) : 0,
            'ok' => $poMissing === 0,
        ];
    } catch (Throwable $e) {
        $dataIntegrity['po_30d'] = ['ok' => false, 'error' => 'table_or_column'];
    }

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM wqs_incoming WHERE received_date >= ?");
        $st->execute([$thirtyDays]);
        $grCount = (int)($st->fetchColumn() ?: 0);
        $dataIntegrity['gr_30d'] = ['count' => $grCount, 'ok' => true];
    } catch (Throwable $e) {
        $dataIntegrity['gr_30d'] = ['ok' => false, 'error' => 'table_or_column'];
    }

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_invoice_ap WHERE deleted_at IS NULL AND created_at >= ?");
        $st->execute([$thirtyDays]);
        $apCount = (int)($st->fetchColumn() ?: 0);
        $dataIntegrity['ap_invoice_30d'] = ['count' => $apCount, 'ok' => true];
    } catch (Throwable $e) {
        $dataIntegrity['ap_invoice_30d'] = ['ok' => false, 'error' => 'table_or_column'];
    }

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM wqs_stock_opname WHERE opname_date >= ?");
        $st->execute([$ninetyDays]);
        $opnameCount = (int)($st->fetchColumn() ?: 0);
        $dataIntegrity['opname_90d'] = ['count' => $opnameCount, 'ok' => true];
    } catch (Throwable $e) {
        $dataIntegrity['opname_90d'] = ['ok' => false, 'error' => 'table_or_column'];
    }
}

// --- 4) Run tools ---
$evidence = [];
$phpBin = (string)(getenv('ERP_PHP_BIN') ?: (defined('PHP_BINARY') ? PHP_BINARY : 'php'));
$envPrefix = 'TOOLS_BASE_URL_INTERNAL=' . escapeshellarg($baseUrl) . ' TOOLS_BASE_URL=' . escapeshellarg($baseUrl) . ' APP_URL=' . escapeshellarg($baseUrl) . ' ';

$toolsToRun = [
    ['name' => 'smoke_http', 'cmd' => $envPrefix . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/smoke_http.php') . ' --strict --write-last', 'artifact' => 'smoke_http_last.json'],
    ['name' => 'contract_check', 'cmd' => $envPrefix . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/contract_check.php') . ' --strict --write-last', 'artifact' => 'contract_check_last.json'],
    ['name' => 'unicode_guard', 'cmd' => escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/unicode_guard.php') . ' --scan --strict --write-last', 'artifact' => 'unicode_guard_last.json'],
];

foreach ($toolsToRun as $t) {
    $out = [];
    exec($t['cmd'] . ' 2>&1', $out, $exit);
    $evidence[$t['name']] = [
        'exit_code' => $exit,
        'ok' => $exit === 0,
        'artifact' => $logsDir . '/' . $t['artifact'],
    ];
}

// run_cutover_checks requires NAS app_root - skip if not on NAS
$actualRoot = realpath($root) ?: $root;
$expectedRoot = trim((string)(is_file($root . '/.expected_app_root') ? file_get_contents($root . '/.expected_app_root') : '/volume4/web/ERP_RMI_SOFULL'));
if ($actualRoot === $expectedRoot) {
    $cutoverCmd = $envPrefix . escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/run_cutover_checks.php') . ' --write-last --strict';
    $out = [];
    exec($cutoverCmd . ' 2>&1', $out, $exit);
    $evidence['run_cutover_checks'] = ['exit_code' => $exit, 'ok' => $exit === 0, 'artifact' => $logsDir . '/cutover_checks.last.json'];
} else {
    $evidence['run_cutover_checks'] = ['exit_code' => -1, 'ok' => false, 'skip_reason' => 'APP_ROOT mismatch (NAS required)'];
}

// tools_doctor
if (is_file($root . '/tools/qa/tools_doctor.php')) {
    $tdCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($root . '/tools/qa/tools_doctor.php') . ' --mode=check --write-last 2>&1';
    $out = [];
    @exec($tdCmd, $out, $exit);
    $evidence['tools_doctor'] = ['exit_code' => $exit, 'ok' => $exit === 0, 'artifact' => $logsDir . '/tools_doctor_last.json'];
} else {
    $evidence['tools_doctor'] = ['skip_reason' => 'file_not_found'];
}

// --- 5) Build modules summary ---
$modulesSummary = [];
foreach ($menuRoutes as $r) {
    $mod = $r['module'];
    if (!isset($modulesSummary[$mod])) {
        $modulesSummary[$mod] = ['urls' => [], 'ok' => true, 'issues' => []];
    }
    $match = null;
    foreach ($httpResults as $hr) {
        if ($hr['path'] === $r['path']) {
            $match = $hr;
            break;
        }
    }
    $modulesSummary[$mod]['urls'][] = [
        'path' => $r['path'],
        'code' => $match['code'] ?? null,
        'ok' => $match['ok'] ?? false,
    ];
    if ($match && !$match['ok']) {
        $modulesSummary[$mod]['ok'] = false;
        $modulesSummary[$mod]['issues'][] = $r['path'] . ' (guest:' . ($match['code'] ?? 0) . ')';
    }
}

// Primary: route coverage (guest HTTP). Tools = evidence only.
$overallOk = $guestFail === 0;

// --- 6) Write e2e_trail_last.json ---
$trailJson = [
    'run_id' => $runId,
    'run_at' => date('c'),
    'overall_ok' => $overallOk,
    'base_url' => $baseUrl,
    'guest_ok' => $guestOk,
    'guest_fail' => $guestFail,
    'modules' => array_map(function ($k, $v) {
        $v['name'] = $k;
        return $v;
    }, array_keys($modulesSummary), array_values($modulesSummary)),
    'data_integrity' => $dataIntegrity,
    'evidence' => $evidence,
    'http_results' => $httpResults,
];
file_put_contents($logsDir . '/e2e_trail_last.json', json_encode($trailJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// --- 7) Write E2E_TRAIL_LAST.md ---
$md = "# E2E Trail Report — ERP_RMI_SOFULL\n\n";
$md .= "**Run ID:** {$runId}  \n**Run At:** " . date('c') . "  \n**Base URL:** " . ($baseUrl ? '[BASE_URL]' : 'N/A') . "\n\n";
$md .= "## Ringkasan\n\n";
$md .= "- **Overall:** " . ($overallOk ? 'PASS' : 'FAIL') . "\n";
$md .= "- **Guest HTTP:** {$guestOk} OK, {$guestFail} FAIL\n";
$toolsStatus = [];
foreach (['smoke_http', 'contract_check', 'unicode_guard', 'run_cutover_checks', 'tools_doctor'] as $t) {
    $e = $evidence[$t] ?? [];
    $toolsStatus[] = $t . ':' . (($e['ok'] ?? false) ? 'OK' : (($e['skip_reason'] ?? '') ? 'SKIP' : 'FAIL'));
}
$md .= "- **Tools:** " . implode(', ', $toolsStatus) . "\n\n";
$md .= "## Per Modul\n\n";
$md .= "| Modul | Entry URL | Status | Catatan |\n|-------|-----------|--------|--------|\n";

foreach ($modulesSummary as $mod => $sum) {
    $entryPath = '';
    foreach ($sum['urls'] as $u) {
        if (str_contains($u['path'], 'index.php') || str_contains($u['path'], 'dashboard')) {
            $entryPath = $u['path'];
            break;
        }
    }
    if (!$entryPath && !empty($sum['urls'])) {
        $entryPath = $sum['urls'][0]['path'];
    }
    $status = $sum['ok'] ? 'OK' : 'FAIL';
    $note = implode('; ', array_slice($sum['issues'], 0, 3));
    $md .= "| {$mod} | {$entryPath} | {$status} | " . ($note ?: '-') . " |\n";
}

$md .= "\n## Stock Opname (WQS)\n\n";
$md .= "- Modul: WQS (Warehouse/Stock)\n";
$md .= "- Entry: `/stock/wqs_stock_opname.php`\n";
$md .= "- Flow: Apply opname → adjustment resmi (tidak bikin flow liar)\n\n";

$md .= "## Temuan Prioritas\n\n";
$issues = [];
foreach ($httpResults as $hr) {
    if (!$hr['ok']) {
        $issues[] = ['path' => $hr['path'], 'code' => $hr['code'], 'module' => $hr['module']];
    }
}
$top10 = array_slice($issues, 0, 10);
foreach ($top10 as $i) {
    $md .= "- **" . ($i['code'] === 0 ? 'UNREACHABLE' : 'HTTP ' . $i['code']) . "** {$i['module']}/{$i['path']}\n";
}
if (empty($top10)) {
    $md .= "- (Tidak ada)\n";
}

$md .= "\n## Rekomendasi\n\n";
if ($guestFail > 0) {
    $md .= "- Periksa URL yang return non-302 (guest): pastikan redirect ke login.\n";
}
if (!($evidence['run_cutover_checks']['ok'] ?? false) && ($evidence['run_cutover_checks']['skip_reason'] ?? '') === 'APP_ROOT mismatch (NAS required)') {
    $md .= "- Jalankan run_cutover_checks di NAS: `cd /volume4/web/ERP_RMI_SOFULL && php tools/qa/run_cutover_checks.php --write-last --strict`\n";
}
$md .= "\n## Evidence\n\n";
$md .= "- menu_routes_extracted.json\n";
$md .= "- e2e_trail_last.json\n";
$md .= "- smoke_http_last.json\n";
$md .= "- contract_check_last.json\n";
$md .= "- cutover_checks.last.json (jika di NAS)\n";

file_put_contents($e2eDir . '/E2E_TRAIL_LAST.md', $md);

// --- 8) Evidence zip ---
$zipPath = $logsDir . '/e2e_trail_pack_' . $runId . '.zip';
if (class_exists('ZipArchive')) {
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $files = [
        $e2eDir . '/E2E_TRAIL_LAST.md' => 'E2E_TRAIL_LAST.md',
        $menuJsonPath => 'menu_routes_extracted.json',
        $logsDir . '/e2e_trail_last.json' => 'e2e_trail_last.json',
    ];
    foreach (['smoke_http_last.json', 'contract_check_last.json', 'cutover_checks.last.json', 'unicode_guard_last.json', 'tools_doctor_last.json'] as $f) {
        $fp = $logsDir . '/' . $f;
        if (is_file($fp)) {
            $files[$fp] = $f;
        }
    }
    foreach ($files as $abs => $rel) {
        if (is_file($abs)) {
            $zip->addFile($abs, $rel);
        }
    }
    $zip->close();
    echo "Evidence pack: {$zipPath}\n";
} else {
    echo "WARN: Could not create zip\n";
}
} else {
    echo "WARN: ZipArchive not available, skipping evidence pack\n";
}

echo "\nDone. Overall: " . ($overallOk ? 'PASS' : 'FAIL') . "\n";
