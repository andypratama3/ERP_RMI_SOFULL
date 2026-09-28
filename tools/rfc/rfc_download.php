<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/_lib/rfc_dashboard_lib.php';

tools_require_access('rfc/rfc_download.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

$requestId = 'rfc-dl-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
$rfcId = strtoupper(trim((string)($_GET['rfc'] ?? '')));
$guardOut = [];
$guardCode = 1;
$guardCmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ts_root() . '/tools/dev/verify_clean_room.php');
@exec($guardCmd . ' 2>&1', $guardOut, $guardCode);
if ((int)$guardCode !== 0) {
    rfcdash_append_audit('RFC_DOWNLOADED', $requestId, ['rfc_id' => $rfcId, 'ok' => false, 'error' => 'CLEAN_ROOM_GUARD_FAIL']);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Clean-room guard failed.\n";
    exit;
}
$index = rfcsf_read_index_last();
$resolved = rfcsf_resolve_rfc_file($rfcId, $index['ok'] ? (array)$index['data'] : null);

if (!$resolved['ok']) {
    rfcdash_append_audit('RFC_DOWNLOADED', $requestId, ['rfc_id' => $rfcId, 'ok' => false, 'error' => (string)($resolved['error'] ?? 'UNKNOWN')]);
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "RFC not found or blocked.\n";
    echo "Run: php tools/rfc/rfc_index_scan.php --write-last\n";
    exit;
}

$read = rfcsf_read_file_masked((string)$resolved['abs_path']);
if (!$read['ok']) {
    rfcdash_append_audit('RFC_DOWNLOADED', $requestId, ['rfc_id' => $rfcId, 'ok' => false, 'error' => 'READ_FAILED']);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Read failed.\n";
    exit;
}

$filename = $rfcId . '.md';
rfcdash_append_audit('RFC_DOWNLOADED', $requestId, ['rfc_id' => $rfcId, 'ok' => true, 'file' => (string)($resolved['rel_path_masked'] ?? '')]);

header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
echo (string)$read['content'];
exit;

