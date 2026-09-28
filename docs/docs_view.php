<?php
/**
 * docs/docs_view.php
 * Proxy untuk docs internal (governance, ops, archive, NOTES) — HANYA user login.
 * Mencegah akses publik ke runbook, policy, template, dll.
 */
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/_token_helper.php';

$token = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
$hasValidToken = $token !== '' && function_exists('docs_view_token_validate') && docs_view_token_validate($token);
if (!$hasValidToken) {
  require_login();
}

$root = dirname(__DIR__);
$docsDir = $root . '/docs';

$f = isset($_GET['f']) ? trim((string)$_GET['f']) : '';
if ($f === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Parameter f (file) wajib. Contoh: ?f=governance/OPS_RUNBOOK_FINAL.md';
  exit;
}

// Cegah path traversal
$f = str_replace(['../', '..\\', "\0"], '', $f);
$f = ltrim($f, '/\\');
if ($f === '' || strpos($f, '..') !== false) {
  http_response_code(400);
  exit('Path tidak valid.');
}

// Hanya subfolder/file yang diizinkan (whitelist)
$allowedPrefixes = ['governance/', 'ops/', 'archive/', 'NOTES_'];
$ok = false;
foreach ($allowedPrefixes as $p) {
  if (strpos($f, $p) === 0) {
    $ok = true;
    break;
  }
}
if (!$ok && preg_match('/^NOTES_[A-Za-z0-9_]+\.md$/', $f)) {
  $ok = true;
}
// Panduan modul ERP (Help Center → modules_hub)
if (!$ok && preg_match('/^modules/[A-Za-z0-9_]+\.md$/', $f)) {
  $ok = true;
}
// File .md di root docs/ (allowlist ketat — untuk pintasan dari modules_hub / help)
$allowedRootMd = [
  'SESSION_POLICY.md',
  'MONITORING_AND_CONTROL.md',
  'BASELINES.md',
];
if (!$ok && preg_match('/^[A-Za-z0-9_]+\.md$/', $f) && in_array($f, $allowedRootMd, true)) {
  $ok = true;
}
if (!$ok) {
  http_response_code(403);
  exit('Akses ditolak.');
}

$path = realpath($docsDir . '/' . $f);
if ($path === false || !is_file($path)) {
  http_response_code(404);
  exit('File tidak ditemukan.');
}

$docsReal = realpath($docsDir);
if ($docsReal === false || strpos($path, $docsReal) !== 0) {
  http_response_code(403);
  exit('Akses ditolak.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = [
  'md'   => 'text/markdown; charset=utf-8',
  'html' => 'text/html; charset=utf-8',
  'htm'  => 'text/html; charset=utf-8',
  'yaml' => 'text/yaml; charset=utf-8',
  'yml'  => 'text/yaml; charset=utf-8',
  'csv'  => 'text/csv; charset=utf-8',
  'txt'  => 'text/plain; charset=utf-8',
  'sql'  => 'text/plain; charset=utf-8',
  'diff' => 'text/plain; charset=utf-8',
  'pdf'  => 'application/pdf',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($path) . '"');

readfile($path);
exit;
