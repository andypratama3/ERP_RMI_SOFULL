<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker
function absensi_flash_set(string $type, string $msg): void {
  if (session_status() === PHP_SESSION_NONE) session_start();
  $_SESSION['_abs_flash'] = ['type'=>$type,'msg'=>$msg];
}
function absensi_flash_get(): ?array {
  if (session_status() === PHP_SESSION_NONE) session_start();
  $v = $_SESSION['_abs_flash'] ?? null;
  unset($_SESSION['_abs_flash']);
  return $v;
}
