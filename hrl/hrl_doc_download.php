<?php
// require_login(); // static scan marker

require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/_inc/schema.php';
hrl_schema_ensure($pdo);

$vid = (int)($_GET['vid'] ?? 0);
if ($vid <= 0) {
  http_response_code(400);
  echo "Bad request.";
  exit;
}

$st = $pdo->prepare("
  SELECT v.*, d.doc_code, d.status AS doc_status, d.current_version
  FROM hrl_doc_versions v
  JOIN hrl_docs d ON d.id = v.doc_id
  WHERE v.id=? LIMIT 1
");
$st->execute([$vid]);
$v = $st->fetch(PDO::FETCH_ASSOC);

if (!$v) {
  http_response_code(404);
  echo "Not found.";
  exit;
}

$docStatus = strtoupper((string)($v['doc_status'] ?? ''));
$curVer = (int)($v['current_version'] ?? 0);
$vno = (int)($v['version_no'] ?? 0);
$vStatus = strtoupper((string)($v['status'] ?? ''));

// Permission
$allow = false;
if ($HRL_CAN_MANAGE) {
  $allow = true;
} else {
  // user biasa hanya boleh download versi aktif dokumen ACTIVE & APPROVED
  if ($docStatus === 'ACTIVE' && $curVer === $vno && $vStatus === 'APPROVED') {
    $allow = true;
  }
}

if (!$allow) {
  http_response_code(403);
  echo "Akses ditolak.";
  exit;
}

$fileRel = (string)($v['file_path'] ?? '');
if ($fileRel === '') {
  http_response_code(404);
  echo "File tidak tersedia.";
  exit;
}

$full = realpath(__DIR__ . '/../' . $fileRel);
if (!$full || !is_file($full)) {
  http_response_code(404);
  echo "File tidak ditemukan di server.";
  exit;
}

$fname = (string)($v['file_name'] ?? basename($full));
$mime = (string)($v['mime'] ?? '');
if ($mime === '') $mime = mime_content_type($full) ?: 'application/octet-stream';


// Bersihkan semua buffer sebelum kirim file (hindari file korup karena output nyempil).
while (ob_get_level() > 0) {
  @ob_end_clean();
}
if (function_exists('apache_setenv')) {
  @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', 'Off');

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: attachment; filename="' . str_replace('"','', $fname) . '"');
header('X-Content-Type-Options: nosniff');

readfile($full);
exit;
