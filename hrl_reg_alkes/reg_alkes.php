<?php
// hrl_reg_alkes/reg_alkes.php
// Phase 3 (Full): dossier-aware, idempotent import to master_products, audit log, preview, summary
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader

require_once __DIR__ . '/../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['HRL.REG_ALKES_VIEW', 'HRL.VIEW', 'PQP.VIEW']);
} elseif (file_exists(__DIR__ . '/../_shared/enterprise_guard.php')) {
    require_once __DIR__ . '/../_shared/enterprise_guard.php';
    if (function_exists('eg_can_access_reg_alkes') && !eg_can_access_reg_alkes()) {
        http_response_code(403);
        echo "<h3>Akses ditolak</h3><p>Module REG Alkes hanya untuk dept PQP/HRL/ITC atau SYS.</p>";
        exit;
    }
}

$pdo = db_pdo();

// --- Audit ---
if (file_exists(__DIR__ . '/../_shared/erp_audit.php')) {
    require_once __DIR__ . '/../_shared/erp_audit.php';
    if (function_exists('erp_audit_ensure')) {
        try {
            erp_audit_ensure($pdo);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
if (file_exists(__DIR__ . '/../master/_audit_master.php')) {
    require_once __DIR__ . '/../master/_audit_master.php';
}
function regalkes_audit(PDO $pdo, string $action, ?string $entityKey, array $payload = []): void {
    if (function_exists('erp_audit')) {
        erp_audit($pdo, 'REG_ALKES', $entityKey, $action, $payload);
    }
    if (function_exists('master_audit')) {
        $recordId = null;
        $code = $entityKey ?? '';
        if ($code !== '' && preg_match('/CASE#(\d+)/', $code, $m)) {
            $recordId = (int)$m[1];
        }
        $desc = $action . ($code !== '' ? ": {$code}" : '');
        master_audit($pdo, 'hrl_reg_alkes', 'reg_alkes', $action, $recordId ?: null, $code, $desc, $payload);
    }
}

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}


function project_root(): string {
    $root = realpath(__DIR__ . '/..');
    return $root ?: dirname(__DIR__);
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

if (!function_exists('safe_filename')) {
    function safe_filename(string $name): string {
        $name = trim($name);
        $name = str_replace(["\0", "\r", "\n"], '', $name);
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
        $name = preg_replace('/_{2,}/', '_', $name) ?? '';
        $name = trim($name, '._-');
        return $name !== '' ? $name : 'file';
    }
}

function csrf_check_or_die(): void {
    $token = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    if (function_exists('verify_csrf')) {
        verify_csrf($token);
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $expected = (string)($_SESSION['_csrf'] ?? '');
    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('Forbidden (CSRF)');
    }
}


function list_files(string $dir, array $exts): array {
    if (!is_dir($dir)) return [];
    $out = [];
    $dh = opendir($dir);
    if (!$dh) return [];
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $exts, true)) {
            $out[] = [
                'name' => $f,
                'path' => $path,
                'size' => filesize($path) ?: 0,
                'mtime' => filemtime($path) ?: 0,
            ];
        }
    }
    closedir($dh);
    usort($out, fn($a,$b) => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['name'],$b['name']));
    return $out;
}

function safe_join(string $base, string $child): ?string {
    // Prevent path traversal
    $child = str_replace(['..', "\0"], '', $child);
    $full = realpath($base . DIRECTORY_SEPARATOR . $child);
    $baseReal = realpath($base);
    if (!$full || !$baseReal) return null;
    if (strpos($full, $baseReal) !== 0) return null;
    return $full;
}

function download_file(string $fullPath, string $downloadName): void {
    if (!is_file($fullPath)) { http_response_code(404); exit('File not found'); }
    $mime = 'application/octet-stream';
    $ext = strtolower(pathinfo($downloadName, PATHINFO_EXTENSION));
    if ($ext === 'pdf') $mime = 'application/pdf';
    if ($ext === 'csv') $mime = 'text/csv';
    if ($ext === 'xlsx') $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fullPath));
    header('Content-Disposition: attachment; filename="' . str_replace('"','',$downloadName) . '"');
    readfile($fullPath);
    exit;
}

function parse_csv_rows(string $path): array {
    $rows = [];
    $fh = fopen($path, 'rb');
    if (!$fh) return $rows;

    $sample = fread($fh, 4096);
    rewind($fh);
    $delims = [",",";","\t","|"];
    $best = ",";
    $bestCount = 0;
    foreach ($delims as $d) {
        $c = substr_count($sample, $d);
        if ($c > $bestCount) { $bestCount = $c; $best = $d; }
    }

    while (($data = fgetcsv($fh, 0, $best)) !== false) {
        // normalize
        $data = array_map(fn($x) => is_string($x) ? trim($x) : $x, $data);
        if (count($data) === 1 && $data[0] === '') continue;
        $rows[] = $data;
    }
    fclose($fh);
    return $rows;
}

function xlsx_shared_strings(\ZipArchive $zip): array {
    $strings = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) return $strings;
    $sx = @simplexml_load_string($xml);
    if (!$sx) return $strings;
    foreach ($sx->si as $si) {
        // handle <t> or rich text <r><t>
        if (isset($si->t)) {
            $strings[] = (string)$si->t;
        } else {
            $txt = '';
            foreach ($si->r as $r) $txt .= (string)$r->t;
            $strings[] = $txt;
        }
    }
    return $strings;
}

function col_to_index(string $col): int {
    $col = strtoupper($col);
    $n = 0;
    for ($i=0; $i<strlen($col); $i++) {
        $n = $n*26 + (ord($col[$i]) - 64);
    }
    return $n - 1; // zero-based
}

function parse_xlsx_rows(string $path, int $maxRows = 5000): array {
    $rows = [];
    if (!class_exists('ZipArchive')) return $rows;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $rows;

    $shared = xlsx_shared_strings($zip);
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        // try first sheet from workbook
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook !== false) {
            $wx = @simplexml_load_string($workbook);
            if ($wx && isset($wx->sheets->sheet[0]['sheetId'])) {
                $sid = (string)$wx->sheets->sheet[0]['sheetId'];
                // relationship mapping is complex; fallback still to sheet1
            }
        }
        $zip->close();
        return $rows;
    }

    $sx = @simplexml_load_string($sheetXml);
    if (!$sx) { $zip->close(); return $rows; }

    $ns = $sx->getNamespaces(true);
    // iterate rows
    $count = 0;
    foreach ($sx->sheetData->row as $row) {
        $count++;
        if ($count > $maxRows) break;
        $cells = [];
        foreach ($row->c as $c) {
            $r = (string)$c['r']; // e.g., C5
            if (!preg_match('/^([A-Z]+)(\d+)$/i', $r, $m)) continue;
            $col = col_to_index($m[1]);
            $type = (string)$c['t'];
            $val = '';
            if (isset($c->v)) {
                $v = (string)$c->v;
                if ($type === 's') {
                    $idx = (int)$v;
                    $val = $shared[$idx] ?? '';
                } else {
                    $val = $v;
                }
            } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                $val = (string)$c->is->t;
            }
            $cells[$col] = trim((string)$val);
        }
        if (!$cells) continue;
        $maxCol = max(array_keys($cells));
        $arr = [];
        for ($i=0; $i<=$maxCol; $i++) $arr[] = $cells[$i] ?? '';
        // skip all-empty
        $nonEmpty = false;
        foreach ($arr as $v) { if ($v !== '') { $nonEmpty = true; break; } }
        if ($nonEmpty) $rows[] = $arr;
    }

    $zip->close();
    return $rows;
}

function normalize_accessory_rows(array $rows): array {
    // returns ['header' => [...], 'data' => [['sku'=>..,'name'=>..,'function'=>..,'raw'=>..], ...], 'warnings'=>[]]
    $warnings = [];
    $headerRowIdx = null;
    $colSku = null; $colName = null; $colFn = null;

    // find header
    foreach ($rows as $i => $r) {
        $joined = strtolower(implode(' | ', $r));
        if (strpos($joined, 'katalog') !== false || strpos($joined, 'catalog') !== false) {
            $headerRowIdx = $i;
            break;
        }
    }

    if ($headerRowIdx !== null) {
        $hdr = $rows[$headerRowIdx];
        foreach ($hdr as $ci => $v) {
            $lv = strtolower(trim((string)$v));
            if ($colSku === null && (strpos($lv, 'katalog') !== false || strpos($lv, 'sku') !== false || strpos($lv, 'kode') !== false)) $colSku = $ci;
            if ($colName === null && (strpos($lv, 'aksesoris') !== false || strpos($lv, 'aksesori') !== false || strpos($lv, 'deskripsi') !== false || strpos($lv,'nama')!==false)) $colName = $ci;
            if ($colFn === null && (strpos($lv, 'fungsi') !== false || strpos($lv, 'function') !== false)) $colFn = $ci;
        }
        $start = $headerRowIdx + 1;
    } else {
        $warnings[] = "Header kolom tidak terdeteksi. Aku pakai default mapping: B=Nama, C=SKU/Katalog, D=Fungsi.";
        $start = 1; // assume first row header or numbering
        $colName = 1; $colSku = 2; $colFn = 3;
    }

    if ($colSku === null) { $colSku = 2; $warnings[] = "Kolom SKU/Katalog tidak terdeteksi → fallback ke kolom C."; }
    if ($colName === null) { $colName = 1; $warnings[] = "Kolom Nama/Aksesoris tidak terdeteksi → fallback ke kolom B."; }
    if ($colFn === null) { $colFn = 3; }

    $data = [];
    $seen = [];
    for ($i=$start; $i<count($rows); $i++) {
        $r = $rows[$i];
        $sku = trim((string)($r[$colSku] ?? ''));
        $name = trim((string)($r[$colName] ?? ''));
        $fn = trim((string)($r[$colFn] ?? ''));
        if ($sku === '' && $name === '') continue;
        $sku = preg_replace('/\s+/', '', $sku);
        if ($sku === '') continue;
        if (isset($seen[$sku])) continue; // dedup within file (keep first)
        $seen[$sku] = true;
        if ($name === '') $name = $sku;
        $data[] = ['sku'=>$sku,'name'=>$name,'function'=>$fn,'raw'=>$r];
    }

    if (!$data) $warnings[] = "Tidak ada baris data valid yang terbaca dari file.";
    return ['data'=>$data,'warnings'=>$warnings];
}

function log_audit(array $payload): void {
    $root = project_root();
    $dir = $root . '/master/uploads/audit_logs';
    ensure_dir($dir);
    $file = $dir . '/reg_alkes_' . date('Ymd') . '.log';
    $payload['ts'] = date('c');
    @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

function fetch_manufacture(PDO $pdo, string $code): ?array {
    $stmt = $pdo->prepare("SELECT * FROM master_manufactures WHERE manufacture_code = ? OR manufactures_code = ? LIMIT 1");
    $stmt->execute([$code, $code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_manufactures_list(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, manufacture_code, manufacture_name, status FROM master_manufactures ORDER BY manufacture_name ASC");
    return $stmt->fetchAll() ?: [];
}

function master_products_exists_skus(PDO $pdo, array $skus): array {
    if (!$skus) return [];
    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $stmt = $pdo->prepare("SELECT sku FROM master_products WHERE sku IN ($placeholders)");
    $stmt->execute($skus);
    $out = [];
    while ($r = $stmt->fetch()) $out[$r['sku']] = true;
    return $out;
}

function import_to_master_products(PDO $pdo, int $manufacture_id, string $akl_no, array $items, string $mode = 'skip'): array {
    // mode: skip|upsert
    $skus = array_map(fn($x)=>$x['sku'], $items);
    $exists = master_products_exists_skus($pdo, $skus);

    $inserted=0; $updated=0; $skipped=0; $errors=0;
    $details = [];

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO master_products (sku, products_name, general_name, no_akl, akl_reg_no, licence_number, manufacture_id, status, price, stock_qty, current_stock, unit, product_type)
                              VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0.00, 0, 0, 'unit', 'SINGLE')");
        $upd = $pdo->prepare("UPDATE master_products SET products_name=?, general_name=?, no_akl=?, akl_reg_no=?, licence_number=?, manufacture_id=?, status='active', updated_at=NOW() WHERE sku=?");

        foreach ($items as $it) {
            $sku = $it['sku'];
            $name = $it['name'];
            $fn = $it['function'];
            if (isset($exists[$sku])) {
                if ($mode === 'upsert') {
                    try {
                        $upd->execute([$name, $fn, $akl_no, $akl_no, $akl_no, $manufacture_id, $sku]);
                        $updated++;
                        $details[] = ['sku'=>$sku,'action'=>'updated'];
                    } catch (\Throwable $e) {
                        $errors++;
                        $details[] = ['sku'=>$sku,'action'=>'error','msg'=>$e->getMessage()];
                    }
                } else {
                    $skipped++;
                    $details[] = ['sku'=>$sku,'action'=>'skipped'];
                }
            } else {
                try {
                    $ins->execute([$sku, $name, $fn, $akl_no, $akl_no, $akl_no, $manufacture_id]);
                    $inserted++;
                    $details[] = ['sku'=>$sku,'action'=>'inserted'];
                } catch (\Throwable $e) {
                    $errors++;
                    $details[] = ['sku'=>$sku,'action'=>'error','msg'=>$e->getMessage()];
                }
            }
        }

        if ($errors > 0) {
            // keep transaction but still commit successful ones; user asked human, not all-or-nothing
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return compact('inserted','updated','skipped','errors','details');
}

function update_case_import_meta(PDO $pdo, string $case_code, int $manufacture_id, string $nie_no, string $updated_by = ''): void {
    // Best-effort: update sku_imported_count + sku_last_import_at on hrl_reg_alkes_cases
    if ($case_code === '' || $manufacture_id <= 0 || $nie_no === '') return;
    try {
        $q = $pdo->prepare("SELECT id, nie_no FROM hrl_reg_alkes_cases WHERE case_code=? LIMIT 1");
        $q->execute([$case_code]);
        $row = $q->fetch();
        if (!$row) return;
        $case_id = (int)($row['id'] ?? 0);
        if ($case_id <= 0) return;

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM master_products WHERE manufacture_id=? AND (akl_reg_no=? OR no_akl=?)");
        $cnt->execute([$manufacture_id, $nie_no, $nie_no]);
        $c = (int)$cnt->fetchColumn();

        // update meta (ignore if columns missing)
        $pdo->prepare("UPDATE hrl_reg_alkes_cases SET sku_imported_count=?, sku_last_import_at=NOW(), updated_by=? WHERE id=?")
            ->execute([$c, $updated_by, $case_id]);

        // If NIE belum diisi di case, isi otomatis
        $oldNie = trim((string)($row['nie_no'] ?? ''));
        if ($oldNie === '') {
            $pdo->prepare("UPDATE hrl_reg_alkes_cases SET nie_no=?, updated_by=? WHERE id=?")
                ->execute([$nie_no, $updated_by, $case_id]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

// ---------- Routing ----------
$root = project_root();
$uploads_manu = $root . '/master/uploads/manufactures';
$code = trim((string)($_GET['code'] ?? $_POST['code'] ?? ''));
$case = trim((string)($_GET['case'] ?? $_POST['case'] ?? ''));
$case = preg_replace('/[^\w\-]/', '', $case);

$manufacture = $code !== '' ? fetch_manufacture($pdo, $code) : null;

// --- Enterprise audit entity_key (dossier vs case) ---
$case_id = 0;
if ($case !== '') {
    try {
        $st = $pdo->prepare("SELECT id FROM hrl_reg_alkes_cases WHERE case_code=? LIMIT 1");
        $st->execute([$case]);
        $case_id = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $case_id = 0;
    }
}
function regalkes_dossier_entity_key(string $code, string $case_code = '', int $case_id = 0, string $akl_no = ''): ?string {
    $code = trim($code);
    $case_code = trim($case_code);
    $akl_no = trim($akl_no);

    if ($case_code !== '') {
        $prefix = ($case_id > 0) ? ('CASE#' . $case_id . '|' . $case_code) : ('CASECODE#' . $case_code);
        $parts = [$prefix];
        if ($akl_no !== '') $parts[] = 'NIE#' . $akl_no;
        if ($code !== '') $parts[] = 'MNF#' . $code;
        return implode('|', $parts);
    }
    return $code !== '' ? ('DOSSIER#' . $code) : null;
}

$qcase = ($case !== '' ? ('&case=' . urlencode($case)) : '');


if (isset($_GET['dl']) && $code !== '') {
    $type = $_GET['t'] ?? 'hrl';
    $file = $_GET['dl'] ?? '';
    $base = $uploads_manu . '/' . $code . '/' . ($type === 'internal' ? 'internal' : 'hrl');
    if ($type !== 'internal' && $case !== '') {
        $base = $uploads_manu . '/' . $code . '/hrl/cases/' . $case;
    }
    $full = safe_join($base, $file);
    if (!$full) { http_response_code(400); exit('Bad path'); }
    download_file($full, basename($full));
}

$message = '';
$errors_ui = [];
$warnings_ui = [];
$preview = null;
$import_result = null;

$dossier_hrl_dir = ($code !== '' ? ($uploads_manu . '/' . $code . '/hrl') : '');
if ($case !== '' && $code !== '') {
    $dossier_hrl_dir = $uploads_manu . '/' . $code . '/hrl/cases/' . $case;
}

$dossier_internal_dir = ($code !== '' ? ($uploads_manu . '/' . $code . '/internal') : '');

if ($code !== '') {
    ensure_dir($dossier_hrl_dir);
    ensure_dir($dossier_internal_dir);
}

// Handle upload AKL/AKD output PDF to dossier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_output' && $code !== '') {

    csrf_check_or_die();

// Role gate: upload dossier output hanya HRL/ITC (atau SYS)
if (function_exists('eg_can_import_sku') && !eg_can_import_sku()) {
    $errors_ui[] = "Akses ditolak: hanya HRL/Legal yang boleh upload output dossier.";
    regalkes_audit($pdo, 'deny_upload_output', regalkes_dossier_entity_key($code, $case, $case_id, trim((string)($_POST['akl_no'] ?? ''))), [
        'case_code' => $case,
        'case_id' => $case_id,
        'code' => $code,
        'dept' => function_exists('eg_dept') ? eg_dept() : null
    ]);
}
    $akl_no = trim((string)($_POST['akl_no'] ?? ''));
    $doc_type = strtoupper(trim((string)($_POST['doc_type'] ?? 'AKL')));
    if (!in_array($doc_type, ['AKL','AKD'], true)) $doc_type = 'AKL';

    if ($akl_no === '') $errors_ui[] = "Nomor AKL/AKD wajib diisi untuk upload output.";
    if (!isset($_FILES['output_pdf']) || ($_FILES['output_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors_ui[] = "File PDF output belum dipilih.";

    if (!$errors_ui) {
        $tmp = $_FILES['output_pdf']['tmp_name'];
        $origName = safe_filename((string)($_FILES['output_pdf']['name'] ?? ''));
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') $errors_ui[] = "File output harus PDF.";
        else {
            $safeNo = preg_replace('/[^A-Za-z0-9\-_.]/', '', $akl_no);
            $fname = $doc_type . '_' . $safeNo . '_' . date('Ymd_His') . '.pdf';
            $dest = $dossier_hrl_dir . '/' . $fname;
            if (@move_uploaded_file($tmp, $dest)) {
                $message = "Output {$doc_type} berhasil diupload ke dossier HRL: {$fname}";
                log_audit([
                    'event' => 'upload_output',
                    'user_id' => (int)($_SESSION['user_id'] ?? 0),
                    'level' => (string)($_SESSION['level'] ?? ''),
                    'manufacture_code' => $code,
                    'akl_no' => $akl_no,
                    'file' => $fname,
                ]);
                regalkes_audit($pdo, 'upload_output', regalkes_dossier_entity_key($code, $case, $case_id, $akl_no), ['code'=>$code, 'case_code'=>$case, 'case_id'=>$case_id, 'akl_no'=>$akl_no, 'file'=>$fname]);
            } else {
                $errors_ui[] = "Gagal menyimpan file output. Cek permission folder uploads.";
            }
        }
    }
}

// Handle upload accessory file to dossier (optional)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_accessory' && $code !== '') {

    csrf_check_or_die();

// Role gate: upload aksesoris hanya HRL/ITC (atau SYS)
if (function_exists('eg_can_import_sku') && !eg_can_import_sku()) {
    $errors_ui[] = "Akses ditolak: hanya HRL/Legal yang boleh upload file aksesoris.";
    regalkes_audit($pdo, 'deny_upload_accessory', regalkes_dossier_entity_key($code, $case, $case_id, ''), [
        'case_code' => $case,
        'case_id' => $case_id,
        'code' => $code,
        'dept' => function_exists('eg_dept') ? eg_dept() : null
    ]);
}
    if (!isset($_FILES['accessory_file']) || ($_FILES['accessory_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors_ui[] = "File aksesoris belum dipilih.";
    if (!$errors_ui) {
        $name = safe_filename((string)($_FILES['accessory_file']['name'] ?? ''));
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','csv'], true)) $errors_ui[] = "File aksesoris harus .xlsx atau .csv";
        else {
            $fname = 'FILE_AKSESORIS_' . date('Ymd_His') . '.' . $ext;
            $dest = $dossier_hrl_dir . '/' . $fname;
            if (@move_uploaded_file($_FILES['accessory_file']['tmp_name'], $dest)) {
                $message = "File aksesoris berhasil diupload ke dossier HRL: {$fname}";
                log_audit([
                    'event' => 'upload_accessory',
                    'user_id' => (int)($_SESSION['user_id'] ?? 0),
                    'level' => (string)($_SESSION['level'] ?? ''),
                    'manufacture_code' => $code,
                    'file' => $fname,
                ]);
                regalkes_audit($pdo, 'upload_accessory', regalkes_dossier_entity_key($code, $case, $case_id, ''), ['code'=>$code, 'case_code'=>$case, 'case_id'=>$case_id, 'file'=>$fname]);
            } else $errors_ui[] = "Gagal menyimpan file aksesoris. Cek permission folder uploads.";
        }
    }
}

// Preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview' && $code !== '') {

    csrf_check_or_die();
    $akl_no = trim((string)($_POST['akl_no'] ?? ''));
    $selected = trim((string)($_POST['accessory_selected'] ?? ''));
    if ($akl_no === '') $errors_ui[] = "Nomor AKL/AKD wajib diisi.";
    if ($selected === '') $errors_ui[] = "Pilih file aksesoris dari dossier (atau upload dulu).";
    $pdf_ok = false;
    if ($akl_no !== '') {
        $safeNo = preg_replace('/[^A-Za-z0-9\-_.]/', '', $akl_no);
        // look for output pdf containing akl_no in filename OR any pdf uploaded recently (human-friendly)
        $pdfs = list_files($dossier_hrl_dir, ['pdf']);
        foreach ($pdfs as $p) {
            if (stripos($p['name'], $safeNo) !== false) { $pdf_ok = true; break; }
        }
        if (!$pdf_ok) $warnings_ui[] = "Aku tidak menemukan PDF output AKL/AKD yang mengandung nomor '{$akl_no}' di dossier HRL. Kamu bisa lanjut preview, tapi disarankan upload output dulu.";
    }

    if (!$errors_ui) {
        $full = safe_join($dossier_hrl_dir, $selected);
        if (!$full) $errors_ui[] = "File aksesoris tidak valid.";
        else {
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            $rows = ($ext === 'csv') ? parse_csv_rows($full) : parse_xlsx_rows($full);
            if (!$rows) $errors_ui[] = "Gagal membaca file aksesoris. Jika XLSX, pastikan ZipArchive aktif. Kalau perlu, convert ke CSV.";
            else {
                $norm = normalize_accessory_rows($rows);
                $warnings_ui = array_merge($warnings_ui, $norm['warnings']);
                $items = $norm['data'];
                $preview = [
                    'file' => basename($full),
                    'akl_no' => $akl_no,
                    'count' => count($items),
                    'rows' => array_slice($items, 0, 20),
                ];
                $_SESSION['reg_alkes_preview'] = [
                    'code' => $code,
                    'akl_no' => $akl_no,
                    'file' => basename($full),
                    'items' => $items,
                    'ts' => time(),
                ];
            }
        }
    }
}

// Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import' && $code !== '') {

    csrf_check_or_die();

// Role gate: Import SKU ke master_products hanya HRL/ITC (atau SYS)
if (function_exists('eg_can_import_sku') && !eg_can_import_sku()) {
    $errors_ui[] = "Akses ditolak: hanya HRL/Legal yang boleh Import ke master_products.";
    regalkes_audit($pdo, 'deny_import', regalkes_dossier_entity_key($code, $case, $case_id, $akl_no ?? ''), [
        'case_code' => $case,
        'case_id' => $case_id,
        'code' => $code,
        'dept' => function_exists('eg_dept') ? eg_dept() : null
    ]);
}
    $mode = ($_POST['mode'] ?? 'skip') === 'upsert' ? 'upsert' : 'skip';
    $sess = $_SESSION['reg_alkes_preview'] ?? null;
    if (!$sess || ($sess['code'] ?? '') !== $code) $errors_ui[] = "Preview session tidak ditemukan. Silakan klik Preview dulu.";
    else {
        $akl_no = (string)($sess['akl_no'] ?? '');
        $items = $sess['items'] ?? [];
        if (!$manufacture) $errors_ui[] = "Manufacture tidak ditemukan. Pastikan code benar.";
        if ($akl_no === '') $errors_ui[] = "Nomor AKL/AKD kosong.";
        if (!$items) $errors_ui[] = "Tidak ada item dari preview.";
        if (!$errors_ui) {
            // Optional: require output pdf exists for this akl_no (hard rule for phase 3)
            $safeNo = preg_replace('/[^A-Za-z0-9\-_.]/', '', $akl_no);
            $pdf_ok = false;
            $pdfs = list_files($dossier_hrl_dir, ['pdf']);
            foreach ($pdfs as $p) if (stripos($p['name'], $safeNo) !== false) { $pdf_ok = true; break; }
            if (!$pdf_ok) $errors_ui[] = "Output PDF AKL/AKD dengan nomor '{$akl_no}' belum ada di dossier HRL. Upload dulu, baru import ke master_products.";
        }
        if (!$errors_ui) {
            $import_result = import_to_master_products($pdo, (int)$manufacture['id'], $akl_no, $items, $mode);
            // Update meta di Control Tower (jika dipanggil dari Case)
            if ($case !== '') {
                update_case_import_meta($pdo, $case, (int)$manufacture['id'], $akl_no, (string)($_SESSION['username'] ?? ''));
            }
            log_audit([
                'event' => 'import_master_products',
                'user_id' => (int)($_SESSION['user_id'] ?? 0),
                'level' => (string)($_SESSION['level'] ?? ''),
                'manufacture_code' => $code,
                'akl_no' => $akl_no,
                'file' => (string)($sess['file'] ?? ''),
                'mode' => $mode,
                'counts' => [
                    'inserted' => $import_result['inserted'],
                    'updated' => $import_result['updated'],
                    'skipped' => $import_result['skipped'],
                    'errors' => $import_result['errors'],
                    'total' => count($items),
                ],
            ]);
            regalkes_audit($pdo, 'import_master_products', regalkes_dossier_entity_key($code, $case, $case_id, $akl_no), [
                'case_code' => $case,
                'case_id' => $case_id,
                'code' => $code,
                'akl_no' => $akl_no,
                'mode' => $mode,
                'inserted' => (int)($import_result['inserted'] ?? 0),
                'updated' => (int)($import_result['updated'] ?? 0),
                'skipped' => (int)($import_result['skipped'] ?? 0),
                'errors' => (int)($import_result['errors'] ?? 0),
                'total' => count($items)
            ]);
            $message = "Import selesai. Inserted={$import_result['inserted']}, Updated={$import_result['updated']}, Skipped={$import_result['skipped']}, Errors={$import_result['errors']}.";
            unset($_SESSION['reg_alkes_preview']);
        }
    }
}

// List dossiers
$hrl_files = ($code !== '') ? list_files($dossier_hrl_dir, ['pdf','xlsx','csv','doc','docx']) : [];
$internal_files = ($code !== '') ? list_files($dossier_internal_dir, ['pdf','xlsx','csv','doc','docx']) : [];

$manufactures = fetch_manufactures_list($pdo);

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('HRL Registrasi Alkes → Master Products (Phase 3)', [
  'active' => 'hrl_reg_alkes',
  'breadcrumbs' => [
    ['label' => 'HRL Reg Alkes', 'url' => $baseProject . '/hrl_reg_alkes/index.php'],
    'HRL Registrasi Alkes → Master Products (Phase 3)',
  ],
  'extra_head' => '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209" rel="stylesheet">'
    . '<link href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209" rel="stylesheet">',
]);
?>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h4 class="mb-0">HRL Registrasi Alkes → Master Products</h4>
      <div class="text-muted small">Phase 3: dossier-aware + preview + idempotent import + audit log</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="../master/master_data.php">Master Data</a>
      <?php if ($code !== ''): ?>
        <a class="btn btn-outline-info btn-sm" href="../master/manufactures_docs.php?code=<?=h($code)?>">Buka Manufactures Docs</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?=h($message)?></div>
  <?php endif; ?>
  <?php foreach ($errors_ui as $e): ?>
    <div class="alert alert-danger"><?=h($e)?></div>
  <?php endforeach; ?>
  <?php foreach ($warnings_ui as $w): ?>
    <div class="alert alert-warning"><?=h($w)?></div>
  <?php endforeach; ?>

  <div class="card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
          <label class="form-label">Pilih Manufacture (Principal)</label>
          <select name="code" class="form-select" onchange="this.form.submit()">
            <option value="">-- pilih --</option>
            <?php foreach ($manufactures as $m): ?>
              <?php $c = (string)$m['manufacture_code']; ?>
              <option value="<?=h($c)?>" <?=($c===$code?'selected':'')?>>
                <?=h($m['manufacture_name'])?> (<?=h($c)?>) <?=((int)$m['status']===1?'':'[inactive]')?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Nomor AKD/AKL (output)</label>
          <input type="text" name="akl_no_q" class="form-control" value="<?=h($_POST['akl_no'] ?? $_GET['akl_no_q'] ?? '')?>" placeholder="contoh: 20903321858">
          <div class="form-text">Dipakai untuk validasi PDF output & pengisian no_akl/akl_reg_no di master_products.</div>
        </div>
        <div class="col-md-3 text-end">
          <button type="submit" class="btn btn-primary w-100">Load Dossier</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($code !== '' && $manufacture): ?>
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Dossier HRL Registrasi (Manufacture)</strong>
            <span class="badge text-bg-secondary"><?=h($code)?></span>
          </div>
          <div class="card-body">
            <div class="mb-2">
              <div class="small text-muted">Manufacture: <strong><?=h($manufacture['manufacture_name'])?></strong></div>
            </div>

            <div class="border rounded p-3 mb-3">
              <div class="fw-semibold mb-2">Upload Output AKL/AKD (PDF)</div>
              <form method="post" enctype="multipart/form-data" class="row g-2">
                <input type="hidden" name="code" value="<?=h($code)?>">
                <input type="hidden" name="action" value="upload_output">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="col-md-3">
                  <select name="doc_type" class="form-select">
                    <option value="AKL">AKL</option>
                    <option value="AKD">AKD</option>
                  </select>
                </div>
                <div class="col-md-5">
                  <input name="akl_no" class="form-control" value="<?=h($_POST['akl_no'] ?? $_GET['akl_no_q'] ?? '')?>" placeholder="Nomor AKL/AKD">
                </div>
                <div class="col-md-4">
                  <input type="file" name="output_pdf" class="form-control" accept="application/pdf">
                </div>
                <div class="col-12">
                  <button class="btn btn-outline-primary btn-sm">Upload Output</button>
                </div>
              </form>
            </div>

            <div class="border rounded p-3 mb-3">
              <div class="fw-semibold mb-2">File Aksesoris (XLSX/CSV) — simpan di Dossier HRL</div>
              <form method="post" enctype="multipart/form-data" class="row g-2">
                <input type="hidden" name="code" value="<?=h($code)?>">
                <input type="hidden" name="action" value="upload_accessory">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="col-md-8">
                  <input type="file" name="accessory_file" class="form-control" accept=".xlsx,.csv">
                </div>
                <div class="col-md-4">
                  <button class="btn btn-outline-success w-100">Upload File Aksesoris</button>
                </div>
              </form>
              <div class="form-text">Jika file sudah ada dari PQP, tidak perlu upload lagi.</div>
            </div>

            <div class="fw-semibold mb-2">Daftar Dokumen (HRL Registrasi)</div>
            <?php if (!$hrl_files): ?>
              <div class="text-muted">Belum ada dokumen.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>File</th><th class="text-end">Size</th><th></th></tr></thead>
                  <tbody>
                  <?php foreach ($hrl_files as $f): ?>
                    <tr>
                      <td><?=h($f['name'])?><div class="text-muted small"><?=date('Y-m-d H:i', (int)$f['mtime'])?></div></td>
                      <td class="text-end"><?=number_format($f['size']/1024,1)?> KB</td>
                      <td class="text-end">
                        <a class="btn btn-outline-secondary btn-sm" href="?code=<?=h($code)?><?=$qcase?>&t=hrl&dl=<?=urlencode($f['name'])?>">Download</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><strong>Internal Wajib (Manufacture)</strong></div>
          <div class="card-body">
            <?php if (!$internal_files): ?>
              <div class="text-muted">Belum ada dokumen.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>File</th><th class="text-end">Size</th><th></th></tr></thead>
                  <tbody>
                  <?php foreach ($internal_files as $f): ?>
                    <tr>
                      <td><?=h($f['name'])?><div class="text-muted small"><?=date('Y-m-d H:i', (int)$f['mtime'])?></div></td>
                      <td class="text-end"><?=number_format($f['size']/1024,1)?> KB</td>
                      <td class="text-end">
                        <a class="btn btn-outline-secondary btn-sm" href="?code=<?=h($code)?><?=$qcase?>&t=internal&dl=<?=urlencode($f['name'])?>">Download</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header"><strong>Validasi & Import → master_products (ACTIVE)</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="code" value="<?=h($code)?>">
          <input type="hidden" name="action" value="preview">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="col-md-4">
            <label class="form-label">Nomor AKD/AKL</label>
            <input name="akl_no" class="form-control" value="<?=h($_POST['akl_no'] ?? $_GET['akl_no_q'] ?? '')?>" placeholder="contoh: 20903321858">
          </div>
          <div class="col-md-5">
            <label class="form-label">Pilih File Aksesoris dari Dossier HRL</label>
            <select name="accessory_selected" class="form-select">
              <option value="">-- pilih file --</option>
              <?php foreach ($hrl_files as $f): ?>
                <?php $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)); ?>
                <?php if (in_array($ext, ['xlsx','csv'], true)): ?>
                  <option value="<?=h($f['name'])?>" <?=((($_POST['accessory_selected'] ?? '')===$f['name'])?'selected':'')?>>
                    <?=h($f['name'])?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Kalau file tidak ada di list, upload dulu di bagian atas.</div>
          </div>
          <div class="col-md-3 text-end">
            <button class="btn btn-primary w-100">Preview</button>
          </div>
        </form>

        <?php if ($preview): ?>
          <hr>
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="fw-semibold">Preview: <?=h($preview['file'])?></div>
              <div class="text-muted small">Total terbaca: <strong><?=h($preview['count'])?></strong> SKU</div>
            </div>
          </div>

          <div class="table-responsive mt-2">
            <table class="table table-sm table-striped">
              <thead><tr><th>#</th><th>SKU/Katalog</th><th>Nama</th><th>Fungsi</th></tr></thead>
              <tbody>
              <?php foreach ($preview['rows'] as $i => $r): ?>
                <tr>
                  <td><?=($i+1)?></td>
                  <td><?=h(strtoupper($r['sku'] ?? ''))?></td>
                  <td><?=h(strtoupper($r['name'] ?? ''))?></td>
                  <td><?=h($r['function'])?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="code" value="<?=h($code)?>">
            <input type="hidden" name="action" value="import">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div class="col-md-4">
              <label class="form-label">Mode Import</label>
              <select name="mode" class="form-select">
                <option value="skip">Skip jika SKU sudah ada</option>
                <option value="upsert">Upsert (update jika SKU sudah ada)</option>
              </select>
              <div class="form-text">Phase 3: idempotent. Upsert aman jika HRL ingin perbaiki nama/fungsi.</div>
            </div>
            <div class="col-md-8 text-end">
              <button class="btn btn-success">Import ke master_products</button>
            </div>
          </form>
        <?php endif; ?>

        <?php if ($import_result): ?>
          <hr>
          <div class="alert alert-info">
            <div class="fw-semibold">Summary Import</div>
            Inserted: <strong><?=h($import_result['inserted'])?></strong> |
            Updated: <strong><?=h($import_result['updated'])?></strong> |
            Skipped: <strong><?=h($import_result['skipped'])?></strong> |
            Errors: <strong><?=h($import_result['errors'])?></strong>
          </div>

          <div class="d-flex gap-2">
            <a class="btn btn-outline-primary btn-sm" href="../master/master_products.php">Buka Master Products</a>
            <a class="btn btn-outline-secondary btn-sm" href="?code=<?=h($code)?><?=$qcase?>">Import Lagi</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($code !== ''): ?>
    <div class="alert alert-danger">Manufacture dengan code <strong><?=h($code)?></strong> tidak ditemukan di DB.</div>
  <?php endif; ?>

  <div class="text-muted small mt-3">
    Catatan: EXP barang tidak diisi di sini. EXP diinput oleh WQS saat inventory datang (LOT/Serial/EXP/QTY + alokasi).
  </div>

  <?php
  $audit_rows = [];
  if (function_exists('master_audit')) {
      try {
          $st = $pdo->prepare("SELECT created_at, action, record_code, username, description FROM system_audit_logs WHERE module='hrl_reg_alkes' ORDER BY created_at DESC LIMIT 50");
          $st->execute();
          $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {}
  }
  if (!empty($audit_rows)): ?>
  <div class="card mt-3">
    <div class="card-header">Audit Log (Last 50 events)</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-dark mb-0">
          <thead><tr><th>Time</th><th>Action</th><th>Code</th><th>User</th><th>Description</th></tr></thead>
          <tbody>
            <?php foreach ($audit_rows as $a): ?>
              <tr>
                <td><?= h($a['created_at'] ?? '') ?></td>
                <td><?= h($a['action'] ?? '') ?></td>
                <td><?= h($a['record_code'] ?? '') ?></td>
                <td><?= h($a['username'] ?? '') ?></td>
                <td><?= h($a['description'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script src="<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/js/bootstrap.bundle.min.js?v=20260209"></script>
<?php rmi_footer(); ?>
