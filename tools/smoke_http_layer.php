<?php
/**
 * Single-base HTTP smoke checks (internal or public layer).
 * Loaded by smoke_http.php — uses s_req(), s_has_crash(), s_csrf_token(), url_join(), s_log().
 */
declare(strict_types=1);

/**
 * @return array{results: list<array<string,mixed>>, failed: int, passed: int, base_url: string, elapsed_ms: int}
 */
function smoke_http_run_layer(
    string $root,
    string $baseInput,
    string $layerKey,
    string $adminUser,
    string $adminPass,
    string $staffUser,
    string $staffPass
): array {
    $base = $baseInput;
    $results = [];
    $failed = 0;
    $passed = 0;
    $started = microtime(true);

    $add = function (
        string $category,
        string $name,
        bool $ok,
        string $detail = '',
        ?int $httpCode = null,
        string $layer = ''
    ) use (&$results, &$failed, &$passed, $layerKey): void {
        $row = [
            'category' => $category,
            'name' => $name,
            'ok' => $ok,
            'detail' => $detail,
            'layer' => $layer,
        ];
        if ($httpCode !== null) {
            $row['http_code'] = $httpCode;
        }
        if (!$ok) {
            $row['mismatch_category'] = ($httpCode === 301) ? 'URL_JOIN_BUG' : 'OTHER';
        }
        $results[] = $row;
        if ($ok) {
            $passed++;
        } else {
            $failed++;
        }
    };

    $guestCookie = $root . '/storage/logs/.smoke_guest_' . $layerKey . '.txt';
    $adminCookie = $root . '/storage/logs/.SmokeSYS_SYS_' . $layerKey . '.txt';
    $staffCookie = $root . '/storage/logs/.SmokeBRANCH_SYS_' . $layerKey . '.txt';
    @unlink($guestCookie);
    @unlink($adminCookie);
    @unlink($staffCookie);

    $s_join = function (string $b, string $p): string {
        $p = $p === '' ? '/' : ($p[0] === '/' ? $p : '/' . $p);

        return url_join($b, $p);
    };

    // Auto-detect HTTP→HTTPS redirect
    {
        $preflight = s_req('GET', $s_join($base, '/master/login.php'), $guestCookie);
        if ($preflight['code'] === 301) {
            if (preg_match('/Location:\s*(https:\/\/[^\s\r\n]+)/i', $preflight['header'], $m)) {
                $redirectTarget = rtrim((string)$m[1], '/');
                $parsed = parse_url($redirectTarget);
                if (!empty($parsed['host'])) {
                    $newBase = 'https://' . $parsed['host'];
                    if (!empty($parsed['port']) && (int)$parsed['port'] !== 443) {
                        $newBase .= ':' . $parsed['port'];
                    }
                    $oldPath = rtrim((string)(parse_url($base, PHP_URL_PATH) ?? ''), '/');
                    $newBase .= $oldPath;
                    s_log("AUTO HTTP→HTTPS [{$layerKey}]: {$base} → {$newBase}");
                    $base = $newBase;
                    if (function_exists('normalize_base_url')) {
                        try {
                            $base = normalize_base_url($base);
                        } catch (Throwable $e) { /* keep */
                        }
                    }
                    @unlink($guestCookie);
                }
            }
            $add('preflight', 'http_to_https_redirect', true, "base updated code={$preflight['code']}", $preflight['code'], $layerKey);
        } else {
            $add('preflight', 'http_to_https_redirect', true, "code={$preflight['code']}", $preflight['code'], $layerKey);
        }
    }

    foreach (['/', '/master/login.php', '/api/v1/health.php'] as $p) {
        $r = s_req('GET', $s_join($base, $p), $guestCookie);
        $okCode = in_array($r['code'], [200, 302], true);
        $okCrash = !s_has_crash($r['body']);
        $add('runtime_guest', "GET {$p}", $okCode && $okCrash, "code={$r['code']}", $r['code'], $layerKey);
    }

    foreach (['/master/master_system_login.php', '/tools/health.php', '/tools/backup_manager.php'] as $p) {
        $r = s_req('GET', $s_join($base, $p), $guestCookie);
        $ok = in_array($r['code'], [302, 303, 401, 403], true);
        $add('security_guard', "guest_block {$p}", $ok, "code={$r['code']}", $r['code'], $layerKey);
    }

    $rLoginStaff = s_req('POST', $s_join($base, '/master/login.php'), $staffCookie, ['username' => $staffUser, 'password' => $staffPass]);
    $add('runtime_auth', 'login_staff', in_array($rLoginStaff['code'], [302, 303], true), "code={$rLoginStaff['code']}", $rLoginStaff['code'], $layerKey);
    foreach (['/master/master_system_login.php', '/tools/health.php', '/tools/backup_manager.php'] as $p) {
        $r = s_req('GET', $s_join($base, $p), $staffCookie);
        $ok = in_array($r['code'], [302, 303, 401, 403], true);
        $add('security_guard', "staff_block {$p}", $ok, "code={$r['code']}", $r['code'], $layerKey);
    }

    $rLogin = s_req('POST', $s_join($base, '/master/login.php'), $adminCookie, ['username' => $adminUser, 'password' => $adminPass]);
    $add('runtime_auth', 'login_admin', in_array($rLogin['code'], [302, 303], true), "code={$rLogin['code']}", $rLogin['code'], $layerKey);

    $authPaths = [
        '/dashboards/index.php',
        '/master/master_system_login.php',
        '/master/audit_logs.php',
        '/master/monitoring_center.php',
        '/master/itc_reset_password.php',
        '/master/master_system_config.php',
        '/tools/health.php',
        '/tools/backup_schedule.php',
        '/tools/backup_manager.php',
        '/sales/sales_dashboard.php',
        '/purchases/index.php',
        '/stock/index.php',
        '/rbac/index.php',
        '/absensi/admin/settings.php',
        '/absensi/admin/shifts.php',
        '/dashboards/finance/ar_ap_cash_dashboard.php',
    ];
    foreach ($authPaths as $p) {
        $r = s_req('GET', $s_join($base, $p), $adminCookie);
        $ok = in_array($r['code'], [200, 302], true) && !s_has_crash($r['body']);
        $add('runtime_auth', "GET {$p}", $ok, "code={$r['code']}", $r['code'], $layerKey);
    }

    $rCfg = s_req('GET', $s_join($base, '/master/master_system_config.php'), $adminCookie);
    $okCfgAdmin = $rCfg['code'] === 200 && !s_has_crash($rCfg['body']) && stripos($rCfg['body'], 'Akses Ditolak') === false;
    $add('security_guard', 'admin_allow_master_system_config', $okCfgAdmin, "code={$rCfg['code']}", $rCfg['code'], $layerKey);

    $rCfgStaff = s_req('GET', $s_join($base, '/master/master_system_config.php'), $staffCookie);
    $okCfgStaff = $rCfgStaff['code'] === 403
        || stripos($rCfgStaff['body'], 'Akses Ditolak') !== false
        || in_array($rCfgStaff['code'], [302, 303], true);
    $add('security_guard', 'staff_block_master_system_config', $okCfgStaff, "code={$rCfgStaff['code']}", $rCfgStaff['code'], $layerKey);

    $freshCookieFile = $root . '/storage/logs/.smoke_fresh_' . $layerKey . '.txt';
    @unlink($freshCookieFile);
    $rLoginFresh = s_req('GET', $s_join($base, '/master/login.php'), $freshCookieFile);
    $loginHeader = strtolower($rLoginFresh['header']);
    $hasHttpOnly = strpos($loginHeader, 'httponly') !== false;
    $hasSameSite = strpos($loginHeader, 'samesite=lax') !== false
        || strpos($loginHeader, 'samesite=strict') !== false;
    @unlink($freshCookieFile);
    $add('security_session', 'login_cookie_httponly', $hasHttpOnly, $hasHttpOnly ? 'HttpOnly present' : 'HttpOnly MISSING', $rLoginFresh['code'], $layerKey);
    $add('security_session', 'login_cookie_samesite', $hasSameSite, $hasSameSite ? 'SameSite present' : 'SameSite MISSING', $rLoginFresh['code'], $layerKey);

    $hasAutocomplete = isset($rLoginFresh['body']) && stripos($rLoginFresh['body'], 'autocomplete="username"') !== false;
    $add('runtime_guest', 'login_autocomplete_attr', $hasAutocomplete, $hasAutocomplete ? 'ok' : 'autocomplete missing', null, $layerKey);

    $rAuditLog = s_req('GET', $s_join($base, '/master/audit_logs.php'), $adminCookie);
    $okAuditIp = $rAuditLog['code'] === 200 && stripos($rAuditLog['body'], 'IP') !== false;
    $add('runtime_auth', 'audit_log_ip_column', $okAuditIp, "code={$rAuditLog['code']}", $rAuditLog['code'], $layerKey);

    $rMon = s_req('GET', $s_join($base, '/master/monitoring_center.php'), $adminCookie);
    $okMon = $rMon['code'] === 200 && stripos($rMon['body'], 'Monitoring') !== false && !s_has_crash($rMon['body']);
    $add('runtime_auth', 'monitoring_center_page', $okMon, "code={$rMon['code']}", $rMon['code'], $layerKey);

    $rAbsSettings = s_req('GET', $s_join($base, '/absensi/admin/settings.php'), $adminCookie);
    $okAbsSettings = in_array($rAbsSettings['code'], [200, 302], true)
        && !s_has_crash($rAbsSettings['body'])
        && (stripos($rAbsSettings['body'], 'checkin_std_time') !== false || $rAbsSettings['code'] === 302);
    $add('runtime_auth', 'absensi_settings_form', $okAbsSettings, "code={$rAbsSettings['code']}", $rAbsSettings['code'], $layerKey);

    $rAbsShifts = s_req('GET', $s_join($base, '/absensi/admin/shifts.php'), $adminCookie);
    $okAbsShifts = in_array($rAbsShifts['code'], [200, 302], true)
        && !s_has_crash($rAbsShifts['body'])
        && (stripos($rAbsShifts['body'], 'absensi_shifts') !== false
            || stripos($rAbsShifts['body'], 'Shift') !== false
            || $rAbsShifts['code'] === 302);
    $add('runtime_auth', 'absensi_shifts_page', $okAbsShifts, "code={$rAbsShifts['code']}", $rAbsShifts['code'], $layerKey);

    $lockedGetPaths = [
        '/stock/wqs_pr.php',
        '/purchases/purchases_po.php',
        '/stock/wqs_incoming.php',
        '/purchases/purchases_invoice_ap.php',
        '/purchases/purchases_payment_ap.php',
        '/purchases/gl_reversal_approvals.php',
        '/sales/sales_order.php',
        '/sales/sales_do.php',
        '/stock/wqs_stock.php',
        '/stock/wqs_stock_adjustment.php',
        '/stock/wqs_picking.php',
        '/stock/wqs_allocation.php',
    ];
    foreach ($lockedGetPaths as $p) {
        $r = s_req('GET', $s_join($base, $p), $adminCookie);
        $ok = in_array($r['code'], [200, 302], true) && !s_has_crash($r['body']);
        if ($p === '/purchases/gl_reversal_approvals.php') {
            $schemaMissingWarn = stripos($r['body'], 'gl_reversal_requests') !== false
                && (stripos($r['body'], 'belum tersedia') !== false || stripos($r['body'], 'Load failed') !== false);
            if ($schemaMissingWarn) {
                $ok = false;
                $add('locked_routes', "GET {$p}", false, "code={$r['code']}, schema_missing_warning", $r['code'], $layerKey);
                continue;
            }
        }
        $add('locked_routes', "GET {$p}", $ok, "code={$r['code']}", $r['code'], $layerKey);
    }

    $rNoCsrfBackup = s_req('POST', $s_join($base, '/tools/backup_manager.php'), $adminCookie, ['op' => 'backup_now', 'label' => 'smoke_invalid']);
    $okNoCsrfBackup = ($rNoCsrfBackup['code'] === 403) || stripos($rNoCsrfBackup['body'], 'CSRF') !== false;
    $add('security_csrf', 'backup_manager_without_csrf_rejected', $okNoCsrfBackup, "code={$rNoCsrfBackup['code']}", $rNoCsrfBackup['code'], $layerKey);

    $rNoCsrfLife = s_req('POST', $s_join($base, '/master/master_system_login.php'), $adminCookie, ['op' => 'create', 'username' => 'SmokeNoCsrf_SYS', 'password' => 'x']);
    $okNoCsrfLife = ($rNoCsrfLife['code'] === 403) || stripos($rNoCsrfLife['body'], 'CSRF') !== false;
    $add('security_csrf', 'lifecycle_without_csrf_rejected', $okNoCsrfLife, "code={$rNoCsrfLife['code']}", $rNoCsrfLife['code'], $layerKey);

    $rBGet = s_req('GET', $s_join($base, '/tools/backup_manager.php'), $adminCookie);
    $csrfB = s_csrf_token($rBGet['body']);
    $beforePkgs = count(glob($root . '/storage/backups/ERP_RMI_SOFULL_backup_*') ?: []);
    $rBPost = s_req('POST', $s_join($base, '/tools/backup_manager.php'), $adminCookie, ['csrf_token' => $csrfB, 'op' => 'backup_now', 'label' => 'smoke_http'], [], 180);
    $afterPkgs = count(glob($root . '/storage/backups/ERP_RMI_SOFULL_backup_*') ?: []);
    $okBValid = $csrfB !== '' && $rBPost['code'] === 200 && $afterPkgs >= $beforePkgs;
    $add('tools', 'backup_now_with_valid_csrf', $okBValid, "code={$rBPost['code']}, before={$beforePkgs}, after={$afterPkgs}", $rBPost['code'], $layerKey);

    $rLGet = s_req('GET', $s_join($base, '/master/master_system_login.php'), $adminCookie);
    $csrfL = s_csrf_token($rLGet['body']);
    $newUser = 'smoke_u_' . $layerKey . '_' . date('His');
    $rLPost = s_req('POST', $s_join($base, '/master/master_system_login.php'), $adminCookie, [
        'csrf_token' => $csrfL,
        'op' => 'create',
        'username' => $newUser,
        'password' => 'SmokeUser#123',
        'full_name' => 'Smoke User',
        'role' => 'staff',
        'level' => 'staff',
        'department' => 'ITC',
        'office_code' => '',
        'status' => 'ACTIVE',
    ]);
    $okLValid = $csrfL !== '' && in_array($rLPost['code'], [200, 302, 303], true) && stripos($rLPost['body'], 'Forbidden (CSRF)') === false;
    $add('security_csrf', 'lifecycle_with_valid_csrf', $okLValid, "code={$rLPost['code']}", $rLPost['code'], $layerKey);

    $rHealthJson = s_req('GET', $s_join($base, '/api/v1/health.php'), $adminCookie);
    $j = json_decode($rHealthJson['body'], true);
    $okStruct = is_array($j) && isset($j['data']['db'], $j['data']['storage'], $j['data']['cron'], $j['data']['worker_queue']);
    $add('tools', 'health_json_structure', $okStruct, "code={$rHealthJson['code']}", $rHealthJson['code'], $layerKey);
    $rHealthUi = s_req('GET', $s_join($base, '/tools/health.php'), $adminCookie);
    $okMask = stripos($rHealthUi['body'], '[APP_ROOT]') !== false;
    $add('tools', 'health_ui_masked_paths', $okMask, "code={$rHealthUi['code']}", $rHealthUi['code'], $layerKey);

    $elapsed = (int)round((microtime(true) - $started) * 1000);

    return [
        'results' => $results,
        'failed' => $failed,
        'passed' => $passed,
        'base_url' => $base,
        'elapsed_ms' => $elapsed,
    ];
}
