<?php
require_once __DIR__ . '/../_shared/rmi_icons.php';
// Debug: cek nilai session & scope detection untuk BRANCH user
// Hapus file ini setelah selesai debug
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../master/auth.php';
require_login();
$pdo = db_pdo();

$u = auth_user();
$dept_ec = strtoupper(trim((string)($u['department'] ?: $u['level'] ?: '')));
$role    = strtoupper(trim((string)($u['role'] ?? '')));
$office  = strtoupper(trim((string)($u['office_code'] ?? '')));
$is_branch = in_array($dept_ec, ['BRANCH'], true)
          && !in_array($role, ['SYS','ADMIN','SUPERADMIN'], true)
          && $office !== '';

// DB row
$dbRow = null;
try {
    $st = $pdo->prepare("SELECT role, level, department, office_code, status FROM master_system_login WHERE username=? LIMIT 1");
    $st->execute([$u['username']]);
    $dbRow = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

header('Content-Type: text/plain; charset=utf-8');
echo "USER         : {$u['username']}\n\n";
echo "--- SESSION ---\n";
echo "role         : " . ($_SESSION['role'] ?? '(kosong)') . "\n";
echo "level        : " . ($_SESSION['level'] ?? '(kosong)') . "\n";
echo "department   : " . ($_SESSION['department'] ?? '(kosong)') . "\n";
echo "office_code  : " . ($_SESSION['office_code'] ?? '(kosong)') . "\n\n";
echo "--- auth_user() ---\n";
echo "role         : {$u['role']}\n";
echo "level        : {$u['level']}\n";
echo "department   : {$u['department']}\n";
echo "office_code  : {$u['office_code']}\n\n";
echo "--- SCOPE RESULT ---\n";
echo "dept_detected: {$dept_ec}\n";
echo "is_branch    : " . ($is_branch ? 'YES ' . rmi_icon('tick') : 'NO ' . rmi_icon('x')) . "\n";
echo "office_scope : " . ($is_branch ? $office : '(null - lihat semua)') . "\n\n";
echo "--- DATABASE (master_system_login) ---\n";
if ($dbRow) {
    foreach ($dbRow as $k => $v) echo str_pad($k, 14) . ": " . var_export($v, true) . "\n";
} else {
    echo "(tidak ditemukan)\n";
}
