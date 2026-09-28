<?php
declare(strict_types=1);

/**
 * Day-1 readiness preflight (CLI).
 *
 * Usage:
 *   php bin/readiness_preflight.php
 *
 * Output:
 *   - storage/audit/readiness_preflight_latest.json
 *   - storage/audit/readiness_preflight_latest.md
 *   - storage/audit/account_readiness_issues.csv
 */

require_once __DIR__ . '/../_shared/db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$auditDir = $root . '/storage/audit';
if (!is_dir($auditDir)) {
    @mkdir($auditDir, 0775, true);
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function pf_add(array &$rows, string $domain, string $check, string $status, string $detail, string $evidence): void
{
    $rows[] = [
        'domain' => $domain,
        'check' => $check,
        'status' => strtoupper($status),
        'detail' => $detail,
        'evidence' => $evidence,
    ];
}

function pf_table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function pf_bool_str(bool $ok): string
{
    return $ok ? 'PASS' : 'FAIL';
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function pf_to_md(array $rows, string $generatedAt, string $gate): string
{
    $md = "# Readiness Preflight Report (CLI)\n\n";
    $md .= "- Generated at: `{$generatedAt}`\n";
    $md .= "- Gate result: **{$gate}**\n\n";
    $md .= "| Domain | Check | Status | Detail | Evidence |\n";
    $md .= "|---|---|---|---|---|\n";
    foreach ($rows as $r) {
        $domain = str_replace('|', '\\|', (string)($r['domain'] ?? ''));
        $check = str_replace('|', '\\|', (string)($r['check'] ?? ''));
        $status = strtoupper((string)($r['status'] ?? 'WARN'));
        $detail = str_replace('|', '\\|', (string)($r['detail'] ?? ''));
        $evidence = str_replace('|', '\\|', (string)($r['evidence'] ?? ''));
        $md .= "| {$domain} | {$check} | {$status} | {$detail} | `{$evidence}` |\n";
    }
    return $md . "\n";
}

$checks = [];
$generatedAt = date('c');

// 1) Critical pages file existence
$critical = [
    'master/master_system_login.php',
    'master/mfa_policy.php',
    'master/mfa_bypass.php',
    'master/jobs_monitor.php',
    'master/rate_limit_policies.php',
    'rbac/index.php',
    'chat/admin_settings.php',
    'tools/review_kit_workspace.php',
    'tools/readiness_audit.php',
];
foreach ($critical as $rel) {
    $ok = is_file($root . '/' . $rel);
    pf_add(
        $checks,
        'FILES',
        'Critical page exists: ' . $rel,
        pf_bool_str($ok),
        $ok ? 'Found' : 'Missing',
        $rel
    );
}

// 2) Rollout docs existence
$docs = [
    'ROLLOUT_24H_SOCIALIZATION_PLAN.md',
    'ROLLOUT_COMMUNICATION_TEMPLATES.md',
    'ERP_DAY1_FAQ_TROUBLESHOOT.md',
    'EXECUTIVE_BROADCAST_DAY1_ERP.md',
    'GO_NO_GO_DAY1_SHEET.md',
];
foreach ($docs as $rel) {
    $ok = is_file($root . '/' . $rel);
    pf_add(
        $checks,
        'DOCS',
        'Rollout doc exists: ' . $rel,
        $ok ? 'PASS' : 'WARN',
        $ok ? 'Ready' : 'Missing (recommended)',
        $rel
    );
}

// 3) Chat migrations
$chatMigs = [
    'sql/migrations/090_internal_chat_module.sql',
    'sql/migrations/091_chat_collab_features.sql',
    'sql/migrations/092_chat_presence_thread_prefs.sql',
    'sql/migrations/093_chat_thread_acl_mentions.sql',
];
foreach ($chatMigs as $rel) {
    $ok = is_file($root . '/' . $rel);
    pf_add(
        $checks,
        'CHAT MIGRATION',
        'Migration exists: ' . basename($rel),
        pf_bool_str($ok),
        $ok ? 'Found' : 'Missing',
        $rel
    );
}

// 4) DB checks
$accountIssues = [];
try {
    $pdo = rmi_db_pdo();
    pf_add($checks, 'DB', 'Database connectivity', 'PASS', 'Connected to DB.', '_shared/db.php');

    if (pf_table_exists($pdo, 'master_system_login')) {
        // Missing dept/office on ACTIVE users
        try {
            $sql = "SELECT id, username, COALESCE(full_name,'') AS full_name, COALESCE(role,'') AS role, COALESCE(level,'') AS level,
                           COALESCE(department,'') AS department, COALESCE(office_code,'') AS office_code, COALESCE(status,'') AS status
                    FROM master_system_login
                    WHERE UPPER(COALESCE(status,''))='ACTIVE'
                      AND (COALESCE(TRIM(department),'')='' OR COALESCE(TRIM(office_code),'')='')
                    ORDER BY id ASC";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $accountIssues[] = [
                    'issue_type' => 'MISSING_DEPT_OR_OFFICE',
                    'id' => (int)($r['id'] ?? 0),
                    'username' => (string)($r['username'] ?? ''),
                    'full_name' => (string)($r['full_name'] ?? ''),
                    'role' => (string)($r['role'] ?? ''),
                    'level' => (string)($r['level'] ?? ''),
                    'department' => (string)($r['department'] ?? ''),
                    'office_code' => (string)($r['office_code'] ?? ''),
                    'status' => (string)($r['status'] ?? ''),
                ];
            }
            $cnt = count($rows);
            pf_add(
                $checks,
                'ACCOUNT',
                'Active users missing department/office',
                $cnt === 0 ? 'PASS' : 'WARN',
                $cnt === 0 ? 'No issues found.' : ("Found {$cnt} account issues."),
                'master_system_login'
            );
        } catch (Throwable $e) {
            pf_add($checks, 'ACCOUNT', 'Active users missing department/office', 'WARN', 'Query failed.', 'master_system_login');
        }

        // Suspicious role/level empty
        try {
            $sql = "SELECT COUNT(*) FROM master_system_login
                    WHERE UPPER(COALESCE(status,''))='ACTIVE'
                      AND (COALESCE(TRIM(role),'')='' OR COALESCE(TRIM(level),'')='')";
            $cnt = (int)$pdo->query($sql)->fetchColumn();
            pf_add(
                $checks,
                'ACCOUNT',
                'Active users with empty role/level',
                $cnt === 0 ? 'PASS' : 'WARN',
                $cnt === 0 ? 'No issues found.' : ("Found {$cnt} users with empty role/level."),
                'master_system_login'
            );
        } catch (Throwable $e) {
            pf_add($checks, 'ACCOUNT', 'Active users with empty role/level', 'WARN', 'Query failed.', 'master_system_login');
        }
    } else {
        pf_add($checks, 'ACCOUNT', 'master_system_login table readiness', 'FAIL', 'Table not found.', 'master_system_login');
    }

    if (pf_table_exists($pdo, 'auth_mfa_bypass_tickets')) {
        try {
            $sql = "SELECT COUNT(*) FROM auth_mfa_bypass_tickets
                    WHERE is_active=1
                      AND UPPER(COALESCE(status,''))='APPROVED'
                      AND expires_at IS NOT NULL
                      AND expires_at < NOW()";
            $cnt = (int)$pdo->query($sql)->fetchColumn();
            pf_add(
                $checks,
                'SECURITY',
                'Expired MFA bypass still active',
                $cnt === 0 ? 'PASS' : 'WARN',
                $cnt === 0 ? 'No expired active bypass found.' : ("Found {$cnt} expired active bypass tickets."),
                'auth_mfa_bypass_tickets'
            );
        } catch (Throwable $e) {
            pf_add($checks, 'SECURITY', 'Expired MFA bypass still active', 'WARN', 'Query failed.', 'auth_mfa_bypass_tickets');
        }
    } else {
        pf_add($checks, 'SECURITY', 'auth_mfa_bypass_tickets table readiness', 'WARN', 'Table not found.', 'auth_mfa_bypass_tickets');
    }

    if (pf_table_exists($pdo, 'auth_mfa_policies')) {
        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM auth_mfa_policies")->fetchColumn();
            pf_add(
                $checks,
                'SECURITY',
                'MFA policy rows present',
                $cnt > 0 ? 'PASS' : 'WARN',
                $cnt > 0 ? ("{$cnt} policy rows found.") : 'No policy rows found.',
                'auth_mfa_policies'
            );
        } catch (Throwable $e) {
            pf_add($checks, 'SECURITY', 'MFA policy rows present', 'WARN', 'Query failed.', 'auth_mfa_policies');
        }
    } else {
        pf_add($checks, 'SECURITY', 'auth_mfa_policies table readiness', 'WARN', 'Table not found.', 'auth_mfa_policies');
    }
} catch (Throwable $e) {
    pf_add($checks, 'DB', 'Database connectivity', 'FAIL', 'Connection failed: ' . $e->getMessage(), '_shared/db.php');
}

$pass = count(array_filter($checks, static fn($c): bool => strtoupper((string)$c['status']) === 'PASS'));
$warn = count(array_filter($checks, static fn($c): bool => strtoupper((string)$c['status']) === 'WARN'));
$fail = count(array_filter($checks, static fn($c): bool => strtoupper((string)$c['status']) === 'FAIL'));
$gate = $fail > 0 ? 'NO-GO' : (($warn > 3) ? 'GO WITH CAUTION' : 'GO');

$jsonPath = $auditDir . '/readiness_preflight_latest.json';
$mdPath = $auditDir . '/readiness_preflight_latest.md';
$csvPath = $auditDir . '/account_readiness_issues.csv';

$payload = [
    'generated_at' => $generatedAt,
    'gate' => $gate,
    'summary' => [
        'pass' => $pass,
        'warn' => $warn,
        'fail' => $fail,
        'total' => count($checks),
    ],
    'checks' => $checks,
];
@file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
@file_put_contents($mdPath, pf_to_md($checks, $generatedAt, $gate), LOCK_EX);

$csv = fopen($csvPath, 'wb');
if ($csv) {
    fputcsv($csv, ['issue_type', 'id', 'username', 'full_name', 'role', 'level', 'department', 'office_code', 'status'], ',', '"', '\\');
    foreach ($accountIssues as $r) {
        fputcsv($csv, [
            $r['issue_type'],
            $r['id'],
            $r['username'],
            $r['full_name'],
            $r['role'],
            $r['level'],
            $r['department'],
            $r['office_code'],
            $r['status'],
        ], ',', '"', '\\');
    }
    fclose($csv);
}

echo "Readiness Preflight completed\n";
echo "Gate: {$gate}\n";
echo "Summary: PASS={$pass}, WARN={$warn}, FAIL={$fail}, TOTAL=" . count($checks) . "\n";
echo "Reports:\n";
echo "- {$jsonPath}\n";
echo "- {$mdPath}\n";
echo "- {$csvPath}\n";
exit($gate === 'NO-GO' ? 2 : 0);

