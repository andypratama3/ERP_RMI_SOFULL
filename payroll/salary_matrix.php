<?php
require_once dirname(__DIR__) . '/master/auth.php'; // enforce login (static scan + runtime)
require_login(); // enforce login guard (static scan marker + runtime)
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';

// Authorization: allow FIN/HRL/ACT or admin/superadmin/owner; prefer RBAC if available
if (function_exists('rbac_require')) {
    rbac_require(['PAYROLL.VIEW']);
} else {
    $__role  = strtoupper((string)($_SESSION['role'] ?? ''));
    $__level = strtoupper((string)($_SESSION['level'] ?? ''));
    $__dept  = strtoupper((string)($_SESSION['department'] ?? ''));
    $__admin = in_array($__role, ['ADMIN','SUPERADMIN','SYS'], true) || in_array($__level, ['ADMIN','SUPERADMIN','SYS'], true);
    $__allowedDepts = ['FIN','HRL','ACT'];
    if (!$__admin && !in_array($__dept, $__allowedDepts, true)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

if (defined('APP_ENV') && APP_ENV === 'PRODUCTION') {
    @ini_set('display_errors','0');
}

$flash = payroll_flash_get();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && function_exists('verify_csrf')) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

function sm_parse_num($v): float {
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    $s = str_ireplace(['rp', ' '], '', $s);

    // If looks like 1.800.000 (dots as thousand separators)
    if (preg_match('/\d\.\d{3}(\.\d{3})+/', $s)) {
        $s = str_replace('.', '', $s);
    }
    $s = str_replace(',', '', $s);
    $s = preg_replace('/[^0-9\-\.]/', '', $s);
    if ($s === '' || $s === '-' || $s === '.') return 0.0;
    return (float)$s;
}

// ---- XLSX reader (tanpa composer) ----
function xlsx_read_rows(string $filePath, ?string $sheetName = null): array {
    if (!class_exists('ZipArchive')) {
        throw new Exception("ZipArchive tidak aktif. Aktifkan extension zip di PHP.");
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new Exception("Gagal membuka file XLSX.");
    }

    // Shared strings
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml) {
        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($sharedXml);
        if ($sx && isset($sx->si)) {
            foreach ($sx->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                }
                $shared[] = $text;
            }
        }
    }

    // workbook: sheet list
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if (!$wbXml) { $zip->close(); throw new Exception("workbook.xml tidak ditemukan."); }
    libxml_use_internal_errors(true);
    $wb = simplexml_load_string($wbXml);
    if (!$wb) { $zip->close(); throw new Exception("workbook.xml tidak valid."); }

    $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (!$relsXml) { $zip->close(); throw new Exception("workbook rels tidak ditemukan."); }
    $rels = simplexml_load_string($relsXml);
    if (!$rels) { $zip->close(); throw new Exception("workbook rels tidak valid."); }

    // choose sheet
    $targetRid = null;
    foreach ($wb->sheets->sheet as $sh) {
        $name = (string)$sh['name'];
        $rid  = (string)$sh->attributes('r', true)['id'];
        if ($sheetName === null || strtoupper($name) === strtoupper($sheetName)) {
            $targetRid = $rid;
            break;
        }
    }
    if (!$targetRid) {
        $zip->close();
        throw new Exception("Sheet '{$sheetName}' tidak ditemukan di XLSX.");
    }

    $sheetTarget = null;
    foreach ($rels->Relationship as $rel) {
        if ((string)$rel['Id'] === $targetRid) {
            $sheetTarget = (string)$rel['Target'];
            break;
        }
    }
    if (!$sheetTarget) { $zip->close(); throw new Exception("Sheet target tidak ditemukan."); }

    $sheetTarget = ltrim($sheetTarget, '/');
  // Some XLSX creators already include 'xl/' in the relationship target
  if (stripos($sheetTarget, 'xl/') === 0) {
    $sheetPath = $sheetTarget;
  } else {
    $sheetPath = 'xl/' . $sheetTarget;
  }
    $sheetXml = $zip->getFromName($sheetPath);
    if (!$sheetXml) { $zip->close(); throw new Exception("Sheet XML tidak terbaca: {$sheetPath}"); }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) { $zip->close(); throw new Exception("Sheet XML invalid."); }

    $colToIdx = function(string $letters): int {
        $letters = strtoupper($letters);
        $n = 0;
        for ($i=0; $i<strlen($letters); $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1; // 0-based
    };

    $rows = [];
    if (isset($sheet->sheetData->row)) {
        foreach ($sheet->sheetData->row as $row) {
            $r = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                if (!preg_match('/^([A-Z]+)\d+$/', $ref, $m)) continue;
                $ci = $colToIdx($m[1]);

                $type = (string)$c['t'];
                $val = '';
                if ($type === 's') {
                    $idx = (int)$c->v;
                    $val = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string)$c->is->t;
                } elseif ($type === 'b') {
                    $val = ((string)$c->v === '1') ? '1' : '0';
                } else {
                    $val = isset($c->v) ? (string)$c->v : '';
                }
                $r[$ci] = $val;
            }

            if (!$r) continue;
            ksort($r);
            $max = max(array_keys($r));
            $out = [];
            for ($i=0; $i<=$max; $i++) $out[] = $r[$i] ?? '';
            $rows[] = $out;
        }
    }

    $zip->close();
    return $rows;
}

function xlsx_try_read_rows(string $filePath, array $preferredSheets = ['ALL','MATRIX']): array {
    // Coba sheet ALL dulu (template 3-sheet), lalu MATRIX (template single-sheet), lalu fallback sheet pertama
    foreach ($preferredSheets as $sn) {
        try { return xlsx_read_rows($filePath, $sn); } catch (Throwable $e) {}
    }
    return xlsx_read_rows($filePath, null);
}


$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

$statusFilter = strtoupper(trim($_GET['status'] ?? ''));
if (!in_array($statusFilter, ['', 'KONTRAK', 'PROBATION'], true)) $statusFilter = '';

$q = trim($_GET['q'] ?? '');

$template = strtolower(trim($_GET['template'] ?? ''));
if ($template !== '') {
    $map = [
        'kontrak' => __DIR__ . '/_templates/salary_matrix_2025_kontrak.csv',
        'probation' => __DIR__ . '/_templates/salary_matrix_2025_probation.csv',
        'all2025' => __DIR__ . '/_templates/salary_matrix_2025_all.csv',
    ];
    if (isset($map[$template]) && file_exists($map[$template])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.basename($map[$template]).'"');
        readfile($map[$template]);
        exit;
    }
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="salary_matrix_'.$year.'.csv"');
    if (function_exists('erp_audit')) { erp_audit($pdo, 'PAYROLL', 'SALARY_MATRIX', 'export_csv', ['year'=>$year,'status'=>$statusFilter,'q'=>$q]); }

    $sql = "SELECT * FROM payroll_salary_matrix WHERE matrix_year = ?";
    $params = [$year];
    if ($statusFilter !== '') {
        $sql .= " AND payroll_status = ?";
        $params[] = $statusFilter;
    }
    if ($q !== '') {
        $sql .= " AND (payroll_level LIKE ? OR job_title LIKE ?)";
        $params[] = '%'.$q.'%';
        $params[] = '%'.$q.'%';
    }
    $sql .= " ORDER BY payroll_status, payroll_level, job_title";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $out = fopen('php://output', 'w');
    fputcsv($out, ['matrix_year','payroll_status','payroll_level','job_title','take_home_pay','basic_salary','op_rate_day','work_days_default','tunj_jabatan','tunj_anak','transport','kuota']);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['matrix_year'],
            $r['payroll_status'],
            $r['payroll_level'],
            $r['job_title'],
            $r['take_home_pay'],
            $r['basic_salary'],
            $r['op_rate_day'],
            $r['work_days_default'],
            $r['tunj_jabatan'],
            $r['tunj_anak'],
            $r['transport'],
            $r['kuota'],
        ]);
    }
    fclose($out);
    exit;
}

// Import CSV / XLSX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        payroll_flash_set('danger', 'Upload file gagal. Pastikan pilih file CSV/XLSX.');
        rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
    }

    $name = (string)($_FILES['csv_file']['name'] ?? '');
    $tmp  = (string)($_FILES['csv_file']['tmp_name'] ?? '');
    $size = (int)($_FILES['csv_file']['size'] ?? 0);

    // Sanitasi nama file (untuk static scan + keamanan).
    $safeName = $name;
    if (function_exists('safe_filename')) {
        $safeName = safe_filename($safeName);
    } elseif (function_exists('rmi_safe_filename')) {
        $safeName = rmi_safe_filename($safeName);
    } else {
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($safeName));
    }

    // Validasi dasar upload
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        payroll_flash_set('danger', 'Ukuran file maksimal 10MB.');
        rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
    }
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        payroll_flash_set('danger', 'File upload tidak valid.');
        rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
    }

    $ext  = strtolower(pathinfo($safeName ?: $name, PATHINFO_EXTENSION));

    $rows = [];

    try {
        if ($ext === 'csv') {
            $fh = fopen($tmp, 'r');
            if (!$fh) {
                throw new Exception('Tidak bisa membaca file CSV.');
            }
            while (($r = fgetcsv($fh)) !== false) {
                $rows[] = $r;
            }
            fclose($fh);
        } elseif ($ext === 'xlsx') {
            $rows = xlsx_try_read_rows($tmp, ['ALL','MATRIX']);
        } else {
            throw new Exception('Format file tidak didukung. Gunakan CSV atau XLSX.');
        }
    } catch (Throwable $e) {
        payroll_flash_set('danger', 'Import gagal: ' . $e->getMessage());
        rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
    }

    if (!$rows || count($rows) < 1) {
        payroll_flash_set('danger', 'File kosong / tidak ada data.');
        rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
    }

    $header = $rows[0];
    if (!$header) {
        payroll_flash_set('danger', 'Header tidak ditemukan.');
        rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
    }

    // Map kolom -> index
    $map = [];
    foreach ($header as $i => $h) {
        $key = strtolower(trim((string)$h));
        // remove UTF-8 BOM jika ada (umum di CSV)
        $key = preg_replace('/^\xEF\xBB\xBF/', '', $key);
        if ($key !== '') $map[$key] = $i;
    }

    $need = ['matrix_year','payroll_status','payroll_level'];
    foreach ($need as $k) {
        if (!isset($map[$k])) {
            payroll_flash_set('danger', "Header wajib punya kolom: {$k}");
            rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
        }
    }

    $upsert = $pdo->prepare("INSERT INTO payroll_salary_matrix
        (matrix_year, payroll_status, payroll_level, job_title,
         take_home_pay, basic_salary, op_rate_day, work_days_default,
         tunj_jabatan, tunj_anak, transport, kuota,
         created_at, updated_at)
        VALUES
        (?, ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?, ?, ?,
         NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          job_title=VALUES(job_title),
          take_home_pay=VALUES(take_home_pay),
          basic_salary=VALUES(basic_salary),
          op_rate_day=VALUES(op_rate_day),
          work_days_default=VALUES(work_days_default),
          tunj_jabatan=VALUES(tunj_jabatan),
          tunj_anak=VALUES(tunj_anak),
          transport=VALUES(transport),
          kuota=VALUES(kuota),
          updated_at=NOW()");

    $ok = 0; $skip = 0;

    for ($ri = 1; $ri < count($rows); $ri++) {
        $row = $rows[$ri];
        if (!is_array($row)) { $skip++; continue; }

        // skip empty row
        $allEmpty = true;
        foreach ($row as $v) {
            if (trim((string)$v) !== '') { $allEmpty = false; break; }
        }
        if ($allEmpty) continue;

        $my = (int)sm_parse_num($row[$map['matrix_year']] ?? $year);
        $stt = payroll_norm_status($row[$map['payroll_status']] ?? '');
        $lvl = payroll_norm_level($row[$map['payroll_level']] ?? '');

        if ($my <= 0 || $stt === '' || $lvl === '') { $skip++; continue; }

        $job = '';
        if (isset($map['job_title'])) $job = trim((string)($row[$map['job_title']] ?? ''));

        $takeHome = isset($map['take_home_pay']) ? sm_parse_num($row[$map['take_home_pay']] ?? 0) : 0.0;
        $basic = isset($map['basic_salary']) ? sm_parse_num($row[$map['basic_salary']] ?? 0) : 0.0;
        $opRate = isset($map['op_rate_day']) ? sm_parse_num($row[$map['op_rate_day']] ?? 0) : 0.0;
        $wd = isset($map['work_days_default']) ? (int)sm_parse_num($row[$map['work_days_default']] ?? 21) : 21;

        $jab = isset($map['tunj_jabatan']) ? sm_parse_num($row[$map['tunj_jabatan']] ?? 0) : 0.0;
        $anak = isset($map['tunj_anak']) ? sm_parse_num($row[$map['tunj_anak']] ?? 0) : 0.0;
        $trans = isset($map['transport']) ? sm_parse_num($row[$map['transport']] ?? 0) : 0.0;
        $kuota = isset($map['kuota']) ? sm_parse_num($row[$map['kuota']] ?? 0) : 0.0;

        $upsert->execute([
            $my, $stt, $lvl, $job,
            $takeHome, $basic, $opRate, $wd,
            $jab, $anak, $trans, $kuota
        ]);
        $ok++;
    }

    erp_audit($pdo, 'PAYROLL', 'SALARY_MATRIX', 'import_matrix', ['file_ext'=>$ext, 'ok'=>$ok, 'skip'=>$skip]);
    if (function_exists('master_audit')) {
        master_audit($pdo, 'payroll', 'payroll_salary_matrix', 'IMPORT', null, "IMPORT-{$year}", "Salary matrix import: OK={$ok}, skip={$skip}", ['ok' => $ok, 'skip' => $skip]);
    }

    payroll_flash_set('success', "Import selesai ({$ext}). OK: {$ok}, Skip: {$skip}");
    rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
}

// Save row (manual add/edit) (manual add/edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_row') {
    $my = (int)($_POST['matrix_year'] ?? $year);
    $stt = payroll_norm_status($_POST['payroll_status'] ?? '');
    $lvl = payroll_norm_level($_POST['payroll_level'] ?? '');
    $job = trim($_POST['job_title'] ?? '');

    if ($my <= 0 || $stt === '' || $lvl === '') {
        payroll_flash_set('danger', 'Year / Status / Level wajib diisi.');
        rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
    }

    $takeHome = sm_parse_num($_POST['take_home_pay'] ?? 0);
    $basic = sm_parse_num($_POST['basic_salary'] ?? 0);
    $opRate = sm_parse_num($_POST['op_rate_day'] ?? 0);
    $wd = (int)sm_parse_num($_POST['work_days_default'] ?? 21);

    $jab = sm_parse_num($_POST['tunj_jabatan'] ?? 0);
    $anak = sm_parse_num($_POST['tunj_anak'] ?? 0);
    $trans = sm_parse_num($_POST['transport'] ?? 0);
    $kuota = sm_parse_num($_POST['kuota'] ?? 0);

    $upsert = $pdo->prepare("INSERT INTO payroll_salary_matrix
        (matrix_year, payroll_status, payroll_level, job_title,
         take_home_pay, basic_salary, op_rate_day, work_days_default,
         tunj_jabatan, tunj_anak, transport, kuota,
         created_at, updated_at)
        VALUES
        (?, ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?, ?, ?,
         NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          job_title=VALUES(job_title),
          take_home_pay=VALUES(take_home_pay),
          basic_salary=VALUES(basic_salary),
          op_rate_day=VALUES(op_rate_day),
          work_days_default=VALUES(work_days_default),
          tunj_jabatan=VALUES(tunj_jabatan),
          tunj_anak=VALUES(tunj_anak),
          transport=VALUES(transport),
          kuota=VALUES(kuota),
          updated_at=NOW()");
    $upsert->execute([$my,$stt,$lvl,$job,$takeHome,$basic,$opRate,$wd,$jab,$anak,$trans,$kuota]);

    erp_audit($pdo, 'PAYROLL', 'SALARY_MATRIX', 'save_row', ['year'=>$my,'status'=>$stt,'level'=>$lvl]);
    if (function_exists('master_audit')) {
        master_audit($pdo, 'payroll', 'payroll_salary_matrix', 'SAVE_ROW', null, "{$my}/{$stt}/{$lvl}", "Salary matrix saved: {$my}/{$stt}/{$lvl}", ['year' => $my, 'status' => $stt, 'level' => $lvl]);
    }

    payroll_flash_set('success', 'Data matrix tersimpan.');
    rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
}

// Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $stCode = $pdo->prepare("SELECT matrix_year, payroll_status, payroll_level FROM payroll_salary_matrix WHERE id = ? LIMIT 1");
        $stCode->execute([$id]);
        $r = $stCode->fetch(PDO::FETCH_ASSOC);
        $code = $r ? ($r['matrix_year'] . '/' . $r['payroll_status'] . '/' . $r['payroll_level']) : "MATRIX#{$id}";
        $st = $pdo->prepare("DELETE FROM payroll_salary_matrix WHERE id = ?");
        $st->execute([$id]);
        erp_audit($pdo, 'PAYROLL', 'SALARY_MATRIX#'.$id, 'delete', []);
        if (function_exists('master_audit')) {
            master_audit($pdo, 'payroll', 'payroll_salary_matrix', 'DELETE', $id, $code, "Salary matrix deleted: {$code}", []);
        }
        payroll_flash_set('success', 'Baris matrix dihapus.');
    }
    rmi_redirect("{$BASE_PAYROLL}/salary_matrix.php?year={$year}");
}

// Edit load
$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    if ($id > 0) {
        $st = $pdo->prepare("SELECT * FROM payroll_salary_matrix WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $edit = $st->fetch(PDO::FETCH_ASSOC);
    }
}

// List
$sql = "SELECT * FROM payroll_salary_matrix WHERE matrix_year = ?";
$params = [$year];
if ($statusFilter !== '') {
    $sql .= " AND payroll_status = ?";
    $params[] = $statusFilter;
}
if ($q !== '') {
    $sql .= " AND (payroll_level LIKE ? OR job_title LIKE ?)";
    $params[] = '%'.$q.'%';
    $params[] = '%'.$q.'%';
}
$sql .= " ORDER BY payroll_status, payroll_level, job_title";
$st = $pdo->prepare($sql);
$st->execute($params);
$list = $st->fetchAll(PDO::FETCH_ASSOC);

rmi_header('Master Golongan Gaji', 'payroll', ['base_project'=>$BASE_PROJECT]);
?>

<?php if ($flash): ?>
  <div class="alert alert-<?= payroll_h($flash['type']) ?>"><?= payroll_h($flash['msg']) ?></div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <div class="fw-bold" style="font-size:18px;">Master Golongan Gaji</div>
    <div class="rmi-muted" style="font-size:12px;">
      Payroll otomatis mengambil <b>status</b> & <b>level</b> dari <b>Master Employee</b> (payroll_status & payroll_level).
    </div>
  </div>
 <div class="d-flex gap-2">
  <?php if ($edit): ?>
    <a class="btn btn-sm btn-rmi" href="<?= payroll_h($BASE_PAYROLL) ?>/salary_matrix.php?year=<?= (int)$year ?>">+ Tambah Baru</a>
  <?php endif; ?>
  <a class="btn btn-sm btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/index.php">Back</a>
 </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card rmi-card mb-3">
      <div class="card-header fw-bold">Import Excel / CSV</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
          <input type="hidden" name="action" value="import_csv">
          <div class="col-12">
            <input type="file" name="csv_file" class="form-control" accept=".csv,.xlsx" required>
            <div class="rmi-muted mt-1" style="font-size:12px;">
              Header wajib: <code>matrix_year,payroll_status,payroll_level</code>. (kolom lain optional)<br>Jika upload <b>XLSX</b>: gunakan sheet <code>ALL</code> (template 3-sheet) atau <code>MATRIX</code> (single sheet).
            </div>
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-rmi" type="submit">Import</button>
          </div>
        </form>
        <hr>
        <div class="d-grid gap-2">
          <a class="btn btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/salary_matrix.php?template=all2025">Download Template 2025 (All)</a>
          <a class="btn btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/salary_matrix.php?template=kontrak">Template 2025 KONTRAK</a>
          <a class="btn btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/salary_matrix.php?template=probation">Template 2025 PROBATION</a>
        </div>
      </div>
    </div>

    <div class="card rmi-card">
      <div class="card-header fw-bold"><?= $edit ? 'Edit Baris' : 'Tambah Baris' ?></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? payroll_h(csrf_token()) : '' ?>">
          <input type="hidden" name="action" value="save_row">
          <div class="col-4">
            <label class="form-label">Year</label>
            <input type="number" name="matrix_year" class="form-control" value="<?= payroll_h($edit['matrix_year'] ?? $year) ?>" min="2000" max="2100" required>
          </div>
          <div class="col-4">
            <label class="form-label">Status</label>
            <select name="payroll_status" class="form-select" required>
              <?php $sv = payroll_norm_status($edit['payroll_status'] ?? ''); ?>
              <option value="KONTRAK" <?= $sv==='KONTRAK'?'selected':'' ?>>KONTRAK</option>
              <option value="PROBATION" <?= $sv==='PROBATION'?'selected':'' ?>>PROBATION</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label">Level</label>
            <input type="text" name="payroll_level" class="form-control" value="<?= payroll_h($edit['payroll_level'] ?? '') ?>" placeholder="1A" required>
          </div>

          <div class="col-12">
            <label class="form-label">Job Title (opsional)</label>
            <input type="text" name="job_title" class="form-control" value="<?= payroll_h($edit['job_title'] ?? '') ?>" placeholder="Helper / Staff / Supervisor ...">
          </div>

          <div class="col-6">
            <label class="form-label">Take Home Pay</label>
            <input type="number" step="0.01" name="take_home_pay" class="form-control" value="<?= payroll_h($edit['take_home_pay'] ?? 0) ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Basic Salary</label>
            <input type="number" step="0.01" name="basic_salary" class="form-control" value="<?= payroll_h($edit['basic_salary'] ?? 0) ?>">
          </div>

          <div class="col-6">
            <label class="form-label">OP Rate / day</label>
            <input type="number" step="0.01" name="op_rate_day" class="form-control" value="<?= payroll_h($edit['op_rate_day'] ?? 0) ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Work Days Default</label>
            <input type="number" name="work_days_default" class="form-control" value="<?= payroll_h($edit['work_days_default'] ?? 21) ?>">
          </div>

          <div class="col-6">
            <label class="form-label">Tunj Jabatan</label>
            <input type="number" step="0.01" name="tunj_jabatan" class="form-control" value="<?= payroll_h($edit['tunj_jabatan'] ?? 0) ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Tunj Anak</label>
            <input type="number" step="0.01" name="tunj_anak" class="form-control" value="<?= payroll_h($edit['tunj_anak'] ?? 0) ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Transport</label>
            <input type="number" step="0.01" name="transport" class="form-control" value="<?= payroll_h($edit['transport'] ?? 0) ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Kuota</label>
            <input type="number" step="0.01" name="kuota" class="form-control" value="<?= payroll_h($edit['kuota'] ?? 0) ?>">
          </div>

          <div class="col-12 d-grid">
            <button class="btn btn-rmi" type="submit">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card rmi-card">
      <div class="card-header">
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-bold">Daftar Matrix</div>
          <div class="d-flex gap-2">
            <form method="get" class="d-flex gap-2" style="margin:0;">
              <input type="number" name="year" class="form-control form-control-sm" value="<?= (int)$year ?>" style="width:90px;">
              <select name="status" class="form-select form-select-sm" style="width:140px;">
                <option value="" <?= $statusFilter===''?'selected':'' ?>>ALL</option>
                <option value="KONTRAK" <?= $statusFilter==='KONTRAK'?'selected':'' ?>>KONTRAK</option>
                <option value="PROBATION" <?= $statusFilter==='PROBATION'?'selected':'' ?>>PROBATION</option>
              </select>
              <input type="text" name="q" class="form-control form-control-sm" value="<?= payroll_h($q) ?>" placeholder="Cari level / job..." style="width:180px;">
              <button class="btn btn-sm btn-outline-light" type="submit">Filter</button>
              <a class="btn btn-sm btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/salary_matrix.php?year=<?= (int)$year ?>&export=csv">CSV</a>
            </form>
          </div>
        </div>
        <div class="rmi-muted" style="font-size:12px;">Total: <?= count($list) ?> baris</div>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm rmi-table mb-0">
            <thead>
              <tr>
                <th>Status</th>
                <th>Level</th>
                <th>Job</th>
                <th class="text-end">THP</th>
                <th class="text-end">Basic</th>
                <th class="text-end">OP/day</th>
                <th class="text-end">WD</th>
                <th class="text-end">Jab</th>
                <th class="text-end">Anak</th>
                <th class="text-end">Trans</th>
                <th class="text-end">Kuota</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$list): ?>
                <tr><td colspan="12" class="text-center rmi-muted py-4">Belum ada data.</td></tr>
              <?php else: foreach ($list as $r): ?>
                <tr>
                  <td><?= payroll_h($r['payroll_status']) ?></td>
                  <td><?= payroll_h($r['payroll_level']) ?></td>
                  <td><?= payroll_h($r['job_title']) ?></td>
                  <td class="text-end"><?= number_format((float)$r['take_home_pay'],0) ?></td>
                  <td class="text-end"><?= number_format((float)$r['basic_salary'],0) ?></td>
                  <td class="text-end"><?= number_format((float)$r['op_rate_day'],0) ?></td>
                  <td class="text-end"><?= (int)$r['work_days_default'] ?></td>
                  <td class="text-end"><?= number_format((float)$r['tunj_jabatan'],0) ?></td>
                  <td class="text-end"><?= number_format((float)$r['tunj_anak'],0) ?></td>
                  <td class="text-end"><?= number_format((float)$r['transport'],0) ?></td>
                  <td class="text-end"><?= number_format((float)$r['kuota'],0) ?></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-light" href="<?= payroll_h($BASE_PAYROLL) ?>/salary_matrix.php?year=<?= (int)$year ?>&edit=<?= (int)$r['id'] ?>">Edit</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= payroll_h($BASE_PAYROLL) ?>/salary_matrix.php?year=<?= (int)$year ?>&delete=<?= (int)$r['id'] ?>" onclick="return confirm('Hapus baris ini?')">Del</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<?php rmi_footer(); ?>

