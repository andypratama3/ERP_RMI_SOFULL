<?php
declare(strict_types=1);

/**
 * Apply due employee mutations.
 * CLI-first. Safe to run daily/hourly from NAS scheduler.
 */
require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_inc/employee_mutation_helper.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    require_login();
    if (!hrlm_is_admin()) { http_response_code(403); exit('SYS only'); }
}

$actor = $isCli ? 'SYSTEM_CRON' : hrlm_username();
$st = $pdo->prepare("SELECT id FROM hrl_employee_mutations
                     WHERE status IN ('APPROVED','SCHEDULED')
                       AND effective_date<=CURDATE()
                     ORDER BY effective_date,id");
$st->execute();
$ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

$ok = 0; $fail = 0; $errors = [];
foreach ($ids as $id) {
    try {
        hrlm_apply_effective($pdo, $id, $actor, false);
        $ok++;
    } catch (Throwable $e) {
        $fail++;
        $errors[] = ['id'=>$id,'error'=>$e->getMessage()];
    }
}

if ($isCli) {
    echo json_encode(['ok'=>$fail===0,'applied'=>$ok,'failed'=>$fail,'errors'=>$errors], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($fail===0 ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>$fail===0,'applied'=>$ok,'failed'=>$fail,'errors'=>$errors], JSON_UNESCAPED_SLASHES);
