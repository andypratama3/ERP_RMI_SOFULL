<?php
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
// Print CF dipakai operasional WQS.
// Tetap menerima SALES.PRINT untuk kompatibilitas admin/SYS,
// tetapi akun WQS yang memiliki WQS.DO_TASKS juga boleh mencetak.
if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.PRINT', 'WQS.DO_TASKS']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','WQS','MANAGER']);
}
// sales_do_print_cf.php
// Versi khusus DOT-MATRIX / CONTINUOUS FORM (4 ply)
// Layout sederhana, font monospaced, siap untuk printer dot-matrix.
// --- DB (centralized) ---
$pdo = db_pdo();

// --- PARAMETER ID DO ---
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    die("Parameter DO tidak valid.");
}
$do_id = (int)$_GET['id'];

// --- AMBIL HEADER DO ---
$stmt = $pdo->prepare("
    SELECT
        d.*,
        c.customers_name,
        c.address      AS customer_address,
        c.city         AS customer_city,
        c.phone        AS customer_phone,
        c.email        AS customer_email,
        o.office_name,
        o.address      AS office_address,
        o.city         AS office_city
    FROM sales_do d
    LEFT JOIN master_customers c
        ON c.customers_code = d.customers_code
    LEFT JOIN master_office o
        ON o.office_code   = d.office_code
    WHERE d.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $do_id]);
$header = $stmt->fetch();

if (!$header) {
    die("DO tidak ditemukan.");
}

// --- AMBIL DETAIL DO + BARCODE DARI master_products ---
$stmt = $pdo->prepare("
    SELECT
        i.*,
        p.barcode
    FROM sales_do_items i
    LEFT JOIN master_products p
        ON p.id = i.product_id
    WHERE i.do_id = :id
    ORDER BY i.line_no ASC, i.id ASC
");
$stmt->execute([':id' => $do_id]);
$items = $stmt->fetchAll();

// helper
function pad_right($text, $len) {
    $text = (string)$text;
    if (strlen($text) >= $len) return substr($text, 0, $len);
    return $text . str_repeat(' ', $len - strlen($text));
}
function pad_left($text, $len) {
    $text = (string)$text;
    if (strlen($text) >= $len) return substr($text, -$len);
    return str_repeat(' ', $len - strlen($text)) . $text;
}

$isTaxIncluded = isset($header['tax_included']) ? (int)$header['tax_included'] === 1 : false;

?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Sales Do Print Cf', [
  'active' => 'sales',
  'breadcrumbs' => [
    ['label' => 'Sales (CRM)', 'url' => $baseProject . '/sales/sales_dashboard.php'],
    'Sales Do Print Cf',
  ],
  'extra_head' => '<style>
        /* Continuous form 9.5 x 11 inch (dot matrix) */
        @page {
            size: 9.5in 11in;
            margin: 0.7cm 0.7cm;
        }
        body {
            font-family: "Courier New", Courier, monospace;
            font-size: 11px;
            color: #000;
            background: #fff;
        }
        .cf-wrapper {
            width: 100%;
            max-width: 107ch;  /* grid cetak tetap: sama dengan lebar tabel item */
            margin: 0 auto;
            white-space: pre-wrap;
            line-height: 1.35;
        }
        /* Dokumen dibuat rapat ke toolbar, tanpa ruang kosong besar dari layout ERP. */
        .cf-document {
            margin-top: 4px;
            white-space: pre;
            line-height: 1.35;
        }
        .cf-line {
            border-top: 1px solid #000;
            margin: 2px 0;
        }
        .cf-table {
            width: 100%;
            border-collapse: collapse;
        }
        .cf-table th,
        .cf-table td {
            border-bottom: 1px dotted #000;
            padding: 1px 2px;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .no-print {
            margin-bottom: 2px;
        }
        .no-print hr {
            margin: 8px 0 4px;
        }
        @media print {
            /* =========================================================
               PRINT CLEAN — hanya Continuous Form yang boleh tercetak.
               Header/menu/breadcrumb/footer ERP tetap tampil di layar,
               tetapi tidak ikut ke kertas.
               ========================================================= */
            @page {
                size: 9.5in 11in;
                margin: 0;
            }

            html,
            body {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                width: 100% !important;
                min-width: 0 !important;
            }

            /* Sembunyikan seluruh layout ERP saat print. */
            body * {
                visibility: hidden !important;
            }

            /* Tampilkan kembali hanya dokumen Continuous Form. */
            .cf-wrapper,
            .cf-wrapper *,
            .cf-document,
            .cf-document * {
                visibility: visible !important;
            }

            .cf-wrapper {
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                width: 107ch !important;
                max-width: 107ch !important;
                margin: 0 !important;
                padding: 0 !important;
                white-space: pre !important;
                font-family: "Courier New", Courier, monospace !important;
                font-size: 12px !important;
                line-height: 1.15 !important;
                letter-spacing: 0 !important;
                font-variant-ligatures: none !important;
                color: #000 !important;
                background: #fff !important;
            }

            /* Posisi fisik continuous form: di dalam area cetak (@page
               margin 4mm), tanpa offset negatif agar baris pertama
               tidak terpotong. */
            .cf-document {
                position: static !important;
                margin: 0 !important;
                padding: 0 !important;
                /* Satu grid karakter 107 kolom untuk HEADER, DETAIL, TOTAL dan TTD. */
                font-family: "Courier New", Courier, monospace !important;
                font-variant-ligatures: none !important;
                letter-spacing: 0 !important;
                box-sizing: border-box !important;
                width: 100% !important;
                white-space: pre !important;
                line-height: 1.25 !important;
                font-size: 12px !important;
                transform: none !important;
            }

            /* Toolbar internal juga tidak ikut tercetak. */
            .cf-wrapper .no-print,
            .cf-wrapper .no-print * {
                display: none !important;
                visibility: hidden !important;
            }

            /* Proteksi tambahan bila class layout ERP berubah. */
            header,
            nav,
            footer,
            .navbar,
            .topbar,
            .sidebar,
            .breadcrumb,
            .breadcrumbs,
            .rmi-header,
            .rmi-navbar,
            .rmi-topbar,
            .rmi-sidebar,
            .rmi-footer,
            .page-header,
            .app-header,
            .app-footer {
                display: none !important;
            }
        }

        /* --- VISIBILITY PATCH (RMI): ensure dark tables text always visible, no hidden/truncated text --- */
        .table-dark-custom{
            --bs-table-color: #e5e7eb;
            --bs-table-bg: transparent;
            --bs-table-striped-color: #e5e7eb;
            --bs-table-striped-bg: rgba(255,255,255,.02);
            --bs-table-hover-color: #f9fafb;
            --bs-table-hover-bg: rgba(255,255,255,.06);
            --bs-table-active-color: #f9fafb;
            --bs-table-active-bg: rgba(255,255,255,.10);
            color: var(--bs-table-color) !important;
        }
        .table-dark-custom th,
        .table-dark-custom td{
            color: var(--bs-table-color) !important;
        }
        .table-dark-custom a,
        .table-dark-custom a:visited{
            color: inherit !important;
        }
        .table-dark-custom .text-muted,
        .table-dark-custom .text-secondary{
            color: rgba(229,231,235,.78) !important;
        }
        /* DataTables often forces nowrap; allow wrapping so long text isn\'t "invisible" */
        table.dataTable.table-dark-custom th,
        table.dataTable.table-dark-custom td,
        .table-dark-custom .nowrap,
        .table-dark-custom td.nowrap,
        .table-dark-custom th.nowrap{
            white-space: normal !important;
        }
        table.dataTable.table-dark-custom td{
            overflow: visible !important;
            text-overflow: clip !important;
            word-break: break-word;
        }
</style>',
]);
?>


<div class="cf-wrapper">

    <!-- Toolbar hanya di layar -->
    <div class="no-print">
        <button type="button" onclick="window.print()">🖨 Print Continuous Form</button>
        &nbsp;
        <a href="sales_do.php">Kembali ke DO</a>
        <hr>
    </div>

<div class="cf-document"><?php
// --------------------------------------------------
// HEADER KIRI (PERUSAHAAN) & KANAN (INFO DO)
// --------------------------------------------------
$officeName    = $header['office_name'] ?? $header['office_code'];
$officeAddr    = trim(($header['office_address'] ?? '') . ' ' . ($header['office_city'] ?? ''));
$customerName  = $header['customers_name'] ?? $header['customers_code'];
$customerAddr  = trim(($header['shipping_address'] ?: $header['customer_address'] ?: '') . ' ' . ($header['customer_city'] ?? ''));
$doDate        = date('d-m-Y', strtotime($header['do_date']));
$doCode        = $header['do_code'];
$trackCode     = $header['tracking_code'] ?: $header['do_code'];
$status        = strtoupper($header['status'] ?? 'DRAFT');

echo pad_right('RIZQULLAH MEDISKA INDONESIA', 53)
   . pad_left('DELIVERY ORDER', 54) . "\n";

echo pad_right($officeName, 53)
   . pad_left('DO : ' . $doCode, 54) . "\n";

// Alamat kantor bisa panjang: bungkus ke baris-baris 53 kolom
// (memakai slot kiri yang kosong di baris Tracking/Tanggal),
// JANGAN dipotong seperti pad_right.
$addrChunks = function_exists('mb_str_split')
    ? mb_str_split($officeAddr, 53)
    : str_split($officeAddr, 53);
if (empty($addrChunks)) $addrChunks = [''];
echo pad_right(array_shift($addrChunks), 53)
   . pad_left('Tracking : ' . $trackCode, 54) . "\n";

echo pad_right(array_shift($addrChunks) ?? '', 53)
   . pad_left('Tanggal : ' . $doDate, 54) . "\n";

$lastLeft = implode(' ', $addrChunks);
echo pad_right(mb_substr($lastLeft, 0, 53), 53)
   . pad_left('Status  : ' . $status, 54) . "\n";
// Sisa alamat yang sangat panjang: baris tambahan (kiri saja).
$rest = trim(mb_substr($lastLeft, 53));
while ($rest !== '') {
    echo pad_right(mb_substr($rest, 0, 53), 53) . "\n";
    $rest = trim(mb_substr($rest, 53));
}

echo str_repeat('-', 107) . "\n";

echo 'Kepada Yth : ' . $customerName . "\n";
echo 'Alamat     : ' . $customerAddr . "\n";
echo 'PIC / Telp : ' . ($header['customer_pic'] ?: '-') . ' / ' . ($header['customer_phone'] ?: '-') . "\n";

echo str_repeat('=', 107) . "\n";

?>

No  Nama Barang                        SKU        Barcode      Qty  Sat    Harga        Disc    Subtotal
--- ---------------------------------- ---------- ------------ ---- ----- ---------- -------- -----------
<?php
// --------------------------------------------------
// TABEL ITEM (PAKAI MONOSPACE, COLUMN WIDTH FIX)
// --------------------------------------------------
$no = 1;
$total = 0;

if (empty($items)) {
    echo "(Tidak ada item)\n";
} else {
    foreach ($items as $it) {
        $nama    = pad_right($it['products_name'] ?: '-', 34);
        $sku     = pad_right($it['sku'] ?: '-', 10);
        $barcode = pad_right($it['barcode'] ?: '-', 12);
        $qty     = pad_left((int)$it['qty'], 4);
        $sat     = pad_right($it['unit'] ?: '-', 5);
        $harga   = pad_left(number_format((float)$it['unit_price'], 0, ',', '.'), 10);
        $disc    = pad_left(number_format((float)$it['disc_percent'], 2, ',', '.'), 8);
        $sub     = (float)$it['subtotal'];
        $subtotalText = pad_left(number_format($sub, 0, ',', '.'), 11);
        $total  += $sub;

        echo pad_left($no, 3) . ' '
           . $nama . ' '
           . $sku . ' '
           . $barcode . ' '
           . $qty . ' '
           . $sat . ' '
           . $harga . ' '
           . $disc . ' '
           . $subtotalText . "\n";

        $no++;
    }
}

// garis penutup item
echo str_repeat('-', 107) . "\n";

// --------------------------------------------------
// RINGKASAN TOTAL & PPN
// --------------------------------------------------
$dpp   = (float)$header['total_amount'];
$tax   = (float)$header['tax_amount'];
$grand = (float)$header['grand_total'];

echo pad_left('', 72)
   . pad_right('DPP / Subtotal :', 17)
   . pad_left(number_format($dpp, 0, ',', '.'), 18) . "\n";

echo pad_left('', 72)
   . pad_right('PPN / Tax      :', 17)
   . pad_left(number_format($tax, 0, ',', '.'), 18) . "\n";

echo pad_left('', 72)
   . pad_right('Grand Total    :', 17)
   . pad_left(number_format($grand, 0, ',', '.'), 18) . "\n";

echo "\n";

if ($isTaxIncluded) {
    echo 'Keterangan : Harga sudah TERMASUK PPN sesuai profil pajak.' . "\n";
} else {
    echo 'Keterangan : Harga BELUM termasuk PPN, atau sesuai profil pajak di sistem.' . "\n";
}

echo "\n";

// --------------------------------------------------
// BLOK TANDA TANGAN
// --------------------------------------------------
// TTD mengikuti grid 80 karakter yang sama dengan header dan tabel.
// Masing-masing blok 40 karakter agar kolom kiri/kanan benar-benar sejajar.
// Tiga pihak: CRM/Sales -> SCM/Kurir -> Customer.
// Total tetap 107 karakter agar alignment print yang sudah baik tidak berubah.
echo pad_right('Dibuat oleh (CRM / Sales)', 35)
   . pad_right('Diterima oleh (SCM / Kurir)', 36)
   . pad_right('Diterima oleh (Customer)', 36) . "\n";
echo "\n\n\n"; // ruang tanda tangan
echo pad_right('_____________________________', 35)
   . pad_right('_____________________________', 36)
   . pad_right('_____________________________', 36) . "\n";
echo pad_right('Nama Jelas & Tanggal', 35)
   . pad_right('Nama Jelas & Tanggal', 36)
   . pad_right('Nama Jelas, Tanda Tangan & Stempel', 36) . "\n";

?>
</div>
</div>
<?php rmi_footer(); ?>
