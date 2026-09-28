<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/../tools_state_lib.php';

tools_require_access('release/create_clean_deploy_zip.php');

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

function cdz_should_skip(string $rel): bool
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '') {
        return true;
    }
    // Include .gitkeep for storage dirs so folder structure exists after extract (Cpanel/shared hosting)
    $keepGitkeep = [
        'storage/logs/.gitkeep',
        'storage/backups/.gitkeep',
        'storage/uploads/.gitkeep',
        'storage/signoffs/.gitkeep',
    ];
    foreach ($keepGitkeep as $k) {
        if ($rel === $k || str_starts_with($rel, $k . '/')) {
            return false;
        }
    }
    $prefixes = [
        '.git/',
        '.cursor/',
        '.vscode/',
        'node_modules/',
        'storage/logs/',
        'storage/backups/',
        'storage/exports/',
        'storage/framework/cache/data/',
        'storage/framework/sessions/',
        'storage/framework/views/',
        'exports/',
        'playwright-report/',
        'tests-output/',
        'android_app/',
        'tests/',
        '_examples/',
        '_tracker/',
        'TEMPLATES/',
    ];
    foreach ($prefixes as $p) {
        if (str_starts_with($rel, $p)) {
            return true;
        }
    }

    $base = strtolower(basename($rel));
    if (in_array($base, ['.ds_store'], true)) {
        return true;
    }
    if (str_starts_with($base, '.env') && $base !== '.env.example') {
        return true;
    }
    if ($base === '.allow_remote') {
        return true; // override lokal untuk Tools remote, buat di server
    }
    if ($base === 'config-db.php') {
        return true; // credential, buat di server
    }
    if (str_ends_with($base, '.zip')) {
        return true;
    }
    if (str_ends_with($base, '.log')) {
        return true;
    }
    return false;
}

function cdz_generate(): array
{
    $root = realpath(__DIR__ . '/../../');
    if (!$root) {
        return ['ok' => false, 'status' => 'FAIL', 'message' => 'Root project tidak ditemukan.'];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'status' => 'FAIL', 'message' => 'ZipArchive extension belum aktif.'];
    }

    $bucket = date('Y-m');
    $stamp = date('Ymd_His');

    // Coba exports/deploy dulu, fallback ke storage/exports jika tidak writable
    $outDirPrimary = $root . '/exports/deploy/' . $bucket;
    @mkdir($root . '/exports', 0775, true);
    @mkdir($root . '/exports/deploy', 0775, true);
    @mkdir($outDirPrimary, 0775, true);
    if (is_writable($outDirPrimary)) {
        $outDir = $outDirPrimary;
    } else {
        $outDir = $root . '/storage/exports/deploy/' . $bucket;
        @mkdir($root . '/storage/exports/deploy', 0775, true);
        @mkdir($outDir, 0775, true);
    }
    @file_put_contents($root . '/exports/.htaccess', "Deny from all\n");
    @file_put_contents($root . '/exports/deploy/.htaccess', "Deny from all\n");
    @file_put_contents($outDir . '/.htaccess', "Deny from all\n");

    // Tulis langsung ke outDir (storage/exports/deploy/) — no temp file needed
    $zipPath = $outDir . '/ERP_RMI_SOFULL_deploy_clean_' . $stamp . '.zip';
    $zip = new ZipArchive();
    $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($openResult !== true) {
        return ['ok' => false, 'status' => 'FAIL', 'message' => 'Gagal membuat ZIP (error=' . $openResult . '). Cek permission: ' . $outDir];
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );

    $filesAdded = 0;
    $bytesAdded = 0;
    $skipped = 0;
    $sampleIncluded = [];
    foreach ($it as $path => $info) {
        /** @var SplFileInfo $info */
        if (!$info->isFile()) {
            continue;
        }
        $abs = (string)$path;
        $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
        if ($rel === '') {
            continue;
        }
        if (cdz_should_skip($rel)) {
            $skipped++;
            continue;
        }
        if ($abs === $zipPath) {
            continue;
        }
        $zip->addFile($abs, $rel);
        $filesAdded++;
        $bytesAdded += (int)$info->getSize();
        if (count($sampleIncluded) < 40) {
            $sampleIncluded[] = $rel;
        }
    }

    $readme = [];
    $readme[] = '# Deploy Clean Package';
    $readme[] = '';
    $readme[] = '- Generated at: ' . date(DateTimeInterface::ATOM);
    $readme[] = '- Files included: ' . $filesAdded;
    $readme[] = '- Files skipped: ' . $skipped;
    $readme[] = '- Included bytes: ' . $bytesAdded;
    $readme[] = '- Note: logs/backups/exports/node_modules telah di-exclude.';
    $zip->addFromString('DEPLOY_PACKAGE_README.md', implode("\n", $readme) . "\n");

    $manifest = [
        'state_version' => 1,
        'type' => 'deploy_clean_package',
        'generated_at' => date(DateTimeInterface::ATOM),
        'policy' => [
            'excluded_prefixes' => [
                '.git/',
                '.cursor/',
                '.vscode/',
                'node_modules/',
                'storage/logs/',
                'storage/backups/',
                'storage/exports/',
                'exports/',
                'playwright-report/',
                'tests-output/',
            ],
            'excluded_file_rules' => ['*.log', '*.zip', '.env* (except .env.example)'],
        ],
        'summary' => [
            'files_added' => $filesAdded,
            'files_skipped' => $skipped,
            'included_bytes' => $bytesAdded,
        ],
        'required_runtime_checks' => [
            'set production .env on target host',
            'ensure storage and uploads are writable',
            'run DB migration for target environment',
            'run smoke check after deployment',
        ],
        'included_sample' => $sampleIncluded,
    ];
    $zip->addFromString('DEPLOY_MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $checklist = [];
    $checklist[] = '# Post Deploy Checklist';
    $checklist[] = '';
    $checklist[] = '1. Upload + extract package ke docroot target.';
    $checklist[] = '2. Set `.env` produksi (jangan copy dari local).';
    $checklist[] = '3. Pastikan writable: `storage/` dan `uploads/`.';
    $checklist[] = '4. Jalankan smoke: `php tools/smoke_http.php`.';
    $checklist[] = '5. Buka `/tools/health.php` dan `/tools/index.php` untuk sanity check.';
    $checklist[] = '';
    $checklist[] = 'Jika gagal, cek: `storage/logs/smoke_http_last.json` dan `storage/logs/readiness_report_last.json`.';
    $zip->addFromString('POST_DEPLOY_CHECKLIST.md', implode("\n", $checklist) . "\n");
    if (!$zip->close()) {
        return ['ok' => false, 'status' => 'FAIL', 'message' => 'ZipArchive::close() gagal. Cek permission: ' . $outDir . ' — ' . $zip->getStatusString()];
    }

    $payload = [
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'status' => 'OK',
        'zip_path' => tools_mask_sensitive($zipPath),
        'files_added' => $filesAdded,
        'files_skipped' => $skipped,
        'included_bytes' => $bytesAdded,
        'zip_size_bytes' => (int)@filesize($zipPath),
    ];
    ts_write_json($root . '/storage/logs/deploy_clean_zip_last.json', $payload);
    ts_append_run_history('deploy_clean_zip_generate', 'OK', [
        'actor_username' => tools_current_actor_username(),
        'source' => 'tools/release/create_clean_deploy_zip.php',
        'zip_path' => $payload['zip_path'],
        'files_added' => $filesAdded,
        'zip_size_bytes' => $payload['zip_size_bytes'],
    ]);

    return ['ok' => true] + $payload;
}

if (PHP_SAPI === 'cli') {
    $res = cdz_generate();
    echo json_encode($res, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($res['ok']) ? 0 : 1);
}

require_login();
require_any_permission(['TOOLS.VIEW', 'SYSTEM.USER_MANAGE']);
$result = tools_read_state_json(ts_storage_logs_dir() . '/deploy_clean_zip_last.json');
$data = is_array($result['data'] ?? null) ? (array)$result['data'] : [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $run = cdz_generate();
    $result = ['ok' => true, 'data' => $run];
    $data = $run;
}

$baseProject = rmi_layout_base_project();
rmi_header('Create Clean Deploy ZIP', [
    'active' => 'tools',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Create Clean Deploy ZIP'],
]);
?>
<div class="card p-3">
  <div class="small mb-2">Generate ZIP deploy ringan (exclude logs/backups/exports/node_modules) untuk upload webhosting/VPS.</div>
  <form method="post" class="mb-3">
    <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
    <button class="btn btn-sm btn-rmi">Generate Clean Deploy ZIP</button>
  </form>
  <pre class="small mb-0"><?= h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php rmi_footer(); ?>

