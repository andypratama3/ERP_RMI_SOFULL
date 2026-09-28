<?php
declare(strict_types=1);

namespace App\Accounting;

use PDO;

final class BankReconService
{
    public function importCsvLines(PDO $pdo, int $statementId, string $csvPath): int
    {
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            return 0;
        }

        $header = fgetcsv($handle);
        $map = $this->mapHeader($header ?: []);
        $inserted = 0;

        $ins = $pdo->prepare(
            "INSERT INTO bank_statement_lines
            (statement_id, txn_date, description, reference_no, amount, txn_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );

        while (($row = fgetcsv($handle)) !== false) {
            $date = trim((string)($row[$map['date']] ?? ''));
            $desc = trim((string)($row[$map['description']] ?? ''));
            $ref  = trim((string)($row[$map['reference']] ?? ''));
            $amtRaw = trim((string)($row[$map['amount']] ?? '0'));
            $typeRaw = strtoupper(trim((string)($row[$map['type']] ?? 'DEBIT')));

            $amount = $this->toAmount($amtRaw);
            if ($amount <= 0 || !$this->isDate($date)) {
                continue;
            }
            $txnType = ($typeRaw === 'CREDIT' || $typeRaw === 'CR') ? 'CREDIT' : 'DEBIT';
            $ins->execute([$statementId, $date, $desc, $ref, $amount, $txnType]);
            $inserted++;
        }
        fclose($handle);
        return $inserted;
    }

    public function autoMatch(PDO $pdo, int $reconId, int $statementId, int $dayTolerance = 3): int
    {
        $lines = $pdo->prepare(
            "SELECT l.id, l.txn_date, l.amount
             FROM bank_statement_lines l
             LEFT JOIN bank_recon_matches m ON m.statement_line_id = l.id
             WHERE l.statement_id = ? AND m.id IS NULL
             ORDER BY l.txn_date ASC, l.id ASC"
        );
        $lines->execute([$statementId]);
        $lineRows = $lines->fetchAll(PDO::FETCH_ASSOC);
        if (!$lineRows) {
            return 0;
        }

        $matched = 0;
        $ins = $pdo->prepare(
            "INSERT INTO bank_recon_matches
            (reconciliation_id, statement_line_id, erp_txn_type, erp_txn_id, matched_amount, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())"
        );

        foreach ($lineRows as $line) {
            $txn = $this->findNearestErpTxn(
                $pdo,
                (string)$line['txn_date'],
                (float)$line['amount'],
                $dayTolerance
            );
            if ($txn === null) {
                continue;
            }
            $ins->execute([
                $reconId,
                (int)$line['id'],
                (string)$txn['type'],
                (int)$txn['id'],
                (float)$line['amount'],
            ]);
            $matched++;
        }
        return $matched;
    }

    public function candidateTxns(PDO $pdo, string $date, float $amount, int $dayTolerance = 7): array
    {
        $all = $this->erpTxns($pdo);
        $baseTs = strtotime($date) ?: time();
        $out = [];
        foreach ($all as $txn) {
            $delta = abs((strtotime((string)$txn['txn_date']) ?: $baseTs) - $baseTs) / 86400;
            if ((float)$txn['amount'] === $amount && $delta <= $dayTolerance) {
                $out[] = $txn;
            }
        }
        return $out;
    }

    public function unmatchedErpTxns(PDO $pdo, string $periodKey): array
    {
        $start = $periodKey . '-01';
        $end = date('Y-m-t', strtotime($start));
        $all = $this->erpTxns($pdo, $start, $end);

        $st = $pdo->query("SELECT erp_txn_type, erp_txn_id FROM bank_recon_matches");
        $mapped = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $mapped[(string)$m['erp_txn_type'] . '#' . (int)$m['erp_txn_id']] = true;
        }
        $unmatched = [];
        foreach ($all as $txn) {
            $key = (string)$txn['type'] . '#' . (int)$txn['id'];
            if (!isset($mapped[$key])) {
                $unmatched[] = $txn;
            }
        }
        return $unmatched;
    }

    private function findNearestErpTxn(PDO $pdo, string $date, float $amount, int $dayTolerance): ?array
    {
        $cand = $this->candidateTxns($pdo, $date, $amount, $dayTolerance);
        if (!$cand) {
            return null;
        }
        usort($cand, static function (array $a, array $b) use ($date): int {
            $ta = abs((strtotime((string)$a['txn_date']) ?: 0) - (strtotime($date) ?: 0));
            $tb = abs((strtotime((string)$b['txn_date']) ?: 0) - (strtotime($date) ?: 0));
            return $ta <=> $tb;
        });
        return $cand[0];
    }

    private function erpTxns(PDO $pdo, ?string $start = null, ?string $end = null): array
    {
        $out = [];
        $sources = [
            ['sql' => "SELECT id, pay_date AS txn_date, amount FROM purchases_payment_ap WHERE deleted_at IS NULL", 'type' => 'PURCHASE_AP_PAY'],
            ['sql' => "SELECT id, pay_date AS txn_date, amount FROM purchases_forwarder_payment WHERE deleted_at IS NULL", 'type' => 'FORWARDER_PAY'],
            ['sql' => "SELECT id, pay_date AS txn_date, amount FROM purchases_ceisa_payment WHERE deleted_at IS NULL", 'type' => 'CEISA_PAY'],
        ];

        foreach ($sources as $src) {
            $sql = $src['sql'];
            $params = [];
            if ($start !== null && $end !== null) {
                $sql .= " AND pay_date BETWEEN ? AND ?";
                $params = [$start, $end];
            }
            try {
                $st = $pdo->prepare($sql);
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $out[] = [
                        'type' => $src['type'],
                        'id' => (int)$row['id'],
                        'txn_date' => (string)$row['txn_date'],
                        'amount' => (float)$row['amount'],
                    ];
                }
            } catch (\Throwable $e) {
                // table might not exist in older schema
            }
        }
        return $out;
    }

    private function mapHeader(array $header): array
    {
        $lower = array_map(static fn($x): string => strtolower(trim((string)$x)), $header);
        $map = [
            'date' => 0,
            'description' => 1,
            'reference' => 2,
            'amount' => 3,
            'type' => 4,
        ];
        foreach ($lower as $idx => $name) {
            if (in_array($name, ['date', 'txn_date', 'tanggal'], true)) $map['date'] = $idx;
            if (in_array($name, ['description', 'desc', 'keterangan'], true)) $map['description'] = $idx;
            if (in_array($name, ['reference', 'ref', 'reference_no'], true)) $map['reference'] = $idx;
            if (in_array($name, ['amount', 'nominal'], true)) $map['amount'] = $idx;
            if (in_array($name, ['type', 'txn_type', 'dc'], true)) $map['type'] = $idx;
        }
        return $map;
    }

    private function toAmount(string $raw): float
    {
        $raw = trim($raw);
        $raw = str_replace([' ', ','], ['', '.'], $raw);
        return is_numeric($raw) ? (float)$raw : 0.0;
    }

    private function isDate(string $v): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    }
}
