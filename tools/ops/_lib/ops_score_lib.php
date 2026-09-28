<?php
declare(strict_types=1);

require_once __DIR__ . '/ops_helpers.php';
require_once __DIR__ . '/ops_thresholds_lib.php';

if (!function_exists('ops_score_mask')) {
    function ops_score_mask(string $value): string
    {
        return ops_mask($value);
    }
}

if (!function_exists('ops_score_read_json')) {
    function ops_score_read_json(string $path): array
    {
        $res = [
            'ok' => false,
            'error' => 'missing',
            'error_masked' => '',
            'path_masked' => ops_score_mask($path),
            'data' => [],
        ];
        if (!is_file($path)) {
            return $res;
        }
        $raw = (string)@file_get_contents($path);
        if (trim($raw) === '') {
            $res['error'] = 'empty';
            return $res;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                $res['error'] = 'schema_mismatch';
                return $res;
            }
            $res['ok'] = true;
            $res['error'] = '';
            $res['data'] = $decoded;
            return $res;
        } catch (Throwable $e) {
            $res['error'] = 'invalid_json';
            $res['error_masked'] = ops_score_mask($e->getMessage());
            return $res;
        }
    }
}

if (!function_exists('ops_score_to_bool_or_null')) {
    function ops_score_to_bool_or_null(mixed $value): ?bool
    {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return ((int)$value) !== 0;
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['true', '1', 'yes', 'on'], true)) return true;
            if (in_array($v, ['false', '0', 'no', 'off'], true)) return false;
        }
        return null;
    }
}

if (!function_exists('ops_score_int_or_null')) {
    function ops_score_int_or_null(mixed $value): ?int
    {
        if (is_int($value)) return $value;
        if (is_numeric($value)) return (int)$value;
        return null;
    }
}

if (!function_exists('ops_score_load_inputs')) {
    function ops_score_load_inputs(string $env): array
    {
        $root = ops_root();
        $inputs = [
            'readiness' => ops_score_read_json($root . '/storage/logs/readiness_report_last.json'),
            'smoke_http' => ops_score_read_json($root . '/storage/logs/smoke_http_last.json'),
            'contract_check' => ops_score_read_json($root . '/storage/logs/contract_check_last.json'),
            'smoke_core_flows' => ops_score_read_json($root . '/storage/logs/smoke_core_flows_last.json'),
            'fix_backlog' => ops_score_read_json($root . '/storage/logs/pipeline/fix_backlog_last.json'),
            'sla_monitor' => ops_score_read_json($root . '/storage/logs/sla_monitor_last.json'),
            'pipeline_last' => ops_score_read_json($root . '/storage/logs/pipeline/pipeline_last.json'),
            'backup_last' => ops_score_read_json($root . '/storage/state/backup_last.json'),
        ];

        $missingReasons = [];
        foreach (['readiness', 'smoke_http', 'contract_check', 'smoke_core_flows', 'fix_backlog'] as $k) {
            if (!$inputs[$k]['ok']) {
                $missingReasons[] = 'DATA_MISSING:' . $k;
            }
        }

        $readinessScore = $inputs['readiness']['ok']
            ? ops_score_int_or_null($inputs['readiness']['data']['score'] ?? null)
            : null;
        $contractOk = $inputs['contract_check']['ok']
            ? ops_score_to_bool_or_null($inputs['contract_check']['data']['ok'] ?? null)
            : null;
        $smokeHttpFail = $inputs['smoke_http']['ok']
            ? (ops_score_int_or_null($inputs['smoke_http']['data']['fail'] ?? $inputs['smoke_http']['data']['summary']['fail'] ?? null) ?? 0)
            : null;
        $smokeHttpWarn = $inputs['smoke_http']['ok']
            ? (ops_score_int_or_null($inputs['smoke_http']['data']['warn'] ?? $inputs['smoke_http']['data']['warning_count'] ?? null) ?? 0)
            : null;
        $coreFlowsOk = $inputs['smoke_core_flows']['ok']
            ? ops_score_to_bool_or_null($inputs['smoke_core_flows']['data']['ok'] ?? $inputs['smoke_core_flows']['data']['overall_ok'] ?? null)
            : null;
        $backlogP0 = $inputs['fix_backlog']['ok']
            ? (ops_score_int_or_null($inputs['fix_backlog']['data']['summary']['p0'] ?? null) ?? 0)
            : null;
        $backlogP1 = $inputs['fix_backlog']['ok']
            ? (ops_score_int_or_null($inputs['fix_backlog']['data']['summary']['p1'] ?? null) ?? 0)
            : null;
        $backlogP2 = $inputs['fix_backlog']['ok']
            ? (ops_score_int_or_null($inputs['fix_backlog']['data']['summary']['p2'] ?? null) ?? 0)
            : null;
        $slaBreach = $inputs['sla_monitor']['ok']
            ? (ops_score_to_bool_or_null($inputs['sla_monitor']['data']['sla']['breach'] ?? null) ?? false)
            : null;

        $backupAgeHours = null;
        if ($inputs['backup_last']['ok']) {
            $backupAgeHours = ops_score_int_or_null($inputs['backup_last']['data']['backup_age_hours'] ?? null);
        }
        if ($backupAgeHours === null) {
            $meta = ts_latest_backup_meta();
            if (is_array($meta) && array_key_exists('age_hours', $meta) && $meta['age_hours'] !== null && $meta['age_hours'] !== '') {
                $backupAgeHours = (int)round((float)$meta['age_hours']);
            }
        }

        $runId = '';
        if ($inputs['pipeline_last']['ok']) {
            $runId = (string)($inputs['pipeline_last']['data']['run_id'] ?? '');
        }
        if ($runId === '') {
            $runId = 'opsscore-' . $env . '-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
        }

        $policyLoaded = load_ops_thresholds('active-first', false);

        return [
            'inputs' => $inputs,
            'run_id' => $runId,
            'policy' => [
                'ok' => (bool)$policyLoaded['ok'],
                'source' => (string)($policyLoaded['source'] ?? 'defaults'),
                'fingerprint' => (string)($policyLoaded['fingerprint'] ?? ''),
                'policy_id' => (string)(($policyLoaded['policy']['policy_id'] ?? '')),
                'updated_at' => (string)(($policyLoaded['policy']['updated_at'] ?? '')),
                'updated_by' => (string)(($policyLoaded['policy']['updated_by'] ?? '')),
                'errors' => (array)($policyLoaded['errors'] ?? []),
                'notes' => (array)($policyLoaded['notes'] ?? []),
                'policy_data' => (array)($policyLoaded['policy'] ?? []),
            ],
            'metrics' => [
                'readiness_score' => $readinessScore,
                'contract_ok' => $contractOk,
                'smoke_http_fail' => $smokeHttpFail,
                'smoke_http_warn' => $smokeHttpWarn,
                'core_flows_ok' => $coreFlowsOk,
                'backlog_p0' => $backlogP0,
                'backlog_p1' => $backlogP1,
                'backlog_p2' => $backlogP2,
                'backup_age_hours' => $backupAgeHours,
                'sla_breach' => $slaBreach,
                'data_missing_count' => count($missingReasons),
            ],
            'missing_reasons' => $missingReasons,
        ];
    }
}

if (!function_exists('ops_score_compute')) {
    function ops_score_compute(array $metrics, string $env, ?array $policy = null): array
    {
        if ($policy === null || $policy === []) {
            $loaded = load_ops_thresholds('active-first', false);
            $policy = (array)($loaded['policy'] ?? ops_thresholds_defaults());
        }
        $reasons = [];
        $notes = [];
        $criticalReason = '';
        $readinessMissing = ($metrics['readiness_score'] === null);
        $contractOk = $metrics['contract_ok'];
        $smokeFail = $metrics['smoke_http_fail'];
        $coreFlowsOk = $metrics['core_flows_ok'];

        if ($contractOk === false) {
            $criticalReason = 'CONTRACT_FAIL';
        } elseif (($smokeFail !== null) && ((int)$smokeFail > 0)) {
            $criticalReason = 'HTTP_SMOKE_FAIL';
        } elseif ($coreFlowsOk === false) {
            $criticalReason = 'CORE_FLOWS_FAIL';
        }

        if ($criticalReason !== '') {
            $score = match ($criticalReason) {
                'CONTRACT_FAIL' => (int)($policy['ops_score']['critical_blockers']['contract_fail_score'] ?? 0),
                'HTTP_SMOKE_FAIL' => (int)($policy['ops_score']['critical_blockers']['smoke_http_fail_score'] ?? 0),
                'CORE_FLOWS_FAIL' => (int)($policy['ops_score']['critical_blockers']['core_flows_fail_score'] ?? 0),
                default => 0,
            };
            $status = 'CRITICAL';
            $reasons[] = $criticalReason;
        } else {
            $baseSource = (string)($policy['ops_score']['base_score_source'] ?? 'readiness');
            $fixedBase = (int)($policy['ops_score']['fixed_base_score'] ?? 100);
            $baseScore = $baseSource === 'fixed_100' ? $fixedBase : 100;
            if ($baseSource === 'readiness') {
                if ($metrics['readiness_score'] !== null) {
                    $baseScore = (int)$metrics['readiness_score'];
                } else {
                    $baseScore = 100;
                    $notes[] = 'READINESS_MISSING_ASSUMED_100';
                }
            }

            $p0Per = (int)($policy['ops_score']['penalties']['backlog_p0']['per_item'] ?? 10);
            $p0Cap = (int)($policy['ops_score']['penalties']['backlog_p0']['cap'] ?? 30);
            $p1Per = (int)($policy['ops_score']['penalties']['backlog_p1']['per_item'] ?? 1);
            $p1Cap = (int)($policy['ops_score']['penalties']['backlog_p1']['cap'] ?? 10);
            $warnPer = (int)($policy['ops_score']['penalties']['smoke_http_warn']['per_item'] ?? 1);
            $warnCap = (int)($policy['ops_score']['penalties']['smoke_http_warn']['cap'] ?? 10);
            $backlogP0Penalty = min($p0Cap, max(0, (int)($metrics['backlog_p0'] ?? 0) * $p0Per));
            $backlogP1Penalty = min($p1Cap, max(0, (int)($metrics['backlog_p1'] ?? 0) * $p1Per));
            $warnPenalty = min($warnCap, max(0, (int)($metrics['smoke_http_warn'] ?? 0) * $warnPer));

            $backupPenalty = 0;
            $backupAge = $metrics['backup_age_hours'];
            if ($backupAge !== null) {
                $cfg = ($env === 'production')
                    ? (array)($policy['ops_score']['penalties']['backup_age']['production'] ?? [])
                    : (array)($policy['ops_score']['penalties']['backup_age']['staging'] ?? []);
                $warnHours = (int)($cfg['warn_hours'] ?? 24);
                $warnPenaltyVal = (int)($cfg['warn_penalty'] ?? ($env === 'production' ? 10 : 5));
                $criticalHours = (int)($cfg['critical_hours'] ?? 48);
                $criticalPenaltyVal = (int)($cfg['critical_penalty'] ?? ($env === 'production' ? 20 : 10));
                if ((int)$backupAge > $criticalHours) $backupPenalty = $criticalPenaltyVal;
                elseif ((int)$backupAge > $warnHours) $backupPenalty = $warnPenaltyVal;
            }

            $slaPenaltyVal = (int)($policy['ops_score']['penalties']['sla_breach_penalty'] ?? 10);
            $slaPenalty = (($metrics['sla_breach'] ?? false) === true) ? $slaPenaltyVal : 0;
            $penaltyTotal = $backlogP0Penalty + $backlogP1Penalty + $warnPenalty + $backupPenalty + $slaPenalty;
            $score = max(0, min(100, $baseScore - $penaltyTotal));

            $healthyMin = (int)($policy['status_thresholds']['healthy_min'] ?? 90);
            $attentionMin = (int)($policy['status_thresholds']['attention_min'] ?? 70);
            if ($score >= $healthyMin) $status = 'HEALTHY';
            elseif ($score >= $attentionMin) $status = 'ATTENTION';
            else $status = 'CRITICAL';
            if ($readinessMissing && $status === 'HEALTHY') {
                $status = 'ATTENTION';
            }
        }

        $requireReadiness100 = (bool)($policy['go_no_go']['require_readiness_score_100'] ?? true);
        $requireP0Zero = (bool)($policy['go_no_go']['require_backlog_p0_zero'] ?? true);
        $requireContractOk = (bool)($policy['go_no_go']['require_contract_ok'] ?? true);
        $requireSmokeZero = (bool)($policy['go_no_go']['require_smoke_http_fail_zero'] ?? true);
        $requireCoreFlows = (bool)($policy['go_no_go']['require_core_flows_ok'] ?? true);
        $prodBackupMax = (int)($policy['go_no_go']['production_requires_backup_age_hours_max'] ?? 24);
        $goNoGo = 'GO';
        $goFail =
            ($requireContractOk && $contractOk !== true)
            || ($requireSmokeZero && ($smokeFail !== null && $smokeFail > 0))
            || ($requireCoreFlows && $coreFlowsOk !== true)
            || ($requireReadiness100 && (($metrics['readiness_score'] ?? null) !== 100))
            || ($requireP0Zero && (((int)($metrics['backlog_p0'] ?? 0)) > 0))
            || ($env === 'production' && (($metrics['backup_age_hours'] ?? null) !== null) && ((int)$metrics['backup_age_hours'] > $prodBackupMax));
        if ($goFail) {
            $goNoGo = 'NO-GO';
            if ($requireContractOk && $contractOk === false) $reasons[] = 'CONTRACT_FAIL';
            if ($requireSmokeZero && (($smokeFail !== null) && ($smokeFail > 0))) $reasons[] = 'HTTP_SMOKE_FAIL';
            if ($requireCoreFlows && $coreFlowsOk === false) $reasons[] = 'CORE_FLOWS_FAIL';
            if ($requireReadiness100 && (($metrics['readiness_score'] ?? null) !== 100)) $reasons[] = 'READINESS_SCORE_NOT_100';
            if ($requireP0Zero && (((int)($metrics['backlog_p0'] ?? 0)) > 0)) $reasons[] = 'BACKLOG_P0_GT_0';
            if ($env === 'production' && (($metrics['backup_age_hours'] ?? null) !== null) && ((int)$metrics['backup_age_hours'] > $prodBackupMax)) $reasons[] = 'BACKUP_TOO_OLD';
        }
        if (($metrics['data_missing_count'] ?? 0) > 0 && $goNoGo === 'GO') {
            $goNoGo = 'UNKNOWN';
            $reasons[] = 'DATA_MISSING';
        }

        return [
            'ops_score' => (int)$score,
            'status' => (string)$status,
            'go_no_go' => $goNoGo,
            'reasons' => array_values(array_unique($reasons)),
            'notes' => $notes,
            'policy' => [
                'policy_id' => (string)($policy['policy_id'] ?? ''),
                'updated_at' => (string)($policy['updated_at'] ?? ''),
                'updated_by' => (string)($policy['updated_by'] ?? ''),
                'fingerprint' => ops_thresholds_fingerprint($policy),
            ],
        ];
    }
}

