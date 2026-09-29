<?php
/**
 * Audit bug UI di atas HTML yang benar-benar dirender lewat HTTP.
 * (Versi include-based tidak valid: banyak halaman bergantung pada
 *  $_SERVER / router / session, sehingga hasilnya berbeda dari production.)
 *
 * Jalankan:
 *   php tools/qa/ui_bug_audit.php [jumlah] [regex-filter]
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$limit = (int) ($argv[1] ?? 200);
$filter = $argv[2] ?? '';
$port = random_int(8800, 8899);

$skipDirs = ['/vendor/', '/node_modules/', '/_backup/', '/.git/', '/tools/'];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $rel = substr($f->getPathname(), strlen($root));
    foreach ($skipDirs as $sd) { if (strpos($rel, $sd) !== false) continue 2; }
    $base = $f->getBasename();
    if (strpos($base, '__') !== false || strpos($base, '_debug') !== false) continue;
    if (preg_match('#/(?:_inc|inc|partials?|includes?)/#', $rel)) continue;
    if (preg_match('#/(?:helpers?|lib|_lib|common|bootstrap|config|db)\.php$#', $rel)) continue;
    if (preg_match('/(login|auth|logout|setup|install|seed|cron|migrat|config-db)\.php$/', $base)) continue;
    $files[] = $rel;
}
sort($files);
if ($filter !== '') {
    $rx = '/' . str_replace('/', '\\/', $filter) . '/';
    $files = array_values(array_filter($files, fn($x) => (bool) preg_match($rx, $x)));
}
$files = array_slice($files, 0, $limit);

$jar = tempnam(sys_get_temp_dir(), 'uia');
$pid = null;
$server = @proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root],
    [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
    $pipes
);
if (!is_resource($server)) { fwrite(STDERR, "gagal start server\n"); exit(2); }
usleep(700000);

// Login sungguhan (POST + CSRF). Tanpa ini semua halaman ber-RBAC hanya
// redirect ke login.php dan kita salah mengukur isi halaman login berulang kali.
$loginPage = "http://127.0.0.1:$port/master/login.php";
$ch = curl_init($loginPage);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20]);
$loginHtml = (string) curl_exec($ch);
curl_close($ch);
$csrf = '';
if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $loginHtml, $m)) $csrf = $m[1];

$user = getenv('RMI_AUDIT_USER') ?: 'superadmin';
$pass = getenv('RMI_AUDIT_PASS') ?: 'RMI2026';
$ch = curl_init($loginPage);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20,
    CURLOPT_POSTFIELDS => http_build_query(['_csrf' => $csrf, 'username' => $user, 'password' => $pass]),
]);
curl_exec($ch);
curl_close($ch);

// Verifikasi sesi benar-benar aktif, bukan diam-diam gagal login.
$ch = curl_init("http://127.0.0.1:$port/master/master_data.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20]);
$probe = (string) curl_exec($ch); curl_close($ch);
if (strpos($probe, 'l_password') !== false || stripos($probe, 'password') !== false && strpos($probe, 'login.php') !== false) {
    fwrite(STDERR, "GAGAL LOGIN sebagai '$user' — set RMI_AUDIT_USER / RMI_AUDIT_PASS\n");
    proc_terminate($server); proc_close($server); exit(3);
}

$totals = []; $detail = []; $pages = 0; $errs = [];
function bump(string $k, string $where, string $msg): void {
    global $totals, $detail;
    $totals[$k] = ($totals[$k] ?? 0) + 1;
    $detail[$k][] = "$where — $msg";
}

foreach ($files as $rel) {
    $url = "http://127.0.0.1:$port/" . ltrim($rel, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => true,
    ]);
    $html = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (preg_match('/(Fatal error|Parse error|Call to undefined|Uncaught|Compile error|Allowed memory size)/i', $html, $m)) {
        $errs[] = "$rel :: " . trim(preg_replace('/\s+/', ' ', substr($html, 0, 220)));
        continue;
    }
    if ($code !== 200 || trim($html) === '') continue;
    $pages++;
    $body = $html;

    // --- SVG ---
    if (preg_match_all('/&lt;svg/i', $body, $m)) bump('svg_escape_ke_layar', $rel, count($m[0]) . ' SVG tampil sebagai teks');
    if (preg_match_all('/<svg(?![^>]*viewBox)[^>]*>/i', $body, $m)) bump('svg_tanpa_viewbox', $rel, count($m[0]) . ' SVG tanpa viewBox');
    if (preg_match_all('/<svg[^>]*>\s*<\/svg>/i', $body, $m)) bump('svg_kosong', $rel, count($m[0]) . ' SVG kosong');
    if (preg_match_all('/<svg[^>]*(width="0"|height="0")/i', $body, $m)) bump('svg_ukuran_nol', $rel, count($m[0]) . ' SVG 0px');
    if (preg_match_all('/<svg[^>]*stroke="#[0-9a-fA-F]{3,6}"/i', $body, $m)) bump('svg_warna_hardcode', $rel, count($m[0]) . ' SVG stroke hex (patah di light mode)');
    // hanya count svg yang DI LUAR atribut data-*
    $vis = preg_replace('/data-icon-(?:dark|light)="[^"]*"/i', '', $body);
    if (preg_match_all('/<svg/i', $vis, $m)) $svgVisible = count($m[0]);

    // --- struktur ---
    preg_match_all('/\sid="([^"]+)"/i', $body, $m);
    $ids = array_map('strtolower', $m[1]);
    $dup = array_keys(array_filter(array_count_values($ids), fn($c) => $c > 1));
    if ($dup) bump('id_ganda', $rel, implode(', ', array_slice($dup, 0, 4)));

    // --- aksesibilitas ---
    if (preg_match_all('/<button\b(?![^>]*(aria-label|aria-labelledby))[^>]*>\s*<svg\b[^>]*>(?:(?!<\/svg>).)*<\/svg>\s*<\/button>/is', $body, $m)) {
        bump('tombol_ikon_tanpa_label', $rel, count($m[0]) . ' tombol ikon tanpa aria-label');
    }
    if (preg_match_all('/<a\b(?![^>]*(aria-label|title))[^>]*>\s*<svg\b[^>]*>(?:(?!<\/svg>).)*<\/svg>\s*<\/a>/is', $body, $m)) {
        bump('link_ikon_tanpa_label', $rel, count($m[0]) . ' link ikon tanpa aria-label/title');
    }
    if (preg_match_all('/<img\b(?![^>]*\balt=)[^>]*>/i', $body, $m)) bump('img_tanpa_alt', $rel, count($m[0]) . ' <img> tanpa alt');
    if (preg_match_all('/<img\b(?![^>]*\bsrc=)[^>]*>/i', $body, $m)) bump('img_tanpa_src', $rel, count($m[0]) . ' <img> tanpa src');

    // --- kontras / warna ---
    if (preg_match_all('/color\s*:\s*(?:#(?:0[0-9a-f]|1[0-9a-f])[0-9a-f]{0,4}\b|rgba?\(\s*0\s*,\s*0\s*,\s*0)/i', $body, $m)) {
        bump('warna_gelap_hardcode', $rel, count($m[0]) . ' deklarasi warna hampir hitam (cek light mode)');
    }
    if (preg_match('/background(?:-color)?\s*:\s*(?:#fff\b|white\b)[^;}]*;[^;}]*color\s*:\s*(?:#fff\b|white\b)/i', $body)) {
        bump('teks_sama_dengan_latar', $rel, 'background putih + teks putih');
    }
    if (preg_match_all('/<hr\b(?![^>]*style)[^>]*>/i', $body, $m)) {
        $s = 0;
        foreach ($m[0] as $tag) if (!preg_match('/class="[^"]*\b(border|hr-)/i', $tag)) $s++;
        if ($s) bump('hr_tanpa_border', $rel, "$s <hr> tanpa garis eksplisit");
    }

    // --- form ---
    if (preg_match_all('/<(input|select|textarea)\b(?![^>]*(aria-label|aria-labelledby|\bid=))[^>]*>/i', $body, $m)) {
        $c = count($m[0]);
        if ($c > 5) bump('form_tanpa_label', $rel, "$c kontrol tanpa aria-label/id");
    }
    if (preg_match_all('/<label\b(?![^>]*\bfor=)[^>]*>/i', $body, $m)) {
        $c = count($m[0]);
        if ($c > 5) bump('label_tanpa_for', $rel, "$c <label> tanpa for=");
    }
    if (preg_match_all('/autofocus/i', $body, $m)) bump('autofocus', $rel, count($m[0]) . ' autofocus (bisa merebut fokus)');

    // --- emoji hardcode yang lolos ---
    if (preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $body, $m)) {
        $u = array_values(array_unique($m[0]));
        $ctx = [];
        foreach (array_slice($m[0], 0, 3) as $ch) {
            $pos = mb_strpos($body, $ch);
            $ctx[] = trim(preg_replace('/\s+/', ' ', mb_substr($body, max(0, $pos - 90), 130)) ?? '');
        }
        bump('emoji_hardcode', $rel, count($m[0]) . ' emoji [' . implode(' ', $u) . '] ctx: ' . mb_substr($ctx[0] ?? '', 0, 130));
    }

    // --- css hygiene ---
    if (preg_match_all('/z-index\s*:\s*(\d{4,})/i', $body, $m)) {
        foreach ($m[1] as $z) if ((int)$z > 20000) { bump('zindex_raksasa', $rel, "z-index $z"); break; }
    }
    if (preg_match_all('/!important/i', $body, $m) && count($m[0]) > 150) {
        bump('important_membanjir', $rel, count($m[0]) . ' !important');
    }
    if (preg_match('/overflow\s*:\s*hidden[\s\S]{0,80}<\?php|<div[^>]*style="[^"]*overflow:\s*hidden[^"]*"[^>]*>\s*<form/i', $body)) {
        bump('form_terpotong', $rel, 'indikasi form di dalam overflow:hidden');
    }
}
proc_terminate($server); proc_close($server);
@unlink($jar);

ksort($totals);
echo "Halaman HTTP 200  : $pages dari " . count($files) . " kandidat\n";
echo "Fatal/parse error : " . count($errs) . "\n";
foreach (array_slice($errs, 0, 10) as $e) echo "   ! $e\n";
echo "Total temuan UI  : " . array_sum($totals) . "\n\n";
if (!$totals && !$errs) { echo "BERSIH — tidak ada bug UI terdeteksi\n"; exit(0); }
foreach ($totals as $k => $n) {
    echo strtoupper($k) . ": $n\n";
    foreach (array_slice(array_values(array_unique($detail[$k])), 0, 6) as $d) echo "    $d\n";
    echo "\n";
}
exit(0);
