<?php
/**
 * absensi/photo.php — Serve absensi photo securely (auth required)
 * Usage: /absensi/photo.php?f=uploads/absensi/2026/03/filename.jpg
 */
declare(strict_types=1);
require_once __DIR__ . "/_inc/bootstrap.php";
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.VIEW');

$rel  = ltrim(trim((string)($_GET['f'] ?? '')), '/');
$root = realpath(__DIR__ . '/..');

// Security: only allow uploads/absensi/ prefix, no ..
if ($rel === '' || strpos($rel, '..') !== false || !str_starts_with($rel, 'uploads/absensi/')) {
    http_response_code(403);
    exit('Forbidden');
}

$absPath = $root . '/' . $rel;
$real    = realpath($absPath);

// Must be inside project root AND inside uploads/absensi
if (!$real || !str_starts_with($real, $root . '/uploads/absensi/') || !is_file($real)) {
    http_response_code(404);
    exit('Not Found');
}

// Serve file
$ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$mime = match($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'gif'         => 'image/gif',
    'webp'        => 'image/webp',
    default       => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($real);
exit;
