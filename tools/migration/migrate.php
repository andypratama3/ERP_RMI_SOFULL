<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden — CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../../_shared/bootstrap.php';
require_once __DIR__ . '/../../_shared/erp_audit.php';
require_once __DIR__ . '/../../app/Accounting/GLPostingService.php';

use App\Accounting\GLPostingService;

const SUPPORTED_SOURCES = ['legacy', 'accurate'];
const SUPPORTED_MODES = ['cutover', 'full-history'];

main($argv);

function main(array $argv): void
{
    $opts = getopt('', [
        'source:',
        'dry-run',
        'batch-size::',
        'from-date::',
        'to-date::',
        'mode::',
        'input-dir::',
        'help',
    ]);

    if (isset($opts['help'])) {
        printHelp();
        return;
    }

    $source = strtolower((string)($opts['source'] ?? ''));
    if (!in_array($source, SUPPORTED_SOURCES, true)) {
        fwrite(STDERR, "ERROR: --source wajib diisi: legacy|accurate\n");
        exit(1);
    }

    $mode = strtolower((string)($opts['mode'] ?? 'cutover'));
    if (!in_array($mode, SUPPORTED_MODES, true)) {
        fwrite(STDERR, "ERROR: --mode hanya support cutover|full-history\n");
        exit(1);
    }

    $isDryRun = array_key_exists('dry-run', $opts);
    $batchSize = max(1, (int)($opts['batch-size'] ?? 200));
    $fromDate = normalizeDateOrNull($opts['from-date'] ?? null);
    $toDate = normalizeDateOrNull($opts['to-date'] ?? null);
    if (($opts['from-date'] ?? null) !== null && $fromDate === null) {
        fwrite(STDERR, "ERROR: --from-date format harus YYYY-MM-DD\n");
        exit(1);
    }
    if (($opts['to-date'] ?? null) !== null && $toDate === null) {
        fwrite(STDERR, "ERROR: --to-date format harus YYYY-MM-DD\n");
        exit(1);
    }
    if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
        fwrite(STDERR, "ERROR: --from-date tidak boleh lebih besar dari --to-date\n");
        exit(1);
    }

    $inputDir = (string)($opts['input-dir'] ?? (__DIR__ . '/input/' . $source));
    $configPath = __DIR__ . '/config/' . $source . '.mapping.json';

    if (!is_file($configPath)) {
        fwrite(STDERR, "ERROR: mapping config tidak ditemukan: {$configPath}\n");
        exit(1);
    }
    $mappingConfig = json_decode((string)file_get_contents($configPath), true);
    if (!is_array($mappingConfig)) {
        fwrite(STDERR, "ERROR: mapping config tidak valid JSON\n");
        exit(1);
    }

    $pdo = rmi_db_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureMigrationBaseTables($pdo);
    erp_audit_ensure($pdo);

    $fileHash = computeInputHash($inputDir);
    $runId = createRun($pdo, [
        'source' => $source,
        'mode' => $mode,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'is_dry_run' => $isDryRun ? 1 : 0,
        'file_hash' => $fileHash,
    ]);

    $summary = [
        'source' => $source,
        'mode' => $mode,
        'dry_run' => $isDryRun,
        'batch_size' => $batchSize,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'entities' => [],
        'errors' => 0,
    ];

    $errors = [];
    $services = [
        'gl' => new GLPostingService(),
    ];

    try {
        ensureTargetMetadataColumns($pdo);

        $pipeline = [
            ['entity' => 'coa', 'stage' => 'stg_coa', 'type' => 'master'],
            ['entity' => 'tax_codes', 'stage' => 'stg_tax_codes', 'type' => 'master'],
            ['entity' => 'customers', 'stage' => 'stg_parties', 'type' => 'master'],
            ['entity' => 'vendors', 'stage' => 'stg_parties', 'type' => 'master'],
            ['entity' => 'units', 'stage' => 'stg_units', 'type' => 'master'],
            ['entity' => 'warehouses', 'stage' => 'stg_warehouses', 'type' => 'master'],
            ['entity' => 'items', 'stage' => 'stg_items', 'type' => 'master'],
            ['entity' => 'opening_gl', 'stage' => 'stg_opening_balances', 'type' => 'opening'],
            ['entity' => 'opening_ar', 'stage' => 'stg_opening_balances', 'type' => 'opening'],
            ['entity' => 'opening_ap', 'stage' => 'stg_opening_balances', 'type' => 'opening'],
            ['entity' => 'opening_stock', 'stage' => 'stg_opening_balances', 'type' => 'opening'],
            ['entity' => 'sales_invoices', 'stage' => 'stg_documents', 'type' => 'txn'],
            ['entity' => 'sales_payments', 'stage' => 'stg_documents', 'type' => 'txn'],
            ['entity' => 'purchase_invoices', 'stage' => 'stg_documents', 'type' => 'txn'],
            ['entity' => 'purchase_payments', 'stage' => 'stg_documents', 'type' => 'txn'],
            ['entity' => 'general_journal', 'stage' => 'stg_documents', 'type' => 'txn'],
            ['entity' => 'inventory_adjustments', 'stage' => 'stg_documents', 'type' => 'txn'],
        ];

        foreach ($pipeline as $step) {
            $entity = $step['entity'];
            $rows = extractRows($inputDir, $mappingConfig, $source, $entity);
            $result = processEntity(
                $pdo,
                $services,
                $runId,
                $source,
                $entity,
                $step['stage'],
                $rows,
                $mode,
                $isDryRun,
                $batchSize,
                $fromDate,
                $toDate,
                $errors
            );
            $summary['entities'][$entity] = $result;
            echo sprintf(
                "[%s] staged=%d loaded=%d skipped=%d errors=%d\n",
                $entity,
                $result['staged'],
                $result['loaded'],
                $result['skipped'],
                $result['errors']
            );
        }

        $summary['errors'] = count($errors);
        foreach ($errors as $err) {
            insertMigrationError($pdo, $runId, $err['entity'], $err['source_key'], $err['error'], $err['payload']);
        }

        finishRun($pdo, $runId, 'DONE', $summary);
        erp_audit($pdo, 'MIGRATION', 'RUN#' . $runId, 'DONE', $summary);
        echo "Migration run completed. run_id={$runId}, errors=" . count($errors) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        $summary['fatal'] = $e->getMessage();
        finishRun($pdo, $runId, 'FAILED', $summary);
        erp_audit($pdo, 'MIGRATION', 'RUN#' . $runId, 'FAILED', $summary);
        fwrite(STDERR, "FATAL: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

function printHelp(): void
{
    echo "Usage:\n";
    echo "  php tools/migration/migrate.php --source=legacy|accurate [--dry-run] [--batch-size=200] [--from-date=YYYY-MM-DD] [--to-date=YYYY-MM-DD] [--mode=cutover|full-history] [--input-dir=/path]\n";
    echo "\n";
    echo "Default mode: cutover (recommended)\n";
    echo "Default input dir: tools/migration/input/{source}\n";
}

function normalizeDateOrNull($value): ?string
{
    $v = trim((string)$value);
    if ($v === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt || $dt->format('Y-m-d') !== $v) {
        return null;
    }
    return $v;
}

function ensureMigrationBaseTables(PDO $pdo): void
{
    $tables = [
        'migration_runs',
        'migration_errors',
        'migration_target_keys',
        'stg_coa',
        'stg_tax_codes',
        'stg_parties',
        'stg_items',
        'stg_units',
        'stg_warehouses',
        'stg_opening_balances',
        'stg_documents',
        'map_accounts',
        'map_customers',
        'map_vendors',
        'map_items',
        'map_warehouses',
    ];
    foreach ($tables as $table) {
        if (!tableExists($pdo, $table)) {
            throw new RuntimeException("Table '{$table}' belum ada. Jalankan sql/migrations/084_migration_layer.sql dulu.");
        }
    }
}

function ensureTargetMetadataColumns(PDO $pdo): void
{
    $targets = [
        'gl_accounts',
        'master_tax',
        'master_customers',
        'master_vendors',
        'master_products',
        'master_office',
        'master_units',
        'sales_do',
        'purchases_invoice_ap',
        'purchases_payment_ap',
        'gl_journal_headers',
        'wqs_stock_adjustments',
        'migration_sales_receipts',
    ];
    foreach ($targets as $table) {
        if (!tableExists($pdo, $table)) {
            continue;
        }
        ensureColumn($pdo, $table, 'source_system', "VARCHAR(30) NULL");
        ensureColumn($pdo, $table, 'source_key', "VARCHAR(190) NULL");
        ensureColumn($pdo, $table, 'migration_run_id', "BIGINT NULL");
        ensureColumn($pdo, $table, 'migrated_at', "DATETIME NULL");
        ensureUniqueSourceIndex($pdo, $table);
    }
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $st->execute([$table, $column]);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function ensureUniqueSourceIndex(PDO $pdo, string $table): void
{
    $idxName = 'uq_src_system_key';
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?"
    );
    $st->execute([$table, $idxName]);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }

    $dupCheck = $pdo->query(
        "SELECT COUNT(*) AS cnt
         FROM (
           SELECT source_system, source_key, COUNT(*) c
           FROM `{$table}`
           WHERE source_system IS NOT NULL AND source_key IS NOT NULL
           GROUP BY source_system, source_key
           HAVING COUNT(*) > 1
         ) d"
    )->fetchColumn();
    if ((int)$dupCheck > 0) {
        return; // skip index creation if legacy duplicates exist.
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$idxName}` (`source_system`, `source_key`)");
}

function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $st->execute([$table]);
    return ((int)$st->fetchColumn()) > 0;
}

function createRun(PDO $pdo, array $run): int
{
    $st = $pdo->prepare(
        "INSERT INTO migration_runs
        (source, mode, from_date, to_date, is_dry_run, status, file_hash, summary_json, started_at, executed_by)
        VALUES (?, ?, ?, ?, ?, 'RUNNING', ?, '{}', NOW(), ?)"
    );
    $st->execute([
        $run['source'],
        $run['mode'],
        $run['from_date'],
        $run['to_date'],
        $run['is_dry_run'],
        $run['file_hash'],
        get_current_user() ?: (getenv('USER') ?: 'cli'),
    ]);
    return (int)$pdo->lastInsertId();
}

function finishRun(PDO $pdo, int $runId, string $status, array $summary): void
{
    $st = $pdo->prepare(
        "UPDATE migration_runs
         SET status=?, finished_at=NOW(), summary_json=?
         WHERE id=?"
    );
    $st->execute([$status, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $runId]);
}

function insertMigrationError(PDO $pdo, int $runId, string $entity, string $sourceKey, string $error, array $payload): void
{
    $st = $pdo->prepare(
        "INSERT INTO migration_errors (run_id, entity, source_key, error_message, payload_json, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $st->execute([
        $runId,
        $entity,
        $sourceKey !== '' ? $sourceKey : null,
        mb_substr($error, 0, 1000),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function processEntity(
    PDO $pdo,
    array $services,
    int $runId,
    string $source,
    string $entity,
    string $stageTable,
    array $rows,
    string $mode,
    bool $isDryRun,
    int $batchSize,
    ?string $fromDate,
    ?string $toDate,
    array &$errors
): array {
    $result = ['staged' => 0, 'loaded' => 0, 'skipped' => 0, 'errors' => 0, 'rows' => count($rows)];
    if (count($rows) === 0) {
        return $result;
    }

    $chunks = array_chunk($rows, $batchSize);
    foreach ($chunks as $chunk) {
        $pdo->beginTransaction();
        try {
            foreach ($chunk as $row) {
                $sourceKey = (string)($row['source_key'] ?? '');
                try {
                    stageRow($pdo, $runId, $source, $entity, $stageTable, $row);
                    $result['staged']++;

                    if (shouldSkipByDateWindow($entity, $row, $mode, $fromDate, $toDate)) {
                        $result['skipped']++;
                        continue;
                    }
                    if ($isDryRun) {
                        $result['skipped']++;
                        continue;
                    }

                    $loaded = loadToTarget($pdo, $services, $runId, $source, $entity, $row);
                    if ($loaded) {
                        $result['loaded']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (Throwable $e) {
                    $result['errors']++;
                    $errors[] = [
                        'entity' => $entity,
                        'source_key' => $sourceKey,
                        'error' => $e->getMessage(),
                        'payload' => $row,
                    ];
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    return $result;
}

function shouldSkipByDateWindow(string $entity, array $row, string $mode, ?string $fromDate, ?string $toDate): bool
{
    $date = normalizeDateOrNull($row['doc_date'] ?? ($row['balance_date'] ?? null));
    if ($date === null) {
        return false;
    }

    if ($toDate !== null && $date > $toDate) {
        return true;
    }

    // Cutover mode: transaksi sebelum from_date tidak di-load sebagai dokumen (opening only).
    if ($mode === 'cutover' && isTransactionalEntity($entity) && $fromDate !== null && $date < $fromDate) {
        return true;
    }

    if ($fromDate !== null && isOpeningEntity($entity) && $date > $fromDate) {
        return true;
    }

    return false;
}

function isTransactionalEntity(string $entity): bool
{
    return in_array($entity, [
        'sales_invoices',
        'sales_payments',
        'purchase_invoices',
        'purchase_payments',
        'general_journal',
        'inventory_adjustments',
    ], true);
}

function isOpeningEntity(string $entity): bool
{
    return in_array($entity, ['opening_gl', 'opening_ar', 'opening_ap', 'opening_stock'], true);
}

function stageRow(PDO $pdo, int $runId, string $source, string $entity, string $stageTable, array $row): void
{
    if ($stageTable === 'stg_parties') {
        $entityType = $entity === 'customers' ? 'CUSTOMER' : 'VENDOR';
        upsertBySource(
            $pdo,
            'stg_parties',
            $source,
            (string)$row['source_key'],
            [
                'run_id' => $runId,
                'source_system' => $source,
                'entity_type' => $entityType,
                'source_id' => (string)$row['source_id'],
                'source_key' => (string)$row['source_key'],
                'party_code' => (string)($row['party_code'] ?? ''),
                'party_name' => (string)($row['party_name'] ?? ''),
                'npwp' => nullOrString($row['npwp'] ?? null),
                'email' => nullOrString($row['email'] ?? null),
                'phone' => nullOrString($row['phone'] ?? null),
                'address' => nullOrString($row['address'] ?? null),
                'city' => nullOrString($row['city'] ?? null),
                'status' => (string)($row['status'] ?? 'active'),
                'raw_payload_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        return;
    }

    if ($stageTable === 'stg_opening_balances') {
        $entityTypeMap = [
            'opening_gl' => 'GL',
            'opening_ar' => 'AR',
            'opening_ap' => 'AP',
            'opening_stock' => 'STOCK',
        ];
        upsertBySource(
            $pdo,
            'stg_opening_balances',
            $source,
            (string)$row['source_key'],
            [
                'run_id' => $runId,
                'source_system' => $source,
                'entity_type' => $entityTypeMap[$entity] ?? 'GL',
                'source_key' => (string)$row['source_key'],
                'balance_date' => (string)($row['balance_date'] ?? date('Y-m-d')),
                'ref_code' => nullOrString($row['ref_code'] ?? null),
                'account_code' => nullOrString($row['account_code'] ?? null),
                'party_code' => nullOrString($row['party_code'] ?? null),
                'item_code' => nullOrString($row['item_code'] ?? null),
                'warehouse_code' => nullOrString($row['warehouse_code'] ?? null),
                'qty' => (float)($row['qty'] ?? 0),
                'amount' => (float)($row['amount'] ?? 0),
                'currency' => (string)($row['currency'] ?? 'IDR'),
                'raw_payload_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        return;
    }

    if ($stageTable === 'stg_documents') {
        $docTypeMap = [
            'sales_invoices' => 'SALES_INVOICE',
            'sales_payments' => 'SALES_PAYMENT',
            'purchase_invoices' => 'PURCHASE_INVOICE',
            'purchase_payments' => 'PURCHASE_PAYMENT',
            'general_journal' => 'GENERAL_JOURNAL',
            'inventory_adjustments' => 'INVENTORY_ADJUSTMENT',
        ];
        upsertBySource(
            $pdo,
            'stg_documents',
            $source,
            (string)$row['source_key'],
            [
                'run_id' => $runId,
                'source_system' => $source,
                'doc_type' => $docTypeMap[$entity] ?? 'GENERAL_JOURNAL',
                'source_key' => (string)$row['source_key'],
                'doc_no' => (string)($row['doc_no'] ?? ''),
                'doc_date' => (string)($row['doc_date'] ?? date('Y-m-d')),
                'party_code' => nullOrString($row['party_code'] ?? null),
                'warehouse_code' => nullOrString($row['warehouse_code'] ?? null),
                'item_code' => nullOrString($row['item_code'] ?? null),
                'account_code' => nullOrString($row['account_code'] ?? null),
                'qty' => (float)($row['qty'] ?? 0),
                'amount' => (float)($row['amount'] ?? 0),
                'tax_amount' => (float)($row['tax_amount'] ?? 0),
                'currency' => (string)($row['currency'] ?? 'IDR'),
                'status' => nullOrString($row['status'] ?? null),
                'raw_payload_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        return;
    }

    upsertBySource(
        $pdo,
        $stageTable,
        $source,
        (string)$row['source_key'],
        array_merge(
            [
                'run_id' => $runId,
                'source_system' => $source,
                'source_id' => (string)($row['source_id'] ?? ''),
                'source_key' => (string)$row['source_key'],
            ],
            buildStagePayload($stageTable, $row)
        )
    );
}

function buildStagePayload(string $table, array $row): array
{
    if ($table === 'stg_coa') {
        return [
            'account_code' => (string)$row['account_code'],
            'account_name' => (string)$row['account_name'],
            'account_type' => (string)$row['account_type'],
            'parent_code' => nullOrString($row['parent_code'] ?? null),
            'is_postable' => (int)($row['is_postable'] ?? 1),
            'status' => (string)($row['status'] ?? 'ACTIVE'),
            'currency' => nullOrString($row['currency'] ?? null),
            'raw_payload_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
    if ($table === 'stg_tax_codes') {
        return [
            'tax_code' => (string)$row['tax_code'],
            'tax_name' => (string)$row['tax_name'],
            'tax_type' => (string)($row['tax_type'] ?? 'PPN'),
            'rate_percent' => (float)($row['rate_percent'] ?? 0),
            'status' => (string)($row['status'] ?? 'active'),
            'raw_payload_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
    if ($table === 'stg_items') {
        return [
            'item_code' => (string)$row['item_code'],
            'item_name' => (string)$row['item_name'],
            'unit_code' => nullOrString($row['unit_code'] ?? null),
            'category' => nullOrString($row['category'] ?? null),
            'price' => (float)($row['price'] ?? 0),
            'status' => (string)($row['status'] ?? 'active'),
            'raw_payload_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
    if ($table === 'stg_units') {
        return [
            'unit_code' => (string)$row['unit_code'],
            'unit_name' => (string)$row['unit_name'],
            'raw_payload_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
    if ($table === 'stg_warehouses') {
        return [
            'warehouse_code' => (string)$row['warehouse_code'],
            'warehouse_name' => (string)$row['warehouse_name'],
            'city' => nullOrString($row['city'] ?? null),
            'address' => nullOrString($row['address'] ?? null),
            'is_active' => (int)($row['is_active'] ?? 1),
            'raw_payload_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
    return [];
}

function upsertBySource(PDO $pdo, string $table, string $source, string $sourceKey, array $payload): void
{
    $check = $pdo->prepare("SELECT id FROM `{$table}` WHERE source_system=? AND source_key=? LIMIT 1");
    $check->execute([$source, $sourceKey]);
    $id = (int)$check->fetchColumn();

    if ($id > 0) {
        $fields = [];
        $params = [];
        foreach ($payload as $k => $v) {
            if ($k === 'source_system' || $k === 'source_key') {
                continue;
            }
            $fields[] = "`{$k}` = ?";
            $params[] = $v;
        }
        $params[] = $source;
        $params[] = $sourceKey;
        $sql = "UPDATE `{$table}` SET " . implode(', ', $fields) . " WHERE source_system=? AND source_key=?";
        $pdo->prepare($sql)->execute($params);
        return;
    }

    $cols = array_keys($payload);
    $marks = array_fill(0, count($cols), '?');
    $sql = "INSERT INTO `{$table}` (" . implode(',', array_map(static fn($c) => "`{$c}`", $cols)) . ")
            VALUES (" . implode(',', $marks) . ")";
    $pdo->prepare($sql)->execute(array_values($payload));
}

function loadToTarget(PDO $pdo, array $services, int $runId, string $source, string $entity, array $row): bool
{
    return match ($entity) {
        'coa' => loadCoa($pdo, $runId, $source, $row),
        'tax_codes' => loadTaxCode($pdo, $runId, $source, $row),
        'customers' => loadCustomer($pdo, $runId, $source, $row),
        'vendors' => loadVendor($pdo, $runId, $source, $row),
        'units' => loadUnit($pdo, $runId, $source, $row),
        'warehouses' => loadWarehouse($pdo, $runId, $source, $row),
        'items' => loadItem($pdo, $runId, $source, $row),
        'opening_gl' => loadOpeningGl($pdo, $runId, $source, $row),
        'opening_ar' => loadOpeningArAp($pdo, $runId, $source, $row, true),
        'opening_ap' => loadOpeningArAp($pdo, $runId, $source, $row, false),
        'opening_stock' => loadOpeningStock($pdo, $runId, $source, $row),
        'sales_invoices' => loadSalesInvoice($pdo, $runId, $source, $row),
        'sales_payments' => loadSalesPayment($pdo, $runId, $source, $row),
        'purchase_invoices' => loadPurchaseInvoice($pdo, $runId, $source, $row, $services['gl']),
        'purchase_payments' => loadPurchasePayment($pdo, $runId, $source, $row),
        'general_journal' => loadGeneralJournal($pdo, $runId, $source, $row),
        'inventory_adjustments' => loadInventoryAdjustment($pdo, $runId, $source, $row),
        default => false,
    };
}

function loadCoa(PDO $pdo, int $runId, string $source, array $row): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'gl_accounts', $source, $srcKey)) {
        return true;
    }
    $accountType = strtoupper((string)($row['account_type'] ?? 'ASSET'));
    if (!in_array($accountType, ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'], true)) {
        $accountType = 'ASSET';
    }

    $check = $pdo->prepare("SELECT id FROM gl_accounts WHERE code=? LIMIT 1");
    $check->execute([(string)$row['account_code']]);
    $id = (int)$check->fetchColumn();
    if ($id > 0) {
        $pdo->prepare(
            "UPDATE gl_accounts
             SET name=?, account_type=?, is_postable=?, status=?, source_system=?, source_key=?, migration_run_id=?, migrated_at=NOW()
             WHERE id=?"
        )->execute([
            (string)$row['account_name'],
            $accountType,
            (int)($row['is_postable'] ?? 1),
            strtoupper((string)($row['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
            $source,
            $srcKey,
            $runId,
            $id,
        ]);
    } else {
        $pdo->prepare(
            "INSERT INTO gl_accounts (code,name,account_type,is_postable,status,source_system,source_key,migration_run_id,migrated_at,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())"
        )->execute([
            (string)$row['account_code'],
            (string)$row['account_name'],
            $accountType,
            (int)($row['is_postable'] ?? 1),
            strtoupper((string)($row['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
            $source,
            $srcKey,
            $runId,
        ]);
        $id = (int)$pdo->lastInsertId();
    }

    upsertMap($pdo, 'map_accounts', $source, (string)$row['source_id'], (string)$row['account_code'], 'target_account_id', $id);
    upsertTargetKey($pdo, 'gl_accounts', $id, $source, $srcKey, $runId);
    return true;
}

function loadTaxCode(PDO $pdo, int $runId, string $source, array $row): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'master_tax', $source, $srcKey)) {
        return true;
    }
    $check = $pdo->prepare("SELECT id FROM master_tax WHERE tax_code=? LIMIT 1");
    $check->execute([(string)$row['tax_code']]);
    $id = (int)$check->fetchColumn();
    if ($id > 0) {
        $pdo->prepare(
            "UPDATE master_tax
             SET tax_name=?, tax_type=?, rate_percent=?, status=?, source_system=?, source_key=?, migration_run_id=?, migrated_at=NOW(), updated_at=NOW()
             WHERE id=?"
        )->execute([
            (string)$row['tax_name'],
            (string)($row['tax_type'] ?? 'PPN'),
            (float)($row['rate_percent'] ?? 0),
            (string)($row['status'] ?? 'active'),
            $source,
            $srcKey,
            $runId,
            $id,
        ]);
    } else {
        $newId = nextIntId($pdo, 'master_tax');
        $pdo->prepare(
            "INSERT INTO master_tax
             (id,tax_code,tax_name,tax_type,rate_percent,level_type,office_scope,description,status,created_at,updated_at,source_system,source_key,migration_run_id,migrated_at)
             VALUES (?,?,?,?,?,'Transaction','All',? ,?,NOW(),NOW(),?,?,?,NOW())"
        )->execute([
            $newId,
            (string)$row['tax_code'],
            (string)$row['tax_name'],
            (string)($row['tax_type'] ?? 'PPN'),
            (float)($row['rate_percent'] ?? 0),
            'Imported from ' . $source,
            (string)($row['status'] ?? 'active'),
            $source,
            $srcKey,
            $runId,
        ]);
        $id = $newId;
    }
    upsertTargetKey($pdo, 'master_tax', $id, $source, $srcKey, $runId);
    return true;
}

function loadCustomer(PDO $pdo, int $runId, string $source, array $row): bool
{
    return loadParty($pdo, $runId, $source, $row, true);
}

function loadVendor(PDO $pdo, int $runId, string $source, array $row): bool
{
    return loadParty($pdo, $runId, $source, $row, false);
}

function loadParty(PDO $pdo, int $runId, string $source, array $row, bool $isCustomer): bool
{
    $table = $isCustomer ? 'master_customers' : 'master_vendors';
    $codeCol = $isCustomer ? 'customers_code' : 'vendors_code';
    $nameCol = $isCustomer ? 'customers_name' : 'vendors_name';
    $mapTable = $isCustomer ? 'map_customers' : 'map_vendors';
    $mapTargetCol = $isCustomer ? 'target_customer_id' : 'target_vendor_id';
    $srcKey = (string)$row['source_key'];

    if (isSourceExists($pdo, $table, $source, $srcKey)) {
        return true;
    }

    $partyCode = (string)($row['party_code'] ?: ('MIG-' . strtoupper(substr($source, 0, 3)) . '-' . (string)$row['source_id']));
    $st = $pdo->prepare("SELECT id FROM `{$table}` WHERE `{$codeCol}`=? LIMIT 1");
    $st->execute([$partyCode]);
    $id = (int)$st->fetchColumn();

    if ($id > 0) {
        if ($isCustomer) {
            $pdo->prepare(
                "UPDATE master_customers
                 SET customers_name=?, phone=?, email=?, npwp=?, address=?, city=?, status=?, source_system=?, source_key=?, migration_run_id=?, migrated_at=NOW(), updated_at=NOW()
                 WHERE id=?"
            )->execute([
                (string)$row['party_name'],
                nullOrString($row['phone'] ?? null),
                nullOrString($row['email'] ?? null),
                nullOrString($row['npwp'] ?? null),
                nullOrString($row['address'] ?? null),
                nullOrString($row['city'] ?? null),
                (string)($row['status'] ?? 'active'),
                $source,
                $srcKey,
                $runId,
                $id,
            ]);
        } else {
            $pdo->prepare(
                "UPDATE master_vendors
                 SET vendors_name=?, phone=?, email=?, npwp=?, address=?, city=?, status=?, source_system=?, source_key=?, migration_run_id=?, migrated_at=NOW(), updated_at=NOW()
                 WHERE id=?"
            )->execute([
                (string)$row['party_name'],
                nullOrString($row['phone'] ?? null),
                nullOrString($row['email'] ?? null),
                nullOrString($row['npwp'] ?? null),
                nullOrString($row['address'] ?? null),
                nullOrString($row['city'] ?? null),
                (string)($row['status'] ?? 'active'),
                $source,
                $srcKey,
                $runId,
                $id,
            ]);
        }
    } else {
        $newId = nextIntId($pdo, $table);
        if ($isCustomer) {
            $pdo->prepare(
                "INSERT INTO master_customers
                 (id,customers_code,customers_name,phone,email,npwp,address,city,status,created_at,updated_at,source_system,source_key,migration_run_id,migrated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW(),?,?,?,NOW())"
            )->execute([
                $newId,
                $partyCode,
                (string)$row['party_name'],
                nullOrString($row['phone'] ?? null),
                nullOrString($row['email'] ?? null),
                nullOrString($row['npwp'] ?? null),
                nullOrString($row['address'] ?? null),
                nullOrString($row['city'] ?? null),
                (string)($row['status'] ?? 'active'),
                $source,
                $srcKey,
                $runId,
            ]);
        } else {
            $pdo->prepare(
                "INSERT INTO master_vendors
                 (id,vendors_code,vendors_name,phone,email,npwp,address,city,status,created_at,updated_at,source_system,source_key,migration_run_id,migrated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW(),?,?,?,NOW())"
            )->execute([
                $newId,
                $partyCode,
                (string)$row['party_name'],
                nullOrString($row['phone'] ?? null),
                nullOrString($row['email'] ?? null),
                nullOrString($row['npwp'] ?? null),
                nullOrString($row['address'] ?? null),
                nullOrString($row['city'] ?? null),
                (string)($row['status'] ?? 'active'),
                $source,
                $srcKey,
                $runId,
            ]);
        }
        $id = $newId;
    }

    upsertMap($pdo, $mapTable, $source, (string)$row['source_id'], $partyCode, $mapTargetCol, $id);
    upsertTargetKey($pdo, $table, $id, $source, $srcKey, $runId);
    return true;
}

function loadUnit(PDO $pdo, int $runId, string $source, array $row): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'master_units', $source, $srcKey)) {
        return true;
    }
    $st = $pdo->prepare("SELECT id FROM master_units WHERE unit_code=? LIMIT 1");
    $st->execute([(string)$row['unit_code']]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) {
        $pdo->prepare(
            "UPDATE master_units
             SET unit_name=?, status=?, source_system=?, source_key=?, migration_run_id=?, migrated_at=NOW(), updated_at=NOW()
             WHERE id=?"
        )->execute([
            (string)$row['unit_name'],
            (string)($row['status'] ?? 'active'),
            $source,
            $srcKey,
            $runId,
            $id,
        ]);
    } else {
        $pdo->prepare(
            "INSERT INTO master_units (unit_code,unit_name,status,source_system,source_key,migration_run_id,migrated_at,created_at,updated_at)
             VALUES (?,?,?,?,?,?,NOW(),NOW(),NOW())"
        )->execute([
            (string)$row['unit_code'],
            (string)$row['unit_name'],
            (string)($row['status'] ?? 'active'),
            $source,
            $srcKey,
            $runId,
        ]);
        $id = (int)$pdo->lastInsertId();
    }
    upsertTargetKey($pdo, 'master_units', $id, $source, $srcKey, $runId);
    return true;
}

/**
 * Canonical office names that must not be overwritten
 * by external source labels (e.g. Accurate warehouse names).
 *
 * @return array<string,string>
 */
function canonicalOfficeNames(): array
{
    return [
        'BGR' => 'Rizqullah Mediska Indonesia',
        'BKS' => 'Rizqullah Mediska Indonesia Bekasi',
        'TGR' => 'Rizqullah Mediska Indonesia Tangerang',
        'BDG' => 'Rizqullah Mediska Indonesia Bandung',
        'SLO' => 'Rizqullah Mediska Indonesia Jawa Tengah (Solo)',
        'SMG' => 'Rizqullah Mediska Indonesia Semarang',
        'JGY' => 'Depo Yogyakarta',
        'KAL' => 'Depo Kalimantan',
        'SYS' => 'SYS',
    ];
}

function loadWarehouse(PDO $pdo, int $runId, string $source, array $row): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'master_office', $source, $srcKey)) {
        return true;
    }
    $officeCode = strtoupper((string)($row['warehouse_code'] ?: ('WH' . str_pad((string)$row['source_id'], 3, '0', STR_PAD_LEFT))));
    $canonical = canonicalOfficeNames();
    $isCanonicalOffice = array_key_exists($officeCode, $canonical);
    $officeName = $isCanonicalOffice ? (string)$canonical[$officeCode] : (string)$row['warehouse_name'];
    $st = $pdo->prepare("SELECT id FROM master_office WHERE office_code=? LIMIT 1");
    $st->execute([$officeCode]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) {
        if ($isCanonicalOffice) {
            // Protect official office naming from external warehouse labels.
            $pdo->prepare(
                "UPDATE master_office
                 SET office_name=?, source_system=?, source_key=?, migration_run_id=?, migrated_at=NOW(), updated_at=NOW()
                 WHERE id=?"
            )->execute([
                $officeName,
                $source,
                $srcKey,
                $runId,
                $id,
            ]);
        } else {
            $pdo->prepare(
                "UPDATE master_office
                 SET office_name=?, city=?, address=?, is_active=?, source_system=?, source_key=?, migration_run_id=?, migrated_at=NOW(), updated_at=NOW()
                 WHERE id=?"
            )->execute([
                $officeName,
                nullOrString($row['city'] ?? null),
                (string)($row['address'] ?? ''),
                (int)($row['is_active'] ?? 1),
                $source,
                $srcKey,
                $runId,
                $id,
            ]);
        }
    } else {
        $newId = nextIntId($pdo, 'master_office');
        $pdo->prepare(
            "INSERT INTO master_office
             (id,office_code,office_name,city,address,is_active,created_by,created_at,updated_at,source_system,source_key,migration_run_id,migrated_at)
             VALUES (?,?,?,?,?,?,?,NOW(),NOW(),?,?,?,NOW())"
        )->execute([
            $newId,
            $officeCode,
            $officeName,
            nullOrString($row['city'] ?? null),
            (string)($row['address'] ?? ''),
            (int)($row['is_active'] ?? 1),
            'MIGRATION',
            $source,
            $srcKey,
            $runId,
        ]);
        $id = $newId;
    }
    upsertMap($pdo, 'map_warehouses', $source, (string)$row['source_id'], $officeCode, 'target_warehouse_id', $id, ['target_office_code' => $officeCode]);
    upsertTargetKey($pdo, 'master_office', $id, $source, $srcKey, $runId);
    return true;
}

function loadItem(PDO $pdo, int $runId, string $source, array $row): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'master_products', $source, $srcKey)) {
        return true;
    }
    $sku = (string)($row['item_code'] ?: ('ITEM-' . str_pad((string)$row['source_id'], 6, '0', STR_PAD_LEFT)));
    $st = $pdo->prepare("SELECT id FROM master_products WHERE sku=? LIMIT 1");
    $st->execute([$sku]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) {
        $pdo->prepare(
            "UPDATE master_products
             SET products_name=?, category=?, unit=?, price=?, status=?, source_system=?, source_key=?, migration_run_id=?, migrated_at=NOW(), updated_at=NOW()
             WHERE id=?"
        )->execute([
            (string)$row['item_name'],
            nullOrString($row['category'] ?? null),
            (string)($row['unit_code'] ?? 'unit'),
            (float)($row['price'] ?? 0),
            (string)($row['status'] ?? 'active'),
            $source,
            $srcKey,
            $runId,
            $id,
        ]);
    } else {
        $newId = nextIntId($pdo, 'master_products');
        $pdo->prepare(
            "INSERT INTO master_products
             (id,sku,products_name,category,unit,price,status,stock_qty,current_stock,created_at,updated_at,source_system,source_key,migration_run_id,migrated_at)
             VALUES (?,?,?,?,?,?,?,0,0,NOW(),NOW(),?,?,?,NOW())"
        )->execute([
            $newId,
            $sku,
            (string)$row['item_name'],
            nullOrString($row['category'] ?? null),
            (string)($row['unit_code'] ?? 'unit'),
            (float)($row['price'] ?? 0),
            (string)($row['status'] ?? 'active'),
            $source,
            $srcKey,
            $runId,
        ]);
        $id = $newId;
    }
    upsertMap($pdo, 'map_items', $source, (string)$row['source_id'], $sku, 'target_item_id', $id);
    upsertTargetKey($pdo, 'master_products', $id, $source, $srcKey, $runId);
    return true;
}

function loadOpeningGl(PDO $pdo, int $runId, string $source, array $row): bool
{
    $accountCode = (string)($row['account_code'] ?? '');
    $amount = (float)($row['amount'] ?? 0);
    if ($accountCode === '' || abs($amount) < 0.000001) {
        return false;
    }
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'gl_journal_headers', $source, $srcKey)) {
        return true;
    }

    $accId = findAccountIdByCode($pdo, $accountCode);
    if ($accId <= 0) {
        throw new RuntimeException("Akun GL tidak ditemukan: {$accountCode}");
    }
    $offsetCode = (string)($row['offset_account_code'] ?? '399999');
    $offsetId = findAccountIdByCode($pdo, $offsetCode);
    if ($offsetId <= 0) {
        throw new RuntimeException("Akun offset opening tidak ditemukan: {$offsetCode}");
    }

    $journalNo = 'MIG-OPEN-' . date('Ymd') . '-' . substr(hash('sha1', $srcKey), 0, 6);
    $journalDate = (string)($row['balance_date'] ?? date('Y-m-d'));
    $desc = 'Opening GL from ' . strtoupper($source);

    $pdo->prepare(
        "INSERT INTO gl_journal_headers
         (journal_no,journal_date,source_module,source_event,source_ref,description,status,created_by,created_at,source_system,source_key,migration_run_id,migrated_at)
         VALUES (?,?,?,?,?,'Opening balance migration','POSTED',NULL,NOW(),?,?,?,NOW())"
    )->execute([
        $journalNo,
        $journalDate,
        'MIGRATION',
        'OPENING_GL',
        $srcKey,
        $source,
        $srcKey,
        $runId,
    ]);
    $headerId = (int)$pdo->lastInsertId();

    $dr = $amount >= 0 ? $amount : 0.0;
    $cr = $amount < 0 ? abs($amount) : 0.0;
    $dr2 = $amount < 0 ? abs($amount) : 0.0;
    $cr2 = $amount >= 0 ? $amount : 0.0;

    $ins = $pdo->prepare(
        "INSERT INTO gl_journal_lines (header_id,line_no,account_id,dr_amount,cr_amount,memo,created_at)
         VALUES (?,?,?,?,?,?,NOW())"
    );
    $ins->execute([$headerId, 1, $accId, $dr, $cr, $desc]);
    $ins->execute([$headerId, 2, $offsetId, $dr2, $cr2, 'Opening offset']);

    upsertTargetKey($pdo, 'gl_journal_headers', $headerId, $source, $srcKey, $runId);
    return true;
}

function loadOpeningArAp(PDO $pdo, int $runId, string $source, array $row, bool $isAr): bool
{
    $docNo = (string)($row['ref_code'] ?? '');
    $amount = (float)($row['amount'] ?? 0);
    if ($docNo === '' || $amount <= 0) {
        return false;
    }

    if ($isAr) {
        // AR opening -> sales_do document.
        $row2 = [
            'source_key' => (string)$row['source_key'],
            'doc_no' => $docNo,
            'doc_date' => (string)($row['balance_date'] ?? date('Y-m-d')),
            'party_code' => (string)($row['party_code'] ?? ''),
            'amount' => $amount,
            'tax_amount' => 0,
            'status' => 'UNPAID',
            'office_code' => (string)($row['warehouse_code'] ?? 'SYS'),
        ];
        return loadSalesInvoice($pdo, $runId, $source, $row2);
    }

    // AP opening -> purchases_invoice_ap document.
    $row2 = [
        'source_key' => (string)$row['source_key'],
        'doc_no' => $docNo,
        'doc_date' => (string)($row['balance_date'] ?? date('Y-m-d')),
        'party_code' => (string)($row['party_code'] ?? ''),
        'amount' => $amount,
        'tax_amount' => 0,
        'status' => 'UNPAID',
        'office_code' => (string)($row['warehouse_code'] ?? 'SYS'),
    ];
    return loadPurchaseInvoice($pdo, $runId, $source, $row2, new GLPostingService(), true);
}

function loadOpeningStock(PDO $pdo, int $runId, string $source, array $row): bool
{
    $itemCode = (string)($row['item_code'] ?? '');
    $qty = (float)($row['qty'] ?? 0);
    if ($itemCode === '' || abs($qty) < 0.000001) {
        return false;
    }
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'wqs_stock_adjustments', $source, $srcKey)) {
        return true;
    }
    $productId = findProductIdBySku($pdo, $itemCode);
    if ($productId <= 0) {
        throw new RuntimeException("Produk tidak ditemukan untuk opening stock: {$itemCode}");
    }
    return applyStockDelta($pdo, $runId, $source, $srcKey, $productId, $itemCode, $qty, 'OPENING_STOCK');
}

function loadSalesInvoice(PDO $pdo, int $runId, string $source, array $row): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'sales_do', $source, $srcKey)) {
        return true;
    }
    $doCode = (string)($row['doc_no'] ?? '');
    if ($doCode === '') {
        return false;
    }
    $docDate = normalizeDateOrNull($row['doc_date'] ?? null) ?? date('Y-m-d');
    $partyCode = (string)($row['party_code'] ?? '');
    $customer = resolveCustomer($pdo, $source, (string)($row['source_id'] ?? ''), $partyCode);
    $officeCode = strtoupper((string)($row['office_code'] ?? 'SYS'));
    $taxAmount = (float)($row['tax_amount'] ?? 0);
    $amount = (float)($row['amount'] ?? 0);
    $grandTotal = $amount + $taxAmount;

    $st = $pdo->prepare("SELECT id FROM sales_do WHERE do_code=? LIMIT 1");
    $st->execute([$doCode]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) {
        $pdo->prepare(
            "UPDATE sales_do
             SET do_date=?, customer_id=?, customers_code=?, office_code=?, total_amount=?, tax_amount=?, grand_total=?, source_system=?, source_key=?, migration_run_id=?, migrated_at=NOW(), updated_at=NOW()
             WHERE id=?"
        )->execute([
            $docDate,
            $customer['id'],
            $customer['code'],
            $officeCode,
            $amount,
            $taxAmount,
            $grandTotal,
            $source,
            $srcKey,
            $runId,
            $id,
        ]);
    } else {
        $newId = nextIntId($pdo, 'sales_do');
        $pdo->prepare(
            "INSERT INTO sales_do
             (id,do_code,tracking_code,do_date,customer_id,customers_code,office_code,status,total_amount,tax_amount,grand_total,created_at,updated_at,source_system,source_key,migration_run_id,migrated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),?,?,?,NOW())"
        )->execute([
            $newId,
            $doCode,
            $doCode,
            $docDate,
            $customer['id'] > 0 ? $customer['id'] : null,
            $customer['code'],
            $officeCode,
            'fin_done',
            $amount,
            $taxAmount,
            $grandTotal,
            $source,
            $srcKey,
            $runId,
        ]);
        $id = $newId;
    }
    upsertTargetKey($pdo, 'sales_do', $id, $source, $srcKey, $runId);
    return true;
}

function loadSalesPayment(PDO $pdo, int $runId, string $source, array $row): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'migration_sales_receipts', $source, $srcKey)) {
        return true;
    }
    $receiptNo = (string)($row['doc_no'] ?? '');
    $invoiceNo = (string)($row['invoice_no'] ?? $row['ref_invoice_no'] ?? '');
    if ($receiptNo === '' || $invoiceNo === '') {
        return false;
    }
    $customerCode = (string)($row['party_code'] ?? '');
    $amount = (float)($row['amount'] ?? 0);
    if ($amount <= 0) {
        return false;
    }

    $pdo->prepare(
        "INSERT INTO migration_sales_receipts
         (receipt_no,receipt_date,sales_invoice_no,customer_code,amount,currency,method,note,source_system,source_key,migration_run_id,migrated_at,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE
         receipt_date=VALUES(receipt_date),sales_invoice_no=VALUES(sales_invoice_no),customer_code=VALUES(customer_code),
         amount=VALUES(amount),currency=VALUES(currency),method=VALUES(method),note=VALUES(note),
         migration_run_id=VALUES(migration_run_id),migrated_at=NOW()"
    )->execute([
        $receiptNo,
        normalizeDateOrNull($row['doc_date'] ?? null) ?? date('Y-m-d'),
        $invoiceNo,
        $customerCode !== '' ? $customerCode : null,
        $amount,
        (string)($row['currency'] ?? 'IDR'),
        nullOrString($row['method'] ?? null),
        nullOrString($row['note'] ?? null),
        $source,
        $srcKey,
        $runId,
    ]);
    $id = (int)$pdo->lastInsertId();
    upsertTargetKey($pdo, 'migration_sales_receipts', $id > 0 ? $id : null, $source, $srcKey, $runId);
    return true;
}

function loadPurchaseInvoice(PDO $pdo, int $runId, string $source, array $row, GLPostingService $glSvc, bool $isOpening = false): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'purchases_invoice_ap', $source, $srcKey)) {
        return true;
    }
    $apCode = (string)($row['doc_no'] ?? '');
    if ($apCode === '') {
        return false;
    }
    $docDate = normalizeDateOrNull($row['doc_date'] ?? null) ?? date('Y-m-d');
    $amount = (float)($row['amount'] ?? 0);
    $taxAmount = (float)($row['tax_amount'] ?? 0);
    $subtotal = max(0.0, $amount - $taxAmount);
    $vendor = resolveVendor($pdo, $source, (string)($row['source_id'] ?? ''), (string)($row['party_code'] ?? ''));

    $st = $pdo->prepare("SELECT id FROM purchases_invoice_ap WHERE ap_code=? LIMIT 1");
    $st->execute([$apCode]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) {
        $pdo->prepare(
            "UPDATE purchases_invoice_ap
             SET invoice_date=?, manufacture_id=?, office_code=?, subtotal=?, tax_amount=?, total_amount=?, status=?, source_system=?, source_key=?, migration_run_id=?, migrated_at=NOW(), updated_at=NOW()
             WHERE id=?"
        )->execute([
            $docDate,
            $vendor > 0 ? $vendor : null,
            strtoupper((string)($row['office_code'] ?? 'SYS')),
            $subtotal,
            $taxAmount,
            $amount,
            (string)($row['status'] ?? 'UNPAID'),
            $source,
            $srcKey,
            $runId,
            $id,
        ]);
    } else {
        $newId = nextIntId($pdo, 'purchases_invoice_ap');
        $pdo->prepare(
            "INSERT INTO purchases_invoice_ap
             (id,ap_code,invoice_type,invoice_number,invoice_date,due_date,manufacture_id,office_code,currency,subtotal,tax_percent,tax_amount,total_amount,status,note,created_by,created_at,updated_at,source_system,source_key,migration_run_id,migrated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),?,?,?,NOW())"
        )->execute([
            $newId,
            $apCode,
            $isOpening ? 'PROFORMA' : 'FINAL',
            (string)($row['invoice_number'] ?? $apCode),
            $docDate,
            normalizeDateOrNull($row['due_date'] ?? null),
            $vendor > 0 ? $vendor : null,
            strtoupper((string)($row['office_code'] ?? 'SYS')),
            (string)($row['currency'] ?? 'IDR'),
            $subtotal,
            0,
            $taxAmount,
            $amount,
            (string)($row['status'] ?? 'UNPAID'),
            'Migrated from ' . $source,
            'migration',
            $source,
            $srcKey,
            $runId,
        ]);
        $id = $newId;
    }
    upsertTargetKey($pdo, 'purchases_invoice_ap', $id, $source, $srcKey, $runId);

    // Approach 1 (recommended): posting via GL engine from document when mapping exists.
    if (!$isOpening) {
        if ($pdo->inTransaction()) {
            // Avoid nested transaction conflict inside GLPostingService.
            // Defer to jobs queue; worker will post using existing engine.
            enqueueGlPostingJob($pdo, $apCode, $amount);
        } else {
            $glSvc->createJournalFromMapping(
                $pdo,
                'PURCHASES',
                'AP_INVOICE_CREATED',
                $apCode,
                $amount,
                'Migrated AP invoice ' . $apCode,
                0
            );
        }
    }
    return true;
}

function loadPurchasePayment(PDO $pdo, int $runId, string $source, array $row): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'purchases_payment_ap', $source, $srcKey)) {
        return true;
    }
    $payCode = (string)($row['doc_no'] ?? '');
    $invoiceNo = (string)($row['invoice_no'] ?? $row['ref_invoice_no'] ?? '');
    if ($payCode === '' || $invoiceNo === '') {
        return false;
    }
    $apId = findApIdByCode($pdo, $invoiceNo);
    if ($apId <= 0) {
        throw new RuntimeException("AP invoice tidak ditemukan untuk payment: {$invoiceNo}");
    }
    $newId = nextIntId($pdo, 'purchases_payment_ap');
    $pdo->prepare(
        "INSERT INTO purchases_payment_ap
         (id,pay_code,ap_id,pay_date,amount,method,bank_name,reference,note,created_by,created_at,source_system,source_key,migration_run_id,migrated_at)
         VALUES (?,?,?,?,?,?,?,?,?,'migration',NOW(),?,?,?,NOW())"
    )->execute([
        $newId,
        $payCode,
        $apId,
        normalizeDateOrNull($row['doc_date'] ?? null) ?? date('Y-m-d'),
        (float)($row['amount'] ?? 0),
        nullOrString($row['method'] ?? null),
        nullOrString($row['bank_name'] ?? null),
        nullOrString($row['reference'] ?? null),
        nullOrString($row['note'] ?? null),
        $source,
        $srcKey,
        $runId,
    ]);
    upsertTargetKey($pdo, 'purchases_payment_ap', $newId, $source, $srcKey, $runId);
    return true;
}

function loadGeneralJournal(PDO $pdo, int $runId, string $source, array $row): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'gl_journal_headers', $source, $srcKey)) {
        return true;
    }
    $journalNo = (string)($row['doc_no'] ?? '');
    if ($journalNo === '') {
        return false;
    }
    $lineNo = (int)($row['line_no'] ?? 1);
    $accountCode = (string)($row['account_code'] ?? '');
    $accId = findAccountIdByCode($pdo, $accountCode);
    if ($accId <= 0) {
        throw new RuntimeException("Akun jurnal tidak ditemukan: {$accountCode}");
    }

    $st = $pdo->prepare("SELECT id FROM gl_journal_headers WHERE source_system=? AND source_key=? LIMIT 1");
    $st->execute([$source, $srcKey]);
    $headerId = (int)$st->fetchColumn();
    if ($headerId <= 0) {
        // Multi-line document can come with different source_key per line.
        // Reuse header by journal_no if it already exists.
        $stByNo = $pdo->prepare("SELECT id FROM gl_journal_headers WHERE journal_no=? LIMIT 1");
        $stByNo->execute([$journalNo]);
        $headerId = (int)$stByNo->fetchColumn();
        if ($headerId <= 0) {
            $pdo->prepare(
                "INSERT INTO gl_journal_headers
                 (journal_no,journal_date,source_module,source_event,source_ref,description,status,created_by,created_at,source_system,source_key,migration_run_id,migrated_at)
                 VALUES (?,?,?,?,?,'Migrated journal','POSTED',NULL,NOW(),?,?,?,NOW())"
            )->execute([
                $journalNo,
                normalizeDateOrNull($row['doc_date'] ?? null) ?? date('Y-m-d'),
                'MIGRATION',
                'GENERAL_JOURNAL',
                $journalNo,
                $source,
                $srcKey,
                $runId,
            ]);
            $headerId = (int)$pdo->lastInsertId();
        }
    }

    $chk = $pdo->prepare("SELECT id FROM gl_journal_lines WHERE header_id=? AND line_no=? LIMIT 1");
    $chk->execute([$headerId, $lineNo]);
    if ((int)$chk->fetchColumn() > 0) {
        return true;
    }
    $pdo->prepare(
        "INSERT INTO gl_journal_lines (header_id,line_no,account_id,dr_amount,cr_amount,memo,created_at)
         VALUES (?,?,?,?,?,?,NOW())"
    )->execute([
        $headerId,
        $lineNo,
        $accId,
        (float)($row['dr_amount'] ?? 0),
        (float)($row['cr_amount'] ?? 0),
        (string)($row['memo'] ?? 'Migrated journal line'),
    ]);
    upsertTargetKey($pdo, 'gl_journal_headers', $headerId, $source, $srcKey, $runId);
    return true;
}

function loadInventoryAdjustment(PDO $pdo, int $runId, string $source, array $row): bool
{
    $srcKey = (string)$row['source_key'];
    if (isSourceExists($pdo, 'wqs_stock_adjustments', $source, $srcKey)) {
        return true;
    }
    $itemCode = (string)($row['item_code'] ?? '');
    $qty = (float)($row['qty'] ?? 0);
    if ($itemCode === '' || abs($qty) < 0.000001) {
        return false;
    }
    $productId = findProductIdBySku($pdo, $itemCode);
    if ($productId <= 0) {
        throw new RuntimeException("Produk inventory adjustment tidak ditemukan: {$itemCode}");
    }
    return applyStockDelta($pdo, $runId, $source, $srcKey, $productId, $itemCode, $qty, 'MIGRATION_ADJ');
}

function applyStockDelta(PDO $pdo, int $runId, string $source, string $sourceKey, int $productId, string $sku, float $deltaQty, string $reason): bool
{
    $adjCode = 'MIG-' . date('Ymd') . '-' . substr(hash('sha1', $sourceKey), 0, 8);
    $newId = nextIntId($pdo, 'wqs_stock_adjustments');
    $pdo->prepare(
        "INSERT INTO wqs_stock_adjustments
         (id,adj_code,product_id,sku,delta_qty,reason,created_at,created_by,source_system,source_key,migration_run_id,migrated_at)
         VALUES (?,?,?,?,?,?,NOW(),'migration',?,?,?,NOW())"
    )->execute([
        $newId,
        $adjCode,
        $productId,
        $sku,
        $deltaQty,
        $reason,
        $source,
        $sourceKey,
        $runId,
    ]);

    $st = $pdo->prepare("SELECT id,stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
    $st->execute([$productId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $newQty = (float)$row['stock_qty'] + $deltaQty;
        $pdo->prepare("UPDATE wqs_stock SET stock_qty=?, updated_at=NOW() WHERE id=?")->execute([$newQty, (int)$row['id']]);
    } else {
        $newStockId = nextIntId($pdo, 'wqs_stock');
        $pdo->prepare("INSERT INTO wqs_stock (id,product_id,stock_qty,updated_at) VALUES (?,?,?,NOW())")
            ->execute([$newStockId, $productId, $deltaQty]);
    }
    upsertTargetKey($pdo, 'wqs_stock_adjustments', $newId, $source, $sourceKey, $runId);
    return true;
}

function resolveCustomer(PDO $pdo, string $source, string $sourceId, string $fallbackCode): array
{
    if ($sourceId !== '') {
        $st = $pdo->prepare("SELECT target_customer_id FROM map_customers WHERE source=? AND source_id=? LIMIT 1");
        $st->execute([$source, $sourceId]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            $s2 = $pdo->prepare("SELECT customers_code FROM master_customers WHERE id=?");
            $s2->execute([$id]);
            return ['id' => $id, 'code' => (string)$s2->fetchColumn()];
        }
    }
    if ($fallbackCode !== '') {
        $st = $pdo->prepare("SELECT id,customers_code FROM master_customers WHERE customers_code=? LIMIT 1");
        $st->execute([$fallbackCode]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['id' => (int)$row['id'], 'code' => (string)$row['customers_code']];
        }
    }
    return ['id' => 0, 'code' => $fallbackCode];
}

function resolveVendor(PDO $pdo, string $source, string $sourceId, string $fallbackCode): int
{
    if ($sourceId !== '') {
        $st = $pdo->prepare("SELECT target_vendor_id FROM map_vendors WHERE source=? AND source_id=? LIMIT 1");
        $st->execute([$source, $sourceId]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }
    if ($fallbackCode !== '') {
        $st = $pdo->prepare("SELECT id FROM master_vendors WHERE vendors_code=? LIMIT 1");
        $st->execute([$fallbackCode]);
        return (int)$st->fetchColumn();
    }
    return 0;
}

function findAccountIdByCode(PDO $pdo, string $code): int
{
    $st = $pdo->prepare("SELECT id FROM gl_accounts WHERE code=? LIMIT 1");
    $st->execute([$code]);
    return (int)$st->fetchColumn();
}

function findProductIdBySku(PDO $pdo, string $sku): int
{
    $st = $pdo->prepare("SELECT id FROM master_products WHERE sku=? LIMIT 1");
    $st->execute([$sku]);
    return (int)$st->fetchColumn();
}

function findApIdByCode(PDO $pdo, string $apCode): int
{
    $st = $pdo->prepare("SELECT id FROM purchases_invoice_ap WHERE ap_code=? LIMIT 1");
    $st->execute([$apCode]);
    return (int)$st->fetchColumn();
}

function isSourceExists(PDO $pdo, string $table, string $source, string $sourceKey): bool
{
    if ($sourceKey === '') {
        return false;
    }
    if (!tableHasColumns($pdo, $table, ['source_system', 'source_key'])) {
        $st = $pdo->prepare("SELECT id FROM migration_target_keys WHERE target_table=? AND source_system=? AND source_key=? LIMIT 1");
        $st->execute([$table, $source, $sourceKey]);
        return (bool)$st->fetchColumn();
    }
    $st = $pdo->prepare("SELECT id FROM `{$table}` WHERE source_system=? AND source_key=? LIMIT 1");
    $st->execute([$source, $sourceKey]);
    return (bool)$st->fetchColumn();
}

function tableHasColumns(PDO $pdo, string $table, array $cols): bool
{
    $in = implode(',', array_fill(0, count($cols), '?'));
    $params = array_merge([$table], $cols);
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name=? AND column_name IN ({$in})"
    );
    $st->execute($params);
    return ((int)$st->fetchColumn()) === count($cols);
}

function upsertTargetKey(PDO $pdo, string $table, ?int $targetId, string $source, string $sourceKey, int $runId): void
{
    if ($sourceKey === '') {
        return;
    }
    $pdo->prepare(
        "INSERT INTO migration_target_keys (target_table,target_id,source_system,source_key,migration_run_id,migrated_at)
         VALUES (?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE target_id=VALUES(target_id),migration_run_id=VALUES(migration_run_id),migrated_at=NOW()"
    )->execute([$table, $targetId, $source, $sourceKey, $runId]);
}

function upsertMap(
    PDO $pdo,
    string $table,
    string $source,
    string $sourceId,
    string $sourceCode,
    string $targetCol,
    int $targetId,
    array $extra = []
): void {
    $fields = ['source' => $source, 'source_id' => $sourceId, 'source_code' => $sourceCode, $targetCol => $targetId];
    foreach ($extra as $k => $v) {
        $fields[$k] = $v;
    }

    $cols = array_keys($fields);
    $marks = array_fill(0, count($cols), '?');
    $updates = [];
    foreach ($cols as $col) {
        if ($col === 'source' || $col === 'source_id') {
            continue;
        }
        $updates[] = "{$col}=VALUES({$col})";
    }
    $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ")
            VALUES (" . implode(',', $marks) . ")
            ON DUPLICATE KEY UPDATE " . implode(',', $updates) . ", updated_at=NOW()";
    $pdo->prepare($sql)->execute(array_values($fields));
}

function nextIntId(PDO $pdo, string $table): int
{
    $id = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM `{$table}`")->fetchColumn();
    return max(1, $id);
}

function extractRows(string $inputDir, array $cfg, string $source, string $entity): array
{
    $entityCfg = $cfg['entities'][$entity] ?? null;
    if (!is_array($entityCfg)) {
        return [];
    }
    $fileName = (string)($entityCfg['file'] ?? '');
    if ($fileName === '') {
        return [];
    }
    $path = rtrim($inputDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($path)) {
        return [];
    }
    $columns = is_array($entityCfg['columns'] ?? null) ? $entityCfg['columns'] : [];
    if (count($columns) === 0) {
        return [];
    }

    $rows = readCsvAssoc($path);
    $out = [];
    foreach ($rows as $idx => $raw) {
        $norm = [];
        foreach ($columns as $targetCol => $sourceCol) {
            $v = $raw[$sourceCol] ?? null;
            $norm[$targetCol] = normalizeField($targetCol, $v);
        }

        $sourceIdCol = (string)($entityCfg['source_id'] ?? 'source_id');
        $sourceId = (string)($norm[$sourceIdCol] ?? ($raw[$sourceIdCol] ?? ($idx + 1)));
        $keyTemplate = (string)($entityCfg['source_key_template'] ?? ($entity . ':{source_id}'));
        $sourceKey = str_replace('{source_id}', $sourceId, $keyTemplate);
        $norm['source_id'] = $sourceId;
        $norm['source_key'] = $source . ':' . $sourceKey;
        $out[] = $norm;
    }
    return $out;
}

function readCsvAssoc(string $path): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    $header = null;
    $rows = [];
    while (($line = fgetcsv($fh, 0, ",", "\"", "\\")) !== false) {
        if ($header === null) {
            $header = array_map(static fn($v) => trim((string)$v), $line);
            continue;
        }
        if (count($line) === 1 && trim((string)$line[0]) === '') {
            continue;
        }
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = $line[$i] ?? null;
        }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function enqueueGlPostingJob(PDO $pdo, string $sourceRef, float $amount): void
{
    if (!tableExists($pdo, 'jobs')) {
        return;
    }
    $payload = json_encode([
        'module' => 'PURCHASES',
        'event' => 'AP_INVOICE_CREATED',
        'source_ref' => $sourceRef,
        'amount' => $amount,
        'description' => 'Migrated AP invoice ' . $sourceRef,
        'user_id' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // Support both old and new jobs schema.
    if (tableHasColumns($pdo, 'jobs', ['max_attempts'])) {
        $pdo->prepare(
            "INSERT INTO jobs (job_type,payload_json,status,attempts,max_attempts,run_at,created_at,updated_at)
             VALUES ('gl_posting_batch', ?, 'PENDING', 0, 3, NOW(), NOW(), NOW())"
        )->execute([$payload]);
        return;
    }
    $pdo->prepare(
        "INSERT INTO jobs (job_type,payload_json,status,attempts,run_at,created_at,updated_at)
         VALUES ('gl_posting_batch', ?, 'PENDING', 0, NOW(), NOW(), NOW())"
    )->execute([$payload]);
}

function normalizeField(string $field, $value)
{
    if ($value === null) {
        return null;
    }
    $v = trim((string)$value);
    if ($v === '') {
        return null;
    }

    $dateFields = ['doc_date', 'due_date', 'balance_date'];
    if (in_array($field, $dateFields, true)) {
        $d = normalizeDateString($v);
        return $d ?? $v;
    }

    $numericFields = ['amount', 'tax_amount', 'qty', 'dr_amount', 'cr_amount', 'price', 'rate_percent'];
    if (in_array($field, $numericFields, true)) {
        return normalizeNumber($v);
    }
    return $v;
}

function normalizeNumber(string $value): float
{
    $v = str_replace([' ', ','], ['', '.'], $value);
    if (substr_count($v, '.') > 1) {
        $parts = explode('.', $v);
        $last = array_pop($parts);
        $v = implode('', $parts) . '.' . $last;
    }
    return (float)$v;
}

function normalizeDateString(string $value): ?string
{
    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

function nullOrString($v): ?string
{
    if ($v === null) {
        return null;
    }
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

function computeInputHash(string $inputDir): ?string
{
    if (!is_dir($inputDir)) {
        return null;
    }
    $files = array_values(array_filter(
        scandir($inputDir) ?: [],
        static fn($f) => is_file($inputDir . DIRECTORY_SEPARATOR . $f)
    ));
    sort($files);
    if (count($files) === 0) {
        return null;
    }
    $ctx = hash_init('sha256');
    foreach ($files as $f) {
        hash_update($ctx, $f);
        hash_update($ctx, (string)filesize($inputDir . DIRECTORY_SEPARATOR . $f));
        hash_update($ctx, (string)filemtime($inputDir . DIRECTORY_SEPARATOR . $f));
    }
    return hash_final($ctx);
}
