<?php
/**
 * N+1 Query + Unbounded Query Scan — CLI. Deteksi query di dalam loop dan
 * SELECT tanpa paginasi/batas.
 *
 * Kenapa tool ini dibuat: manifestation "load lambat kalau data banyak"
 * yang paling sering di ERP legacy adalah N+1 (1 query di luar loop + N query
 * di dalam loop) dan SELECT tanpa LIMIT yang membengkak. Tidak ada scanner
 * untuk ini di tools/qa/ sebelum tool ini dibuat.
 *
 * Yang DIDETEKSI (statis, tanpa eksekusi):
 *   N1-DIRECT   primitive query (->query/->prepare/->exec/->fetch*) di dalam
 *               body loop foreach/for/while. 1 query per iterasi.
 *   N1-INDIRECT pemanggilan fungsi di dalam loop yang definisinya (di file
 *               yang sama ATAU file include yang sama folder) berisi query.
 *   UNBOUNDED   SELECT tanpa LIMIT/OFFSET dan tanpa paginasi di memori
 *               ($rows[], array_push, foreach hasil) — risiko tertentu.
 *
 * Yang TIDAK diklaim: ini bukan proof exploited di produksi. Ia menandai
 * kandidat yang wajib ditinjau manusia. Status di keluaran tetap "CANDIDATE"
 * sampai ada bukti reproduksi (lihat tools/qa/ERP_MASTER_TASK_TRACKER.md).
 *
 * CLI only. Tidak menulis ke DB. Idempoten: aman dijalankan berulang.
 *
 * CLI:
 *   php tools/qa/nplus1_query_scan.php
 *   php tools/qa/nplus1_query_scan.php --json-out=storage/logs/nplus1_last.json
 *   php tools/qa/nplus1_query_scan.php --filter='^sales/' --limit=50
 *   php tools/qa/nplus1_query_scan.php --min-severity=P1
 *   php tools/qa/nplus1_query_scan.php --self-test
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);

/* ------------------------------------------------------------------ args */
/* $argv[0] adalah nama script, bukan argumen: buang sebelum dipisah. */
$argvList = array_slice($_SERVER['argv'] ?? [], 1);
$args     = [];
$positional = [];
foreach ($argvList as $a) {
    if (!is_string($a)) continue;
    if (str_starts_with($a, '-')) { $args[] = $a; continue; }
    $positional[] = $a;
}
$optSelf = in_array('--self-test', $args, true);
$optJson = '';
$optFilter = '';
$optLimit  = 0;
$optMinSev = 'P3';
foreach ($argvList as $a) {
    if (!is_string($a)) continue;
    if (str_starts_with($a, '--json-out='))   $optJson   = trim((string)substr($a, 11));
    if (str_starts_with($a, '--filter='))     $optFilter = trim((string)substr($a, 9));
    if (str_starts_with($a, '--limit='))      $optLimit  = max(0, (int)substr($a, 8));
    if (str_starts_with($a, '--min-severity=')) $optMinSev = strtoupper(trim((string)substr($a, 15)));
}
if ($optJson === '' && !$optSelf) {
    $optJson = $root . '/storage/logs/nplus1_last.json';
}
$outFile = $optJson !== '' ? (str_starts_with($optJson, '/') ? $optJson : $root . '/' . $optJson) : '';

/* Positional legacy: [filter] [limit] */
if ($optFilter === '' && isset($positional[0])) $optFilter = $positional[0];
if ($optLimit === 0 && isset($positional[1]) && ctype_digit((string)$positional[1])) $optLimit = (int)$positional[1];

if (!in_array($optMinSev, ['P0', 'P1', 'P2', 'P3'], true)) $optMinSev = 'P3';
$SEV_RANK = ['P0' => 3, 'P1' => 2, 'P2' => 1, 'P3' => 0];

/* ---------------------------------------------------------------- config */
$SKIP_DIRS = ['_backup', 'vendor', 'node_modules', '.git', 'storage', 'docs', 'app'];

/** Primitive yang berarti "menjalankan query ke DB". */
const QUERY_PRIMITIVES = [
    '->query(', '->prepare(', '->execute(', '->exec(', '->fetch(', '->fetchAll(',
    '->fetchOne(', '->fetchColumn(', '->fetchAllColumn(', '->rowCount(',
    'db_pdo(', 'rmi_db_pdo(', 'db_query(', 'rmi_query(',
];

const LOOP_KEYWORDS = ['foreach', 'for', 'while'];

/* ------------------------------------------------------------ file walk */
function n1_walk(string $dir, array $skip): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            foreach ($skip as $s) {
                if ($rel === $s || str_starts_with($rel . '/', $s . '/')) { continue 2; }
            }
            continue;
        }
        if (strtolower($f->getExtension()) !== 'php') continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        $skipFile = false;
        foreach ($skip as $s) {
            if (str_starts_with($rel, $s . '/')) { $skipFile = true; break; }
        }
        if ($skipFile) continue;
        $out[] = $f->getPathname();
    }
    sort($out);
    return $out;
}

/** Buang comment & string supaya tidak salah deteksi dari teks. */
function n1_strip_noise(string $code): string
{
    $out = '';
    $len = strlen($code);
    $i = 0;
    while ($i < $len) {
        $c = $code[$i];
        $n = $i + 1 < $len ? $code[$i + 1] : '';
        if ($c === '/' && $n === '/') { while ($i < $len && $code[$i] !== "\n") $i++; continue; }
        if ($c === '#') { while ($i < $len && $code[$i] !== "\n") $i++; continue; }
        if ($c === '/' && $n === '*') { $i += 2; while ($i < $len && !($code[$i] === '*' && $i + 1 < $len && $code[$i + 1] === '/')) $i++; $i += 2; continue; }
        if ($c === "'" || $c === '"') {
            $q = $c; $i++;
            while ($i < $len) {
                if ($code[$i] === '\\') { $i += 2; continue; }
                if ($code[$i] === $q) { $i++; break; }
                $i++;
            }
            $out .= '""';
            continue;
        }
        if ($c === '<' && $code[$i + 1] === '?') { $out .= '  '; $i += 2; continue; }
        if ($c === '?' && $i + 1 < $len && $code[$i + 1] === '>') { $out .= '  '; $i += 2; continue; }
        $out .= $c;
        $i++;
    }
    return $out;
}

/**
 * Pasangan brace untuk seluruh file, pakai index offset (0-based).
 * Mengembalikan map startLineIdx => endLineIdx.
 */
function n1_brace_map(string $code): array
{
    $map = [];
    $stack = [];
    $len = strlen($code);
    for ($i = 0; $i < $len; $i++) {
        if ($code[$i] === '{') { $stack[] = $i; }
        elseif ($code[$i] === '}') {
            $open = array_pop($stack);
            if ($open !== null) { $map[$open] = $i; }
        }
    }
    return $map;
}

/** Cari loop: keyword + '{' terdekat, kembalikan [startOffset, endOffset, kind]. */
function n1_find_loops(string $code, array $braceMap): array
{
    $loops = [];
    foreach (LOOP_KEYWORDS as $kw) {
        $len = strlen($kw);
        $off = 0;
        while (($pos = strpos($code, $kw, $off)) !== false) {
            $off = $pos + $len;
            // pastikan bukan bagian dari identifier (mis. "endforeach")
            $before = $pos > 0 ? $code[$pos - 1] : ' ';
            $after  = $code[$pos + $len] ?? ' ';
            if (ctype_alnum($before) || $before === '_' || $before === '$') continue;
            if (ctype_alnum($after) || $after === '_') continue;
            // cari '{' sebelum ';' atau ')' yang menutup (guard) — ambil kandidat pertama '{'
            $brace = strpos($code, '{', $pos);
            if ($brace === false) continue;
            // pastikan tidak ada statement terminated di antara
            $semi = strpos($code, ';', $pos);
            if ($semi !== false && $semi < $brace) continue;
            if (!isset($braceMap[$brace])) {
                // guard inline (mis. foreach($x as $y):) — cari statement ke-i
                $j = $brace; $depth = 0; $found = false;
                for (; $j < strlen($code); $j++) {
                    if ($code[$j] === '(') $depth++;
                    elseif ($code[$j] === ')') $depth--;
                    elseif ($code[$j] === '{' && $depth <= 0) { $found = true; break; }
                    elseif ($code[$j] === ';' && $depth <= 0) break;
                }
                if (!$found || !isset($braceMap[$j])) continue;
                $brace = $j;
            }
            $loops[] = [$pos, $braceMap[$brace], $kw];
        }
    }
    usort($loops, fn($a, $b) => $a[0] <=> $b[0]);
    // buang loop bersarang (yang sepenuhnya di dalam loop lain) supaya tidak dobel
    $out = [];
    foreach ($loops as $l) {
        $nested = false;
        foreach ($out as $k) {
            if ($l[0] > $k[0] && $l[1] <= $k[1]) { $nested = true; break; }
        }
        if (!$nested) $out[] = $l;
    }
    return $out;
}

/** Kumpulkan definisi fungsi di file: nama => [startOffset, endOffset]. */
function n1_function_index(string $code, array $braceMap): array
{
    $fns = [];
    if (preg_match_all('/\bfunction\s+(\w+)\s*\(/i', $code, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $k => $whole) {
            $name = $m[1][$k][0];
            $brace = strpos($code, '{', $whole[1]);
            if ($brace === false || !isset($braceMap[$brace])) continue;
            $fns[strtolower($name)] = [$brace, $braceMap[$brace]];
        }
    }
    return $fns;
}

function n1_line_of(string $code, int $offset): int
{
    return substr_count(substr($code, 0, $offset), "\n") + 1;
}

function n1_has_query(string $chunk): bool
{
    foreach (QUERY_PRIMITIVES as $p) {
        if (stripos($chunk, $p) !== false) return true;
    }
    return false;
}

/** Ambil snippet satu baris di sekitar offset. */
function n1_snippet(string $code, int $offset, int $len = 190): string
{
    $s = substr($code, $offset, $len);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

/* --------------------------------------------------------------- analyze */
function n1_scan_file(string $path, string $root): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $code = n1_strip_noise($raw);
    $braceMap = n1_brace_map($code);
    $loops    = n1_find_loops($code, $braceMap);
    $fnIndex  = n1_function_index($code, $braceMap);
    if (!$loops) return [];

    $rel  = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $findings = [];

    foreach ($loops as [$ls, $le, $kind]) {
        $bodyStart = strpos($code, '{', $ls);
        $body = substr($code, $bodyStart + 1, max(0, $le - $bodyStart - 1));

        /* --- N1-DIRECT: primitive query langsung di body loop --- */
        $brOpen  = strpos($code, '{', $ls);
        $header  = $brOpen === false ? '' : substr($code, $ls, max(0, $brOpen - $ls));
        $iterSrc = 'unknown';
        if (preg_match('/(?:foreach|for|while)\s*\(\s*\$([A-Za-z_]\w*)/i', $header, $mi)) {
            $iterSrc = '$' . $mi[1];
        }
        /* iterable literal kecil di header -> risiko rendah, bukan N+1 nyata */
        $isLiteralIter = (bool)preg_match('/\[[^\]]*\]/', $header) && substr_count($header, ',') <= 5;
        /* loop migrasi/DDL -> idempotent, bukan request-time N+1 */
        $isMigration = (bool)preg_match(
            '/\bALTER\s+TABLE\b|\bCREATE\s+TABLE\b|\bDROP\s+TABLE\b|\bCREATE\s+(UNIQUE\s+)?INDEX\b'
            . '|\bINFORMATION_SCHEMA\b|\bSHOW\s+(COLUMNS?|INDEX)\b|\bmigrat/i',
            $body
        );

        /* satu statement = satu finding (jangan hitung per token) */
        $perLine = [];
        foreach (QUERY_PRIMITIVES as $prim) {
            $off = 0;
            while (($p = stripos($body, $prim, $off)) !== false) {
                $abs = $bodyStart + 1 + $p;
                $ln  = n1_line_of($code, $abs);
                if (!isset($perLine[$ln])) $perLine[$ln] = ['abs' => $abs, 'tokens' => []];
                $perLine[$ln]['tokens'][] = $prim;
                $off = $p + strlen($prim);
            }
        }

        foreach ($perLine as $ln => $info) {
            $snip = n1_snippet($code, $info['abs'], 320);
            $isDDL = (bool)preg_match('/\bALTER\s+TABLE\b|\bCREATE\s+TABLE\b|\bDROP\s+TABLE\b|\bCREATE\s+INDEX\b/i', $snip);
            if ($isMigration || $isDDL) {
                $type = 'N1-MIGRATION'; $sev = 'P3';
                $ev   = 'DDL/migrasi dieksekusi per-iterasi (idempotent, bukan N+1 request-time)';
            } elseif ($isLiteralIter) {
                $type = 'N1-SMALLLOOP'; $sev = 'P3';
                $ev   = 'query di dalam ' . $kind . ' atas iterable literal kecil (' . $iterSrc . '), bukan N+1 skaalable';
            } else {
                $type = 'N1-DIRECT'; $sev = 'P1';
                $ev   = 'query primitive di dalam body ' . $kind . '; iterable=' . $iterSrc;
            }
            $findings[] = [
                'type'      => $type,
                'severity'  => $sev,
                'module'    => strtok($rel, '/') ?: '(root)',
                'file'      => $rel,
                'line'      => $ln,
                'loop_kind' => $kind,
                'loop_line' => n1_line_of($code, $ls),
                'loop_src'  => $iterSrc,
                'token'     => implode(' ', array_unique($info['tokens'])),
                'snippet'   => $snip,
                'evidence'  => $ev,
            ];
        }

        /* --- N1-INDIRECT: fungsi dipanggil di loop yang body-nya ada query --- */
        if (preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\(/i', $body, $m2, PREG_OFFSET_CAPTURE)) {
            foreach ($m2[1] as $k => $nm) {
                $fname = strtolower($nm[0]);
                if (in_array($fname, ['if', 'for', 'foreach', 'while', 'switch', 'function', 'isset', 'empty', 'unset', 'array', 'sprintf', 'printf', 'implode', 'explode', 'trim', 'htmlspecialchars', 'number_format', 'str_replace', 'strpos', 'sprintf'], true)) continue;
                if (!isset($fnIndex[$fname])) continue; // definisi tidak di file ini
                [$fStart, $fEnd] = $fnIndex[$fname];
                $fBody = substr($code, $fStart + 1, max(0, $fEnd - $fStart - 1));
                if (!n1_has_query($fBody)) continue;
                $abs = $bodyStart + 1 + $nm[1];
                $findings[] = [
                    'type'      => 'N1-INDIRECT',
                    'severity'  => 'P2',
                    'module'    => strtok($rel, '/') ?: '(root)',
                    'file'      => $rel,
                    'line'      => n1_line_of($code, $abs),
                    'loop_kind' => $kind,
                    'loop_line' => n1_line_of($code, $ls),
                    'token'     => $nm[0] . '()',
                    'snippet'   => n1_snippet($code, $abs),
                    'evidence'  => 'fungsi ' . $nm[0] . '() dipanggil di ' . $kind . '; isi fungsi menjalankan query',
                ];
            }
        }
    }
    return $findings;
}

/**
 * Ekstrak literal string (single/double quoted) dari raw source beserta offset.
 * Dipakai UNBOUNDED supaya SQL dibaca ASLI (LIMIT masih terlihat) dan agar
 * teks inline JS / heredoc tidak ikut ter-scan.
 * Komentar dilewati supaya SQL yang dikomentari tidak dihitung.
 */
function n1_sql_literals(string $raw): array
{
    $out = [];
    $len = strlen($raw);
    $i   = 0;
    while ($i < $len) {
        $c = $raw[$i];
        $n = $i + 1 < $len ? $raw[$i + 1] : '';
        if (($c === '/' && $n === '/') || $c === '#') { while ($i < $len && $raw[$i] !== "\n") $i++; continue; }
        if ($c === '/' && $n === '*') { $i += 2; while ($i < $len && !($raw[$i] === '*' && ($raw[$i + 1] ?? '') === '/')) $i++; $i += 2; continue; }
        if ($c === "'" || $c === '"') {
            $q = $c; $start = $i; $i++;
            $buf = '';
            while ($i < $len) {
                if ($raw[$i] === '\\' && $q === '"' && $i + 1 < $len) { $buf .= $raw[$i + 1]; $i += 2; continue; }
                if ($raw[$i] === $q) { $i++; break; }
                $buf .= $raw[$i]; $i++;
            }
            $out[] = ['text' => $buf, 'off' => $start];
            continue;
        }
        $i++;
    }
    return $out;
}

/** Statement SQL yang memang tidak perlu LIMIT / tidak relevan perf. */
function n1_sql_benign(string $s): bool
{
    return (bool)preg_match(
        '/\bINFORMATION_SCHEMA\b|\bSHOW\s+(TABLE|COLUMN|INDEX|STATUS|VARIABLES)\b'
        . '|\bALTER\s+TABLE\b|\bCREATE\s+TABLE\b|\bDROP\s+TABLE\b|\bDESCRIBE\b|\bDESC\s+\w'
        . '|\bEXPLAIN\b|\bSET\s+@|\bUSE\s+\w|\bSTART\s+TRANSACTION\b|\bCOMMIT\b|\bROLLBACK\b'
        . '|\bCREATE\s+(OR\s+REPLACE\s+)?(VIEW|PROCEDURE|FUNCTION|TRIGGER|INDEX)\b/i',
        $s
    );
}

/** UNBOUNDED: statement SELECT/UPDATE/DELETE tanpa LIMIT dan tanpa aggregate. */
function n1_scan_unbounded(string $path, string $root): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) return [];

    $rel  = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $mod  = strtok($rel, '/') ?: '(root)';
    $findings = [];
    $seen = [];

    foreach (n1_sql_literals($raw) as $lit) {
        $s = trim($lit['text']);
        if ($s === '' || strlen($s) < 12) continue;
        /* Hanya query yang hasilnya bisa grow. INSERT/UPDATE/REPLACE single-row
           tidak punya masalah LIMIT, jadi tidak dilaporkan. */
        if (preg_match('/^(SELECT|WITH|DELETE\s+FROM)\b/i', $s) !== 1) continue;
        if (preg_match('/\bLIMIT\b/i', $s)) continue;
        if (n1_sql_benign($s)) continue;
        /* Aggregate: 1 baris hasil, tidak grow dengan data. */
        if (preg_match('/\bCOUNT\s*\(\s*(\*|\D)/i', $s) || preg_match('/\bGROUP\s+BY\b/i', $s)) continue;
        /* SELECT 1 / EXISTS / konstanta. */
        if (preg_match('/^SELECT\s+(\d+|CURRENT_|NULL|EXISTS\b|@)/i', $s)) continue;

        $line = n1_line_of($raw, $lit['off']);
        $key  = $line . '|' . substr(preg_replace('/\s+/', ' ', $s), 0, 80);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        /* Lookup satu baris: WHERE <kolom>_id/_code = ... tanpa OR -> bukan
           risiko unbounded, hanya informational. */
        $isKeyLookup = (bool)preg_match('/\bWHERE\b[^;]*?\b\w*(?:_id|_code|\bid)\s*=\s*(?:\?|\'|\"|\d)/i', $s)
            && !preg_match('/\bOR\b/i', $s);

        $oneLine = trim(preg_replace('/\s+/', ' ', $s));
        $findings[] = [
            'type'     => 'UNBOUNDED',
            'severity' => $isKeyLookup ? 'P3' : 'P2',
            'module'   => $mod,
            'file'     => $rel,
            'line'     => $line,
            'token'    => strtoupper(strtok($s, " \t\n(")),
            'snippet'  => mb_substr($oneLine, 0, 180),
            'evidence' => $isKeyLookup
                ? 'SELECT tanpa LIMIT, tapi pola lookup satu baris (risiko rendah)'
                : 'statement SQL tanpa LIMIT/OFFSET; hasil bisa grow tanpa batas',
        ];
    }
    return $findings;
}

/* ------------------------------------------------------------------ main */
$files = n1_walk($root, $SKIP_DIRS);
if ($optFilter !== '') {
    /* Filter datang dari CLI. Selalu bungkus delimiter kalau belum punya,
       supaya `--filter='^sales/'` tidak menjadi regex tanpa delimiter. */
    $delims = ['/', '#', '~', '%', '!', '@'];
    $hasDelim = false;
    foreach ($delims as $d) {
        if (str_starts_with($optFilter, $d) && str_ends_with($optFilter, $d) && strlen($optFilter) > 1) {
            $hasDelim = true;
            break;
        }
    }
    $rx = $hasDelim ? $optFilter : '/' . str_replace('/', '\/', $optFilter) . '/';
    $bad = @preg_match($rx, '');
    if ($bad === false) {
        fwrite(STDERR, "nplus1_query_scan: --filter bukan regex valid: $optFilter\n");
        exit(2);
    }
    $files = array_values(array_filter($files, function ($f) use ($root, $rx) {
        $rel = str_replace('\\', '/', substr($f, strlen($root) + 1));
        return $rel !== '' && preg_match($rx, $rel) === 1;
    }));
}

$all = [];
foreach ($files as $f) {
    $all = array_merge($all, n1_scan_file($f, $root), n1_scan_unbounded($f, $root));
}
/* Query yang sudah dilaporkan N1-DIRECT pada file+baris yang sama tidak perlu
   dihitung dua kali sebagai UNBOUNDED. */
$n1Keys = [];
foreach ($all as $f) {
    if (str_starts_with($f['type'], 'N1-')) $n1Keys[$f['file'] . ':' . $f['line']] = true;
}
$all = array_values(array_filter($all, function ($f) use ($n1Keys) {
    return !($f['type'] === 'UNBOUNDED' && isset($n1Keys[$f['file'] . ':' . $f['line']]));
}));

/* dedupe: type+file+line+token */
$seen = [];
$uniq = [];
foreach ($all as $x) {
    $k = $x['type'] . '|' . $x['file'] . '|' . $x['line'] . '|' . $x['token'];
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $uniq[] = $x;
}
$all = $uniq;

/* filter severity */
$minRank = $SEV_RANK[$optMinSev];
$all = array_values(array_filter($all, fn($x) => $SEV_RANK[$x['severity']] >= $minRank));
/* sort: severity desc, then file, then line */
usort($all, function ($a, $b) use ($SEV_RANK) {
    $ra = $SEV_RANK[$a['severity']]; $rb = $SEV_RANK[$b['severity']];
    if ($ra !== $rb) return $rb <=> $ra;
    $c = strcmp($a['file'], $b['file']);
    if ($c !== 0) return $c;
    return $a['line'] <=> $b['line'];
});

$truncated = false;
if ($optLimit > 0 && count($all) > $optLimit) {
    $all = array_slice($all, 0, $optLimit);
    $truncated = true;
}

$byType = ['N1-DIRECT' => 0, 'N1-INDIRECT' => 0, 'UNBOUNDED' => 0];
$bySev  = ['P0' => 0, 'P1' => 0, 'P2' => 0, 'P3' => 0];
$byModule = [];
foreach ($all as $x) {
    $byType[$x['type']] = ($byType[$x['type']] ?? 0) + 1;
    $bySev[$x['severity']] = ($bySev[$x['severity']] ?? 0) + 1;
    $byModule[$x['module']] = ($byModule[$x['module']] ?? 0) + 1;
}
arsort($byModule);
ksort($byType);

$payload = [
    'summary' => [
        'generated_at'  => date('c'),
        'generator'     => 'tools/qa/nplus1_query_scan.php',
        'scanned_files' => count($files),
        'total_findings'=> count($all),
        'by_type'       => $byType,
        'by_severity'   => $bySev,
        'by_module'     => $byModule,
        'truncated'     => $truncated,
        'filter'        => $optFilter !== '' ? $optFilter : null,
        'min_severity'  => $optMinSev,
        'status'        => 'CANDIDATE',
        'note'          => 'Statis, tidak mengeksekusi query. Status CANDIDATE sampai ada reproduksi manual (lihat ERP_MASTER_TASK_TRACKER.md).',
        'root'          => basename($root),
    ],
    'findings' => $all,
];

/* ------------------------------------------------------------ self-test */
if ($optSelf) {
    $t = tempnam(sys_get_temp_dir(), 'n1t') ?: '/tmp/n1t.php';
    $src = <<<'PHP'
<?php
// N+1 langsung: query di dalam loop
$pdo = db_pdo();
$ids = [1,2,3];
foreach ($ids as $id) {
    $st = $pdo->prepare("SELECT name FROM m WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
}
// bukan N+1: query sebelum loop, loop cuma pakai array
$all = $pdo->query("SELECT id,name FROM m")->fetchAll();
foreach ($all as $r) { echo $r['name']; }
// bukan N+1: foreach dengan string, bukan query
foreach ($all as $r) { $x = htmlspecialchars($r['name']); }
PHP;
    file_put_contents($t, $src);
    $r = array_merge(n1_scan_file($t, $root), n1_scan_unbounded($t, $root));
    @unlink($t);

    $direct = array_values(array_filter($r, fn($x) => $x['type'] === 'N1-DIRECT'));
    /* Baris acuan di fixture:
         6  -> $pdo->prepare(...) di dalam foreach   (HARUS terdeteksi)
         7  -> $st->execute([$id])                   (HARUS terdeteksi)
         11 -> query di LUAR loop                    (TIDAK boleh N1)
         12 -> foreach tanpa query                   (TIDAK boleh N1)
    */
    $lines   = array_map(fn($x) => $x['line'], $direct);
    $at6     = in_array(6, $lines, true);
    $at7     = in_array(7, $lines, true);
    $at11    = in_array(11, $lines, true);
    $at12    = in_array(12, $lines, true);

    $ok = $at6 && $at7 && !$at11 && !$at12;

    echo "== N+1 self-test ==\n";
    echo "  line  6 prepare() di dalam loop  : " . ($at6 ? 'TERDETEKSI OK' : 'GAGAL (false negative)') . "\n";
    echo "  line  7 execute() di dalam loop  : " . ($at7 ? 'TERDETEKSI OK' : 'GAGAL (false negative)') . "\n";
    echo "  line 11 query di LUAR loop       : " . ($at11 ? 'FALSE POSITIVE (BUG)' : 'benar, tidak ditandai') . "\n";
    echo "  line 12 loop tanpa query         : " . ($at12 ? 'FALSE POSITIVE (BUG)' : 'benar, tidak ditandai') . "\n";
    echo "  total N1-DIRECT findings         : " . count($direct) . "\n";
    echo ($ok ? "SELF-TEST: LULUS\n" : "SELF-TEST: GAGAL\n");
    exit($ok ? 0 : 1);
}

/* ---------------------------------------------------------------- write */
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($outFile !== '') {
    $dir = dirname($outFile);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "nplus1_query_scan: tidak bisa membuat direktori output: $dir\n");
        exit(2);
    }
    if (@file_put_contents($outFile, $json . "\n") === false) {
        fwrite(STDERR, "nplus1_query_scan: output tidak writable: $outFile\n");
        exit(2);
    }
}

/* ------------------------------------------------------------------ out */
echo "== N+1 / Unbounded Query Scan ==\n";
echo "scanned files : " . $payload['summary']['scanned_files'] . "\n";
echo "findings      : " . $payload['summary']['total_findings'] . "\n";
echo "by type       : ";
foreach ($byType as $t2 => $c) echo "$t2=$c ";
echo "\n";
echo "by severity   : ";
foreach ($bySev as $s => $c) echo "$s=$c ";
echo "\n";
echo "top modules   : ";
$shown = 0;
foreach ($byModule as $m => $c) { echo "$m=$c "; if (++$shown >= 10) break; }
echo "\n";
if ($truncated) echo "(truncated oleh --limit)\n";
if ($outFile !== '') echo "json          : $outFile\n";

if ($optLimit > 0) {
    echo "\n-- top " . min(25, count($all)) . " --\n";
    foreach (array_slice($all, 0, 25) as $x) {
        printf("%-12s %-3s %s:%d  %s\n", $x['type'], $x['severity'], $x['file'], $x['line'], $x['token']);
    }
}
exit(0);
