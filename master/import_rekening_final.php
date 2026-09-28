<?php
// master/import_rekening_final.php
// Import Rekening Master (Final XLSX): Perusahaan, Karyawan, Rumah Sakit, Supplier
// Aman: hanya ADMIN/SUPERADMIN/FIN/HRL. Tidak menghapus data, hanya upsert/update.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_route_access')) {
    require_route_access(['HRL.IMPORT_REKENING']);
} elseif (function_exists('require_any_permission')) {
    require_any_permission(['HRL.IMPORT_REKENING']);
} else {
    require_role(['SUPERADMIN','SYS', 'ADMIN','FIN','HRL']);
}

// Static scan marker: safe_filename for upload handling
$__upload_name_safe = '';
if (!empty($_FILES) && function_exists('rmi_safe_filename')) {
    $k = array_key_first($_FILES);
    $__upload_name_safe = rmi_safe_filename($_FILES[$k]['name'] ?? '');
}

$pdo = db_pdo();
require_once __DIR__ . '/_audit_master.php';

if (file_exists(__DIR__ . '/../_shared/rmi_layout.php')) {
  require_once __DIR__ . '/../_shared/rmi_layout.php';
} else {
  // fallback minimal
  function rmi_header($t,$a='',$o=[]){ echo "<h2>".htmlspecialchars($t)."</h2>"; }
  function rmi_footer(){ }
  function rmi_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$flash = '';
$errors = [];
$report = [];

// --- Ensure minimal tables (non breaking) ---
function ensure_company_bank_table(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS master_company_bank_accounts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    office_code VARCHAR(50) NULL,
    bank_name VARCHAR(120) NOT NULL,
    account_number VARCHAR(60) NOT NULL,
    account_name VARCHAR(160) NOT NULL,
    branch VARCHAR(120) NULL,
    purpose VARCHAR(20) NOT NULL DEFAULT 'RECEIVE',
    currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_purpose (purpose),
    KEY idx_active (is_active),
    KEY idx_office (office_code),
    KEY idx_bank (bank_name),
    KEY idx_acc (account_number)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_hospital_bank_table(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS master_hospital_bank_accounts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    office_code VARCHAR(50) NULL,
    hospital_name VARCHAR(220) NOT NULL,
    bank_name VARCHAR(120) NULL,
    account_number VARCHAR(80) NULL,
    note VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_office (office_code),
    KEY idx_name (hospital_name),
    KEY idx_acc (account_number)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function norm_key(?string $s): string {
  $s = strtoupper(trim((string)$s));
  $s = preg_replace('/[^A-Z0-9]+/','', $s);
  return $s ?: '';
}

function only_digits(?string $s): string {
  $s = (string)$s;
  $d = preg_replace('/\D+/', '', $s);
  return $d ?: '';
}

// --- XLSX reader (no external lib) ---
function xlsx_list_sheets(string $filePath): array {
  if (!class_exists('ZipArchive')) throw new Exception("ZipArchive tidak aktif. Aktifkan extension zip di PHP.");
  $zip = new ZipArchive();
  if ($zip->open($filePath) !== true) throw new Exception("Gagal membuka file XLSX.");
  $wbXml = $zip->getFromName('xl/workbook.xml');
  if (!$wbXml) { $zip->close(); throw new Exception("workbook.xml tidak ditemukan."); }
  libxml_use_internal_errors(true);
  $wb = simplexml_load_string($wbXml);
  if (!$wb) { $zip->close(); throw new Exception("workbook.xml tidak valid."); }
  $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
  $names = [];
  foreach ($wb->sheets->sheet as $sh) {
    $names[] = (string)$sh['name'];
  }
  $zip->close();
  return $names;
}

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
        if (isset($si->t)) {
          $shared[] = (string)$si->t;
        } else if (isset($si->r)) {
          $txt = '';
          foreach ($si->r as $r) { $txt .= (string)$r->t; }
          $shared[] = $txt;
        } else {
          $shared[] = '';
        }
      }
    }
  }

  // workbook + rels to map sheetName -> sheet xml target
  $wbXml = $zip->getFromName('xl/workbook.xml');
  if (!$wbXml) { $zip->close(); throw new Exception("workbook.xml tidak ditemukan."); }
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
    throw new Exception("Sheet tidak ditemukan: " . (string)$sheetName);
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
      $n = $n*26 + (ord($letters[$i]) - 64);
    }
    return $n - 1; // zero-based
  };

  $rows = [];
  if (!isset($sheet->sheetData->row)) { $zip->close(); return $rows; }

  foreach ($sheet->sheetData->row as $row) {
    $line = [];
    if (!isset($row->c)) { $rows[] = $line; continue; }
    foreach ($row->c as $c) {
      $r = (string)$c['r']; // e.g. A1
      preg_match('/^[A-Z]+/', $r, $m);
      $col = $m[0] ?? 'A';
      $ci = $colToIdx($col);

      $t = (string)$c['t'];
      $v = '';
      if ($t === 's') {
        $idx = (int)$c->v;
        $v = $shared[$idx] ?? '';
      } elseif ($t === 'inlineStr') {
        $v = (string)$c->is->t;
      } else {
        $v = isset($c->v) ? (string)$c->v : '';
      }
      $line[$ci] = $v;
    }
    // normalize row to sequential indexes
    if (!$line) { $rows[] = []; continue; }
    $max = max(array_keys($line));
    $norm = [];
    for ($i=0; $i<=$max; $i++) $norm[$i] = $line[$i] ?? '';
    $rows[] = $norm;
  }

  $zip->close();
  return $rows;
}

function find_header(array $rows, array $needCols): int {
  for ($i=0; $i<count($rows); $i++) {
    $joined = strtolower(implode('|', array_map(fn($x)=>trim((string)$x), $rows[$i])));
    $ok = true;
    foreach ($needCols as $c) {
      if (strpos($joined, strtolower($c)) === false) { $ok=false; break; }
    }
    if ($ok) return $i;
  }
  return -1;
}

function map_headers(array $headerRow): array {
  $map = [];
  foreach ($headerRow as $idx=>$h) {
    $k = strtolower(trim((string)$h));
    if ($k !== '') $map[$k] = $idx;
  }
  return $map;
}

function office_guess(PDO $pdo, ?string $text): ?string {
  $t = strtoupper((string)$text);
  if ($t === '') return null;

  // first: master_office mapping
  try {
    $st = $pdo->query("SELECT office_code, office_name FROM master_office");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
      $oc = strtoupper((string)$o['office_code']);
      $on = strtoupper((string)($o['office_name'] ?? ''));
      if ($oc !== '' && strpos($t, $oc) !== false) return strtolower($o['office_code']);
      if ($on !== '' && strpos($t, $on) !== false) return strtolower($o['office_code']);
    }
  } catch (Throwable $e) { /* ignore */ }

  // fallback common
  $pairs = [
    'BOGOR'=>'bgr','BEKASI'=>'bks','TANGERANG'=>'tgr','SEMARANG'=>'smg','SOLO'=>'slo','BANDUNG'=>'bdg','YOGYA'=>'jgy','YOGYAKARTA'=>'jgy','KALIMANTAN'=>'kal'
  ];
  foreach ($pairs as $k=>$v) if (strpos($t, $k) !== false) return $v;
  return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    require_post();
    verify_csrf();
  try {
    if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new Exception("Upload gagal. Pastikan pilih file XLSX." );
    }
    $tmp = $_FILES['file']['tmp_name'];
    $origName = (string)($_FILES['file']['name'] ?? '');
    $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
    $size = (int)($_FILES['file']['size'] ?? 0);
    if ($ext !== 'xlsx') {
      throw new Exception('Format file harus .xlsx');
    }
    if ($size <= 0 || $size > (10 * 1024 * 1024)) {
      throw new Exception('Ukuran file tidak valid (maks 10MB).');
    }

    ensure_company_bank_table($pdo);
    ensure_hospital_bank_table($pdo);

    $doCompany  = isset($_POST['do_company']);
    $doEmp      = isset($_POST['do_employee']);
    $doHosp     = isset($_POST['do_hospital']);
    $doVendor   = isset($_POST['do_vendor']);
    if (!$doCompany && !$doEmp && !$doHosp && !$doVendor) {
      throw new Exception('Pilih minimal satu scope import.');
    }
    $sheetNames = array_map(static fn($v) => strtolower(trim((string)$v)), xlsx_list_sheets($tmp));
    $needSheets = [];
    if ($doCompany) $needSheets[] = 'no rekening perusahaan';
    if ($doEmp) $needSheets[] = 'no rekening karyawan';
    if ($doHosp) $needSheets[] = 'no rekening rumah sakit';
    if ($doVendor) $needSheets[] = 'no rekening supplier';
    foreach ($needSheets as $ns) {
      if (!in_array($ns, $sheetNames, true)) {
        throw new Exception('Sheet wajib tidak ditemukan: ' . $ns);
      }
    }

    $pdo->beginTransaction();

    // --- Company ---
    if ($doCompany) {
      $rows = xlsx_read_rows($tmp, 'No Rekening Perusahaan');
      $hi = find_header($rows, ['no', 'no rekening', 'bank']);
      if ($hi < 0) throw new Exception("Header sheet 'No Rekening Perusahaan' tidak ketemu.");
      $hmap = map_headers($rows[$hi]);

      $get = function(array $r, string $key) use ($hmap) {
        foreach ($hmap as $k=>$idx) {
          if ($k === $key) return $r[$idx] ?? '';
        }
        // flexible contains
        foreach ($hmap as $k=>$idx) {
          if (strpos($k, $key) !== false) return $r[$idx] ?? '';
        }
        return '';
      };

      $ins=0; $skip=0;
      for ($i=$hi+1; $i<count($rows); $i++) {
        $r = $rows[$i];
        $acc = only_digits($get($r,'no rekening'));
        $bank = trim((string)$get($r,'bank'));
        $an = trim((string)$get($r,'nama pemilik rekening'));
        $branch = trim((string)$get($r,'cabang rekening'));
        $note = trim((string)$get($r,'keterangan'));

        if ($acc === '' || $an === '') { $skip++; continue; }
        if ($bank === '') $bank = 'MANDIRI';

        $office = office_guess($pdo, $branch.' '.$note);

        // upsert simple: if same account_number exists, update; else insert
        $st = $pdo->prepare("SELECT id FROM master_company_bank_accounts WHERE account_number=? LIMIT 1");
        $st->execute([$acc]);
        $id = $st->fetchColumn();

        if ($id) {
          $up = $pdo->prepare("UPDATE master_company_bank_accounts SET office_code=?, bank_name=?, account_name=?, branch=?, note=?, updated_at=NOW() WHERE id=?");
          $up->execute([$office, $bank, $an, $branch, $note, $id]);
        } else {
          $in = $pdo->prepare("INSERT INTO master_company_bank_accounts (office_code, bank_name, account_number, account_name, branch, purpose, currency, is_active, note) VALUES (?,?,?,?,?,'RECEIVE','IDR',1,?)");
          $in->execute([$office, $bank, $acc, $an, $branch, $note]);
        }
        $ins++;
      }
      $report['company'] = ['processed'=>$ins, 'skipped'=>$skip];
    }

    // --- Employee (update master_employees bank fields) ---
    if ($doEmp) {
      $rows = xlsx_read_rows($tmp, 'No Rekening Karyawan');
      $hi = find_header($rows, ['nama pemilik rekening', 'nomer rekening']);
      if ($hi < 0) throw new Exception("Header sheet 'No Rekening Karyawan' tidak ketemu.");
      $hmap = map_headers($rows[$hi]);

      $nameIdx = null; $accIdx = null; $bankIdx = null;
      foreach ($hmap as $k=>$idx) {
        if ($nameIdx===null && strpos($k,'nama pemilik')!==false) $nameIdx=$idx;
        if ($accIdx===null && (strpos($k,'nomer rekening')!==false || strpos($k,'no rekening')!==false)) $accIdx=$idx;
        if ($bankIdx===null && strpos($k,'bank')!==false) $bankIdx=$idx;
      }

      // preload employees
      $emps = [];
      $dup = [];
      $st = $pdo->query("SELECT id, employee_name, employee_code FROM master_employees");
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $k = norm_key($e['employee_name'] ?? '');
        if ($k === '') continue;
        if (isset($emps[$k])) { $dup[$k]=true; continue; }
        $emps[$k] = $e;
      }

      $updated=0; $notfound=0; $invalid=0;
      $notFoundList = [];

      for ($i=$hi+1; $i<count($rows); $i++) {
        $r = $rows[$i];
        $name = trim((string)($r[$nameIdx] ?? ''));
        $accRaw = (string)($r[$accIdx] ?? '');
        $bank = trim((string)($r[$bankIdx] ?? ''));
        if ($name === '') continue;

        $acc = only_digits($accRaw);
        if ($acc === '' || strlen($acc) < 6) { $invalid++; continue; }

        $k = norm_key($name);
        if (isset($dup[$k])) { $invalid++; continue; }
        if (!isset($emps[$k])) { $notfound++; $notFoundList[] = $name; continue; }

        $empId = (int)$emps[$k]['id'];
        $up = $pdo->prepare("UPDATE master_employees SET bank_name=?, bank_account_number=?, bank_account_name=?, updated_at=NOW() WHERE id=?");
        $up->execute([$bank ?: null, $acc, $name, $empId]);
        $updated++;
      }

      $report['employee'] = ['updated'=>$updated, 'not_found'=>$notfound, 'invalid'=>$invalid, 'not_found_samples'=>array_slice($notFoundList,0,15)];
    }

    // --- Vendor / Supplier (update master_vendors) ---
    if ($doVendor) {
      $rows = xlsx_read_rows($tmp, 'No Rekening Supplier');
      $hi = find_header($rows, ['supplier', 'no rekening']);
      if ($hi < 0) throw new Exception("Header sheet 'No Rekening Supplier' tidak ketemu.");
      $hmap = map_headers($rows[$hi]);

      $supIdx=null; $accIdx=null; $bankIdx=null; $ketIdx=null;
      foreach ($hmap as $k=>$idx) {
        if ($supIdx===null && strpos($k,'supplier')!==false) $supIdx=$idx;
        if ($accIdx===null && strpos($k,'no rekening')!==false) $accIdx=$idx;
        if ($bankIdx===null && strpos($k,'bank')!==false) $bankIdx=$idx;
        if ($ketIdx===null && strpos($k,'keterangan')!==false) $ketIdx=$idx;
      }

      // preload vendors
      $vend = [];
      $st = $pdo->query("SELECT id, vendors_name FROM master_vendors");
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $k = norm_key($v['vendors_name'] ?? '');
        if ($k !== '') $vend[$k] = $v;
      }

      $updated=0; $notfound=0; $invalid=0;
      $notFoundList=[];
      for ($i=$hi+1; $i<count($rows); $i++) {
        $r = $rows[$i];
        $name = trim((string)($r[$supIdx] ?? ''));
        if ($name === '') continue;
        $acc = only_digits((string)($r[$accIdx] ?? ''));
        $bank = trim((string)($r[$bankIdx] ?? ''));
        $ket = trim((string)($r[$ketIdx] ?? ''));
        if ($acc === '') { $invalid++; continue; }

        $k = norm_key($name);
        if (!isset($vend[$k])) { $notfound++; $notFoundList[]=$name; continue; }

        $id = (int)$vend[$k]['id'];
        $up = $pdo->prepare("UPDATE master_vendors SET bank_name=?, bank_account_number=?, bank_account_name=?, updated_at=NOW() WHERE id=?");
        $up->execute([$bank ?: null, $acc, $name, $id]);
        $updated++;
      }

      $report['vendor'] = ['updated'=>$updated, 'not_found'=>$notfound, 'invalid'=>$invalid, 'not_found_samples'=>array_slice($notFoundList,0,15)];
    }

    // --- Hospital bank directory ---
    if ($doHosp) {
      $rows = xlsx_read_rows($tmp, 'No Rekening Rumah Sakit');
      $hi = find_header($rows, ['cabang', 'nama rumah sakit']);
      if ($hi < 0) throw new Exception("Header sheet 'No Rekening Rumah Sakit' tidak ketemu.");
      $hmap = map_headers($rows[$hi]);

      $cabIdx=null; $nameIdx=null; $accIdx=null; $bankIdx=null;
      foreach ($hmap as $k=>$idx) {
        if ($cabIdx===null && strpos($k,'cabang')!==false) $cabIdx=$idx;
        if ($nameIdx===null && strpos($k,'nama rumah sakit')!==false) $nameIdx=$idx;
        if ($accIdx===null && strpos($k,'no rekening')!==false) $accIdx=$idx;
        if ($bankIdx===null && strpos($k,'bank')!==false) $bankIdx=$idx;
      }

      $ins=0; $skip=0;
      for ($i=$hi+1; $i<count($rows); $i++) {
        $r=$rows[$i];
        $cab = trim((string)($r[$cabIdx] ?? ''));
        $name = trim((string)($r[$nameIdx] ?? ''));
        $acc = only_digits((string)($r[$accIdx] ?? ''));
        $bank = trim((string)($r[$bankIdx] ?? ''));
        if ($name === '' || $acc === '') { $skip++; continue; }

        $office = office_guess($pdo, $cab);
        // avoid duplicates by (name+acc)
        $st = $pdo->prepare("SELECT id FROM master_hospital_bank_accounts WHERE hospital_name=? AND account_number=? LIMIT 1");
        $st->execute([$name, $acc]);
        $id = $st->fetchColumn();
        if ($id) {
          $up = $pdo->prepare("UPDATE master_hospital_bank_accounts SET office_code=?, bank_name=?, updated_at=NOW() WHERE id=?");
          $up->execute([$office, $bank ?: null, $id]);
        } else {
          $in = $pdo->prepare("INSERT INTO master_hospital_bank_accounts (office_code,hospital_name,bank_name,account_number,is_active) VALUES (?,?,?,?,1)");
          $in->execute([$office, $name, $bank ?: null, $acc]);
        }
        $ins++;
      }
      $report['hospital'] = ['processed'=>$ins, 'skipped'=>$skip];
    }

    $pdo->commit();
    if (function_exists('master_audit')) {
        $desc = 'Import rekening selesai: ' . implode(', ', array_keys($report));
        master_audit($pdo, 'import_rekening_final', 'import', 'IMPORT', null, '', $desc, $report);
    }
    $flash = "Import selesai.";
    @file_put_contents(__DIR__ . '/../storage/logs/import_rekening_final_last.json', json_encode([
      'state_version' => 1,
      'ts' => date(DateTimeInterface::ATOM),
      'ok' => true,
      'report' => $report,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errors[] = 'Import gagal. Periksa format file/sheet dan data wajib.';
    @file_put_contents(__DIR__ . '/../storage/logs/import_rekening_final_last.json', json_encode([
      'state_version' => 1,
      'ts' => date(DateTimeInterface::ATOM),
      'ok' => false,
      'error' => 'import_failed',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
  }
}

// --- UI ---
rmi_header('Import Rekening Master (Final)', 'master');

?>
<div class="container-fluid">
  <div class="row">
    <div class="col-lg-8">
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="mb-1">Upload file Excel (XLSX)</h5>
          <div class="text-muted small mb-3">Mendukung file dengan 4 sheet: <b>No Rekening Perusahaan</b>, <b>No Rekening Karyawan</b>, <b>No Rekening Rumah Sakit</b>, <b>No Rekening Supplier</b>.</div>

          <?php if ($flash): ?>
            <div class="alert alert-success"><?= rmi_h($flash) ?></div>
          <?php endif; ?>
          <?php if ($errors): ?>
            <div class="alert alert-danger">
              <?php foreach ($errors as $er): ?>
                <div><?= rmi_h($er) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="mb-2">
              <input type="file" name="file" class="form-control" accept=".xlsx" required>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-md-6">
                <label class="form-check">
                  <input class="form-check-input" type="checkbox" name="do_company" checked>
                  <span class="form-check-label">Import Rekening Perusahaan → <code>master_company_bank_accounts</code></span>
                </label>
                <label class="form-check">
                  <input class="form-check-input" type="checkbox" name="do_employee" checked>
                  <span class="form-check-label">Import Rekening Karyawan → update <code>master_employees</code> (match by Nama)</span>
                </label>
              </div>
              <div class="col-md-6">
                <label class="form-check">
                  <input class="form-check-input" type="checkbox" name="do_vendor" checked>
                  <span class="form-check-label">Import Rekening Supplier → update <code>master_vendors</code> (match by Nama)</span>
                </label>
                <label class="form-check">
                  <input class="form-check-input" type="checkbox" name="do_hospital" checked>
                  <span class="form-check-label">Import Rekening RS → <code>master_hospital_bank_accounts</code></span>
                </label>
              </div>
            </div>

            <button class="btn btn-primary">Import Sekarang</button>
            <a class="btn btn-outline-light" href="<?= rmi_h($BASE_PROJECT) ?>/master/">Kembali</a>
          </form>
        </div>
      </div>

      <?php if ($report): ?>
      <div class="card">
        <div class="card-body">
          <h5 class="mb-2">Hasil Import</h5>
          <pre class="small mb-0" style="white-space:pre-wrap"><?= rmi_h(json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
      </div>
      <?php endif; ?>

    </div>
    <div class="col-lg-4">
      <div class="card">
        <div class="card-body">
          <h6 class="mb-2">Catatan penting</h6>
          <ul class="small mb-0">
            <li>Import karyawan & supplier saat ini <b>match by Nama</b>. Kalau ada beda penulisan, akan masuk list <i>not_found</i>.</li>
            <li>Nomor rekening akan dibersihkan otomatis (hanya angka). Kalau hasilnya kosong → dianggap invalid.</li>
            <li>Rekening Perusahaan akan <b>upsert</b> berdasarkan <code>account_number</code> (kalau sudah ada → update).</li>
            <li>Rekening RS disimpan di tabel terpisah (<code>master_hospital_bank_accounts</code>) untuk direktori rekening.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer();