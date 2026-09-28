<?php
/**
 * Seed QA test users for each department (WQS, PQP, CRM, FIN, ACT, HRL, MPR).
 * Used for UI testing with role-scoped access.
 *
 * Run: php tools/seed/seed_qa_users.php (from NAS)
 * Or:  Open /tools/seed/seed_qa_users.php as Admin via browser.
 *
 * Env: QA_SEED_PASSWORD (default QaStaff#123)
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);

if (PHP_SAPI === 'cli') {
    require_once $root . '/_shared/env.php';
    if (function_exists('rmi_env_load')) {
        rmi_env_load();
    }
    require_once $root . '/_shared/db.php';
    if (!function_exists('rmi_db_pdo')) {
        fwrite(STDERR, "Error: rmi_db_pdo not found.\n");
        exit(1);
    }
    $pdo = rmi_db_pdo();
} else {
    require_once __DIR__ . '/../../master/auth.php';
    require_once __DIR__ . '/../tools_access_helpers.php';
    tools_require_access('seed/seed_qa_users.php');
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$defaultPass = (string)(getenv('QA_SEED_PASSWORD') ?: 'QaStaff#123');

$qaUsers = [
    ['qa_wqs', 'QA WQS', 'WQS'],
    ['qa_pqp', 'QA PQP', 'PQP'],
    ['qa_crm', 'QA CRM', 'CRM'],
    ['qa_fin', 'QA FIN', 'FIN'],
    ['qa_act', 'QA ACT', 'ACT'],
    ['qa_hrl', 'QA HRL', 'HRL'],
    ['qa_mpr', 'QA MPR', 'MPR'],
];

$created = 0;
$updated = 0;

$sel = $pdo->prepare('SELECT id, username FROM master_system_login WHERE username = ? LIMIT 1');
$up = $pdo->prepare(
    "UPDATE master_system_login
     SET password_hash = ?, full_name = ?, role = 'STAFF', level = 'STAFF', department = ?,
         office_code = COALESCE(NULLIF(TRIM(office_code),''), 'BGR'),
         status = 'ACTIVE', updated_at = NOW()
     WHERE id = ?"
);
$ins = $pdo->prepare(
    "INSERT INTO master_system_login (username, password_hash, full_name, role, level, department, office_code, status, created_at, updated_at)
     VALUES (?, ?, ?, 'STAFF', 'STAFF', ?, 'BGR', 'ACTIVE', NOW(), NOW())"
);

$hash = password_hash($defaultPass, PASSWORD_DEFAULT);

foreach ($qaUsers as [$username, $fullName, $dept]) {
    $sel->execute([$username]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $up->execute([$hash, $fullName, $dept, (int)$row['id']]);
        $updated++;
    } else {
        $ins->execute([$username, $hash, $fullName, $dept]);
        $created++;
    }
}

$out = [
    'ok' => true,
    'created' => $created,
    'updated' => $updated,
    'users' => array_map(fn($u) => ['username' => $u[0], 'dept' => $u[2]], $qaUsers),
    'password_env' => 'QA_SEED_PASSWORD',
];

if (PHP_SAPI === 'cli') {
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json');
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
