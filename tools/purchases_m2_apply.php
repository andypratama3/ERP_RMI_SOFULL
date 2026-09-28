<?php
/**
 * tools/purchases_m2_apply.php
 *
 * M2 Purchases apply helper:
 * - Inject require purchases/_purchases_bootstrap.php + purchases_require_login() ke semua halaman UI purchases.
 * - Ganti basename($_FILES...) -> rmi_safe_filename($_FILES...) untuk file upload (hardening + satisfy audit scan).
 * - Membuat backup otomatis sebelum menulis.
 *
 * Jalankan:
 *   php tools/purchases_m2_apply.php
 *
 * Dry run:
 *   php tools/purchases_m2_apply.php --dry-run
 */

declare(strict_types=1);
require_once __DIR__ . '/tools_state_lib.php';

// This is a repository maintenance helper.
// When accessed from a browser, avoid CLI-only globals/constants causing fatal errors.
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../_shared/bootstrap.php';
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

    header('Content-Type: text/html; charset=utf-8');
    ?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Purchases M2 Apply', [
  'active' => 'tools',
  'breadcrumbs' => [
    ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
    'Purchases M2 Apply',
  ],
  'extra_head' => '<style>body{font-family:Arial,Helvetica,sans-serif;padding:16px;max-width:900px;margin:0 auto;}</style>',
]);
?>

        <h2>Purchases M2 Apply (CLI tool)</h2>
        <p>Tool ini adalah helper maintenance repo (patch otomatis file PHP Purchases). Demi keamanan, tool ini <strong>tidak</strong> dijalankan via browser.</p>
        <p>Jalankan via CLI dari root project:</p>
        <pre>php tools/purchases_m2_apply.php
php tools/purchases_m2_apply.php --dry-run</pre>
        <p><a href="../index.php">Kembali ke Home</a> | <a href="../master/logout.php">Logout</a></p>
    <?php
    rmi_footer();
    exit;
}

$dryRun = in_array('--dry-run', $argv, true);
$understand = in_array('--i-understand', $argv, true);
$forceNoBackupCheck = in_array('--force-without-backup-check', $argv, true);
$actor = 'SYSTEM';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--actor=')) {
        $actor = trim(substr($arg, 8)) ?: 'SYSTEM';
    }
}
if ($actor === 'SYSTEM') {
    $actor = (string)(getenv('USER') ?: 'SYSTEM');
}
$statePath = ts_storage_logs_dir() . '/migration_purchases_m2_apply.state.json';
$appEnv = (string)(getenv('APP_ENV') ?: 'local');
$latestBackup = ts_latest_backup_meta();
$smoke = ts_read_json(ts_storage_logs_dir() . '/smoke_http_last.json');
$smokePass = (bool)(ts_smoke_summary($smoke)['ok'] ?? false);
echo "============================================================\n";
echo "ONE-TIME SCRIPT - DO NOT RE-RUN CARELESSLY\n";
echo "Script: purchases_m2_apply.php\n";
echo "============================================================\n";
if (($latestBackup['age_hours'] ?? 999) > 24 && !$forceNoBackupCheck) {
    fwrite(STDERR, "Pre-run check blocked: latest backup older than 24h. Use --force-without-backup-check if intentional.\n");
    exit(2);
}
if (!$smokePass) {
    fwrite(STDERR, "Pre-run warning: last smoke is not PASS.\n");
}
if (in_array(strtolower($appEnv), ['prod', 'production'], true) && !$understand) {
    fwrite(STDERR, "Production safeguard: add --i-understand to continue.\n");
    exit(2);
}
ts_write_json($statePath, [
    'script' => 'purchases_m2_apply.php',
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

$root = realpath(__DIR__ . '/..');
if (!$root) {
    fwrite(STDERR, "Cannot resolve project root\n");
    exit(1);
}

$purchasesDir = $root . '/purchases';
if (!is_dir($purchasesDir)) {
    fwrite(STDERR, "Purchases folder not found: {$purchasesDir}\n");
    exit(1);
}

$files = glob($purchasesDir . '/*.php') ?: [];
$files = array_map('realpath', $files);
$files = array_values(array_filter($files));

// Exclude pure library (do not inject bootstrap into lib)
$exclude = [
    realpath($purchasesDir . '/_purchases_lib.php'),
];

$targets = [];
foreach ($files as $f) {
    if (in_array($f, $exclude, true)) continue;
    $targets[] = $f;
}

$backupDir = $root . '/_backup/purchases_m2_' . date('Ymd_His');

function ensure_dir(string $dir): void {
    if (is_dir($dir)) return;
    if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create dir: {$dir}\n");
        exit(1);
    }
}

function backup_file(string $src, string $root, string $backupDir): void {
    $rel = ltrim(str_replace($root, '', $src), '/');
    $dest = $backupDir . '/' . $rel;
    ensure_dir(dirname($dest));
    if (!copy($src, $dest)) {
        fwrite(STDERR, "Backup failed: {$src} -> {$dest}\n");
        exit(1);
    }
}

function inject_bootstrap(string $content): array {
    // Return [newContent, changedBool, reason]
    if (stripos($content, "_purchases_bootstrap.php") !== false) {
        return [$content, false, 'bootstrap already present'];
    }

    // Only patch PHP files (allow leading BOM/whitespace)
    if (!preg_match('/^\s*<\?php/i', $content)) {
        return [$content, false, 'not a php entry (missing <?php)'];
    }

    $snippet = "require_once __DIR__ . '/_purchases_bootstrap.php';\n" .
               "purchases_require_login();\n\n";

    // Insert after optional declare(strict_types=1);
    $pattern = '/^(\s*<\?php\s*(?:\R|\r\n))(declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*(?:\R|\r\n))?/i';
    if (preg_match($pattern, $content, $m)) {
        $replacement = $m[1];
        if (!empty($m[2])) {
            $replacement .= $m[2];
        }
        $replacement .= $snippet;
        $new = preg_replace($pattern, $replacement, $content, 1);
        if ($new === null) {
            return [$content, false, 'regex failed'];
        }
        return [$new, true, 'injected bootstrap'];
    }

    // Fallback: insert right after <?php\n
    $pos = strpos($content, "\n");
    if ($pos === false) {
        return ["<?php\n" . $snippet, true, 'injected (fallback)'];
    }
    $new = substr($content, 0, $pos + 1) . $snippet . substr($content, $pos + 1);
    return [$new, true, 'injected (fallback)'];
}

function patch_safe_filename(string $content): array {
    // Replace basename($_FILES...) -> rmi_safe_filename($_FILES...)
    if (stripos($content, '$_FILES') === false) {
        return [$content, false, 'no uploads'];
    }

    $new = preg_replace('/basename\s*\(\s*\$_FILES/i', 'rmi_safe_filename($_FILES', $content);
    if ($new === null) return [$content, false, 'regex failed'];

    if ($new !== $content) {
        return [$new, true, 'patched basename($_FILES..) -> rmi_safe_filename'];
    }
    return [$content, false, 'no basename($_FILES..) pattern'];
}

ensure_dir($backupDir);

$changed = 0;
$changedFiles = [];

echo "Project root: {$root}\n";
echo "Purchases files: " . count($targets) . "\n";
echo "Backup dir: {$backupDir}\n";
echo $dryRun ? "Mode: DRY-RUN\n\n" : "Mode: APPLY\n\n";

foreach ($targets as $file) {
    $orig = file_get_contents($file);
    if ($orig === false) {
        echo "[SKIP] {$file} (cannot read)\n";
        continue;
    }

    [$c1, $ch1, $r1] = inject_bootstrap($orig);
    [$c2, $ch2, $r2] = patch_safe_filename($c1);

    $final = $c2;
    $didChange = ($final !== $orig);

    if ($didChange) {
        $changed++;
        $changedFiles[] = [basename($file), $r1, $r2];
        if (!$dryRun) {
            backup_file($file, $root, $backupDir);
            file_put_contents($file, $final);
        }
        echo "[PATCH] " . basename($file) . " :: {$r1} | {$r2}\n";
    } else {
        echo "[OK]    " . basename($file) . " :: {$r1} | {$r2}\n";
    }
}

echo "\nDone. Files changed: {$changed}\n";
if ($dryRun) {
    echo "Dry-run selesai. Jalankan tanpa --dry-run untuk apply.\n";
} else {
    echo "Backup tersimpan di: {$backupDir}\n";
    echo "Jika ada masalah, restore manual dari folder backup itu.\n";
}
ts_write_json($statePath, [
    'script' => 'purchases_m2_apply.php',
    'one_time' => true,
    'last_executed_at' => date(DateTimeInterface::ATOM),
    'last_executed_by' => $actor,
    'env' => $appEnv,
    'ok' => true,
    'summary' => $dryRun ? 'Dry-run completed' : 'Apply completed',
    'last_error_masked' => '',
    'files_updated' => $changed,
]);
@chmod($statePath, 0644);
