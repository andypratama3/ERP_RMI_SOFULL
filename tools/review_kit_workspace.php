<?php
declare(strict_types=1);

// Localhost-only gate for /tools
require_once __DIR__ . '/tools_remote_check.php';

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.REVIEW_KIT', 'TOOLS.VIEW']);
} else {
    require_admin_critical();
}
require_once __DIR__ . '/../_shared/rmi_layout.php';

if (!function_exists('rkw_h')) {
    function rkw_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rkw_storage_file')) {
    function rkw_storage_file(): string
    {
        $root = defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
        return $root . '/storage/audit/review_kit_findings.json';
    }
}

if (!function_exists('rkw_load')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function rkw_load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = (string)@file_get_contents($file);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('rkw_save')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    function rkw_save(string $file, array $rows): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Gagal encode data review.');
        }
        if (@file_put_contents($file, $payload . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menyimpan data review.');
        }
    }
}

if (!function_exists('rkw_save_text_file')) {
    function rkw_save_text_file(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menyimpan file roadmap.');
        }
    }
}

if (!function_exists('rkw_activity_file')) {
    function rkw_activity_file(): string
    {
        $root = defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
        return $root . '/storage/audit/review_kit_activity.jsonl';
    }
}

if (!function_exists('rkw_log_activity')) {
    /**
     * @param array<string,mixed> $meta
     */
    function rkw_log_activity(string $action, string $username, array $meta = []): void
    {
        $file = rkw_activity_file();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $row = [
            'ts' => date('c'),
            'action' => $action,
            'user' => $username,
            'meta' => $meta,
        ];
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        @file_put_contents($file, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('rkw_recent_activity')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function rkw_recent_activity(int $limit = 20): array
    {
        $file = rkw_activity_file();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw) || !$raw) {
            return [];
        }
        $raw = array_reverse($raw);
        $out = [];
        foreach ($raw as $line) {
            $dec = json_decode((string)$line, true);
            if (!is_array($dec)) {
                continue;
            }
            $out[] = $dec;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}

if (!function_exists('rkw_parse_jsonl')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function rkw_parse_jsonl(string $file, int $limit = 100): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw) || !$raw) {
            return [];
        }
        $raw = array_reverse($raw);
        $out = [];
        foreach ($raw as $line) {
            $dec = json_decode((string)$line, true);
            if (!is_array($dec)) {
                continue;
            }
            $out[] = $dec;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}

if (!function_exists('rkw_db_table_exists')) {
    function rkw_db_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $st = $pdo->prepare("SHOW TABLES LIKE ?");
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('rkw_unified_audit_rows')) {
    /**
     * @param array<string,string> $filters
     * @return array<int,array<string,string>>
     */
    function rkw_unified_audit_rows(array $filters, int $limit = 120): array
    {
        $root = rkw_repo_root();
        $rows = [];
        $limit = max(20, min(300, $limit));
        $fUser = strtolower(trim((string)($filters['user'] ?? '')));
        $fModule = strtoupper(trim((string)($filters['module'] ?? '')));
        $fAction = strtoupper(trim((string)($filters['action'] ?? '')));
        $fFrom = trim((string)($filters['from'] ?? ''));
        $fTo = trim((string)($filters['to'] ?? ''));

        $inDateRange = static function (string $ts) use ($fFrom, $fTo): bool {
            if ($ts === '') return true;
            $t = strtotime($ts);
            if ($t === false) return true;
            if ($fFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) {
                $fromTs = strtotime($fFrom . ' 00:00:00');
                if ($fromTs !== false && $t < $fromTs) return false;
            }
            if ($fTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)) {
                $toTs = strtotime($fTo . ' 23:59:59');
                if ($toTs !== false && $t > $toTs) return false;
            }
            return true;
        };

        // 1) Core unified DB audit log (all modules that call erp_audit()).
        $pdo = auth_pdo();
        if ($pdo instanceof PDO && rkw_db_table_exists($pdo, 'erp_audit_log')) {
            try {
                $sql = "SELECT created_at, module, action, username, payload_json, entity_key
                        FROM erp_audit_log WHERE 1=1";
                $params = [];
                if ($fUser !== '') {
                    $sql .= " AND LOWER(COALESCE(username,'')) LIKE ?";
                    $params[] = '%' . $fUser . '%';
                }
                if ($fModule !== '') {
                    $sql .= " AND UPPER(COALESCE(module,'')) LIKE ?";
                    $params[] = '%' . $fModule . '%';
                }
                if ($fAction !== '') {
                    $sql .= " AND UPPER(COALESCE(action,'')) LIKE ?";
                    $params[] = '%' . $fAction . '%';
                }
                if ($fFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $fFrom . ' 00:00:00';
                }
                if ($fTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $fTo . ' 23:59:59';
                }
                $sql .= " ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit;
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $dbRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($dbRows as $r) {
                    $rows[] = [
                        'ts' => (string)($r['created_at'] ?? ''),
                        'source' => 'erp_audit_log',
                        'module' => strtoupper((string)($r['module'] ?? '')),
                        'action' => strtoupper((string)($r['action'] ?? '')),
                        'user' => (string)($r['username'] ?? ''),
                        'entity' => (string)($r['entity_key'] ?? ''),
                        'detail' => (string)($r['payload_json'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {
                // fail-soft
            }
        }

        // 2) sales_do_audit (important operational flow logs).
        if ($pdo instanceof PDO && rkw_db_table_exists($pdo, 'sales_do_audit')) {
            try {
                if ($fModule === '' || str_contains($fModule, 'SALES') || str_contains($fModule, 'DO')) {
                    $sql = "SELECT created_at, do_id, status_from, status_to, actor_dept, actor_name, note
                            FROM sales_do_audit ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit;
                    $st = $pdo->query($sql);
                    $sRows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                    foreach ($sRows as $r) {
                        $ts = (string)($r['created_at'] ?? '');
                        $user = (string)($r['actor_name'] ?? '');
                        $action = strtoupper((string)($r['note'] ?? 'SALES_DO_AUDIT'));
                        if ($fUser !== '' && !str_contains(strtolower($user), $fUser)) continue;
                        if ($fAction !== '' && !str_contains($action, $fAction)) continue;
                        if (!$inDateRange($ts)) continue;
                        $rows[] = [
                            'ts' => $ts,
                            'source' => 'sales_do_audit',
                            'module' => 'SALES_DO',
                            'action' => $action,
                            'user' => $user,
                            'entity' => 'DO#' . (string)($r['do_id'] ?? ''),
                            'detail' => 'from=' . (string)($r['status_from'] ?? '') . ', to=' . (string)($r['status_to'] ?? '') . ', dept=' . (string)($r['actor_dept'] ?? ''),
                        ];
                    }
                }
            } catch (Throwable $e) {
                // fail-soft
            }
        }

        // 3) Workspace local activity log.
        $wkRows = rkw_parse_jsonl($root . '/storage/audit/review_kit_activity.jsonl', $limit);
        foreach ($wkRows as $r) {
            $ts = (string)($r['ts'] ?? '');
            $user = (string)($r['user'] ?? '');
            $action = strtoupper((string)($r['action'] ?? ''));
            if ($fUser !== '' && !str_contains(strtolower($user), $fUser)) continue;
            if ($fModule !== '' && !str_contains('REVIEW_KIT', $fModule)) continue;
            if ($fAction !== '' && !str_contains($action, $fAction)) continue;
            if (!$inDateRange($ts)) continue;
            $rows[] = [
                'ts' => $ts,
                'source' => 'review_kit_activity',
                'module' => 'REVIEW_KIT',
                'action' => $action,
                'user' => $user,
                'entity' => '',
                'detail' => json_encode(($r['meta'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            ];
        }

        // 4) master system login file audit (legacy file-based).
        $mslRows = rkw_parse_jsonl($root . '/uploads/audit_logs/audit_master_system_login.log', $limit);
        foreach ($mslRows as $r) {
            $ts = (string)($r['ts'] ?? '');
            $user = (string)($r['by'] ?? '');
            $action = strtoupper((string)($r['action'] ?? ''));
            if ($fUser !== '' && !str_contains(strtolower($user), $fUser)) continue;
            if ($fModule !== '' && !str_contains('MASTER_SYSTEM_LOGIN', $fModule)) continue;
            if ($fAction !== '' && !str_contains($action, $fAction)) continue;
            if (!$inDateRange($ts)) continue;
            $rows[] = [
                'ts' => $ts,
                'source' => 'audit_master_system_login.log',
                'module' => 'MASTER_SYSTEM_LOGIN',
                'action' => $action,
                'user' => $user,
                'entity' => '',
                'detail' => json_encode(($r['meta'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($b['ts'] ?? ''), (string)($a['ts'] ?? ''));
        });

        return array_slice($rows, 0, $limit);
    }
}

if (!function_exists('rkw_priority')) {
    function rkw_priority(string $severity, string $status): string
    {
        $sev = strtoupper(trim($severity));
        $st = strtoupper(trim($status));
        if ($st === 'DONE') {
            return 'DONE';
        }
        if ($sev === 'CRITICAL') {
            return 'P0';
        }
        if ($sev === 'HIGH') {
            return $st === 'BLOCKED' ? 'P0' : 'P1';
        }
        if ($sev === 'MEDIUM') {
            return in_array($st, ['OPEN', 'BLOCKED'], true) ? 'P1' : 'P2';
        }
        return 'P2';
    }
}

if (!function_exists('rkw_group_active_backlog')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<int,array<string,mixed>>>
     */
    function rkw_group_active_backlog(array $rows): array
    {
        $active = array_values(array_filter($rows, static fn(array $r): bool => strtoupper((string)($r['status'] ?? 'OPEN')) !== 'DONE'));
        usort($active, static function (array $a, array $b): int {
            $rank = ['P0' => 1, 'P1' => 2, 'P2' => 3];
            $pa = rkw_priority((string)($a['severity'] ?? ''), (string)($a['status'] ?? ''));
            $pb = rkw_priority((string)($b['severity'] ?? ''), (string)($b['status'] ?? ''));
            $ra = $rank[$pa] ?? 99;
            $rb = $rank[$pb] ?? 99;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
        });

        $group = ['P0' => [], 'P1' => [], 'P2' => []];
        foreach ($active as $r) {
            $p = rkw_priority((string)($r['severity'] ?? ''), (string)($r['status'] ?? ''));
            if (isset($group[$p])) {
                $group[$p][] = $r;
            }
        }
        return $group;
    }
}

if (!function_exists('rkw_build_roadmap_md')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    function rkw_build_roadmap_md(array $rows, string $by): string
    {
        $group = rkw_group_active_backlog($rows);
        $ts = date('c');
        $md = "# Remediation Roadmap (Auto)\n\n";
        $md .= "- Generated at: `{$ts}`\n";
        $md .= "- Generated by: `{$by}`\n";
        $md .= "- Source: `storage/audit/review_kit_findings.json`\n\n";
        foreach (['P0', 'P1', 'P2'] as $p) {
            $md .= "## {$p}\n\n";
            if (!$group[$p]) {
                $md .= "_No active items._\n\n";
                continue;
            }
            $md .= "| Severity | Module | Finding | PIC | ETA | Status | Evidence |\n";
            $md .= "|---|---|---|---|---|---|---|\n";
            foreach ($group[$p] as $r) {
                $sev = strtoupper((string)($r['severity'] ?? 'MEDIUM'));
                $module = str_replace('|', '\\|', (string)($r['module'] ?? ''));
                $finding = str_replace('|', '\\|', (string)($r['finding'] ?? ''));
                $owner = str_replace('|', '\\|', (string)($r['owner'] ?? '-'));
                $eta = str_replace('|', '\\|', (string)($r['eta'] ?? '-'));
                $status = strtoupper((string)($r['status'] ?? 'OPEN'));
                $ev = str_replace('|', '\\|', (string)($r['evidence_file'] ?? ''));
                $md .= "| {$sev} | {$module} | {$finding} | {$owner} | {$eta} | {$status} | `{$ev}` |\n";
            }
            $md .= "\n";
        }
        return $md;
    }
}

if (!function_exists('rkw_baseline_findings')) {
    /**
     * @return array<int,array<string,string>>
     */
    function rkw_baseline_findings(): array
    {
        return [
            [
                'domain' => 'Workflow Integrity',
                'module' => 'Purchases',
                'severity' => 'CRITICAL',
                'finding' => '3-Way Match berisiko salah join kolom pada validator incoming/PO.',
                'evidence_file' => 'app/Accounting/ThreeWayMatchValidator.php',
                'recommendation' => 'Perbaiki join ke header incoming agar mapping po_code konsisten.',
            ],
            [
                'domain' => 'Security',
                'module' => 'Internal API & Multi Module',
                'severity' => 'CRITICAL',
                'finding' => 'Sebagian endpoint/handler POST belum enforce CSRF konsisten.',
                'evidence_file' => 'api/v1/internal/gl_enqueue_posting.php',
                'recommendation' => 'Wajibkan verify_csrf() di seluruh POST mutasi + regression test.',
            ],
            [
                'domain' => 'UI Navigation',
                'module' => 'Master',
                'severity' => 'CRITICAL',
                'finding' => 'Link fitur import di Master mengarah ke file yang tidak ada (404).',
                'evidence_file' => 'master/index.php',
                'recommendation' => 'Implement halaman import atau remove/disable link sementara.',
            ],
            [
                'domain' => 'Data Integrity',
                'module' => 'Sales/Stock',
                'severity' => 'CRITICAL',
                'finding' => 'Schema drift: DDL inline di halaman aplikasi, tidak melalui migration.',
                'evidence_file' => 'sales/sales_do.php; stock/wqs_incoming.php; stock/wqs_stock_adjustment.php',
                'recommendation' => 'Pindahkan DDL ke migration idempotent dan hapus inline DDL.',
            ],
            [
                'domain' => 'RBAC',
                'module' => 'Auth/Core',
                'severity' => 'HIGH',
                'finding' => 'RBAC campur dept-based vs permission-based, kontrol granular belum konsisten.',
                'evidence_file' => 'master/auth.php; rbac/*',
                'recommendation' => 'Tetapkan permission matrix per modul/aksi dan enforcement bertahap.',
            ],
            [
                'domain' => 'Observability',
                'module' => 'Master/Stock/Payroll/MPR/HRL',
                'severity' => 'HIGH',
                'finding' => 'Coverage audit log belum merata ke seluruh mutasi penting.',
                'evidence_file' => 'multi-module',
                'recommendation' => 'Standarkan erp_audit_log untuk create/edit/approve/post/cancel.',
            ],
            [
                'domain' => 'Finance Reporting',
                'module' => 'GL',
                'severity' => 'HIGH',
                'finding' => 'UI GL report untuk FIN belum selengkap API internal yang tersedia.',
                'evidence_file' => 'api/v1/internal/gl_*',
                'recommendation' => 'Tambah halaman FIN trial balance/ledger/journal listing berbasis API.',
            ],
            [
                'domain' => 'Data Consistency',
                'module' => 'Finance',
                'severity' => 'HIGH',
                'finding' => 'Sumber rekening bank terpisah antara Bank Recon dan master rekening perusahaan.',
                'evidence_file' => 'purchases/bank_recon.php; master/company_bank_accounts.php',
                'recommendation' => 'Samakan source of truth atau mapping sinkron dua arah.',
            ],
            [
                'domain' => 'Hardening',
                'module' => 'CRM Leads',
                'severity' => 'HIGH',
                'finding' => 'CRM Leads perlu hardening lanjutan (fallback migration, helper dedupe, test otomatis).',
                'evidence_file' => 'sales/crm_*; api/v1/internal/crm_*',
                'recommendation' => 'Tambah fallback UI saat tabel belum migrate, refactor helper shared, tambah test suite.',
            ],
        ];
    }
}

if (!function_exists('rkw_repo_root')) {
    function rkw_repo_root(): string
    {
        return defined('RMI_ROOT') ? (string)RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
    }
}

if (!function_exists('rkw_real_scan_targets')) {
    /**
     * @return array<int,string>
     */
    function rkw_real_scan_targets(): array
    {
        $base = [
            'app/Accounting/ThreeWayMatchValidator.php',
            'api/v1/internal/gl_enqueue_posting.php',
            'api/v1/internal/crm_lead_action.php',
            '_shared/rmi_layout.php',
            '_shared/assets.php',
            '_shared/rmi.css',
            'chat/index.php',
            'chat/views/index.php',
            'chat/admin_settings.php',
            'master/index.php',
            'master/auth.php',
            'master/master_system_login.php',
            'master/mfa_policy.php',
            'master/mfa_bypass.php',
            'master/jobs_monitor.php',
            'master/rate_limit_policies.php',
            'master/company_bank_accounts.php',
            'purchases/bank_recon.php',
            'purchases/purchases_invoice_ap.php',
            'purchases/purchases_payment_ap.php',
            'purchases/purchases_invoice_ap_edit.php',
            'purchases/purchases_reports.php',
            'purchases/fin_gl_auto.php',
            'sales/sales_do.php',
            'stock/wqs_incoming.php',
            'stock/wqs_stock_adjustment.php',
        ];
        $dynamic = [];
        if (function_exists('rkw_collect_php_files')) {
            $dynamic = rkw_collect_php_files(rkw_repo_root(), [
                'master',
                'sales',
                'purchases',
                'stock',
                'chat',
                'hrl',
                'hrl_process',
                'hrl_reg_alkes',
                'payroll',
                'mpr',
                'Fixed_Asset',
                'kpi',
                'dashboards',
                'rbac',
                'api/v1',
                'api/v1/internal',
            ]);
        }
        $all = array_values(array_unique(array_merge($base, $dynamic)));
        sort($all);
        return $all;
    }
}

if (!function_exists('rkw_collect_php_files')) {
    /**
     * @param array<int,string> $dirs
     * @return array<int,string>
     */
    function rkw_collect_php_files(string $root, array $dirs): array
    {
        $out = [];
        foreach ($dirs as $relDir) {
            $dir = $root . '/' . ltrim($relDir, '/');
            if (!is_dir($dir)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $f) {
                    if (!$f instanceof SplFileInfo || !$f->isFile()) {
                        continue;
                    }
                    $path = str_replace('\\', '/', (string)$f->getPathname());
                    if (strtolower((string)$f->getExtension()) !== 'php') {
                        continue;
                    }
                    if (str_contains($path, '/vendor/') || str_contains($path, '/storage/') || str_contains($path, '/uploads/')) {
                        continue;
                    }
                    $rel = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');
                    $out[] = $rel;
                }
            } catch (Throwable $e) {
                // keep scan resilient
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }
}

if (!function_exists('rkw_real_scan_meta')) {
    /**
     * @return array{signature:string,files_checked:int,last_modified:string}
     */
    function rkw_real_scan_meta(string $root): array
    {
        $targets = rkw_real_scan_targets();
        $parts = [];
        $latestTs = 0;
        $checked = 0;
        foreach ($targets as $rel) {
            $abs = $root . '/' . ltrim($rel, '/');
            if (!is_file($abs)) {
                $parts[] = $rel . ':missing';
                continue;
            }
            $checked++;
            $mtime = (int)@filemtime($abs);
            if ($mtime > $latestTs) {
                $latestTs = $mtime;
            }
            $hash = @sha1_file($abs);
            $parts[] = $rel . ':' . ($hash ?: 'nohash') . ':' . $mtime;
        }
        return [
            'signature' => substr(sha1(implode('|', $parts)), 0, 16),
            'files_checked' => $checked,
            'last_modified' => $latestTs > 0 ? date('c', $latestTs) : date('c'),
        ];
    }
}

if (!function_exists('rkw_read_rel')) {
    function rkw_read_rel(string $root, string $rel): string
    {
        $p = $root . '/' . ltrim($rel, '/');
        if (!is_file($p)) {
            return '';
        }
        $c = @file_get_contents($p);
        return is_string($c) ? $c : '';
    }
}

if (!function_exists('rkw_has_inline_ddl')) {
    function rkw_has_inline_ddl(string $content): bool
    {
        if ($content === '') {
            return false;
        }
        return (stripos($content, 'CREATE TABLE IF NOT EXISTS') !== false)
            || (stripos($content, 'ALTER TABLE') !== false);
    }
}

if (!function_exists('rkw_baseline_real_findings')) {
    /**
     * @return array<int,array<string,string>>
     */
    function rkw_baseline_real_findings(string $root): array
    {
        $out = [];

        // 1) 3-way match suspicious join usage.
        $f = 'app/Accounting/ThreeWayMatchValidator.php';
        $c = rkw_read_rel($root, $f);
        if ($c !== '' && stripos($c, 'ii.po_code') !== false) {
            $out[] = [
                'domain' => 'Workflow Integrity',
                'module' => 'Purchases',
                'severity' => 'CRITICAL',
                'finding' => '3-Way Match validator masih mengacu ke ii.po_code; perlu verifikasi join ke header incoming.',
                'evidence_file' => $f,
                'recommendation' => 'Gunakan join ke header incoming untuk po_code dan tambah test query path ini.',
            ];
        }

        // 2) POST API without CSRF.
        $f = 'api/v1/internal/gl_enqueue_posting.php';
        $c = rkw_read_rel($root, $f);
        if ($c !== '' && stripos($c, 'require_post()') !== false && stripos($c, 'verify_csrf') === false) {
            $out[] = [
                'domain' => 'Security',
                'module' => 'Internal API',
                'severity' => 'CRITICAL',
                'finding' => 'Endpoint POST internal belum enforce verify_csrf().',
                'evidence_file' => $f,
                'recommendation' => 'Tambahkan verify_csrf() sebelum mutasi dan lengkapi test negative CSRF.',
            ];
        }

        // 3) Broken import links in master index.
        $masterIndex = rkw_read_rel($root, 'master/index.php');
        if ($masterIndex !== '') {
            $imports = ['master_import_products.php', 'master_import_customers.php', 'master_import_vendors.php'];
            $missing = [];
            foreach ($imports as $imp) {
                if (stripos($masterIndex, $imp) !== false && !is_file($root . '/master/' . $imp)) {
                    $missing[] = $imp;
                }
            }
            if ($missing) {
                $out[] = [
                    'domain' => 'UI Navigation',
                    'module' => 'Master',
                    'severity' => 'CRITICAL',
                    'finding' => 'Link import pada master/index.php menuju file yang belum ada: ' . implode(', ', $missing),
                    'evidence_file' => 'master/index.php',
                    'recommendation' => 'Implement halaman import atau nonaktifkan link sampai siap.',
                ];
            }
        }

        // 4) Inline DDL checks in known pages.
        $ddlFiles = ['sales/sales_do.php', 'stock/wqs_incoming.php', 'stock/wqs_stock_adjustment.php'];
        $ddlHit = [];
        foreach ($ddlFiles as $rel) {
            if (rkw_has_inline_ddl(rkw_read_rel($root, $rel))) {
                $ddlHit[] = $rel;
            }
        }
        if ($ddlHit) {
            $out[] = [
                'domain' => 'Data Integrity',
                'module' => 'Sales/Stock',
                'severity' => 'CRITICAL',
                'finding' => 'Masih terdeteksi DDL inline di halaman aplikasi: ' . implode('; ', $ddlHit),
                'evidence_file' => implode('; ', $ddlHit),
                'recommendation' => 'Pindahkan DDL ke migration idempotent terpusat.',
            ];
        }

        // 5) RBAC mixed model indicator.
        // Only flag when major purchasing pages are still role-based.
        $auth = rkw_read_rel($root, 'master/auth.php');
        $rbacMixed = false;
        if ($auth !== '' && stripos($auth, 'function require_role') !== false && stripos($auth, 'can_any(') === false) {
            $rbacMixed = true;
        }
        $rbacPriorityPages = [
            'purchases/purchases_invoice_ap.php',
            'purchases/purchases_payment_ap.php',
            'purchases/purchases_invoice_ap_edit.php',
            'purchases/purchases_reports.php',
            'purchases/fin_gl_auto.php',
            'purchases/bank_recon.php',
        ];
        foreach ($rbacPriorityPages as $pg) {
            $pc = rkw_read_rel($root, $pg);
            if ($pc === '') {
                continue;
            }
            if (stripos($pc, 'require_role(') !== false && stripos($pc, 'require_any_permission(') === false) {
                $rbacMixed = true;
                break;
            }
        }
        if ($rbacMixed) {
            $out[] = [
                'domain' => 'RBAC',
                'module' => 'Auth/Core',
                'severity' => 'HIGH',
                'finding' => 'Model akses masih campuran dept-based dan permission-based.',
                'evidence_file' => 'master/auth.php; _shared/rbac.php',
                'recommendation' => 'Buat roadmap migrasi kontrol granular permission-based per modul/aksi.',
            ];
        }

        // 6) Dual bank account source indicator.
        // Flag only when both modules still use different tables.
        $bankRecon = rkw_read_rel($root, 'purchases/bank_recon.php');
        $bankMaster = rkw_read_rel($root, 'master/company_bank_accounts.php');
        $bankSplit = false;
        if ($bankRecon !== '' && $bankMaster !== '') {
            $reconUsesBankAccounts = stripos($bankRecon, 'FROM bank_accounts') !== false || stripos($bankRecon, 'INSERT INTO bank_accounts') !== false;
            $masterUsesLegacy = stripos($bankMaster, 'master_company_bank_accounts') !== false;
            $masterUsesBankAccounts = stripos($bankMaster, 'FROM bank_accounts') !== false || stripos($bankMaster, 'INSERT INTO bank_accounts') !== false;
            if ($reconUsesBankAccounts && $masterUsesLegacy) {
                $bankSplit = true;
            }
            if (!$masterUsesBankAccounts && stripos($bankMaster, 'master_company_bank_accounts') !== false) {
                $bankSplit = true;
            }
        }
        if ($bankSplit) {
            $out[] = [
                'domain' => 'Data Consistency',
                'module' => 'Finance',
                'severity' => 'HIGH',
                'finding' => 'Sumber data rekening bank masih split antara bank recon dan master rekening.',
                'evidence_file' => 'purchases/bank_recon.php; master/company_bank_accounts.php',
                'recommendation' => 'Tetapkan single source of truth atau sinkronisasi mapping yang eksplisit.',
            ];
        }

        // 7) Chat UI/layout integration drift indicator.
        $layout = rkw_read_rel($root, '_shared/rmi_layout.php');
        $auth = rkw_read_rel($root, 'master/auth.php');
        $assets = rkw_read_rel($root, '_shared/assets.php');
        $chatView = rkw_read_rel($root, 'chat/views/index.php');
        $chatCss = rkw_read_rel($root, '_shared/rmi.css');
        $chatEntry = rkw_read_rel($root, 'chat/index.php');
        $chatUiDrift = false;
        $evidences = [];
        if ($layout !== '' && stripos($layout, '|chat|') === false && stripos($layout, ",'chat',") === false) {
            $chatUiDrift = true;
            $evidences[] = '_shared/rmi_layout.php (base path detector missing chat)';
        }
        if ($auth !== '' && stripos($auth, '|chat|') === false && stripos($auth, ",'chat',") === false) {
            $chatUiDrift = true;
            $evidences[] = 'master/auth.php (BASE_PROJECT detector missing chat)';
        }
        if ($assets !== '' && stripos($assets, '|chat|') === false && stripos($assets, ",'chat',") === false) {
            $chatUiDrift = true;
            $evidences[] = '_shared/assets.php (asset base detector missing chat)';
        }
        if ($chatView !== '' && (stripos($chatView, 'rmi_header(') === false || stripos($chatView, 'rmi_footer(') === false)) {
            $chatUiDrift = true;
            $evidences[] = 'chat/views/index.php (not fully using rmi layout wrapper)';
        }
        if ($chatCss !== '' && stripos($chatCss, '.chat-page') === false) {
            $chatUiDrift = true;
            $evidences[] = '_shared/rmi.css (chat token-driven styles not found)';
        }
        if ($chatEntry !== '' && stripos($chatEntry, 'ChatController') === false) {
            $chatUiDrift = true;
            $evidences[] = 'chat/index.php (controller entrypoint mismatch)';
        }
        if ($chatUiDrift) {
            $out[] = [
                'domain' => 'UI Integration',
                'module' => 'Chat',
                'severity' => 'HIGH',
                'finding' => 'Chat UI belum sepenuhnya sinkron dengan layout/tokens global ERP (potensi tampilan polos / asset path mismatch).',
                'evidence_file' => implode('; ', $evidences),
                'recommendation' => 'Pastikan route chat terdaftar pada base path detector, view menggunakan rmi_header/rmi_footer, dan style chat berada di _shared/rmi.css berbasis token.',
            ];
        }

        // 8) Office master runtime data drift (DB-level, not just static code).
        // Detect canonical office code with unexpected naming from migration source.
        try {
            if (function_exists('auth_pdo')) {
                $pdo = auth_pdo();
                if ($pdo instanceof PDO) {
                    $canonical = [
                        'BGR' => 'Rizqullah Mediska Indonesia',
                        'BKS' => 'Rizqullah Mediska Indonesia Bekasi',
                        'TGR' => 'Rizqullah Mediska Indonesia Tangerang',
                        'BDG' => 'Rizqullah Mediska Indonesia Bandung',
                        'SLO' => 'Rizqullah Mediska Indonesia Jawa Tengah (Solo)',
                        'SMG' => 'Rizqullah Mediska Indonesia Semarang',
                        'JGY' => 'Depo Yogyakarta',
                        'KAL' => 'Depo Kalimantan',
                    ];
                    $codes = array_keys($canonical);
                    if ($codes) {
                        $in = implode(',', array_fill(0, count($codes), '?'));
                        $st = $pdo->prepare("SELECT office_code, office_name, COALESCE(source_system,'') AS source_system
                                             FROM master_office
                                             WHERE UPPER(office_code) IN ($in)");
                        $st->execute($codes);
                        $rowsDb = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        $drifts = [];
                        foreach ($rowsDb as $r) {
                            $code = strtoupper(trim((string)($r['office_code'] ?? '')));
                            $name = trim((string)($r['office_name'] ?? ''));
                            $src = strtolower(trim((string)($r['source_system'] ?? '')));
                            $expected = $canonical[$code] ?? null;
                            if ($expected === null) {
                                continue;
                            }
                            // flag when canonical office got replaced by external label
                            if ($name !== '' && strcasecmp($name, $expected) !== 0 && $src === 'accurate') {
                                $drifts[] = $code . ': "' . $name . '" (expected "' . $expected . '")';
                            }
                        }
                        if ($drifts) {
                            $out[] = [
                                'domain' => 'Data Consistency',
                                'module' => 'Master Office',
                                'severity' => 'HIGH',
                                'finding' => 'Office naming drift terdeteksi pada kode canonical: ' . implode('; ', $drifts),
                                'evidence_file' => 'master_office (runtime DB check)',
                                'recommendation' => 'Sinkronkan nama office canonical di master_office dan lindungi dari overwrite source external saat migration re-run.',
                            ];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore runtime DB scan errors; static scan should still work
        }

        // 9) Critical page auth/role guard check.
        $guardPages = [
            'master/master_system_login.php' => ['ADMIN', 'SUPERADMIN'],
            'master/mfa_policy.php' => ['ADMIN', 'SUPERADMIN'],
            'master/mfa_bypass.php' => ['ADMIN', 'SUPERADMIN'],
            'master/jobs_monitor.php' => ['ADMIN', 'SUPERADMIN'],
            'master/rate_limit_policies.php' => ['ADMIN', 'SUPERADMIN'],
            'chat/admin_settings.php' => ['ADMIN', 'SUPERADMIN'],
        ];
        $guardDrift = [];
        foreach ($guardPages as $pg => $rolesNeed) {
            $pc = rkw_read_rel($root, $pg);
            if ($pc === '') {
                $guardDrift[] = $pg . ' (file missing)';
                continue;
            }
            $hasAdminWrapper = stripos($pc, 'require_admin_critical(') !== false;
            if (stripos($pc, 'require_login()') === false && !$hasAdminWrapper) {
                $guardDrift[] = $pg . ' (missing require_login())';
                continue;
            }
            $roleHit = false;
            foreach ($rolesNeed as $rr) {
                if (stripos($pc, "'" . $rr . "'") !== false || stripos($pc, '"' . $rr . '"') !== false) {
                    $roleHit = true;
                    break;
                }
            }
            if (!$hasAdminWrapper && (stripos($pc, 'require_role(') === false || !$roleHit)) {
                $guardDrift[] = $pg . ' (role guard mismatch)';
            }
        }
        if ($guardDrift) {
            $out[] = [
                'domain' => 'Access Control',
                'module' => 'Admin Critical Pages',
                'severity' => 'CRITICAL',
                'finding' => 'Guard admin kritikal belum konsisten: ' . implode('; ', $guardDrift),
                'evidence_file' => implode('; ', array_keys($guardPages)),
                'recommendation' => 'Pastikan semua halaman kritikal memakai require_admin_critical() atau kombinasi require_login() + require_role([\'ADMIN\',\'SUPERADMIN\']) secara konsisten.',
            ];
        }

        // 10) Internal API POST CSRF check for critical endpoints.
        $postApiChecks = [
            'api/v1/internal/gl_enqueue_posting.php',
            'api/v1/internal/crm_lead_action.php',
        ];
        $csrfMiss = [];
        foreach ($postApiChecks as $apiFile) {
            $ac = rkw_read_rel($root, $apiFile);
            if ($ac === '') {
                continue;
            }
            $hasPostGuard = stripos($ac, 'require_post()') !== false || stripos($ac, "REQUEST_METHOD'] === 'POST'") !== false || stripos($ac, '$_SERVER[\'REQUEST_METHOD\']') !== false;
            $hasCsrf = stripos($ac, 'verify_csrf') !== false || stripos($ac, 'rmi_csrf_validate') !== false;
            if ($hasPostGuard && !$hasCsrf) {
                $csrfMiss[] = $apiFile;
            }
        }
        if ($csrfMiss) {
            $out[] = [
                'domain' => 'Security',
                'module' => 'Internal API',
                'severity' => 'CRITICAL',
                'finding' => 'Endpoint POST internal tanpa CSRF guard: ' . implode('; ', $csrfMiss),
                'evidence_file' => implode('; ', $csrfMiss),
                'recommendation' => 'Tambahkan verify_csrf()/rmi_csrf_validate() pada seluruh endpoint POST internal.',
            ];
        }

        // 11) Runtime account readiness minimal check.
        try {
            if (function_exists('auth_pdo')) {
                $pdo = auth_pdo();
                if ($pdo instanceof PDO) {
                    $tblReady = false;
                    try {
                        $pdo->query("SELECT 1 FROM master_system_login LIMIT 1");
                        $tblReady = true;
                    } catch (Throwable $e) {
                        $tblReady = false;
                    }
                    if ($tblReady) {
                        $cnt = 0;
                        try {
                            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM master_system_login
                                WHERE UPPER(COALESCE(status,''))='ACTIVE'
                                  AND (COALESCE(TRIM(department),'')='' OR COALESCE(TRIM(office_code),'')='')")->fetchColumn();
                        } catch (Throwable $e) {
                            $cnt = 0;
                        }
                        if ($cnt > 0) {
                            $out[] = [
                                'domain' => 'Account Readiness',
                                'module' => 'Master System Login',
                                'severity' => 'HIGH',
                                'finding' => 'Ditemukan akun aktif tanpa department/office_code: ' . $cnt . ' user.',
                                'evidence_file' => 'master_system_login (runtime DB check)',
                                'recommendation' => 'Lengkapi assignment department dan office_code untuk semua akun aktif sebelum rollout massal.',
                            ];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // keep scan resilient
        }

        // 12) Runtime duplicate office_code check.
        try {
            if (function_exists('auth_pdo')) {
                $pdo = auth_pdo();
                if ($pdo instanceof PDO) {
                    $dups = [];
                    try {
                        $st = $pdo->query("SELECT UPPER(office_code) AS code, COUNT(*) AS c
                                           FROM master_office
                                           GROUP BY UPPER(office_code)
                                           HAVING COUNT(*) > 1");
                        $rowsDup = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                        foreach ($rowsDup as $rd) {
                            $dups[] = (string)$rd['code'] . ' x' . (string)$rd['c'];
                        }
                    } catch (Throwable $e) {
                        $dups = [];
                    }
                    if ($dups) {
                        $out[] = [
                            'domain' => 'Data Integrity',
                            'module' => 'Master Office',
                            'severity' => 'CRITICAL',
                            'finding' => 'Duplicate office_code terdeteksi: ' . implode('; ', $dups),
                            'evidence_file' => 'master_office (runtime DB check)',
                            'recommendation' => 'Pastikan office_code unik dan canonical, lalu konsolidasikan row duplikat.',
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // keep scan resilient
        }

        // 13) Full web-module auth guard coverage (aggregate).
        $webFiles = [];
        if (function_exists('rkw_collect_php_files')) {
            $webFiles = rkw_collect_php_files($root, [
                'master',
                'sales',
                'purchases',
                'stock',
                'chat',
                'hrl',
                'hrl_process',
                'hrl_reg_alkes',
                'payroll',
                'mpr',
                'Fixed_Asset',
                'kpi',
                'dashboards',
                'rbac',
            ]);
        }
        $allowNoLogin = [
            'master/login.php',
            'master/logout.php',
            'master/mfa_verify.php',
        ];
        $missingAuth = [];
        foreach ($webFiles as $wf) {
            if (in_array($wf, $allowNoLogin, true)) {
                continue;
            }
            $wc = rkw_read_rel($root, $wf);
            if ($wc === '') {
                continue;
            }
            if (stripos($wc, 'require_login(') === false && stripos($wc, 'require_admin_critical(') === false) {
                $missingAuth[] = $wf;
            }
        }
        if ($missingAuth) {
            $sample = array_slice($missingAuth, 0, 15);
            $out[] = [
                'domain' => 'Access Control',
                'module' => 'Web Modules',
                'severity' => 'HIGH',
                'finding' => 'Ditemukan file web tanpa require_login(): ' . count($missingAuth) . ' file.',
                'evidence_file' => implode('; ', $sample) . (count($missingAuth) > count($sample) ? '; ...' : ''),
                'recommendation' => 'Tambahkan require_login() pada seluruh halaman modul web yang wajib login.',
            ];
        }

        // 14) Full mutation pages missing CSRF verification (aggregate).
        $mutationNoCsrf = [];
        foreach ($webFiles as $wf) {
            $wc = rkw_read_rel($root, $wf);
            if ($wc === '') {
                continue;
            }
            $hasPostHandling = stripos($wc, "REQUEST_METHOD']") !== false || stripos($wc, '$_POST') !== false || stripos($wc, 'require_post(') !== false;
            $looksMutation = stripos($wc, 'INSERT INTO') !== false || stripos($wc, 'UPDATE ') !== false || stripos($wc, 'DELETE FROM') !== false;
            $hasCsrf = stripos($wc, 'verify_csrf') !== false || stripos($wc, 'rmi_csrf_validate') !== false;
            $inheritsCsrfViaAuth = (stripos($wc, 'require_login(') !== false);
            if ($hasPostHandling && $looksMutation && !$hasCsrf && !$inheritsCsrfViaAuth) {
                $mutationNoCsrf[] = $wf;
            }
        }
        if ($mutationNoCsrf) {
            $sample = array_slice($mutationNoCsrf, 0, 15);
            $out[] = [
                'domain' => 'Security',
                'module' => 'Web Mutation',
                'severity' => 'CRITICAL',
                'finding' => 'Ditemukan halaman mutasi tanpa CSRF verify: ' . count($mutationNoCsrf) . ' file.',
                'evidence_file' => implode('; ', $sample) . (count($mutationNoCsrf) > count($sample) ? '; ...' : ''),
                'recommendation' => 'Tambahkan verify_csrf()/rmi_csrf_validate() pada semua handler POST mutasi.',
            ];
        }

        // 15) Internal API guard coverage (aggregate).
        $apiInternal = [];
        if (function_exists('rkw_collect_php_files')) {
            $apiInternal = rkw_collect_php_files($root, ['api/v1/internal']);
        }
        $apiNoLogin = [];
        foreach ($apiInternal as $af) {
            $ac = rkw_read_rel($root, $af);
            if ($ac === '') continue;
            if (stripos($ac, 'require_login(') === false && stripos($ac, 'require_admin_critical(') === false) {
                $apiNoLogin[] = $af;
            }
        }
        if ($apiNoLogin) {
            $sample = array_slice($apiNoLogin, 0, 15);
            $out[] = [
                'domain' => 'Access Control',
                'module' => 'Internal API',
                'severity' => 'CRITICAL',
                'finding' => 'Ditemukan endpoint internal tanpa require_login(): ' . count($apiNoLogin) . ' file.',
                'evidence_file' => implode('; ', $sample) . (count($apiNoLogin) > count($sample) ? '; ...' : ''),
                'recommendation' => 'Wajibkan require_login() pada seluruh endpoint internal API.',
            ];
        }

        return $out;
    }
}

$storageFile = rkw_storage_file();
$roadmapFile = rkw_repo_root() . '/storage/audit/REMEDIATION_ROADMAP_AUTO.md';
$rows = rkw_load($storageFile);
$flash = '';
$errors = [];

$allowedSeverity = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'];
$allowedStatus = ['OPEN', 'IN_PROGRESS', 'DONE', 'BLOCKED'];
$export = strtolower(trim((string)($_GET['export'] ?? '')));

if ($export === 'findings_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="review_kit_findings_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'created_at', 'updated_at', 'domain', 'module', 'severity', 'priority', 'status', 'finding', 'evidence_file', 'recommendation', 'owner', 'eta', 'note', 'created_by', 'updated_by']);
    foreach ($rows as $r) {
        $severity = strtoupper((string)($r['severity'] ?? 'MEDIUM'));
        $status = strtoupper((string)($r['status'] ?? 'OPEN'));
        fputcsv($out, [
            (string)($r['id'] ?? ''),
            (string)($r['created_at'] ?? ''),
            (string)($r['updated_at'] ?? ''),
            (string)($r['domain'] ?? ''),
            (string)($r['module'] ?? ''),
            $severity,
            rkw_priority($severity, $status),
            $status,
            (string)($r['finding'] ?? ''),
            (string)($r['evidence_file'] ?? ''),
            (string)($r['recommendation'] ?? ''),
            (string)($r['owner'] ?? ''),
            (string)($r['eta'] ?? ''),
            (string)($r['note'] ?? ''),
            (string)($r['created_by'] ?? ''),
            (string)($r['updated_by'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

if ($export === 'backlog_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="review_kit_backlog_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['priority', 'severity', 'status', 'module', 'finding', 'owner', 'eta', 'evidence_file', 'recommendation']);
    usort($rows, static function (array $a, array $b): int {
        $rank = ['P0' => 1, 'P1' => 2, 'P2' => 3, 'DONE' => 4];
        $pa = rkw_priority((string)($a['severity'] ?? ''), (string)($a['status'] ?? ''));
        $pb = rkw_priority((string)($b['severity'] ?? ''), (string)($b['status'] ?? ''));
        $ra = $rank[$pa] ?? 99;
        $rb = $rank[$pb] ?? 99;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
    foreach ($rows as $r) {
        $severity = strtoupper((string)($r['severity'] ?? 'MEDIUM'));
        $status = strtoupper((string)($r['status'] ?? 'OPEN'));
        fputcsv($out, [
            rkw_priority($severity, $status),
            $severity,
            $status,
            (string)($r['module'] ?? ''),
            (string)($r['finding'] ?? ''),
            (string)($r['owner'] ?? ''),
            (string)($r['eta'] ?? ''),
            (string)($r['evidence_file'] ?? ''),
            (string)($r['recommendation'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

if ($export === 'roadmap_md') {
    $by = (string)($_SESSION['username'] ?? 'system');
    $md = rkw_build_roadmap_md($rows, $by);

    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="remediation_roadmap_' . date('Ymd_His') . '.md"');
    echo $md;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    verify_csrf();

    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $actor = (string)($_SESSION['username'] ?? 'system');
    try {
        if ($action === 'add') {
            $domain = trim((string)($_POST['domain'] ?? ''));
            $module = trim((string)($_POST['module'] ?? ''));
            $severity = strtoupper(trim((string)($_POST['severity'] ?? 'MEDIUM')));
            $status = strtoupper(trim((string)($_POST['status'] ?? 'OPEN')));
            $finding = trim((string)($_POST['finding'] ?? ''));
            $evidenceFile = trim((string)($_POST['evidence_file'] ?? ''));
            $recommendation = trim((string)($_POST['recommendation'] ?? ''));
            $owner = trim((string)($_POST['owner'] ?? ''));
            $eta = trim((string)($_POST['eta'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($finding === '') {
                throw new RuntimeException('Finding wajib diisi.');
            }
            if (!in_array($severity, $allowedSeverity, true)) {
                $severity = 'MEDIUM';
            }
            if (!in_array($status, $allowedStatus, true)) {
                $status = 'OPEN';
            }
            if ($eta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eta)) {
                throw new RuntimeException('Format ETA harus YYYY-MM-DD.');
            }

            $id = date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
            $rows[] = [
                'id' => $id,
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'domain' => $domain,
                'module' => $module,
                'severity' => $severity,
                'status' => $status,
                'finding' => $finding,
                'evidence_file' => $evidenceFile,
                'recommendation' => $recommendation,
                'owner' => $owner,
                'eta' => $eta,
                'note' => $note,
                'created_by' => $actor,
                'updated_by' => $actor,
            ];
            rkw_save($storageFile, $rows);
            rkw_log_activity('ADD_FINDING', $actor, [
                'id' => $id,
                'module' => $module,
                'severity' => $severity,
                'status' => $status,
            ]);
            $flash = 'Temuan berhasil ditambahkan.';
        } elseif ($action === 'set_status') {
            $id = trim((string)($_POST['id'] ?? ''));
            $to = strtoupper(trim((string)($_POST['to_status'] ?? 'OPEN')));
            if ($id === '') {
                throw new RuntimeException('ID temuan tidak valid.');
            }
            if (!in_array($to, $allowedStatus, true)) {
                throw new RuntimeException('Status tujuan tidak valid.');
            }
            $found = false;
            foreach ($rows as &$r) {
                if ((string)($r['id'] ?? '') !== $id) {
                    continue;
                }
                $r['status'] = $to;
                $r['updated_at'] = date('c');
                $r['updated_by'] = $actor;
                $found = true;
                break;
            }
            unset($r);
            if (!$found) {
                throw new RuntimeException('Temuan tidak ditemukan.');
            }
            rkw_save($storageFile, $rows);
            rkw_log_activity('SET_STATUS', $actor, [
                'id' => $id,
                'to_status' => $to,
            ]);
            $flash = 'Status temuan berhasil diubah.';
        } elseif ($action === 'delete') {
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') {
                throw new RuntimeException('ID temuan tidak valid.');
            }
            $before = count($rows);
            $rows = array_values(array_filter($rows, static fn(array $r): bool => (string)($r['id'] ?? '') !== $id));
            if (count($rows) === $before) {
                throw new RuntimeException('Temuan tidak ditemukan.');
            }
            rkw_save($storageFile, $rows);
            rkw_log_activity('DELETE_FINDING', $actor, [
                'id' => $id,
                'removed' => ($before - count($rows)),
            ]);
            $flash = 'Temuan berhasil dihapus.';
        } elseif ($action === 'seed_baseline') {
            $existing = [];
            foreach ($rows as $r) {
                $key = strtoupper(trim((string)($r['module'] ?? ''))) . '|' . trim((string)($r['finding'] ?? ''));
                $existing[$key] = true;
            }
            $added = 0;
            foreach (rkw_baseline_findings() as $b) {
                $module = trim((string)($b['module'] ?? ''));
                $finding = trim((string)($b['finding'] ?? ''));
                if ($finding === '') {
                    continue;
                }
                $key = strtoupper($module) . '|' . $finding;
                if (isset($existing[$key])) {
                    continue;
                }
                $severity = strtoupper(trim((string)($b['severity'] ?? 'MEDIUM')));
                if (!in_array($severity, $allowedSeverity, true)) {
                    $severity = 'MEDIUM';
                }
                $rows[] = [
                    'id' => date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6),
                    'created_at' => date('c'),
                    'updated_at' => date('c'),
                    'domain' => trim((string)($b['domain'] ?? '')),
                    'module' => $module,
                    'severity' => $severity,
                    'status' => 'OPEN',
                    'finding' => $finding,
                    'evidence_file' => trim((string)($b['evidence_file'] ?? '')),
                    'recommendation' => trim((string)($b['recommendation'] ?? '')),
                    'owner' => '',
                    'eta' => '',
                    'note' => 'Seeded baseline from AI review summary',
                    'created_by' => $actor,
                    'updated_by' => $actor,
                ];
                $existing[$key] = true;
                $added++;
            }
            rkw_save($storageFile, $rows);
            rkw_log_activity('SEED_BASELINE_AI', $actor, ['added' => $added]);
            $flash = $added > 0
                ? ('Baseline temuan berhasil dimuat: ' . $added . ' item.')
                : 'Baseline temuan sudah ada semua (tidak ada item baru).';
        } elseif ($action === 'seed_baseline_real' || $action === 'refresh_baseline_real') {
            $root = rkw_repo_root();
            $baseline = rkw_baseline_real_findings($root);
            $scanMeta = rkw_real_scan_meta($root);
            $scanNote = 'Seeded baseline from real code scan'
                . ' | signature=' . (string)$scanMeta['signature']
                . ' | files=' . (string)$scanMeta['files_checked']
                . ' | latest=' . (string)$scanMeta['last_modified'];
            $removed = 0;
            if ($action === 'refresh_baseline_real') {
                $before = count($rows);
                $rows = array_values(array_filter($rows, static function (array $r): bool {
                    $note = (string)($r['note'] ?? '');
                    if (stripos($note, 'Seeded baseline from real code scan') !== false) {
                        return false;
                    }
                    if (stripos($note, 'Seeded baseline from AI review summary') !== false) {
                        return false;
                    }
                    return true;
                }));
                $removed = $before - count($rows);
            }
            $existing = [];
            foreach ($rows as $r) {
                $key = strtoupper(trim((string)($r['module'] ?? ''))) . '|' . trim((string)($r['finding'] ?? ''));
                $existing[$key] = true;
            }
            $added = 0;
            foreach ($baseline as $b) {
                $module = trim((string)($b['module'] ?? ''));
                $finding = trim((string)($b['finding'] ?? ''));
                if ($finding === '') {
                    continue;
                }
                $key = strtoupper($module) . '|' . $finding;
                if (isset($existing[$key])) {
                    continue;
                }
                $severity = strtoupper(trim((string)($b['severity'] ?? 'MEDIUM')));
                if (!in_array($severity, $allowedSeverity, true)) {
                    $severity = 'MEDIUM';
                }
                $rows[] = [
                    'id' => date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6),
                    'created_at' => date('c'),
                    'updated_at' => date('c'),
                    'domain' => trim((string)($b['domain'] ?? '')),
                    'module' => $module,
                    'severity' => $severity,
                    'status' => 'OPEN',
                    'finding' => $finding,
                    'evidence_file' => trim((string)($b['evidence_file'] ?? '')),
                    'recommendation' => trim((string)($b['recommendation'] ?? '')),
                    'owner' => '',
                    'eta' => '',
                    'note' => $scanNote,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                ];
                $existing[$key] = true;
                $added++;
            }
            rkw_save($storageFile, $rows);
            rkw_log_activity(
                $action === 'refresh_baseline_real' ? 'REFRESH_BASELINE_REAL' : 'SEED_BASELINE_REAL',
                $actor,
                [
                    'added' => $added,
                    'removed' => $removed ?? 0,
                    'signature' => (string)$scanMeta['signature'],
                    'files_checked' => (int)$scanMeta['files_checked'],
                ]
            );
            if ($action === 'refresh_baseline_real') {
                $flash = 'REAL scan disegarkan dari kode saat ini. Removed auto baseline (AI+REAL): ' . $removed
                    . ', Added baru: ' . $added
                    . ', Signature: ' . (string)$scanMeta['signature']
                    . ', Files checked: ' . (string)$scanMeta['files_checked'] . '.';
            } else {
                $flash = $added > 0
                    ? ('Baseline REAL scan berhasil dimuat: ' . $added . ' item. Signature: ' . (string)$scanMeta['signature'] . ', Files checked: ' . (string)$scanMeta['files_checked'] . '.')
                    : 'Baseline REAL scan tidak menambah item baru.';
            }
        } elseif ($action === 'save_roadmap_file') {
            $by = $actor;
            $md = rkw_build_roadmap_md($rows, $by);
            rkw_save_text_file($roadmapFile, $md);
            rkw_log_activity('SAVE_ROADMAP_FILE', $actor, ['file' => 'storage/audit/REMEDIATION_ROADMAP_AUTO.md']);
            $flash = 'Roadmap berhasil disimpan ke file: storage/audit/REMEDIATION_ROADMAP_AUTO.md';
        } elseif ($action === 'save_roadmap_open') {
            $by = $actor;
            $md = rkw_build_roadmap_md($rows, $by);
            rkw_save_text_file($roadmapFile, $md);
            rkw_log_activity('SAVE_ROADMAP_OPEN', $actor, ['file' => 'storage/audit/REMEDIATION_ROADMAP_AUTO.md']);
            header('Content-Type: text/markdown; charset=utf-8');
            header('Content-Disposition: inline; filename="REMEDIATION_ROADMAP_AUTO.md"');
            echo $md;
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$severitySummary = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
$statusSummary = ['OPEN' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'BLOCKED' => 0];
$backlogSummary = ['P0' => 0, 'P1' => 0, 'P2' => 0, 'DONE' => 0];
$realScanInfo = [
    'signature' => '',
    'files_checked' => '',
    'latest' => '',
    'items' => 0,
    'row_updated_at' => '',
];
foreach ($rows as $r) {
    $sev = strtoupper((string)($r['severity'] ?? 'MEDIUM'));
    $st = strtoupper((string)($r['status'] ?? 'OPEN'));
    if (!isset($severitySummary[$sev])) {
        $severitySummary[$sev] = 0;
    }
    if (!isset($statusSummary[$st])) {
        $statusSummary[$st] = 0;
    }
    $severitySummary[$sev]++;
    $statusSummary[$st]++;
    $prio = rkw_priority($sev, $st);
    if (!isset($backlogSummary[$prio])) {
        $backlogSummary[$prio] = 0;
    }
    $backlogSummary[$prio]++;

    $note = (string)($r['note'] ?? '');
    if (stripos($note, 'Seeded baseline from real code scan') !== false) {
        $realScanInfo['items']++;
        $updated = (string)($r['updated_at'] ?? '');
        if ($updated >= (string)$realScanInfo['row_updated_at']) {
            $realScanInfo['row_updated_at'] = $updated;
            if (preg_match('/signature=([a-f0-9]{8,40})/i', $note, $m)) {
                $realScanInfo['signature'] = (string)$m[1];
            }
            if (preg_match('/files=([0-9]+)/i', $note, $m)) {
                $realScanInfo['files_checked'] = (string)$m[1];
            }
            if (preg_match('/latest=([^|]+)/i', $note, $m)) {
                $realScanInfo['latest'] = trim((string)$m[1]);
            }
        }
    }
}

$riskWeights = ['CRITICAL' => 35, 'HIGH' => 15, 'MEDIUM' => 6, 'LOW' => 2];
$activeSeverity = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
$activeCriticalOpen = 0;
foreach ($rows as $r) {
    $sev = strtoupper((string)($r['severity'] ?? 'MEDIUM'));
    $st = strtoupper((string)($r['status'] ?? 'OPEN'));
    if ($st === 'DONE') {
        continue;
    }
    if (!isset($activeSeverity[$sev])) {
        continue;
    }
    $activeSeverity[$sev]++;
    if ($sev === 'CRITICAL' && ($st === 'OPEN' || $st === 'BLOCKED' || $st === 'IN_PROGRESS')) {
        $activeCriticalOpen++;
    }
}
$riskScore = 0;
foreach ($riskWeights as $sev => $w) {
    $riskScore += ((int)$activeSeverity[$sev] * $w);
}
$riskScore = max(0, min(100, $riskScore));
$readinessScore = 100 - $riskScore;
$readinessStatus = 'HIJAU';
$readinessBadgeClass = 'success';
if ($readinessScore < 65 || $activeCriticalOpen > 0) {
    $readinessStatus = 'MERAH';
    $readinessBadgeClass = 'danger';
} elseif ($readinessScore < 85) {
    $readinessStatus = 'KUNING';
    $readinessBadgeClass = 'warning';
}

$recentActivity = rkw_recent_activity(20);
$singleMode = strtolower(trim((string)($_GET['mode'] ?? ''))) === 'single';

$readinessAudit = [
    'available' => false,
    'gate' => 'UNKNOWN',
    'generated_at' => '',
    'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0, 'total' => 0],
    'issues' => [],
    'fresh_minutes' => null,
];
$readinessJsonFile = rkw_repo_root() . '/storage/audit/readiness_audit_latest.json';
if (is_file($readinessJsonFile)) {
    $rawReadiness = (string)@file_get_contents($readinessJsonFile);
    $decReadiness = json_decode($rawReadiness, true);
    if (is_array($decReadiness)) {
        $readinessAudit['available'] = true;
        $readinessAudit['gate'] = strtoupper((string)($decReadiness['gate'] ?? 'UNKNOWN'));
        $readinessAudit['generated_at'] = (string)($decReadiness['generated_at'] ?? '');
        $sum = $decReadiness['summary'] ?? [];
        if (is_array($sum)) {
            $readinessAudit['summary'] = [
                'pass' => (int)($sum['pass'] ?? 0),
                'warn' => (int)($sum['warn'] ?? 0),
                'fail' => (int)($sum['fail'] ?? 0),
                'total' => (int)($sum['total'] ?? 0),
            ];
        }
        $checks = $decReadiness['checks'] ?? [];
        if (is_array($checks)) {
            foreach ($checks as $ck) {
                if (!is_array($ck)) {
                    continue;
                }
                $st = strtoupper((string)($ck['status'] ?? 'WARN'));
                if ($st === 'WARN' || $st === 'FAIL') {
                    $readinessAudit['issues'][] = [
                        'status' => $st,
                        'domain' => (string)($ck['domain'] ?? ''),
                        'name' => (string)($ck['name'] ?? ''),
                        'detail' => (string)($ck['detail'] ?? ''),
                        'evidence' => (string)($ck['evidence'] ?? ''),
                    ];
                }
            }
        }
        if ($readinessAudit['generated_at'] !== '') {
            $ts = strtotime($readinessAudit['generated_at']);
            if ($ts !== false) {
                $readinessAudit['fresh_minutes'] = max(0, (int)floor((time() - $ts) / 60));
            }
        }
    }
}

$unifiedGate = 'HIJAU';
$unifiedGateClass = 'success';
if (
    $activeCriticalOpen > 0
    || (int)$backlogSummary['P0'] > 0
    || (string)$readinessAudit['gate'] === 'NO-GO'
    || (int)($readinessAudit['summary']['fail'] ?? 0) > 0
) {
    $unifiedGate = 'MERAH';
    $unifiedGateClass = 'danger';
} elseif (
    $readinessScore < 85
    || (string)$readinessAudit['gate'] === 'GO WITH CAUTION'
    || (int)($readinessAudit['summary']['warn'] ?? 0) > 0
) {
    $unifiedGate = 'KUNING';
    $unifiedGateClass = 'warning';
}

$auditFilters = [
    'user' => trim((string)($_GET['aud_user'] ?? '')),
    'module' => trim((string)($_GET['aud_module'] ?? '')),
    'action' => trim((string)($_GET['aud_action'] ?? '')),
    'from' => trim((string)($_GET['aud_from'] ?? '')),
    'to' => trim((string)($_GET['aud_to'] ?? '')),
];
$auditLimit = max(20, min(300, (int)($_GET['aud_limit'] ?? 120)));
$unifiedAuditRows = rkw_unified_audit_rows($auditFilters, $auditLimit);
$unifiedAuditBySource = [];
foreach ($unifiedAuditRows as $ar) {
    $src = (string)($ar['source'] ?? 'unknown');
    if (!isset($unifiedAuditBySource[$src])) {
        $unifiedAuditBySource[$src] = 0;
    }
    $unifiedAuditBySource[$src]++;
}

usort($rows, static function (array $a, array $b): int {
    $prioOrder = ['P0' => 1, 'P1' => 2, 'P2' => 3, 'DONE' => 4];
    $pa = rkw_priority((string)($a['severity'] ?? ''), (string)($a['status'] ?? ''));
    $pb = rkw_priority((string)($b['severity'] ?? ''), (string)($b['status'] ?? ''));
    $ra = $prioOrder[$pa] ?? 99;
    $rb = $prioOrder[$pb] ?? 99;
    if ($ra !== $rb) {
        return $ra <=> $rb;
    }
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

$roadmapPreview = rkw_group_active_backlog($rows);
$roadmapSavedAt = is_file($roadmapFile) ? date('Y-m-d H:i:s', (int)filemtime($roadmapFile)) : '';
$roadmapPlainText = rkw_build_roadmap_md($rows, (string)($_SESSION['username'] ?? 'system'));

$base = rmi_layout_base_project();
$headerActions = [
    ['label' => 'Save + Open file', 'url' => '#', 'class' => 'btn btn-sm btn-primary', 'attrs' => 'onclick="document.getElementById(\'rkwSaveOpenRoadmapForm\').submit(); return false;"'],
    ['label' => 'Save Roadmap to File', 'url' => '#', 'class' => 'btn btn-sm btn-primary', 'attrs' => 'onclick="document.getElementById(\'rkwSaveRoadmapForm\').submit(); return false;"'],
    ['label' => 'Export Findings CSV', 'url' => '?export=findings_csv', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Export Backlog CSV', 'url' => '?export=backlog_csv', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Generate Roadmap MD', 'url' => '?export=roadmap_md', 'class' => 'btn btn-sm btn-primary'],
    ['label' => $singleMode ? 'Full Workspace Mode' : 'Single Dashboard Mode', 'url' => $singleMode ? '?mode=full' : '?mode=single', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Back to Review Kit', 'url' => $base . '/tools/review_kit.php', 'class' => 'btn btn-sm btn-outline-light'],
];
rmi_header('Review Kit Workspace', [
    'active' => 'tools',
    'subtitle' => 'Input temuan audit via UI + summary severity otomatis',
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => $base . '/tools/index.php'],
        ['label' => 'Review Kit ERP', 'url' => $base . '/tools/review_kit.php'],
        'Workspace',
    ],
    'actions' => $headerActions,
]);
?>

<form id="rkwSaveRoadmapForm" method="post" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= rkw_h(csrf_token()) ?>">
  <input type="hidden" name="action" value="save_roadmap_file">
</form>
<form id="rkwSaveOpenRoadmapForm" method="post" target="_blank" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= rkw_h(csrf_token()) ?>">
  <input type="hidden" name="action" value="save_roadmap_open">
</form>

<?php if ($flash !== ''): ?>
  <div class="alert alert-success py-2"><?= rkw_h($flash) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="alert alert-danger py-2">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= rkw_h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">Single Dashboard (Unified Control)</div>
      <div class="small rmi-muted">
        Mode: <b><?= $singleMode ? 'Single Dashboard' : 'Full Workspace' ?></b>
        &middot; Gabungan Review Kit + Readiness Audit.
      </div>
    </div>
    <div class="text-end">
      <span class="badge text-bg-<?= rkw_h($unifiedGateClass) ?>">Unified Gate <?= rkw_h($unifiedGate) ?></span>
      <div class="small text-secondary mt-1">
        Workspace score: <b><?= (int)$readinessScore ?></b>
        <?php if ((string)$readinessAudit['gate'] !== 'UNKNOWN'): ?>
          &middot; Readiness gate: <b><?= rkw_h((string)$readinessAudit['gate']) ?></b>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="mt-2 small">
    <a class="btn btn-sm btn-outline-light me-1" href="<?= rkw_h($base . '/tools/review_kit.php') ?>">Review Kit Global</a>
    <a class="btn btn-sm btn-outline-light me-1" href="<?= rkw_h($base . '/tools/readiness_audit.php') ?>">Readiness Audit</a>
    <a class="btn btn-sm btn-outline-light me-1" href="<?= rkw_h($base . '/tools/security_audit.php') ?>">Security Audit</a>
    <a class="btn btn-sm btn-outline-light" href="<?= rkw_h($base . '/tools/audit/audit_center.php') ?>">Audit Center</a>
  </div>
  <?php if ((bool)$readinessAudit['available']): ?>
    <div class="small rmi-muted mt-2">
      Readiness generated: <b><?= rkw_h((string)$readinessAudit['generated_at']) ?></b>
      <?php if ($readinessAudit['fresh_minutes'] !== null): ?>
        &middot; Freshness: <b><?= (int)$readinessAudit['fresh_minutes'] ?> min ago</b>
      <?php endif; ?>
      &middot; PASS <b><?= (int)($readinessAudit['summary']['pass'] ?? 0) ?></b>
      / WARN <b><?= (int)($readinessAudit['summary']['warn'] ?? 0) ?></b>
      / FAIL <b><?= (int)($readinessAudit['summary']['fail'] ?? 0) ?></b>
    </div>
  <?php else: ?>
    <div class="small rmi-muted mt-2">Readiness audit belum tersedia. Jalankan `tools/readiness_audit.php`.</div>
  <?php endif; ?>
</div>

<?php if ($singleMode && !empty($readinessAudit['issues'])): ?>
  <div class="rmi-card p-3 mb-3">
    <div class="fw-semibold mb-2">Top Readiness Issues (WARN/FAIL)</div>
    <div class="table-responsive">
      <table class="table table-sm table-dark table-hover mb-0">
        <thead><tr><th>Status</th><th>Domain</th><th>Check</th><th>Detail</th><th>Evidence</th></tr></thead>
        <tbody>
        <?php foreach (array_slice((array)$readinessAudit['issues'], 0, 12) as $iss): ?>
          <tr>
            <td><span class="badge text-bg-<?= (($iss['status'] ?? '') === 'FAIL') ? 'danger' : 'warning' ?>"><?= rkw_h((string)($iss['status'] ?? 'WARN')) ?></span></td>
            <td><?= rkw_h((string)($iss['domain'] ?? '')) ?></td>
            <td><?= rkw_h((string)($iss['name'] ?? '')) ?></td>
            <td><?= rkw_h((string)($iss['detail'] ?? '')) ?></td>
            <td><code><?= rkw_h((string)($iss['evidence'] ?? '')) ?></code></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <div class="fw-semibold">Unified Module Audit Log (All Modules)</div>
    <div class="small rmi-muted">
      Rows: <b><?= (int)count($unifiedAuditRows) ?></b>
      <?php if ($unifiedAuditBySource): ?>
        &middot;
        <?php foreach ($unifiedAuditBySource as $src => $cnt): ?>
          <?= rkw_h((string)$src) ?>=<b><?= (int)$cnt ?></b>&nbsp;
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <form method="get" class="row g-2 mb-2">
    <?php if ($singleMode): ?><input type="hidden" name="mode" value="single"><?php endif; ?>
    <div class="col-md-2">
      <label class="form-label">User</label>
      <input class="form-control form-control-sm" name="aud_user" value="<?= rkw_h((string)$auditFilters['user']) ?>" placeholder="username / actor">
    </div>
    <div class="col-md-2">
      <label class="form-label">Module</label>
      <input class="form-control form-control-sm" name="aud_module" value="<?= rkw_h((string)$auditFilters['module']) ?>" placeholder="SALES / CHAT / ...">
    </div>
    <div class="col-md-2">
      <label class="form-label">Action</label>
      <input class="form-control form-control-sm" name="aud_action" value="<?= rkw_h((string)$auditFilters['action']) ?>" placeholder="CREATE / EDIT / DELETE">
    </div>
    <div class="col-md-2">
      <label class="form-label">From</label>
      <input type="date" class="form-control form-control-sm" name="aud_from" value="<?= rkw_h((string)$auditFilters['from']) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">To</label>
      <input type="date" class="form-control form-control-sm" name="aud_to" value="<?= rkw_h((string)$auditFilters['to']) ?>">
    </div>
    <div class="col-md-1">
      <label class="form-label">Limit</label>
      <input type="number" min="20" max="300" class="form-control form-control-sm" name="aud_limit" value="<?= (int)$auditLimit ?>">
    </div>
    <div class="col-md-1 d-flex align-items-end gap-1">
      <button class="btn btn-sm btn-outline-light">Apply</button>
      <a class="btn btn-sm btn-outline-light" href="<?= $singleMode ? '?mode=single' : '?' ?>">Reset</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover mb-0">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Source</th>
          <th>Module</th>
          <th>Action</th>
          <th>User</th>
          <th>Entity</th>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$unifiedAuditRows): ?>
        <tr><td colspan="7" class="text-center text-muted py-3">Belum ada data audit lintas modul untuk filter ini.</td></tr>
      <?php endif; ?>
      <?php foreach ($unifiedAuditRows as $au): ?>
        <tr>
          <td><small><?= rkw_h((string)($au['ts'] ?? '')) ?></small></td>
          <td><span class="badge text-bg-secondary"><?= rkw_h((string)($au['source'] ?? '')) ?></span></td>
          <td><?= rkw_h((string)($au['module'] ?? '')) ?></td>
          <td><?= rkw_h((string)($au['action'] ?? '')) ?></td>
          <td><?= rkw_h((string)($au['user'] ?? '')) ?></td>
          <td><code><?= rkw_h((string)($au['entity'] ?? '')) ?></code></td>
          <td><small><?= rkw_h((string)($au['detail'] ?? '')) ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!$singleMode): ?>
<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">Last REAL Scan Signature</div>
      <?php if ((int)$realScanInfo['items'] > 0): ?>
        <div class="small rmi-muted">
          Signature: <code><?= rkw_h((string)$realScanInfo['signature']) ?></code>
          &middot; Files checked: <b><?= rkw_h((string)$realScanInfo['files_checked']) ?></b>
          &middot; Latest file mtime: <b><?= rkw_h((string)$realScanInfo['latest']) ?></b>
          &middot; Real findings: <b><?= (int)$realScanInfo['items'] ?></b>
        </div>
      <?php else: ?>
        <div class="small rmi-muted">Belum ada data REAL scan tersimpan. Jalankan <b>Refresh REAL Scan (Replace)</b>.</div>
      <?php endif; ?>
    </div>
    <div class="small text-secondary">Sumber: metadata note pada temuan auto REAL scan.</div>
  </div>
</div>

<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">Readiness Score (0-100)</div>
      <div class="small rmi-muted">
        Score dihitung dari backlog aktif (exclude DONE) dengan bobot severity:
        Critical=35, High=15, Medium=6, Low=2.
      </div>
    </div>
    <div class="text-end">
      <div class="fs-2 fw-bold"><?= (int)$readinessScore ?></div>
      <span class="badge text-bg-<?= rkw_h($readinessBadgeClass) ?>">Status <?= rkw_h($readinessStatus) ?></span>
      <div class="small text-secondary mt-1">
        Active Critical: <b><?= (int)$activeSeverity['CRITICAL'] ?></b> |
        High: <b><?= (int)$activeSeverity['HIGH'] ?></b> |
        Medium: <b><?= (int)$activeSeverity['MEDIUM'] ?></b> |
        Low: <b><?= (int)$activeSeverity['LOW'] ?></b>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Critical</div><div class="fs-4 fw-bold text-danger"><?= (int)$severitySummary['CRITICAL'] ?></div></div></div>
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">High</div><div class="fs-4 fw-bold text-warning"><?= (int)$severitySummary['HIGH'] ?></div></div></div>
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Medium</div><div class="fs-4 fw-bold text-info"><?= (int)$severitySummary['MEDIUM'] ?></div></div></div>
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Low</div><div class="fs-4 fw-bold text-success"><?= (int)$severitySummary['LOW'] ?></div></div></div>
</div>

<div class="rmi-card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">Preview Remediation Roadmap (Live)</div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <button type="button" id="rkwCopyRoadmapBtn" class="btn btn-sm btn-outline-light">Copy Roadmap as plain text</button>
      <span id="rkwCopyRoadmapMsg" class="small rmi-muted"></span>
      <div class="small rmi-muted">
        Sumber: temuan aktif saat ini (exclude DONE)
        <?php if ($roadmapSavedAt !== ''): ?>
          &middot; Last saved file: <code>storage/audit/REMEDIATION_ROADMAP_AUTO.md</code> (<?= rkw_h($roadmapSavedAt) ?>)
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="row g-3">
    <?php foreach (['P0', 'P1', 'P2'] as $bucket): ?>
      <div class="col-lg-4">
        <div class="rmi-card p-2 h-100">
          <div class="fw-semibold mb-2"><?= rkw_h($bucket) ?> (<?= (int)count($roadmapPreview[$bucket] ?? []) ?>)</div>
          <?php if (empty($roadmapPreview[$bucket])): ?>
            <div class="small text-secondary">Tidak ada item aktif.</div>
          <?php else: ?>
            <div class="small">
              <ol class="mb-0">
                <?php foreach (array_slice($roadmapPreview[$bucket], 0, 8) as $it): ?>
                  <li>
                    <b><?= rkw_h((string)($it['module'] ?? '-')) ?></b> -
                    <?= rkw_h((string)($it['finding'] ?? '')) ?>
                    <div class="text-secondary">PIC: <?= rkw_h((string)($it['owner'] ?? '-')) ?> | ETA: <?= rkw_h((string)($it['eta'] ?? '-')) ?></div>
                  </li>
                <?php endforeach; ?>
              </ol>
              <?php if (count($roadmapPreview[$bucket]) > 8): ?>
                <div class="text-secondary mt-1">... dan <?= (int)(count($roadmapPreview[$bucket]) - 8) ?> item lainnya.</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<textarea id="rkwRoadmapPlainText" style="position:absolute;left:-9999px;top:-9999px;opacity:0;" aria-hidden="true"><?= rkw_h($roadmapPlainText) ?></textarea>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Backlog P0</div><div class="fs-4 fw-bold text-danger"><?= (int)$backlogSummary['P0'] ?></div></div></div>
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Backlog P1</div><div class="fs-4 fw-bold text-warning"><?= (int)$backlogSummary['P1'] ?></div></div></div>
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Backlog P2</div><div class="fs-4 fw-bold text-info"><?= (int)$backlogSummary['P2'] ?></div></div></div>
  <div class="col-md-3"><div class="rmi-card p-3"><div class="rmi-muted">Done</div><div class="fs-4 fw-bold text-success"><?= (int)$backlogSummary['DONE'] ?></div></div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <div class="fw-semibold">Lihat hasil audit seperti ringkasan AI</div>
          <div class="rmi-muted small">Kamu bisa pilih baseline dari ringkasan AI (cepat) atau REAL scan (berdasarkan kondisi file ERP saat ini). Data temuan tersimpan di JSON dan tidak berubah otomatis sampai kamu jalankan scan lagi.</div>
        </div>
        <div class="d-flex gap-2">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= rkw_h(csrf_token()) ?>">
            <input type="hidden" name="action" value="seed_baseline">
            <button class="btn btn-sm btn-warning" onclick="return confirm('Muat baseline temuan AI ke workspace?')">Muat Baseline AI</button>
          </form>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= rkw_h(csrf_token()) ?>">
            <input type="hidden" name="action" value="seed_baseline_real">
            <button class="btn btn-sm btn-outline-warning" onclick="return confirm('Jalankan baseline REAL scan dari file ERP saat ini?')">Muat Baseline REAL Scan</button>
          </form>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= rkw_h(csrf_token()) ?>">
            <input type="hidden" name="action" value="refresh_baseline_real">
            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Refresh REAL scan? Semua temuan auto baseline (AI + REAL) lama akan dihapus lalu digenerate ulang dari kode saat ini.')">Refresh REAL Scan (Replace)</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Tambah Temuan Baru</div>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= rkw_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="col-md-2">
          <label class="form-label">Domain</label>
          <input class="form-control form-control-sm" name="domain" placeholder="Security / Data / ...">
        </div>
        <div class="col-md-2">
          <label class="form-label">Module</label>
          <input class="form-control form-control-sm" name="module" placeholder="Sales / Purchases / ...">
        </div>
        <div class="col-md-2">
          <label class="form-label">Severity</label>
          <select class="form-select form-select-sm" name="severity">
            <option>CRITICAL</option><option>HIGH</option><option selected>MEDIUM</option><option>LOW</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select class="form-select form-select-sm" name="status">
            <option selected>OPEN</option><option>IN_PROGRESS</option><option>DONE</option><option>BLOCKED</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Owner</label>
          <input class="form-control form-control-sm" name="owner" placeholder="PIC">
        </div>
        <div class="col-md-2">
          <label class="form-label">ETA</label>
          <input type="date" class="form-control form-control-sm" name="eta">
        </div>
        <div class="col-12">
          <label class="form-label">Finding *</label>
          <input class="form-control form-control-sm" name="finding" required placeholder="Deskripsi temuan">
        </div>
        <div class="col-md-6">
          <label class="form-label">Evidence File</label>
          <input class="form-control form-control-sm" name="evidence_file" placeholder="contoh: sales/sales_dashboard.php">
        </div>
        <div class="col-md-6">
          <label class="form-label">Recommendation</label>
          <input class="form-control form-control-sm" name="recommendation" placeholder="aksi perbaikan yang disarankan">
        </div>
        <div class="col-12">
          <label class="form-label">Note</label>
          <textarea class="form-control form-control-sm" rows="2" name="note" placeholder="catatan tambahan"></textarea>
        </div>
        <div class="col-12">
          <button class="btn btn-sm btn-primary">Simpan Temuan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="rmi-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">Daftar Temuan</div>
    <div class="small rmi-muted">Open: <?= (int)$statusSummary['OPEN'] ?> | In Progress: <?= (int)$statusSummary['IN_PROGRESS'] ?> | Done: <?= (int)$statusSummary['DONE'] ?> | Blocked: <?= (int)$statusSummary['BLOCKED'] ?></div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover mb-0">
      <thead>
        <tr>
          <th>Priority</th>
          <th>Severity</th>
          <th>Domain/Module</th>
          <th>Finding</th>
          <th>Evidence</th>
          <th>PIC/ETA</th>
          <th>Status</th>
          <th style="width:220px;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-3">Belum ada temuan. Mulai dari form di atas.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <?php $id = (string)($r['id'] ?? ''); $st = strtoupper((string)($r['status'] ?? 'OPEN')); ?>
        <?php $sev = strtoupper((string)($r['severity'] ?? 'MEDIUM')); ?>
        <?php $prio = rkw_priority($sev, $st); ?>
        <tr>
          <td><span class="badge text-bg-<?= $prio === 'P0' ? 'danger' : ($prio === 'P1' ? 'warning' : ($prio === 'P2' ? 'info' : 'success')) ?>"><?= rkw_h($prio) ?></span></td>
          <td><span class="badge text-bg-<?= $sev === 'CRITICAL' ? 'danger' : ($sev === 'HIGH' ? 'warning' : ($sev === 'MEDIUM' ? 'info' : 'success')) ?>"><?= rkw_h($sev) ?></span></td>
          <td>
            <div><?= rkw_h((string)($r['domain'] ?? '-')) ?></div>
            <small class="text-secondary"><?= rkw_h((string)($r['module'] ?? '-')) ?></small>
          </td>
          <td>
            <div><?= rkw_h((string)($r['finding'] ?? '')) ?></div>
            <small class="text-secondary"><?= rkw_h((string)($r['recommendation'] ?? '')) ?></small>
          </td>
          <td>
            <div><code><?= rkw_h((string)($r['evidence_file'] ?? '')) ?></code></div>
            <small class="text-secondary"><?= rkw_h((string)($r['note'] ?? '')) ?></small><br>
            <small class="text-secondary">By: <?= rkw_h((string)($r['created_by'] ?? '-')) ?> | Last edit: <?= rkw_h((string)($r['updated_by'] ?? '-')) ?></small>
          </td>
          <td>
            <div><?= rkw_h((string)($r['owner'] ?? '-')) ?></div>
            <small class="text-secondary"><?= rkw_h((string)($r['eta'] ?? '-')) ?></small>
          </td>
          <td><span class="badge text-bg-secondary"><?= rkw_h($st) ?></span></td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <?php foreach (['OPEN', 'IN_PROGRESS', 'DONE', 'BLOCKED'] as $to): ?>
                <?php if ($to !== $st): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= rkw_h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="id" value="<?= rkw_h($id) ?>">
                  <input type="hidden" name="to_status" value="<?= rkw_h($to) ?>">
                  <button class="btn btn-sm btn-outline-light"><?= rkw_h($to) ?></button>
                </form>
                <?php endif; ?>
              <?php endforeach; ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus temuan ini?')">
                <input type="hidden" name="csrf_token" value="<?= rkw_h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= rkw_h($id) ?>">
                <button class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="rmi-card p-3 mt-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">Audit Activity (Recent)</div>
    <div class="small rmi-muted">Sumber: <code>storage/audit/review_kit_activity.jsonl</code></div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-dark table-hover mb-0">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>User</th>
          <th>Aksi</th>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$recentActivity): ?>
        <tr><td colspan="4" class="text-center text-muted py-3">Belum ada aktivitas audit.</td></tr>
      <?php endif; ?>
      <?php foreach ($recentActivity as $act): ?>
        <?php
          $ts = (string)($act['ts'] ?? '');
          $u = (string)($act['user'] ?? '-');
          $a = (string)($act['action'] ?? '-');
          $meta = isset($act['meta']) && is_array($act['meta']) ? json_encode($act['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        ?>
        <tr>
          <td><small><?= rkw_h($ts) ?></small></td>
          <td><?= rkw_h($u) ?></td>
          <td><span class="badge text-bg-secondary"><?= rkw_h($a) ?></span></td>
          <td><code><?= rkw_h((string)$meta) ?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var btn = document.getElementById('rkwCopyRoadmapBtn');
  var msg = document.getElementById('rkwCopyRoadmapMsg');
  var ta = document.getElementById('rkwRoadmapPlainText');
  if (!btn || !ta) return;
  btn.addEventListener('click', function () {
    var text = ta.value || '';
    if (!text) {
      if (msg) msg.textContent = 'Roadmap kosong.';
      return;
    }
    var done = function (ok) {
      if (msg) msg.textContent = ok ? 'Copied.' : 'Copy gagal, silakan copy manual dari file.';
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { done(true); }).catch(function () {
        ta.focus();
        ta.select();
        try { done(document.execCommand('copy')); } catch (e) { done(false); }
      });
      return;
    }
    ta.focus();
    ta.select();
    try { done(document.execCommand('copy')); } catch (e) { done(false); }
  });
})();
</script>
<?php rmi_footer(); ?>

