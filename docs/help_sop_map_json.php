<?php
/**
 * docs/help_sop_map_json.php
 * Endpoint untuk serve help_sop_map.json — dipakai oleh Help F1 (rmi_assist.js).
 * Tanpa require_login agar fetch dari halaman yang sudah login bisa dapat JSON
 * (fetch kirim cookie same-origin). File JSON tidak sensitif.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300'); // 5 menit cache

$path = __DIR__ . '/help_sop_map.json';
if (!is_file($path)) {
  http_response_code(404);
  echo json_encode(['error' => 'help_sop_map.json not found']);
  exit;
}

$json = file_get_contents($path);
if ($json === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Failed to read file']);
  exit;
}

// Validasi JSON
$data = json_decode($json, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(500);
  echo json_encode(['error' => 'Invalid JSON']);
  exit;
}

echo $json;
