<?php
require_once __DIR__ . '/_inc/bootstrap.php';


require_login(); // static scan marker
if (!hrlp_can_enter_module()) {
    http_response_code(403);
    echo "access denied";
    exit;
}
$fileId = (int)($_GET['file_id'] ?? 0);
if ($fileId <= 0) {
    http_response_code(400);
    echo "file_id invalid";
    exit;
}

$stmt = $pdo->prepare("SELECT f.*, r.* FROM hrl_request_files f
    JOIN hrl_requests r ON r.id = f.request_id
    WHERE f.id = ? LIMIT 1");
$stmt->execute([$fileId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo "file not found";
    exit;
}

$req = $row; // contains request fields too
if (!hrlp_can_view($req)) {
    http_response_code(403);
    echo "access denied";
    exit;
}

$path = (string)($row['file_path'] ?? '');
$real = $path ? realpath($path) : false;
if (!$real || !is_file($real)) {
    http_response_code(404);
    echo "file missing on disk";
    exit;
}

// Ensure within uploads/hrl_process (basic safety)
$root = realpath(hrlp_upload_root());
if ($root && strpos($real, $root) !== 0) {
    http_response_code(403);
    echo "invalid path";
    exit;
}

$fname = (string)($row['file_name'] ?? ('file_'.$fileId));
$fnameSafe = preg_replace('/[\r\n"]+/', '_', $fname);
$mime = (string)($row['mime'] ?? '');
if ($mime === '') { $mime = 'application/octet-stream'; }

$inline = (string)($_GET['inline'] ?? '') === '1';
if (!$inline && strtoupper((string)($row['kind'] ?? '')) === 'PHOTO') {
    // allow photo inline by default
    $inline = true;
}
$disp = $inline ? 'inline' : 'attachment';

while (ob_get_level()) { @ob_end_clean(); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: ' . $disp . '; filename="' . $fnameSafe . '"');
header('X-Content-Type-Options: nosniff');

readfile($real);
exit;
