<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools_state_lib.php';
require_once __DIR__ . '/../../tools_ui_helpers.php';
require_once __DIR__ . '/../../tools_access_helpers.php';
require_once __DIR__ . '/email_notifier.php';

if (!function_exists('al_root')) {
    function al_root(): string
    {
        return ts_root();
    }
}

if (!function_exists('al_mask')) {
    function al_mask(string $v): string
    {
        return ts_mask(tools_mask_sensitive($v));
    }
}

if (!function_exists('al_mask_note')) {
    function al_mask_note(string $note): string
    {
        return trim($note) === '' ? '' : '[MASKED_NOTE]';
    }
}

if (!function_exists('al_iso_now')) {
    function al_iso_now(?int $ts = null): string
    {
        return date(DateTimeInterface::ATOM, $ts ?? time());
    }
}

if (!function_exists('al_parse_iso')) {
    function al_parse_iso(string $v): int
    {
        $t = strtotime($v);
        return $t === false ? time() : $t;
    }
}

if (!function_exists('al_policy_path')) {
    function al_policy_path(): string
    {
        return al_root() . '/docs/governance/ALERTING_POLICY.yaml';
    }
}

if (!function_exists('al_workflow_path')) {
    function al_workflow_path(): string
    {
        return al_root() . '/storage/state/alerts_workflow_state.json';
    }
}

if (!function_exists('al_alerts_last_path')) {
    function al_alerts_last_path(): string
    {
        return al_root() . '/storage/state/alerts_last.json';
    }
}

if (!function_exists('al_ops_banner_path')) {
    function al_ops_banner_path(): string
    {
        return al_root() . '/storage/state/ops_banner.json';
    }
}

if (!function_exists('al_alert_engine_log_path')) {
    function al_alert_engine_log_path(): string
    {
        return al_root() . '/storage/logs/alert_engine.log';
    }
}

if (!function_exists('al_alert_audit_path')) {
    function al_alert_audit_path(): string
    {
        return al_root() . '/storage/logs/audit_alerts.jsonl';
    }
}

if (!function_exists('al_alert_lock_path')) {
    function al_alert_lock_path(): string
    {
        return al_root() . '/storage/locks/alert_engine.lock';
    }
}

if (!function_exists('al_default_policy')) {
    function al_default_policy(): array
    {
        return [
            'state_version' => 1,
            'sla_defaults' => [
                'CRITICAL' => ['ack_minutes' => 30, 'resolve_minutes' => 240],
                'ATTENTION' => ['ack_minutes' => 240, 'resolve_minutes' => 1440],
            ],
            'owners' => [
                'default_owner' => 'TBD',
                'default_secondary' => 'TBD',
                'rules' => [],
            ],
            'escalation' => [
                'enabled' => true,
                'banner_add_sla_breach_badge' => true,
                'email_on_sla_breach' => false,
                'create_manual_action_queue' => false,
                'max_escalation_levels' => 3,
                'include_owner_in_non_admin_banner' => true,
            ],
            'workflow' => [
                'allow_ack' => true,
                'allow_status_update' => true,
                'allow_owner_override' => false,
                'statuses' => ['OPEN', 'ACKNOWLEDGED', 'IN_PROGRESS', 'RESOLVED', 'SUPPRESSED'],
                'suppress_rules' => [
                    'enabled' => false,
                    'note' => 'Do not suppress CRITICAL in production without RFC',
                ],
            ],
        ];
    }
}

if (!function_exists('al_merge_policy_defaults')) {
    function al_merge_policy_defaults(array $policy): array
    {
        $def = al_default_policy();
        $policy = array_replace_recursive($def, $policy);
        $policy['owners']['default_owner'] = trim((string)($policy['owners']['default_owner'] ?? 'TBD')) ?: 'TBD';
        $policy['owners']['default_secondary'] = trim((string)($policy['owners']['default_secondary'] ?? 'TBD')) ?: 'TBD';
        return $policy;
    }
}

if (!function_exists('al_validate_policy')) {
    function al_validate_policy(array $policy): array
    {
        $errors = [];
        foreach (['CRITICAL', 'ATTENTION'] as $sev) {
            $ack = (int)($policy['sla_defaults'][$sev]['ack_minutes'] ?? 0);
            $res = (int)($policy['sla_defaults'][$sev]['resolve_minutes'] ?? 0);
            if ($ack <= 0 || $res <= 0) $errors[] = 'ERR_POLICY_SLA_' . $sev;
        }
        $statuses = array_map('strtoupper', (array)($policy['workflow']['statuses'] ?? []));
        foreach (['OPEN', 'ACKNOWLEDGED', 'IN_PROGRESS', 'RESOLVED', 'SUPPRESSED'] as $required) {
            if (!in_array($required, $statuses, true)) $errors[] = 'ERR_POLICY_WORKFLOW_STATUS_' . $required;
        }
        $mx = (int)($policy['escalation']['max_escalation_levels'] ?? 0);
        if ($mx <= 0) $errors[] = 'ERR_POLICY_ESCALATION_MAX_LEVELS';
        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors))];
    }
}

if (!function_exists('al_load_policy')) {
    function al_load_policy(bool $strict = true): array
    {
        $path = al_policy_path();
        if (!is_file($path)) {
            $d = al_default_policy();
            $v = al_validate_policy($d);
            return [
                'ok' => !$strict,
                'strict' => $strict,
                'policy' => $d,
                'errors' => $strict ? ['ERR_POLICY_MISSING'] : [],
                'warnings' => ['WARN_POLICY_MISSING_DEFAULT_APPLIED'],
                'path_masked' => al_mask($path),
            ];
        }
        $raw = (string)@file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) && function_exists('yaml_parse')) {
            $parsed = @yaml_parse($raw);
            if (is_array($parsed)) $decoded = $parsed;
        }
        if (!is_array($decoded)) {
            $d = al_default_policy();
            return [
                'ok' => !$strict,
                'strict' => $strict,
                'policy' => $d,
                'errors' => $strict ? ['ERR_POLICY_INVALID'] : [],
                'warnings' => ['WARN_POLICY_INVALID_DEFAULT_APPLIED'],
                'path_masked' => al_mask($path),
            ];
        }
        $merged = al_merge_policy_defaults($decoded);
        $valid = al_validate_policy($merged);
        return [
            'ok' => $valid['ok'] || !$strict,
            'strict' => $strict,
            'policy' => $merged,
            'errors' => $valid['ok'] ? [] : $valid['errors'],
            'warnings' => [],
            'path_masked' => al_mask($path),
        ];
    }
}

if (!function_exists('al_rule_severity')) {
    function al_rule_severity(string $ruleKey, array $ctx): string
    {
        return match ($ruleKey) {
            'contract_fail', 'smoke_http_fail', 'core_flows_fail', 'release_verify_fail' => 'CRITICAL',
            default => 'ATTENTION',
        };
    }
}

if (!function_exists('al_eval_triggered_rules')) {
    function al_eval_triggered_rules(array $ctx): array
    {
        $rules = [];

        $contract = tools_read_state_json(ts_storage_logs_dir() . '/contract_check_last.json', ['ok']);
        if (!$contract['ok'] || !((bool)($contract['data']['ok'] ?? false))) {
            $rules['contract_fail'] = ['triggered' => true, 'reason' => 'contract not ok'];
        }

        $smoke = tools_read_state_json(ts_storage_logs_dir() . '/smoke_http_last.json');
        $smokeFail = (int)($smoke['data']['fail'] ?? $smoke['data']['summary']['fail'] ?? 0);
        if (!$smoke['ok'] || $smokeFail > 0) {
            $rules['smoke_http_fail'] = ['triggered' => true, 'reason' => 'smoke fail > 0 or missing'];
        }

        $coreFlows = tools_read_state_json(ts_storage_logs_dir() . '/smoke_core_flows_last.json');
        $coreOk = (bool)($coreFlows['data']['ok'] ?? false);
        if (!$coreFlows['ok'] || !$coreOk) {
            $rules['core_flows_fail'] = ['triggered' => true, 'reason' => 'core flows failed or missing'];
        }

        $findings = tools_read_state_json(ts_root() . '/storage/state/ops_findings.json');
        $p0 = 0;
        foreach ((array)($findings['data']['findings'] ?? []) as $f) {
            $sev = strtoupper((string)($f['severity'] ?? ''));
            $status = strtoupper((string)($f['status'] ?? 'OPEN'));
            if ($sev === 'P0' && in_array($status, ['OPEN', 'IN_PROGRESS'], true)) $p0++;
        }
        if ($p0 > 0) {
            $rules['backlog_p0_present'] = ['triggered' => true, 'reason' => 'open p0 findings present'];
        }

        $rv = tools_read_state_json(ts_root() . '/storage/state/release_verify_all_last.json');
        $rvFail = (int)($rv['data']['summary']['bundle_fail'] ?? 0);
        $rvOk = (bool)($rv['data']['overall_ok'] ?? false);
        if ($rvFail > 0 || !$rvOk) {
            $rules['release_verify_fail'] = ['triggered' => true, 'reason' => 'release verify fail'];
        }

        $backup = ts_latest_backup_meta();
        $age = (float)($backup['age_hours'] ?? 9999);
        if ($age > 24.0) {
            $rules['backup_age'] = ['triggered' => true, 'reason' => 'backup age > 24h'];
        }

        return $rules;
    }
}

if (!function_exists('al_get_rule_owners')) {
    function al_get_rule_owners(string $ruleKey, array $policy, array $existingRuleState = []): array
    {
        $defaultPrimary = (string)($policy['owners']['default_owner'] ?? 'TBD');
        $defaultSecondary = (string)($policy['owners']['default_secondary'] ?? 'TBD');
        $rule = (array)($policy['owners']['rules'][$ruleKey] ?? []);
        $cur = (array)($existingRuleState['owners'] ?? []);
        return [
            'primary' => (string)($cur['primary'] ?? $rule['primary'] ?? $defaultPrimary),
            'secondary' => (string)($cur['secondary'] ?? $rule['secondary'] ?? $defaultSecondary),
            'escalation' => array_values(array_map('strval', (array)($cur['escalation'] ?? $rule['escalation'] ?? []))),
        ];
    }
}

if (!function_exists('al_atomic_write_json')) {
    function al_atomic_write_json(string $path, array $payload): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
        $tmp = $path . '.tmp.' . getmypid() . '.' . substr(sha1((string)microtime(true)), 0, 8);
        $ok = @file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false;
        if (!$ok) return false;
        return @rename($tmp, $path);
    }
}

if (!function_exists('al_load_workflow_state')) {
    function al_load_workflow_state(string $env): array
    {
        $path = al_workflow_path();
        if (!is_file($path)) {
            return [
                'ok' => true,
                'corrupt' => false,
                'data' => ['state_version' => 1, 'updated_at' => al_iso_now(), 'env' => $env, 'rules' => []],
                'errors' => [],
            ];
        }
        $raw = (string)@file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'corrupt' => true,
                'data' => ['state_version' => 1, 'updated_at' => al_iso_now(), 'env' => $env, 'rules' => []],
                'errors' => ['WORKFLOW_STATE_CORRUPT'],
            ];
        }
        if (!isset($decoded['rules']) || !is_array($decoded['rules'])) $decoded['rules'] = [];
        return ['ok' => true, 'corrupt' => false, 'data' => $decoded, 'errors' => []];
    }
}

if (!function_exists('al_build_sla')) {
    function al_build_sla(string $severity, int $openedTs, array $policy): array
    {
        $sev = strtoupper($severity);
        $ackMin = (int)($policy['sla_defaults'][$sev]['ack_minutes'] ?? $policy['sla_defaults']['ATTENTION']['ack_minutes'] ?? 240);
        $resMin = (int)($policy['sla_defaults'][$sev]['resolve_minutes'] ?? $policy['sla_defaults']['ATTENTION']['resolve_minutes'] ?? 1440);
        return [
            'ack_due_at' => al_iso_now($openedTs + ($ackMin * 60)),
            'resolve_due_at' => al_iso_now($openedTs + ($resMin * 60)),
            'breached' => false,
        ];
    }
}

if (!function_exists('al_detect_breach')) {
    function al_detect_breach(array $ruleState, int $nowTs): bool
    {
        $st = strtoupper((string)($ruleState['status'] ?? 'OPEN'));
        $ackDue = al_parse_iso((string)($ruleState['sla']['ack_due_at'] ?? ''));
        $resolveDue = al_parse_iso((string)($ruleState['sla']['resolve_due_at'] ?? ''));
        if ($st === 'OPEN' && $nowTs > $ackDue) return true;
        if (in_array($st, ['OPEN', 'ACKNOWLEDGED', 'IN_PROGRESS'], true) && $nowTs > $resolveDue) return true;
        return false;
    }
}

if (!function_exists('al_append_audit')) {
    function al_append_audit(array $entry): void
    {
        $payload = [
            'ts' => al_iso_now(),
            'actor_username' => (string)($entry['actor_username'] ?? tools_current_actor_username()),
            'request_id' => (string)($entry['request_id'] ?? ('req-' . date('YmdHis'))),
            'action' => (string)($entry['action'] ?? 'ALERT_EVENT'),
            'env' => (string)($entry['env'] ?? 'staging'),
            'rule_key' => (string)($entry['rule_key'] ?? ''),
            'new_status' => (string)($entry['new_status'] ?? ''),
            'note_masked' => al_mask_note((string)($entry['note_masked'] ?? '')),
            'owners' => (array)($entry['owners'] ?? []),
            'result' => (string)($entry['result'] ?? 'OK'),
        ];
        @file_put_contents(al_alert_audit_path(), json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }
}

if (!function_exists('al_apply_workflow_action')) {
    function al_apply_workflow_action(string $ruleKey, string $action, string $actor, string $env, array $policy, string $note = '', array $ownerOverride = []): array
    {
        $wf = al_load_workflow_state($env);
        $data = (array)$wf['data'];
        $rules = (array)($data['rules'] ?? []);
        $cur = (array)($rules[$ruleKey] ?? []);
        $now = al_iso_now();
        $result = ['ok' => false, 'error' => '', 'data' => $data];
        if ($cur === []) {
            $result['error'] = 'ERR_RULE_NOT_FOUND';
            return $result;
        }
        $action = strtoupper(trim($action));
        if ($action === 'ACKNOWLEDGE') {
            if (mb_strlen(trim($note)) < 10) {
                $result['error'] = 'ERR_NOTE_REQUIRED';
                return $result;
            }
            $cur['status'] = 'ACKNOWLEDGED';
            $cur['ack'] = ['acknowledged_at' => $now, 'acknowledged_by' => $actor, 'note' => al_mask_note($note)];
            al_append_audit(['action' => 'ALERT_ACKNOWLEDGED', 'actor_username' => $actor, 'env' => $env, 'rule_key' => $ruleKey, 'new_status' => 'ACKNOWLEDGED', 'note_masked' => $note, 'result' => 'OK']);
        } elseif ($action === 'IN_PROGRESS') {
            $cur['status'] = 'IN_PROGRESS';
            $cur['in_progress'] = ['set_at' => $now, 'set_by' => $actor];
            al_append_audit(['action' => 'ALERT_STATUS_SET', 'actor_username' => $actor, 'env' => $env, 'rule_key' => $ruleKey, 'new_status' => 'IN_PROGRESS', 'result' => 'OK']);
        } elseif ($action === 'RESOLVE_MANUAL') {
            if (mb_strlen(trim($note)) < 10) {
                $result['error'] = 'ERR_NOTE_REQUIRED';
                return $result;
            }
            $cur['status'] = 'RESOLVED';
            $cur['resolved'] = ['resolved_at' => $now, 'resolved_by' => $actor, 'resolution_type' => 'MANUAL'];
            al_append_audit(['action' => 'ALERT_RESOLVED_MANUAL', 'actor_username' => $actor, 'env' => $env, 'rule_key' => $ruleKey, 'new_status' => 'RESOLVED', 'note_masked' => $note, 'result' => 'OK']);
        } elseif ($action === 'ASSIGN_OWNER') {
            $enabled = (bool)($policy['workflow']['allow_owner_override'] ?? false);
            if (!$enabled) {
                $result['error'] = 'ERR_OWNER_OVERRIDE_DISABLED';
                return $result;
            }
            if (mb_strlen(trim($note)) < 10) {
                $result['error'] = 'ERR_NOTE_REQUIRED';
                return $result;
            }
            $cur['owners']['primary'] = trim((string)($ownerOverride['primary'] ?? $cur['owners']['primary'] ?? 'TBD')) ?: 'TBD';
            $cur['owners']['secondary'] = trim((string)($ownerOverride['secondary'] ?? $cur['owners']['secondary'] ?? 'TBD')) ?: 'TBD';
            al_append_audit(['action' => 'ALERT_OWNER_OVERRIDE', 'actor_username' => $actor, 'env' => $env, 'rule_key' => $ruleKey, 'owners' => $cur['owners'], 'note_masked' => $note, 'result' => 'OK']);
        } else {
            $result['error'] = 'ERR_ACTION_INVALID';
            return $result;
        }
        $cur['last_seen_at'] = $now;
        $rules[$ruleKey] = $cur;
        $data['rules'] = $rules;
        $data['updated_at'] = $now;
        $result['ok'] = al_atomic_write_json(al_workflow_path(), $data);
        $result['data'] = $data;
        if (!$result['ok']) $result['error'] = 'ERR_WORKFLOW_WRITE_FAILED';
        return $result;
    }
}

if (!function_exists('al_pick_top_rule_for_banner')) {
    function al_pick_top_rule_for_banner(array $rulesState, array $triggeredKeys): array
    {
        $rank = ['CRITICAL' => 0, 'ATTENTION' => 1];
        $best = null;
        foreach ($triggeredKeys as $key) {
            $r = (array)($rulesState[$key] ?? []);
            if ($r === []) continue;
            $sev = strtoupper((string)($r['severity'] ?? 'ATTENTION'));
            $sc = $rank[$sev] ?? 9;
            if ($best === null || $sc < (int)$best['score']) {
                $best = ['score' => $sc, 'key' => $key, 'row' => $r];
            }
        }
        return $best ?? ['score' => 9, 'key' => '', 'row' => []];
    }
}

if (!function_exists('al_write_log_line')) {
    function al_write_log_line(array $line): void
    {
        @file_put_contents(al_alert_engine_log_path(), json_encode($line, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }
}

if (!function_exists('al_create_manual_action_queue_item')) {
    function al_create_manual_action_queue_item(string $ruleKey, array $ruleState): void
    {
        $enabled = is_file(al_root() . '/tools/ops/manual_action_queue.php');
        if (!$enabled) return;
        $path = al_root() . '/storage/logs/manual_action_queue_last.json';
        $state = ts_read_json($path);
        $rows = (array)($state['rows'] ?? []);
        $id = 'ALERT-' . strtoupper($ruleKey);
        foreach ($rows as $r) {
            if ((string)($r['id'] ?? '') === $id) return;
        }
        $rows[] = [
            'id' => $id,
            'created_at' => al_iso_now(),
            'severity' => (string)($ruleState['severity'] ?? 'ATTENTION'),
            'title' => 'SLA breach ' . $ruleKey,
            'owner' => (string)($ruleState['owners']['primary'] ?? 'OPS'),
            'suggested_action' => 'Investigate SLA breach and update workflow status.',
            'status' => 'OPEN',
            'pic' => '',
        ];
        $state['state_version'] = 1;
        $state['generated_at'] = al_iso_now();
        $state['rows'] = $rows;
        al_atomic_write_json($path, $state);
    }
}

