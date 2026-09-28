<?php
// require_login(); // static scan marker (login enforced via _kpi_bootstrap.php)
// kpi/_kpi_policy.php
// Helper untuk baca kebijakan owner (system_config) tanpa bergantung sync KPI.

require_once __DIR__ . '/_kpi_bootstrap.php';

function kpi_policy_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        return ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) {
        return false;
    }
}

function kpi_policy_get(PDO $pdo, string $group, string $key, ?string $office_code = null, $default = null) {
    if (!kpi_policy_table_exists($pdo, 'system_config')) return $default;

    try {
        if ($office_code === null || $office_code === '') {
            $sql = "SELECT config_value FROM system_config
                    WHERE config_group = :g
                      AND config_key = :k
                      AND is_active = 1
                      AND office_code IS NULL
                    ORDER BY id DESC
                    LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':g' => $group,
                ':k' => $key,
            ]);
        } else {
            $sql = "SELECT config_value FROM system_config
                    WHERE config_group = :g
                      AND config_key = :k
                      AND is_active = 1
                      AND office_code = :o
                    ORDER BY id DESC
                    LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':g' => $group,
                ':k' => $key,
                ':o' => $office_code,
            ]);
        }

        $v = $st->fetchColumn();
        return ($v === false) ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function kpi_policy_get_targets_office(PDO $pdo, string $period, string $office_code): array {
    $out = [];
    if (!kpi_policy_table_exists($pdo, 'system_config')) return $out;
    try {
        $like = $period . '|%';
        $st = $pdo->prepare("SELECT config_key, config_value FROM system_config
                             WHERE config_group='KPI_TARGET_OFFICE'
                               AND office_code=:o AND is_active=1
                               AND config_key LIKE :lk");
        $st->execute([':o'=>$office_code, ':lk'=>$like]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $ck = (string)($r['config_key'] ?? '');
            $val = (string)($r['config_value'] ?? '0');
            // config_key format: YYYY-MM|TARGET_...
            $parts = explode('|', $ck, 2);
            if (count($parts) === 2) {
                $out[$parts[1]] = $val;
            }
        }
    } catch (Throwable $e) {
        // fail-soft
    }
    return $out;
}

function kpi_policy_parse_workdays(string $csv): array {
    $csv = strtoupper(trim($csv));
    if ($csv === '') $csv = 'MON,TUE,WED,THU,FRI,SAT';
    $arr = array_filter(array_map('trim', explode(',', $csv)));
    // normalize 3-letter day codes
    $map = ['MON'=>1,'TUE'=>2,'WED'=>3,'THU'=>4,'FRI'=>5,'SAT'=>6,'SUN'=>7];
    $nums = [];
    foreach ($arr as $d) {
        $d = substr($d,0,3);
        if (isset($map[$d])) $nums[$d] = $map[$d];
    }
    return array_values($nums);
}

function kpi_policy_month_range(string $period): array {
    // period: YYYY-MM
    $start = $period . '-01';
    $dt = new DateTime($start);
    $end = clone $dt;
    $end->modify('last day of this month');
    return [$start, $end->format('Y-m-d')];
}

function kpi_policy_count_workdays_in_period(string $period, string $work_days_csv): int {
    [$start, $end] = kpi_policy_month_range($period);
    $days = kpi_policy_parse_workdays($work_days_csv);
    if (empty($days)) return 0;

    $d1 = new DateTime($start);
    $d2 = new DateTime($end);
    $d2->modify('+1 day');

    $cnt = 0;
    for ($d = clone $d1; $d < $d2; $d->modify('+1 day')) {
        $n = (int)$d->format('N');
        if (in_array($n, $days, true)) $cnt++;
    }
    return $cnt;
}

function kpi_policy_money($v): string {
    $n = is_numeric($v) ? (float)$v : 0.0;
    return number_format($n, 0, ',', '.');
}

// -------------------------------------------------------------------------
// KPI DO SLA — kebijakan menit per stage (hanya SYS yang menyimpan via POST)
// Disimpan di system_config group KPI_DO_SLA; fallback ke KPI_SLA *jam* lalu default.
// -------------------------------------------------------------------------

function kpi_do_sla_policy_group(): string {
    return 'KPI_DO_SLA';
}

/** @return array{CRM:int,WQS:int,SCM:int,ACT:int,FIN:int} */
function kpi_do_sla_policy_defaults(): array {
    return [
        'CRM' => 30,
        'WQS' => 240,
        'SCM' => 1440,
        'ACT' => 2880,
        'FIN' => 10080,
    ];
}

function kpi_do_sla_clamp_minutes(int $m): int {
    if ($m < 1) {
        return 1;
    }
    if ($m > 525600) {
        return 525600;
    }
    return $m;
}

/**
 * Baca SLA DO (menit) dari DB + fallback legacy KPI_SLA (jam).
 *
 * @return array{CRM:int,WQS:int,SCM:int,ACT:int,FIN:int}
 */
function kpi_do_sla_policy_load(PDO $pdo): array {
    $defs = kpi_do_sla_policy_defaults();
    $group = kpi_do_sla_policy_group();
    $keys = [
        'CRM' => 'SLA_CRM_MINUTES',
        'WQS' => 'SLA_WQS_MINUTES',
        'SCM' => 'SLA_SCM_MINUTES',
        'ACT' => 'SLA_ACT_MINUTES',
        'FIN' => 'SLA_FIN_MINUTES',
    ];
    $legacyHours = [
        'CRM' => 'SLA_CRM_HOURS',
        'WQS' => 'SLA_WQS_HOURS',
        'SCM' => 'SLA_SCM_HOURS',
        'ACT' => 'SLA_ACT_HOURS',
        'FIN' => 'SLA_FIN_HOURS',
    ];
    $out = [];
    foreach ($keys as $dep => $cfgKey) {
        $v = kpi_policy_get($pdo, $group, $cfgKey, null, null);
        if ($v !== null && $v !== '' && is_numeric($v)) {
            $out[$dep] = kpi_do_sla_clamp_minutes((int)$v);
            continue;
        }
        $h = kpi_policy_get($pdo, 'KPI_SLA', $legacyHours[$dep], null, null);
        if ($h !== null && $h !== '' && is_numeric($h)) {
            $out[$dep] = kpi_do_sla_clamp_minutes((int)round((float)$h * 60.0));
            continue;
        }
        $out[$dep] = $defs[$dep];
    }
    return $out;
}

/**
 * Simpan kebijakan SLA DO (menit) — global, office_code NULL.
 *
 * @param array{CRM?:int|mixed,WQS?:int|mixed,SCM?:int|mixed,ACT?:int|mixed,FIN?:int|mixed} $slaMinutes
 */
function kpi_do_sla_policy_save(PDO $pdo, array $slaMinutes): void {
    if (!kpi_policy_table_exists($pdo, 'system_config')) {
        throw new RuntimeException('system_config tidak tersedia');
    }
    $group = kpi_do_sla_policy_group();
    $map = [
        'CRM' => 'SLA_CRM_MINUTES',
        'WQS' => 'SLA_WQS_MINUTES',
        'SCM' => 'SLA_SCM_MINUTES',
        'ACT' => 'SLA_ACT_MINUTES',
        'FIN' => 'SLA_FIN_MINUTES',
    ];
    $desc = [
        'SLA_CRM_MINUTES' => 'KPI DO SLA CRM (menit) — kebijakan global, diatur SYS.',
        'SLA_WQS_MINUTES' => 'KPI DO SLA WQS (menit) — kebijakan global, diatur SYS.',
        'SLA_SCM_MINUTES' => 'KPI DO SLA SCM (menit) — kebijakan global, diatur SYS.',
        'SLA_ACT_MINUTES' => 'KPI DO SLA ACT (menit) — kebijakan global, diatur SYS.',
        'SLA_FIN_MINUTES' => 'KPI DO SLA FIN (menit) — kebijakan global, diatur SYS.',
    ];
    $sql = "INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
            VALUES (:g, :k, :v, NULL, :d, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              config_value = VALUES(config_value),
              config_group = VALUES(config_group),
              description = VALUES(description),
              is_active = 1,
              updated_at = NOW()";
    $st = $pdo->prepare($sql);
    foreach ($map as $dep => $key) {
        $raw = $slaMinutes[$dep] ?? null;
        $min = is_numeric($raw) ? (int)$raw : 0;
        $min = kpi_do_sla_clamp_minutes($min);
        $st->execute([
            ':g' => $group,
            ':k' => $key,
            ':v' => (string)$min,
            ':d' => $desc[$key],
        ]);
    }
}


// -------------------------------------------------------------------------
// KPI PURCHASES / PQP SLA
// START = wqs_pr.submitted_at
// STOP  = purchases_po.created_at
// Terpisah dari KPI_DO_SLA agar alur CRM→WQS→SCM→ACT→FIN tidak berubah.
// -------------------------------------------------------------------------
function kpi_purchases_policy_group(): string {
    return 'KPI_PURCHASES';
}

function kpi_purchases_policy_load(PDO $pdo, ?string $office_code = null): array {
    $target = kpi_policy_get($pdo, 'KPI_PURCHASES', 'PQP_PR_TO_PO_SLA_MINUTES', $office_code, null);
    if ($target === null || $target === '' || !is_numeric($target)) {
        $target = kpi_policy_get($pdo, 'KPI_PURCHASES', 'PQP_PR_TO_PO_SLA_MINUTES', null, 1440);
    }
    $target = max(1, min(525600, (int)$target));
    return [
        'pqp_pr_to_po_sla_minutes' => $target,
    ];
}

function kpi_purchases_policy_save(PDO $pdo, int $minutes, ?string $office_code = null): void {
    if (!kpi_policy_table_exists($pdo, 'system_config')) {
        throw new RuntimeException('system_config tidak tersedia');
    }
    $minutes = max(1, min(525600, $minutes));
    $sql = "INSERT INTO system_config
              (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
            VALUES
              ('KPI_PURCHASES','PQP_PR_TO_PO_SLA_MINUTES',:v,:o,
               'SLA PQP dari WQS submit PR sampai PO dibuat (menit).',1,NOW(),NOW())
            ON DUPLICATE KEY UPDATE
              config_value=VALUES(config_value),
              description=VALUES(description),
              is_active=1,
              updated_at=NOW()";
    $st = $pdo->prepare($sql);
    $st->execute([':v'=>(string)$minutes, ':o'=>$office_code ?: null]);
}

function kpi_purchases_score(array $metrics, array $policy): array {
    $available = (bool)($metrics['pqp_sla_available'] ?? false);
    $completed = (int)($metrics['pqp_sla_completed_count'] ?? 0);
    if (!$available) {
        return ['score'=>null, 'coverage_pct'=>0.0, 'excluded'=>['PQP SLA source']];
    }
    if ($completed <= 0) {
        return ['score'=>null, 'coverage_pct'=>100.0, 'excluded'=>['Belum ada PR→PO selesai pada periode']];
    }
    $score = max(0.0, min(100.0, (float)($metrics['pqp_sla_ontime_pct'] ?? 0.0)));
    return ['score'=>$score, 'coverage_pct'=>100.0, 'excluded'=>[]];
}
