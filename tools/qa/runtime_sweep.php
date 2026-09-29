<?php
/**
 * Sapuan runtime: benar-benar mengeksekusi setiap halaman di dalam proses
 * terpisah dan menangkap exception (SQL/PDO/fatal). Ini不同于 analisis statis
 * — hasilnya otoritatif soal halaman mana yang benar-benar mati.
 *
 * Jalankan: php tools/qa/runtime_sweep.php [jumlah] [regex-filter]
 *           php tools/qa/runtime_sweep.php --json-out=/path/laporan.json
 *
 * Argumen posisi (jumlah, regex-filter) tetap didukung supaya pemanggilan
 * lama tidak rusak. Opsi --json-out menulis laporan untuk run_full_suite.php:
 * tanpa ini suite menganggap tahap render gagal karena tidak ada JSON.
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);

$limit    = 200;
$filter   = '';
$jsonOut  = '';
$position = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--json-out=(.+)$/', $a, $m)) { $jsonOut = $m[1]; continue; }
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) { $limit = (int) $m[1]; continue; }
    if (preg_match('/^--filter=(.*)$/', $a, $m)) { $filter = $m[1]; continue; }
    if (strpos($a, '-') === 0) continue;   // opsi lain milik pemanggil, abaikan
    $position[] = $a;
}
if (isset($position[0])) $limit  = (int) $position[0];
if (isset($position[1])) $filter = (string) $position[1];
if (isset($position[0]) && $jsonOut === '' && count($position) > 1) {
    fwrite(STDERR, "CATATAN: '{$position[1]}' diperlakukan sebagai regex-filter. "
        . "Untuk menulis JSON pakai --json-out=<path>.\n");
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $rel = substr($f->getPathname(), strlen($root));
    foreach (['/vendor/', '/node_modules/', '/_backup/', '/.git/', '/tools/', '/tests/'] as $sd) {
        if (strpos($rel, $sd) !== false) continue 2;
    }
    $base = $f->getBasename();
    if (strpos($base, '__') !== false || strpos($base, '_debug') !== false) continue;
    if (preg_match('#/(?:_inc|inc|partials?|includes?)/#', $rel)) continue;
    if (preg_match('#/(?:helpers?|lib|_lib|common|bootstrap|config|db)\.php$#', $rel)) continue;
    if (preg_match('/^(login|logout|setup|install|seed|cron|run_migration|migrat|config-db)\.php$/', $base)) continue;
    if (preg_match('#^/api/#', $rel)) continue;   // endpoint JSON, bukan halaman UI
    $files[] = $rel;
}
sort($files);
if ($filter !== '') {
    $rx = '/' . str_replace('/', '\\/', $filter) . '/';
    $files = array_values(array_filter($files, fn($x) => (bool) preg_match($rx, $x)));
}
$totalAll = count($files);
$files = array_slice($files, 0, $limit);

$sessDir = sys_get_temp_dir() . '/rmi_sweep_' . getmypid();
@mkdir($sessDir, 0775, true);
ini_set('session.save_path', $sessDir);

// Harness: sesi SYS (agar scope_department tidak menolak), include sekali, tangkap semua error.
$harness = $sessDir . '/_harness.php';
// WAJIB session_start() SEBELUM isi $_SESSION. Tanpa ini auth.php memicu
// loop login yang menghasilkan belasan MB output dan harness mati sebelum
// menulis hasil — sehingga halaman yang error tersembunyi sebagai "sukses".
file_put_contents($harness, <<<'H'
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$__rel = $argv[1];
$_SERVER['SCRIPT_NAME']    = '/' . $__rel;
$_SERVER['REQUEST_URI']    = '/' . $__rel;
$_SERVER['PHP_SELF']       = '/' . $__rel;
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$__sd = sys_get_temp_dir() . '/rmi_sweep_sess';
@mkdir($__sd, 0775, true);
ini_set('session.save_path', $__sd);
session_name('RS' . getmypid());
@session_start();
$_SESSION['user'] = [
    'username' => 'superadmin', 'name' => 'Super Admin', 'full_name' => 'Super Admin',
    'role' => 'SYS', 'level' => 'SYS', 'employee_code' => 'ADM001',
    'department' => 'SYS', 'office' => 'TGR', 'office_code' => 'TGR',
];
$_SESSION['role'] = 'SYS'; $_SESSION['dept'] = 'SYS'; $_SESSION['level'] = 'SYS';
$_SESSION['office'] = 'TGR';
$_SESSION['logged_in'] = true; $_SESSION['login_time'] = time();

$__err = null;
set_error_handler(function ($no, $str, $file, $line) {
    fwrite(STDERR, 'PHPWARN|' . $str . ' @' . basename((string) $file) . ':' . $line . "\n");
    return true;
});
ob_start();
try {
    include $__rel;
} catch (Throwable $e) {
    $__err = get_class($e) . ': ' . $e->getMessage();
}
$__out = (string) ob_get_clean();
if ($__err === null && preg_match('/(Fatal error|Parse error|Call to undefined|Allowed memory size)/i', $__out, $m)) {
    $__err = 'BUILTIN: ' . trim(preg_replace('/\s+/', ' ', $m[0]));
}
@file_put_contents($argv[2], json_encode([
    'len'      => strlen($__out),
    'err'      => $__err,
    'forbidden'=> stripos($__out, 'USER_ACTIVE_PERM_LEVEL_METHOD_BLOCK') !== false,
], JSON_UNESCAPED_UNICODE));
H);

$errors = [];   // [file, error]
$warns   = [];   // file => jumlah warning
$ok      = 0; $empty = 0; $forbidden = 0;
$pages   = [];   // laporan per halaman untuk --json-out

foreach ($files as $rel) {
    $abs = $root . '/' . ltrim($rel, '/');
    $errFile = $sessDir . '/_err.txt';
    $resFile = $sessDir . '/_res.json';
    @unlink($resFile);
    // Hanya boleh SATU php. Jika ada `php -d ... /path/php harness.php`,
    // PHP memperlakukan /path/php sebagai SKRIP dan membocorkan 13 MB binary
    // Mach-O ke stdout, sedangkan harness tidak pernah jalan.
    $cmd = 'APP_ROOT=' . escapeshellarg($root)
         . ' ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL '
         . escapeshellarg($harness) . ' ' . escapeshellarg($abs)
         . ' ' . escapeshellarg($resFile)
         . ' 2> ' . escapeshellarg($errFile);
    $out = (string) shell_exec($cmd);
    $errOut = (string) @file_get_contents($errFile);
    @unlink($errFile);
    if (getenv('RMI_SWEEP_DEBUG')) {
        fwrite(STDERR, "CMD: $cmd\n");
        fwrite(STDERR, 'harness bytes: ' . (is_file($harness) ? filesize($harness) : 'MISSING') . "\n");
        fwrite(STDERR, 'stdout bytes: ' . strlen($out) . '  resFile: ' . (is_file($resFile) ? 'ada' : 'MISSING') . "\n");
        fwrite(STDERR, 'stdout 8b: ' . bin2hex(substr($out, 0, 8)) . "\n");
        fwrite(STDERR, "----\n");
    }

    $res = null;
    if (is_file($resFile)) {
        $res = json_decode((string) @file_get_contents($resFile), true);
        @unlink($resFile);
    }

    $status = 'ok'; $pageError = null;
    if (!$res) {
        // Harness tidak melaporkan apa pun: JANGAN dianggap sukses. Ini yang
        // membuat halaman mati tersembunyi sebelumnya.
        $pageError = 'HARNESS-GAGAL: ' . substr(trim(preg_replace('/\s+/', ' ',
            $errOut . ' ' . $out)), 0, 160);
        $errors[] = [$rel, $pageError];
        $status = 'error';
    } elseif (!empty($res['err'])) {
        $pageError = (string) $res['err'];
        $errors[] = [$rel, $pageError];
        $status = 'error';
    } elseif (!empty($res['forbidden'])) {
        $forbidden++;
        $status = 'forbidden';
    } elseif ((int) $res['len'] === 0) {
        $empty++;
        $status = 'empty';
    } else {
        $ok++;
    }

    $n = preg_match_all('/^PHPWARN\|(.+)$/m', $errOut, $wm);
    if ($n) {
        $warns[$rel] = $wm[1];
    }

    if ($jsonOut !== '') {
        $pages[] = [
            'file'      => ltrim($rel, '/'),
            'status'    => $status,
            'len'       => (int) ($res['len'] ?? 0),
            'warn'      => $n,
            'error'     => $pageError,
        ];
    }
}
@unlink($harness);

echo "Total halaman kandidat : $totalAll (dieksekusi " . count($files) . ")\n";
echo "Berhasil render        : $ok\n";
echo "Kosong                 : $empty\n";
echo "Ditolak (scope/perm)  : $forbidden\n";
echo "ERROR                  : " . count($errors) . "\n\n";

if ($errors) {
    echo "=== HALAMAN MATI ===\n";
    usort($errors, fn($a, $b) => strcmp($a[0], $b[0]));
    foreach ($errors as [$rel, $e]) {
        printf("  %-58s %s\n", $rel, substr($e, 0, 130));
    }
    echo "\n";
}
if ($warns) {
    echo "=== WARNING (PHP Notice/Warning/Deprecated) : " . count($warns) . " file ===\n";
    ksort($warns);
    $i = 0;
    foreach ($warns as $rel => $list) {
        if ($i++ >= 25) { echo "  ... (" . (count($warns) - 25) . " file lagi)\n"; break; }
        $uniq = array_slice(array_values(array_unique($list)), 0, 3);
        printf("  %-52s x%d  %s\n", $rel, count($list), substr(implode(' | ', $uniq), 0, 110));
    }
}

if ($jsonOut !== '') {
    $summary = [
        'total'     => count($files),
        'candidate' => $totalAll,
        'ok'        => $ok,
        'empty'     => $empty,
        'forbidden' => $forbidden,
        'error'     => count($errors),
        'warn_files'=> count($warns),
        'generated' => date('c'),
    ];
    $written = @file_put_contents($jsonOut, json_encode(
        ['summary' => $summary, 'pages' => $pages],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    if ($written === false) {
        fwrite(STDERR, "GAGAL menulis laporan ke $jsonOut (periksa izin direktori)\n");
        exit(2);
    }
    echo "\nLaporan JSON  : $jsonOut (" . count($pages) . " halaman)\n";
}

exit($errors ? 1 : 0);
