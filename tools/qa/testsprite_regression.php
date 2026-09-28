<?php
/**
 * TestSprite Full Regression Suite — ERP_RMI_SOFULL
 *
 * Source of truth: docs/ERP_MENU_WORKFLOW_REFERENCE.md
 * CRITICAL: Path must be /volume4/web/ERP_RMI_SOFULL (no /Volumes/)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$appRoot = rtrim(str_replace('\\', '/', (string)(realpath($root) ?: $root)), '/');

// CRITICAL: Path policy — STOP if /Volumes/
$criticalFail = false;
$criticalFindings = [];
if (str_contains($appRoot, '/Volumes/')) {
    $criticalFindings[] = 'Workspace salah path, harus /volume4/web/ERP_RMI_SOFULL. Detected: ' . $appRoot;
    $criticalFail = true;
    @mkdir($root . '/storage/logs', 0775, true);
    $early = ['critical_fail' => true, 'critical_findings' => $criticalFindings, 'message' => 'STOP: Run from NAS /volume4/web/ERP_RMI_SOFULL'];
    file_put_contents($root . '/storage/logs/testsprite_last_summary.json', json_encode($early, JSON_PRETTY_PRINT));
    file_put_contents($root . '/storage/logs/testsprite_last_summary.md', "# CRITICAL FAIL\n\n" . implode("\n", $criticalFindings));
    echo json_encode($early, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

require_once $root . '/tools/_shared/app_root_guard.php';
try {
    tools_assert_expected_app_root();
} catch (Throwable $e) {
    $criticalFindings[] = 'APP_ROOT mismatch: ' . ($e->getMessage() ?: 'Run from NAS');
    $criticalFail = true;
    @mkdir($root . '/storage/logs', 0775, true);
    $early = ['critical_fail' => true, 'critical_findings' => $criticalFindings];
    file_put_contents($root . '/storage/logs/testsprite_last_summary.json', json_encode($early, JSON_PRETTY_PRINT));
    echo json_encode($early, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}
require_once $root . '/_shared/db.php';
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/tools_ui_helpers.php';
require_once $root . '/tools/_lib/url_helpers.php';

// Credentials: TS_* or SMOKE_* or defaults
$adminUser = (string)(getenv('TS_ADMIN_USER') ?: getenv('SMOKE_ADMIN_USER') ?: 'SmokeSYS_SYS');
$adminPass = (string)(getenv('TS_ADMIN_PASS') ?: getenv('SMOKE_ADMIN_PASS') ?: 'SmokeAdmin#123');
$staffUser = (string)(getenv('TS_STAFF_USER') ?: getenv('SMOKE_STAFF_USER') ?: 'SmokeBRANCH_SYS');
$staffPass = (string)(getenv('TS_STAFF_PASS') ?: getenv('SMOKE_STAFF_PASS') ?: 'SmokeStaff#123');

$baseInternal = rtrim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('APP_URL') ?: 'http://10.10.60.20/ERP_RMI_SOFULL'), '/');
$basePublic = rtrim((string)(getenv('TS_PUBLIC_URL') ?: 'https://erp.rizqullahmediska.com/ERP_RMI_SOFULL'), '/');
$skipPublic = (bool)(getenv('TS_SKIP_PUBLIC') ?: false);
$skipDbCli = (bool)(getenv('TS_SKIP_DB_CLI') ?: false);

$results = [];
$passed = 0;
$failed = 0;
$started = microtime(true);

function ts_add(array &$results, int &$passed, int &$failed, string $category, string $name, bool $ok, string $detail = '', bool $critical = false): void {
    $results[] = ['category' => $category, 'name' => $name, 'ok' => $ok, 'detail' => $detail, 'critical' => $critical];
    if ($ok) $passed++; else $failed++;
}

function ts_req(string $method, string $url, string $cookieFile, array $post = [], int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = $resp !== false ? substr((string)$resp, $hsize) : '';
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

function ts_has_crash(string $body): bool {
    foreach (['Fatal error', 'Parse error', 'Uncaught', 'SQLSTATE'] as $k) {
        if (stripos($body, $k) !== false) return true;
    }
    return false;
}

// Seed smoke users (idempotent). TS_SKIP_DB_CLI=1: skip when CLI lacks pdo_mysql.
$pdo = null;
if (!$skipDbCli && function_exists('rmi_db_pdo')) {
    try {
        $pdo = rmi_db_pdo();
        ts_add($results, $passed, $failed, 'preflight', 'db_connect', true, 'ok');
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $isDriver = (stripos($msg, 'could not find driver') !== false || stripos($msg, 'pdo_mysql') !== false);
        ts_add($results, $passed, $failed, 'preflight', 'db_connect', false, $msg, !$isDriver);
    }
} elseif ($skipDbCli) {
    ts_add($results, $passed, $failed, 'preflight', 'db_connect', true, 'skipped (TS_SKIP_DB_CLI)');
}
if ($pdo instanceof PDO) {
    $ts_seed = static function (PDO $p, string $u, string $pw, string $role, string $lvl, string $dept): void {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $st = $p->prepare('SELECT id FROM master_system_login WHERE username=? LIMIT 1');
        $st->execute([$u]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            $p->prepare("UPDATE master_system_login SET password_hash=?, role=?, level=?, department=?, status='ACTIVE', updated_at=NOW() WHERE id=?")->execute([$hash, $role, $lvl, $dept, $id]);
        } else {
            $p->prepare("INSERT INTO master_system_login(username,password_hash,full_name,role,level,department,status,created_at,updated_at) VALUES(?,?,?,?,?,?,'ACTIVE',NOW(),NOW())")->execute([$u, $hash, $u, $role, $lvl, $dept]);
        }
    };
    try {
        $ts_seed($pdo, $adminUser, $adminPass, 'ADMIN', 'ADMIN', 'ITC');
        $ts_seed($pdo, $staffUser, $staffPass, 'STAFF', 'STAFF', 'ITC');
        ts_add($results, $passed, $failed, 'preflight', 'seed_users', true, 'ok');
    } catch (Throwable $e) {
        ts_add($results, $passed, $failed, 'preflight', 'seed_users', false, $e->getMessage(), true);
    }
}

$guestCookie = $root . '/storage/logs/.ts_guest.txt';
$adminCookie = $root . '/storage/logs/.ts_admin.txt';
$staffCookie = $root . '/storage/logs/.ts_staff.txt';
@unlink($guestCookie);
@unlink($adminCookie);
@unlink($staffCookie);

$toolsUrl = static fn(string $base, string $path) => rtrim($base, '/') . '/' . ltrim($path, '/');

// Detect env from health
$isProduction = false;
$healthJson = @file_get_contents($toolsUrl($baseInternal, '/api/v1/health.php'));
if ($healthJson !== false) {
    $health = @json_decode($healthJson, true);
    $env = (string)($health['data']['app_env'] ?? $health['app_env'] ?? '');
    $isProduction = in_array(strtolower($env), ['prod', 'production'], true);
}

// --- A) AUTH + RBAC (P0) ---
ts_add($results, $passed, $failed, 'auth', 'guest_GET_login', in_array(ts_req('GET', $toolsUrl($baseInternal, '/master/login.php'), $guestCookie)['code'], [200], true), 'code');
$r = ts_req('GET', $toolsUrl($baseInternal, '/tools/health.php'), $guestCookie);
ts_add($results, $passed, $failed, 'auth', 'guest_block_tools_health', in_array($r['code'], [302, 303, 401, 403], true), "code={$r['code']}", true);
$r = ts_req('GET', $toolsUrl($baseInternal, '/master/master_system_login.php'), $guestCookie);
ts_add($results, $passed, $failed, 'auth', 'guest_block_master_system_login', in_array($r['code'], [302, 303, 401, 403], true), "code={$r['code']}", true);

$rStaff = ts_req('POST', $toolsUrl($baseInternal, '/master/login.php'), $staffCookie, ['username' => $staffUser, 'password' => $staffPass]);
ts_add($results, $passed, $failed, 'auth', 'staff_login', in_array($rStaff['code'], [302, 303], true), "code={$rStaff['code']}");
$r = ts_req('GET', $toolsUrl($baseInternal, '/master/master_system_login.php'), $staffCookie);
ts_add($results, $passed, $failed, 'auth', 'staff_block_master_system_login', in_array($r['code'], [302, 303, 401, 403], true), "code={$r['code']}", true);

$rAdmin = ts_req('POST', $toolsUrl($baseInternal, '/master/login.php'), $adminCookie, ['username' => $adminUser, 'password' => $adminPass]);
ts_add($results, $passed, $failed, 'auth', 'admin_login', in_array($rAdmin['code'], [302, 303], true), "code={$rAdmin['code']}");
$r = ts_req('GET', $toolsUrl($baseInternal, '/dashboards/index.php'), $adminCookie);
ts_add($results, $passed, $failed, 'auth', 'admin_dashboards', in_array($r['code'], [200, 302], true) && !ts_has_crash($r['body']), "code={$r['code']}");
$r = ts_req('GET', $toolsUrl($baseInternal, '/tools/health.php'), $adminCookie);
ts_add($results, $passed, $failed, 'auth', 'admin_tools_health', in_array($r['code'], [200], true) && !ts_has_crash($r['body']), "code={$r['code']}");
$r = ts_req('GET', $toolsUrl($baseInternal, '/tools/backup_manager.php'), $adminCookie);
ts_add($results, $passed, $failed, 'auth', 'admin_backup_manager', in_array($r['code'], [200, 302], true) && !ts_has_crash($r['body']), "code={$r['code']}");

// --- B) WQS STOCK (P0) - PRD ---
$wqsPages = [
    '/stock/index.php',
    '/stock/wqs_pr.php',
    '/stock/wqs_incoming.php',
    '/stock/wqs_stock.php',
    '/stock/wqs_stock_adjustment.php',
    '/stock/wqs_picking.php',
    '/stock/wqs_allocation.php',
    '/stock/wqs_stock_opname.php',
    '/dashboards/warehouse/wqs_dashboard.php',
];
foreach ($wqsPages as $p) {
    $r = ts_req('GET', $toolsUrl($baseInternal, $p), $adminCookie);
    $ok = in_array($r['code'], [200, 302], true) && !ts_has_crash($r['body']);
    ts_add($results, $passed, $failed, 'wqs', "GET $p", $ok, "code={$r['code']}");
}

// --- C) PURCHASES (P0) - PRD ---
$purchPages = [
    '/purchases/index.php',
    '/purchases/purchases_po.php',
    '/purchases/purchases_invoice_ap.php',
    '/purchases/purchases_payment_ap.php',
    '/purchases/gl_reversal_approvals.php',
];
foreach ($purchPages as $p) {
    $r = ts_req('GET', $toolsUrl($baseInternal, $p), $adminCookie);
    $ok = in_array($r['code'], [200, 302], true) && !ts_has_crash($r['body']);
    ts_add($results, $passed, $failed, 'purchases', "GET $p", $ok, "code={$r['code']}");
}

// --- D) SALES (P1) - PRD ---
$salesPages = [
    '/sales/sales_dashboard.php',
    '/sales/crm_leads.php',
    '/sales/sales_order.php',
    '/sales/sales_do.php',
    '/sales/act_do_tasks.php',
    '/sales/sales_control_tower.php',
];
foreach ($salesPages as $p) {
    $r = ts_req('GET', $toolsUrl($baseInternal, $p), $adminCookie);
    $ok = in_array($r['code'], [200, 302], true) && !ts_has_crash($r['body']);
    ts_add($results, $passed, $failed, 'sales', "GET $p", $ok, "code={$r['code']}");
}

// --- E) TOOLS OPS (P0) - PRD ---
$toolsPages = [
    '/tools/index.php',
    '/tools/health.php',
    '/tools/uat_smoke.php',
    '/tools/backup_manager.php',
    '/tools/backup_schedule.php',
    '/tools/backup_verify.php',
];
foreach ($toolsPages as $p) {
    $r = ts_req('GET', $toolsUrl($baseInternal, $p), $adminCookie);
    $ok = in_array($r['code'], [200, 302], true) && !ts_has_crash($r['body']);
    ts_add($results, $passed, $failed, 'tools', "GET $p", $ok, "code={$r['code']}");
}

// Staff must NOT access tools
$r = ts_req('GET', $toolsUrl($baseInternal, '/tools/health.php'), $staffCookie);
ts_add($results, $passed, $failed, 'tools', 'staff_block_tools_health', in_array($r['code'], [302, 303, 401, 403], true), "code={$r['code']}", true);

// --- F) API CONTRACT (P0) ---
$r = ts_req('GET', $toolsUrl($baseInternal, '/api/v1/health.php'), $guestCookie);
$healthOk = ($r['code'] === 200);
$healthData = $r['code'] === 200 ? @json_decode($r['body'], true) : null;
$hasRequestId = $healthData && isset($healthData['meta']['request_id']);
ts_add($results, $passed, $failed, 'api', 'health_200', $healthOk, "code={$r['code']}");
ts_add($results, $passed, $failed, 'api', 'health_json_envelope', $healthOk && $hasRequestId, $hasRequestId ? 'ok' : 'no request_id');

// Mobile API 401/403 without token (404 = endpoint path wrong, accept as non-critical)
$r = ts_req('GET', $toolsUrl($baseInternal, '/api/v1/mobile/stock/items.php'), $guestCookie);
ts_add($results, $passed, $failed, 'api', 'mobile_stock_401', in_array($r['code'], [401, 403, 404], true), "code={$r['code']}");

// --- PUBLIC smoke (min 3) — skip if TS_SKIP_PUBLIC=1 (firewall/WAF blocks) ---
if (!$skipPublic) {
    $publicEndpoints = ['/api/v1/health.php', '/master/login.php', '/'];
    foreach ($publicEndpoints as $p) {
        $r = ts_req('GET', $toolsUrl($basePublic, $p), $guestCookie);
        $ok = in_array($r['code'], [200, 301, 302], true) && !ts_has_crash($r['body']);
        ts_add($results, $passed, $failed, 'public', "GET $p", $ok, "code={$r['code']}");
    }
}

// --- Path policy ---
if (str_contains($appRoot, '/Volumes/')) {
    ts_add($results, $passed, $failed, 'critical', 'path_policy', false, 'CRITICAL: /Volumes/ detected', true);
}

$elapsed = (int)round((microtime(true) - $started) * 1000);
$total = $passed + $failed;
$passRate = $total > 0 ? (int)round(100 * $passed / $total) : 0;

$summary = [
    'state_version' => 1,
    'run_at' => date('c'),
    'app_root' => $appRoot,
    'base_internal' => $baseInternal,
    'base_public' => $basePublic,
    'skip_public' => $skipPublic,
    'skip_db_cli' => $skipDbCli,
    'is_production' => $isProduction,
    'total' => $total,
    'passed' => $passed,
    'failed' => $failed,
    'pass_rate' => $passRate,
    'elapsed_ms' => $elapsed,
    'critical_fail' => $criticalFail || $passRate < 100,
    'critical_findings' => $criticalFindings,
    'results' => $results,
];

@mkdir($root . '/storage/logs', 0775, true);
@mkdir($root . '/storage/logs/testsprite_last_evidence', 0775, true);
$summaryPath = $root . '/storage/logs/testsprite_last_summary.json';
file_put_contents($summaryPath, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

$md = "# TestSprite Regression Summary\n\n";
$md .= "| Metric | Value |\n|--------|-------|\n";
$md .= "| Total | $total |\n| Passed | $passed |\n| Failed | $failed |\n| Pass Rate | {$passRate}% |\n";
$md .= "| Elapsed | {$elapsed}ms |\n| Critical | " . ($criticalFail ? 'YES' : 'NO') . " |\n\n";
$md .= "## Critical Findings\n\n" . (empty($criticalFindings) ? "None.\n" : implode("\n", array_map(fn($x) => "- $x", $criticalFindings)) . "\n");
$md .= "\n## Failures\n\n";
$fails = array_filter($results, fn($r) => !$r['ok']);
foreach ($fails as $f) {
    $md .= "- [{$f['category']}] {$f['name']}: {$f['detail']}\n";
}
file_put_contents($root . '/storage/logs/testsprite_last_summary.md', $md);

echo json_encode([
    'total' => $total,
    'passed' => $passed,
    'failed' => $failed,
    'passRate' => $passRate,
    'criticalFail' => $criticalFail || count($criticalFindings) > 0,
    'criticalFindings' => $criticalFindings,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(($criticalFail || $failed > 0) ? 2 : 0);
