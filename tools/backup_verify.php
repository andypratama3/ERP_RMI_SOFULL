<?php
declare(strict_types=1);

require_once __DIR__ . '/tools_remote_check.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/tools_ui_helpers.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.BACKUP_MANAGE', 'TOOLS.VIEW']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function bv_mask_path(string $path, string $root): string {
    $normRoot = rtrim(str_replace('\\', '/', $root), '/');
    $norm = str_replace('\\', '/', $path);
    if (str_starts_with($norm, $normRoot)) {
        return '[APP_ROOT]' . substr($norm, strlen($normRoot));
    }
    return $norm;
}

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$backupRoot = $projectRoot . '/storage/backups';
$latestFile = $backupRoot . '/LATEST_BACKUP.txt';
$latest = '';
if (is_file($latestFile)) {
    $latest = trim((string)@file_get_contents($latestFile));
}

$status = 'missing';
$lines = [];
$verifyCode = 1;
if ($latest !== '' && is_dir($latest)) {
    $checksums = $latest . '/checksums.sha256';
    if (is_file($checksums)) {
        // PHP-native SHA256 verification (no shasum dependency)
        $failed = [];
        $checked = 0;
        $rawLines = array_filter(array_map('trim', file($checksums, FILE_IGNORE_NEW_LINES) ?: []));
        foreach ($rawLines as $line) {
            if (!preg_match('/^([a-f0-9]{64})\s+(.+)$/', $line, $m)) continue;
            $expectedHash = $m[1];
            $filename     = ltrim($m[2], './');
            $filepath     = $latest . '/' . $filename;
            $checked++;
            if (!is_file($filepath)) {
                $failed[] = $filename . ' (missing)';
            } elseif (hash_file('sha256', $filepath) !== $expectedHash) {
                $failed[] = $filename . ' (hash mismatch)';
            }
        }
        if ($checked === 0) {
            $status = 'missing_checksums'; $verifyCode = 1;
            $lines[] = 'checksums.sha256 is empty or invalid';
        } elseif (empty($failed)) {
            $status = 'ok'; $verifyCode = 0;
            $lines[] = "All {$checked} files verified OK";
        } else {
            $status = 'failed'; $verifyCode = 1;
            foreach (array_slice($failed, 0, 5) as $f) $lines[] = "FAIL: {$f}";
        }
    } else {
        $status = 'missing_checksums';
        $lines[] = 'checksums.sha256 not found';
    }
}

// Artifact standar: storage/logs/backup_verify_last.json (roadmap Tahap 4)
$logsDir = $projectRoot . '/storage/logs';
@mkdir($logsDir, 0775, true);
@file_put_contents($logsDir . '/backup_verify_last.json', json_encode([
    'ok' => ($status === 'ok'),
    'run_at' => date(DateTimeInterface::ATOM),
    'status' => $status,
    'latest_package' => $latest !== '' ? basename($latest) : null,
    'exit_code' => $verifyCode,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();
rmi_header('Backup Verify', [
    'active'      => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Backup Verify',
    ],
]);
?>
<div class="rmi-card p-3 mb-3">
  <div class="fw-semibold">Latest Backup Verification</div>
  <div class="rmi-muted mt-1">Latest package: <code><?= h($latest !== '' ? bv_mask_path($latest, $projectRoot) : '-') ?></code></div>
  <div class="mt-2">
    Status:
    <?= $status === 'ok' ? tools_badge('HEALTHY', 'OK') : tools_badge('ATTENTION', strtoupper($status)) ?>
  </div>
</div>

<div class="rmi-card p-3">
  <pre style="white-space:pre-wrap"><?= h(tools_mask_sensitive(implode("\n", $lines))) ?></pre>
</div>
<?php rmi_footer(); ?>
