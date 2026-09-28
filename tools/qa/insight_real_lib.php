<?php
/**
 * INSIGHT REAL — shared logic (CLI + SYS web).
 * Read-only queries; writes only optional audit row INSIGHT_REAL_RUN.
 */
declare(strict_types=1);

if (!function_exists('irlib_root')) {
    function irlib_root(): string
    {
        return realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }
}

if (!class_exists('InsightRealQueryLog')) {
    final class InsightRealQueryLog
    {
        /** @var list<array{sql:string,params:array,note?:string}> */
        private array $entries = [];

        public function log(string $sql, array $params = [], string $note = ''): void
        {
            $this->entries[] = [
                'sql' => $sql,
                'params' => $params,
                'note' => $note,
            ];
        }

        /** @return list<array{sql:string,params:array,note?:string}> */
        public function all(): array
        {
            return $this->entries;
        }

        public function writeMarkdown(string $path, string $title): void
        {
            $lines = ['# ' . $title, '', 'Generated: ' . date('c'), '',];
            $n = 1;
            foreach ($this->entries as $e) {
                $lines[] = '## Q' . $n++;
                if (($e['note'] ?? '') !== '') {
                    $lines[] = '_Note: ' . $e['note'] . '_';
                }
                $lines[] = '```sql';
                $lines[] = trim($e['sql']);
                $lines[] = '```';
                $lines[] = '**Params:** `' . json_encode($e['params'], JSON_UNESCAPED_UNICODE) . '`';
                $lines[] = '';
            }
            file_put_contents($path, implode("\n", $lines));
        }
    }
}

if (!function_exists('irlib_table_exists')) {
    function irlib_table_exists(PDO $pdo, string $table, InsightRealQueryLog $log): bool
    {
        $sql = 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1';
        $log->log($sql, [$table], 'information_schema.tables');
        $st = $pdo->prepare($sql);
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    }
}

if (!function_exists('irlib_col_exists')) {
    function irlib_col_exists(PDO $pdo, string $table, string $col, InsightRealQueryLog $log): bool
    {
        $sql = 'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1';
        $log->log($sql, [$table, $col], 'information_schema.columns');
        $st = $pdo->prepare($sql);
        $st->execute([$table, $col]);
        return (bool) $st->fetchColumn();
    }
}

if (!function_exists('irlib_safe_query_all')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function irlib_safe_query_all(PDO $pdo, InsightRealQueryLog $log, string $sql, array $params, string $note): array
    {
        $log->log($sql, $params, $note);
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('irlib_safe_query_one')) {
    function irlib_safe_query_one(PDO $pdo, InsightRealQueryLog $log, string $sql, array $params, string $note): ?array
    {
        $rows = irlib_safe_query_all($pdo, $log, $sql, $params, $note);
        return $rows[0] ?? null;
    }
}

if (!function_exists('irlib_count_php_errors_by_day')) {
    /**
     * @return array<string, int> date Y-m-d => count
     */
    function irlib_count_php_errors_by_day(string $logsDir, int $daysBack, InsightRealQueryLog $log): array
    {
        $log->log('-- (file scan)', ['logsDir' => $logsDir, 'daysBack' => $daysBack], 'PHP error lines: scan storage/logs/app-*.log for [ERROR] / ERROR] / FATAL');
        $cutoff = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
        $cutoff = $cutoff->modify('-' . $daysBack . ' days');
        $out = [];
        $files = glob($logsDir . '/app-*.log') ?: [];
        foreach ($files as $file) {
            $base = basename($file);
            if (!preg_match('/^app-(\d{4}-\d{2}-\d{2})\.log$/', $base, $m)) {
                continue;
            }
            $d = $m[1];
            if ($d < $cutoff->format('Y-m-d')) {
                continue;
            }
            $c = 0;
            $fh = @fopen($file, 'rb');
            if ($fh === false) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                if (stripos($line, '[ERROR]') !== false
                    || stripos($line, 'FATAL') !== false
                    || stripos($line, 'PHP Fatal') !== false
                    || stripos($line, 'Uncaught') !== false) {
                    $c++;
                }
            }
            fclose($fh);
            $out[$d] = ($out[$d] ?? 0) + $c;
        }
        ksort($out);
        return $out;
    }
}

if (!function_exists('irlib_read_json_glob')) {
    /**
     * @return list<string> paths
     */
    function irlib_rbac_matrix_paths(string $logsDir, string $prefix): array
    {
        $paths = [];
        foreach (glob($logsDir . '/' . $prefix . '.json') ?: [] as $p) {
            $paths[] = $p;
        }
        foreach (glob($logsDir . '/' . $prefix . '.*.json') ?: [] as $p) {
            $paths[] = $p;
        }
        $paths = array_values(array_unique(array_map('strval', $paths)));
        sort($paths);
        return $paths;
    }
}

if (!function_exists('irlib_collect_rbac_mismatches')) {
    /**
     * @return array{real_leak: list<array>, url_join_bug: list<array>}
     */
    function irlib_collect_rbac_mismatches(string $logsDir, InsightRealQueryLog $log): array
    {
        $log->log('-- (json parse)', [$logsDir], 'RBAC matrices: rbac_smoke_matrix_last*.json + rbac_action_matrix_last*.json');
        $real = [];
        $u301 = [];
        $seen = [];
        foreach (['rbac_smoke_matrix_last', 'rbac_action_matrix_last'] as $prefix) {
            foreach (irlib_rbac_matrix_paths($logsDir, $prefix) as $path) {
                $raw = @file_get_contents($path);
                if ($raw === false) {
                    continue;
                }
                $j = json_decode($raw, true);
                if (!is_array($j) || !isset($j['results']) || !is_array($j['results'])) {
                    continue;
                }
                foreach ($j['results'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $epId = (string)($row['id'] ?? '');
                    $pathEp = (string)($row['path'] ?? '');
                    $method = (string)($row['method'] ?? 'GET');
                    $risk = (string)($row['risk'] ?? '');
                    $cash = !empty($row['cash_out']);
                    $label = (string)($row['label'] ?? '');
                    $roleTests = $row['role_tests'] ?? null;
                    if (!is_array($roleTests)) {
                        $roleTests = [];
                    }
                    foreach ($roleTests as $roleName => $t) {
                        if (!is_array($t)) {
                            continue;
                        }
                        $exp = (int)($t['expected'] ?? 0);
                        $act = (int)($t['actual'] ?? 0);
                        $cat = isset($t['mismatch_category']) ? (string)$t['mismatch_category'] : '';
                        if ($exp === 403 && $act === 200) {
                            $k = $epId . '|' . $roleName . '|' . $pathEp . '|' . $method;
                            if (isset($seen[$k])) {
                                continue;
                            }
                            $seen[$k] = true;
                            $real[] = [
                                'source_file' => basename($path),
                                'id' => $epId,
                                'label' => $label,
                                'path' => $pathEp,
                                'method' => $method,
                                'role' => (string)$roleName,
                                'expected' => $exp,
                                'actual' => $act,
                                'risk' => $risk,
                                'cash_out' => $cash,
                                'category' => 'REAL_LEAK',
                            ];
                        } elseif ($exp === 403 && $act === 301) {
                            $k = '301|' . $epId . '|' . $roleName . '|' . $pathEp . '|' . $method;
                            if (isset($seen[$k])) {
                                continue;
                            }
                            $seen[$k] = true;
                            $u301[] = [
                                'source_file' => basename($path),
                                'id' => $epId,
                                'label' => $label,
                                'path' => $pathEp,
                                'method' => $method,
                                'role' => (string)$roleName,
                                'expected' => $exp,
                                'actual' => $act,
                                'risk' => $risk,
                                'cash_out' => $cash,
                                'category' => $cat !== '' ? $cat : 'URL_JOIN_BUG',
                            ];
                        } elseif ($cat === 'URL_JOIN_BUG' || stripos($cat, 'URL_JOIN') !== false) {
                            $k = 'c|' . $epId . '|' . $roleName . '|' . $pathEp . '|' . $method;
                            if (isset($seen[$k])) {
                                continue;
                            }
                            $seen[$k] = true;
                            $u301[] = [
                                'source_file' => basename($path),
                                'id' => $epId,
                                'label' => $label,
                                'path' => $pathEp,
                                'method' => $method,
                                'role' => (string)$roleName,
                                'expected' => $exp,
                                'actual' => $act,
                                'risk' => $risk,
                                'cash_out' => $cash,
                                'category' => $cat,
                            ];
                        }
                    }
                }
            }
        }
        $score = static function (array $x): int {
            $r = $x['risk'] ?? '';
            $base = $r === 'HIGH' ? 100 : ($r === 'MED' ? 20 : 5);
            return $base + (!empty($x['cash_out']) ? 50 : 0);
        };
        usort($real, static fn ($a, $b) => $score($b) <=> $score($a));
        usort($u301, static fn ($a, $b) => $score($b) <=> $score($a));

        return ['real_leak' => $real, 'url_join_bug' => $u301];
    }
}

if (!function_exists('irlib_read_artifact')) {
    function irlib_read_artifact(string $logsDir, string $name, InsightRealQueryLog $log): array
    {
        $log->log('-- (read file)', [$logsDir . '/' . $name], 'artifact');
        $p = $logsDir . '/' . $name;
        if (!is_file($p)) {
            return ['__missing' => true, 'path' => $name];
        }
        $j = json_decode((string)file_get_contents($p), true);
        return is_array($j) ? $j : ['__invalid' => true, 'path' => $name];
    }
}

if (!function_exists('irlib_fin_allowed_actor')) {
    function irlib_fin_allowed_actor(?string $username, ?string $role, ?string $level): bool
    {
        $u = strtoupper(trim((string)$username));
        $r = strtoupper(trim((string)$role));
        $l = strtoupper(trim((string)$level));
        if ($u === 'MGRFIN_BGR') {
            return true;
        }
        foreach (['SYS', 'ADMIN', 'SUPERADMIN'] as $ok) {
            if ($r === $ok || $l === $ok) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ir_insight_real_render_md')) {
    function ir_insight_real_render_md(array $payload, string $auditWrittenCell): string
    {
        $md = [];
        $md[] = '# INSIGHT REAL (last run)';
        $md[] = '';
        $md[] = '| Field | Value |';
        $md[] = '|---|---|';
        $md[] = '| run_at | ' . ($payload['run_at'] ?? '') . ' |';
        $md[] = '| request_id | ' . ($payload['request_id'] ?? '') . ' |';
        $md[] = '| actor | ' . ($payload['actor'] ?? '') . ' |';
        $md[] = '| audit written | ' . $auditWrittenCell . ' |';
        $md[] = '';

        $md[] = '## Health (7d DB + log files)';
        $md[] = '- **audit_events_by_day** + **login_by_day**: `system_audit_logs` (see JSON).';
        $md[] = '- **php_error_lines_by_day**: scan `storage/logs/app-*.log` for error/fatal lines.';
        $md[] = '- **Artifacts**: `backup_last.json`, `backup_verify_last.json`, `restore_dry_run_last.json`.';
        $md[] = '';

        $md[] = '## RBAC (from matrix artifacts)';
        $md[] = '| Metric | Count |';
        $md[] = '|---|---|';
        $md[] = '| REAL_LEAK (expected 403, actual 200) | ' . (int)($payload['rbac']['real_leak_total'] ?? 0) . ' |';
        $md[] = '| URL_JOIN_BUG / 301 class | ' . (int)($payload['rbac']['url_join_bug_total'] ?? 0) . ' |';
        $md[] = '';
        $md[] = '### Top REAL_LEAK (max 20)';
        $md[] = '| id | role | path | method | risk | cash_out |';
        $md[] = '|---|---|---|---|---|---|';
        foreach (($payload['rbac']['real_leak_top20'] ?? []) as $r) {
            $md[] = '| ' . ($r['id'] ?? '') . ' | ' . ($r['role'] ?? '') . ' | ' . ($r['path'] ?? '') . ' | ' . ($r['method'] ?? '') . ' | ' . ($r['risk'] ?? '') . ' | ' . (!empty($r['cash_out']) ? 'Y' : '') . ' |';
        }
        $md[] = '';

        $md[] = '## FIN central cash-out (audit)';
        $fc = $payload['fin_central_cash_out'] ?? [];
        $md[] = '| transaction_count | violation_count |';
        $md[] = '|---|---|';
        $md[] = '| ' . (int)($fc['transaction_count'] ?? 0) . ' | ' . (int)($fc['violation_count'] ?? 0) . ' |';
        $md[] = '';
        if (!empty($fc['violations'])) {
            $md[] = '| kind | doc_code | object_id | username | role |';
            $md[] = '|---|---|---|---|---|';
            foreach ($fc['violations'] as $v) {
                $md[] = '| ' . ($v['kind'] ?? '') . ' | ' . ($v['doc_code'] ?? '') . ' | ' . ($v['object_id'] ?? '') . ' | ' . ($v['username'] ?? '') . ' | ' . ($v['role'] ?? '') . ' |';
            }
            $md[] = '';
        }

        $md[] = '## Sales DO backlog (>3d, non-terminal)';
        $md[] = '| doc_code | office | status | age_days |';
        $md[] = '|---|---|---|---|';
        $n = 0;
        foreach (($payload['sales_do']['backlog_stuck_gt3d'] ?? []) as $row) {
            if ($n++ > 60) {
                $md[] = '| … | (truncated — see CSV) | … | … |';
                break;
            }
            $md[] = '| ' . ($row['doc_code'] ?? '') . ' | ' . ($row['office_code'] ?? '') . ' | ' . ($row['status'] ?? '') . ' | ' . ($row['age_days'] ?? '') . ' |';
        }
        $md[] = '';
        $md[] = 'Full backlog: `insight_real_do_backlog.csv`';
        $md[] = '';

        $md[] = '## Procurement snapshot';
        $pr = $payload['procurement'] ?? [];
        $md[] = '- PO open: ' . json_encode($pr['po_open_count'] ?? null);
        $md[] = '- GR rows (`wqs_incoming`): ' . json_encode($pr['gr_incoming_count'] ?? null);
        $md[] = '- PO without PR: ' . json_encode($pr['po_without_pr'] ?? null);
        $md[] = '- % PO with ≥1 forwarding doc: ' . json_encode($pr['forwarding_doc_po_pct'] ?? null);
        $md[] = '- orphan payment rows (no invoice): ' . json_encode($pr['ap_paid_orphan_payments'] ?? null);
        $md[] = '';

        if (!empty($payload['notes'])) {
            $md[] = '## Notes';
            foreach ($payload['notes'] as $n) {
                $md[] = '- ' . $n;
            }
        }

        return implode("\n", $md);
    }
}

if (!function_exists('irlib_run')) {
    /**
     * @param array{logs_dir:string,actor:string,write_audit:bool,request_id:string} $opts
     * @return array<string, mixed>
     */
    function irlib_run(PDO $pdo, array $opts): array
    {
        $log = new InsightRealQueryLog();
        $logsDir = $opts['logs_dir'];
        $actor = $opts['actor'];
        $writeAudit = $opts['write_audit'];
        $requestId = $opts['request_id'];
        $runAt = date(DateTimeInterface::ATOM);

        $payload = [
            'ok' => true,
            'run_at' => $runAt,
            'request_id' => $requestId,
            'actor' => $actor,
            'health' => [],
            'rbac' => [],
            'fin_central_cash_out' => [],
            'sales_do' => [],
            'procurement' => [],
            'artifacts' => [],
            'notes' => [],
        ];

        // ── Health: audit + login ───────────────────────────────────────────
        $hasAudit = irlib_table_exists($pdo, 'system_audit_logs', $log);
        if ($hasAudit) {
            $sql = "SELECT DATE(created_at) AS d, COUNT(*) AS c
                    FROM system_audit_logs
                    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    GROUP BY DATE(created_at) ORDER BY d";
            $payload['health']['audit_events_by_day'] = irlib_safe_query_all($pdo, $log, $sql, [], 'audit_event count per day (7d)');

            $sql = "SELECT DATE(created_at) AS d,
                    SUM(CASE WHEN action = 'LOGIN_SUCCESS' THEN 1 ELSE 0 END) AS login_ok,
                    SUM(CASE WHEN action = 'LOGIN_DENIED' THEN 1 ELSE 0 END) AS login_fail
                    FROM system_audit_logs
                    WHERE module = 'auth'
                      AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    GROUP BY DATE(created_at) ORDER BY d";
            $payload['health']['login_by_day'] = irlib_safe_query_all($pdo, $log, $sql, [], 'login success vs denied (7d)');
        } else {
            $payload['health']['audit_events_by_day'] = [];
            $payload['health']['login_by_day'] = [];
            $payload['notes'][] = 'system_audit_logs missing — skip DB health audit slices';
        }

        $phpErr = irlib_count_php_errors_by_day($logsDir, 7, $log);
        $payload['health']['php_error_lines_by_day'] = $phpErr;
        $payload['health']['php_error_lines_note'] = 'Count of lines matching [ERROR]/FATAL/Uncaught in storage/logs/app-YYYY-MM-DD.log (Asia/Jakarta “today” window for file names).';

        $payload['artifacts']['backup_last'] = irlib_read_artifact($logsDir, 'backup_last.json', $log);
        $payload['artifacts']['backup_verify_last'] = irlib_read_artifact($logsDir, 'backup_verify_last.json', $log);
        $payload['artifacts']['restore_dry_run_last'] = irlib_read_artifact($logsDir, 'restore_dry_run_last.json', $log);

        $rbacM = irlib_collect_rbac_mismatches($logsDir, $log);
        $payload['rbac'] = [
            'real_leak_top20' => array_slice($rbacM['real_leak'], 0, 20),
            'real_leak_total' => count($rbacM['real_leak']),
            'url_join_bug_top20' => array_slice($rbacM['url_join_bug'], 0, 20),
            'url_join_bug_total' => count($rbacM['url_join_bug']),
        ];

        // ── FIN CASH_OUT (audit-backed) ─────────────────────────────────────
        $finRows = [];
        $violations = [];
        if ($hasAudit) {
            $parts = [];
            if (irlib_table_exists($pdo, 'system_audit_logs', $log)) {
                $parts[] = "SELECT id, 'ap_payment_create' AS kind, username, role, level, record_code AS doc_code, record_id AS object_id, created_at
                    FROM system_audit_logs
                    WHERE module = 'purchases_payment_ap' AND action = 'CREATE'";
                $parts[] = "SELECT id, 'mpr_ops_paid' AS kind, username, role, level, record_code AS doc_code, record_id AS object_id, created_at
                    FROM system_audit_logs
                    WHERE module = 'mpr_ops_daily_fin_pay' AND action = 'OPS_PAID'";
                $parts[] = "SELECT id, 'gl_reversal_approve' AS kind, username, role, level, record_code AS doc_code, record_id AS object_id, created_at
                    FROM system_audit_logs
                    WHERE module = 'fin_gl_auto' AND record_table = 'gl_reversal_requests' AND action = 'APPROVE'";
            }
            if ($parts !== []) {
                $sql = implode(' UNION ALL ', $parts) . ' ORDER BY created_at DESC';
                $finRows = irlib_safe_query_all($pdo, $log, $sql, [], 'FIN CASH_OUT union (AP payment create, MPR OPS_PAID, GL reversal APPROVE)');
            }
            foreach ($finRows as $fr) {
                if (!irlib_fin_allowed_actor(
                    isset($fr['username']) ? (string)$fr['username'] : null,
                    isset($fr['role']) ? (string)$fr['role'] : null,
                    isset($fr['level']) ? (string)$fr['level'] : null
                )) {
                    $violations[] = [
                        'kind' => $fr['kind'] ?? '',
                        'object_id' => (string)($fr['object_id'] ?? ''),
                        'doc_code' => (string)($fr['doc_code'] ?? ''),
                        'username' => (string)($fr['username'] ?? ''),
                        'role' => (string)($fr['role'] ?? ''),
                        'created_at' => (string)($fr['created_at'] ?? ''),
                    ];
                }
            }
        }
        $payload['fin_central_cash_out'] = [
            'transaction_count' => count($finRows),
            'violation_count' => count($violations),
            'violations' => $violations,
            'actors_distinct' => array_values(array_unique(array_map(
                static fn ($r) => (string)($r['username'] ?? ''),
                $finRows
            ))),
            'policy_note' => 'Allowed actors: username MgrFIN_BGR (case-insensitive) OR role/level in SYS, ADMIN, SUPERADMIN (per workspace RBAC convention).',
        ];

        // ── Sales DO ─────────────────────────────────────────────────────────
        $salesSection = [
            'flow_status_counts' => [],
            'flow_status_distinct_from_db' => [],
            'backlog_stuck_gt3d' => [],
            'sla_avg_hours' => [],
            'office_scope_counts' => [],
            'methodology' => [],
        ];
        if (irlib_table_exists($pdo, 'sales_do', $log)) {
            $stCol = irlib_col_exists($pdo, 'sales_do', 'flow_status', $log) ? 'flow_status' : (irlib_col_exists($pdo, 'sales_do', 'status', $log) ? 'status' : null);
            if ($stCol !== null) {
                $sql = "SELECT LOWER(TRIM({$stCol})) AS st, COUNT(*) AS c FROM sales_do GROUP BY LOWER(TRIM({$stCol})) ORDER BY c DESC";
                $salesSection['flow_status_counts'] = irlib_safe_query_all($pdo, $log, $sql, [], 'DO count by flow/status column');
                $sql2 = "SELECT DISTINCT LOWER(TRIM({$stCol})) AS st FROM sales_do ORDER BY st";
                $dist = irlib_safe_query_all($pdo, $log, $sql2, [], 'distinct statuses');
                $salesSection['flow_status_distinct_from_db'] = array_map(static fn ($r) => (string)($r['st'] ?? ''), $dist);
            } else {
                $payload['notes'][] = 'sales_do: no flow_status/status column';
            }

            // Anchor expression (documented O2C proxy) — requires $stCol
            $crmS = irlib_col_exists($pdo, 'sales_do', 'crm_started_at', $log) ? 'crm_started_at' : 'NULL';
            if ($crmS === 'NULL' && irlib_col_exists($pdo, 'sales_do', 'crm_start_time', $log)) {
                $crmS = 'crm_start_time';
            }
            $wqsS = irlib_col_exists($pdo, 'sales_do', 'wqs_started_at', $log) ? 'wqs_started_at' : 'NULL';
            if ($wqsS === 'NULL' && irlib_col_exists($pdo, 'sales_do', 'wqs_updated_at', $log)) {
                $wqsS = 'wqs_updated_at';
            }
            $scmS = irlib_col_exists($pdo, 'sales_do', 'scm_on_delivery_at', $log) ? 'scm_on_delivery_at' : 'NULL';
            if ($scmS === 'NULL' && irlib_col_exists($pdo, 'sales_do', 'scm_delivered_at', $log)) {
                $scmS = 'scm_delivered_at';
            }
            $actS = irlib_col_exists($pdo, 'sales_do', 'act_invoiced_at', $log) ? 'act_invoiced_at' : 'NULL';
            if ($actS === 'NULL' && irlib_col_exists($pdo, 'sales_do', 'act_updated_at', $log)) {
                $actS = 'act_updated_at';
            }
            $finS = irlib_col_exists($pdo, 'sales_do', 'fin_paid_at', $log) ? 'fin_paid_at' : 'NULL';
            if ($finS === 'NULL' && irlib_col_exists($pdo, 'sales_do', 'fin_updated_at', $log)) {
                $finS = 'fin_updated_at';
            }
            if ($stCol !== null) {
                $upd = irlib_col_exists($pdo, 'sales_do', 'updated_at', $log) ? 'updated_at' : 'created_at';
                $crt = irlib_col_exists($pdo, 'sales_do', 'created_at', $log) ? 'created_at' : $upd;

                $stColExpr = 'LOWER(TRIM(COALESCE(' . $stCol . ",'')))";
                $anchor = "COALESCE(
              CASE {$stColExpr}
                WHEN 'draft' THEN COALESCE({$crmS}, {$crt})
                WHEN 'crm' THEN COALESCE({$crmS}, {$crt})
                WHEN 'crm_to_wqs' THEN COALESCE({$crmS}, {$crt})
                WHEN 'sent_wqs' THEN COALESCE({$crmS}, {$crt})
                WHEN 'wqs_processing' THEN COALESCE({$wqsS}, {$upd})
                WHEN 'ready_scm' THEN COALESCE({$wqsS}, {$upd})
                WHEN 'wqs_done' THEN COALESCE({$wqsS}, {$upd})
                WHEN 'on_delivery' THEN COALESCE({$scmS}, {$upd})
                WHEN 'delivered' THEN COALESCE({$scmS}, {$upd})
                WHEN 'scm_done' THEN COALESCE({$scmS}, {$upd})
                WHEN 'wait_payment' THEN COALESCE({$actS}, {$upd})
                WHEN 'act_done' THEN COALESCE({$actS}, {$upd})
                WHEN 'paid' THEN COALESCE({$finS}, {$upd})
                WHEN 'fin_done' THEN COALESCE({$finS}, {$upd})
                WHEN 'closed' THEN COALESCE({$finS}, {$upd})
                WHEN 'cancelled' THEN {$upd}
                WHEN 'void' THEN {$upd}
                ELSE {$upd}
              END, {$upd})";

                $term = "LOWER(TRIM(COALESCE({$stCol},''))) NOT IN ('closed','cancelled','paid','fin_done','void')";
                $sqlB = "SELECT id, do_code AS doc_code, office_code, {$stCol} AS status,
                    DATEDIFF(NOW(), {$anchor}) AS age_days
                    FROM sales_do
                    WHERE {$term}
                      AND DATEDIFF(NOW(), {$anchor}) > 3
                    ORDER BY age_days DESC
                    LIMIT 5000";
                $salesSection['backlog_stuck_gt3d'] = irlib_safe_query_all($pdo, $log, $sqlB, [], 'DO backlog >3d in non-terminal status (anchor CASE by flow_status)');
                $salesSection['methodology']['stuck_anchor_expr'] = 'DATEDIFF(NOW(), ' . $anchor . ') with CASE on ' . $stCol;

                // SLA hours (only where both endpoints non-null)
                $pairs = [
                    ['crm_to_wqs', $crmS, $wqsS, 'CRM→WQS'],
                    ['wqs_to_scm', $wqsS, $scmS, 'WQS→SCM'],
                    ['scm_to_act', $scmS, $actS, 'SCM→ACT'],
                    ['act_to_fin', $actS, $finS, 'ACT→FIN'],
                ];
                foreach ($pairs as [$key, $a, $b, $label]) {
                    if ($a === 'NULL' || $b === 'NULL') {
                        $salesSection['sla_avg_hours'][$key] = null;
                        continue;
                    }
                    $sqlS = "SELECT AVG(TIMESTAMPDIFF(HOUR, {$a}, {$b})) AS avg_hours, COUNT(*) AS sample_n
                         FROM sales_do
                         WHERE {$a} IS NOT NULL AND {$b} IS NOT NULL AND {$b} >= {$a}";
                    $row = irlib_safe_query_one($pdo, $log, $sqlS, [], 'SLA ' . $label);
                    $salesSection['sla_avg_hours'][$key] = [
                        'label' => $label,
                        'avg_hours' => isset($row['avg_hours']) ? (float)$row['avg_hours'] : null,
                        'sample_n' => isset($row['sample_n']) ? (int)$row['sample_n'] : 0,
                    ];
                }
                $sqlFinDone = "SELECT AVG(TIMESTAMPDIFF(HOUR, {$finS}, {$upd})) AS avg_hours, COUNT(*) AS sample_n
                     FROM sales_do
                     WHERE {$finS} IS NOT NULL AND LOWER(TRIM(COALESCE({$stCol},''))) IN ('closed','fin_done')";
                if ($finS !== 'NULL') {
                    $rowFd = irlib_safe_query_one($pdo, $log, $sqlFinDone, [], 'FIN→DONE proxy (fin_paid_at to updated_at on closed)');
                    $salesSection['sla_avg_hours']['fin_to_done'] = [
                        'label' => 'FIN→DONE (proxy)',
                        'avg_hours' => isset($rowFd['avg_hours']) ? (float)$rowFd['avg_hours'] : null,
                        'sample_n' => isset($rowFd['sample_n']) ? (int)$rowFd['sample_n'] : 0,
                    ];
                }

                if (irlib_col_exists($pdo, 'sales_do', 'office_code', $log)) {
                    $sqlO = "SELECT office_code, LOWER(TRIM({$stCol})) AS st, COUNT(*) AS c
                        FROM sales_do GROUP BY office_code, LOWER(TRIM({$stCol})) ORDER BY office_code, c DESC";
                    $salesSection['office_scope_counts'] = irlib_safe_query_all($pdo, $log, $sqlO, [], 'DO breakdown office × status');
                }
            }
        } else {
            $payload['notes'][] = 'sales_do missing';
        }
        $payload['sales_do'] = $salesSection;

        // ── Procurement PR→PO→GR→AP ─────────────────────────────────────────
        $proc = [
            'wqs_pr_by_status' => [],
            'po_open_count' => null,
            'gr_incoming_count' => null,
            'gr_without_po' => null,
            'ap_by_status' => [],
            'po_without_pr' => null,
            'forwarding_doc_po_pct' => null,
            'ap_paid_orphan_payments' => null,
        ];
        if (irlib_table_exists($pdo, 'wqs_pr', $log)) {
            $prDel = irlib_col_exists($pdo, 'wqs_pr', 'deleted_at', $log) ? 'WHERE deleted_at IS NULL' : '';
            $sql = 'SELECT LOWER(TRIM(status)) AS st, COUNT(*) AS c FROM wqs_pr ' . $prDel . ' GROUP BY LOWER(TRIM(status))';
            $proc['wqs_pr_by_status'] = irlib_safe_query_all($pdo, $log, $sql, [], 'PR by status');
        }
        if (irlib_table_exists($pdo, 'purchases_po', $log)) {
            $sql = "SELECT COUNT(*) FROM purchases_po WHERE deleted_at IS NULL AND UPPER(TRIM(status)) NOT IN ('CLOSED','CANCELLED')";
            $log->log($sql, [], 'PO open count');
            try {
                $proc['po_open_count'] = (int)$pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                $proc['po_open_count'] = null;
            }
            $sql = 'SELECT COUNT(*) FROM purchases_po WHERE deleted_at IS NULL AND (pr_id IS NULL OR pr_id = 0)';
            $log->log($sql, [], 'PO without PR ref');
            try {
                $proc['po_without_pr'] = (int)$pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                $proc['po_without_pr'] = null;
            }
        }
        if (irlib_table_exists($pdo, 'wqs_incoming', $log)) {
            $sql = 'SELECT COUNT(*) FROM wqs_incoming WHERE deleted_at IS NULL';
            $log->log($sql, [], 'GR / wqs_incoming count');
            try {
                $proc['gr_incoming_count'] = (int)$pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                $proc['gr_incoming_count'] = null;
            }
            $sql = 'SELECT COUNT(*) FROM wqs_incoming WHERE deleted_at IS NULL AND (po_id IS NULL OR po_id = 0)';
            $log->log($sql, [], 'GR without po_id');
            try {
                $proc['gr_without_po'] = (int)$pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                $proc['gr_without_po'] = null;
            }
        }
        if (irlib_table_exists($pdo, 'purchases_invoice_ap', $log)) {
            $sql = 'SELECT UPPER(TRIM(status)) AS st, COUNT(*) AS c FROM purchases_invoice_ap WHERE deleted_at IS NULL GROUP BY UPPER(TRIM(status))';
            $proc['ap_by_status'] = irlib_safe_query_all($pdo, $log, $sql, [], 'AP invoice by status');
        }
        if (irlib_table_exists($pdo, 'purchases_forwarding_docs', $log) && irlib_table_exists($pdo, 'purchases_po', $log)) {
            $sql = "SELECT
                      ROUND(100 * SUM(CASE WHEN dc.cnt > 0 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 2) AS pct_po_with_doc
                    FROM purchases_po po
                    LEFT JOIN (
                      SELECT po_id, COUNT(*) AS cnt FROM purchases_forwarding_docs WHERE deleted_at IS NULL GROUP BY po_id
                    ) dc ON dc.po_id = po.id
                    WHERE po.deleted_at IS NULL";
            $rowP = irlib_safe_query_one($pdo, $log, $sql, [], '% PO with ≥1 forwarding doc');
            $proc['forwarding_doc_po_pct'] = isset($rowP['pct_po_with_doc']) ? (float)$rowP['pct_po_with_doc'] : null;
        }
        if (irlib_table_exists($pdo, 'purchases_payment_ap', $log) && irlib_col_exists($pdo, 'purchases_payment_ap', 'ap_id', $log)) {
            $sql = "SELECT COUNT(*) FROM purchases_payment_ap p
                    LEFT JOIN purchases_invoice_ap ap ON ap.id = p.ap_id AND ap.deleted_at IS NULL
                    WHERE p.deleted_at IS NULL AND ap.id IS NULL";
            $log->log($sql, [], 'AP payments with missing invoice row');
            try {
                $proc['ap_paid_orphan_payments'] = (int)$pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                $proc['ap_paid_orphan_payments'] = null;
            }
        }
        $payload['procurement'] = $proc;

        // ── Audit INSIGHT_REAL_RUN ──────────────────────────────────────────
        if ($writeAudit && $hasAudit) {
            require_once irlib_root() . '/master/_audit_master.php';
            master_audit_ensure_table($pdo);
            $summary = [
                'request_id' => $requestId,
                'fin_cash_out_n' => count($finRows),
                'fin_violations' => count($violations),
                'rbac_real_leak' => (int)($payload['rbac']['real_leak_total'] ?? 0),
                'do_backlog_rows' => count($salesSection['backlog_stuck_gt3d']),
            ];
            $sqlIns = "INSERT INTO system_audit_logs
                (module, action, record_table, record_id, record_code, description, details,
                 user_id, username, role, level, ip, user_agent, created_at)
                VALUES
                ('tools_insight', 'INSIGHT_REAL_RUN', 'insight_real', NULL, :rcode, :descr, :details,
                 NULL, :uname, 'SYS', 'SYS', '', '', NOW())";
            $log->log($sqlIns, [':rcode' => $requestId, ':descr' => 'INSIGHT_REAL_RUN', ':details' => json_encode($summary)], 'audit trail');
            try {
                $st = $pdo->prepare($sqlIns);
                $st->execute([
                    ':rcode' => $requestId,
                    ':descr' => 'INSIGHT_REAL_RUN',
                    ':details' => json_encode($summary, JSON_UNESCAPED_UNICODE),
                    ':uname' => substr($actor, 0, 100),
                ]);
            } catch (Throwable $e) {
                $payload['notes'][] = 'INSIGHT_REAL_RUN audit insert failed: ' . $e->getMessage();
            }
        }

        $payload['_query_log_entries'] = $log->all();

        return $payload;
    }
}
