<?php

// --- auto-injected login guard (tools/enforce_login_guards.php) ---
require_once dirname(__DIR__, 1) . '/master/auth.php';
require_once dirname(__DIR__, 1) . '/_shared/rbac.php';
require_login();
if (function_exists('require_route_access')) {
    require_route_access(['MASTER.CUSTOMER_EXPORT']);
} elseif (function_exists('require_any_permission')) {
    require_any_permission(['MASTER.CUSTOMER_EXPORT']);
}
// -------------------------------------------------------------

// master_export_customers.php
// Export data master_customers ke CSV / Excel / Print View (PDF via browser)
// --- DB (centralized) ---
$pdo = db_pdo();

// format: csv / xls / pdf
$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';
if (!in_array($format, ['csv', 'xls', 'pdf'], true)) {
    $format = 'csv';
}

// Ambil semua data (tanpa filter/paging)
$sql = "SELECT
            customers_code,
            customers_name,
            category,
            city,
            office_code,
            cover_area,
            address,
            maps_url,
            phone,
            email,
            npwp,
            segment,
            staff_mpr_code,
            staff_crm_code,
            staff_scm_code,
            staff_act_code,
            staff_fin_code,
            status,
            created_at,
            updated_at
        FROM master_customers
        ORDER BY customers_name ASC";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();

$filename_date = date('Ymd_His');

// ------------------------------------------------------------------
//  CSV
// ------------------------------------------------------------------
if ($format === 'csv') {
    $filename = "master_customers_{$filename_date}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Header kolom
    fputcsv($output, [
        'customers_code',
        'customers_name',
        'category',
        'city',
        'office_code',
        'cover_area',
        'address',
        'maps_url',
        'phone',
        'email',
        'npwp',
        'segment',
        'staff_mpr_code',
        'staff_crm_code',
        'staff_scm_code',
        'staff_act_code',
        'staff_fin_code',
        'status',
        'created_at',
        'updated_at',
    ]);

    // Data
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['customers_code'],
            $r['customers_name'],
            $r['category'],
            $r['city'],
            $r['office_code'],
            $r['cover_area'],
            $r['address'],
            $r['maps_url'],
            $r['phone'],
            $r['email'],
            $r['npwp'],
            $r['segment'],
            $r['staff_mpr_code'],
            $r['staff_crm_code'],
            $r['staff_scm_code'],
            $r['staff_act_code'],
            $r['staff_fin_code'],
            $r['status'],
            $r['created_at'],
            $r['updated_at'],
        ]);
    }

    fclose($output);
    exit;
}

// ------------------------------------------------------------------
//  EXCEL (XLS sederhana pakai HTML table)
// ------------------------------------------------------------------
if ($format === 'xls') {
    $filename = "master_customers_{$filename_date}.xls";

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<table border='1'>";
    echo "<thead><tr>";
    $headers = [
        'Customers Code',
        'Customers Name',
        'Category',
        'City',
        'Office Code',
        'Cover Area',
        'Address',
        'Maps URL',
        'Phone',
        'Email',
        'NPWP',
        'Segment',
        'Staff MPR',
        'Staff CRM',
        'Staff SCM',
        'Staff ACT',
        'Staff FIN',
        'Status',
        'Created At',
        'Updated At',
    ];
    foreach ($headers as $h) {
        echo "<th>" . htmlspecialchars($h) . "</th>";
    }
    echo "</tr></thead><tbody>";

    foreach ($rows as $r) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($r['customers_code']) . "</td>";
        echo "<td>" . htmlspecialchars($r['customers_name']) . "</td>";
        echo "<td>" . htmlspecialchars($r['category']) . "</td>";
        echo "<td>" . htmlspecialchars($r['city']) . "</td>";
        echo "<td>" . htmlspecialchars($r['office_code']) . "</td>";
        echo "<td>" . htmlspecialchars($r['cover_area']) . "</td>";
        echo "<td>" . htmlspecialchars($r['address']) . "</td>";
        echo "<td>" . htmlspecialchars($r['maps_url']) . "</td>";
        echo "<td>" . htmlspecialchars($r['phone']) . "</td>";
        echo "<td>" . htmlspecialchars($r['email']) . "</td>";
        echo "<td>" . htmlspecialchars($r['npwp']) . "</td>";
        echo "<td>" . htmlspecialchars($r['segment']) . "</td>";
        echo "<td>" . htmlspecialchars($r['staff_mpr_code']) . "</td>";
        echo "<td>" . htmlspecialchars($r['staff_crm_code']) . "</td>";
        echo "<td>" . htmlspecialchars($r['staff_scm_code']) . "</td>";
        echo "<td>" . htmlspecialchars($r['staff_act_code']) . "</td>";
        echo "<td>" . htmlspecialchars($r['staff_fin_code']) . "</td>";
        echo "<td>" . htmlspecialchars($r['status']) . "</td>";
        echo "<td>" . htmlspecialchars($r['created_at']) . "</td>";
        echo "<td>" . htmlspecialchars($r['updated_at']) . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    exit;
}

// ------------------------------------------------------------------
//  PRINT VIEW (PDF via browser: File -> Print -> Save as PDF)
// ------------------------------------------------------------------
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('Export Master Customers - Print View', [
  'active' => 'master',
  'breadcrumbs' => [
    ['label' => 'Master Data', 'url' => $baseProject . '/master/index.php'],
    'Export Master Customers - Print View',
  ],
  'extra_head' => '<style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 12px;
            color: #111827;
            padding: 16px;
        }
        h2 {
            margin: 0 0 8px 0;
            font-size: 18px;
        }
        .subtitle {
            font-size: 11px;
            color: #4b5563;
            margin-bottom: 12px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 10px;
        }
        th, td {
            border: 1px solid #9ca3af;
            padding: 4px 6px;
        }
        th {
            background: #e5e7eb;
        }


/* RMI_PATCH_DARK_TABLE_VISIBILITY */
/* RMI_PATCH_DARK_TABLE_VISIBILITY: keep dark tables readable + show full text */
.table-dark-custom{
  --bs-table-color: rgba(255,255,255,0.92);
  --bs-table-striped-color: rgba(255,255,255,0.92);
  --bs-table-hover-color: rgba(255,255,255,0.98);
  --bs-table-active-color: rgba(255,255,255,0.98);
}
.table-dark-custom td,
.table-dark-custom th,
.table-dark-custom a{
  color: var(--bs-table-color) !important;
}
.table-dark-custom .text-muted,
.table-dark-custom .text-secondary{
  color: rgba(255,255,255,0.78) !important;
}

/* Prevent truncation/ellipsis that hides content */
.table-dark-custom td,
.table-dark-custom th{
  white-space: normal !important;
  overflow: visible !important;
  text-overflow: clip !important;
  word-break: break-word;
}
.table-dark-custom .text-truncate,
.table-dark-custom .truncate,
.table-dark-custom .nowrap{
  white-space: normal !important;
  overflow: visible !important;
  text-overflow: clip !important;
}
</style>',
]);
?>

<h2>Master Customers - ERP RMI</h2>
<div class="subtitle">
    Export Print View &middot; <?= date('d-m-Y H:i:s') ?><br>
    Untuk menyimpan PDF: gunakan menu <strong>Print → Save as PDF</strong> di browser.
</div>

<table>
    <thead>
    <tr>
        <th>Code</th>
        <th>Name</th>
        <th>Category</th>
        <th>City</th>
        <th>Office</th>
        <th>Cover Area</th>
        <th>Address</th>
        <th>Phone</th>
        <th>Email</th>
        <th>NPWP</th>
        <th>Segment</th>
        <th>StaffMPR</th>
        <th>StaffCRM</th>
        <th>StaffSCM</th>
        <th>StaffACT</th>
        <th>StaffFIN</th>
        <th>Status</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr>
            <td colspan="17" style="text-align:center;">Belum ada data customers.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['customers_code']) ?></td>
                <td><?= htmlspecialchars($r['customers_name']) ?></td>
                <td><?= htmlspecialchars($r['category']) ?></td>
                <td><?= htmlspecialchars($r['city']) ?></td>
                <td><?= htmlspecialchars($r['office_code']) ?></td>
                <td><?= htmlspecialchars($r['cover_area']) ?></td>
                <td><?= htmlspecialchars($r['address']) ?></td>
                <td><?= htmlspecialchars($r['phone']) ?></td>
                <td><?= htmlspecialchars($r['email']) ?></td>
                <td><?= htmlspecialchars($r['npwp']) ?></td>
                <td><?= htmlspecialchars($r['segment']) ?></td>
                <td><?= htmlspecialchars($r['staff_mpr_code']) ?></td>
                <td><?= htmlspecialchars($r['staff_crm_code']) ?></td>
                <td><?= htmlspecialchars($r['staff_scm_code']) ?></td>
                <td><?= htmlspecialchars($r['staff_act_code']) ?></td>
                <td><?= htmlspecialchars($r['staff_fin_code']) ?></td>
                <td><?= htmlspecialchars($r['status']) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
<?php rmi_footer(); ?>
