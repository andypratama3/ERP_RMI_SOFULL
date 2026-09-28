<?php
declare(strict_types=1);

require_once __DIR__ . '/tools_remote_check.php';

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/_lib/bootstrap.php';
require_once __DIR__ . '/tools_state_lib.php';
require_once __DIR__ . '/tools_ui_helpers.php';
require_once __DIR__ . '/tools_access_helpers.php';
require_once __DIR__ . '/tools_alert_helpers.php';
require_once __DIR__ . '/_lib/tools_exec_helpers.php';
require_once __DIR__ . '/ops/_lib/plan_exec_summary_view_lib.php';
tools_require_access('__tools_index');
require_login();
// Tools & RBAC management = SYS ONLY (ITC has no special access)
if (function_exists('require_any_permission')) {
    require_once __DIR__ . '/../_shared/rbac.php';
    require_any_permission(['TOOLS.VIEW', 'SYSTEM.RBAC_MANAGE']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$forceMutatingMode = true;

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ti_score_badge')) {
    function ti_score_badge(int $score): array
    {
        if ($score >= 80) return ['success', 'HEALTHY'];
        if ($score >= 50) return ['warning', 'ATTENTION'];
        return ['danger', 'CRITICAL'];
    }
}

if (!function_exists('ti_state_error_label')) {
    function ti_state_error_label(string $err): string
    {
        return match ($err) {
            'missing' => 'Belum pernah dijalankan',
            'empty' => 'State file kosong atau tidak terbaca',
            'invalid_json' => 'State file invalid/corrupt',
            'schema_mismatch' => 'State schema tidak dikenali',
            default => 'State bermasalah',
        };
    }
}

if (!function_exists('ti_hardening_lock_path')) {
    function ti_hardening_lock_path(): string
    {
        return ts_root() . '/storage/locks/ops_hardening_full.lock.json';
    }
}

if (!function_exists('ti_is_pid_running')) {
    function ti_is_pid_running(int $pid): bool
    {
        if ($pid <= 0) return false;
        $out = [];
        $code = 1;
        @exec('ps -p ' . (int)$pid . ' -o pid= 2>/dev/null', $out, $code);
        if ($code !== 0) return false;
        foreach ($out as $line) {
            if ((int)trim((string)$line) === $pid) return true;
        }
        return false;
    }
}

if (!function_exists('ti_ops_hardening_runtime')) {
    function ti_ops_hardening_runtime(): array
    {
        $lock = ts_read_json(ti_hardening_lock_path());
        $pid = (int)($lock['pid'] ?? 0);
        if ($pid > 0 && ti_is_pid_running($pid)) {
            return ['running' => true, 'pid' => $pid, 'mode' => (string)($lock['mode'] ?? 'normal')];
        }
        if (is_file(ti_hardening_lock_path())) @unlink(ti_hardening_lock_path());
        return ['running' => false, 'pid' => $pid, 'mode' => (string)($lock['mode'] ?? 'normal')];
    }
}

if (!function_exists('ti_start_ops_hardening_async')) {
    function ti_start_ops_hardening_async(string $scope, string $mode): array
    {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['ops', 'full'], true)) $scope = 'ops';
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['normal', 'dry-run', 'reset'], true)) $mode = 'normal';

        $rt = ti_ops_hardening_runtime();
        if (!empty($rt['running'])) {
            return ['ok' => false, 'message' => 'Ops hardening masih berjalan (PID ' . (int)$rt['pid'] . ').'];
        }

        $root = ts_root();
        $phpBin = defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php';
        $script = $root . '/tools/ops/mutation_hardening_audit.php';
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' --scope=' . escapeshellarg($scope);
        if ($mode === 'dry-run') $cmd .= ' --baseline-dry-run';
        if ($mode === 'reset') $cmd .= ' --baseline-reset';
        $log = ts_storage_logs_dir() . '/ops_hardening_async.log';
        $spawn = 'nohup ' . $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
        $out = [];
        $code = 1;
        @exec($spawn, $out, $code);
        $pid = (int)trim((string)($out[0] ?? '0'));
        if ($code !== 0 || $pid <= 0) {
            return ['ok' => false, 'message' => 'Gagal start ops hardening async.'];
        }
        ts_write_json(ti_hardening_lock_path(), [
            'state_version' => 1,
            'pid' => $pid,
            'scope' => $scope,
            'mode' => $mode,
            'started_at' => date(DateTimeInterface::ATOM),
        ]);
        ts_append_run_history('ops_hardening_async_start', 'OK', [
            'actor_username' => tools_current_actor_username(),
            'source' => 'tools/index.php',
            'scope' => $scope,
            'mode' => $mode,
            'pid' => $pid,
        ]);
        return ['ok' => true, 'message' => 'Ops hardening dijalankan di background (PID ' . $pid . ').'];
    }
}

if (!function_exists('ti_all_checks_lock_path')) {
    function ti_all_checks_lock_path(): string
    {
        return ts_root() . '/storage/locks/all_checks.lock.json';
    }
}

if (!function_exists('ti_all_checks_runtime')) {
    function ti_all_checks_runtime(): array
    {
        $lock = ts_read_json(ti_all_checks_lock_path());
        $pid = (int)($lock['pid'] ?? 0);
        if ($pid > 0 && ti_is_pid_running($pid)) {
            return ['running' => true, 'pid' => $pid, 'mode' => (string)($lock['mode'] ?? 'quick')];
        }
        if (is_file(ti_all_checks_lock_path())) @unlink(ti_all_checks_lock_path());
        return ['running' => false, 'pid' => $pid, 'mode' => (string)($lock['mode'] ?? 'quick')];
    }
}

if (!function_exists('ti_start_all_checks_async')) {
    function ti_start_all_checks_async(bool $quick = true): array
    {
        $rt = ti_all_checks_runtime();
        if (!empty($rt['running'])) {
            return ['ok' => false, 'message' => 'All checks masih berjalan (PID ' . (int)$rt['pid'] . ').'];
        }
        $root = ts_root();
        $phpBin = defined('PHP_BINARY') && PHP_BINARY ? (string)PHP_BINARY : 'php';
        $script = $root . '/tools/qa/run_all_checks.php';
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ($quick ? ' --quick' : '');
        $log = ts_storage_logs_dir() . '/all_checks_async.log';
        $spawn = 'nohup ' . $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
        $out = [];
        $code = 1;
        @exec($spawn, $out, $code);
        $pid = (int)trim((string)($out[0] ?? '0'));
        if ($code !== 0 || $pid <= 0) {
            return ['ok' => false, 'message' => 'Gagal start all checks async.'];
        }
        ts_write_json(ti_all_checks_lock_path(), [
            'state_version' => 1,
            'pid' => $pid,
            'mode' => $quick ? 'quick' : 'full',
            'started_at' => date(DateTimeInterface::ATOM),
        ]);
        ts_append_run_history('all_checks_async_start', 'OK', [
            'actor_username' => tools_current_actor_username(),
            'source' => 'tools/index.php',
            'mode' => $quick ? 'quick' : 'full',
            'pid' => $pid,
        ]);
        return ['ok' => true, 'message' => 'All checks dijalankan di background (PID ' . $pid . ').'];
    }
}

$known = [
    'ops/reset_for_golive_ui.php' => ['title' => '🚀 Reset for Go-Live', 'cat' => 'Backup / Restore', 'safety' => 'DESTRUCTIVE', 'web' => true, 'owner' => 'SYS Only', 'tags' => ['WEB', 'MUTATING', 'SYS_ONLY'], 'pinned' => true, 'desc' => 'Reset ERP sebelum production: full/transactions/testdata. Backup otomatis sebelum reset.'],
    'backup_manager.php' => ['title' => 'Backup Manager', 'cat' => 'Backup / Restore', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'DevOps Team', 'tags' => ['WEB', 'MUTATING'], 'pinned' => true, 'desc' => 'One-click backup + list packages'],
    'backup_now.php' => ['title' => 'Backup Now', 'cat' => 'Backup / Restore', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'DevOps Team', 'tags' => ['WEB', 'MUTATING'], 'pinned' => true, 'desc' => 'Trigger backup now'],
    'backup_retention.php' => ['title' => 'Backup Retention', 'cat' => 'Backup / Restore', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'DevOps Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Cleanup old backup packages'],
    'backup_schedule.php' => ['title' => 'Autobackup Scheduler', 'cat' => 'Backup / Restore', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'DevOps Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Enable/check/disable daily backup'],
    'backup_verify.php' => ['title' => 'Backup Verify', 'cat' => 'Backup / Restore', 'safety' => 'READ', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'READ'], 'desc' => 'Verify checksums'],
    'restore_now.php' => ['title' => 'Restore Now (Web)', 'cat' => 'Backup / Restore', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'DevOps Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Restore package from web'],
    'restore.php' => ['title' => 'Restore CLI Wrapper', 'cat' => 'Backup / Restore', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'DevOps Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Safe restore wrapper for restore_now.sh'],
    'db_test.php' => ['title' => 'DB Test', 'cat' => 'Diagnostics', 'safety' => 'READ', 'web' => true, 'owner' => 'DBA Team', 'tags' => ['WEB', 'READ'], 'desc' => 'Quick DB connectivity'],
    'diag_boot.php' => ['title' => 'Diag Boot', 'cat' => 'Diagnostics', 'safety' => 'READ', 'web' => true, 'owner' => 'Platform Team', 'tags' => ['WEB', 'READ'], 'desc' => 'Bootstrap chain checks'],
    'diag_db.php' => ['title' => 'Diag DB', 'cat' => 'Diagnostics', 'safety' => 'READ', 'web' => true, 'owner' => 'DBA Team', 'tags' => ['WEB', 'READ'], 'desc' => 'DB config + query checks'],
    'log_test.php' => ['title' => 'Log Test', 'cat' => 'Diagnostics', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Platform Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Write test logs'],
    'health.php' => ['title' => 'Go-Live Health', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'READ'], 'pinned' => true, 'desc' => 'DB/storage/cron/worker health'],
    'preflight_check.php' => ['title' => 'Preflight Check', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => true, 'owner' => 'DevOps Team', 'tags' => ['WEB', 'READ'], 'desc' => 'Deploy preflight checks'],
    'readiness_audit.php' => ['title' => 'Readiness Audit', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'READ'], 'desc' => 'Readiness report generator'],
    'security_audit.php' => ['title' => 'Security Audit', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => true, 'owner' => 'Security Team', 'tags' => ['WEB', 'READ'], 'desc' => 'Security regex scan'],
    'rbac_effective_permissions.php' => ['title' => 'RBAC Effective Permissions', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => true, 'owner' => 'Security Team', 'tags' => ['WEB', 'READ'], 'desc' => 'Inspect effective permissions'],
    'review_kit.php' => ['title' => 'Review Kit', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'READ'], 'desc' => 'Audit review checklist'],
    'review_kit_workspace.php' => ['title' => 'Review Kit Workspace', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'READ'], 'desc' => 'Workspace review notes'],
    'signoff/business_signoff.php' => ['title' => 'Business Sign-off', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Business QA', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Capture formal business approval evidence'],
    'smoke_http.php' => ['title' => 'Smoke HTTP (CLI)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'QA Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Runtime smoke runner'],
    'qa/smoke_pwa.php' => ['title' => 'Smoke PWA (CLI)', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => false, 'owner' => 'QA Team', 'tags' => ['CLI_ONLY', 'READ'], 'desc' => 'PWA manifest & file checks untuk install Android'],
    'qa/smoke_http_web.php' => ['title' => 'Smoke HTTP (Latest)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'pinned' => true, 'desc' => 'Run runtime smoke from tools UI'],
    'qa/all_checks_web.php' => ['title' => 'All Checks (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run all checks quick/full from tools UI'],
    'qa/cutover_checks_web.php' => ['title' => 'Cutover Checks (Latest)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'pinned' => true, 'desc' => 'Run cutover checks from tools UI'],
    'qa/negative_tests_web.php' => ['title' => 'Negative Tests (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run negative tests from tools UI'],
    'qa/contract_check_web.php' => ['title' => 'Contract Check (Latest)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'pinned' => true, 'desc' => 'Run strict contract check from tools UI'],
    'qa/unicode_guard_web.php' => ['title' => 'Unicode Guard (Latest)', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'READ'], 'pinned' => true, 'desc' => 'View latest Cyrillic/Unicode scan (ASCII-only policy)'],
    'qa/audit_live_web.php' => ['title' => 'Live Audit (Latest)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'pinned' => true, 'desc' => 'Live audit real data + real-time. Run Now or view latest.'],
    'qa/insight_real_web.php' => ['title' => 'INSIGHT REAL', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Platform Team', 'tags' => ['WEB', 'MUTATING'], 'pinned' => true, 'desc' => 'SYS-only: snapshot DB+logs → insight_real_last.* + query log + audit INSIGHT_REAL_RUN'],
    'ops/audit_exec_summary.php' => ['title' => 'Audit Exec Summary (Latest)', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'READ'], 'pinned' => true, 'desc' => 'One-page exec summary from live audit'],
    'qa/sales_tracking_checks_web.php' => ['title' => 'Sales Tracking Checks (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run sales tracking integrity checks from tools UI'],
    'qa/signoff_ops_web.php' => ['title' => 'Signoff Ops Chain (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run hypercare checkpoint + summary + signoff verdict from tools UI'],
    'qa/hypercare_summary_web.php' => ['title' => 'Hypercare Summary (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run hypercare summary from tools UI'],
    'qa/signoff_verdict_web.php' => ['title' => 'Signoff Verdict (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Generate GO/NO-GO verdict from tools UI'],
    'qa/tools_dashboard_smoke_web.php' => ['title' => 'Tools Dashboard Smoke (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run tools dashboard smoke from tools UI'],
    'qa/hypercare_checkpoint_web.php' => ['title' => 'Hypercare Checkpoint (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run hypercare checkpoint from tools UI'],
    'qa/mobile_api_smoke_web.php' => ['title' => 'Mobile API Smoke (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run mobile API smoke from tools UI'],
    'qa/mobile_auth_policy_web.php' => ['title' => 'Mobile Auth & Policy (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run mobile negative auth + master policy checks from tools UI'],
    'qa/mobile_api_contract_check_web.php' => ['title' => 'Mobile API Contract Check (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run mobile API contract envelope checks from tools UI'],
    'qa/dashboard_role_smoke_web.php' => ['title' => 'Dashboard Role Smoke (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run manager/staff dashboard role smoke from tools UI'],
    'qa/chat_api_smoke_web.php' => ['title' => 'Chat API Smoke (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run chat API smoke from tools UI'],
    'qa/evidence_pack_web.php' => ['title' => 'Evidence Pack (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Generate evidence pack from tools UI'],
    'qa/chat_schema_smoke_web.php' => ['title' => 'Chat Schema Smoke (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run chat schema smoke from tools UI'],
    'qa/sales_tracking_smoke_web.php' => ['title' => 'Sales Tracking Smoke (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run sales tracking structure smoke from tools UI'],
    'qa/tools_doctor_web.php' => ['title' => 'Tools Doctor (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Scan tools untuk bootstrap, CSRF, admin guard. Hasil CLI tampil di web'],
'qa/web_wrapper_health_matrix.php' => ['title' => 'Web Wrapper Health Matrix', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'One-page matrix for wrapper state + one-click smoke'],
    'qa/http_response_analysis_web.php' => ['title' => 'HTTP Response Analyzer (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Detail HTTP timing analysis table (code, TTFB, total, DNS, size, redirect)'],
    'qa/erp_structure_guard_web.php' => ['title' => 'ERP Structure Guard (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run structure guard checks for core ERP modules'],
    'qa/erp_boundary_guard_web.php' => ['title' => 'ERP Boundary Guard (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run boundary smell checks across ERP modules'],
    'qa/erp_pattern_standard_web.php' => ['title' => 'ERP Pattern Standard (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run coding pattern standard checks (strict types + CSRF pattern)'],
    'qa/erp_hardening_triage_web.php' => ['title' => 'ERP Hardening Triage (Web)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'One-click combined runner for structure + boundary + pattern guards'],
    'hardening/erp_hardening_triage_web.php' => ['title' => 'Hardening Triage', 'cat' => 'Hardening + QA + Ops', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Security Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Deterministic hardening triage viewer and runner'],
    'hardening/auto_normalize_extended.php' => ['title' => 'Auto Normalize Extended', 'cat' => 'Hardening + QA + Ops', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Security Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Preview/apply whitelist-only normalization with backup'],
    'doctor/index.php' => ['title' => 'Doctor One Click', 'cat' => 'Hardening + QA + Ops', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Safe one-click gate pipeline with lock + retry'],
    'release/release_final_checklist.php' => ['title' => 'Release Final Checklist', 'cat' => 'Hardening + QA + Ops', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Release Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'DONE/IN_PROGRESS/BLOCKED + GO/NO-GO'],
    'ops/weekly_trend.php' => ['title' => 'Weekly Trend', 'cat' => 'Hardening + QA + Ops', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'OPS Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Trend 7/30 hari score/p0/p1/p2'],
    'ops/sla_monitor.php' => ['title' => 'SLA Monitor', 'cat' => 'Hardening + QA + Ops', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'OPS Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'SLA harian: P0 harus 0 dalam 24 jam'],
    'ops/manual_action_queue.php' => ['title' => 'Manual Action Queue', 'cat' => 'Hardening + QA + Ops', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'OPS Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Queue non-autofix findings hingga DONE'],
    'qa/smoke_tools_regression.php' => ['title' => 'Tools Regression Smoke', 'cat' => 'Hardening + QA + Ops', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'QA Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Smoke regresi tooling setelah batch perubahan'],
    'smoke_schedule.php' => ['title' => 'Nightly Smoke Scheduler', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Manage nightly smoke scheduler'],
    'uat_smoke.php' => ['title' => 'UAT Smoke', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'READ'], 'desc' => 'UAT smoke checks'],
    'repo_health_gaps.php' => ['title' => 'Repo Health Gaps', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Release Security', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'One-command repo readiness gap audit (UI + CLI engine)'],
    'qa/mobile_quality_gate.php' => ['title' => 'Mobile Quality Gate', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Run mobile API quality gate from tools dashboard'],
    'enterprise_audit.php' => ['title' => 'Enterprise Audit (CLI)', 'cat' => 'Gate / Audit', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Security Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Enterprise audit writer'],
    'qa/arch_audit.php' => ['title' => 'Architecture Audit (CLI)', 'cat' => 'Gate / Audit', 'safety' => 'READ', 'web' => false, 'owner' => 'QA Team', 'tags' => ['CLI_ONLY', 'READ'], 'desc' => 'Repo scan: broker, data gov, IAM, monitoring, backup DR'],
    'migrate_cdn_to_local.php' => ['title' => 'Migrate CDN to Local', 'cat' => 'Migration (CLI)', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Platform Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'One-time migration'],
    'migrate_cdn_round2.php' => ['title' => 'Migrate CDN Round2', 'cat' => 'Migration (CLI)', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Platform Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'One-time migration (round2)'],
    'purchases_m2_apply.php' => ['title' => 'Purchases M2 Apply', 'cat' => 'Migration (CLI)', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Platform Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'One-time purchases patch'],
    'rbac_seed_act_purchases.php' => ['title' => 'RBAC Seed ACT', 'cat' => 'RBAC Seed (CLI)', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Security Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Seed ACT permissions'],
    'rbac_seed_fin_purchases.php' => ['title' => 'RBAC Seed FIN', 'cat' => 'RBAC Seed (CLI)', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Security Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Seed FIN permissions'],
    'rbac_seed_scm_purchases.php' => ['title' => 'RBAC Seed SCM', 'cat' => 'RBAC Seed (CLI)', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Security Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Seed SCM permissions'],
    'setup/seed_pembelian_antar_kantor.php' => ['title' => 'Seed Pembelian Antar Kantor', 'cat' => 'Setup / Migration', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'WQS Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Buat Customer Internal & Manufacture Kantor untuk flow pembelian antar kantor'],
    'migration_upload.php' => ['title' => 'Upload Migrasi Data', 'cat' => 'Setup / Migration', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'FIN Team', 'tags' => ['WEB', 'MUTATING'], 'pinned' => true, 'desc' => 'Upload Excel template migrasi (12 sheet) — Master + Stock + AP + AR langsung masuk sistem'],
    'enforce_h_guard.php' => ['title' => 'Enforce h() Guard', 'cat' => 'Security / Hardening', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Security Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Hardening patch with check/apply'],
    'enforce_login_guards.php' => ['title' => 'Enforce Login Guards', 'cat' => 'Security / Hardening', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Security Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Inject login guards'],
    'enforce_upload_safety.php' => ['title' => 'Enforce Upload Safety', 'cat' => 'Security / Hardening', 'safety' => 'MUTATING', 'web' => false, 'owner' => 'Security Team', 'tags' => ['CLI_ONLY', 'MUTATING'], 'desc' => 'Inject upload safety'],
    'plan_kerja.php' => ['title' => 'Plan Kerja Viewer', 'cat' => 'UI / Viewer', 'safety' => 'READ', 'web' => true, 'owner' => 'PMO Team', 'tags' => ['WEB', 'READ'], 'desc' => 'Roadmap viewer + checklist'],
    'ops/executive_ops_summary.php' => ['title' => 'Executive Ops Summary', 'cat' => 'Ops Monitoring', 'safety' => 'READ', 'web' => true, 'owner' => 'Release Team', 'tags' => ['WEB', 'READ'], 'pinned' => true, 'desc' => 'One-page screenshot/print summary'],
    'ops/runtime_hygiene_center.php' => ['title' => 'Runtime Hygiene Center', 'cat' => 'Ops Monitoring', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Platform Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Prune old runtime backups/logs/exports to prevent storage bloat'],
    'ops/findings.php' => ['title' => 'Ops Findings', 'cat' => 'Ops Monitoring', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'OPS Team', 'tags' => ['WEB', 'MUTATING'], 'pinned' => true, 'desc' => 'Manage open/in-progress/resolved findings + SLA'],
    'ops/module_governance_tracker.php' => ['title' => 'Module Governance Tracker', 'cat' => 'Ops Monitoring', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Release Captain', 'tags' => ['WEB', 'MUTATING'], 'pinned' => true, 'desc' => 'Batch 1-3 execution tracker by module and pillar'],
    'dr/backup_db.php' => ['title' => 'DR Backup DB', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'DevOps Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Backup database with checksum'],
    'dr/restore_db.php' => ['title' => 'DR Restore DB', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'DevOps Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Restore database + RTO/RPO log'],
    'dr/dr_log.php' => ['title' => 'DR Drill Log', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'OPS Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Tabletop/real drill logging'],
    'perf/perf_baseline.php' => ['title' => 'Perf Baseline', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Measure latency/disk/memory (PERF_BASELINE). Lihat docs/BASELINES.md'],
    'perf/perf_budget.php' => ['title' => 'Perf Budget', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'QA Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Budget status and warnings'],
    'perf/cleanup_runner.php' => ['title' => 'Cleanup Runner', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Platform Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Safe temp/log cleanup with dry-run'],
    'compliance/evidence_index.php' => ['title' => 'Evidence Pack Export', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Compliance Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'One-click evidence ZIP export'],
    'change/rfc_list.php' => ['title' => 'RFC Change Management', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'PMO Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'RFC registry and approval flow'],
    'release/release_gate.php' => ['title' => 'Release Gate', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Release Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Automated gate checks before release'],
    'release/go_live_readiness_one_click.php' => ['title' => 'Go Live Readiness One Click', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Release Captain', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'One-click final chain: closeout audit + handover pack + verifier'],
    'release/release_notes.php' => ['title' => 'Release Notes Generator', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Release Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Generate standard release notes'],
    'release/create_clean_deploy_zip.php' => ['title' => 'Create Clean Deploy ZIP', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Release Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Build lightweight deploy ZIP for hosting/VPS upload'],
    'release/deploy_size_audit.php' => ['title' => 'Deploy Size Audit', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'Release Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Generate clean ZIP + compressed/extracted size audit'],
    'ci/postmortem_list.php' => ['title' => 'Postmortem Center', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'OPS Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Create/list postmortem and improvement'],
    'ci/kpi_improvement.php' => ['title' => 'KPI Improvement Loop', 'cat' => 'Ops & Governance', 'safety' => 'MUTATING', 'web' => true, 'owner' => 'OPS Team', 'tags' => ['WEB', 'MUTATING'], 'desc' => 'Incident trend and action backlog'],
];

$cliAlternativeMap = [
    'smoke_http.php' => 'qa/smoke_http_web.php',
    'smoke_tools_dashboard.php' => 'qa/tools_dashboard_smoke_web.php',
    'tools_dashboard_smoke.php' => 'qa/tools_dashboard_smoke_web.php',
    'chat_smoke.php' => 'qa/chat_schema_smoke_web.php',
    'chat_api_smoke.php' => 'qa/chat_api_smoke_web.php',
    'sales_tracking_smoke.php' => 'qa/sales_tracking_smoke_web.php',
    'generate_evidence_pack.php' => 'qa/evidence_pack_web.php',
    'generate_signoff_verdict.php' => 'qa/signoff_verdict_web.php',
    'hypercare_summary.php' => 'qa/hypercare_summary_web.php',
    'hypercare_checkpoint.php' => 'qa/hypercare_checkpoint_web.php',
];

$cliRiskReasonMap = [
    'restore.php' => 'Tetap CLI: operasi restore bersifat destruktif dan perlu kontrol shell langsung.',
    'log_test.php' => 'Tetap CLI: debug internal non-operasional harian.',
    'enterprise_audit.php' => 'Tetap CLI: audit deep scan berbiaya tinggi dan biasanya batch/offline.',
    'migrate_cdn_to_local.php' => 'Tetap CLI: migration one-time berisiko.',
    'migrate_cdn_round2.php' => 'Tetap CLI: migration one-time berisiko.',
    'purchases_m2_apply.php' => 'Tetap CLI: patch data one-time berisiko.',
    'rbac_seed_act_purchases.php' => 'Tetap CLI: seed RBAC low-frequency dan sensitif.',
    'rbac_seed_fin_purchases.php' => 'Tetap CLI: seed RBAC low-frequency dan sensitif.',
    'rbac_seed_scm_purchases.php' => 'Tetap CLI: seed RBAC low-frequency dan sensitif.',
    'enforce_h_guard.php' => 'Tetap CLI: hardening mutasi kode, perlu review ketat.',
    'enforce_login_guards.php' => 'Tetap CLI: hardening mutasi kode, perlu review ketat.',
    'enforce_upload_safety.php' => 'Tetap CLI: hardening mutasi kode, perlu review ketat.',
];

$diagMap = [
    'db_test.php' => 'diag_db_test',
    'diag_boot.php' => 'diag_boot',
    'diag_db.php' => 'diag_db',
];

$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'run_diag') {
        $toolId = (string)($_POST['tool_id'] ?? '');
        if (in_array($toolId, ['diag_db_test', 'diag_boot', 'diag_db'], true)) {
            $diagFile = (string)array_search($toolId, $diagMap, true);
            if ($diagFile !== '' && tools_can_access_web_tool($diagFile)) {
                $result = ts_run_diag_check($toolId);
                $message = 'Run check ' . h($toolId) . ': ' . (($result['ok'] ?? false) ? 'OK' : 'FAIL');
                ts_append_run_history('diag_run', ($result['ok'] ?? false) ? 'ok' : 'fail', [
                    'actor_username' => tools_current_actor_username(),
                    'tool_id' => $toolId,
                    'source' => 'tools/index.php',
                ]);
            } else {
                $message = 'Akses ditolak oleh RBAC matrix.';
            }
        }
    } elseif ($action === 'generate_readiness') {
        if (tools_can_access_web_tool('readiness_audit.php')) {
            $rep = ts_generate_readiness_report();
            $message = 'Readiness report generated: ' . h(tools_mask_sensitive((string)($rep['json'] ?? '')));
            ts_append_run_history('readiness_generate', 'ok', [
                'actor_username' => tools_current_actor_username(),
                'source' => 'tools/index.php',
            ]);
        } else {
            $message = 'Akses ditolak oleh RBAC matrix.';
        }
    } elseif ($action === 'run_all_checks_web') {
        if (tools_can_access_web_tool('__run_all_checks_web')) {
            $mode = strtolower(trim((string)($_POST['mode'] ?? 'quick')));
            $quick = ($mode !== 'full');
            $res = ti_start_all_checks_async($quick);
            $message = (string)($res['message'] ?? 'All checks diproses.');
        } else {
            $message = 'Akses ditolak oleh RBAC matrix.';
        }
    } elseif ($action === 'run_ops_hardening_web') {
        if (tools_can_access_web_tool('__ops_hardening_audit_web')) {
            $scope = strtolower(trim((string)($_POST['scope'] ?? 'ops')));
            $mode = strtolower(trim((string)($_POST['mode'] ?? 'normal')));
            $res = ti_start_ops_hardening_async($scope, $mode);
            if (!empty($res['ok'])) {
                $message = (string)($res['message'] ?? 'Ops hardening dijalankan.');
            } else {
                $message = (string)($res['message'] ?? 'Ops hardening gagal.');
            }
        } else {
            $message = 'Akses ditolak oleh RBAC matrix.';
        }
    } elseif ($action === 'sync_system_recovery') {
        if (tools_can_access_web_tool('doctor/index.php')) {
            $chain = [
                APP_ROOT . '/tools/doctor/run_doctor.php',
                APP_ROOT . '/tools/release/release_final_checklist_build.php',
                APP_ROOT . '/tools/ops/weekly_trend_build.php',
                APP_ROOT . '/tools/ops/sla_monitor_daily.php',
            ];
            $errors = 0;
            foreach ($chain as $script) {
                $cmd = escapeshellarg((string)(defined('PHP_BINARY') ? PHP_BINARY : 'php')) . ' ' . escapeshellarg($script);
                $run = tools_run_step($cmd, 240, ['idempotent' => true, 'max_retry' => 1]);
                if (empty($run['ok'])) {
                    $errors++;
                }
            }
            $message = $errors === 0
                ? 'Sync System / Auto-Recovery selesai.'
                : 'Sync System / Auto-Recovery selesai dengan warning.';
        } else {
            $message = 'Akses ditolak oleh RBAC matrix.';
        }
    } elseif ($action === 'toggle_pin') {
        $file = (string)($_POST['file'] ?? '');
        $pins = json_decode((string)($_COOKIE['tools_pins'] ?? '[]'), true);
        $pins = is_array($pins) ? $pins : [];
        if (in_array($file, $pins, true)) {
            $pins = array_values(array_filter($pins, static fn($v) => $v !== $file));
        } else {
            $pins[] = $file;
        }
        setcookie('tools_pins', json_encode(array_values(array_unique($pins))), time() + 86400 * 180, '/');
        $message = 'Pinned list updated';
    }
    rmi_redirect('index.php?tag=' . urlencode((string)($_GET['tag'] ?? 'ALL')) . '&q=' . urlencode((string)($_GET['q'] ?? '')) . '&msg=' . urlencode($message));
}

$message = (string)($_GET['msg'] ?? '');
$q = strtolower(trim((string)($_GET['q'] ?? '')));
$tag = strtoupper(trim((string)($_GET['tag'] ?? 'ALL')));
$cookiePins = json_decode((string)($_COOKIE['tools_pins'] ?? '[]'), true);
$cookiePins = is_array($cookiePins) ? $cookiePins : [];

$rows = [];
foreach ($known as $file => $meta) {
    if (!is_file(__DIR__ . '/' . $file) && !is_file(__DIR__ . '/' . str_replace('.php', '.sh', $file))) {
        // Keep registry but skip missing concrete file
    }
    $meta['file'] = $file;
    $meta['cli'] = 'php tools/' . $file;
    if ($forceMutatingMode) {
        $meta['safety'] = 'MUTATING';
        $tags = array_map('strtoupper', (array)($meta['tags'] ?? []));
        if (!in_array('MUTATING', $tags, true)) {
            $tags[] = 'MUTATING';
        }
        $meta['tags'] = array_values(array_unique($tags));
    }
    $meta['pinned'] = !empty($meta['pinned']) || in_array($file, $cookiePins, true);
    $rows[] = $meta;
}

$rows = array_values(array_filter($rows, static function (array $r) use ($q, $tag): bool {
    if ($q !== '') {
        $blob = strtolower($r['file'] . ' ' . $r['title'] . ' ' . $r['desc'] . ' ' . $r['cat']);
        if (!str_contains($blob, $q)) {
            return false;
        }
    }
    if ($tag === 'ALL') return true;
    if ($tag === 'READ') return false;
    if ($tag === 'MUTATING') return $r['safety'] === 'MUTATING';
    if ($tag === 'CLI_ONLY') return !$r['web'];
    if ($tag === 'WEB') return (bool)$r['web'];
    return true;
}));

usort($rows, static function (array $a, array $b): int {
    if ((bool)$a['pinned'] !== (bool)$b['pinned']) return ((bool)$a['pinned']) ? -1 : 1;
    return strcmp($a['cat'], $b['cat']) ?: strcmp($a['title'], $b['title']);
});

$backup = ts_latest_backup_meta();
$scheduler = ts_scheduler_state();
$runtimeCfg = ts_runtime_config_valid();
$readiness = ts_readiness_score();
$smokeState = tools_read_state_json(ts_storage_logs_dir() . '/smoke_http_last.json');
$smoke = is_array($smokeState['data'] ?? null) ? (array)$smokeState['data'] : [];
$smokeNorm = ts_smoke_summary($smoke);
$smokeTotal = (int)$smokeNorm['total'];
$smokePass = (bool)$smokeNorm['ok'];
$smokeUnknown = (bool)$smokeNorm['unknown'];
$smokePassCount = (int)$smokeNorm['pass'];
$smokeFailCount = (int)$smokeNorm['fail'];
$smokeDuration = (int)$smokeNorm['elapsed_ms'];
$smokeErrLabel = '';
if (!$smokeState['ok']) {
    $smokeErrLabel = match ((string)$smokeState['error']) {
        'missing' => 'Belum pernah dijalankan',
        'invalid_json' => 'State file invalid/corrupt',
        'schema_mismatch' => 'State schema tidak dikenali, butuh migrate',
        default => 'State smoke bermasalah',
    };
}

$readinessState = tools_read_state_json(ts_storage_logs_dir() . '/readiness_report_last.json', ['score']);
$readinessJson = is_array($readinessState['data'] ?? null) ? (array)$readinessState['data'] : [];
$readinessScoreJson = (int)($readinessJson['score'] ?? 0);
$contractState = tools_contract_state_read();
$contractOk = (bool)(($contractState['data']['ok'] ?? false) === true);
$readyForCutover = $readinessState['ok'] && $smokeState['ok'] && $contractState['ok'] && $readinessScoreJson === 100 && $smokeFailCount === 0 && $contractOk;
$cutoverReasons = [];
if (!$readinessState['ok']) {
    $cutoverReasons[] = match ((string)$readinessState['error']) {
        'missing' => 'Readiness belum tersedia',
        'invalid_json' => 'Readiness invalid/corrupt',
        'schema_mismatch' => 'Readiness schema tidak dikenali',
        default => 'Readiness bermasalah',
    };
} elseif ($readinessScoreJson !== 100) {
    $cutoverReasons[] = 'Readiness score belum 100';
}
if (!$smokeState['ok']) {
    $cutoverReasons[] = $smokeErrLabel !== '' ? $smokeErrLabel : 'Smoke state bermasalah';
} elseif ($smokeFailCount !== 0) {
    $cutoverReasons[] = 'Smoke fail tidak nol';
}
if (!$contractState['ok']) {
    $cutoverReasons[] = match ((string)$contractState['error']) {
        'missing' => 'Contract check belum tersedia',
        'invalid_json' => 'Contract check invalid/corrupt',
        'schema_mismatch' => 'Contract schema tidak dikenali',
        default => 'Contract check bermasalah',
    };
} elseif (!$contractOk) {
    $cutoverReasons[] = 'Contract check belum pass';
}
$allChecksState = tools_read_state_json(ts_storage_logs_dir() . '/all_checks.last.json', ['overall_ok', 'summary', 'steps']);
$allChecksData = is_array($allChecksState['data'] ?? null) ? (array)$allChecksState['data'] : [];
$allChecksOk = (bool)($allChecksData['overall_ok'] ?? false);
$allChecksSummary = is_array($allChecksData['summary'] ?? null) ? (array)$allChecksData['summary'] : [];
$allChecksSteps = is_array($allChecksData['steps'] ?? null) ? (array)$allChecksData['steps'] : [];
$allChecksRuntime = ti_all_checks_runtime();
$hypercareSummaryState = tools_read_state_json(ts_storage_logs_dir() . '/hypercare_summary_last.json', ['hypercare_complete_24h']);
$hypercareSummary = is_array($hypercareSummaryState['data'] ?? null) ? (array)$hypercareSummaryState['data'] : [];
$businessSignoffState = business_signoff_read_state();
$businessSignoff = is_array($businessSignoffState['data'] ?? null) ? (array)$businessSignoffState['data'] : [];
$signoffVerdictState = tools_read_state_json(ts_storage_logs_dir() . '/signoff_verdict_last.json', ['verdict']);
$signoffVerdict = is_array($signoffVerdictState['data'] ?? null) ? (array)$signoffVerdictState['data'] : [];
$readyForFinalSignoff = strtoupper((string)($signoffVerdict['verdict'] ?? 'NO-GO')) === 'GO';

$historyRes = tools_tail_jsonl(ts_storage_logs_dir() . '/tools_run_history.jsonl', 200);
$historyItems = [];
if ($historyRes['exists']) {
    foreach (array_reverse((array)$historyRes['items']) as $evt) {
        $event = strtolower((string)($evt['event'] ?? ''));
        $action = strtolower((string)($evt['action'] ?? ''));
        $toolId = strtolower((string)($evt['tool_id'] ?? ''));
        $haystack = trim($event . ' ' . $action . ' ' . $toolId);
        if (!preg_match('/backup|smoke|readiness|scheduler/', $haystack)) continue;
        $historyItems[] = $evt;
        if (count($historyItems) >= 10) break;
    }
}

$backupColor = 'warning';
$backupLabel = 'ATTENTION';
$lastStatusNorm = strtoupper(trim((string)($scheduler['last_status'] ?? 'UNKNOWN')));
$lastFailed = in_array($lastStatusNorm, ['FAIL', 'FAILED', 'ERROR'], true);
if (($backup['age_hours'] ?? 999) < 24 && ($backup['checksum_ok'] ?? false) && ($scheduler['enabled'] ?? false) && ($runtimeCfg['ok'] ?? false) && !$lastFailed) {
    $backupColor = 'success';
    $backupLabel = 'HEALTHY';
} elseif (($backup['age_hours'] ?? 999) > 72 || !($runtimeCfg['ok'] ?? false) || $lastFailed) {
    $backupColor = 'danger';
    $backupLabel = 'CRITICAL';
}

$diagStates = [
    'diag_db_test' => tools_read_state_json(ts_storage_logs_dir() . '/diag_db_test.last.json'),
    'diag_boot' => tools_read_state_json(ts_storage_logs_dir() . '/diag_boot.last.json'),
    'diag_db' => tools_read_state_json(ts_storage_logs_dir() . '/diag_db.last.json'),
];

$migrationStates = [
    'migrate_cdn_to_local' => tools_read_state_json(ts_storage_logs_dir() . '/migration_migrate_cdn_to_local.state.json'),
    'migrate_cdn_round2' => tools_read_state_json(ts_storage_logs_dir() . '/migration_migrate_cdn_round2.state.json'),
    'purchases_m2_apply' => tools_read_state_json(ts_storage_logs_dir() . '/migration_purchases_m2_apply.state.json'),
];
$seedStates = [
    'rbac_seed_act_purchases' => tools_read_state_json(ts_storage_logs_dir() . '/rbac_seed_act_purchases.state.json'),
    'rbac_seed_fin_purchases' => tools_read_state_json(ts_storage_logs_dir() . '/rbac_seed_fin_purchases.state.json'),
    'rbac_seed_scm_purchases' => tools_read_state_json(ts_storage_logs_dir() . '/rbac_seed_scm_purchases.state.json'),
];
$hardeningLast = tools_read_state_json(ts_storage_logs_dir() . '/security_hardening_last.json');
[$scoreCls, $scoreLabel] = ti_score_badge((int)$readiness['score']);

$categories = [];
foreach ($rows as $r) {
    $categories[$r['cat']][] = $r;
}
$alert = tools_alert_evaluate();
tools_alert_maybe_email($alert);
$opsBannerState = tools_read_state_json(ts_root() . '/storage/state/ops_banner.json', ['level', 'headline']);
$opsBanner = is_array($opsBannerState['data'] ?? null) ? (array)$opsBannerState['data'] : [];
$opsBannerLevel = strtoupper((string)($opsBanner['level'] ?? 'HEALTHY'));
$opsBannerHeadline = (string)($opsBanner['headline'] ?? 'All checks healthy');
$opsBannerPrimaryOwner = (string)($opsBanner['primary_owner'] ?? 'TBD');
$opsBannerSlaDueAt = (string)($opsBanner['sla_due_at'] ?? '');
$opsBannerWorkflowStatus = (string)($opsBanner['workflow_status'] ?? 'OPEN');
$opsBannerBreached = (bool)($opsBanner['breached'] ?? false);
$opsAlertsState = tools_read_state_json(ts_storage_logs_dir() . '/ops_alerts_last.json', ['active_counts']);
$opsAlerts = is_array($opsAlertsState['data'] ?? null) ? (array)$opsAlertsState['data'] : [];
$opsActiveCounts = is_array($opsAlerts['active_counts'] ?? null) ? (array)$opsAlerts['active_counts'] : [];
$opsCritical = (int)($opsActiveCounts['CRITICAL'] ?? 0);
$opsHigh = (int)($opsActiveCounts['HIGH'] ?? 0);
$opsMedium = (int)($opsActiveCounts['MEDIUM'] ?? 0);
$opsLow = (int)($opsActiveCounts['LOW'] ?? 0);
$opsHardeningBaseline = tools_read_state_json(ts_storage_logs_dir() . '/ops_hardening_full_baseline.json', ['summary']);
$opsHardeningData = is_array($opsHardeningBaseline['data'] ?? null) ? (array)$opsHardeningBaseline['data'] : [];
$opsHardeningSummary = is_array($opsHardeningData['summary'] ?? null) ? (array)$opsHardeningData['summary'] : [];
$opsHardeningRuntime = ti_ops_hardening_runtime();
$releaseVerifyState = tools_read_state_json(ts_root() . '/storage/state/release_verify_all_last.json', ['summary']);
$releaseVerifyData = is_array($releaseVerifyState['data'] ?? null) ? (array)$releaseVerifyState['data'] : [];
$releaseVerifySummary = is_array($releaseVerifyData['summary'] ?? null) ? (array)$releaseVerifyData['summary'] : [];
$releaseVerifyBadge = 'ATTENTION';
if ($releaseVerifyState['ok']) {
    $failCount = (int)($releaseVerifySummary['bundle_fail'] ?? 0);
    $warnCount = (int)($releaseVerifySummary['bundle_warn'] ?? 0);
    if ($failCount > 0) $releaseVerifyBadge = 'FAIL';
    elseif ($warnCount > 0) $releaseVerifyBadge = 'WARN';
    else $releaseVerifyBadge = 'OK';
}
$opsReportOpsPath = __DIR__ . '/../docs/governance/OPS_HARDENING_REPORT.md';
$planExecState = pesv_load_panel_state();
$opsReportFullPath = __DIR__ . '/../docs/governance/OPS_HARDENING_REPORT_FULL.md';
$opsReportOpsExists = is_file($opsReportOpsPath);
$opsReportFullExists = is_file($opsReportFullPath);
$mobileQualityState = tools_read_state_json(ts_storage_logs_dir() . '/mobile_quality_gate_web.last.json', ['overall_ok', 'summary', 'run_at']);
$mobileQualityData = is_array($mobileQualityState['data'] ?? null) ? (array)$mobileQualityState['data'] : [];
$mobileQualitySummary = is_array($mobileQualityData['summary'] ?? null) ? (array)$mobileQualityData['summary'] : [];
$mobileQualityOk = (bool)($mobileQualityData['overall_ok'] ?? false);
$smokeHttpWebState = tools_read_state_json(ts_storage_logs_dir() . '/smoke_http_web.last.json', ['overall_ok', 'summary', 'run_at']);
$smokeHttpWebData = is_array($smokeHttpWebState['data'] ?? null) ? (array)$smokeHttpWebState['data'] : [];
$smokeHttpWebSummary = is_array($smokeHttpWebData['summary'] ?? null) ? (array)$smokeHttpWebData['summary'] : [];
if (empty($smokeHttpWebSummary)) {
    $smokeCli = ts_read_json(ts_storage_logs_dir() . '/smoke_http_last.json');
    if (is_array($smokeCli) && isset($smokeCli['total'], $smokeCli['pass'], $smokeCli['fail'])) {
        $smokeHttpWebSummary = ['total' => (int)$smokeCli['total'], 'pass' => (int)$smokeCli['pass'], 'fail' => (int)$smokeCli['fail']];
        $smokeHttpWebData['run_at'] = $smokeHttpWebData['run_at'] ?? $smokeCli['generated_at'] ?? '';
    }
}
$smokeHttpWebOk = (bool)($smokeHttpWebData['overall_ok'] ?? ((int)($smokeHttpWebSummary['fail'] ?? 1) === 0));
$allChecksWebState = tools_read_state_json(ts_storage_logs_dir() . '/all_checks_web.last.json', ['overall_ok', 'all_checks', 'run_at']);
$allChecksWebData = is_array($allChecksWebState['data'] ?? null) ? (array)$allChecksWebState['data'] : [];
$allChecksWebCore = is_array($allChecksWebData['all_checks'] ?? null) ? (array)$allChecksWebData['all_checks'] : [];
$allChecksWebSummary = is_array($allChecksWebCore['summary'] ?? null) ? (array)$allChecksWebCore['summary'] : [];
$allChecksWebOk = (bool)($allChecksWebData['overall_ok'] ?? false);
$cutoverWebState = tools_read_state_json(ts_storage_logs_dir() . '/cutover_checks_web.last.json', ['overall_ok', 'cutover', 'run_at']);
$cutoverWebData = is_array($cutoverWebState['data'] ?? null) ? (array)$cutoverWebState['data'] : [];
$cutoverWebCore = is_array($cutoverWebData['cutover'] ?? null) ? (array)$cutoverWebData['cutover'] : [];
if (empty($cutoverWebCore)) {
    $cutoverWebCore = ts_read_json(ts_storage_logs_dir() . '/cutover_checks.last.json');
    $cutoverWebCore = is_array($cutoverWebCore) ? $cutoverWebCore : [];
}
$cutoverWebSummary = is_array($cutoverWebCore['summary'] ?? null) ? (array)$cutoverWebCore['summary'] : [];
$cutoverWebOk = (bool)($cutoverWebData['overall_ok'] ?? $cutoverWebCore['overall_ok'] ?? false);
$negativeWebState = tools_read_state_json(ts_storage_logs_dir() . '/negative_tests_web.last.json', ['overall_ok', 'negative_tests', 'run_at']);
$negativeWebData = is_array($negativeWebState['data'] ?? null) ? (array)$negativeWebState['data'] : [];
$negativeWebCore = is_array($negativeWebData['negative_tests'] ?? null) ? (array)$negativeWebData['negative_tests'] : [];
$negativeWebSummary = is_array($negativeWebCore['summary'] ?? null) ? (array)$negativeWebCore['summary'] : [];
$negativeWebOk = (bool)($negativeWebData['overall_ok'] ?? false);
$contractWebState = tools_read_state_json(ts_storage_logs_dir() . '/contract_check_web.last.json', ['overall_ok', 'contract', 'run_at']);
$contractWebData = is_array($contractWebState['data'] ?? null) ? (array)$contractWebState['data'] : [];
$contractWebCore = is_array($contractWebData['contract'] ?? null) ? (array)$contractWebData['contract'] : [];
if (empty($contractWebCore)) {
    $contractWebCore = ts_read_json(ts_storage_logs_dir() . '/contract_check_last.json');
    $contractWebCore = is_array($contractWebCore) ? $contractWebCore : [];
}
$contractWebOk = (bool)($contractWebData['overall_ok'] ?? $contractWebCore['ok'] ?? false);
$unicodeGuardData = ts_read_json(ts_storage_logs_dir() . '/unicode_guard_last.json');
$unicodeGuardData = is_array($unicodeGuardData) ? $unicodeGuardData : [];
$unicodeGuardOk = (bool)($unicodeGuardData['ok'] ?? false);
$unicodeGuardCounts = is_array($unicodeGuardData['counts'] ?? null) ? (array)$unicodeGuardData['counts'] : [];
$salesTrackingWebState = tools_read_state_json(ts_storage_logs_dir() . '/sales_tracking_checks_web.last.json', ['overall_ok', 'tracking', 'run_at']);
$salesTrackingWebData = is_array($salesTrackingWebState['data'] ?? null) ? (array)$salesTrackingWebState['data'] : [];
$salesTrackingWebCore = is_array($salesTrackingWebData['tracking'] ?? null) ? (array)$salesTrackingWebData['tracking'] : [];
if (empty($salesTrackingWebCore)) {
    $salesTrackingWebCore = ts_read_json(ts_storage_logs_dir() . '/sales_tracking_checks.last.json');
    $salesTrackingWebCore = is_array($salesTrackingWebCore) ? $salesTrackingWebCore : [];
}
$salesTrackingWebSummary = is_array($salesTrackingWebCore['summary'] ?? null) ? (array)$salesTrackingWebCore['summary'] : [];
$salesTrackingWebOk = (bool)($salesTrackingWebData['overall_ok'] ?? $salesTrackingWebCore['overall_ok'] ?? false);
$deploySizeState = tools_read_state_json(ts_storage_logs_dir() . '/deploy_size_audit_last.json', ['overall_ok', 'summary', 'run_at']);
$deploySizeData = is_array($deploySizeState['data'] ?? null) ? (array)$deploySizeState['data'] : [];
$deploySizeSummary = is_array($deploySizeData['summary'] ?? null) ? (array)$deploySizeData['summary'] : [];
$deploySizeOk = (bool)($deploySizeData['overall_ok'] ?? false);
$matrix = tools_access_matrix();
$totalTools = count($rows);
$webTools = count(array_filter($rows, static fn(array $r): bool => (bool)($r['web'] ?? false)));
$cliOnlyTools = count(array_filter($rows, static fn(array $r): bool => !(bool)($r['web'] ?? false)));
$pinnedTools = count(array_filter($rows, static fn(array $r): bool => !empty($r['pinned'])));
$opsBackupReady = ($backupLabel === 'HEALTHY');
$opsReadinessReady = ($readinessState['ok'] && $readinessScoreJson === 100 && $smokeFailCount === 0);
$opsAllChecksReady = ($allChecksState['ok'] && $allChecksOk);
$opsSignoffReady = $readyForFinalSignoff;
$opsReadyCount = (int)$opsBackupReady + (int)$opsReadinessReady + (int)$opsAllChecksReady + (int)$opsSignoffReady;
$opsHour = (int)date('G');
$opsPhase = 'Morning Focus';
$opsHint = 'Prioritas: backup pulse dan readiness gate.';
if ($opsHour >= 12 && $opsHour < 17) {
    $opsPhase = 'Afternoon Focus';
    $opsHint = 'Prioritas: jalankan full checks dan review hasil.';
} elseif ($opsHour >= 17 || $opsHour < 5) {
    $opsPhase = 'Evening Focus';
    $opsHint = 'Prioritas: final sign-off dan verifikasi evidence.';
}

// DR backup readiness (quick signal for dashboard)
// Primary: storage/backups (backup utama). Fallback: BACKUP_PATH env atau exports/evidence/backups.
$_drPrimaryDir  = __DIR__ . '/../storage/backups';
$_drEvidenceDir = (string)(getenv('BACKUP_PATH') ?: (__DIR__ . '/../exports/evidence/backups'));
@mkdir($_drEvidenceDir, 0775, true);

// Direktori "writable" → cek storage/backups (primary) atau evidence dir
$drBackupDir      = is_dir($_drPrimaryDir) && is_writable($_drPrimaryDir) ? $_drPrimaryDir : $_drEvidenceDir;
$drBackupWritable = is_dir($drBackupDir) && is_writable($drBackupDir);

// Cari latest backup: dari LATEST_BACKUP.txt, lalu dari glob package dirs, lalu dari meta.json
$drLatestMeta = [];
$drBackupAgeHours = null;

// 1) Coba baca dari LATEST_BACKUP.txt (backup utama)
$_drLatestTxt = $_drPrimaryDir . '/LATEST_BACKUP.txt';
if (is_file($_drLatestTxt)) {
    $_drPkg = trim((string)@file_get_contents($_drLatestTxt));
    if ($_drPkg !== '' && is_dir($_drPkg)) {
        $_drMtime = @filemtime($_drPkg) ?: 0;
        if ($_drMtime > 0) {
            $drBackupAgeHours = round((time() - $_drMtime) / 3600, 1);
            $drLatestMeta = ['created_at' => date('c', $_drMtime), 'package' => basename($_drPkg)];
        }
    }
}

// 2) Fallback: glob package dirs terbaru jika LATEST_BACKUP.txt tidak ada
if ($drBackupAgeHours === null) {
    $_drPackages = glob($_drPrimaryDir . '/ERP_RMI_SOFULL_backup_*', GLOB_ONLYDIR) ?: [];
    if ($_drPackages) {
        rsort($_drPackages, SORT_STRING);
        $_drMtime = @filemtime($_drPackages[0]) ?: 0;
        if ($_drMtime > 0) {
            $drBackupAgeHours = round((time() - $_drMtime) / 3600, 1);
            $drLatestMeta = ['created_at' => date('c', $_drMtime), 'package' => basename($_drPackages[0])];
        }
    }
}

// 3) Fallback terakhir: *.meta.json di evidence dir
if ($drBackupAgeHours === null) {
    $drLatestMetaFiles = glob(rtrim($_drEvidenceDir, '/') . '/*.meta.json') ?: [];
    rsort($drLatestMetaFiles);
    if ($drLatestMetaFiles) {
        $drLatestMetaArr = json_decode((string)@file_get_contents((string)$drLatestMetaFiles[0]), true);
        if (is_array($drLatestMetaArr)) {
            $drLatestMeta = $drLatestMetaArr;
            if (!empty($drLatestMeta['created_at'])) {
                $ts = strtotime((string)$drLatestMeta['created_at']);
                if ($ts !== false) $drBackupAgeHours = round((time() - $ts) / 3600, 1);
            }
        }
    }
}

$drDbReady = false;
try {
    $pdoProbe = rmi_db_pdo();
    $drDbReady = (bool)$pdoProbe->query('SELECT 1')->fetchColumn();
} catch (Throwable $e) {
    $drDbReady = false;
}
$drRecentBackupOk = ($drBackupAgeHours !== null && $drBackupAgeHours <= 24);
$drBackupReady = $drDbReady && $drBackupWritable && $drRecentBackupOk;
$drBackupBadge = $drBackupReady ? 'HEALTHY' : 'ATTENTION';
$drBackupHint = $drBackupReady
    ? 'DR backup ready (DB/dir/recent backup OK).'
    : 'Periksa DR backup: koneksi DB, writable dir, atau backup terakhir >24 jam.';

require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/_lib/tools_http.php';
$baseProject = rmi_layout_base_project();
$baseUrlPublic = (function_exists('tools_get_base_url_public') && tools_get_base_url_public() !== null) ? rtrim(tools_get_base_url_public(), '/') : $baseProject;
$csrfTok = (string)(function_exists('csrf_token') ? csrf_token() : '');
rmi_header('Tools & Maintenance', [
    'active' => 'tools',
    'subtitle' => 'Operational control center untuk health, readiness, backup, dan governance tools.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Dashboard'],
]);
?>
<style>
  .ops-row-active {
    outline: 1px solid rgba(255, 214, 10, 0.6);
    box-shadow: inset 0 0 0 9999px rgba(255, 214, 10, 0.07);
  }
</style>

<div class="row g-3">
  <?php if ($message !== ''): ?><div class="col-12"><div class="alert alert-info py-2 mb-0"><?= h($message) ?></div></div><?php endif; ?>
  <?php if ($opsBannerState['ok']): ?>
    <div class="col-12">
      <div class="alert <?= $opsBannerLevel === 'CRITICAL' ? 'alert-danger' : ($opsBannerLevel === 'ATTENTION' ? 'alert-warning' : 'alert-success') ?> py-2 mb-0">
        <?= tools_badge($opsBannerLevel, $opsBannerLevel) ?>
        <span class="ms-1"><?= h($opsBannerHeadline) ?></span>
        <span class="ms-2 small">Owner: <b><?= h($opsBannerPrimaryOwner) ?></b></span>
        <span class="ms-2 small">Due: <b><?= h(tools_fmt_ts($opsBannerSlaDueAt)) ?></b></span>
        <span class="ms-2 small">Status: <b><?= h($opsBannerWorkflowStatus) ?></b></span>
        <?php if ($opsBannerBreached): ?><span class="ms-2"><?= tools_badge('CRITICAL', 'SLA BREACH') ?></span><?php endif; ?>
        <a class="ms-2 small" href="ops/alerts.php">Open Alerts Workflow</a>
      </div>
    </div>
  <?php endif; ?>
  <?php if (strtoupper((string)($alert['level'] ?? 'HEALTHY')) !== 'HEALTHY'): ?>
    <div class="col-12">
      <div class="alert <?= strtoupper((string)$alert['level']) === 'CRITICAL' ? 'alert-danger' : 'alert-warning' ?> py-2 mb-0">
        <?= tools_badge((string)$alert['level']) ?>
        <?php foreach ((array)($alert['reasons'] ?? []) as $reason): ?>
          <span class="ms-1"><?= h(tools_mask_sensitive((string)$reason)) ?></span>
        <?php endforeach; ?>
        <span class="ms-2 small">Updated: <?= h(tools_fmt_ts((string)($alert['checked_at'] ?? ''))) ?></span>
        <a class="ms-2 small" href="readiness_audit.php">Open readiness audit</a>
        <a class="ms-2 small" href="ops/readiness_report_view.php?format=json" target="_blank" rel="noopener">JSON</a>
        <a class="ms-1 small" href="ops/readiness_report_view.php?format=md" target="_blank" rel="noopener">MD</a>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($opsAlertsState['ok'] && ($opsCritical > 0 || $opsHigh > 0 || $opsMedium > 0)): ?>
    <div class="col-12">
      <div class="alert <?= $opsCritical > 0 ? 'alert-danger' : 'alert-warning' ?> py-2 mb-0">
        <?= tools_badge($opsCritical > 0 ? 'CRITICAL' : 'ATTENTION') ?>
        Ops alerts active: C:<?= (int)$opsCritical ?> H:<?= (int)$opsHigh ?> M:<?= (int)$opsMedium ?> L:<?= (int)$opsLow ?>
        <a class="ms-2 small" href="ops/executive_ops_summary.php">Executive Ops Summary</a>
        <a class="ms-2 small" href="ops/findings.php">Open Findings</a>
      </div>
    </div>
  <?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <input class="form-control form-control-sm" style="max-width:260px" type="text" name="q" value="<?= h($q) ?>" placeholder="Cari tool, owner, atau aksi...">
        <?php foreach (['ALL','MUTATING','CLI_ONLY','WEB'] as $chip): ?>
          <button class="btn btn-sm <?= $tag === $chip ? 'btn-rmi' : 'btn-outline-light' ?>" type="submit" name="tag" value="<?= h($chip) ?>"><?= h($chip === 'CLI_ONLY' ? 'CLI ONLY' : $chip) ?></button>
        <?php endforeach; ?>
      </form>
      <div class="rmi-muted small mb-2">Owner sudah distandardisasi per tim. Prioritas list: pinned, lalu kategori, lalu nama tool.</div>
      <div class="d-flex flex-wrap gap-2 small">
        <span class="badge rmi-badge success" title="APP_ROOT lock">APP_ROOT LOCK: OK</span>
        <span class="badge rmi-badge"><?= (int)$totalTools ?> total tools</span>
        <span class="badge rmi-badge success"><?= (int)$webTools ?> web-ready</span>
        <span class="badge rmi-badge warning"><?= (int)$cliOnlyTools ?> CLI-required</span>
        <span class="badge rmi-badge"><?= (int)$pinnedTools ?> pinned</span>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Release Verify</div>
      <div class="small mb-1">
        <?= tools_badge($releaseVerifyBadge, $releaseVerifyBadge) ?>
        <?php if ($releaseVerifyState['ok']): ?>
          <span class="rmi-muted">bundles ok/warn/fail: <?= (int)($releaseVerifySummary['bundle_ok'] ?? 0) ?>/<?= (int)($releaseVerifySummary['bundle_warn'] ?? 0) ?>/<?= (int)($releaseVerifySummary['bundle_fail'] ?? 0) ?></span>
        <?php else: ?>
          <span class="rmi-muted">state belum tersedia / invalid</span>
        <?php endif; ?>
      </div>
      <div class="small">Last run: <b><?= h(tools_fmt_ts((string)($releaseVerifyData['generated_at'] ?? ''))) ?></b></div>
      <div class="small mt-2"><a href="release/release_artifacts.php">Open Release Artifacts</a></div>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Plan Executive Summary</div>
      <div class="small mb-1">
        <?php if (!$planExecState['ok']): ?>
          <?= tools_badge('ATTENTION') ?> DATA_MISSING
          <span class="rmi-muted">Run pipeline to generate.</span>
        <?php else:
          $pesData = (array)($planExecState['data'] ?? []);
          $goNoGo = (string)($pesData['decision']['go_no_go'] ?? 'UNKNOWN');
        ?>
          <?= tools_badge($goNoGo, $goNoGo) ?>
          <span class="rmi-muted"><?= h((string)($pesData['plan_id'] ?? '-')) ?> · <?= h((string)($pesData['master_run_id'] ?? '-')) ?></span>
        <?php endif; ?>
      </div>
      <div class="small mt-2"><a href="<?= h($baseProject . '/tools/ops/plan_exec_summary_view.php?mode=html&file=last') ?>">Open Summary</a> · <a href="<?= h($baseProject . '/tools/ops/control_center.php') ?>">Control Center</a> · <a href="<?= h($baseProject . '/tools/diagrams_index.php') ?>">Diagram Suite</a></div>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Sync System / Auto-Recovery</div>
      <div class="small rmi-muted mb-2">Menjalankan doctor safe + rebuild checklist + trend + SLA tanpa modifikasi transaksi.</div>
      <form method="post" class="d-flex gap-2 flex-wrap">
        <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
        <input type="hidden" name="action" value="sync_system_recovery">
        <button type="submit" class="btn btn-rmi btn-sm">Sync System / Auto-Recovery</button>
        <a class="btn btn-outline-light btn-sm" href="doctor/index.php">Open Doctor</a>
      </form>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3" id="pinned-links-tools-normal">
      <div class="fw-semibold mb-2">Pinned Links (TOOLS NORMAL) — Public URL</div>
      <div class="d-flex flex-wrap gap-2 small">
        <a class="btn btn-sm btn-outline-light" href="<?= h($baseUrlPublic . '/tools/ops/executive_summary_view.php?mode=html&file=ultimate') ?>">Executive Ops Summary ULTIMATE</a>
        <a class="btn btn-sm btn-outline-light" href="<?= h($baseUrlPublic . '/storage/logs/weekly_ops_report_latest.md') ?>" target="_blank" rel="noopener">Weekly Ops Report (latest)</a>
        <a class="btn btn-sm btn-outline-light" href="<?= h($baseUrlPublic . '/tools/ops/manual_action_queue.php') ?>">Manual Action Queue</a>
        <a class="btn btn-sm btn-outline-light" href="<?= h($baseUrlPublic . '/tools/ops/assumptions_view.php') ?>">Assumptions Log</a>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3" id="audit-center-mini">
      <div class="fw-semibold mb-2">Audit Center (Mini Panel)</div>
      <div class="small rmi-muted mb-2">Ringkasan cepat tool audit harian tanpa CLI. Semua tombol langsung buka halaman detail.</div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead>
            <tr><th>Tool</th><th>Status</th><th>Evidence Ringkas</th><th>Last Run</th><th>Action</th></tr>
          </thead>
          <tbody>
            <tr>
              <td><code>qa/mobile_quality_gate.php</code></td>
              <td><?= $mobileQualityState['ok'] ? ($mobileQualityOk ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL')) : tools_badge('ATTENTION', 'NOT RUN') ?></td>
              <td class="small">PASS <?= (int)($mobileQualitySummary['pass_lines'] ?? 0) ?> · WARN <?= (int)($mobileQualitySummary['warn_lines'] ?? 0) ?> · FAIL <?= (int)($mobileQualitySummary['fail_lines'] ?? 0) ?></td>
              <td class="small"><?= h(tools_fmt_ts((string)($mobileQualityData['run_at'] ?? ''))) ?></td>
              <td><a class="btn btn-sm btn-outline-light" href="qa/mobile_quality_gate.php">Open</a></td>
            </tr>
            <tr>
              <td><code>qa/smoke_http_web.php</code></td>
              <td><?= $smokeHttpWebState['ok'] ? ($smokeHttpWebOk ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL')) : tools_badge('ATTENTION', 'NOT RUN') ?></td>
              <td class="small">Total <?= (int)($smokeHttpWebSummary['total'] ?? 0) ?> · Pass <?= (int)($smokeHttpWebSummary['pass'] ?? 0) ?> · Fail <?= (int)($smokeHttpWebSummary['fail'] ?? 0) ?></td>
              <td class="small"><?= h(tools_fmt_ts((string)($smokeHttpWebData['run_at'] ?? ''))) ?></td>
              <td><a class="btn btn-sm btn-outline-light" href="qa/smoke_http_web.php">Open</a></td>
            </tr>
            <tr>
              <td><code>qa/all_checks_web.php</code></td>
              <td><?= $allChecksWebState['ok'] ? ($allChecksWebOk ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL')) : tools_badge('ATTENTION', 'NOT RUN') ?></td>
              <td class="small">Score <?= (int)($allChecksWebSummary['score'] ?? 0) ?>/100 · Fail <?= (int)($allChecksWebSummary['fail_count'] ?? 0) ?></td>
              <td class="small"><?= h(tools_fmt_ts((string)($allChecksWebData['run_at'] ?? ''))) ?></td>
              <td><a class="btn btn-sm btn-outline-light" href="qa/all_checks_web.php">Open</a></td>
            </tr>
            <tr>
              <td><code>qa/cutover_checks_web.php</code></td>
              <td><?= $cutoverWebState['ok'] ? ($cutoverWebOk ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL')) : tools_badge('ATTENTION', 'NOT RUN') ?></td>
              <td class="small">Score <?= (int)($cutoverWebSummary['score'] ?? 0) ?>/100 · Fail <?= (int)($cutoverWebSummary['fail_count'] ?? 0) ?></td>
              <td class="small"><?= h(tools_fmt_ts((string)($cutoverWebData['run_at'] ?? ''))) ?></td>
              <td><a class="btn btn-sm btn-outline-light" href="qa/cutover_checks_web.php">Open</a></td>
            </tr>
            <tr>
              <td><code>qa/negative_tests_web.php</code></td>
              <td><?= $negativeWebState['ok'] ? ($negativeWebOk ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL')) : tools_badge('ATTENTION', 'NOT RUN') ?></td>
              <td class="small">Score <?= (int)($negativeWebSummary['score'] ?? 0) ?>/100 · Fail <?= (int)($negativeWebSummary['fail_count'] ?? 0) ?></td>
              <td class="small"><?= h(tools_fmt_ts((string)($negativeWebData['run_at'] ?? ''))) ?></td>
              <td><a class="btn btn-sm btn-outline-light" href="qa/negative_tests_web.php">Open</a></td>
            </tr>
            <tr>
              <td><code>qa/tools_doctor_web.php</code></td>
              <td><?php
                $doctorState = tools_read_state_json(ts_storage_logs_dir() . '/tools_doctor_web.last.json', ['overall_ok']);
                $doctorCore = ts_read_json(ts_storage_logs_dir() . '/tools_doctor_last.json');
                $doctorOk = (bool)($doctorState['data']['overall_ok'] ?? $doctorCore['overall_ok'] ?? false);
                ?><?= ($doctorState['ok'] || !empty($doctorCore)) ? ($doctorOk ? tools_badge('HEALTHY', 'OK') : tools_badge('CRITICAL', 'FAIL')) : tools_badge('ATTENTION', 'NOT RUN') ?></td>
              <td class="small">Critical <?= (int)($doctorCore['critical_fail_count'] ?? 0) ?> · Findings <?= count((array)($doctorCore['findings'] ?? [])) ?></td>
              <td class="small"><?= h(tools_fmt_ts((string)($doctorCore['generated_at'] ?? $doctorState['data']['run_at'] ?? ''))) ?></td>
              <td><a class="btn btn-sm btn-outline-light" href="qa/tools_doctor_web.php">Open</a></td>
            </tr>
            <tr>
              <td><code>qa/contract_check_web.php</code></td>
              <td><?= $contractWebState['ok'] ? ($contractWebOk ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL')) : tools_badge('ATTENTION', 'NOT RUN') ?></td>
              <td class="small">Contract OK <?= !empty($contractWebCore['ok']) ? 'YES' : 'NO' ?> · Missing <?= (int)($contractWebCore['missing_count'] ?? 0) ?> · Mismatch <?= (int)($contractWebCore['mismatch_count'] ?? 0) ?></td>
              <td class="small"><?= h(tools_fmt_ts((string)($contractWebData['run_at'] ?? ''))) ?></td>
              <td><a class="btn btn-sm btn-outline-light" href="qa/contract_check_web.php">Open</a></td>
            </tr>
            <tr>
              <td><code>qa/unicode_guard_web.php</code></td>
              <td><?= !empty($unicodeGuardData) ? ($unicodeGuardOk ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL')) : tools_badge('ATTENTION', 'NOT RUN') ?></td>
              <td class="small">Path <?= (int)($unicodeGuardCounts['path_cyrillic'] ?? 0) ?> · Content path <?= (int)($unicodeGuardCounts['content_cyrillic_path_ctx'] ?? 0) ?> · General <?= (int)($unicodeGuardCounts['content_cyrillic_general'] ?? 0) ?></td>
              <td class="small"><?= h(tools_fmt_ts((string)($unicodeGuardData['generated_at'] ?? ''))) ?></td>
              <td><a class="btn btn-sm btn-outline-light" href="qa/unicode_guard_web.php">Open</a></td>
            </tr>
            <tr>
              <td><code>qa/sales_tracking_checks_web.php</code></td>
              <td><?= $salesTrackingWebState['ok'] ? ($salesTrackingWebOk ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL')) : tools_badge('ATTENTION', 'NOT RUN') ?></td>
              <td class="small">Fail <?= (int)($salesTrackingWebSummary['fail_count'] ?? 0) ?> · Warn <?= (int)($salesTrackingWebSummary['warn_count'] ?? 0) ?> · Pass <?= (int)($salesTrackingWebSummary['pass_count'] ?? 0) ?></td>
              <td class="small"><?= h(tools_fmt_ts((string)($salesTrackingWebData['run_at'] ?? ''))) ?></td>
              <td><a class="btn btn-sm btn-outline-light" href="qa/sales_tracking_checks_web.php">Open</a></td>
            </tr>
            <tr>
              <td><code>qa/http_response_analysis_web.php</code></td>
              <td><?= tools_badge('ATTENTION', 'MANUAL RUN') ?></td>
              <td class="small">Analisis HTTP code, TTFB, total, DNS, size, redirect secara detail.</td>
              <td class="small">-</td>
              <td><a class="btn btn-sm btn-outline-light" href="qa/http_response_analysis_web.php">Open</a></td>
            </tr>
            <tr>
              <td><code>release/deploy_size_audit.php</code></td>
              <td><?= $deploySizeState['ok'] ? ($deploySizeOk ? tools_badge('HEALTHY', 'READY') : tools_badge('ATTENTION', 'CHECK')) : tools_badge('ATTENTION', 'NOT RUN') ?></td>
              <td class="small">ZIP <?= h((string)($deploySizeSummary['zip_size_mb'] ?? 0)) ?> MB · Extracted <?= h((string)($deploySizeSummary['included_mb'] ?? 0)) ?> MB</td>
              <td class="small"><?= h(tools_fmt_ts((string)($deploySizeData['run_at'] ?? ''))) ?></td>
              <td><a class="btn btn-sm btn-outline-light" href="release/deploy_size_audit.php">Open</a></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Daily Ops Checklist (No CLI)</div>
      <div class="small rmi-muted mb-2">Gunakan urutan ini setiap hari agar operasional tetap konsisten. Progress: <b><?= (int)$opsReadyCount ?>/4</b> completed.</div>
      <div class="small mb-2">
        <?= tools_badge('ATTENTION', $opsPhase) ?>
        <span class="rmi-muted"><?= h($opsHint) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead>
            <tr><th>Step</th><th>Status</th><th>Evidence</th><th>Best Window</th><th>Action</th></tr>
          </thead>
          <tbody>
            <tr>
            <?php $isMorning = ($opsHour >= 8 && $opsHour < 12); ?>
            <tr class="<?= $isMorning ? 'ops-row-active' : '' ?>">
              <td>1. Backup pulse valid</td>
              <td><?= $opsBackupReady ? tools_badge('HEALTHY', 'READY') : tools_badge('ATTENTION', 'CHECK') ?></td>
              <td class="small">Latest backup age <?= h((string)($backup['age_hours'] ?? '-')) ?>h, checksum <?= !empty($backup['checksum_ok']) ? 'OK' : 'FAIL' ?></td>
              <td class="small">08:00 - 11:00</td>
              <td><a class="btn btn-sm btn-outline-light" href="#backup-pulse">Open</a></td>
            </tr>
            <?php $isMidday = ($opsHour >= 9 && $opsHour < 14); ?>
            <tr class="<?= $isMidday ? 'ops-row-active' : '' ?>">
              <td>2. Release readiness gate</td>
              <td><?= $opsReadinessReady ? tools_badge('HEALTHY', 'READY') : tools_badge('ATTENTION', 'CHECK') ?></td>
              <td class="small">Readiness score <?= (int)$readinessScoreJson ?>/100, smoke fail <?= (int)$smokeFailCount ?></td>
              <td class="small">09:00 - 13:00</td>
              <td><a class="btn btn-sm btn-outline-light" href="#release-readiness-gate">Open</a></td>
            </tr>
            <?php $isAfternoon = ($opsHour >= 13 && $opsHour < 17); ?>
            <tr class="<?= $isAfternoon ? 'ops-row-active' : '' ?>">
              <td>3. Full checks orchestrator</td>
              <td><?= $opsAllChecksReady ? tools_badge('HEALTHY', 'PASS') : tools_badge('ATTENTION', 'RUN') ?></td>
              <td class="small">Last run <?= h(tools_fmt_ts((string)($allChecksData['run_at'] ?? ''))) ?></td>
              <td class="small">13:00 - 17:00</td>
              <td><a class="btn btn-sm btn-outline-light" href="#all-checks-orchestrator">Open</a></td>
            </tr>
            <?php $isEvening = ($opsHour >= 17 || $opsHour < 5); ?>
            <tr class="<?= $isEvening ? 'ops-row-active' : '' ?>">
              <td>4. Business sign-off gate</td>
              <td><?= $opsSignoffReady ? tools_badge('HEALTHY', 'GO') : tools_badge('ATTENTION', 'PENDING') ?></td>
              <td class="small">Approver <?= h((string)($businessSignoff['approver_name'] ?? '-')) ?>, verdict <?= h(strtoupper((string)($signoffVerdict['verdict'] ?? 'NO-GO'))) ?></td>
              <td class="small">17:00 - end of day</td>
              <td><a class="btn btn-sm btn-outline-light" href="#business-signoff-gate">Open</a></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100" id="backup-pulse">
      <div class="fw-semibold mb-2">Backup & Restore Pulse</div>
      <div class="mb-2">Health badge: <?= tools_badge($backupLabel) ?></div>
      <div class="small">DR Backup readiness: <?= tools_badge($drBackupBadge, $drBackupReady ? 'READY' : 'NEEDS ATTENTION') ?></div>
      <div class="small rmi-muted mb-1"><?= h($drBackupHint) ?></div>
      <div class="small">Latest backup: <code><?= h((string)$backup['name']) ?></code> (<?= h((string)$backup['size_human']) ?>)</div>
      <div class="small">Age: <b><?= h((string)($backup['age_hours'] ?? '-')) ?> h</b> | Checksum: <?= tools_badge((bool)($backup['checksum_ok'] ?? false) ? 'OK' : 'FAIL') ?></div>
      <div class="small">Scheduler: <?= tools_badge((bool)($scheduler['enabled'] ?? false) ? 'ENABLED' : 'DISABLED') ?> | Next run: <b><?= h(tools_fmt_ts((string)($scheduler['next_run_at'] ?: ''))) ?></b></div>
      <div class="small">Runtime config: <?= tools_badge((bool)($runtimeCfg['ok'] ?? false) ? 'VALID' : 'INVALID') ?></div>
      <div class="small">DR components: DB <?= tools_badge($drDbReady ? 'OK' : 'FAIL') ?> | Backup dir <?= tools_badge($drBackupWritable ? 'OK' : 'FAIL') ?> | Recent backup <?= tools_badge($drRecentBackupOk ? 'OK' : 'STALE') ?></div>
      <div class="mt-2">
        <a class="btn btn-sm btn-outline-light" href="backup_manager.php">Open Backup Manager</a>
        <a class="btn btn-sm btn-outline-light" href="dr/backup_db.php">Open DR Backup</a>
        <a class="btn btn-sm btn-outline-light" href="restore_now.php">Open Restore Web</a>
      </div>
      <div class="mt-2 small">Dry-run command: <code>php tools/restore.php --from=[LATEST_PACKAGE] --dry-run</code></div>
      <?php if (!empty($backup['download_rel'])): ?><div class="small">Latest package path: <code><?= h(ts_mask((string)$backup['download_rel'])) ?></code></div><?php endif; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100" id="diagnostics-control">
      <div class="fw-semibold mb-2">Diagnostics Control</div>
      <?php foreach (['diag_db_test' => 'DB Test', 'diag_boot' => 'Diag Boot', 'diag_db' => 'Diag DB'] as $id => $label): $stWrap = $diagStates[$id]; $st = is_array($stWrap['data'] ?? null) ? $stWrap['data'] : []; $diagFile = array_search($id, $diagMap, true); $canRunDiag = $diagFile ? tools_can_access_web_tool((string)$diagFile) : false; ?>
        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
          <div>
            <div><?= h($label) ?></div>
            <div class="small rmi-muted"><?= h((string)($st['checked_at'] ?? 'never')) ?> | <?= h((string)($st['summary'] ?? ($stWrap['ok'] ? 'No state yet' : 'State invalid/corrupt'))) ?></div>
            <?php if (!empty($st['last_error_masked'])): ?><div class="small text-warning"><?= h((string)$st['last_error_masked']) ?></div><?php endif; ?>
          </div>
          <div class="d-flex gap-2">
            <span class="badge rmi-badge <?= (!empty($st) && ($st['ok'] ?? false)) ? 'success' : 'warning' ?>"><?= (!empty($st) && ($st['ok'] ?? false)) ? 'OK' : 'UNKNOWN' ?></span>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
              <input type="hidden" name="action" value="run_diag">
              <input type="hidden" name="tool_id" value="<?= h($id) ?>">
              <button type="submit" class="btn btn-sm btn-outline-light" <?= $canRunDiag ? '' : 'disabled' ?>>Run Diagnostic</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100" id="release-readiness-gate">
      <div class="fw-semibold mb-2">Release Readiness Gate</div>
      <div>Readiness score: <span class="badge rmi-badge <?= h($scoreCls) ?>"><?= (int)$readiness['score'] ?>/100 - <?= h($scoreLabel) ?></span></div>
      <div class="small mt-1">Health: <?= tools_badge((bool)$readiness['health_ok'] ? 'OK' : 'FAIL') ?> | Smoke: <?= tools_badge((bool)$readiness['smoke_ok'] ? 'OK' : 'FAIL') ?> | Preflight: <?= tools_badge((bool)$readiness['preflight_ok'] ? 'OK' : 'FAIL') ?></div>
      <?php if ($readyForCutover): ?>
        <div class="small mt-1"><?= tools_badge('HEALTHY', 'READY FOR CUTOVER') ?></div>
      <?php else: ?>
        <div class="small mt-1"><?= tools_badge('ATTENTION', 'NOT READY FOR CUTOVER') ?></div>
        <?php if ($cutoverReasons): ?>
          <div class="small text-warning"><?= h(implode('; ', array_slice($cutoverReasons, 0, 3))) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="small mt-2">Last smoke:
        <?= $smokeUnknown ? tools_badge('UNKNOWN') : tools_badge($smokePass ? 'PASS' : 'FAIL') ?>
        checks=<?= (int)$smokeTotal ?> pass=<?= (int)$smokePassCount ?> fail=<?= (int)$smokeFailCount ?> duration=<?= $smokeDuration > 0 ? h((string)round($smokeDuration / 1000, 2)) . 's' : '-' ?>
      </div>
      <?php if ($smokeUnknown): ?>
        <div class="small text-warning">Last smoke not found.</div>
      <?php endif; ?>
      <?php if ($smokeErrLabel !== ''): ?>
        <div class="small text-warning"><?= h($smokeErrLabel) ?></div>
      <?php endif; ?>
      <?php if (!$smokeUnknown && !$smokeNorm['consistent']): ?>
        <div class="small text-warning">Smoke data inconsistent: total != pass+fail</div>
      <?php endif; ?>
      <form method="post" class="mt-2">
        <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
        <input type="hidden" name="action" value="generate_readiness">
        <button type="submit" class="btn btn-sm btn-rmi" <?= tools_can_access_web_tool('readiness_audit.php') ? '' : 'disabled' ?>>Refresh Readiness Report</button>
      </form>
      <div class="small mt-2">
        <a href="health.php">Health</a> ·
        <a href="preflight_check.php">Preflight</a> ·
        <a href="readiness_audit.php">Readiness Audit</a>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100" id="mobile-quality-gate">
      <div class="fw-semibold mb-2">Mobile Quality Gate (Quick Link)</div>
      <?php if ($mobileQualityState['ok']): ?>
        <div class="small mb-1"><?= $mobileQualityOk ? tools_badge('HEALTHY', 'PASS') : tools_badge('CRITICAL', 'FAIL') ?></div>
        <div class="small">Last run: <b><?= h(tools_fmt_ts((string)($mobileQualityData['run_at'] ?? ''))) ?></b></div>
        <div class="small">PASS: <?= (int)($mobileQualitySummary['pass_lines'] ?? 0) ?> · WARN: <?= (int)($mobileQualitySummary['warn_lines'] ?? 0) ?> · SKIP: <?= (int)($mobileQualitySummary['skip_lines'] ?? 0) ?> · FAIL: <?= (int)($mobileQualitySummary['fail_lines'] ?? 0) ?></div>
      <?php else: ?>
        <div class="small mb-1"><?= tools_badge('ATTENTION', 'NOT RUN YET') ?></div>
        <div class="small rmi-muted">Belum ada state `mobile_quality_gate_web.last.json`.</div>
      <?php endif; ?>
      <div class="small mt-2">
        <a class="btn btn-sm btn-outline-light" href="qa/mobile_quality_gate.php">Open Mobile Quality Gate</a>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100" id="deploy-size-audit">
      <div class="fw-semibold mb-2">Deploy Size Audit (Quick Link)</div>
      <?php if ($deploySizeState['ok']): ?>
        <div class="small mb-1"><?= $deploySizeOk ? tools_badge('HEALTHY', 'READY') : tools_badge('ATTENTION', 'CHECK') ?></div>
        <div class="small">Last run: <b><?= h(tools_fmt_ts((string)($deploySizeData['run_at'] ?? ''))) ?></b></div>
        <div class="small">ZIP: <b><?= h((string)($deploySizeSummary['zip_size_mb'] ?? 0)) ?> MB</b> · Extracted: <b><?= h((string)($deploySizeSummary['included_mb'] ?? 0)) ?> MB</b></div>
      <?php else: ?>
        <div class="small mb-1"><?= tools_badge('ATTENTION', 'NOT RUN YET') ?></div>
        <div class="small rmi-muted">Belum ada state `deploy_size_audit_last.json`.</div>
      <?php endif; ?>
      <div class="small mt-2">
        <a class="btn btn-sm btn-outline-light" href="release/deploy_size_audit.php">Open Deploy Size Audit</a>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100" id="all-checks-orchestrator">
      <div class="fw-semibold mb-2">Run All Checks (One Click) — One-Click Check Orchestrator</div>
      <?php if (!$allChecksState['ok']): ?>
        <div class="small mb-2"><?= tools_badge('ATTENTION') ?>
          <?php
            $err = (string)$allChecksState['error'];
            echo h(match ($err) {
                'missing' => 'History belum tersedia. Jalankan: php tools/qa/run_all_checks.php',
                'invalid_json' => 'State file invalid/corrupt.',
                'schema_mismatch' => 'State schema tidak dikenali, butuh migrate.',
                default => 'State all checks bermasalah.',
            });
          ?>
        </div>
      <?php else: ?>
        <div class="small mb-2"><?= $allChecksOk ? tools_badge('HEALTHY', 'ALL CHECKS PASS') : tools_badge('CRITICAL', 'ALL CHECKS FAIL') ?></div>
        <div class="small">Run at: <b><?= h(tools_fmt_ts((string)($allChecksData['run_at'] ?? ''))) ?></b></div>
        <div class="small">Score: <b><?= (int)($allChecksSummary['score'] ?? 0) ?>/100</b> | Fail count: <b><?= (int)($allChecksSummary['fail_count'] ?? 0) ?></b></div>
        <div class="small mt-1">Runtime: <?= !empty($allChecksRuntime['running']) ? tools_badge('ATTENTION', 'RUNNING PID ' . (int)($allChecksRuntime['pid'] ?? 0)) : tools_badge('HEALTHY', 'IDLE') ?></div>
        <?php if ($allChecksSteps): ?>
          <div class="small mt-2">
            <?php foreach ($allChecksSteps as $st): ?>
              <?php $sok = !empty($st['ok']); ?>
              <div class="border-bottom py-1">
                <code><?= h((string)($st['name'] ?? '-')) ?></code> · <?= $sok ? tools_badge('OK') : tools_badge('FAIL') ?> · <?= h((string)($st['duration_ms'] ?? 0)) ?>ms
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post" class="mt-2 d-flex gap-2 flex-wrap">
        <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
        <input type="hidden" name="action" value="run_all_checks_web">
        <input type="hidden" name="mode" value="quick">
        <button type="submit" class="btn btn-sm btn-rmi" <?= (tools_can_access_web_tool('__run_all_checks_web') && empty($allChecksRuntime['running'])) ? '' : 'disabled' ?>>Run Checks (Background Quick)</button>
        <span class="small align-self-center"><code>php tools/qa/run_all_checks.php --quick</code> · full: <code>php tools/qa/run_all_checks.php</code></span>
      </form>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100" id="ops-hardening-orchestrator">
      <div class="fw-semibold mb-2">Ops Hardening Audit (One Click)</div>
      <div class="small mb-1">Default scope <code>ops</code> untuk operasional harian, scope <code>full</code> untuk observability lintas repo.</div>
      <div class="small mb-1">Hardening Snapshot: <?= $opsHardeningBaseline['ok'] ? tools_badge('HEALTHY', 'READY') : tools_badge('ATTENTION', 'MISSING') ?></div>
      <div class="small mb-2">
        Last ts: <b><?= h(tools_fmt_ts((string)($opsHardeningData['ts'] ?? ''))) ?></b>
        · findings: <b><?= (int)($opsHardeningSummary['finding_count'] ?? 0) ?></b>
        · new: <b><?= (int)($opsHardeningSummary['new_count'] ?? 0) ?></b>
        · resolved: <b><?= (int)($opsHardeningSummary['resolved_count'] ?? 0) ?></b>
      </div>
      <div class="small mb-2">
        Runtime: <?= !empty($opsHardeningRuntime['running']) ? tools_badge('ATTENTION', 'RUNNING PID ' . (int)($opsHardeningRuntime['pid'] ?? 0)) : tools_badge('HEALTHY', 'IDLE') ?>
      </div>
      <form method="post" class="d-flex gap-2 flex-wrap mb-2">
        <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
        <input type="hidden" name="action" value="run_ops_hardening_web">
        <input type="hidden" name="scope" value="full">
        <input type="hidden" name="mode" value="normal">
        <button type="submit" class="btn btn-sm btn-rmi" <?= (tools_can_access_web_tool('__ops_hardening_audit_web') && empty($opsHardeningRuntime['running'])) ? '' : 'disabled' ?>>Run Full Audit</button>
      </form>
      <form method="post" class="d-flex gap-2 flex-wrap mb-2">
        <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
        <input type="hidden" name="action" value="run_ops_hardening_web">
        <input type="hidden" name="scope" value="full">
        <input type="hidden" name="mode" value="dry-run">
        <button type="submit" class="btn btn-sm btn-outline-light" <?= (tools_can_access_web_tool('__ops_hardening_audit_web') && empty($opsHardeningRuntime['running'])) ? '' : 'disabled' ?>>Dry-Run Delta (No Write)</button>
      </form>
      <form method="post" class="d-flex gap-2 flex-wrap">
        <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
        <input type="hidden" name="action" value="run_ops_hardening_web">
        <input type="hidden" name="scope" value="full">
        <input type="hidden" name="mode" value="reset">
        <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Reset Hardening Snapshot ke kondisi saat ini?')" <?= (tools_can_access_web_tool('__ops_hardening_audit_web') && empty($opsHardeningRuntime['running'])) ? '' : 'disabled' ?>>Reset Hardening Snapshot</button>
      </form>
      <div class="small mt-2">
        Reports:
        <?php if ($opsReportOpsExists): ?><a href="../docs/governance/OPS_HARDENING_REPORT.md" target="_blank" rel="noopener">Ops</a><?php else: ?><span class="muted">Ops missing</span><?php endif; ?>
        ·
        <?php if ($opsReportFullExists): ?><a href="../docs/governance/OPS_HARDENING_REPORT_FULL.md" target="_blank" rel="noopener">Full</a><?php else: ?><span class="muted">Full missing</span><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100" id="business-signoff-gate">
      <div class="fw-semibold mb-2">Business Sign-off Gate</div>
      <div class="small mb-1">Verdict:
        <?php if ($signoffVerdictState['ok']): ?>
          <?= strtoupper((string)($signoffVerdict['verdict'] ?? 'NO-GO')) === 'GO' ? tools_badge('HEALTHY', 'GO') : tools_badge('ATTENTION', 'NO-GO') ?>
        <?php else: ?>
          <?= tools_badge('ATTENTION', 'NO VERDICT') ?>
        <?php endif; ?>
      </div>
      <div class="small mb-1">Hypercare 24h:
        <?= $hypercareSummaryState['ok'] ? (!empty($hypercareSummary['hypercare_complete_24h']) ? tools_badge('HEALTHY', 'COMPLETE') : tools_badge('ATTENTION', 'INCOMPLETE')) : tools_badge('UNKNOWN') ?>
        <span class="rmi-muted">covered <?= h((string)($hypercareSummary['window_hours_covered'] ?? 0)) ?>h, checkpoints <?= h((string)($hypercareSummary['total_checkpoints'] ?? 0)) ?></span>
      </div>
      <div class="small mb-1">Business sign-off:
        <?= $businessSignoffState['ok'] ? (!empty($businessSignoff['signed']) ? tools_badge('HEALTHY', 'SIGNED') : tools_badge('ATTENTION', 'NOT SIGNED')) : tools_badge('UNKNOWN') ?>
      </div>
      <div class="small mb-2">Approver: <b><?= h((string)($businessSignoff['approver_name'] ?? '-')) ?></b> · Signed at: <?= h(tools_fmt_ts((string)($businessSignoff['signed_at'] ?? ''))) ?></div>
      <div class="small">
        <?php if ($readyForFinalSignoff): ?>
          <?= tools_badge('HEALTHY', 'READY FOR FINAL SIGN-OFF') ?>
        <?php else: ?>
          <?= tools_badge('ATTENTION', 'NOT READY FOR FINAL SIGN-OFF') ?>
        <?php endif; ?>
      </div>
      <div class="small mt-2">
        <a href="signoff/business_signoff.php">Open Business Sign-off Form</a>
        · <a href="qa/dashboard_role_smoke_web.php">Open Dashboard Role Smoke Web</a>
        · <a href="qa/chat_api_smoke_web.php">Open Chat API Smoke Web</a>
        · <a href="qa/chat_schema_smoke_web.php">Open Chat Schema Smoke Web</a>
        · <a href="qa/sales_tracking_smoke_web.php">Open Sales Tracking Smoke Web</a>
        · <a href="qa/web_wrapper_health_matrix.php">Open Wrapper Health Matrix</a>
        · <a href="qa/erp_structure_guard_web.php">Open ERP Structure Guard</a>
        · <a href="qa/erp_boundary_guard_web.php">Open ERP Boundary Guard</a>
        · <a href="qa/erp_pattern_standard_web.php">Open ERP Pattern Standard</a>
        · <a href="qa/erp_hardening_triage_web.php">Open ERP Hardening Triage</a>
        · <a href="qa/evidence_pack_web.php">Open Evidence Pack Web</a>
        · <a href="qa/mobile_api_smoke_web.php">Open Mobile API Smoke Web</a>
        · <a href="qa/mobile_auth_policy_web.php">Open Mobile Auth Policy Web</a>
        · <a href="qa/mobile_api_contract_check_web.php">Open Mobile API Contract Check Web</a>
        · <a href="qa/tools_dashboard_smoke_web.php">Open Tools Dashboard Smoke Web</a>
        · <a href="qa/hypercare_checkpoint_web.php">Open Hypercare Checkpoint Web</a>
        · <a href="qa/hypercare_summary_web.php">Open Hypercare Summary Web</a>
        · <a href="qa/signoff_verdict_web.php">Open Signoff Verdict Web</a>
        · <a href="qa/signoff_ops_web.php">Open Signoff Ops Chain</a>
      </div>
      <div class="small mt-1"><code>php tools/qa/hypercare_checkpoint.php</code> · <code>php tools/qa/hypercare_summary.php</code> · <code>php tools/qa/generate_signoff_verdict.php</code></div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Security & Migration Snapshot</div>
      <div class="small mb-2">Migration markers (last executed by/at + one-time warning):</div>
      <?php foreach ($migrationStates as $k => $stWrap): $st = is_array($stWrap['data'] ?? null) ? $stWrap['data'] : []; ?>
        <div class="small border-bottom py-1">
          <code><?= h($k) ?></code>
          · <?= h(tools_fmt_ts((string)($st['last_executed_at'] ?? ''))) !== '-' ? h(tools_fmt_ts((string)($st['last_executed_at'] ?? ''))) : 'Belum pernah dijalankan' ?>
          · by <?= h((string)($st['last_executed_by'] ?? '-')) ?>
          <?= !$stWrap['ok'] ? tools_badge('ATTENTION', ti_state_error_label((string)($stWrap['error'] ?? ''))) : '' ?>
        </div>
      <?php endforeach; ?>
      <div class="small mt-2 mb-1">RBAC seed verify status:</div>
      <?php foreach ($seedStates as $k => $stWrap): $st = is_array($stWrap['data'] ?? null) ? $stWrap['data'] : []; ?>
        <div class="small">
          <code><?= h($k) ?></code>
          · <?= !empty($st['applied']) ? 'Applied' : 'Not Applied' ?>
          · last <?= h(tools_fmt_ts((string)($st['last_run_at'] ?? $st['checked_at'] ?? ''))) !== '-' ? h(tools_fmt_ts((string)($st['last_run_at'] ?? $st['checked_at'] ?? ''))) : 'Belum pernah dijalankan' ?>
          <?= !$stWrap['ok'] ? tools_badge('ATTENTION', ti_state_error_label((string)($stWrap['error'] ?? ''))) : '' ?>
        </div>
      <?php endforeach; ?>
      <div class="small mt-2">
        Security hardening mode: <code>--check</code>, <code>--apply</code>, <code>--backup</code>
        · last files touched: <?= (int)((is_array($hardeningLast['data'] ?? null) ? $hardeningLast['data'] : [])['files_touched'] ?? 0) ?>
        <?= !$hardeningLast['ok'] ? tools_badge('ATTENTION', ti_state_error_label((string)($hardeningLast['error'] ?? ''))) : '' ?>
      </div>
      <div class="small">Effective perms: <a href="rbac_effective_permissions.php?source=tools_index">Open checker</a></div>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Recent Tools Run History</div>
      <?php if (!$historyRes['exists']): ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> History belum tersedia.</div>
      <?php else: ?>
        <?php if ((int)$historyRes['corrupt_lines'] > 20): ?>
          <div class="small mb-2"><?= tools_badge('ATTENTION') ?> History file corrupt sebagian.</div>
        <?php elseif ((int)$historyRes['corrupt_lines'] > 0): ?>
          <div class="small mb-2"><?= tools_badge('ATTENTION') ?> History file mengandung beberapa baris invalid.</div>
        <?php endif; ?>
        <?php if (!$historyItems): ?>
          <div class="small"><?= tools_badge('UNKNOWN') ?> Event penting belum ada.</div>
        <?php else: ?>
          <?php foreach ($historyItems as $ev): ?>
            <?php
              $ok = strtoupper((string)($ev['status'] ?? 'UNKNOWN'));
              $lvl = in_array($ok, ['OK', 'PASS', 'SUCCESS'], true) ? 'HEALTHY' : (in_array($ok, ['FAIL', 'FAILED', 'ERROR'], true) ? 'CRITICAL' : 'ATTENTION');
              $meta = tools_mask_sensitive(json_encode((array)($ev['meta'] ?? []), JSON_UNESCAPED_SLASHES) ?: '{}');
            ?>
            <div class="small border-bottom py-1">
              <b><?= h(tools_fmt_ts((string)($ev['time'] ?? ''))) ?></b> · <code><?= h((string)($ev['event'] ?? '-')) ?></code>
              · <?= tools_badge($lvl, $ok !== '' ? $ok : 'UNKNOWN') ?> · <?= h($meta) ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">RBAC Tool Access Matrix</div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead><tr><th>Tool</th><th>Tags</th><th>Web Roles</th><th>CLI Roles</th><th>Owner</th></tr></thead>
          <tbody>
            <?php foreach ($matrix as $toolId => $entry): ?>
              <tr>
                <td><code><?= h((string)$toolId) ?></code><div class="small rmi-muted"><?= h((string)($entry['title'] ?? '')) ?></div></td>
                <td><?= h(implode(', ', (array)($entry['tags'] ?? []))) ?></td>
                <td><?= h(implode(', ', (array)($entry['web_roles_allowed'] ?? []))) ?></td>
                <td><?= h(implode(', ', (array)($entry['cli_roles_allowed'] ?? []))) ?></td>
                <td><?= h((string)($entry['owner'] ?? 'Platform Team')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Knowledge Hub & SOP Links</div>
      <div class="small">Viewer query example: <code>plan_kerja.php?q=security</code> · Owner: <b>PMO Team</b> · last updated: <?= h(date('Y-m-d H:i:s', (int)@filemtime(__DIR__ . '/plan_kerja.php'))) ?></div>
      <div class="small mt-1">
        SOP links:
        <a href="../CUTOVER_ONE_PAGER.md">CUTOVER_ONE_PAGER.md</a> ·
        <a href="../DEPLOY_RUNBOOK.md">DEPLOY_RUNBOOK.md</a> ·
        <a href="../TOOLS_DASHBOARD_SPEC.md">TOOLS_DASHBOARD_SPEC.md</a> ·
        <a href="../TOOLS_TESTPLAN.md">TOOLS_TESTPLAN.md</a>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Tools by Category (Accordion)</div>
      <div class="small rmi-muted mb-2">Klik kategori untuk expand/collapse agar halaman lebih ringan saat dibaca.</div>
      <?php $catIdx = 0; foreach ($categories as $cat => $items): $catIdx++; ?>
        <details class="mb-2" <?= $catIdx === 1 ? 'open' : '' ?>>
          <summary class="fw-semibold" style="cursor:pointer; list-style-position: inside;">
            <?= h($cat) ?> <span class="badge rmi-badge"><?= count($items) ?> tool</span>
          </summary>
          <div class="row g-3 mt-1">
            <?php foreach ($items as $r): ?>
              <div class="col-md-6 col-lg-4">
                <div class="rmi-card p-3 h-100">
                  <div class="d-flex justify-content-between align-items-start mb-1">
                    <div><code><?= h($r['file']) ?></code><div class="small"><?= h($r['title']) ?></div></div>
                    <span class="badge rmi-badge <?= $r['safety'] === 'READ' ? 'success' : 'danger' ?>"><?= h($r['safety']) ?></span>
                  </div>
                  <div class="small rmi-muted mb-2"><?= h($r['desc']) ?></div>
                  <div class="small mb-2">Service owner: <b><?= h((string)$r['owner']) ?></b> <?= !empty($r['pinned']) ? '<span class="badge rmi-badge warning">PINNED</span>' : '' ?></div>
                  <div class="d-flex gap-2 flex-wrap">
                    <?php if ($r['web']): ?>
                      <?php $canOpen = tools_can_access_web_tool((string)$r['file']); ?>
                      <?php if ($canOpen): ?><a class="btn btn-sm btn-rmi" href="<?= h($r['file']) ?>">Open</a><?php else: ?><span class="btn btn-sm btn-outline-light disabled">Locked</span><?php endif; ?>
                    <?php else: ?>
                      <span class="btn btn-sm btn-outline-light disabled">CLI Required</span>
                      <?php
                        $alt = (string)($cliAlternativeMap[$r['file']] ?? '');
                        $altExists = ($alt !== '') && isset($known[$alt]) && is_file(__DIR__ . '/' . $alt);
                      ?>
                      <?php if ($altExists && tools_can_access_web_tool($alt)): ?>
                        <a class="btn btn-sm btn-rmi" href="<?= h($alt) ?>">Open Web Alternative</a>
                      <?php endif; ?>
                    <?php endif; ?>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
                      <input type="hidden" name="action" value="toggle_pin">
                      <input type="hidden" name="file" value="<?= h($r['file']) ?>">
                      <button type="submit" class="btn btn-sm btn-outline-light"><?= !empty($r['pinned']) ? 'Unpin' : 'Pin' ?></button>
                    </form>
                  </div>
                  <?php if (!$r['web']): ?>
                    <?php $riskReason = (string)($cliRiskReasonMap[$r['file']] ?? 'Tetap CLI: kontrol manual lebih aman untuk tool ini.'); ?>
                    <div class="small mt-2 text-warning"><?= h($riskReason) ?></div>
                    <?php
                      $alt2 = (string)($cliAlternativeMap[$r['file']] ?? '');
                      $alt2Exists = ($alt2 !== '') && isset($known[$alt2]) && is_file(__DIR__ . '/' . $alt2);
                    ?>
                    <?php if ($alt2Exists): ?>
                      <div class="small">Alternatif web: <code><?= h($alt2) ?></code></div>
                    <?php endif; ?>
                  <?php endif; ?>
                  <div class="small mt-2"><code><?= h($r['cli']) ?></code></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php rmi_footer(); ?>
