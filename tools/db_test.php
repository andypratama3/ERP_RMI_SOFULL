<?php
// tools/db_test.php - quick DB connectivity test (PDO)
require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../master/auth.php';
if (PHP_SAPI !== 'cli') {
  require_login();
  require_role(['SYS', 'ADMIN','SUPERADMIN']);
}

try {
  $pdo = rmi_db_pdo();
  $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
  if (PHP_SAPI === 'cli') {
    $actor = getenv('USER') ?: 'SYSTEM';
    echo "OK DB CONNECT\n";
    echo "DB: " . htmlspecialchars((string)$db, ENT_QUOTES) . "\n";
    echo "ACTOR: " . htmlspecialchars((string)$actor, ENT_QUOTES) . "\n";
  } else {
    echo "OK DB CONNECT ✅<br>";
    echo "DB: " . htmlspecialchars((string)$db, ENT_QUOTES);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>DB CONNECT FAILED ❌\n" . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "</pre>";
}
