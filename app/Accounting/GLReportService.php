<?php
declare(strict_types=1);

namespace App\Accounting;

use PDO;

final class GLReportService
{
    public function trialBalance(PDO $pdo, string $dateFrom, string $dateTo): array
    {
        $st = $pdo->prepare(
            "SELECT a.id, a.code, a.name, a.account_type,
                    SUM(l.dr_amount) AS total_dr,
                    SUM(l.cr_amount) AS total_cr,
                    SUM(l.dr_amount - l.cr_amount) AS balance
             FROM gl_journal_lines l
             JOIN gl_journal_headers h ON h.id = l.header_id
             JOIN gl_accounts a ON a.id = l.account_id
             WHERE h.journal_date BETWEEN ? AND ?
             GROUP BY a.id, a.code, a.name, a.account_type
             ORDER BY a.code ASC"
        );
        $st->execute([$dateFrom, $dateTo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function generalLedger(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): array
    {
        $st = $pdo->prepare(
            "SELECT h.id AS header_id, h.journal_no, h.journal_date, h.source_module, h.source_event, h.source_ref,
                    l.line_no, l.memo, l.dr_amount, l.cr_amount
             FROM gl_journal_lines l
             JOIN gl_journal_headers h ON h.id = l.header_id
             WHERE l.account_id = ?
               AND h.journal_date BETWEEN ? AND ?
             ORDER BY h.journal_date ASC, h.id ASC, l.line_no ASC"
        );
        $st->execute([$accountId, $dateFrom, $dateTo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $running = 0.0;
        foreach ($rows as &$r) {
            $running += (float)$r['dr_amount'] - (float)$r['cr_amount'];
            $r['running_balance'] = $running;
        }
        unset($r);
        return $rows;
    }

    public function journalListing(PDO $pdo, string $dateFrom, string $dateTo, string $sourceModule = '', string $sourceRef = ''): array
    {
        $where = ["h.journal_date BETWEEN ? AND ?"];
        $params = [$dateFrom, $dateTo];
        if ($sourceModule !== '') {
            $where[] = "h.source_module = ?";
            $params[] = $sourceModule;
        }
        if ($sourceRef !== '') {
            $where[] = "h.source_ref LIKE ?";
            $params[] = '%' . $sourceRef . '%';
        }
        $sql = "SELECT h.id, h.journal_no, h.journal_date, h.source_module, h.source_event, h.source_ref, h.description, h.status,
                       SUM(l.dr_amount) AS total_dr, SUM(l.cr_amount) AS total_cr
                FROM gl_journal_headers h
                JOIN gl_journal_lines l ON l.header_id = h.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY h.id, h.journal_no, h.journal_date, h.source_module, h.source_event, h.source_ref, h.description, h.status
                ORDER BY h.journal_date DESC, h.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function journalListingPaged(
        PDO $pdo,
        string $dateFrom,
        string $dateTo,
        string $sourceModule = '',
        string $sourceRef = '',
        int $page = 1,
        int $perPage = 50
    ): array {
        $all = $this->journalListing($pdo, $dateFrom, $dateTo, $sourceModule, $sourceRef);
        $total = count($all);
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;
        $rows = array_slice($all, $offset, $perPage);
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    public function trialBalancePaged(
        PDO $pdo,
        string $dateFrom,
        string $dateTo,
        string $accountType = '',
        string $search = '',
        int $page = 1,
        int $perPage = 50
    ): array {
        $all = $this->trialBalance($pdo, $dateFrom, $dateTo);
        if ($accountType !== '') {
            $all = array_values(array_filter($all, static function (array $r) use ($accountType): bool {
                return strtoupper((string)($r['account_type'] ?? '')) === strtoupper($accountType);
            }));
        }
        if ($search !== '') {
            $q = strtolower($search);
            $all = array_values(array_filter($all, static function (array $r) use ($q): bool {
                return str_contains(strtolower((string)($r['code'] ?? '')), $q)
                    || str_contains(strtolower((string)($r['name'] ?? '')), $q);
            }));
        }
        $total = count($all);
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;
        $rows = array_slice($all, $offset, $perPage);
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    public function generalLedgerPaged(
        PDO $pdo,
        int $accountId,
        string $dateFrom,
        string $dateTo,
        int $page = 1,
        int $perPage = 100
    ): array {
        $all = $this->generalLedger($pdo, $accountId, $dateFrom, $dateTo);
        $total = count($all);
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;
        $rows = array_slice($all, $offset, $perPage);
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }
}
