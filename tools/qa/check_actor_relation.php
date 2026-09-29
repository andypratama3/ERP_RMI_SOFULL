<?php
/**
 * tools/qa/check_actor_relation.php
 * Audit relasi pelaku -> akun -> employee untuk blok tanda tangan.
 *
 * Rantai yang WAJIB utuh:
 *   sales_do.*_by (username)
 *     -> master_system_login.username
 *     -> master_system_login.holder_employee_code
 *     -> master_employees.employee_code
 *
 * Tidak menebak & tidak menulis: hanya melaporkan. Perbaikan relasi
 * dilakukan lewat master_system_login (UI "Holder" / migration).
 *
 * Pakai: php tools/qa/check_actor_relation.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$c = require __DIR__ . '/../../config-db.php';
$pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
    $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$isDept = static function (string $v): bool {
    return in_array(strtoupper(trim($v)), ['WQS', 'SCM', 'ACT', 'FIN', 'CRM', 'HRL', 'ITC'], true);
};

echo "=== 1. AKUN WQS/SCM: relasi -> master_employees ===\n";
$q = $pdo->query("SELECT username, full_name, department, office_code, holder_employee_code
                  FROM master_system_login
                  WHERE deleted_at IS NULL
                    AND (department IN ('WQS','SCM')
                         OR username LIKE '%WQS%' OR username LIKE '%SCM%')
                  ORDER BY username");
$linked = 0; $unlinked = 0; $orphan = []; $wrongDept = [];
foreach ($q as $r) {
    $hc = trim((string) $r['holder_employee_code']);
    $dept = strtoupper((string) $r['department']);
    if ($hc === '') { $unlinked++; continue; }
    $s = $pdo->prepare("SELECT employee_name FROM master_employees WHERE employee_code=?");
    $s->execute([$hc]);
    $nm = $s->fetchColumn();
    if ($nm === false) { $orphan[] = "{$r['username']} -> {$hc} (employee tidak ada)"; continue; }
    if ($dept !== '' && $dept !== '' && strtoupper(substr($hc, 0, 3)) !== $dept) {
        $wrongDept[] = "{$r['username']} (dept {$dept}) -> {$hc} ({$nm})";
    }
    $linked++;
}
printf("  tertaut         : %d\n  belum tertaut   : %d\n  kode yatim      : %d\n  kode beda dept  : %d\n",
    $linked, $unlinked, count($orphan), count($wrongDept));
foreach ($orphan as $o)   echo "  [YATIM]  $o\n";
foreach ($wrongDept as $o) echo "  [X-DEPT] $o\n";

echo "\n=== 2. AKTOR DI sales_do: apakah bisa ditelusuri ===\n";
$actorCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM sales_do") as $r) {
    if (str_ends_with($r['Field'], '_by') && !in_array($r['Field'], ['last_updated_by'], true)) $actorCols[] = $r['Field'];
}
$bad = []; $good = 0; $empty = 0;
foreach ($actorCols as $col) {
    $s = $pdo->query("SELECT DISTINCT `$col` AS v FROM sales_do WHERE `$col` IS NOT NULL AND TRIM(`$col`) <> ''");
    foreach ($s as $r) {
        $v = trim((string) $r['v']);
        if ($v === '') continue;
        if ($isDept($v)) { $bad[] = "$col = '$v' (kode dept, bukan orang)"; continue; }
        $s2 = $pdo->prepare("SELECT full_name, holder_employee_code FROM master_system_login WHERE username=? LIMIT 1");
        $s2->execute([$v]);
        $lg = $s2->fetch(PDO::FETCH_ASSOC);
        if (!$lg) { $bad[] = "$col = '$v' (akun tidak ada di master_system_login)"; continue; }
        $hc = trim((string) ($lg['holder_employee_code'] ?? ''));
        if ($hc === '') { $good++; continue; }  // label jatuh ke full_name akun — sah
        $s3 = $pdo->prepare("SELECT employee_name FROM master_employees WHERE employee_code=?");
        $s3->execute([$hc]);
        if ($s3->fetchColumn() === false) $bad[] = "$col = '$v' -> $hc (employee tidak ada)";
        else $good++;
    }
}
printf("  aktor tertelusuri : %d\n  aktor bermasalah  : %d\n", $good, count($bad));
foreach ($bad as $b) echo "  [X] $b\n";

echo "\n=== RINGKASAN ===\n";
$fail = count($orphan) + count($wrongDept) + count($bad);
echo $fail === 0
    ? "  LINTAS: semua relasi aktor utuh (tidak ada kode dept, akun hilang, atau employee yatim).\n"
    : "  PERLU TINDAK: $fail masalah relasi di atas. Perbaiki via master_system_login > Holder.\n";
exit($fail === 0 ? 0 : 1);
