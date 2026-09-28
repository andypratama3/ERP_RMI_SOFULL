<?php
require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../master/auth.php';
if (PHP_SAPI !== 'cli') {
  require_login();
  require_role(['SYS', 'ADMIN','SUPERADMIN']);
}

rmi_log('INFO', 'log_test fired', [
  'time' => date('c'),
  'url'  => $_SERVER['REQUEST_URI'] ?? '',
  'actor' => PHP_SAPI === 'cli' ? (getenv('USER') ?: 'SYSTEM') : ((string)($_SESSION['username'] ?? 'SYSTEM')),
]);

echo "WROTE LOG ✅";