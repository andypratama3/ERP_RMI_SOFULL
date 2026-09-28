<?php
declare(strict_types=1);

namespace App\Accounting;

use PDO;

final class ThreeWayMatchValidator
{
    private const EPS = 0.000001;

    public function validateApAgainstPo(
        PDO $pdo,
        int $poId,
        float $newInvoiceAmount,
        float $qtyTolerancePct = 0.0,
        float $priceTolerancePct = 0.0,
        array $invoiceLines = []
    ): array {
        $poSt = $pdo->prepare("SELECT id, po_code, total_amount FROM purchases_po WHERE id = ? LIMIT 1");
        $poSt->execute([$poId]);
        $po = $poSt->fetch(PDO::FETCH_ASSOC);
        if (!$po) {
            return ['ok' => false, 'reason' => 'PO tidak ditemukan.', 'metrics' => []];
        }

        $poTotal = (float)$po['total_amount'];
        [$qtyTolerancePct, $priceTolerancePct] = $this->resolveTolerance($pdo, $qtyTolerancePct, $priceTolerancePct);

        $poItems = $this->fetchPoItems($pdo, $poId);
        if ($poItems === []) {
            return ['ok' => false, 'reason' => 'PO tidak memiliki line item aktif.', 'metrics' => []];
        }

        $invSt = $pdo->prepare(
            "SELECT COALESCE(SUM(total_amount),0)
             FROM purchases_invoice_ap
             WHERE po_id = ? AND deleted_at IS NULL AND status <> 'HOLD_3WM'"
        );
        $invSt->execute([$poId]);
        $alreadyInvoiced = (float)$invSt->fetchColumn();

        $receivedQtyByItem = $this->fetchReceivedQtyByPoItem($pdo, $poId, $poItems);
        $alreadyInvoicedQtyByItem = $this->fetchAlreadyInvoicedQtyByPoItem($pdo, $poId, $poItems);
        $newInvoiceQtyByItem = [];
        $unresolvedIncomingLines = 0;
        $unresolvedInvoiceLines = 0;
        $qtyCheckSkipped = true;

        if ($invoiceLines !== []) {
            $qtyCheckSkipped = false;
            foreach ($invoiceLines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $qty = (float)($line['qty'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $poItemId = $this->resolvePoItemId($line, $poItems);
                if ($poItemId === null) {
                    $unresolvedInvoiceLines++;
                    continue;
                }
                $newInvoiceQtyByItem[$poItemId] = ($newInvoiceQtyByItem[$poItemId] ?? 0.0) + $qty;
            }
        }

        $receivedValue = 0.0;
        foreach ($poItems as $id => $poItem) {
            $receivedQty = (float)($receivedQtyByItem[$id] ?? 0.0);
            $receivedValue += $receivedQty * (float)$poItem['unit_price'];
        }
        if ($receivedValue < 0) {
            $receivedValue = 0.0;
        }

        $allowedAmount = $receivedValue * (1 + max(0.0, $priceTolerancePct) / 100);
        $newTotal = $alreadyInvoiced + $newInvoiceAmount;
        $amountOk = ($newTotal <= $allowedAmount + self::EPS);
        $qtyOk = true;

        if (!$qtyCheckSkipped) {
            if ($unresolvedInvoiceLines > 0) {
                $qtyOk = false;
            } else {
                foreach ($poItems as $poItemId => $poItem) {
                    $receivedQty = (float)($receivedQtyByItem[$poItemId] ?? 0.0);
                    $allowedQty = $receivedQty * (1 + max(0.0, $qtyTolerancePct) / 100);
                    $invoicedQty = (float)($alreadyInvoicedQtyByItem[$poItemId] ?? 0.0) + (float)($newInvoiceQtyByItem[$poItemId] ?? 0.0);
                    if ($invoicedQty > $allowedQty + self::EPS) {
                        $qtyOk = false;
                        break;
                    }
                }
            }
        }

        $ok = $amountOk && $qtyOk;
        $reason = 'OK';
        if (!$ok) {
            if (!$amountOk && !$qtyCheckSkipped && !$qtyOk) {
                $reason = 'Invoice melebihi qty GR dan nilai GR (3-way match).';
            } elseif (!$amountOk) {
                $reason = 'Invoice melebihi nilai GR yang diterima (3-way match).';
            } elseif ($unresolvedInvoiceLines > 0) {
                $reason = 'Invoice line tidak bisa dipetakan ke PO line (3-way match).';
            } else {
                $reason = 'Invoice qty melebihi qty GR untuk PO line terkait (3-way match).';
            }
        }

        return [
            'ok' => $ok,
            'reason' => $reason,
            'metrics' => [
                'po_total' => $poTotal,
                'po_item_count' => count($poItems),
                'received_value' => $receivedValue,
                'already_invoiced' => $alreadyInvoiced,
                'new_invoice_amount' => $newInvoiceAmount,
                'allowed_total' => $allowedAmount,
                'new_total' => $newTotal,
                'qty_tolerance_pct' => $qtyTolerancePct,
                'price_tolerance_pct' => $priceTolerancePct,
                'qty_check_skipped' => $qtyCheckSkipped,
                'unresolved_invoice_lines' => $unresolvedInvoiceLines,
                'unresolved_incoming_lines' => $unresolvedIncomingLines,
                'received_qty_by_po_item' => $receivedQtyByItem,
                'already_invoiced_qty_by_po_item' => $alreadyInvoicedQtyByItem,
                'new_invoice_qty_by_po_item' => $newInvoiceQtyByItem,
            ],
        ];
    }

    private function resolveTolerance(PDO $pdo, float $qtyTolerancePct, float $priceTolerancePct): array
    {
        if ($qtyTolerancePct > 0 || $priceTolerancePct > 0) {
            return [max(0.0, $qtyTolerancePct), max(0.0, $priceTolerancePct)];
        }
        try {
            $st = $pdo->query(
                "SELECT qty_tolerance_pct, price_tolerance_pct
                 FROM procurement_match_rules
                 WHERE is_active = 1
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if ($row) {
                return [
                    max(0.0, (float)($row['qty_tolerance_pct'] ?? 0.0)),
                    max(0.0, (float)($row['price_tolerance_pct'] ?? 0.0)),
                ];
            }
        } catch (\Throwable $e) {
            // keep defaults
        }
        return [0.0, 0.0];
    }

    /**
     * @return array<int, array{id:int, product_id:int, sku:string, qty:float, unit_price:float}>
     */
    private function fetchPoItems(PDO $pdo, int $poId): array
    {
        $st = $pdo->prepare(
            "SELECT id, COALESCE(product_id,0) AS product_id, COALESCE(sku,'') AS sku, COALESCE(qty,0) AS qty, COALESCE(unit_price,0) AS unit_price
             FROM purchases_po_items
             WHERE po_id = ?
               AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')"
        );
        $st->execute([$poId]);
        $items = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$row['id'];
            if ($id <= 0) {
                continue;
            }
            $items[$id] = [
                'id' => $id,
                'product_id' => (int)$row['product_id'],
                'sku' => strtoupper(trim((string)$row['sku'])),
                'qty' => (float)$row['qty'],
                'unit_price' => (float)$row['unit_price'],
            ];
        }
        return $items;
    }

    /**
     * @param array<int, array{id:int, product_id:int, sku:string, qty:float, unit_price:float}> $poItems
     * @return array<int, float>
     */
    private function fetchReceivedQtyByPoItem(PDO $pdo, int $poId, array $poItems): array
    {
        $rows = $pdo->prepare(
            "SELECT wi.po_item_id, wi.product_id, wi.sku, COALESCE(wi.qty,0) AS qty
             FROM wqs_incoming_items wi
             INNER JOIN wqs_incoming wh ON wh.id = wi.incoming_id
             WHERE wh.po_id = ?"
        );
        $rows->execute([$poId]);
        $result = [];
        while ($line = $rows->fetch(PDO::FETCH_ASSOC)) {
            $poItemId = $this->resolvePoItemId((array)$line, $poItems);
            if ($poItemId === null) {
                continue;
            }
            $result[$poItemId] = ($result[$poItemId] ?? 0.0) + (float)($line['qty'] ?? 0.0);
        }
        return $result;
    }

    /**
     * @param array<int, array{id:int, product_id:int, sku:string, qty:float, unit_price:float}> $poItems
     * @return array<int, float>
     */
    private function fetchAlreadyInvoicedQtyByPoItem(PDO $pdo, int $poId, array $poItems): array
    {
        $hasLines = false;
        try {
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if ($driver === 'sqlite') {
                $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='purchases_invoice_ap_lines'");
                $st->execute();
                $hasLines = ((int)$st->fetchColumn() > 0);
            } else {
                $st = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.tables
                     WHERE table_schema = DATABASE()
                       AND table_name = 'purchases_invoice_ap_lines'"
                );
                $st->execute();
                $hasLines = ((int)$st->fetchColumn() > 0);
            }
        } catch (\Throwable $e) {
            $hasLines = false;
        }
        if (!$hasLines) {
            return [];
        }

        $rows = $pdo->prepare(
            "SELECT il.po_item_id, il.sku, COALESCE(il.qty,0) AS qty
             FROM purchases_invoice_ap_lines il
             INNER JOIN purchases_invoice_ap ih ON ih.id = il.ap_id
             WHERE ih.po_id = ?
               AND ih.deleted_at IS NULL
               AND ih.status <> 'HOLD_3WM'"
        );
        $rows->execute([$poId]);
        $result = [];
        while ($line = $rows->fetch(PDO::FETCH_ASSOC)) {
            $poItemId = $this->resolvePoItemId((array)$line, $poItems);
            if ($poItemId === null) {
                continue;
            }
            $result[$poItemId] = ($result[$poItemId] ?? 0.0) + (float)($line['qty'] ?? 0.0);
        }
        return $result;
    }

    /**
     * Resolve incoming/invoice line to PO item id.
     * Returns null if mapping is ambiguous or not found.
     *
     * @param array<string, mixed> $line
     * @param array<int, array{id:int, product_id:int, sku:string, qty:float, unit_price:float}> $poItems
     */
    private function resolvePoItemId(array $line, array $poItems): ?int
    {
        $poItemId = (int)($line['po_item_id'] ?? 0);
        if ($poItemId > 0 && isset($poItems[$poItemId])) {
            return $poItemId;
        }

        $productId = (int)($line['product_id'] ?? 0);
        if ($productId > 0) {
            $candidate = [];
            foreach ($poItems as $id => $poItem) {
                if ((int)$poItem['product_id'] === $productId) {
                    $candidate[] = $id;
                }
            }
            if (count($candidate) === 1) {
                return (int)$candidate[0];
            }
        }

        $sku = strtoupper(trim((string)($line['sku'] ?? '')));
        if ($sku !== '') {
            $candidate = [];
            foreach ($poItems as $id => $poItem) {
                if ($poItem['sku'] === $sku) {
                    $candidate[] = $id;
                }
            }
            if (count($candidate) === 1) {
                return (int)$candidate[0];
            }
        }
        return null;
    }
}
