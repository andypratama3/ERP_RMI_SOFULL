<?php
/**
 * Deteksi schema drift: kolom yang dipakai di kode tapi tidak ada di database.
 * Penyebab halaman mati (SQL error) yang tidak terlihat dari audit visual.
 *
 * Jalankan: php tools/qa/schema_drift.php [regex-filter-file]
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/db.php';

try { $pdo = rmi_db_pdo(); } catch (Throwable $e) { fwrite(STDERR, "DB gagal: " . $e->getMessage() . "\n"); exit(2); }

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$filter = $argv[1] ?? '';

$schema = [];
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $schema[strtolower($t)] = array_map('strtolower', $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN));
}
fwrite(STDERR, 'Tabel di DB: ' . count($schema) . "\n");

// Kata kunci SQL yang boleh muncul sebagai nama tabel.
$SQL_KW = ['select','from','where','join','left','right','inner','outer','cross','on','and','or','not',
           'group','by','order','having','limit','offset','insert','into','values','update','set',
           'delete','create','table','alter','drop','index','primary','key','foreign','references',
           'distinct','as','case','when','then','else','end','union','all','exists','in','is','null',
           'like','between','asc','desc','using','duplicate','replace','ignore','count','sum','max',
           'min','avg','if','ifnull','coalesce','concat','now','curdate','date','year','month','day'];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $rel = substr($f->getPathname(), strlen($root));
    foreach (['/vendor/', '/node_modules/', '/_backup/', '/.git/'] as $sd) {
        if (strpos($rel, $sd) !== false) continue 2;
    }
    if (strpos($rel, '/tools/') !== false) continue;   // tooling, bukan halaman produksi
    if (strpos($f->getBasename(), '__') !== false) continue;
    if ($filter !== '' && !preg_match('/' . str_replace('/', '\\/', $filter) . '/', $rel)) continue;
    $files[] = $rel;
}
sort($files);

$hits = [];
function miss(string $rel, string $t, string $col, string $why): void {
    global $hits;
    $hits[$rel][$t . '.' . $col] = $why;
}

foreach ($files as $rel) {
    $src = (string) @file_get_contents($root . '/' . ltrim($rel, '/'));
    if ($src === '') continue;

    // Buang komentar saja. JANGAN buang string PHP: pada aplikasi ini SQL
    // justru berada di dalam string ("SELECT ... FROM t"), jadi menghapusnya
    // membuat pemeriksaan buta dan kolom yang hilang lolos dari deteksi.
    $sqlish = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], ' ', $src);

    // 1) INSERT INTO t (a, b, c)
    if (preg_match_all('/\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*\(([^)]*)\)/i', $sqlish, $m, PREG_SET_ORDER)) {
        foreach ($m as $set) {
            $t = strtolower($set[1]);
            if (!isset($schema[$t])) continue;
            foreach (preg_split('/\s*,\s*/', $set[2]) as $col) {
                $col = strtolower(trim($col, " `\t\n\r\"'"));
                if (!preg_match('/^[a-z_][a-z0-9_]*$/', $col)) continue;
                if (!in_array($col, $schema[$t], true)) miss($rel, $t, $col, 'insert-list');
            }
        }
    }

    // 2) UPDATE t SET a=?, b=NOW()
    if (preg_match_all('/\bUPDATE\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+SET\s+(.+?)(?:\s+WHERE\b|\s+LIMIT\b|\s+ORDER\b|;|$)/is', $sqlish, $m, PREG_SET_ORDER)) {
        foreach ($m as $set) {
            $t = strtolower($set[1]);
            if (!isset($schema[$t])) continue;
            if (preg_match_all('/(?:^|,)\s*`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*=\s*(?!=)/', $set[2], $c)) {
                foreach ($c[1] as $col) {
                    $col = strtolower($col);
                    if (in_array($col, $SQL_KW, true)) continue;
                    if (!in_array($col, $schema[$t], true)) miss($rel, $t, $col, 'update-set');
                }
            }
        }
    }

    // 3) Kolom ter-qualify: t.col  (abaikan yangpregnah-nya nama file .php)
    if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\.\s*`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $sqlish, $m, PREG_SET_ORDER)) {
        foreach ($m as $pair) {
            $t = strtolower($pair[1]);
            if (!isset($schema[$t])) continue;
            $col = strtolower($pair[2]);
            if (in_array($col, $SQL_KW, true)) continue;
            // abaikan yang sebenarnya ekstensi berkas: "master_user.php" dsb.
            if (in_array($col, ['php','csv','sql','js','json','png','jpg','jpeg','gif','xlsx','xls',
                                'md','html','htm','css','txt','pdf','svg','xml','yml','yaml','log'], true)) continue;
            if (preg_match('/^[0-9]/', $col)) continue;
            if (!in_array($col, $schema[$t], true)) miss($rel, $t, $col, 'qualified');
        }
    }
}

if (!$hits) { echo "BERSIH — tidak ada kolom hilang yang terdeteksi\n"; exit(0); }
ksort($hits);
$total = 0;
foreach ($hits as $rel => $cols) {
    ksort($cols);
    echo "$rel\n";
    foreach ($cols as $c => $why) { echo "    $c   [$why]\n"; $total++; }
    echo "\n";
}
echo "TOTAL: $total kolom hilang di " . count($hits) . " file\n";
exit(0);
