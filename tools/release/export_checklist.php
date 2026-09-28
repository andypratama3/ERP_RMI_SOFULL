<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once APP_ROOT . '/master/auth.php';

tools_require_access('release/export_checklist.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

$fmt = strtolower(trim((string)($_GET['fmt'] ?? 'json')));
$state = tools_json_read_safe(APP_ROOT . '/storage/logs/release_final_checklist_last.json');
$rows = (array)($state['rows'] ?? []);

if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="checklist_final.csv"');
    $fp = fopen('php://output', 'wb');
    fputcsv($fp, ['item', 'status', 'source', 'ts', 'notes_masked']);
    foreach ($rows as $row) {
        fputcsv($fp, [
            (string)($row['item'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['source'] ?? ''),
            (string)($row['ts'] ?? ''),
            (string)($row['notes_masked'] ?? ''),
        ]);
    }
    fclose($fp);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
