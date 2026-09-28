<?php
/**
 * READ-ONLY diagnostic for ACT DO upload references.
 * Purpose: find where old Faktur Pajak / Tukar Faktur references are actually stored
 * before changing sales/act_do_tasks.php again.
 */
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../master/auth.php';
require_login();

// Keep this diagnostic SYS-only because it exposes schema/table names.
if (function_exists('auth_is_sys') && !auth_is_sys()) {
    http_response_code(403);
    exit('SYS only');
}

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();

function dh($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function qident(string $s): string {
    return '`' . str_replace('`', '``', $s) . '`';
}
function looks_relevant_col(string $c): bool {
    $c = strtolower($c);
    foreach (['file','path','doc','document','faktur','invoice','tax','pajak','tukar','exchange','attachment','upload','due','amount','nominal'] as $k) {
        if (strpos($c, $k) !== false) return true;
    }
    return false;
}
function existing_abs_path(string $projectRoot, string $stored): ?string {
    $stored = trim($stored);
    if ($stored === '') return null;
    if ($stored[0] === '/') {
        $p = $projectRoot . $stored;
        if (is_file($p)) return $p;
        if (is_file($stored)) return $stored;
    } else {
        $p = $projectRoot . '/' . ltrim($stored, '/');
        if (is_file($p)) return $p;
    }
    return null;
}

$doCode = trim((string)($_GET['do_code'] ?? ''));
$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$result = [
    'do_code' => $doCode,
    'sales_do' => null,
    'sales_do_relevant_columns' => [],
    'related_tables' => [],
    'filesystem_matches' => [],
];
$error = '';

if ($doCode !== '') {
    try {
        // 1) sales_do: inspect ALL columns, then show only relevant values.
        $st = $pdo->prepare("SELECT * FROM sales_do WHERE do_code=? LIMIT 1");
        $st->execute([$doCode]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $result['sales_do'] = $row ?: null;

        if ($row) {
            foreach ($row as $col => $val) {
                if (looks_relevant_col($col)) {
                    $entry = ['column'=>$col, 'value'=>$val];
                    if (is_string($val) && trim($val) !== '') {
                        $entry['physical_file'] = existing_abs_path($projectRoot, $val);
                    }
                    $result['sales_do_relevant_columns'][] = $entry;
                }
            }
        }

        // 2) Find other tables with a do_code-like column + relevant file/document columns.
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $meta = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME
                               FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA=?
                               ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $meta->execute([$dbName]);
        $tables = [];
        while ($m = $meta->fetch(PDO::FETCH_ASSOC)) {
            $t = $m['TABLE_NAME']; $c = $m['COLUMN_NAME'];
            $tables[$t][] = $c;
        }

        foreach ($tables as $table => $cols) {
            if ($table === 'sales_do') continue;
            $lowerMap = [];
            foreach ($cols as $c) $lowerMap[strtolower($c)] = $c;

            $doKey = null;
            foreach (['do_code','delivery_order_code','sales_do_code','document_code','record_code','tracking_code'] as $candidate) {
                if (isset($lowerMap[$candidate])) { $doKey = $lowerMap[$candidate]; break; }
            }
            if (!$doKey) continue;

            $relCols = array_values(array_filter($cols, 'looks_relevant_col'));
            if (!$relCols) continue;

            $selectCols = array_unique(array_merge([$doKey], $relCols));
            $sql = 'SELECT ' . implode(',', array_map('qident', $selectCols)) .
                   ' FROM ' . qident($table) . ' WHERE ' . qident($doKey) . '=? LIMIT 20';
            try {
                $q = $pdo->prepare($sql);
                $q->execute([$doCode]);
                $found = $q->fetchAll(PDO::FETCH_ASSOC);
                if ($found) {
                    foreach ($found as &$fr) {
                        foreach ($fr as $k=>$v) {
                            if (is_string($v) && trim($v) !== '' && looks_relevant_col($k)) {
                                $p = existing_abs_path($projectRoot, $v);
                                if ($p) $fr[$k . '__physical_file'] = $p;
                            }
                        }
                    }
                    unset($fr);
                    $result['related_tables'][] = ['table'=>$table, 'key'=>$doKey, 'rows'=>$found];
                }
            } catch (Throwable $ignored) {}
        }

        // 3) Filesystem: conservative scan under common upload roots, matching DO code / tracking fragments.
        $needles = [$doCode, str_replace(['-','_'], '', $doCode)];
        if ($row && !empty($row['tracking_code'])) {
            $needles[] = (string)$row['tracking_code'];
            $needles[] = str_replace(['-','_'], '', (string)$row['tracking_code']);
        }
        $roots = ['uploads', 'storage/uploads', 'sales/uploads', 'uploads/sales_act'];
        foreach ($roots as $relRoot) {
            $absRoot = $projectRoot . '/' . $relRoot;
            if (!is_dir($absRoot)) continue;
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($absRoot, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                $seen = 0;
                foreach ($it as $fi) {
                    if (!$fi->isFile()) continue;
                    $seen++;
                    if ($seen > 200000) break; // safety cap
                    $nameNorm = str_replace(['-','_',' '], '', strtolower($fi->getFilename()));
                    $hit = false;
                    foreach ($needles as $needle) {
                        $needleNorm = str_replace(['-','_',' '], '', strtolower(trim((string)$needle)));
                        if ($needleNorm !== '' && strpos($nameNorm, $needleNorm) !== false) { $hit = true; break; }
                    }
                    if ($hit) {
                        $result['filesystem_matches'][] = [
                            'path' => $fi->getPathname(),
                            'size' => $fi->getSize(),
                            'mtime' => date('Y-m-d H:i:s', $fi->getMTime()),
                        ];
                        if (count($result['filesystem_matches']) >= 100) break 2;
                    }
                }
            } catch (Throwable $ignored) {}
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>ACT DO Upload Diagnostic</title>
<style>body{font-family:system-ui;background:#0b1220;color:#e5e7eb;padding:24px}input,button{padding:9px 12px}.card{background:#111827;border:1px solid #334155;border-radius:12px;padding:16px;margin:14px 0}table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #334155;padding:7px;text-align:left;vertical-align:top}code,pre{white-space:pre-wrap;word-break:break-word;color:#bfdbfe}.ok{color:#86efac}.bad{color:#fca5a5}.muted{color:#94a3b8}</style></head><body>
<h2>ACT DO Upload Diagnostic — READ ONLY</h2>
<p class="muted">Tidak melakukan UPDATE/INSERT/DELETE. Gunakan satu DO yang sebelumnya jelas memiliki upload.</p>
<form method="get"><input name="do_code" style="width:320px" value="<?=dh($doCode)?>" placeholder="contoh: BMHP-TGR-260831-006"><button>Periksa</button></form>
<?php if ($error): ?><div class="card bad">Error: <?=dh($error)?></div><?php endif; ?>
<?php if ($doCode !== '' && !$error): ?>
<div class="card"><h3>1. sales_do — kolom terkait dokumen</h3>
<?php if (!$result['sales_do']): ?><div class="bad">DO tidak ditemukan.</div><?php else: ?>
<table><tr><th>Kolom</th><th>Nilai</th><th>File fisik ditemukan?</th></tr>
<?php foreach ($result['sales_do_relevant_columns'] as $x): ?><tr><td><?=dh($x['column'])?></td><td><code><?=dh((string)$x['value'])?></code></td><td><?=!empty($x['physical_file'])?'<span class="ok">YA: '.dh($x['physical_file']).'</span>':'<span class="muted">tidak / bukan path</span>'?></td></tr><?php endforeach; ?>
</table><?php endif; ?></div>

<div class="card"><h3>2. Tabel lain yang menyimpan referensi DO + dokumen</h3>
<?php if (!$result['related_tables']): ?><div class="muted">Tidak ditemukan tabel lain dengan kecocokan langsung.</div><?php else: ?>
<?php foreach ($result['related_tables'] as $t): ?><h4><?=dh($t['table'])?> (key: <?=dh($t['key'])?>)</h4><pre><?=dh(json_encode($t['rows'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))?></pre><?php endforeach; ?>
<?php endif; ?></div>

<div class="card"><h3>3. File fisik yang namanya cocok dengan DO/tracking</h3>
<?php if (!$result['filesystem_matches']): ?><div class="muted">Tidak ada filename yang cocok langsung. Ini belum berarti file hilang; nama upload bisa berupa nama asli/random tanpa DO code.</div><?php else: ?><pre><?=dh(json_encode($result['filesystem_matches'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))?></pre><?php endif; ?>
</div>

<div class="card"><h3>Kesimpulan cepat</h3><p>Jika kolom canonical kosong tetapi tabel lain berisi path → ACT harus membaca tabel tersebut. Jika DB menyimpan path tetapi file fisik ditemukan → masalah hanya pembacaan UI. Jika DB kosong namun backup memiliki data → lakukan restore referensi dari backup, bukan menebak dari status WAIT PAYMENT.</p></div>
<?php endif; ?>
</body></html>
