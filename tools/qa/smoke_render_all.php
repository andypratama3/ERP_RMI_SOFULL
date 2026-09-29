<?php
/**
 * Smoke render massal: menyertakan tiap halaman di dalam proses terpisah agar
 * fatal error tidak mematikan runner, lalu laporkan halaman yang bermasalah.
 *
 * Jalankan:  php tools/qa/smoke_render_all.php [jumlah] [regex-filter]
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$limit = (int) ($argv[1] ?? 400);
$filter = $argv[2] ?? '';

$skipDirs = ['/vendor/', '/node_modules/', '/_backup/', '/.git/', '/tools/diagrams/', '/tools/ops/'];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $p = $f->getPathname();
    if (in_array($p, [__FILE__, $root . '/tools/uat_smoke.php'], true)) continue;
    $rel = substr($p, strlen($root));
    foreach ($skipDirs as $sd) { if (strpos($rel, $sd) !== false) { continue 2; } }
    $base = $f->getBasename();
    if (strpos($base, '__') !== false || strpos($base, '_debug') !== false) continue;
    if (preg_match('/(login|auth|config-db|setup|install|seed|cron|migrat|logout)\.php$/', $base)) continue;
    $files[] = $rel;
}
sort($files);
if ($filter !== '') $files = array_values(array_filter($files, fn($x) => preg_match($filter, $x)));
$total = count($files);
$files = array_slice($files, 0, $limit);

// Sesi login supaya halaman ber-RBAC tidak hanya redirect.
$tmpSess = sys_get_temp_dir() . '/rmi_smoke_sess';
@mkdir($tmpSess, 0775, true);
ini_set('session.save_path', $tmpSess);
session_name('RMISMOKE' . getmypid());
@session_start();
$_SESSION['user'] = [
    'username' => 'superadmin', 'name' => 'Super Admin', 'role' => 'SUPERADMIN',
    'employee_code' => 'ADM001', 'department' => 'IT', 'office' => 'TGR',
];
$_SESSION['logged_in'] = true;
$_SESSION['login_time'] = time();

$fail = [];
$clean = 0;
$empty = 0;

foreach ($files as $rel) {
    $abs = $root . '/' . ltrim($rel, '/');
    $code = <<<'PHP'
$__f = $argv[1];
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($str, 0, $no, $file, $line);
});
try { include $__f; } catch (Throwable $e) { throw $e; }
PHP;
    $tmp = $tmpSess . '/_inc_' . getmypid() . '.php';
    file_put_contents($tmp, $code);
    $out = shell_exec(
        'APP_ROOT=' . escapeshellarg($root) . ' php -d display_errors=1 -d error_reporting=E_ALL ' .
        escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($abs) . ' 2>&1'
    );
    @unlink($tmp);
    $out = (string) $out;
    if (preg_match('/(Fatal error|Parse error|Call to undefined|Uncaught|Compile error|Allowed memory size)/i', $out, $m)) {
        $detail = trim(preg_replace('/\s+/', ' ', substr($out, 0, 400)) ?? '');
        $fail[] = [$rel, $detail];
        continue;
    }
    if (preg_match('/(Warning|Notice|Deprecated):/i', $out, $m)) {
        $detail = trim(preg_replace('/\s+/', ' ', substr($out, 0, 300)) ?? '');
        $fail[] = [$rel, '[warn] ' . $detail];
        continue;
    }
    if (trim(strip_tags($out)) === '') $empty++; else $clean++;
}

echo "Halaman diperiksa : $total (dirender $clean, kosong $empty)\n";
echo "Bermasalah        : " . count($fail) . "\n";
foreach (array_slice($fail, 0, 60) as [$rel, $d]) echo "  - $rel\n      $d\n";
exit(count($fail) > 0 ? 1 : 0);
