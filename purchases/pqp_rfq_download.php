<?php
/**
 * purchases/pqp_rfq_download.php
 * Download attachment quotation (validasi: PQP login).
 */
declare(strict_types=1);

require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();
require_once __DIR__ . '/../master/auth.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PQP.VIEW']);
} else {
    require_role(['ADMIN', 'SUPERADMIN', 'SYS', 'PQP', 'SCM', 'FIN', 'ACT', 'WQS', 'BRANCH', 'MANAGER', 'STAFF']);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid request');
}

$pdo = p_pdo();
$st = $pdo->prepare("SELECT q.file_rel FROM pqp_rfq_quotations q WHERE q.id=? AND q.file_rel IS NOT NULL AND q.file_rel != ''");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$path = realpath($root . '/' . ltrim((string)$row['file_rel'], '/'));
if ($path === false || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}
if (strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
    http_response_code(403);
    exit('Forbidden');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';
$filename = basename($path);
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
