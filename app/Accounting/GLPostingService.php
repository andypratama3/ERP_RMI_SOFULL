<?php
declare(strict_types=1);

namespace App\Accounting;

use PDO;
use RuntimeException;

final class GLPostingService
{
    public function createJournalFromMapping(
        PDO $pdo,
        string $module,
        string $event,
        string $sourceRef,
        float $amount,
        string $description,
        int $userId = 0
    ): ?int {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }

        $st = $pdo->prepare(
            "SELECT debit_account_id, credit_account_id
             FROM gl_mappings
             WHERE module_name = ? AND event_name = ? AND is_active = 1
             LIMIT 1"
        );
        $st->execute([$module, $event]);
        $map = $st->fetch(PDO::FETCH_ASSOC);
        if (!$map) {
            return null;
        }

        return $this->createJournalLines(
            $pdo,
            $module,
            $event,
            $sourceRef,
            [
                ['account_id' => (int)$map['debit_account_id'], 'dr' => $amount, 'cr' => 0.0, 'memo' => $description],
                ['account_id' => (int)$map['credit_account_id'], 'dr' => 0.0, 'cr' => $amount, 'memo' => $description],
            ],
            $description,
            $userId
        );
    }

    public function createJournalLines(
        PDO $pdo,
        string $module,
        string $event,
        string $sourceRef,
        array $lines,
        string $description,
        int $userId = 0
    ): int {
        $st = $pdo->prepare(
            "SELECT id FROM gl_journal_headers
             WHERE source_module = ? AND source_event = ? AND source_ref = ?
             LIMIT 1"
        );
        $st->execute([$module, $event, $sourceRef]);
        $existing = $st->fetchColumn();
        if ($existing) {
            return (int)$existing;
        }

        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($lines as $line) {
            $sumDr += (float)($line['dr'] ?? 0);
            $sumCr += (float)($line['cr'] ?? 0);
        }
        if (abs($sumDr - $sumCr) > 0.00001) {
            throw new RuntimeException('Journal is not balanced.');
        }

        $journalNo = $this->generateJournalNo($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO gl_journal_headers
                (journal_no, journal_date, source_module, source_event, source_ref, description, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'POSTED', ?, ?)"
            )->execute([$journalNo, date('Y-m-d'), $module, $event, $sourceRef, $description, $userId ?: null, date('Y-m-d H:i:s')]);
            $headerId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare(
                "INSERT INTO gl_journal_lines
                (header_id, line_no, account_id, dr_amount, cr_amount, memo, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $lineNo = 1;
            foreach ($lines as $line) {
                $ins->execute([
                    $headerId,
                    $lineNo++,
                    (int)$line['account_id'],
                    (float)($line['dr'] ?? 0),
                    (float)($line['cr'] ?? 0),
                    (string)($line['memo'] ?? ''),
                    date('Y-m-d H:i:s'),
                ]);
            }

            $pdo->prepare(
                "INSERT INTO gl_posting_batches (batch_code, posted_at, posted_by, note)
                 VALUES (?, ?, ?, ?)"
            )->execute(['GL-' . date('YmdHis'), date('Y-m-d H:i:s'), $userId ?: null, $module . ':' . $sourceRef]);

            $pdo->commit();
            return $headerId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function reverseBySource(
        PDO $pdo,
        string $module,
        string $event,
        string $sourceRef,
        string $reason = '',
        int $userId = 0
    ): ?int {
        $st = $pdo->prepare(
            "SELECT id
             FROM gl_journal_headers
             WHERE source_module = ? AND source_event = ? AND source_ref = ?
             LIMIT 1"
        );
        $st->execute([$module, $event, $sourceRef]);
        $headerId = (int)$st->fetchColumn();
        if ($headerId <= 0) {
            return null;
        }
        return $this->reverseJournal($pdo, $headerId, $reason, $userId);
    }

    public function reverseJournal(PDO $pdo, int $headerId, string $reason = '', int $userId = 0): ?int
    {
        if ($headerId <= 0) {
            return null;
        }

        $st = $pdo->prepare("SELECT * FROM gl_journal_headers WHERE id=? LIMIT 1");
        $st->execute([$headerId]);
        $header = $st->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            return null;
        }

        // Idempotent reversal: if already reversed, return reversal id
        if ($this->columnExists($pdo, 'gl_journal_headers', 'reversal_of_header_id')) {
            $chk = $pdo->prepare("SELECT id FROM gl_journal_headers WHERE reversal_of_header_id=? LIMIT 1");
            $chk->execute([$headerId]);
            $existing = (int)$chk->fetchColumn();
            if ($existing > 0) {
                return $existing;
            }
        }

        $stL = $pdo->prepare("SELECT line_no, account_id, dr_amount, cr_amount, memo FROM gl_journal_lines WHERE header_id=? ORDER BY line_no ASC");
        $stL->execute([$headerId]);
        $lines = $stL->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) {
            return null;
        }

        $revNo = $this->generateJournalNo($pdo);
        $desc = 'REVERSAL ' . (string)($header['journal_no'] ?? ('#' . $headerId));
        if ($reason !== '') {
            $desc .= ' - ' . $reason;
        }

        $pdo->beginTransaction();
        try {
            $hasRevCol = $this->columnExists($pdo, 'gl_journal_headers', 'reversal_of_header_id');
            $hasReasonCol = $this->columnExists($pdo, 'gl_journal_headers', 'reverse_reason');
            $hasReversedAt = $this->columnExists($pdo, 'gl_journal_headers', 'reversed_at');
            $hasReversedBy = $this->columnExists($pdo, 'gl_journal_headers', 'reversed_by');

            $hdrCols = "journal_no, journal_date, source_module, source_event, source_ref, description, status, created_by, created_at";
            $hdrVals = "?, ?, ?, ?, ?, ?, 'POSTED', ?, ?";
            $params = [
                $revNo,
                date('Y-m-d'),
                (string)$header['source_module'],
                ((string)$header['source_event']) . '_REVERSAL',
                (string)$header['source_ref'],
                $desc,
                $userId ?: null,
                date('Y-m-d H:i:s'),
            ];
            if ($hasRevCol) {
                $hdrCols .= ", reversal_of_header_id";
                $hdrVals .= ", ?";
                $params[] = $headerId;
            }
            if ($hasReasonCol) {
                $hdrCols .= ", reverse_reason";
                $hdrVals .= ", ?";
                $params[] = ($reason !== '' ? $reason : null);
            }
            $insHdr = $pdo->prepare("INSERT INTO gl_journal_headers ($hdrCols) VALUES ($hdrVals)");
            $insHdr->execute($params);
            $revHeaderId = (int)$pdo->lastInsertId();

            $insL = $pdo->prepare(
                "INSERT INTO gl_journal_lines
                (header_id, line_no, account_id, dr_amount, cr_amount, memo, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($lines as $ln) {
                $insL->execute([
                    $revHeaderId,
                    (int)$ln['line_no'],
                    (int)$ln['account_id'],
                    (float)$ln['cr_amount'],
                    (float)$ln['dr_amount'],
                    (string)($ln['memo'] ?? ''),
                    date('Y-m-d H:i:s'),
                ]);
            }

            $set = "status='VOIDED'";
            $upd = [];
            if ($hasReversedAt) {
                $set .= ", reversed_at=?";
                $upd[] = date('Y-m-d H:i:s');
            }
            if ($hasReversedBy) {
                $set .= ", reversed_by=?";
                $upd[] = $userId ?: null;
            }
            $upd[] = $headerId;
            $pdo->prepare("UPDATE gl_journal_headers SET $set WHERE id=?")->execute($upd);

            $pdo->commit();
            return $revHeaderId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function generateJournalNo(PDO $pdo): string
    {
        $prefix = 'JRN-' . date('Ymd') . '-';
        $st = $pdo->prepare("SELECT journal_no FROM gl_journal_headers WHERE journal_no LIKE ? ORDER BY id DESC LIMIT 1");
        $st->execute([$prefix . '%']);
        $last = (string)$st->fetchColumn();
        $next = 1;
        if ($last !== '') {
            $seq = (int)substr($last, -4);
            $next = $seq + 1;
        }
        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND column_name = ?"
            );
            $st->execute([$table, $column]);
            return ((int)$st->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
