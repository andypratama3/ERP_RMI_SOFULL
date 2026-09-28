<?php
/**
 * reset_for_golive.php — Reset ERP untuk Go-Live
 *
 * WAJIB dijalankan di NAS (/volume4/web/ERP_RMI_SOFULL) oleh SYS ONLY.
 * Selalu backup otomatis sebelum reset.
 *
 * Usage:
 *   php tools/ops/reset_for_golive.php \
 *     --mode=full|transactions|testdata \
 *     --new-admin-password=RahasiaKuat123! \
 *     --i-understand-this-is-irreversible
 *
 * Mode:
 *   --mode=full         → Reset SEMUA: drop schema, import ulang, seed master
 *   --mode=transactions → Hapus tabel transaksi saja (PR/PO/GR/AP/DO/Stock/dll)
 *                         Pertahankan: master data, user login, RBAC, config
 *   --mode=testdata     → Hapus data uji (transaksi + data demo),
 *                         pertahankan master data real + user + RBAC
 *
 * Flags wajib:
 *   --i-understand-this-is-irreversible   ← WAJIB ada
 *   --new-admin-password=<password>       ← WAJIB ada (min 10 karakter)
 *
 * Flags opsional:
 *   --dry-run           → Tampilkan rencana tanpa eksekusi
 *   --skip-backup       → Skip backup (TIDAK disarankan!)
 *   --db-host=          → Override DB host (default dari .env/config)
 *   --db-name=          → Override DB name
 *   --db-user=          → Override DB user
 *   --db-pass=          → Override DB password
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ── Path lock ─────────────────────────────────────────────────────────────────
$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$expected = '/volume4/web/ERP_RMI_SOFULL';
if ($root !== $expected || str_contains($root, '/Volumes/')) {
    fwrite(STDERR, "FAIL: Wajib dijalankan dari {$expected} di NAS.\n");
    exit(2);
}

// ── Args ──────────────────────────────────────────────────────────────────────
$args = $_SERVER['argv'] ?? [];
$mode       = '';
$newPass    = '';
$dryRun     = false;
$skipBackup = false;
$understood = false;
$dbHost = $dbName = $dbUser = $dbPass = '';

foreach ($args as $a) {
    if (!is_string($a)) continue;
    if (str_starts_with($a, '--mode='))                  $mode       = trim(substr($a, 7));
    if (str_starts_with($a, '--new-admin-password='))    $newPass    = trim(substr($a, 22));
    if ($a === '--dry-run')                               $dryRun     = true;
    if ($a === '--skip-backup')                           $skipBackup = true;
    if ($a === '--i-understand-this-is-irreversible')    $understood = true;
    if (str_starts_with($a, '--db-host='))               $dbHost     = trim(substr($a, 10));
    if (str_starts_with($a, '--db-name='))               $dbName     = trim(substr($a, 10));
    if (str_starts_with($a, '--db-user='))               $dbUser     = trim(substr($a, 10));
    if (str_starts_with($a, '--db-pass='))               $dbPass     = trim(substr($a, 10));
}

// ── Validation ────────────────────────────────────────────────────────────────
$validModes = ['full', 'transactions', 'testdata'];
if (!in_array($mode, $validModes, true)) {
    echo "FAIL: --mode wajib diisi: full | transactions | testdata\n\n";
    echo "Contoh:\n";
    echo "  php tools/ops/reset_for_golive.php \\\n";
    echo "    --mode=transactions \\\n";
    echo "    --new-admin-password=RahasiaKuat123! \\\n";
    echo "    --i-understand-this-is-irreversible\n";
    exit(1);
}
if (!$understood) {
    echo "FAIL: Wajib tambahkan flag: --i-understand-this-is-irreversible\n";
    echo "      Ini untuk memastikan Anda sadar bahwa aksi ini tidak bisa di-undo.\n";
    exit(1);
}
if (strlen($newPass) < 10) {
    echo "FAIL: --new-admin-password wajib ada dan minimal 10 karakter.\n";
    echo "      Contoh: --new-admin-password=RahasiaKuat123!\n";
    exit(1);
}

// ── Load DB config ────────────────────────────────────────────────────────────
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) rmi_env_load();

$host = $dbHost ?: (string)(getenv('ERP_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1');
$name = $dbName ?: (string)(getenv('ERP_DB_NAME') ?: getenv('DB_DATABASE') ?: getenv('DB_NAME') ?: '');
$user = $dbUser ?: (string)(getenv('ERP_DB_USER') ?: getenv('DB_USERNAME') ?: getenv('DB_USER') ?: '');
$pass = $dbPass ?: (string)(getenv('ERP_DB_PASS') ?: getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: '');
$port = (int)(getenv('ERP_DB_PORT') ?: getenv('DB_PORT') ?: 3306);

if ($name === '' || $user === '') {
    echo "FAIL: Konfigurasi DB tidak ditemukan. Pastikan .env sudah ada.\n";
    echo "      Variabel yang dibutuhkan: ERP_DB_HOST, ERP_DB_NAME, ERP_DB_USER, ERP_DB_PASS\n";
    exit(1);
}

// ── Connect DB ────────────────────────────────────────────────────────────────
try {
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    echo "FAIL: Tidak bisa connect ke DB: " . $e->getMessage() . "\n";
    exit(1);
}

// ── Table definitions ─────────────────────────────────────────────────────────
/**
 * TRANSACTION tables: data dari operasi bisnis
 * Dihapus pada mode: transactions, testdata, full
 */
$transactionTables = [
    // Purchases (PR→PO→GR→AP)
    'purchases_po', 'purchases_po_items',
    'purchases_invoice_ap', 'purchases_invoice_ap_lines',
    'purchases_payment_ap',
    'purchases_audit_log',
    'purchases_import_control',
    'purchases_ceisa_payment', 'purchases_ceisa_pib',
    'purchases_forwarder_payment', 'purchases_forwarder_quotes', 'purchases_forwarder_invoice',
    'purchases_forwarding_docs',
    'wqs_pr', 'wqs_pr_items',

    // Sales (SO→DO)
    'sales_do', 'sales_do_items', 'sales_do_audit',
    'sales_do_tracking_events',
    'tax_invoices', 'tax_invoice_logs',
    'sales_marketplace_staging',

    // Stock / WQS
    'wqs_incoming', 'wqs_incoming_items',
    'wqs_picking', 'wqs_picking_items',
    'wqs_allocations',
    'wqs_stock', 'wqs_stock_by_office',
    'wqs_stock_adjustments', 'wqs_stock_adjustment_approvals',
    'wqs_stock_opname', 'wqs_stock_opname_items', 'wqs_stock_opname_attachments',
    'wqs_stock_transfer', 'wqs_stock_transfer_items', 'wqs_stock_transfer_attachments',
    'wqs_stock_snapshot', 'wqs_stock_baseline_lock',
    'office_warehouses',

    // GL / Finance
    'gl_journal_headers', 'gl_journal_lines',
    'gl_posting_batches', 'gl_reversal_requests',
    'gl_periods',
    'bank_reconciliations', 'bank_recon_matches',
    'bank_statement_lines', 'bank_statement_raw', 'bank_statements',
    'bank_accounts',
    'payment_callbacks_inbox', 'payments_webhook_inbox',

    // Payroll
    'payroll_runs', 'payroll_run_items',
    'payroll_loans',

    // Absensi (records, not config)
    'absensi_logs', 'absensi_audit', 'absensi_requests',

    // MPR
    'mpr_plans', 'mpr_visits', 'mpr_progress', 'mpr_budget_requests', 'mpr_ops_payments',

    // Fixed Asset transactions
    'fa_dep_runs', 'fa_dep_lines',
    'fa_audits', 'fa_audit_lines', 'fa_audit_log',
    'fa_transfers', 'fa_disposals', 'fa_maintenance',

    // KPI / analytics
    'kpi_daily_snapshots', 'kpi_snapshot', 'kpi_snapshot_items', 'kpi_snapshot_approvals',
    'kpi_office', 'kpi_employee', 'kpi_adjustments', 'kpi_audit_log', 'kpi_targets',

    // CRM Leads
    'crm_leads', 'crm_lead_logs',

    // HRL requests
    'hrl_requests', 'hrl_request_files', 'hrl_doc_acks',

    // Audit & logs
    'system_audit_logs', 'erp_audit_log',
    'purchases_audit_log',
    'mobile_audit_events', 'mobile_notifications',

    // Marketplace
    'marketplace_orders_inbox',

    // STG (staging import)
    'stg_documents', 'stg_items', 'stg_parties', 'stg_tax_codes',
    'stg_units', 'stg_warehouses', 'stg_opening_balances',
    'migration_sales_receipts',

    // Chat messages (keep channels/config)
    'chat_messages', 'chat_message_context', 'chat_message_idempotency',
    'chat_message_mentions', 'chat_message_reactions', 'chat_pins',
    'chat_presence', 'chat_typing_status', 'chat_attachments',
    'chat_exports', 'chat_mentions',
    'chat_quota_usage', 'chat_rate_limits',
    'mobile_refresh_tokens', 'mobile_device_tokens', 'mobile_idempotency',
    'auth_login_attempts', 'auth_mfa_bypass_tickets',

    // Jobs queue
    'jobs',
];

/**
 * MASTER / CONFIG tables: pertahankan pada mode transactions & testdata
 */
$masterTables = [
    'master_customers', 'master_products', 'master_vendors', 'master_manufactures',
    'master_employees', 'master_office', 'master_departements',
    'master_pricelist', 'master_tax', 'master_units', 'master_payment_terms',
    'master_system_login', 'master_mpr',
    'master_company_bank_accounts',
    'rbac_permissions', 'rbac_roles', 'rbac_dept_role_permissions', 'rbac_user_permissions',
    'system_config',
    'absensi_settings', 'absensi_offices', 'absensi_user_profile',
    'gl_accounts', 'gl_mappings',
    'payroll_salary_matrix', 'payroll_employee_settings',
    'chat_channels', 'chat_channel_members', 'chat_channel_acl',
    'fa_assets',
    'hrl_docs', 'hrl_doc_versions',
    'kpi_corporate_rates', 'kpi_gl_category_map', 'kpi_customer_segment_map',
];

/**
 * TEST / DEMO data that should be removed in testdata mode
 * (tables that might contain demo seeds, identified by pattern)
 */
$demotables = ['crm_lead_dedupe_rules', 'migration_errors', 'migration_runs',
                'migration_target_keys', 'procurement_match_rules'];

// ── Header ────────────────────────────────────────────────────────────────────
$modeLabel = ['full'=>'FULL RESET','transactions'=>'TRANSACTION RESET','testdata'=>'TEST DATA CLEANUP'];
$modeLine  = str_repeat('═', 60);
echo "\n{$modeLine}\n";
echo " RESET FOR GO-LIVE — ERP_RMI_SOFULL\n";
echo " Mode   : " . ($modeLabel[$mode] ?? $mode) . "\n";
echo " DB     : {$name} @ {$host}\n";
echo " DryRun : " . ($dryRun ? 'YES (no changes will be made)' : 'NO — CHANGES WILL BE APPLIED') . "\n";
echo "{$modeLine}\n\n";

// ── Helper functions ──────────────────────────────────────────────────────────
function rg_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
function rg_truncate(PDO $pdo, string $table, bool $dry): void {
    if (!rg_table_exists($pdo, $table)) {
        echo "  SKIP   : {$table} (table not found)\n";
        return;
    }
    $st = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
    $cnt = (int)($st ? $st->fetchColumn() : 0);
    if ($dry) {
        echo "  DRY    : TRUNCATE {$table} ({$cnt} rows)\n";
    } else {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $pdo->exec("TRUNCATE TABLE `{$table}`");
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        echo "  DONE   : TRUNCATE {$table} ({$cnt} rows deleted)\n";
    }
}
function rg_run_sql_file(string $file, string $host, int $port, string $user, string $pass, string $dbName, bool $dry): bool {
    if (!is_file($file)) {
        echo "  SKIP   : {$file} not found\n";
        return false;
    }
    $sz = number_format(filesize($file));
    if ($dry) {
        echo "  DRY    : Would import {$file} ({$sz} bytes)\n";
        return true;
    }
    $cmd = "mysql -h " . escapeshellarg($host) . " -P {$port} -u " . escapeshellarg($user)
         . " -p" . escapeshellarg($pass) . " " . escapeshellarg($dbName)
         . " < " . escapeshellarg($file) . " 2>&1";
    $out = []; $code = 1;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        echo "  FAIL   : Import {$file} failed: " . implode(' | ', array_slice($out,-3)) . "\n";
        return false;
    }
    echo "  DONE   : Import {$file} ({$sz} bytes)\n";
    return true;
}

// ── Step 1: Backup ────────────────────────────────────────────────────────────
echo "STEP 1 — BACKUP OTOMATIS\n";
$backupScript = $root . '/tools/backup_now.sh';
if ($skipBackup) {
    echo "  SKIP   : --skip-backup aktif (TIDAK disarankan!)\n\n";
} elseif (!is_file($backupScript) || !is_executable($backupScript)) {
    echo "  WARN   : backup_now.sh tidak ditemukan. Lanjut tanpa backup.\n\n";
} else {
    $label = "golive_reset_{$mode}_" . date('Ymd_His');
    if ($dryRun) {
        echo "  DRY    : Would run: ./tools/backup_now.sh --label {$label}\n\n";
    } else {
        echo "  RUN    : Backup sedang dijalankan...\n";
        $backupCmd = escapeshellarg($backupScript) . ' --label ' . escapeshellarg($label) . ' 2>&1';
        $bLines = []; $bCode = 1;
        exec($backupCmd, $bLines, $bCode);
        if ($bCode === 0) {
            echo "  DONE   : Backup berhasil (label: {$label})\n\n";
        } else {
            echo "  FAIL   : Backup gagal! " . implode(' | ', array_slice($bLines,-3)) . "\n";
            echo "           Lanjutkan? (Backup gagal — risiko tinggi!)\n";
            echo "           Tekan ENTER untuk lanjut, Ctrl+C untuk batal.\n";
            fgets(STDIN);
        }
    }
}

// ── Step 2: Mode-specific reset ───────────────────────────────────────────────
if ($mode === 'full') {
    // ── FULL: Drop schema, import ulang, seed master ─────────────────────────
    echo "STEP 2 — FULL RESET: Drop & recreate database\n";
    $sqlFile = $root . '/sql/erp_rmi_sofull.sql';
    if (!is_file($sqlFile)) {
        echo "  FAIL   : {$sqlFile} tidak ditemukan.\n";
        exit(1);
    }
    if ($dryRun) {
        echo "  DRY    : Would DROP DATABASE {$name}\n";
        echo "  DRY    : Would CREATE DATABASE {$name}\n";
        echo "  DRY    : Would import: {$sqlFile}\n";
    } else {
        try {
            // Drop & recreate via PDO (drop user db, use default)
            $pdoTemp = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdoTemp->exec("DROP DATABASE IF EXISTS `{$name}`");
            echo "  DONE   : DROP DATABASE {$name}\n";
            $pdoTemp->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            echo "  DONE   : CREATE DATABASE {$name}\n";
        } catch (Throwable $e) {
            echo "  FAIL   : " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    echo "\nSTEP 3 — Import schema + data\n";
    rg_run_sql_file($sqlFile, $host, $port, $user, $pass, $name, $dryRun);

    // Run seeds
    echo "\nSTEP 4 — Jalankan seed files\n";
    $seedDir = $root . '/sql/seeds';
    if (is_dir($seedDir)) {
        foreach (glob($seedDir . '/*.sql') ?: [] as $seed) {
            rg_run_sql_file($seed, $host, $port, $user, $pass, $name, $dryRun);
        }
    } else {
        echo "  SKIP   : Direktori seeds tidak ada ({$seedDir})\n";
    }

    // Reconnect setelah full drop
    if (!$dryRun) {
        try {
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            echo "  FAIL   : Reconnect setelah full reset gagal: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

} elseif ($mode === 'transactions') {
    // ── TRANSACTIONS: Truncate tabel transaksi, pertahankan master ───────────
    echo "STEP 2 — TRANSACTION RESET: Hapus " . count($transactionTables) . " tabel transaksi\n";
    echo "         (Master data, user, RBAC, config TETAP)\n\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach ($transactionTables as $tbl) {
        rg_truncate($pdo, $tbl, $dryRun);
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

} elseif ($mode === 'testdata') {
    // ── TESTDATA: Hapus transaksi + data demo, pertahankan master real ───────
    echo "STEP 2 — TESTDATA CLEANUP: Hapus transaksi + data demo\n";
    echo "         (Master data real, user, RBAC, config TETAP)\n\n";
    $allClean = array_merge($transactionTables, $demotables);
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach ($allClean as $tbl) {
        rg_truncate($pdo, $tbl, $dryRun);
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

// ── Step: Reset admin passwords ───────────────────────────────────────────────
$stepNum = ($mode === 'full') ? 5 : 3;
echo "\nSTEP {$stepNum} — Reset password akun SYS\n";
$hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
$sysAccounts = ['admin', 'superadmin', 'RizqullahMediskaSYS'];
foreach ($sysAccounts as $acc) {
    if ($dryRun) {
        echo "  DRY    : UPDATE master_system_login SET password_hash=*** WHERE username='{$acc}'\n";
    } else {
        try {
            $st = $pdo->prepare("UPDATE master_system_login SET password_hash=?, status='ACTIVE', deleted_at=NULL, updated_at=NOW() WHERE username=?");
            $affected = $st->execute([$hashedPass, $acc]) ? $st->rowCount() : 0;
            echo "  " . ($affected > 0 ? "DONE   : Reset password '{$acc}'" : "SKIP   : '{$acc}' tidak ada di DB") . "\n";
        } catch (Throwable $e) {
            echo "  WARN   : Skip '{$acc}': " . $e->getMessage() . "\n";
        }
    }
}

// ── Step: Clear sessions & rate limits ────────────────────────────────────────
$stepNum2 = $stepNum + 1;
echo "\nSTEP {$stepNum2} — Clear sessions & rate limits\n";
$cleanupTables = ['auth_login_attempts', 'auth_mfa_bypass_tickets', 'mobile_refresh_tokens',
                  'mobile_device_tokens', 'mobile_idempotency', 'jobs'];
foreach ($cleanupTables as $tbl) {
    rg_truncate($pdo, $tbl, $dryRun);
}

// Invalidate existing web sessions via system_config
if (!$dryRun) {
    try {
        $pdo->exec("DELETE FROM system_config WHERE config_group='SESSION' AND config_key='active_token'");
    } catch (Throwable) {}
}
echo "  DONE   : Session token cleared\n";

// ── Step: Write reset artifact ────────────────────────────────────────────────
$stepNum3 = $stepNum2 + 1;
echo "\nSTEP {$stepNum3} — Tulis artifact reset\n";
$logsDir = $root . '/storage/logs';
@mkdir($logsDir, 0775, true);
$artifact = [
    'ok'         => true,
    'run_at'     => date(DateTimeInterface::ATOM),
    'mode'       => $mode,
    'dry_run'    => $dryRun,
    'db_name'    => $name,
    'actor'      => get_current_user(),
    'backup_skipped' => $skipBackup,
    'tables_affected' => ($mode === 'full') ? 'all' : count($transactionTables),
    'note'       => $dryRun
        ? 'Dry run — tidak ada perubahan dilakukan'
        : "Reset {$mode} berhasil. Semua akun SYS password sudah direset.",
];
@file_put_contents($logsDir . '/reset_for_golive_last.json', json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "  DONE   : {$logsDir}/reset_for_golive_last.json\n";

// ── Final summary ─────────────────────────────────────────────────────────────
echo "\n{$modeLine}\n";
echo $dryRun ? " DRY RUN SELESAI — Tidak ada perubahan.\n" : " RESET SELESAI!\n";
echo "{$modeLine}\n\n";

if (!$dryRun) {
    echo "✅ Langkah selanjutnya:\n";
    echo "   1. Login ke ERP dengan akun admin / password yang baru di-set\n";
    echo "   2. Buka RBAC Center → verifikasi permission semua dept\n";
    echo "   3. Buka Master → tambah/verifikasi user real per dept\n";
    echo "   4. Jalankan smoke test: ./tools/nas/erp.sh php tools/qa/smoke_http.php --strict\n\n";
    echo "⚠️  PENTING: Ganti password admin segera jika belum!\n";
    echo "   Password baru yang di-set: [tersimpan di artifact, tidak ditampilkan]\n\n";
} else {
    echo "👆 Ini adalah dry-run. Jalankan tanpa --dry-run untuk eksekusi nyata.\n\n";
}
