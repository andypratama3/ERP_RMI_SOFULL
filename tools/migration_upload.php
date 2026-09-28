<?php
/**
 * tools/migration_upload.php
 * Upload Excel migrasi data — langsung masuk ke sistem.
 * Akses: FIN Manager atau ADMIN/SUPERADMIN.
 */
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

if (function_exists('require_fin_manager_or_admin')) {
    require_fin_manager_or_admin();
} else {
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$baseProject = rmi_layout_base_project();
$csrfToken = function_exists('csrf_token') ? (string)csrf_token() : '';
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_FILES['migration_file']['tmp_name'])) {
    if (function_exists('verify_csrf')) verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $result = _migration_process_upload($_FILES['migration_file']);
}

rmi_header('Upload Migrasi Data', [
    'active' => 'tools',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'],
        'Upload Migrasi Data',
    ],
]);

?>
<div class="rmi-card p-4">
  <h5 class="mb-3">📤 Upload Migrasi Data</h5>
  <p class="text-muted small mb-4">
    Upload file Excel template yang sudah diisi. Data akan masuk ke sistem sesuai sheet.
    <a href="<?= rmi_h($baseProject) ?>/docs/governance/data_migration/README_RUNBOOK.md" target="_blank">Panduan lengkap</a> |
    <a href="<?= rmi_h($baseProject) ?>/docs/governance/data_migration/CARA_PENGISIAN_EXCEL.md" target="_blank">Cara pengisian</a>
  </p>

  <div class="mb-4">
    <strong>Download Template:</strong>
    <a href="<?= rmi_h($baseProject) ?>/tools/migration_download_template.php" class="btn btn-sm btn-outline-primary ms-2">
      📥 Download Excel Template
    </a>
    <span class="text-muted small ms-2">(12 sheet + contoh isian)</span>
  </div>

  <form method="post" enctype="multipart/form-data" class="border rounded p-3 bg-light">
    <input type="hidden" name="csrf_token" value="<?= rmi_h($csrfToken) ?>">
    <div class="mb-3">
      <label class="form-label">Pilih file Excel (.xlsx)</label>
      <input type="file" name="migration_file" class="form-control" accept=".xlsx,.xls" required>
    </div>
    <div class="mb-3">
      <label class="form-check">
        <input type="checkbox" name="post_staging" value="1" checked>
        Post Stock/AP/AR ke tabel produksi (setelah upload ke staging)
      </label>
    </div>
    <button type="submit" class="btn btn-primary">Upload & Proses</button>
    <a href="<?= rmi_h($baseProject) ?>/tools/index.php" class="btn btn-outline-secondary">Batal</a>
  </form>

  <?php if ($result !== null): ?>
  <div class="mt-4 p-3 rounded <?= ($result['ok'] ?? false) ? 'bg-success bg-opacity-10' : 'bg-danger bg-opacity-10' ?>">
    <strong><?= ($result['ok'] ?? false) ? '✅ Berhasil' : '❌ Ada error' ?></strong>
    <pre class="mt-2 mb-0 small" style="max-height:300px;overflow:auto"><?= rmi_h($result['message'] ?? '') ?></pre>
    <?php if (!empty($result['details'])): ?>
    <pre class="mt-2 mb-0 small" style="max-height:200px;overflow:auto"><?= rmi_h(implode("\n", $result['details'])) ?></pre>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="rmi-card p-3 mt-3">
  <h6>Sheet yang didukung</h6>
  <table class="table table-sm">
    <thead><tr><th>Sheet</th><th>Target</th></tr></thead>
    <tbody>
      <tr><td>1_Manufactures</td><td>master_manufactures</td></tr>
      <tr><td>2_Vendors</td><td>master_vendors</td></tr>
      <tr><td>3_Customers</td><td>master_customers</td></tr>
      <tr><td>4_Products</td><td>master_products</td></tr>
      <tr><td>5_Stock</td><td>mig_opening_stock_stg → wqs_stock_by_office</td></tr>
      <tr><td>6_AP</td><td>mig_opening_ap_stg → purchases_invoice_ap</td></tr>
      <tr><td>7_AR</td><td>mig_opening_ar_stg → sales_do</td></tr>
      <tr><td>8_Office</td><td>master_office (update)</td></tr>
      <tr><td>9_Bank, 10_GL, 11_FA</td><td>Coming soon</td></tr>
    </tbody>
  </table>
</div>
<?php
rmi_footer();

function _migration_process_upload(array $file): array {
    $tmp = $file['tmp_name'] ?? '';
    if (!$tmp || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'File upload gagal.'];
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'], true)) {
        return ['ok' => false, 'message' => 'File harus .xlsx atau .xls.'];
    }

    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    require_once $root . '/vendor/autoload.php';

    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xlsx' ? 'Xlsx' : 'Xls');
        // Tidak pakai setReadDataOnly(true) agar formula cross-sheet (=4_Products!A4) terbaca nilainya
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($tmp);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'File Excel tidak valid: ' . $e->getMessage()];
    }

    $details = [];
    $errors = [];

    try {
        $pdo = rmi_db_pdo();
        $postStaging = !empty($_POST['post_staging']);
        $batch = 'CUTOVER_' . date('ymd');

        _migration_ensure_staging_tables($pdo);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $name = $sheet->getTitle();
            $rows = $sheet->toArray();
            if (empty($rows)) continue;

            // Auto-detect baris header: skip banner/info (emoji, teks panjang).
            // Cari baris pertama di mana cell terlihat seperti nama kolom (pendek, tanpa emoji).
            $headerIdx = 0;
            foreach ($rows as $ri => $row) {
                $row = is_array($row) ? $row : [];
                foreach ($row as $cell) {
                    $cell = trim((string)($cell ?? ''));
                    if ($cell === '') continue;
                    // Nama kolom: pendek, tanpa emoji Unicode
                    if (mb_strlen($cell) < 50 && !preg_match('/[\x{2600}-\x{27BF}\x{1F000}-\x{1FAFF}]/u', $cell)) {
                        $headerIdx = $ri;
                        break 2;
                    }
                }
            }
            $header   = array_map('trim', (array)($rows[$headerIdx] ?? []));
            // Data: baris setelah header; skip baris deskripsi (semua cell panjang/italic)
            $rawData  = array_slice($rows, $headerIdx + 1);
            $dataRows = array_values(array_filter($rawData, function ($r) {
                if (!is_array($r)) return false;
                $nonEmpty = array_filter($r, fn($c) => trim((string)($c ?? '')) !== '');
                if (count($nonEmpty) === 0) return false;
                // Skip baris yang semua cell-nya panjang (>80 char) → baris deskripsi
                $allLong = array_reduce($nonEmpty, fn($carry, $c) => $carry && mb_strlen((string)$c) > 80, true);
                return !$allLong;
            }));

            if (str_starts_with($name, '1_Manufactures')) {
                $n = _migration_import_manufactures($pdo, $header, $dataRows);
                $details[] = "Manufactures: $n baris";
            } elseif (str_starts_with($name, '2_Vendors')) {
                $n = _migration_import_vendors($pdo, $header, $dataRows);
                $details[] = "Vendors: $n baris";
            } elseif (str_starts_with($name, '3_Customers')) {
                $n = _migration_import_customers($pdo, $header, $dataRows);
                $details[] = "Customers: $n baris";
            } elseif (str_starts_with($name, '4_Products')) {
                $n = _migration_import_products($pdo, $header, $dataRows);
                $details[] = "Products: $n baris";
            } elseif (str_starts_with($name, '5_Stock')) {
                $n = _migration_import_stock_staging($pdo, $header, $dataRows, $batch);
                $details[] = "Stock staging: $n baris";
            } elseif (str_starts_with($name, '6_AP')) {
                $n = _migration_import_ap_staging($pdo, $header, $dataRows, $batch);
                $details[] = "AP staging: $n baris";
            } elseif (str_starts_with($name, '7_AR')) {
                $n = _migration_import_ar_staging($pdo, $header, $dataRows, $batch);
                $details[] = "AR staging: $n baris";
            } elseif (str_starts_with($name, '8_Office')) {
                $n = _migration_import_office($pdo, $header, $dataRows);
                $details[] = "Office: $n baris";
            }
        }

        if ($postStaging) {
            $postResult = _migration_post_staging($pdo, $batch);
            $details = array_merge($details, $postResult);
        }

        return [
            'ok' => empty($errors),
            'message' => 'Import selesai. ' . implode('; ', $details),
            'details' => $details,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Error saat proses: ' . $e->getMessage(),
            'details' => array_merge($details, ['Trace: ' . $e->getFile() . ':' . $e->getLine()]),
        ];
    }
}

/** Case-insensitive column index map. */
function _migration_col_map(array $header): array {
    $map = [];
    foreach ($header as $i => $h) {
        $k = strtolower(trim((string)$h));
        if ($k !== '' && !isset($map[$k])) {
            $map[$k] = $i;
        }
    }
    return $map;
}

/** Safe value from row; converts Excel date to Y-m-d. */
function _migration_val(array $row, array $colMap, string $key, string $default = ''): string {
    $idx = $colMap[strtolower($key)] ?? null;
    if ($idx === null) return $default;
    $v = $row[$idx] ?? $default;
    if (is_numeric($v) && (float)$v > 1000 && (float)$v < 100000) {
        try {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v);
            return $dt->format('Y-m-d');
        } catch (Throwable $e) {
            return trim((string)$v) ?: $default;
        }
    }
    return trim((string)$v) ?: $default;
}

/** Safe numeric value. */
function _migration_val_num(array $row, array $colMap, string $key, float $default = 0): float {
    $idx = $colMap[strtolower($key)] ?? null;
    if ($idx === null) return $default;
    $v = $row[$idx] ?? $default;
    return is_numeric($v) ? (float)$v : $default;
}

function _migration_ensure_staging_tables(PDO $pdo): void {
    $stmts = [
        "CREATE TABLE IF NOT EXISTS mig_opening_stock_stg (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, migration_batch VARCHAR(80) NOT NULL, row_no INT NOT NULL, sku VARCHAR(80) NOT NULL, qty_on_hand INT NOT NULL DEFAULT 0, loaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_batch (migration_batch), KEY idx_sku (sku)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mig_opening_ap_stg (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, migration_batch VARCHAR(80) NOT NULL, row_no INT NOT NULL, invoice_number VARCHAR(120) NOT NULL, invoice_date DATE NOT NULL, due_date DATE DEFAULT NULL, manufacture_code VARCHAR(60) NOT NULL, office_code VARCHAR(20) NOT NULL, currency VARCHAR(10) NOT NULL DEFAULT 'IDR', balance_amount DECIMAL(18,2) NOT NULL DEFAULT 0, note VARCHAR(255) DEFAULT NULL, loaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_batch (migration_batch), KEY idx_inv (invoice_number), KEY idx_mnf (manufacture_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mig_opening_ar_stg (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, migration_batch VARCHAR(80) NOT NULL, row_no INT NOT NULL, invoice_number VARCHAR(120) NOT NULL, invoice_date DATE NOT NULL, due_date DATE DEFAULT NULL, customers_code VARCHAR(60) NOT NULL, office_code VARCHAR(20) NOT NULL, currency VARCHAR(10) NOT NULL DEFAULT 'IDR', balance_amount DECIMAL(18,2) NOT NULL DEFAULT 0, note VARCHAR(255) DEFAULT NULL, loaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_batch (migration_batch), KEY idx_inv (invoice_number), KEY idx_cust (customers_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS wqs_stock_by_office (product_id INT NOT NULL, office_code VARCHAR(30) NOT NULL DEFAULT 'BGR', stock_qty DECIMAL(18,2) NOT NULL DEFAULT 0, updated_at DATETIME NULL, PRIMARY KEY (product_id, office_code), KEY idx_office (office_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($stmts as $stmt) $pdo->exec($stmt);
}

function _migration_import_manufactures(PDO $pdo, array $header, array $rows): int {
    $cols = _migration_col_map($header);
    $n = 0;
    $chk = $pdo->prepare("SELECT id FROM master_manufactures WHERE UPPER(manufacture_code)=UPPER(?) LIMIT 1");
    $st = $pdo->prepare("INSERT INTO master_manufactures (manufactures_code, manufactures_name, manufacture_code, manufacture_name, brand_name, origin_type, country, city, address, phone, email, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $r = is_array($r) ? $r : [];
        $code = _migration_val($r, $cols, 'manufacture_code');
        if ($code === '') continue;
        $chk->execute([$code]);
        if ($chk->fetch()) continue;
        $name = _migration_val($r, $cols, 'manufacture_name');
        $statusVal = _migration_val($r, $cols, 'status', 'active');
        $status = in_array(strtolower($statusVal), ['active','1','true','yes'], true) ? 1 : 0;
        $st->execute([
            $code, $name, $code, $name,
            _migration_val($r, $cols, 'brand_name'),
            _migration_val($r, $cols, 'origin_type'),
            _migration_val($r, $cols, 'country'),
            _migration_val($r, $cols, 'city'),
            _migration_val($r, $cols, 'address'),
            _migration_val($r, $cols, 'phone'),
            _migration_val($r, $cols, 'email'),
            $status,
        ]);
        $n++;
    }
    return $n;
}

function _migration_import_vendors(PDO $pdo, array $header, array $rows): int {
    $cols = _migration_col_map($header);
    $n = 0;
    $chk = $pdo->prepare("SELECT id FROM master_vendors WHERE UPPER(vendors_code)=UPPER(?) LIMIT 1");
    $st = $pdo->prepare("INSERT INTO master_vendors (vendors_code, vendors_name, vendor_type, city, phone, email, npwp, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $r = is_array($r) ? $r : [];
        $code = _migration_val($r, $cols, 'vendors_code');
        if ($code === '') continue;
        $chk->execute([$code]);
        if ($chk->fetch()) continue;
        $st->execute([
            $code,
            _migration_val($r, $cols, 'vendors_name'),
            _migration_val($r, $cols, 'vendor_type'),
            _migration_val($r, $cols, 'city'),
            _migration_val($r, $cols, 'phone'),
            _migration_val($r, $cols, 'email'),
            _migration_val($r, $cols, 'npwp'),
            _migration_val($r, $cols, 'status', 'active') ?: 'active',
        ]);
        $n++;
    }
    return $n;
}

function _migration_import_customers(PDO $pdo, array $header, array $rows): int {
    $cols = _migration_col_map($header);
    $n = 0;
    $chk = $pdo->prepare("SELECT id FROM master_customers WHERE UPPER(customers_code)=UPPER(?) LIMIT 1");
    $st = $pdo->prepare("INSERT INTO master_customers (customers_code, customers_name, category, segment, city, office_code, address, phone, email, npwp, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $r = is_array($r) ? $r : [];
        $code = _migration_val($r, $cols, 'customers_code');
        if ($code === '') continue;
        $chk->execute([$code]);
        if ($chk->fetch()) continue;
        $st->execute([
            $code,
            _migration_val($r, $cols, 'customers_name'),
            _migration_val($r, $cols, 'category'),
            _migration_val($r, $cols, 'segment'),
            _migration_val($r, $cols, 'city'),
            _migration_val($r, $cols, 'office_code', 'BGR') ?: 'BGR',
            _migration_val($r, $cols, 'address'),
            _migration_val($r, $cols, 'phone'),
            _migration_val($r, $cols, 'email'),
            _migration_val($r, $cols, 'npwp'),
            _migration_val($r, $cols, 'status', 'active') ?: 'active',
        ]);
        $n++;
    }
    return $n;
}

function _migration_import_products(PDO $pdo, array $header, array $rows): int {
    $cols = _migration_col_map($header);
    $n = 0;
    $chk = $pdo->prepare("SELECT id FROM master_products WHERE UPPER(sku)=UPPER(?) LIMIT 1");
    $stMnf = $pdo->prepare("SELECT id FROM master_manufactures WHERE UPPER(manufacture_code)=UPPER(?) LIMIT 1");
    $st = $pdo->prepare("INSERT INTO master_products (sku, products_name, manufacture_id, category, unit, barcode, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $r = is_array($r) ? $r : [];
        $sku = _migration_val($r, $cols, 'sku');
        if ($sku === '') continue;
        $chk->execute([$sku]);
        if ($chk->fetch()) continue;
        $mnfCode = _migration_val($r, $cols, 'manufacture_code');
        $mnfId = null;
        if ($mnfCode !== '') {
            $stMnf->execute([$mnfCode]);
            $m = $stMnf->fetch(PDO::FETCH_ASSOC);
            if ($m) $mnfId = (int)$m['id'];
        }
        $st->execute([
            $sku,
            _migration_val($r, $cols, 'products_name'),
            $mnfId,
            _migration_val($r, $cols, 'category'),
            _migration_val($r, $cols, 'unit', 'PCS') ?: 'PCS',
            _migration_val($r, $cols, 'barcode'),
            _migration_val($r, $cols, 'status', 'active') ?: 'active',
        ]);
        $n++;
    }
    return $n;
}

function _migration_import_stock_staging(PDO $pdo, array $header, array $rows, string $batch): int {
    $cols = _migration_col_map($header);
    $n = 0;
    $st = $pdo->prepare("INSERT INTO mig_opening_stock_stg (migration_batch, row_no, sku, qty_on_hand) VALUES (?, ?, ?, ?)");
    foreach ($rows as $i => $r) {
        $r = is_array($r) ? $r : [];
        $sku = _migration_val($r, $cols, 'sku');
        if ($sku === '') continue;
        $st->execute([
            $batch,
            $i + 1,
            $sku,
            (int)_migration_val_num($r, $cols, 'qty_on_hand'),
        ]);
        $n++;
    }
    return $n;
}

function _migration_import_ap_staging(PDO $pdo, array $header, array $rows, string $batch): int {
    $cols = _migration_col_map($header);
    $n = 0;
    $st = $pdo->prepare("INSERT INTO mig_opening_ap_stg (migration_batch, row_no, invoice_number, invoice_date, due_date, manufacture_code, office_code, currency, balance_amount, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $i => $r) {
        $r = is_array($r) ? $r : [];
        $inv = _migration_val($r, $cols, 'invoice_number');
        if ($inv === '') continue;
        $invDate = _migration_val($r, $cols, 'invoice_date');
        $dueDate = _migration_val($r, $cols, 'due_date');
        $st->execute([
            $batch,
            $i + 1,
            $inv,
            $invDate ?: date('Y-m-d'),
            $dueDate !== '' ? $dueDate : null,
            _migration_val($r, $cols, 'manufacture_code'),
            _migration_val($r, $cols, 'office_code', 'BGR') ?: 'BGR',
            _migration_val($r, $cols, 'currency', 'IDR') ?: 'IDR',
            _migration_val_num($r, $cols, 'balance_amount'),
            _migration_val($r, $cols, 'note'),
        ]);
        $n++;
    }
    return $n;
}

function _migration_import_ar_staging(PDO $pdo, array $header, array $rows, string $batch): int {
    $cols = _migration_col_map($header);
    $n = 0;
    $st = $pdo->prepare("INSERT INTO mig_opening_ar_stg (migration_batch, row_no, invoice_number, invoice_date, due_date, customers_code, office_code, currency, balance_amount, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $i => $r) {
        $r = is_array($r) ? $r : [];
        $inv = _migration_val($r, $cols, 'invoice_number');
        if ($inv === '') continue;
        $invDate = _migration_val($r, $cols, 'invoice_date');
        $dueDate = _migration_val($r, $cols, 'due_date');
        $st->execute([
            $batch,
            $i + 1,
            $inv,
            $invDate ?: date('Y-m-d'),
            $dueDate !== '' ? $dueDate : null,
            _migration_val($r, $cols, 'customers_code'),
            _migration_val($r, $cols, 'office_code', 'BGR') ?: 'BGR',
            _migration_val($r, $cols, 'currency', 'IDR') ?: 'IDR',
            _migration_val_num($r, $cols, 'balance_amount'),
            _migration_val($r, $cols, 'note'),
        ]);
        $n++;
    }
    return $n;
}

function _migration_import_office(PDO $pdo, array $header, array $rows): int {
    $cols = _migration_col_map($header);
    $n = 0;
    $st = $pdo->prepare("UPDATE master_office SET office_name=?, office_lat=?, office_lng=?, office_radius_m=? WHERE office_code=?");
    foreach ($rows as $r) {
        $r = is_array($r) ? $r : [];
        $code = _migration_val($r, $cols, 'office_code');
        if ($code === '') continue;
        $radius = (int)_migration_val_num($r, $cols, 'office_radius_m', 120);
        $st->execute([
            _migration_val($r, $cols, 'office_name'),
            _migration_val($r, $cols, 'office_lat'),
            _migration_val($r, $cols, 'office_lng'),
            $radius > 0 ? $radius : 120,
            $code,
        ]);
        if ($st->rowCount() > 0) $n++;
    }
    return $n;
}

function _migration_post_staging(PDO $pdo, string $batch): array {
    $details = [];
    $office = 'BGR';
    $qb = $pdo->quote($batch);

    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM mig_opening_stock_stg WHERE migration_batch=$qb")->fetchColumn();
        if ($cnt > 0) {
            $pdo->beginTransaction();
            $pdo->exec("
                INSERT INTO wqs_stock_by_office (product_id, office_code, stock_qty, updated_at)
                SELECT p.id, '$office', SUM(s.qty_on_hand), NOW()
                FROM mig_opening_stock_stg s
                JOIN master_products p ON UPPER(p.sku)=UPPER(s.sku)
                WHERE s.migration_batch=$qb
                GROUP BY p.id
                ON DUPLICATE KEY UPDATE stock_qty=VALUES(stock_qty), updated_at=NOW()
            ");
            $pdo->exec("
                UPDATE wqs_stock_by_office ws
                JOIN (SELECT p.id AS product_id, SUM(s.qty_on_hand) AS qty FROM mig_opening_stock_stg s JOIN master_products p ON UPPER(p.sku)=UPPER(s.sku) WHERE s.migration_batch=$qb GROUP BY p.id) x ON x.product_id=ws.product_id AND ws.office_code='$office'
                SET ws.stock_qty=x.qty, ws.updated_at=NOW()
            ");
            $pdo->commit();
            $details[] = "Stock posted: $cnt rows";
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        $details[] = "Stock post error: " . $e->getMessage();
    }

    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM mig_opening_ap_stg WHERE migration_batch=$qb AND balance_amount>0")->fetchColumn();
        if ($cnt > 0) {
            $pdo->beginTransaction();
            $pdo->exec("
                INSERT INTO purchases_invoice_ap (ap_code, invoice_type, invoice_number, invoice_date, due_date, manufacture_id, office_code, currency, subtotal, tax_percent, tax_amount, total_amount, status, note, created_by, created_at, updated_at)
                SELECT CONCAT('OPEN-AP-', DATE_FORMAT(CURDATE(), '%y%m%d'), '-', LPAD(a.row_no, 4, '0')), 'FINAL', a.invoice_number, a.invoice_date, a.due_date, m.id, a.office_code, COALESCE(NULLIF(a.currency, ''), 'IDR'), a.balance_amount, 0, 0, a.balance_amount, 'UNPAID', CONCAT('OPENING_AP batch=', a.migration_batch, ' | ', COALESCE(a.note, '')), 'MIGRATION', NOW(), NOW()
                FROM mig_opening_ap_stg a
                JOIN master_manufactures m ON UPPER(m.manufacture_code)=UPPER(a.manufacture_code)
                LEFT JOIN purchases_invoice_ap ap ON ap.manufacture_id=m.id AND UPPER(COALESCE(ap.invoice_number,''))=UPPER(COALESCE(a.invoice_number,'')) AND ap.deleted_at IS NULL AND ap.note LIKE CONCAT('%OPENING_AP batch=', a.migration_batch, '%')
                WHERE a.migration_batch=$qb AND a.balance_amount>0 AND ap.id IS NULL
            ");
            $pdo->commit();
            $details[] = "AP posted: $cnt rows";
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        $details[] = "AP post error: " . $e->getMessage();
    }

    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM mig_opening_ar_stg WHERE migration_batch=$qb AND balance_amount>0")->fetchColumn();
        if ($cnt > 0) {
            $pdo->beginTransaction();
            $pdo->exec("
                INSERT INTO sales_do (do_code, tracking_code, do_date, customer_id, customers_code, office_code, status, status_wqs, status_scm, status_act, status_fin, wqs_status, scm_status, act_status, fin_status, note, total_amount, tax_code, tax_included, tax_rate_percent, tax_amount, grand_total, price_include_tax, crm_status, flow_status, act_due_date, act_amount, created_at, updated_at)
                SELECT CONCAT('OPEN-AR-', DATE_FORMAT(CURDATE(), '%y%m%d'), '-', LPAD(r.row_no, 4, '0')), CONCAT('OPEN-AR-', DATE_FORMAT(CURDATE(), '%y%m%d'), '-', LPAD(r.row_no, 4, '0')), r.invoice_date, c.id, r.customers_code, r.office_code, 'act_done', 'done', 'done', 'done', 'pending', 'done', 'done', 'done', 'pending', CONCAT('OPENING_AR batch=', r.migration_batch, ' | inv=', r.invoice_number, ' | ', COALESCE(r.note, '')), r.balance_amount, '', 0, 0, 0, r.balance_amount, 0, 'crm_to_wqs', 'CRM', r.due_date, r.balance_amount, NOW(), NOW()
                FROM mig_opening_ar_stg r
                JOIN master_customers c ON UPPER(c.customers_code)=UPPER(r.customers_code)
                LEFT JOIN sales_do d ON d.customers_code=r.customers_code AND d.do_date=r.invoice_date AND d.note LIKE CONCAT('%OPENING_AR batch=', r.migration_batch, '%') AND d.note LIKE CONCAT('%inv=', r.invoice_number, '%')
                WHERE r.migration_batch=$qb AND r.balance_amount>0 AND d.id IS NULL
            ");
            $pdo->commit();
            $details[] = "AR posted: $cnt rows";
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        $details[] = "AR post error: " . $e->getMessage();
    }

    return $details;
}
