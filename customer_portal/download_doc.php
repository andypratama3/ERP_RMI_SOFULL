<?php
/**
 * customer_portal/download_doc.php
 * Download dokumen pendukung (validasi: user punya akses ke DO).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_portal_login();

$doc_id = (int)($_GET['id'] ?? 0);
if ($doc_id <= 0) {
    http_response_code(400);
    exit('Invalid request');
}

$pdo = rmi_db_pdo();
$user = portal_user();

$chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sales_do_portal_docs'");
if (!$chk || !$chk->fetch()) {
    http_response_code(404);
    exit('Not found');
}

$st = $pdo->prepare("
    SELECT d.id, d.file_name, d.file_rel, d.do_id
    FROM sales_do_portal_docs d
    JOIN sales_do s ON s.id = d.do_id AND s.customers_code = ?
    WHERE d.id = ?
");
$st->execute([$user['customers_code'], $doc_id]);
$doc = $st->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan atau tidak ada akses.');
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$path = $root . '/' . $doc['file_rel'];
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($doc['file_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
