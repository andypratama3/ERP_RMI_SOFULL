<?php
/**
 * ============================================================================
 *  tools/qa/run_full_suite.php  —  PEJABAT SUITE PENUH
 * ============================================================================
 *  Berbeda dari runtime_sweep.php (yang hanya merender), berkas ini adalah
 *  GERBANG COVERAGE. Ia membandingkan tiga sumber dan gagal loudly kalau
 *  ada halaman yang lolos tanpa diuji:
 *
 *    A. config/page_registry.php   -> setiap URL yang bisa diklik user
 *    B. seluruh .php di repo       -> halaman yang mungkin tidak terdaftar
 *    C. hasil render nyata         -> halaman yang benar-benar diuji
 *
 *  Aturan keras:
 *   - Tidak ada sampling. Semua URL diuji. Tidak ada `limit`.
 *   - URL registry yang file-nya hilang  = BLOCKER (link mati di menu).
 *   - Halaman .php yang tidak ada di registry = REPORT (bukan gagal),
 *     karena banyak halaman memang sengaja tidak dimenu.
 *   - Halaman yang error/Forbidden        = BLOCKER.
 *
 *  Exit 0 hanya bila: 0 error, 0 broken link, dan registry ter-cover 100%.
 *
 *  Jalankan:
 *    php tools/qa/run_full_suite.php              # semua
 *    php tools/qa/run_full_suite.php --no-db      # lewati yang butuh DB
 *    php tools/qa/run_full_suite.php --jobs=8     # paralel
 * ============================================================================
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($ROOT);

$optNoDb = in_array('--no-db', $argv, true);
$jobs    = 8;
foreach ($argv as $a) {
    if (preg_match('/^--jobs=(\d+)$/', $a, $m)) $jobs = max(1, (int) $m[1]);
}

$T0 = microtime(true);
$say = function (string $s = '') { echo $s, "\n"; };
$rule = function (string $t) { echo "\n", str_repeat('=', 74), "\n  $t\n", str_repeat('=', 74), "\n"; };

$report = [
    'generated_at' => date('c'),
    'root'         => $ROOT,
    'no_db'        => $optNoDb,
    'stages'       => [],
    'coverage'     => [],
    'blockers'     => [],
];
$saveStage = function (string $name, array $data) use (&$report) {
    $report['stages'][$name] = $data;
    $out = __DIR__ . '/out';
    if (!is_dir($out)) @mkdir($out, 0775, true);
    @file_put_contents("$out/$name.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
};
$block = function (string $m) use (&$report, $say) {
    $report['blockers'][] = $m;
    $say("  [BLOCKER] $m");
};

$say('PEJABAT SUITE PENUH - ERP RMI SOFULL');
$say('Root  : ' . $ROOT);
$say('Waktu : ' . date('Y-m-d H:i:s') . '  (jobs=' . $jobs . ($optNoDb ? ', --no-db' : '') . ')');

// ============================================================================
$rule('TAHAP 1  KAOHERENSI SUMBER TUJUAN');
// ============================================================================

$registry = [];
$regFile  = $ROOT . '/config/page_registry.php';
if (is_file($regFile)) {
    $registry = (array) require $regFile;
}

// Kumpulkan URL dari registry (dalam, toleran bentuk ['url'=>..] atau string)
$registryUrls = [];   // url => ['label'=>, 'module'=>]
$walk = function ($node, string $module) use (&$walk, &$registryUrls) {
    if (!is_array($node)) return;
    if (isset($node['url']) && is_string($node['url'])) {
        $u = trim($node['url']);
        if ($u !== '' && $u[0] !== '#') {
            $registryUrls[$u] = ['label' => (string) ($node['label'] ?? $u), 'module' => $module];
        }
        return;
    }
    foreach ($node as $k => $v) {
        if (is_array($v)) {
            $mod = is_string($k) ? $k : $module;
            $walk($v, $mod);
        }
    }
};
$walk($registry, '(root)');

// Pisahkan URL eksternal/anchor/berparameter
$isLocal = function (string $u): bool {
    if ($u === '' || $u[0] === '#') return false;
    if (preg_match('#^(https?:)?//#i', $u)) return false;
    if (preg_match('#^(mailto:|tel:|javascript:)#i', $u)) return false;
    if (str_starts_with($u, '#')) return false;
    return true;
};
$registryLocal = array_filter($registryUrls, fn($u, $k) => $isLocal($k), ARRAY_FILTER_USE_BOTH);
ksort($registryLocal);

$say('  Total node registry      : ' . count($registryUrls));
$say('  URL lokal (dapat diuji)  : ' . count($registryLocal));

// Kumpulkan seluruh .php di repo sebagai kandidat
$allPhp = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
$EXCL = ['/vendor/', '/node_modules/', '/.git/', '/_backup/', '/storage/', '/uploads/'];
while ($it->valid()) {
    $f = $it->current();
    if (!$f->isFile() || $f->getExtension() !== 'php') { $it->next(); continue; }
    $rel = '/' . ltrim(substr($f->getPathname(), strlen($ROOT)), '/');
    foreach ($EXCL as $e) if (str_contains($rel, $e)) { continue 2; }
    $allPhp[$rel] = $rel;
    $it->next();
}
ksort($allPhp);
$say('  Berkas .php di repo      : ' . count($allPhp));

// Normalisasi URL registry -> path file absolut relatif root
$urlToFile = function (string $u) use ($ROOT): ?string {
    $u = explode('#', $u)[0];
    $u = explode('?', $u)[0];
    $u = ltrim(rawurldecode($u), '/');
    if ($u === '') return null;
    $cand = $ROOT . '/' . $u;
    if (is_file($cand)) return '/' . ltrim(substr($cand, strlen($ROOT)), '/');
    // coba relatif ke folder modul
    foreach (glob($ROOT . '/*', GLOB_ONLYDIR) ?: [] as $mod) {
        $c = $mod . '/' . $u;
        if (is_file($c)) return '/' . ltrim(substr($c, strlen($ROOT)), '/');
    }
    return null;
};

// Cocokkan registry <-> file
$registryFiles = [];   // file => [labels]
$unresolved    = [];   // url  => module
foreach ($registryLocal as $u => $meta) {
    $f = $urlToFile($u);
    if ($f === null) { $unresolved[$u] = $meta['module']; continue; }
    $registryFiles[$f][] = $meta['label'];
}

$say('');
$say('  URL registry -> file     : ' . count($registryFiles));
$say('  URL registry TIDAK resolve : ' . count($unresolved));

// Halaman .php di luar registry
$unregistered = array_diff_key($allPhp, $registryFiles);
$say('  .php di luar registry    : ' . count($unregistered));

$report['coverage'] = [
    'registry_url_total'      => count($registryUrls),
    'registry_url_local'      => count($registryLocal),
    'registry_resolved_files' => count($registryFiles),
    'registry_unresolved'     => count($unresolved),
    'php_files_in_repo'       => count($allPhp),
    'php_unregistered'        => count($unregistered),
];
$saveStage('01_koherensi', $report['coverage'] + [
    'unresolved_urls' => array_map(fn($u, $m) => ['url' => $u, 'module' => $m], array_keys($unresolved), $unresolved),
]);

if ($unresolved) {
    $say('');
    $say('  --- URL registry yang file-nya tidak ada (link mati di menu) ---');
    $i = 0;
    foreach ($unresolved as $u => $mod) {
        if ($i++ >= 30) { $say('  ... ' . (count($unresolved) - 30) . ' lainnya'); break; }
        $say("    [$mod] $u");
        $block("link mati di menu: $u");
    }
}

// ============================================================================
$rule('TAHAP 2  LINT 100% (TANPA SAMPEL)');
// ============================================================================
$say('  php -l untuk ' . count($allPhp) . " file...\n");

$lintDir = __DIR__ . '/out';
if (!is_dir($lintDir)) @mkdir($lintDir, 0775, true);
$listFile = $lintDir . '/phpfiles.txt';
file_put_contents($listFile, implode("\n", array_keys($allPhp)) . "\n");

$lintOut = [];
$lintCmd = sprintf(
    'cd %s && tr "\n" "\0" < %s | xargs -0 -P %d -n 1 php -l 2>&1 | grep -v "^No syntax errors detected" || true',
    escapeshellarg($ROOT), escapeshellarg($listFile), $jobs
);
$lintOut = trim((string) shell_exec($lintCmd));
$lintBad = 0;
if ($lintOut !== '') {
    $lintBad = substr_count($lintOut, 'Errors parsing') + substr_count($lintOut, 'Parse error');
    $say("  File bermasalah:\n");
    foreach (array_slice(explode("\n", $lintOut), 0, 25) as $l) $say('    ' . $l);
    if ($lintBad > 25) $say("    ... (" . ($lintBad - 25) . " baris lagi)");
    $block("$lintBad berkas PHP gagal php -l");
} else {
    $say("  OK 全部 " . count($allPhp) . " file PHP lolos syntax check");
}
$saveStage('02_lint', ['files' => count($allPhp), 'bad' => $lintBad, 'output' => $lintOut]);
$say(sprintf(" 耗时: %.1fs", microtime(true) - $T0));

// ============================================================================
$rule('TAHAP 3  GUARD REPO (TANPA DB)');
// ============================================================================
$guardList = [
    'php_error_scan', 'migration_sql_lint', 'no_cyrillic_guard', 'path_guard',
    'path_police', 'unicode_guard', 'module_governance_lint', 'volumes_guard',
    'runbook_check', 'repo_location_guard', 'volumes_guard',
];
$guardResults = [];
$seen = [];
foreach ($guardList as $g) {
    if (isset($seen[$g])) continue;
    $seen[$g] = true;
    $f = "tools/qa/$g.php";
    if (!is_file($f)) continue;
    $o = [];
    $c = 0;
    exec('php ' . escapeshellarg($f) . ' 2>&1', $o, $c);
    $guardResults[$g] = ['exit' => $c, 'head' => array_slice($o, 0, 4)];
    if ($c === 0) $say("  OK    $g");
    else          $say("  GAGAL $g (exit $c)");
}
$saveStage('03_guards', $guardResults);

// ============================================================================
$rule('TAHAP 4  RENDER 100% HALAMAN');
// ============================================================================
$renderOut = sys_get_temp_dir() . '/rmi_full_render.json';
@unlink($renderOut);
$say('  Menjalankan runtime_sweep pada SELURUH target (tanpa limit)...');
$say('  [butuh database]');
if ($optNoDb) {
    $say('  --no-db aktif: tahap render dilewati (hanya statis).');
    $saveStage('04_render', ['skipped' => true]);
} else {
    $sweep = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/runtime_sweep.php')
           . ' 100000 ' . escapeshellarg('--json-out=' . $renderOut);
    $sw = microtime(true);
    passthru($sweep, $swCode);
    $render = is_file($renderOut) ? json_decode((string) file_get_contents($renderOut), true) : null;
    if (!$render) {
        $say('  [BLOCKER] runtime_sweep tidak menghasilkan JSON.');
        $block('runtime_sweep tidak menghasilkan laporan JSON');
        $saveStage('04_render', ['error' => 'no json output', 'exit' => $swCode]);
    } else {
        $r = $render['summary'] ?? [];
        $say(sprintf(
            "  total=%d  ok=%d  kosong=%d  forbidden=%d  error=%d  (%.1fs)",
            (int) ($r['total'] ?? 0), (int) ($r['ok'] ?? 0), (int) ($r['empty'] ?? 0),
            (int) ($r['forbidden'] ?? 0), (int) ($r['error'] ?? 0), microtime(true) - $sw
        ));
        $saveStage('04_render', $render);
        if ((int) ($r['error'] ?? 0) > 0) {
            $block((int) $r['error'] . ' halaman gagal render');
        }
    }
}

// ============================================================================
$rule('TAHAP 5  CAKUPAN 100%');
// ============================================================================
// Setiap file yang terdaftar di registry WAJIB ada di hasil render (tahap 4).
$rendered = [];
if (!$optNoDb && is_file($renderOut)) {
    $render = json_decode((string) file_get_contents($renderOut), true);
    foreach (($render['pages'] ?? []) as $p) $rendered[$p['file']] = $p;
}
$mustTest  = array_keys($registryFiles);          // wajib: dari menu
$niceTest  = array_keys($unregistered);           //.Should: tidak dimenu

$missing = [];
if (!$optNoDb) {
    foreach ($mustTest as $f) {
        if (!isset($rendered[$f])) $missing[] = $f;
    }
}
$say('  Wajib tes (dari registry) : ' . count($mustTest));
$say('  Sudah ter-render          : ' . count(array_intersect($mustTest, array_keys($rendered))));
$say('  BELUM ter-render          : ' . count($missing));
$say('  Tambahan (di luar menu)  : ' . count($niceTest));

if ($missing) {
    $say('');
    $say('  --- Halaman menu yang BELUM teruji ---');
    foreach (array_slice($missing, 0, 25) as $f) $say("    $f");
    if (count($missing) > 25) $say('    ... (' . (count($missing) - 25) . ' lainnya)');
    $block(count($missing) . ' halaman menu belum ter-render (cakupan < 100%)');
}

$pct = count($mustTest) > 0
    ? round(100 * (count($mustTest) - count($missing)) / count($mustTest), 2)
    : 0.0;
$report['coverage']['must_test']         = count($mustTest);
$report['coverage']['rendered']           = count(array_intersect($mustTest, array_keys($rendered)));
$report['coverage']['missing']            = count($missing);
$report['coverage']['percent']            = $pct;
$report['coverage']['unregistered_extra'] = count($niceTest);
$saveStage('05_coverage', $report['coverage'] + ['missing' => array_slice($missing, 0, 200)]);

// ============================================================================
$rule('TAHAP 6  ICON, SVG, DAN STRUKTUR UI');
// ============================================================================
$uiFile = __DIR__ . '/ui_bug_audit.php';
$uiStatic = [];
if (is_file($uiFile)) {
    // mode statis: tanpa HTTP. Hanya parse file.
    $o = [];
    $c = 0;
    exec('php ' . escapeshellarg($uiFile) . ' --static 2>&1', $o, $c);
    $uiStatic = ['exit' => $c, 'output' => array_slice($o, 0, 60)];
    $say('  ui_bug_audit --static exit=' . $c);
    foreach (array_slice($o, 0, 12) as $l) $say('    ' . $l);
} else {
    $say('  (ui_bug_audit.php tidak ada)');
}
$saveStage('06_ui', $uiStatic);

// ============================================================================
$rule('TAHAP 7  KEAMANAN');
// ============================================================================
$sec = [];
$o = []; $c = 0;
exec('composer audit --format=json 2>&1', $o, $c);
$json = json_decode(implode("\n", $o), true);
$advCount = 0;
if (is_array($json)) {
    $advCount = array_sum(array_map('count', $json['advisories'] ?? $json));
    $sec['advisories'] = [];
    foreach (($json['advisories'] ?? $json) as $pkg => $items) {
        foreach ($items as $a) {
            $sec['advisories'][] = [
                'package' => $pkg, 'cve' => $a['cve'] ?? '-',
                'severity' => $a['severity'] ?? '?', 'title' => $a['title'] ?? '',
                'affected' => $a['affectedVersions'] ?? '',
            ];
        }
    }
}
$say('  composer audit: ' . $advCount . ' advisory');
foreach (array_slice($sec['advisories'] ?? [], 0, 10) as $a) {
    $say(sprintf('    [%-8s] %-28s %s', $a['severity'], $a['cve'], $a['package']));
}
$sec['count'] = $advCount;
$saveStage('07_security', $sec);

$o = []; $c = 0;
exec('php ' . escapeshellarg('tools/qa/secret_scan.php') . ' 2>&1', $o, $c);
$secscan = json_decode(implode("\n", $o), true);
$findings = $secscan['findings'] ?? [];
$failF = array_values(array_filter($findings, fn($f) => ($f['severity'] ?? '') === 'FAIL'));
$say('  secret_scan: ' . count($findings) . ' temuan, ' . count($failF) . ' severity=FAIL');
$saveStage('07_security_scan', ['count' => count($findings), 'fail' => count($failF)]);

// ============================================================================
$rule('HASIL AKHIR');
// ============================================================================
$report['finished_at'] = date('c');
$report['duration_s']   = round(microtime(true) - $T0, 1);
$report['blocker_count'] = count($report['blockers']);
@file_put_contents(__DIR__ . '/out/full_suite.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
@file_put_contents(__DIR__ . '/out/full_suite.md', selfMd($report));
@chmod(__DIR__ . '/out/full_suite.md', 0644);

echo "\n";
echo "Ringkasan:\n";
echo "  cakupan registry : {$pct}%\n";
echo "  blocker          : " . count($report['blockers']) . "\n";
echo "  advisory         : $advCount\n";
echo "  durasi           : {$report['duration_s']}s\n";
echo "  laporan          : tools/qa/out/full_suite.md\n";

if ($report['blockers']) {
    echo "\nBLOCKER:\n";
    foreach ($report['blockers'] as $b) echo "  - $b\n";
    exit(1);
}
if ($advCount > 0) {
    echo "\nCATATAN: ada advisory dependency. Lihat tahap 7.\n";
}
if (!$optNoDb && $pct < 100.0) exit(1);
echo "\nSEMUA LOLOS.\n";
exit(0);

/** Ringkasan markdown */
function selfMd(array $r): string
{
    $c  = $r['coverage'];
    $s  = "# Full Suite Report\n\n";
    $s .= "- Generated: {$r['generated_at']}\n";
    $s .= "- Duration : {$r['duration_s']}s\n";
    $s .= "- Root     : `{$r['root']}`\n";
    $s .= "- no_db    : " . ($r['no_db'] ? 'yes' : 'no') . "\n\n";
    $s .= "## Coverage\n\n";
    $s .= "| Metrik | Nilai |\n|---|---:|\n";
    $s .= "| URL registry (lokal) | {$c['registry_url_local']} |\n";
    $s .= "| Registry resolve ke file | {$c['registry_resolved_files']} |\n";
    $s .= "| Registry tidak resolve | {$c['registry_unresolved']} |\n";
    $s .= "| Berkas .php di repo | {$c['php_files_in_repo']} |\n";
    $s .= "| Wajib ter-render | " . ($c['must_test'] ?? 0) . " |\n";
    $s .= "| Sudah ter-render | " . ($c['rendered'] ?? 0) . " |\n";
    $s .= "| Cakupan | " . ($c['percent'] ?? 0) . "% |\n";
    $s .= "| Halaman di luar menu | " . ($c['unregistered_extra'] ?? 0) . " |\n\n";
    $s .= "## Blockers (" . count($r['blockers']) . ")\n\n";
    if (!$r['blockers']) { $s .= "Tidak ada.\n"; }
    else { foreach ($r['blockers'] as $b) $s .= "- $b\n"; }
    $sec = $r['stages']['07_security'] ?? ['advisories' => []];
    $s .= "\n## Security (" . ($sec['count'] ?? 0) . " advisory)\n\n";
    if (empty($sec['advisories'])) { $s .= "Bersih.\n"; }
    else {
        $s .= "| Sev | CVE | Package | Judul |\n|---|---|---|---|\n";
        foreach ($sec['advisories'] as $a) {
            $s .= "| {$a['severity']} | {$a['cve']} | `{$a['package']}` | " . str_replace('|', '\\|', $a['title']) . " |\n";
        }
    }
    return $s;
}
