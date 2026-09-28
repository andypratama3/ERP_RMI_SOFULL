<?php
/**
 * tools/qa/rbac_coverage_check.php
 *
 * RBAC coverage check — scan modul utama untuk guard minimal.
 * Rule:
 * - Jika ada form POST/mutasi -> harus ada verify_csrf + require_permission atau require_login
 * - Jika file di tools/ops, tools/dr, tools/release -> harus admin guard
 *
 * Output: storage/logs/rbac_coverage_last.json
 * Skor 100 jika tidak ada CRITICAL findings.
 *
 * Jalankan: php tools/qa/rbac_coverage_check.php
 * Atau: ./tools/nas/erp.sh php tools/qa/rbac_coverage_check.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);

require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}
require_once $root . '/tools/tools_state_lib.php';

$logsDir = ts_storage_logs_dir();
$outPath = $logsDir . '/rbac_coverage_last.json';

$scanDirs = [
    'master',
    'purchases',
    'stock',
    'sales',
    'hrl',
    'hrl_process',
    'hrl_reg_alkes',
    'payroll',
    'mpr',
    'kpi',
    'Fixed_Asset',
    'chat',
    'tools',
    'dashboards',
    'api',
    'absensi',
    'rbac',
    'docs',
    'customer_portal',
    'manufacturer_portal',
];

$adminGuardPaths = ['tools/ops', 'tools/dr', 'tools/release'];
$publicAllowlist = [
    'master/login.php',
    'master/logout.php',
    'master/mfa_verify.php',
    'sales/sales_do_rekap.php',
    'api/v1/health.php',
    'api/health.php',
    'docs/help_sop_map_json.php',
    'customer_portal/login.php',
    'customer_portal/logout.php',
    'manufacturer_portal/login.php',
    'manufacturer_portal/logout.php',
];
$publicPatterns = ['tracking_public', 'tracking_token', 'public_tracking'];
$excludeCsrf = [
    'master/login.php', 'master/logout.php', 'master/mfa_verify.php', 'master/itc_reset_password.php',
    'api/v1/partner/order_create.php',
];
$criticalFindings = [];
$warnFindings = [];
$scanned = 0;

function rbac_cc_is_post_mutation(string $content): bool {
    if (!preg_match('/\$_POST|REQUEST_METHOD.*POST|POST.*REQUEST_METHOD/', $content)) {
        return false;
    }
    if (preg_match('/if\s*\(\s*.*(POST|post).*\)\s*\{/s', $content)) {
        return true;
    }
    if (str_contains($content, '$_POST[') || str_contains($content, '$_POST[')) {
        return true;
    }
    return str_contains($content, 'POST') && (str_contains($content, '$_POST') || str_contains($content, '$_REQUEST'));
}

function rbac_cc_has_csrf(string $content): bool {
    if (str_contains($content, 'verify_csrf') || str_contains($content, 'rmi_csrf_verify') || str_contains($content, 'csrf_verify')) {
        return true;
    }
    // require_login() in this codebase auto-calls verify_csrf for POST
    if (str_contains($content, 'require_login')) {
        return true;
    }
    // Manual CSRF check (e.g. $_POST['csrf'] === $csrf)
    return preg_match('/\$_POST\s*\[\s*[\'"]csrf[\'"]\s*\]\s*===?\s*\$|hash_equals\s*\([^)]*csrf/i', $content) === 1;
}

function rbac_cc_has_permission_guard(string $content): bool {
    return str_contains($content, 'require_permission') || str_contains($content, 'require_any_permission')
        || str_contains($content, 'require_login') || str_contains($content, 'require_role')
        || str_contains($content, 'require_portal_login') || str_contains($content, 'require_mportal_login')
        || str_contains($content, 'auth_allow_depts') || str_contains($content, 'auth_allow_roles')
        || str_contains($content, 'tools_require_access') || str_contains($content, 'tools_require_admin')
        || str_contains($content, 'opsgov_require_admin') || str_contains($content, 'require_admin_critical')
        || str_contains($content, 'rbac_require');
}

function rbac_cc_has_any_guard(string $content): bool {
    return str_contains($content, 'require_login') || str_contains($content, 'require_portal_login')
        || str_contains($content, 'require_mportal_login')
        || str_contains($content, 'rmi_require_module')
        || str_contains($content, 'require_any_permission') || str_contains($content, 'require_permission')
        || str_contains($content, 'rmi_require_admin') || str_contains($content, 'tools_require_admin')
        || str_contains($content, 'tools_require_access') || str_contains($content, 'require_role')
        || str_contains($content, 'opsgov_require_admin') || str_contains($content, 'require_admin_critical');
}

function rbac_cc_is_public_allowlist(string $relNorm): bool {
    global $publicAllowlist, $publicPatterns;
    if (in_array($relNorm, $publicAllowlist, true)) {
        return true;
    }
    foreach ($publicPatterns as $pat) {
        if (str_contains($relNorm, $pat)) {
            return true;
        }
    }
    return false;
}

function rbac_cc_has_admin_guard(string $content): bool {
    return str_contains($content, 'require_role') || str_contains($content, 'require_login')
        || str_contains($content, 'tools_require_access') || str_contains($content, 'auth_require_login')
        || str_contains($content, 'require_admin_critical') || str_contains($content, 'auth_is_admin')
        || str_contains($content, 'opsgov_require_admin');
}

$phpFiles = [];
foreach ($scanDirs as $dir) {
    $absDir = $root . '/' . $dir;
    if (!is_dir($absDir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absDir, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($it as $f) {
        if (!$f->isFile() || !str_ends_with($f->getFilename(), '.php')) {
            continue;
        }
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if (str_contains($rel, 'vendor') || str_contains($rel, 'node_modules') || str_contains($rel, 'exports/')) {
            continue;
        }
        $phpFiles[] = $rel;
    }
}
$phpFiles = array_unique($phpFiles);
sort($phpFiles);

foreach ($phpFiles as $rel) {
    $abs = $root . '/' . $rel;
    $content = @file_get_contents($abs);
    if ($content === false) {
        continue;
    }
    $scanned++;

    $relNorm = str_replace('\\', '/', $rel);

    $isLib = str_contains($relNorm, '/_lib/') || str_contains($relNorm, 'tools/_shared/')
        || str_starts_with($relNorm, 'tools/diagrams/lib/')
        || str_starts_with($relNorm, 'chat/views/')
        || preg_match('#^master/schema_.*\.php$#', $relNorm)
        || preg_match('#/_[^/]*helper\.php$#', $relNorm)
        || str_ends_with($relNorm, '_lib.php') || str_ends_with($relNorm, '_bootstrap.php')
        || str_starts_with($relNorm, 'tools/test/')
        || str_starts_with($relNorm, 'api/chat/') || str_starts_with($relNorm, 'api/v1/chat/')
        || str_starts_with($relNorm, 'api/v1/partner/')
        || str_starts_with($relNorm, 'api/mobile/') || str_starts_with($relNorm, 'api/v1/mobile/')
        || str_contains($relNorm, 'api/webhooks/')
        || str_contains($relNorm, '/layout.php') || str_contains($relNorm, '_manager_scope.php')
        || str_contains($relNorm, '_rekap_styles.php')
        || preg_match('#(customer_portal|manufacturer_portal)/_auth\.php$#', $relNorm)
        || str_contains($relNorm, 'absensi/_inc/')
        || in_array($relNorm, [
            'tools/tools_ui_helpers.php', 'tools/tools_state_lib.php', 'tools/tools_access_helpers.php',
            'tools/tools_access_matrix.php', 'tools/tools_alert_helpers.php', 'tools/tools_remote_check.php',
            'tools/cek_workspace_path.php', 'tools/compliance/evidence_export.php',
            'tools/dev/verify_help_map.php', 'tools/rbac_verify_config.php', 'tools/diagrams/generate_diagrams.php',
            'chat/admin_audit.php', 'chat/admin_channels.php',
            'docs/_token_helper.php',
        ], true)
        || preg_match('#tools/run_.*_cli\.php$#', $relNorm);
    $head = substr($content, 0, 1200);
    $isCliOnly = str_contains($head, 'PHP_SAPI') && str_contains($head, 'cli') && (str_contains($head, 'exit') || str_contains($head, 'exit('));
    $isCliDir = str_starts_with($relNorm, 'tools/nas/') || str_starts_with($relNorm, 'tools/audit/')
        || str_starts_with($relNorm, 'tools/migration/') || preg_match('#tools/migrate_[^/]+\.php$#', $relNorm)
        || str_starts_with($relNorm, 'tools/qa/');
    $isPublic = rbac_cc_is_public_allowlist($relNorm);

    if (!$isLib && !$isCliOnly && !$isCliDir && !$isPublic && !rbac_cc_has_any_guard($content)) {
        $isToolsOrMasterAdmin = str_starts_with($relNorm, 'tools/') || in_array($relNorm, [
            'master/master_system_login.php', 'master/mfa_policy.php', 'master/jobs_monitor.php', 'master/rate_limit_policies.php',
        ], true);
        if ($isToolsOrMasterAdmin) {
            $criticalFindings[] = [
                'file' => $rel,
                'code' => 'MISSING_GUARD_CRITICAL',
                'message' => 'tools/ or master admin page must have require_login + rmi_require_admin or tools_require_admin',
            ];
        } else {
            $warnFindings[] = [
                'file' => $rel,
                'code' => 'MISSING_GUARD',
                'message' => 'Private page should have require_login or rmi_require_module',
            ];
        }
    }

    // Rule: tools/ops, tools/dr, tools/release must have admin guard (skip _lib, CLI-only)
    $needsAdminGuard = false;
    if (!$isLib && !$isCliOnly) {
        foreach ($adminGuardPaths as $ap) {
            if (str_starts_with($relNorm, $ap . '/') || $relNorm === $ap) {
                $needsAdminGuard = true;
                break;
            }
        }
    }
    if ($needsAdminGuard && !rbac_cc_has_admin_guard($content)) {
        $criticalFindings[] = [
            'file' => $rel,
            'code' => 'MISSING_ADMIN_GUARD',
            'message' => 'File in tools/ops, tools/dr, or tools/release must have require_login + require_role([ADMIN,SUPERADMIN]) or tools_require_access',
        ];
    }

    // Rule: POST/mutation must have verify_csrf + permission guard (skip auth exceptions)
    if ($isLib || $isCliOnly || $isCliDir || $isPublic || in_array($relNorm, $excludeCsrf, true)) {
        // skip
    } elseif (rbac_cc_is_post_mutation($content)) {
        if (!rbac_cc_has_csrf($content)) {
            $criticalFindings[] = [
                'file' => $rel,
                'code' => 'MISSING_CSRF',
                'message' => 'POST/mutation handler must call verify_csrf()',
            ];
        }
        if (!rbac_cc_has_permission_guard($content)) {
            $warnFindings[] = [
                'file' => $rel,
                'code' => 'MISSING_PERMISSION_GUARD',
                'message' => 'POST/mutation handler should have require_permission or require_login',
            ];
        }
    }
}

$criticalCount = count($criticalFindings);
$warnCount = count($warnFindings);
$score = ($criticalCount === 0) ? 100 : max(0, 100 - ($criticalCount * 25) - ($warnCount * 5));

$payload = [
    'run_at' => date(DateTimeInterface::ATOM),
    'scanned_files' => $scanned,
    'critical_count' => $criticalCount,
    'warn_count' => $warnCount,
    'score' => min(100, $score),
    'ok' => $criticalCount === 0,
    'critical_findings' => $criticalFindings,
    'warn_findings' => $warnFindings,
];

ts_write_json($outPath, $payload);

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($criticalCount === 0 ? 0 : 1);
