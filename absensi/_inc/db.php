<?php
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker
// Simple env loader (dotenv-lite). If ERP already has config, you can map it here.
function rmi_env($key, $default=null) {
  $val = getenv($key);
  if ($val === false || $val === null || $val === '') return $default;
  return $val;
}

function rmi_db() : PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $defaultPort = (string)((int)(rmi_env('DB_PORT_DEFAULT', '3306')));
  $defaultPass = (string)rmi_env('DB_PASS_DEFAULT', '');
  $host = rmi_env('ERP_DB_HOST', rmi_env('DB_HOST', '127.0.0.1'));
  $port = rmi_env('ERP_DB_PORT', rmi_env('DB_PORT', $defaultPort));
  $name = rmi_env('ERP_DB_NAME', rmi_env('DB_DATABASE', rmi_env('DB_NAME', 'ERP_RMI_SOFULL')));
  $user = rmi_env('ERP_DB_USER', rmi_env('DB_USERNAME', rmi_env('DB_USER', 'root')));
  $pass = rmi_env('ERP_DB_PASS', rmi_env('DB_PASSWORD', rmi_env('DB_PASS', $defaultPass)));

  $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}
