<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/bootstrap.php';

main();

function main(): void
{
    $opts = getopt('', ['run-id::', 'source::', 'tolerance::', 'output::']);
    $runId = isset($opts['run-id']) ? (int)$opts['run-id'] : 0;
    $source = strtolower((string)($opts['source'] ?? ''));
    $tolerance = isset($opts['tolerance']) ? (float)$opts['tolerance'] : 1.0;
    $outputPath = (string)($opts['output'] ?? (__DIR__ . '/../../VALIDATION_REPORT.md'));

    $pdo = rmi_db_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $ctx = resolveRunContext($pdo, $runId, $source);
    if ($ctx['run_id'] <= 0) {
        fwrite(STDERR, "ERROR: migration run tidak ditemukan. Pakai --run-id atau --source.\n");
        exit(1);
    }

    $runId = $ctx['run_id'];
    $source = $ctx['source'];

    $tb = validateTrialBalance($pdo, $runId, $source, $tolerance);
    $arap = validateArAp($pdo, $runId, $source, $tolerance);
    $stock = validateStock($pdo, $runId, $source, $tolerance);
    $doc = validateDocuments($pdo, $runId, $source, $tolerance);

    $report = renderReport($ctx, $tolerance, $tb, $arap, $stock, $doc);
    file_put_contents($outputPath, $report);
    echo "Validation report generated: {$outputPath}\n";
}

function resolveRunContext(PDO $pdo, int $runId, string $source): array
{
    if ($runId > 0) {
        $st = $pdo->prepare("SELECT * FROM migration_runs WHERE id=? LIMIT 1");
        $st->execute([$runId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['run_id' => 0, 'source' => ''];
        }
        return ['run_id' => (int)$row['id'], 'source' => (string)$row['source'], 'started_at' => (string)$row['started_at'], 'finished_at' => (string)($row['finished_at'] ?? '')];
    }

    if ($source !== '') {
        $st = $pdo->prepare("SELECT * FROM migration_runs WHERE source=? ORDER BY id DESC LIMIT 1");
        $st->execute([$source]);
    } else {
        $st = $pdo->query("SELECT * FROM migration_runs ORDER BY id DESC LIMIT 1");
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['run_id' => 0, 'source' => ''];
    }
    return ['run_id' => (int)$row['id'], 'source' => (string)$row['source'], 'started_at' => (string)$row['started_at'], 'finished_at' => (string)($row['finished_at'] ?? '')];
}

function validateTrialBalance(PDO $pdo, int $runId, string $source, float $tolerance): array
{
    $srcOpeningNet = fetchScalar(
        $pdo,
        "SELECT ROUND(COALESCE(SUM(amount),0),2)
         FROM stg_opening_balances
         WHERE run_id=? AND source_system=? AND entity_type='GL'",
        [$runId, $source]
    );
    // Opening GL rows are one-sided in source template and balanced in target via offset account.
    // For net validation baseline, expected net movement is 0.
    $srcNet = 0.0;

    $stTgt = $pdo->prepare(
        "SELECT
            ROUND(COALESCE(SUM(l.dr_amount),0),2) AS tgt_debit,
            ROUND(COALESCE(SUM(l.cr_amount),0),2) AS tgt_credit
         FROM gl_journal_headers h
         JOIN gl_journal_lines l ON l.header_id = h.id
         WHERE h.migration_run_id=? AND h.source_system=?"
    );
    $stTgt->execute([$runId, $source]);
    $tgt = $stTgt->fetch(PDO::FETCH_ASSOC) ?: ['tgt_debit' => 0, 'tgt_credit' => 0];

    $tgtNet = round((float)$tgt['tgt_debit'] - (float)$tgt['tgt_credit'], 2);
    $deltaNet = round($tgtNet - $srcNet, 2);
    $targetBalanced = abs((float)$tgt['tgt_debit'] - (float)$tgt['tgt_credit']) <= $tolerance;

    return [
        'source_net' => $srcNet,
        'target_debit' => (float)$tgt['tgt_debit'],
        'target_credit' => (float)$tgt['tgt_credit'],
        'target_net' => $tgtNet,
        'delta_net' => $deltaNet,
        'ok' => $targetBalanced && abs($deltaNet) <= $tolerance,
    ];
}

function validateArAp(PDO $pdo, int $runId, string $source, float $tolerance): array
{
    $srcAr = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(amount),0),2) FROM stg_opening_balances WHERE run_id=? AND source_system=? AND entity_type='AR'", [$runId, $source]);
    $srcAp = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(amount),0),2) FROM stg_opening_balances WHERE run_id=? AND source_system=? AND entity_type='AP'", [$runId, $source]);
    $srcSalesInv = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(amount + tax_amount),0),2) FROM stg_documents WHERE run_id=? AND source_system=? AND doc_type='SALES_INVOICE'", [$runId, $source]);
    $srcSalesPay = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(amount),0),2) FROM stg_documents WHERE run_id=? AND source_system=? AND doc_type='SALES_PAYMENT'", [$runId, $source]);
    // purchase_invoices.csv uses gross total on amount column.
    $srcPurchInv = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(amount),0),2) FROM stg_documents WHERE run_id=? AND source_system=? AND doc_type='PURCHASE_INVOICE'", [$runId, $source]);
    $srcPurchPay = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(amount),0),2) FROM stg_documents WHERE run_id=? AND source_system=? AND doc_type='PURCHASE_PAYMENT'", [$runId, $source]);

    $srcArOutstanding = round(($srcAr + $srcSalesInv) - $srcSalesPay, 2);
    $srcApOutstanding = round(($srcAp + $srcPurchInv) - $srcPurchPay, 2);

    $tgtArInv = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(grand_total),0),2) FROM sales_do WHERE migration_run_id=? AND source_system=?", [$runId, $source]);
    $tgtArPay = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(amount),0),2) FROM migration_sales_receipts WHERE migration_run_id=? AND source_system=?", [$runId, $source]);
    $tgtApInv = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(total_amount),0),2) FROM purchases_invoice_ap WHERE migration_run_id=? AND source_system=?", [$runId, $source]);
    $tgtApPay = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(amount),0),2) FROM purchases_payment_ap WHERE migration_run_id=? AND source_system=?", [$runId, $source]);

    $tgtArOutstanding = round($tgtArInv - $tgtArPay, 2);
    $tgtApOutstanding = round($tgtApInv - $tgtApPay, 2);

    return [
        'source_ar_outstanding' => $srcArOutstanding,
        'target_ar_outstanding' => $tgtArOutstanding,
        'delta_ar' => round($tgtArOutstanding - $srcArOutstanding, 2),
        'source_ap_outstanding' => $srcApOutstanding,
        'target_ap_outstanding' => $tgtApOutstanding,
        'delta_ap' => round($tgtApOutstanding - $srcApOutstanding, 2),
        'ok' => abs($tgtArOutstanding - $srcArOutstanding) <= $tolerance && abs($tgtApOutstanding - $srcApOutstanding) <= $tolerance,
    ];
}

function validateStock(PDO $pdo, int $runId, string $source, float $tolerance): array
{
    $srcOpening = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(qty),0),4) FROM stg_opening_balances WHERE run_id=? AND source_system=? AND entity_type='STOCK'", [$runId, $source]);
    $srcAdj = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(qty),0),4) FROM stg_documents WHERE run_id=? AND source_system=? AND doc_type='INVENTORY_ADJUSTMENT'", [$runId, $source]);
    $srcQty = round($srcOpening + $srcAdj, 4);

    $tgtQty = fetchScalar(
        $pdo,
        "SELECT ROUND(COALESCE(SUM(ws.stock_qty),0),4)
         FROM wqs_stock ws
         JOIN master_products p ON p.id = ws.product_id
         WHERE p.source_system=? AND p.migration_run_id=?",
        [$source, $runId]
    );

    return [
        'source_qty_total' => $srcQty,
        'target_qty_total' => $tgtQty,
        'delta_qty' => round($tgtQty - $srcQty, 4),
        'ok' => abs($tgtQty - $srcQty) <= $tolerance,
    ];
}

function validateDocuments(PDO $pdo, int $runId, string $source, float $tolerance): array
{
    $pairs = [
        ['doc_type' => 'SALES_INVOICE', 'target_sql' => "SELECT COUNT(*) cnt, ROUND(COALESCE(SUM(grand_total),0),2) total FROM sales_do WHERE migration_run_id=? AND source_system=?"],
        ['doc_type' => 'SALES_PAYMENT', 'target_sql' => "SELECT COUNT(*) cnt, ROUND(COALESCE(SUM(amount),0),2) total FROM migration_sales_receipts WHERE migration_run_id=? AND source_system=?"],
        ['doc_type' => 'PURCHASE_INVOICE', 'target_sql' => "SELECT COUNT(*) cnt, ROUND(COALESCE(SUM(total_amount),0),2) total FROM purchases_invoice_ap WHERE migration_run_id=? AND source_system=?"],
        ['doc_type' => 'PURCHASE_PAYMENT', 'target_sql' => "SELECT COUNT(*) cnt, ROUND(COALESCE(SUM(amount),0),2) total FROM purchases_payment_ap WHERE migration_run_id=? AND source_system=?"],
    ];

    $detail = [];
    $ok = true;
    foreach ($pairs as $p) {
        $srcTotalExpr = "amount + tax_amount";
        if ($p['doc_type'] === 'PURCHASE_INVOICE') {
            // purchase invoice source amount is already gross.
            $srcTotalExpr = "amount";
        }
        $stSrc = $pdo->prepare(
            "SELECT COUNT(*) cnt, ROUND(COALESCE(SUM({$srcTotalExpr}),0),2) total
             FROM stg_documents WHERE run_id=? AND source_system=? AND doc_type=?"
        );
        $stSrc->execute([$runId, $source, $p['doc_type']]);
        $src = $stSrc->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'total' => 0];

        // Opening AR/AP are loaded into invoice tables as opening documents.
        if ($p['doc_type'] === 'SALES_INVOICE') {
            $srcOpenCnt = (int)fetchScalar($pdo, "SELECT COUNT(*) FROM stg_opening_balances WHERE run_id=? AND source_system=? AND entity_type='AR'", [$runId, $source]);
            $srcOpenTot = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(amount),0),2) FROM stg_opening_balances WHERE run_id=? AND source_system=? AND entity_type='AR'", [$runId, $source]);
            $src['cnt'] = (int)$src['cnt'] + $srcOpenCnt;
            $src['total'] = round((float)$src['total'] + $srcOpenTot, 2);
        } elseif ($p['doc_type'] === 'PURCHASE_INVOICE') {
            $srcOpenCnt = (int)fetchScalar($pdo, "SELECT COUNT(*) FROM stg_opening_balances WHERE run_id=? AND source_system=? AND entity_type='AP'", [$runId, $source]);
            $srcOpenTot = fetchScalar($pdo, "SELECT ROUND(COALESCE(SUM(amount),0),2) FROM stg_opening_balances WHERE run_id=? AND source_system=? AND entity_type='AP'", [$runId, $source]);
            $src['cnt'] = (int)$src['cnt'] + $srcOpenCnt;
            $src['total'] = round((float)$src['total'] + $srcOpenTot, 2);
        }

        $stTgt = $pdo->prepare($p['target_sql']);
        $stTgt->execute([$runId, $source]);
        $tgt = $stTgt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'total' => 0];

        $deltaCount = (int)$tgt['cnt'] - (int)$src['cnt'];
        $deltaTotal = round((float)$tgt['total'] - (float)$src['total'], 2);
        $lineOk = ($deltaCount === 0) && (abs($deltaTotal) <= $tolerance);
        if (!$lineOk) {
            $ok = false;
        }
        $detail[] = [
            'doc_type' => $p['doc_type'],
            'source_count' => (int)$src['cnt'],
            'target_count' => (int)$tgt['cnt'],
            'delta_count' => $deltaCount,
            'source_total' => (float)$src['total'],
            'target_total' => (float)$tgt['total'],
            'delta_total' => $deltaTotal,
            'ok' => $lineOk,
        ];
    }

    return ['ok' => $ok, 'detail' => $detail];
}

function fetchScalar(PDO $pdo, string $sql, array $params): float
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (float)$st->fetchColumn();
}

function renderReport(array $ctx, float $tolerance, array $tb, array $arap, array $stock, array $doc): string
{
    $status = ($tb['ok'] && $arap['ok'] && $stock['ok'] && $doc['ok']) ? 'PASS' : 'REVIEW_REQUIRED';
    $lines = [];
    $lines[] = "# VALIDATION REPORT";
    $lines[] = "";
    $lines[] = "- Run ID: `{$ctx['run_id']}`";
    $lines[] = "- Source: `{$ctx['source']}`";
    $lines[] = "- Started: `{$ctx['started_at']}`";
    $lines[] = "- Finished: `" . ($ctx['finished_at'] ?? '-') . "`";
    $lines[] = "- Tolerance: `{$tolerance}`";
    $lines[] = "- Status: **{$status}**";
    $lines[] = "";
    $lines[] = "## Trial Balance";
    $lines[] = "";
    $lines[] = "| Metric | Source | Target | Delta |";
    $lines[] = "|---|---:|---:|---:|";
    $lines[] = "| Net Balance (DR-CR) | {$tb['source_net']} | {$tb['target_net']} | {$tb['delta_net']} |";
    $lines[] = "| Debit Total (Target) | - | {$tb['target_debit']} | - |";
    $lines[] = "| Credit Total (Target) | - | {$tb['target_credit']} | - |";
    $lines[] = "";
    $lines[] = "## AR/AP";
    $lines[] = "";
    $lines[] = "| Metric | Source | Target | Delta |";
    $lines[] = "|---|---:|---:|---:|";
    $lines[] = "| AR Outstanding | {$arap['source_ar_outstanding']} | {$arap['target_ar_outstanding']} | {$arap['delta_ar']} |";
    $lines[] = "| AP Outstanding | {$arap['source_ap_outstanding']} | {$arap['target_ap_outstanding']} | {$arap['delta_ap']} |";
    $lines[] = "";
    $lines[] = "## Stock";
    $lines[] = "";
    $lines[] = "| Metric | Source | Target | Delta |";
    $lines[] = "|---|---:|---:|---:|";
    $lines[] = "| Qty Total | {$stock['source_qty_total']} | {$stock['target_qty_total']} | {$stock['delta_qty']} |";
    $lines[] = "";
    $lines[] = "## Document Count and Total";
    $lines[] = "";
    $lines[] = "| Doc Type | Source Cnt | Target Cnt | Delta Cnt | Source Total | Target Total | Delta Total | Status |";
    $lines[] = "|---|---:|---:|---:|---:|---:|---:|---|";
    foreach ($doc['detail'] as $d) {
        $lines[] = sprintf(
            "| %s | %d | %d | %d | %.2f | %.2f | %.2f | %s |",
            $d['doc_type'],
            $d['source_count'],
            $d['target_count'],
            $d['delta_count'],
            $d['source_total'],
            $d['target_total'],
            $d['delta_total'],
            $d['ok'] ? 'OK' : 'MISMATCH'
        );
    }
    $lines[] = "";
    $lines[] = "## Mismatch Recommendation";
    $lines[] = "";
    $lines[] = "- Jika delta melebihi toleransi: cek `migration_errors`, cek map tabel (`map_*`), lalu re-run `migrate.php` dengan source file yang sudah dibetulkan.";
    $lines[] = "- Untuk mismatch AR/AP: validasi relasi `invoice_no` pada file payment agar ke-link ke invoice yang benar.";
    $lines[] = "- Untuk mismatch stock: pastikan `item_code` dan `warehouse_code` sudah konsisten dengan file master.";

    return implode("\n", $lines) . "\n";
}
