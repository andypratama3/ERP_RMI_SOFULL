<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker
function absensi_audit(PDO $pdo, array $actor, string $action, array $payload=[]): void {
  try{
    $stmt = $pdo->prepare("INSERT INTO absensi_audit
      (actor_user_id, actor_username, action, payload_json, ip, user_agent, created_at)
      VALUES (?,?,?,?,?,?,NOW())");
    $stmt->execute([
      $actor['id'] ?? null,
      $actor['username'] ?? null,
      $action,
      json_encode($payload, JSON_UNESCAPED_UNICODE),
      $_SERVER['REMOTE_ADDR'] ?? null,
      $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
  } catch (Throwable $e) {}
}
