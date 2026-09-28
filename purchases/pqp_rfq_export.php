<?php
/**
 * purchases/pqp_rfq_export.php
 * Export comparison quotation ke CSV/Excel.
 */
declare(strict_types=1);

require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();
require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/pqp_rfq_helper.php';
if (function_exists('require_any_permission')) {
    require_any_permission(['PQP.VIEW']);
} else {
    require_role(['ADMIN', 'SUPERADMIN', 'SYS', 'PQP', 'SCM', 'FIN', 'ACT', 'WQS', 'BRANCH', 'MANAGER', 'STAFF']);
}

$pdo = p_pdo();
$id = (int)($_GET['id'] ?? 0);
$format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'xls'], true)) $format = 'csv';

if ($id <= 0) {
    rmi_redirect('pqp_rfq.php');
}

$st = $pdo->prepare("SELECT * FROM pqp_rfq WHERE id=?");
$st->execute([$id]);
$rfq = $st->fetch(PDO::FETCH_ASSOC);
if (!$rfq) {
    rmi_redirect('pqp_rfq.php');
}

$st = $pdo->prepare("
    SELECT q.manufacture_code, m.manufacture_name, q.unit_price, q.currency, q.lead_time_days, q.payment_terms, q.notes, q.file_rel, q.submitted_at, q.submitted_by
    FROM pqp_rfq_quotations q
    LEFT JOIN master_manufactures m ON m.id=q.manufacture_id
    WHERE q.rfq_id=? AND q.status='submitted'
    ORDER BY q.unit_price ASC
");
$st->execute([$id]);
$quotations = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($quotations as $i => $q) {
    $quotations[$i]['unit_price_usd'] = pqp_rfq_to_usd($pdo, (float)$q['unit_price'], $q['currency'] ?? 'USD');
}
usort($quotations, fn($a, $b) => $a['unit_price_usd'] <=> $b['unit_price_usd']);

$filename = 'RFQ_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $rfq['rfq_code']) . '_' . date('Ymd_His') . '.' . ($format === 'xls' ? 'xls' : 'csv');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['RFQ', $rfq['rfq_code'], $rfq['title'], $rfq['deadline']]);
    fputcsv($out, []);
    fputcsv($out, ['#', 'Manufacturer', 'Manufacture Code', 'Unit Price', 'Currency', '≈ USD', 'Lead Time (days)', 'Payment Terms', 'Notes', 'Attachment', 'Submitted At', 'Submitted By']);
    foreach ($quotations as $i => $q) {
        fputcsv($out, [
            $i + 1,
            $q['manufacture_name'] ?? '',
            $q['manufacture_code'] ?? '',
            $q['unit_price'],
            $q['currency'] ?? '',
            number_format((float)($q['unit_price_usd'] ?? 0), 2),
            $q['lead_time_days'] ?? '',
            $q['payment_terms'] ?? '',
            $q['notes'] ?? '',
            $q['file_rel'] ?? '',
            $q['submitted_at'] ?? '',
            $q['submitted_by'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// XLS (HTML table - Excel compatible)
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF"; // UTF-8 BOM
echo '<table border="1"><tr><td colspan="4"><strong>RFQ ' . rmi_h($rfq['rfq_code']) . ' — ' . rmi_h($rfq['title']) . '</strong></td></tr>';
echo '<tr><td>Deadline</td><td colspan="3">' . rmi_h($rfq['deadline']) . '</td></tr>';
echo '<tr></tr>';
echo '<tr><th>#</th><th>Manufacturer</th><th>Code</th><th>Unit Price</th><th>Currency</th><th>≈ USD</th><th>Lead Time</th><th>Payment Terms</th><th>Notes</th><th>Attachment</th><th>Submitted</th><th>By</th></tr>';
foreach ($quotations as $i => $q) {
    echo '<tr>';
    echo '<td>' . ($i + 1) . '</td>';
    echo '<td>' . rmi_h($q['manufacture_name'] ?? '') . '</td>';
    echo '<td>' . rmi_h($q['manufacture_code'] ?? '') . '</td>';
    echo '<td>' . number_format((float)$q['unit_price'], 2) . '</td>';
    echo '<td>' . rmi_h($q['currency'] ?? '') . '</td>';
    echo '<td>' . number_format((float)($q['unit_price_usd'] ?? 0), 2) . '</td>';
    echo '<td>' . ($q['lead_time_days'] ?? '') . '</td>';
    echo '<td>' . rmi_h($q['payment_terms'] ?? '') . '</td>';
    echo '<td>' . rmi_h($q['notes'] ?? '') . '</td>';
    echo '<td>' . rmi_h($q['file_rel'] ?? '') . '</td>';
    echo '<td>' . rmi_h($q['submitted_at'] ?? '') . '</td>';
    echo '<td>' . rmi_h($q['submitted_by'] ?? '') . '</td>';
    echo '</tr>';
}
echo '</table>';
exit;
