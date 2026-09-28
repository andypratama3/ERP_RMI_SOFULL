<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';

$root = ts_root();
$logs = ts_storage_logs_dir();
$runAt = date(DateTimeInterface::ATOM);

/**
 * @return array{severity:string,id:string,file:string,ok:bool,message:string}
 */
function rhg_check(string $severity, string $id, string $file, bool $ok, string $message): array
{
    return [
        'severity' => strtoupper($severity),
        'id' => $id,
        'file' => $file,
        'ok' => $ok,
        'message' => tools_mask_sensitive($message),
    ];
}

$findings = [];

// 1) Repo hygiene files
$gitignorePath = $root . '/.gitignore';
$dockerignorePath = $root . '/.dockerignore';
$findings[] = rhg_check(
    'CRITICAL',
    'GITIGNORE_MISSING',
    ts_mask($gitignorePath),
    is_file($gitignorePath),
    is_file($gitignorePath) ? 'File .gitignore tersedia' : 'File .gitignore belum ada'
);
$findings[] = rhg_check(
    'HIGH',
    'DOCKERIGNORE_MISSING',
    ts_mask($dockerignorePath),
    is_file($dockerignorePath),
    is_file($dockerignorePath) ? 'File .dockerignore tersedia' : 'File .dockerignore belum ada'
);

// 2) dashboards/_health.php risky include + debug leakage
$healthPath = $root . '/dashboards/_health.php';
$healthRaw = is_file($healthPath) ? (string)@file_get_contents($healthPath) : '';
$healthConfigBad = ($healthRaw !== '' && str_contains($healthRaw, "require_once __DIR__ . '/config.php'"));
$healthDebugOn = ($healthRaw !== '' && (str_contains($healthRaw, "ini_set('display_errors','1'") || str_contains($healthRaw, 'display_errors","1')));
$findings[] = rhg_check(
    'CRITICAL',
    'DASH_HEALTH_INCLUDE_PATH',
    ts_mask($healthPath),
    !$healthConfigBad,
    !$healthConfigBad ? 'Include config path aman' : 'dashboards/_health.php memakai include config lokal yang berisiko gagal'
);
$findings[] = rhg_check(
    'CRITICAL',
    'DASH_HEALTH_DEBUG_LEAK',
    ts_mask($healthPath),
    !$healthDebugOn,
    !$healthDebugOn ? 'display_errors tidak dipaksa ON' : 'display_errors dipaksa ON pada dashboards/_health.php'
);

// 3) Backup scheduler password field leakage in HTML value
$backupSchedulePath = $root . '/tools/backup_schedule.php';
$bsRaw = is_file($backupSchedulePath) ? (string)@file_get_contents($backupSchedulePath) : '';
$passValueLeak = ($bsRaw !== '' && str_contains($bsRaw, 'name="db_pass"') && str_contains($bsRaw, 'value="<?= h($cfgDbPass) ?>"'));
$findings[] = rhg_check(
    'CRITICAL',
    'BACKUP_SCHEDULE_DBPASS_RENDER',
    ts_mask($backupSchedulePath),
    !$passValueLeak,
    !$passValueLeak ? 'Field db_pass tidak merender nilai password langsung' : 'Field db_pass masih merender value password pada HTML'
);

// 4) master_user POST csrf enforcement
$masterUserPath = $root . '/master/master_user.php';
$muRaw = is_file($masterUserPath) ? (string)@file_get_contents($masterUserPath) : '';
$hasPostBlocks = ($muRaw !== '' && str_contains($muRaw, 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\''));
$hasCsrfValidation = ($muRaw !== '' && (str_contains($muRaw, 'verify_csrf') || str_contains($muRaw, 'rmi_csrf_validate')));
$masterUserCsrfOk = !$hasPostBlocks || $hasCsrfValidation;
$findings[] = rhg_check(
    'CRITICAL',
    'MASTER_USER_CSRF',
    ts_mask($masterUserPath),
    $masterUserCsrfOk,
    $masterUserCsrfOk ? 'POST mutating master_user memiliki validasi CSRF' : 'POST mutating master_user belum ditemukan validasi CSRF'
);

// 5) Backup reliability (latest backup db_status)
$latest = ts_latest_backup_meta();
$dbStatus = strtolower((string)($latest['manifest']['db_status'] ?? 'unknown'));
$backupDbOk = ($dbStatus === 'ok');
$findings[] = rhg_check(
    'HIGH',
    'BACKUP_DB_STATUS',
    ts_mask((string)($latest['path_masked'] ?? '[APP_ROOT]/storage/backups')),
    $backupDbOk,
    $backupDbOk ? 'Backup terakhir memuat DB dump valid' : 'Backup terakhir tidak memuat DB dump valid (db_status!=' . $dbStatus . ')'
);

$criticalFail = 0;
$highFail = 0;
$warn = 0;
foreach ($findings as $f) {
    if ($f['ok']) {
        continue;
    }
    if ($f['severity'] === 'CRITICAL') {
        $criticalFail++;
    } elseif ($f['severity'] === 'HIGH') {
        $highFail++;
    } else {
        $warn++;
    }
}
$overallOk = ($criticalFail === 0 && $highFail === 0);

$state = [
    'state_version' => 1,
    'run_at' => $runAt,
    'overall_ok' => $overallOk,
    'summary' => [
        'total' => count($findings),
        'critical_fail' => $criticalFail,
        'high_fail' => $highFail,
        'warn' => $warn,
        'score' => max(0, 100 - ($criticalFail * 30) - ($highFail * 15) - ($warn * 5)),
    ],
    'findings' => $findings,
];

ts_write_json($logs . '/repo_health_gaps.last.json', $state);

$md = "# REPO_HEALTH_GAPS_REPORT\n\n";
$md .= "- Run at: `" . tools_fmt_ts($runAt) . "`\n";
$md .= "- Overall: **" . ($overallOk ? 'PASS' : 'FAIL') . "**\n\n";
$md .= "| severity | id | file | status | message |\n";
$md .= "|---|---|---|---|---|\n";
foreach ($findings as $f) {
    $md .= "| " . $f['severity'] . " | `" . $f['id'] . "` | `" . tools_mask_sensitive($f['file']) . "` | " . ($f['ok'] ? 'PASS' : 'FAIL') . " | " . tools_mask_sensitive($f['message']) . " |\n";
}
@file_put_contents($logs . '/repo_health_gaps_last.md', $md);

ts_append_run_history('repo_health_gaps', $overallOk ? 'OK' : 'FAIL', [
    'actor_username' => getenv('USER') ?: 'SYSTEM',
    'source' => 'tools/qa/repo_health_gaps.php',
    'critical_fail' => $criticalFail,
    'high_fail' => $highFail,
]);

echo json_encode($state, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($overallOk ? 0 : 1);
