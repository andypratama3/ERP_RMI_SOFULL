<?php
declare(strict_types=1);

namespace App\Dashboard;

use PDO;

final class DashboardDetailService
{
    /** Status DO setelah CRM Submit (untuk rekap - tampilkan semua pipeline, exclude cancelled) */
    private const STATUSES_AFTER_CRM_SUBMIT = [
        'crm_to_wqs', 'sent_wqs', 'wqs_processing', 'wqs_done', 'ready_scm', 'scm_done',
        'on_delivery', 'delivered', 'act_done', 'wait_payment', 'paid', 'fin_done', 'paid_done', 'closed',
    ];

    private PDO $pdo;

    /** @var string[] */
    private array $revenueStatuses;

    public function __construct(PDO $pdo, array $revenueStatuses = ['delivered', 'wait_payment', 'paid', 'fin_done'])
    {
        $this->pdo = $pdo;
        $this->revenueStatuses = array_values(array_filter(array_map('strtolower', $revenueStatuses)));
        if (count($this->revenueStatuses) === 0) {
            $this->revenueStatuses = ['delivered', 'wait_payment', 'paid', 'fin_done'];
        }
    }

    public function build(string $month, int $year, string $asOfDate): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, (int)$month);
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        if ($asOfDate < $monthStart) {
            $asOfDate = $monthStart;
        }
        if ($asOfDate > $monthEnd) {
            $asOfDate = $monthEnd;
        }

        $officeMeta = $this->officeMeta();
        $sales = $this->salesAggregates($monthStart, $asOfDate);
        $dailySeries = $this->salesDailySeries($monthStart, $asOfDate);
        $targets = $this->targetAggregates((int)$month, $year);
        $finance = $this->financeAggregates($monthStart, $asOfDate);
        $adjustments = $this->adjustments((int)$month, $year);

        $section1 = $this->buildSectionTargetVsPencapaian((int)$month, $year, $monthStart, $asOfDate, $officeMeta, $targets, $sales);
        $section2 = $this->buildSectionFinanceDetail((int)$month, $year, $asOfDate, $officeMeta, $targets, $sales, $finance, $adjustments);

        return [
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
            'as_of' => $asOfDate,
            'office_meta' => $officeMeta,
            'targets' => $targets,
            'sales' => $sales,
            'daily_series' => $dailySeries,
            'section1' => $section1,
            'section2' => $section2,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function officeMeta(): array
    {
        $meta = [
            'BGR' => ['name' => 'Bogor', 'block' => 'MAIN'],
            'BKS' => ['name' => 'Bekasi', 'block' => 'MAIN'],
            'SLO' => ['name' => 'Solo', 'block' => 'MAIN'],
            'BDG' => ['name' => 'Bandung', 'block' => 'MAIN'],
            'SMG' => ['name' => 'Semarang', 'block' => 'MAIN'],
            'JGY' => ['name' => 'Depo Yogya', 'block' => 'MAIN'],
            'KAL' => ['name' => 'Depo Samarinda', 'block' => 'MAIN'],
            'TGR' => ['name' => 'RMI Tangerang', 'block' => 'TANGERANG'],
        ];

        $st = $this->pdo->query("SELECT id, UPPER(office_code) office_code, office_name FROM master_office");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $oc = (string)($row['office_code'] ?? '');
            if ($oc === '' || !isset($meta[$oc])) {
                continue;
            }
            $meta[$oc]['id'] = (int)$row['id'];
            $meta[$oc]['db_name'] = (string)($row['office_name'] ?? $meta[$oc]['name']);
        }

        return $meta;
    }

    /** @return array<string,mixed> */
    private function salesAggregates(string $monthStart, string $asOfDate): array
    {
        $in = implode(',', array_fill(0, count($this->revenueStatuses), '?'));
        $hasSegMap = $this->tableExists('kpi_customer_segment_map');
        $hasCustomers = $this->tableExists('master_customers');
        $collate = 'COLLATE utf8mb4_unicode_ci';
        $segJoin = $hasSegMap ? "LEFT JOIN kpi_customer_segment_map cm ON CONVERT(cm.customer_code USING utf8mb4) {$collate} = CONVERT(d.customers_code USING utf8mb4) {$collate}" : "LEFT JOIN (SELECT NULL AS customer_code, NULL AS segment) cm ON 1=0";
        $custJoin = $hasCustomers
            ? "LEFT JOIN master_customers c ON (c.id = d.customer_id OR CONVERT(c.customers_code USING utf8mb4) {$collate} = CONVERT(d.customers_code USING utf8mb4) {$collate})"
            : "LEFT JOIN (SELECT NULL AS id, NULL AS customers_code, NULL AS customer_group, NULL AS segment, NULL AS customer_type) c ON 1=0";
        $sql = "SELECT
                    UPPER(COALESCE(d.office_code,'SYS')) AS office_code,
                    CASE
                      WHEN UPPER(COALESCE(d.office_code,''))='JGY' THEN 'DEPO_YOGYA'
                      WHEN UPPER(COALESCE(d.office_code,''))='KAL' THEN 'DEPO_SAMARINDA'
                      WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(cm.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_group,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_type,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT' THEN 'ACCUNIT'
                      WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(cm.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_group,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_type,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA' THEN 'NON_HERMINA'
                      WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(cm.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_group,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_type,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA' THEN 'HERMINA'
                      ELSE 'NON_HERMINA'
                    END AS segment,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(COALESCE(NULLIF(d.total_amount,0), d.grand_total, 0)),0) AS net_sales,
                    COALESCE(SUM(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0)),0) AS gross_sales,
                    COALESCE(SUM(GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0),0)),0) AS ar_outstanding_total,
                    COALESCE(SUM(CASE WHEN d.do_date BETWEEN ? AND ? THEN GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0),0) ELSE 0 END),0) AS ar_new,
                    COALESCE(SUM(CASE WHEN d.do_date < ? THEN GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0),0) ELSE 0 END),0) AS ar_old
                FROM sales_do d
                {$custJoin}
                {$segJoin}
                WHERE d.do_date >= ?
                  AND d.do_date <= ?
                  AND LOWER(COALESCE(d.status,'')) IN ($in)
                GROUP BY UPPER(COALESCE(d.office_code,'SYS')), segment";

        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $params = array_merge([$monthStart, $monthEnd, $monthStart, $monthStart, $asOfDate], $this->revenueStatuses);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        $rows = [
            'by_office_segment' => [],
            'office_sales_mtd' => [],
            'segment_sales_mtd' => [],
            'ar_new_office' => [],
            'ar_old_office' => [],
            'invoice_count' => [],
        ];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $office = (string)$r['office_code'];
            $segment = (string)$r['segment'];
            $gross = (float)$r['gross_sales'];
            $net = (float)$r['net_sales'];
            $rows['by_office_segment'][$office][$segment] = [
                'gross_sales' => $gross,
                'net_sales' => $net,
                'invoice_count' => (int)$r['invoice_count'],
            ];
            $rows['office_sales_mtd'][$office] = ($rows['office_sales_mtd'][$office] ?? 0.0) + $net;
            $rows['segment_sales_mtd'][$segment] = ($rows['segment_sales_mtd'][$segment] ?? 0.0) + $net;
            $rows['ar_new_office'][$office] = ($rows['ar_new_office'][$office] ?? 0.0) + (float)$r['ar_new'];
            $rows['ar_old_office'][$office] = ($rows['ar_old_office'][$office] ?? 0.0) + (float)$r['ar_old'];
            $rows['invoice_count'][$office] = ($rows['invoice_count'][$office] ?? 0) + (int)$r['invoice_count'];
        }
        return $rows;
    }

    /**
     * Raw sales_do rows untuk rekap - tampilkan DO setelah CRM Submit (crm_to_wqs dst).
     * Flow: sales_do (source) → sales_do_rekap (bridge) → dashboard_detail (display)
     * By day & real-time: do_date MTD (monthStart..asOfDate).
     * Status: pakai STATUSES_AFTER_CRM_SUBMIT agar DO di pipeline (WQS, SCM, dll) ikut tampil.
     * Bila $useRevenueOnly=true (drill dari dashboard): pakai revenueStatuses agar angka match dengan Pencapaian.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getSalesDoRowsForRekap(string $monthStart, string $asOfDate, string $office, string $segment, string $type = 'sales', bool $useRevenueOnly = false): array
    {
        $statuses = $useRevenueOnly ? $this->revenueStatuses : array_map('strtolower', self::STATUSES_AFTER_CRM_SUBMIT);
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $hasSegMap = $this->tableExists('kpi_customer_segment_map');
        $hasCustomers = $this->tableExists('master_customers');
        $collate = 'COLLATE utf8mb4_unicode_ci';
        $segJoin = $hasSegMap ? "LEFT JOIN kpi_customer_segment_map cm ON CONVERT(cm.customer_code USING utf8mb4) {$collate} = CONVERT(d.customers_code USING utf8mb4) {$collate}" : "LEFT JOIN (SELECT NULL AS customer_code, NULL AS segment) cm ON 1=0";
        $custJoin = $hasCustomers
            ? "LEFT JOIN master_customers c ON (c.id = d.customer_id OR CONVERT(c.customers_code USING utf8mb4) {$collate} = CONVERT(d.customers_code USING utf8mb4) {$collate})"
            : "LEFT JOIN (SELECT NULL AS id, NULL AS customers_code, NULL AS customer_group, NULL AS segment, NULL AS customer_type) c ON 1=0";
        $segmentExpr = "CASE
          WHEN UPPER(COALESCE(d.office_code,''))='JGY' THEN 'DEPO_YOGYA'
          WHEN UPPER(COALESCE(d.office_code,''))='KAL' THEN 'DEPO_SAMARINDA'
          WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(cm.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT'
            OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_group,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT'
            OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT'
            OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_type,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT' THEN 'ACCUNIT'
          WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(cm.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA'
            OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA'
            OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_group,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA'
            OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_type,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA' THEN 'NON_HERMINA'
          WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(cm.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA'
            OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_group,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA'
            OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA'
            OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_type,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA' THEN 'HERMINA'
          ELSE 'NON_HERMINA'
        END";
        $officeWhere = $office !== '' ? " AND UPPER(COALESCE(d.office_code,'SYS')) = ?" : "";
        $officeParam = $office !== '' ? [$office] : [];
        if ($type === 'sales') {
            $sql = "SELECT d.id, d.do_code, d.tracking_code, d.do_date, d.customers_code, d.office_code, d.status,
                           d.total_amount, d.tax_amount, d.grand_total,
                           COALESCE(NULLIF(d.total_amount,0), d.grand_total, 0) AS net_amount,
                           ({$segmentExpr}) AS segment
                    FROM sales_do d
                    {$custJoin}
                    {$segJoin}
                    WHERE d.do_date >= ? AND d.do_date <= ?
                      AND LOWER(COALESCE(d.status,'')) IN ({$in})
                      {$officeWhere}
                    ORDER BY d.do_date DESC, d.id DESC";
            $params = array_merge([$monthStart, $asOfDate], $statuses, $officeParam);
        } elseif ($type === 'piutang_baru') {
            $sql = "SELECT d.id, d.do_code, d.tracking_code, d.do_date, d.customers_code, d.office_code, d.status,
                           d.total_amount, d.tax_amount, d.grand_total,
                           GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0), 0) AS outstanding,
                           ({$segmentExpr}) AS segment
                    FROM sales_do d
                    {$custJoin}
                    {$segJoin}
                    WHERE d.do_date <= ? AND d.do_date >= ?
                      AND GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0), 0) > 0
                      AND LOWER(COALESCE(d.status,'')) IN ({$in})
                      {$officeWhere}
                    ORDER BY d.do_date DESC, d.id DESC";
            $params = array_merge([$asOfDate, $monthStart], $statuses, $officeParam);
        } elseif ($type === 'piutang_lama') {
            $sql = "SELECT d.id, d.do_code, d.tracking_code, d.do_date, d.customers_code, d.office_code, d.status,
                           d.total_amount, d.tax_amount, d.grand_total,
                           GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0), 0) AS outstanding,
                           ({$segmentExpr}) AS segment
                    FROM sales_do d
                    {$custJoin}
                    {$segJoin}
                    WHERE d.do_date <= ? AND d.do_date < ?
                      AND GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0), 0) > 0
                      AND LOWER(COALESCE(d.status,'')) IN ({$in})
                      {$officeWhere}
                    ORDER BY d.do_date DESC, d.id DESC";
            $params = array_merge([$asOfDate, $monthStart], $statuses, $officeParam);
        } else {
            $type = 'sales';
            $sql = "SELECT d.id, d.do_code, d.tracking_code, d.do_date, d.customers_code, d.office_code, d.status,
                           d.total_amount, d.tax_amount, d.grand_total,
                           COALESCE(NULLIF(d.total_amount,0), d.grand_total, 0) AS net_amount,
                           ({$segmentExpr}) AS segment
                    FROM sales_do d
                    {$custJoin}
                    {$segJoin}
                    WHERE d.do_date >= ? AND d.do_date <= ?
                      AND LOWER(COALESCE(d.status,'')) IN ({$in})
                      {$officeWhere}
                    ORDER BY d.do_date DESC, d.id DESC";
            $params = array_merge([$monthStart, $asOfDate], $statuses, $officeParam);
        }
        if ($segment !== '') {
            $sql = "SELECT * FROM ({$sql}) sub WHERE segment = ?";
            $params[] = $segment;
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    private function targetAggregates(int $monthNo, int $yearNo): array
    {
        if (!$this->tableExists('kpi_targets')) {
            return ['by_segment_office' => [], 'segment_total' => [], 'office_total' => []];
        }
        $sql = "SELECT
                    CASE
                    WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(t.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')='NONHERMINA' THEN 'NON_HERMINA'
                    WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(t.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')='HERMINA' THEN 'HERMINA'
                    WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(t.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')='ACCUNIT' THEN 'ACCUNIT'
                    ELSE UPPER(COALESCE(t.segment,''))
                    END AS segment,
                    UPPER(COALESCE(o.office_code,'ALL')) AS office_code,
                    SUM(t.target_amount) AS target_amount
                FROM kpi_targets t
                LEFT JOIN master_office o ON o.id = t.office_id
                WHERE t.month_no = ? AND t.year_no = ?
                GROUP BY segment, UPPER(COALESCE(o.office_code,'ALL'))";
        $st = $this->pdo->prepare($sql);
        $st->execute([$monthNo, $yearNo]);

        $out = ['by_segment_office' => [], 'segment_total' => [], 'office_total' => []];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $segment = (string)$r['segment'];
            $office = (string)$r['office_code'];
            $amount = (float)$r['target_amount'];
            $out['by_segment_office'][$segment][$office] = $amount;
            $out['segment_total'][$segment] = ($out['segment_total'][$segment] ?? 0.0) + $amount;
            $out['office_total'][$office] = ($out['office_total'][$office] ?? 0.0) + $amount;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function financeAggregates(string $monthStart, string $asOfDate): array
    {
        return [
            'ap_outstanding' => $this->apOutstandingByOffice($asOfDate),
            'expense_by_office' => $this->expenseByOffice($monthStart, $asOfDate),
            'stock_by_office' => $this->stockByOffice($asOfDate),
            'bank_balances' => $this->bankBalanceByOffice($asOfDate),
            'corporate_rates' => $this->corporateRates(),
        ];
    }

    /** @return array<string,float> */
    private function apOutstandingByOffice(string $asOfDate): array
    {
        if (!$this->tableExists('purchases_invoice_ap') || !$this->tableExists('purchases_payment_ap')) {
            return [];
        }
        $sql = "SELECT
                    UPPER(COALESCE(ap.office_code,'SYS')) AS office_code,
                    COALESCE(SUM(ap.total_amount - COALESCE(paid.paid_amount,0)),0) AS outstanding
                FROM purchases_invoice_ap ap
                LEFT JOIN (
                  SELECT ap_id, SUM(amount) AS paid_amount
                  FROM purchases_payment_ap
                  WHERE deleted_at IS NULL AND pay_date <= ?
                  GROUP BY ap_id
                ) paid ON paid.ap_id = ap.id
                WHERE ap.deleted_at IS NULL
                  AND ap.invoice_date <= ?
                  AND ap.status IN ('UNPAID','PARTIAL')
                GROUP BY UPPER(COALESCE(ap.office_code,'SYS'))";
        $st = $this->pdo->prepare($sql);
        $st->execute([$asOfDate, $asOfDate]);
        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string)$r['office_code']] = (float)$r['outstanding'];
        }
        return $out;
    }

    /** @return array<string,array<string,float>> */
    private function expenseByOffice(string $monthStart, string $asOfDate): array
    {
        if (!$this->tableExists('kpi_gl_category_map') || !$this->tableExists('gl_journal_headers') || !$this->tableExists('gl_journal_lines')) {
            return [];
        }
        $sql = "SELECT
                    UPPER(
                      CASE
                        WHEN COALESCE(NULLIF(l.cost_center,''),'') <> '' THEN l.cost_center
                        WHEN h.source_ref LIKE '%-BGR-%' THEN 'BGR'
                        WHEN h.source_ref LIKE '%-BKS-%' THEN 'BKS'
                        WHEN h.source_ref LIKE '%-SLO-%' THEN 'SLO'
                        WHEN h.source_ref LIKE '%-BDG-%' THEN 'BDG'
                        WHEN h.source_ref LIKE '%-SMG-%' THEN 'SMG'
                        WHEN h.source_ref LIKE '%-JGY-%' THEN 'JGY'
                        WHEN h.source_ref LIKE '%-KAL-%' THEN 'KAL'
                        WHEN h.source_ref LIKE '%-TGR-%' THEN 'TGR'
                        ELSE 'ALL'
                      END
                    ) AS office_code,
                    UPPER(m.category) AS category,
                    COALESCE(SUM(l.dr_amount - l.cr_amount),0) AS amount
                FROM gl_journal_headers h
                JOIN gl_journal_lines l ON l.header_id = h.id
                JOIN kpi_gl_category_map m ON m.gl_account_id = l.account_id
                WHERE h.journal_date BETWEEN ? AND ?
                  AND h.status IN ('POSTED','DRAFT')
                GROUP BY office_code, UPPER(m.category)";
        $st = $this->pdo->prepare($sql);
        $st->execute([$monthStart, $asOfDate]);
        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $office = (string)$r['office_code'];
            $cat = (string)$r['category'];
            $out[$office][$cat] = (float)$r['amount'];
        }
        return $out;
    }

    /** @return array<string,float> */
    private function stockByOffice(string $asOfDate): array
    {
        $hasSnapshots = $this->tableExists('kpi_daily_snapshots');
        if ($hasSnapshots) {
            $st = $this->pdo->prepare("SELECT UPPER(COALESCE(o.office_code,'ALL')) AS office_code, SUM(JSON_EXTRACT(s.metrics_json, '$.stock_value')) AS stock_value
                                       FROM kpi_daily_snapshots s
                                       LEFT JOIN master_office o ON o.id = s.office_id
                                       WHERE s.snapshot_date=? AND s.segment='FINANCE_DETAIL'
                                       GROUP BY UPPER(COALESCE(o.office_code,'ALL'))");
            $st->execute([$asOfDate]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $out = [];
                foreach ($rows as $r) {
                    $out[(string)$r['office_code']] = (float)$r['stock_value'];
                }
                return $out;
            }
        }

        // Fallback: use total stock valuation and distribute by office sales share.
        if (!$this->tableExists('wqs_stock') || !$this->tableExists('master_products') || !$this->tableExists('sales_do')) {
            return [];
        }

        $sql = "SELECT COALESCE(SUM(ws.stock_qty * COALESCE(mp.price,0)),0) AS stock_value
                FROM wqs_stock ws
                JOIN master_products mp ON mp.id = ws.product_id";
        $totalStock = (float)$this->pdo->query($sql)->fetchColumn();

        $salesShare = [];
        $st = $this->pdo->prepare("SELECT UPPER(COALESCE(office_code,'SYS')) AS office_code, COALESCE(SUM(COALESCE(NULLIF(total_amount,0),grand_total,0)),0) AS sales
                                   FROM sales_do
                                   WHERE do_date <= ?
                                   GROUP BY UPPER(COALESCE(office_code,'SYS'))");
        $st->execute([$asOfDate]);
        $sumSales = 0.0;
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $salesShare[(string)$r['office_code']] = (float)$r['sales'];
            $sumSales += (float)$r['sales'];
        }
        if ($sumSales <= 0) {
            return [];
        }
        $out = [];
        foreach ($salesShare as $office => $sales) {
            $out[$office] = ($sales / $sumSales) * $totalStock;
        }
        return $out;
    }

    /** @return array<string,float> */
    private function bankBalanceByOffice(string $asOfDate): array
    {
        if (!$this->tableExists('master_company_bank_accounts')) {
            return [];
        }
        if (!$this->columnExists('master_company_bank_accounts', 'balance')) {
            // Current schema stores bank master only (no running balance field).
            return [];
        }
        $sql = "SELECT UPPER(COALESCE(office_code,'ALL')) AS office_code, COALESCE(SUM(balance),0) AS balance
                FROM master_company_bank_accounts
                WHERE COALESCE(is_active,1)=1
                GROUP BY UPPER(COALESCE(office_code,'ALL'))";
        $st = $this->pdo->query($sql);
        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string)$r['office_code']] = (float)$r['balance'];
        }
        return $out;
    }

    /** @return array<string,float> */
    private function corporateRates(): array
    {
        $out = [
            'MAIN' => 0.10,
            'ACCUNIT' => 0.05,
            'TANGERANG' => 0.10,
        ];
        if (!$this->tableExists('kpi_corporate_rates')) {
            return $out;
        }
        $st = $this->pdo->query("SELECT UPPER(segment) AS segment, rate_percent FROM kpi_corporate_rates WHERE is_active=1");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $seg = (string)$r['segment'];
            $out[$seg] = ((float)$r['rate_percent']) / 100.0;
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function adjustments(int $monthNo, int $yearNo): array
    {
        if (!$this->tableExists('kpi_adjustments')) {
            return [];
        }
        $sql = "SELECT office_id, field, amount, note FROM kpi_adjustments WHERE month_no=? AND year_no=?";
        $st = $this->pdo->prepare($sql);
        $st->execute([$monthNo, $yearNo]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    private function buildSectionTargetVsPencapaian(int $monthNo, int $yearNo, string $monthStart, string $asOfDate, array $officeMeta, array $targets, array $sales): array
    {
        $mainOffices = ['BGR', 'BKS', 'SLO', 'BDG', 'SMG', 'TGR'];
        $herminaOffices = ['BGR', 'BKS', 'SLO', 'BDG', 'SMG', 'JGY', 'KAL', 'TGR'];

        $nonHermina = $this->rowsTargetAchievementByOffice('NON_HERMINA', $mainOffices, $asOfDate, $officeMeta, $targets, $sales);
        $hermina = $this->rowsTargetAchievementByOffice('HERMINA', $herminaOffices, $asOfDate, $officeMeta, $targets, $sales, true);
        $accunit = $this->rowsTargetAchievementByOffice('ACCUNIT', $mainOffices, $asOfDate, $officeMeta, $targets, $sales);

        $ringkasanRows = [];
        // Ringkasan ALL OFFICE mencakup seluruh kantor termasuk TGR.
        // Depo JGY/KAL tetap ditampilkan terpisah agar struktur lama tidak berubah.
        $mainCore = ['BGR', 'BKS', 'SLO', 'BDG', 'SMG', 'TGR'];
        $summaryDefs = [
            'NON_HERMINA' => [
                'target' => $this->targetForSegmentOffices('NON_HERMINA', $mainCore, $targets),
                'pencapaian' => $this->salesForSegmentOffices('NON_HERMINA', $mainCore, $sales),
            ],
            'HERMINA' => [
                'target' => $this->targetForSegmentOffices('HERMINA', $mainCore, $targets),
                'pencapaian' => $this->salesForSegmentOffices('HERMINA', $mainCore, $sales),
            ],
            'DEPO_YOGYA' => [
                'target' => (float)($targets['office_total']['JGY'] ?? 0),
                'pencapaian' => (float)($sales['office_sales_mtd']['JGY'] ?? 0),
            ],
            'DEPO_SAMARINDA' => [
                'target' => (float)($targets['office_total']['KAL'] ?? 0),
                'pencapaian' => (float)($sales['office_sales_mtd']['KAL'] ?? 0),
            ],
        ];
        foreach ($summaryDefs as $seg => $vals) {
            $target = (float)$vals['target'];
            $p = (float)$vals['pencapaian'];
            $ringkasanRows[] = [
                'label' => $this->labelForSegment($seg),
                'segment' => $seg,
                'target' => $target,
                'pencapaian' => $p,
                'persentase' => $this->pct($p, $target),
            ];
        }
        $ringkasanRows[] = $this->sumSummaryRow('TOTAL', $ringkasanRows);

        $tangerangRows = [];
        $tgTarget = (float)($targets['by_segment_office']['HERMINA']['TGR'] ?? 0) + (float)($targets['by_segment_office']['NON_HERMINA']['TGR'] ?? 0);
        $tgHermina = (float)($sales['by_office_segment']['TGR']['HERMINA']['net_sales'] ?? 0);
        $tgNon = (float)($sales['by_office_segment']['TGR']['NON_HERMINA']['net_sales'] ?? 0);
        $tangerangRows[] = ['label' => 'Hermina', 'target' => (float)($targets['by_segment_office']['HERMINA']['TGR'] ?? 0), 'pencapaian' => $tgHermina, 'persentase' => $this->pct($tgHermina, (float)($targets['by_segment_office']['HERMINA']['TGR'] ?? 0))];
        $tangerangRows[] = ['label' => 'Non Hermina', 'target' => (float)($targets['by_segment_office']['NON_HERMINA']['TGR'] ?? 0), 'pencapaian' => $tgNon, 'persentase' => $this->pct($tgNon, (float)($targets['by_segment_office']['NON_HERMINA']['TGR'] ?? 0))];
        $tangerangRows[] = ['label' => 'TOTAL', 'target' => $tgTarget, 'pencapaian' => $tgHermina + $tgNon, 'persentase' => $this->pct($tgHermina + $tgNon, $tgTarget), 'is_total' => true];

        $allCabangRows = [];
        $allCabangRows[] = ['tanggal' => $asOfDate, 'keterangan' => 'BMHP Semua Office (termasuk RMI Tangerang)', 'target' => (float)($ringkasanRows[count($ringkasanRows)-1]['target'] ?? 0), 'pencapaian' => (float)($ringkasanRows[count($ringkasanRows)-1]['pencapaian'] ?? 0), 'drill_segment' => null, 'drill_office' => null];
        $allCabangRows[] = ['tanggal' => $asOfDate, 'keterangan' => 'ACCUNIT', 'target' => (float)($targets['segment_total']['ACCUNIT'] ?? 0), 'pencapaian' => (float)($sales['segment_sales_mtd']['ACCUNIT'] ?? 0), 'drill_segment' => 'ACCUNIT', 'drill_office' => null];
        foreach ($allCabangRows as &$row) {
            $row['persentase'] = $this->pct((float)$row['pencapaian'], (float)$row['target']);
        }
        unset($row);
        $targetAll = 0.0;
        $achAll = 0.0;
        foreach ($allCabangRows as $r) {
            $targetAll += (float)$r['target'];
            $achAll += (float)$r['pencapaian'];
        }
        $allCabangRows[] = ['tanggal' => $asOfDate, 'keterangan' => 'TOTAL', 'target' => $targetAll, 'pencapaian' => $achAll, 'persentase' => $this->pct($achAll, $targetAll), 'is_total' => true, 'drill_segment' => null, 'drill_office' => null];

        $currentTotal = 0.0;
        foreach (['BGR', 'BKS', 'SLO', 'BDG', 'SMG', 'JGY', 'KAL', 'TGR'] as $oc) {
            $currentTotal += (float)($sales['office_sales_mtd'][$oc] ?? 0);
        }

        return [
            'comparisons' => $this->buildComparisonPack($monthNo, $yearNo, $monthStart, $asOfDate, $currentTotal),
            'non_hermina' => $nonHermina,
            'hermina' => $hermina,
            'ringkasan' => $ringkasanRows,
            'accunit' => $accunit,
            'tangerang' => $tangerangRows,
            'all_cabang' => $allCabangRows,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function rowsTargetAchievementByOffice(string $segment, array $offices, string $asOfDate, array $officeMeta, array $targets, array $sales, bool $forHerminaTable = false): array
    {
        $rows = [];
        foreach ($offices as $oc) {
            $target = (float)($targets['by_segment_office'][$segment][$oc] ?? 0);
            if ($forHerminaTable && in_array($oc, ['JGY', 'KAL'], true)) {
                $p = (float)($sales['office_sales_mtd'][$oc] ?? 0);
            } else {
                $p = (float)($sales['by_office_segment'][$oc][$segment]['net_sales'] ?? 0);
            }
            $rows[] = [
                'tanggal' => $asOfDate,
                'office_code' => $oc,
                'office' => (string)($officeMeta[$oc]['name'] ?? $oc),
                'target' => $target,
                'pencapaian' => $p,
                'persentase' => $this->pct($p, $target),
            ];
        }
        $rows[] = $this->sumOfficeRows($asOfDate, $rows);
        return $rows;
    }

    /** @return array<string,mixed> */
    private function buildSectionFinanceDetail(int $monthNo, int $yearNo, string $asOfDate, array $officeMeta, array $targets, array $sales, array $finance, array $adjustments): array
    {
        $mainOffices = ['BGR', 'BKS', 'SLO', 'BDG', 'SMG', 'JGY', 'KAL'];
        $tgrOffices = ['TGR'];
        $rates = $finance['corporate_rates'];

        $mainRows = $this->financeRowsByOffices($mainOffices, 'MAIN', $officeMeta, $sales, $finance, $adjustments);
        $accRows = $this->financeRowsByOffices($mainOffices, 'ACCUNIT', $officeMeta, $sales, $finance, $adjustments, 'ACCUNIT');
        $tgrRows = $this->financeRowsByOffices($tgrOffices, 'TANGERANG', $officeMeta, $sales, $finance, $adjustments);

        $mainTotal = $this->sumFinanceRows($mainRows, 'TOTAL');
        $accTotal = $this->sumFinanceRows($accRows, 'TOTAL ACCUNIT');
        $tgrTotal = $this->sumFinanceRows($tgrRows, 'TOTAL TANGERANG');

        $mainTargetSales = $this->targetSalesByOffices($mainOffices, $targets);
        $accTargetSales = (float)($targets['segment_total']['ACCUNIT'] ?? 0);
        $tgrTargetSales = (float)($targets['by_segment_office']['HERMINA']['TGR'] ?? 0) + (float)($targets['by_segment_office']['NON_HERMINA']['TGR'] ?? 0);

        $ringBottom = [
            'label' => 'Total Penjualan BMHP + ACCUNIT',
            'value' => (float)$mainTotal['penjualan'] + (float)$accTotal['penjualan'] + (float)$tgrTotal['penjualan'],
        ];

        return [
            'rates' => $rates,
            'main' => [
                'rows' => $mainRows,
                'total' => $mainTotal,
                'target_penjualan' => $mainTargetSales,
                'pencapaian_pct' => $this->pct((float)$mainTotal['penjualan'], $mainTargetSales),
                'saldo_label' => 'Saldo Rekening Giro',
                'saldo_value' => $this->sumBankBalanceByOffices($mainOffices, $finance['bank_balances']),
            ],
            'accunit' => [
                'rows' => $accRows,
                'total' => $accTotal,
                'target_penjualan' => $accTargetSales,
                'pencapaian_pct' => $this->pct((float)$accTotal['penjualan'], $accTargetSales),
                'saldo_label' => 'Saldo Rekening Koran Accunit',
                'saldo_value' => $this->sumBankBalanceByOffices($mainOffices, $finance['bank_balances']),
            ],
            'tangerang' => [
                'rows' => $tgrRows,
                'total' => $tgrTotal,
                'target_penjualan' => $tgrTargetSales,
                'pencapaian_pct' => $this->pct((float)$tgrTotal['penjualan'], $tgrTargetSales),
            ],
            'bottom' => $ringBottom,
            'as_of' => $asOfDate,
            'month_no' => $monthNo,
            'year_no' => $yearNo,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function financeRowsByOffices(array $offices, string $rateSegment, array $officeMeta, array $sales, array $finance, array $adjustments, ?string $forceSalesSegment = null): array
    {
        $rows = [];
        $rate = (float)($finance['corporate_rates'][$rateSegment] ?? 0.0);

        foreach ($offices as $idx => $oc) {
            $officeSales = (float)($sales['office_sales_mtd'][$oc] ?? 0);
            if ($forceSalesSegment !== null) {
                $officeSales = (float)($sales['by_office_segment'][$oc][$forceSalesSegment]['net_sales'] ?? 0);
            }
            $opex = (float)($finance['expense_by_office'][$oc]['OPERASIONAL'] ?? 0);
            $beban = (float)($finance['expense_by_office'][$oc]['BEBAN_GAJI'] ?? 0);
            $support = (float)($finance['expense_by_office'][$oc]['SUPPORT'] ?? 0);
            $fee = (float)($finance['expense_by_office'][$oc]['FEE_MGMT'] ?? 0);
            $pph = (float)($finance['expense_by_office'][$oc]['PPH'] ?? 0);
            $corporate = $officeSales * $rate;
            $costTotal = $opex + $beban + $support + $fee + $pph + $corporate;
            $pct = $this->pct($costTotal, $officeSales);
            $hutang = (float)($finance['ap_outstanding'][$oc] ?? 0);
            $piuBaru = (float)($sales['ar_new_office'][$oc] ?? 0);
            $piuLama = (float)($sales['ar_old_office'][$oc] ?? 0);
            $totalPiu = $piuBaru + $piuLama;
            $stock = (float)($finance['stock_by_office'][$oc] ?? 0);
            $profit = $officeSales - $costTotal;
            $workingCap = $stock + $totalPiu - $hutang;
            $jumlah = $profit + $workingCap;
            $adj = $this->adjustmentAmountForOffice($adjustments, (int)($officeMeta[$oc]['id'] ?? 0));
            $jumlahFinal = $jumlah + $adj;

            $rows[] = [
                'no' => $idx + 1,
                'office_code' => $oc,
                'office' => (string)($officeMeta[$oc]['name'] ?? $oc),
                'penjualan' => $officeSales,
                'operasional' => $opex,
                'beban_gaji' => $beban,
                'support' => $support,
                'fee_management' => $fee,
                'pph' => $pph,
                'corporate' => $corporate,
                'corporate_rate' => $rate * 100.0,
                'persentase' => $pct,
                'hutang' => $hutang,
                'piutang_baru' => $piuBaru,
                'piutang_lama' => $piuLama,
                'total_piutang' => $totalPiu,
                'stock_by_office' => $stock,
                'jumlah' => $jumlahFinal,
                'adjustment' => $adj,
            ];
        }

        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sumFinanceRows(array $rows, string $label): array
    {
        $sum = [
            'label' => $label,
            'penjualan' => 0.0,
            'operasional' => 0.0,
            'beban_gaji' => 0.0,
            'support' => 0.0,
            'fee_management' => 0.0,
            'pph' => 0.0,
            'corporate' => 0.0,
            'hutang' => 0.0,
            'piutang_baru' => 0.0,
            'piutang_lama' => 0.0,
            'total_piutang' => 0.0,
            'stock_by_office' => 0.0,
            'jumlah' => 0.0,
        ];
        foreach ($rows as $r) {
            $sum['penjualan'] += (float)$r['penjualan'];
            $sum['operasional'] += (float)$r['operasional'];
            $sum['beban_gaji'] += (float)$r['beban_gaji'];
            $sum['support'] += (float)$r['support'];
            $sum['fee_management'] += (float)$r['fee_management'];
            $sum['pph'] += (float)$r['pph'];
            $sum['corporate'] += (float)$r['corporate'];
            $sum['hutang'] += (float)$r['hutang'];
            $sum['piutang_baru'] += (float)$r['piutang_baru'];
            $sum['piutang_lama'] += (float)$r['piutang_lama'];
            $sum['total_piutang'] += (float)$r['total_piutang'];
            $sum['stock_by_office'] += (float)$r['stock_by_office'];
            $sum['jumlah'] += (float)$r['jumlah'];
        }
        $cost = $sum['operasional'] + $sum['beban_gaji'] + $sum['support'] + $sum['fee_management'] + $sum['pph'] + $sum['corporate'];
        $sum['persentase'] = $this->pct($cost, (float)$sum['penjualan']);
        return $sum;
    }

    private function targetForSegmentOffices(string $segment, array $offices, array $targets): float
    {
        $sum = 0.0;
        foreach ($offices as $oc) {
            $sum += (float)($targets['by_segment_office'][$segment][$oc] ?? 0);
        }
        return $sum;
    }

    private function salesForSegmentOffices(string $segment, array $offices, array $sales): float
    {
        $sum = 0.0;
        foreach ($offices as $oc) {
            $sum += (float)($sales['by_office_segment'][$oc][$segment]['net_sales'] ?? 0);
        }
        return $sum;
    }

    private function targetSalesByOffices(array $offices, array $targets): float
    {
        $sum = 0.0;
        foreach ($offices as $oc) {
            foreach (['NON_HERMINA', 'HERMINA', 'DEPO_YOGYA', 'DEPO_SAMARINDA'] as $seg) {
                $sum += (float)($targets['by_segment_office'][$seg][$oc] ?? 0);
            }
        }
        return $sum;
    }

    private function sumBankBalanceByOffices(array $offices, array $bank): ?float
    {
        if (count($bank) === 0) {
            return null;
        }
        $sum = 0.0;
        foreach ($offices as $oc) {
            $sum += (float)($bank[$oc] ?? 0);
        }
        return $sum;
    }

    /**
     * @param array<int,array<string,mixed>> $adjustments
     */
    private function adjustmentAmountForOffice(array $adjustments, int $officeId): float
    {
        $sum = 0.0;
        foreach ($adjustments as $adj) {
            if ((int)($adj['office_id'] ?? 0) !== $officeId) {
                continue;
            }
            $sum += (float)($adj['amount'] ?? 0);
        }
        return $sum;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sumSummaryRow(string $label, array $rows): array
    {
        $t = 0.0;
        $p = 0.0;
        foreach ($rows as $r) {
            $t += (float)$r['target'];
            $p += (float)$r['pencapaian'];
        }
        return ['label' => $label, 'target' => $t, 'pencapaian' => $p, 'persentase' => $this->pct($p, $t), 'is_total' => true];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sumOfficeRows(string $asOfDate, array $rows): array
    {
        $t = 0.0;
        $p = 0.0;
        foreach ($rows as $r) {
            $t += (float)$r['target'];
            $p += (float)$r['pencapaian'];
        }
        return [
            'tanggal' => $asOfDate,
            'office_code' => 'TOTAL',
            'office' => 'TOTAL',
            'target' => $t,
            'pencapaian' => $p,
            'persentase' => $this->pct($p, $t),
            'is_total' => true,
        ];
    }

    private function labelForSegment(string $segment): string
    {
        return match ($segment) {
            'NON_HERMINA' => 'Non Hermina',
            'HERMINA' => 'Hermina',
            'DEPO_YOGYA' => 'Depo Yogya',
            'DEPO_SAMARINDA' => 'Depo Samarinda',
            default => $segment,
        };
    }

    private function pct(float $num, float $den): float
    {
        if ($den == 0.0) {
            return 0.0;
        }
        return ($num / $den) * 100.0;
    }

    private function tableExists(string $table): bool
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute([$table]);
        return ((int)$st->fetchColumn()) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $st->execute([$table, $column]);
        return ((int)$st->fetchColumn()) > 0;
    }

    /** @return array<string,mixed> */
    private function buildComparisonPack(int $monthNo, int $yearNo, string $monthStart, string $asOfDate, float $currentTotal): array
    {
        $offices = ['BGR', 'BKS', 'SLO', 'BDG', 'SMG', 'JGY', 'KAL', 'TGR'];
        $currentDay = (int)date('d', strtotime($asOfDate));

        // VS 3 Bulan: compare to average of previous 3 months with same day-cap.
        $avg3 = 0.0;
        $count3 = 0;
        for ($i = 1; $i <= 3; $i++) {
            $s = date('Y-m-01', strtotime($monthStart . " -{$i} month"));
            $eom = date('Y-m-t', strtotime($s));
            $e = date('Y-m-d', strtotime($s . ' +' . max(0, $currentDay - 1) . ' day'));
            if ($e > $eom) {
                $e = $eom;
            }
            $avg3 += $this->salesTotalForRange($s, $e, $offices);
            $count3++;
        }
        $avg3 = $count3 > 0 ? ($avg3 / $count3) : 0.0;

        // VS Tahun: same month previous year with same day-cap.
        $prevYearStart = sprintf('%04d-%02d-01', $yearNo - 1, $monthNo);
        $prevYearEom = date('Y-m-t', strtotime($prevYearStart));
        $prevYearEnd = date('Y-m-d', strtotime($prevYearStart . ' +' . max(0, $currentDay - 1) . ' day'));
        if ($prevYearEnd > $prevYearEom) {
            $prevYearEnd = $prevYearEom;
        }
        $vsYearVal = $this->salesTotalForRange($prevYearStart, $prevYearEnd, $offices);

        // VS Pencapaian Tertinggi: highest monthly total in last 24 months.
        $peak = $this->highestMonthlyAchievement($offices, $monthStart);

        // VS Hari Kerja yang Sama: compare to previous month at same workday ordinal.
        $workdayNo = $this->workdayOrdinal($monthStart, $asOfDate);
        $prevMonthStart = date('Y-m-01', strtotime($monthStart . ' -1 month'));
        $prevMonthEndByWorkday = $this->dateAtWorkdayOrdinal($prevMonthStart, $workdayNo);
        $vsWorkdayVal = $this->salesTotalForRange($prevMonthStart, $prevMonthEndByWorkday, $offices);

        return [
            'current_total' => $currentTotal,
            'vs_3_bulan' => $this->comparisonResult($currentTotal, $avg3, 'Rata-rata 3 bulan sebelumnya (MTD day-cap)'),
            'vs_tahun' => $this->comparisonResult($currentTotal, $vsYearVal, 'Bulan yang sama tahun lalu (MTD day-cap)'),
            'vs_tertinggi' => $this->comparisonResult($currentTotal, (float)$peak['value'], 'Pencapaian tertinggi bulanan (24 bulan) ' . (string)$peak['period']),
            'vs_hari_kerja_sama' => $this->comparisonResult($currentTotal, $vsWorkdayVal, 'Bulan lalu sampai hari kerja ke-' . $workdayNo),
        ];
    }

    /** @return array<string,mixed> */
    private function comparisonResult(float $current, float $base, string $note): array
    {
        $delta = $current - $base;
        $pct = $base == 0.0 ? 0.0 : (($delta / $base) * 100.0);
        return [
            'base' => $base,
            'delta' => $delta,
            'delta_pct' => $pct,
            'note' => $note,
        ];
    }

    private function salesTotalForRange(string $startDate, string $endDate, array $offices): float
    {
        if (!$this->tableExists('sales_do')) {
            return 0.0;
        }
        $inStatus = implode(',', array_fill(0, count($this->revenueStatuses), '?'));
        $inOffice = implode(',', array_fill(0, count($offices), '?'));
        $sql = "SELECT COALESCE(SUM(COALESCE(NULLIF(total_amount,0),grand_total,0)),0)
                FROM sales_do
                WHERE do_date BETWEEN ? AND ?
                  AND LOWER(COALESCE(status,'')) IN ({$inStatus})
                  AND UPPER(COALESCE(office_code,'')) IN ({$inOffice})";
        $params = array_merge([$startDate, $endDate], $this->revenueStatuses, $offices);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return (float)$st->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function salesDailySeries(string $monthStart, string $asOfDate): array
    {
        if (!$this->tableExists('sales_do')) {
            return [
                'labels' => [],
                'daily_total' => [],
                'daily_by_segment' => [],
                'cumulative_total' => [],
            ];
        }

        $in = implode(',', array_fill(0, count($this->revenueStatuses), '?'));
        $hasSegMap = $this->tableExists('kpi_customer_segment_map');
        $hasCustomers = $this->tableExists('master_customers');
        $collate = 'COLLATE utf8mb4_unicode_ci';
        $segJoin = $hasSegMap
            ? "LEFT JOIN kpi_customer_segment_map cm ON CONVERT(cm.customer_code USING utf8mb4) {$collate} = CONVERT(d.customers_code USING utf8mb4) {$collate}"
            : "LEFT JOIN (SELECT NULL AS customer_code, NULL AS segment) cm ON 1=0";
        $custJoin = $hasCustomers
            ? "LEFT JOIN master_customers c ON (c.id = d.customer_id OR CONVERT(c.customers_code USING utf8mb4) {$collate} = CONVERT(d.customers_code USING utf8mb4) {$collate})"
            : "LEFT JOIN (SELECT NULL AS id, NULL AS customers_code, NULL AS customer_group, NULL AS segment, NULL AS customer_type) c ON 1=0";

        $sql = "SELECT
                    d.do_date AS ddate,
                    CASE
                      WHEN UPPER(COALESCE(d.office_code,''))='JGY' THEN 'DEPO_YOGYA'
                      WHEN UPPER(COALESCE(d.office_code,''))='KAL' THEN 'DEPO_SAMARINDA'
                      WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(cm.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_group,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_type,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'ACCUNIT' THEN 'ACCUNIT'
                      WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(cm.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_group,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_type,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'NONHERMINA' THEN 'NON_HERMINA'
                      WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(cm.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_group,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA'
                        OR REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_type,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','') = 'HERMINA' THEN 'HERMINA'
                      ELSE 'NON_HERMINA'
                    END AS segment,
                    COALESCE(SUM(COALESCE(NULLIF(d.total_amount,0), d.grand_total, 0)),0) AS net_sales
                FROM sales_do d
                {$custJoin}
                {$segJoin}
                WHERE d.do_date BETWEEN ? AND ?
                  AND LOWER(COALESCE(d.status,'')) IN ({$in})
                GROUP BY d.do_date, segment
                ORDER BY d.do_date ASC";

        $params = array_merge([$monthStart, $asOfDate], $this->revenueStatuses);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        $byDate = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $d = (string)($r['ddate'] ?? '');
            if ($d === '') {
                continue;
            }
            $seg = (string)($r['segment'] ?? 'NON_HERMINA');
            $val = (float)($r['net_sales'] ?? 0);
            $byDate[$d]['total'] = (float)($byDate[$d]['total'] ?? 0) + $val;
            $byDate[$d]['segments'][$seg] = (float)($byDate[$d]['segments'][$seg] ?? 0) + $val;
        }

        $labels = [];
        $daily = [];
        $cum = [];
        $dailyBySegment = [];
        $running = 0.0;
        $d = strtotime($monthStart);
        $end = strtotime($asOfDate);
        while ($d <= $end) {
            $key = date('Y-m-d', $d);
            $labels[] = $key;
            $v = (float)($byDate[$key]['total'] ?? 0);
            $daily[] = $v;
            $running += $v;
            $cum[] = $running;
            foreach (($byDate[$key]['segments'] ?? []) as $seg => $val) {
                $dailyBySegment[$seg][] = (float)$val;
            }
            foreach (['NON_HERMINA', 'HERMINA', 'ACCUNIT', 'DEPO_YOGYA', 'DEPO_SAMARINDA'] as $seg) {
                if (!isset($dailyBySegment[$seg])) {
                    $dailyBySegment[$seg] = [];
                }
                if (count($dailyBySegment[$seg]) < count($labels)) {
                    $dailyBySegment[$seg][] = 0.0;
                }
            }
            $d = strtotime('+1 day', $d);
        }

        return [
            'labels' => $labels,
            'daily_total' => $daily,
            'daily_by_segment' => $dailyBySegment,
            'cumulative_total' => $cum,
        ];
    }

    /** @return array{value:float,period:string} */
    private function highestMonthlyAchievement(array $offices, string $currentMonthStart): array
    {
        $startWindow = date('Y-m-01', strtotime($currentMonthStart . ' -23 month'));
        $inStatus = implode(',', array_fill(0, count($this->revenueStatuses), '?'));
        $inOffice = implode(',', array_fill(0, count($offices), '?'));
        $sql = "SELECT DATE_FORMAT(do_date, '%Y-%m') AS ym,
                       COALESCE(SUM(COALESCE(NULLIF(total_amount,0),grand_total,0)),0) AS val
                FROM sales_do
                WHERE do_date >= ?
                  AND LOWER(COALESCE(status,'')) IN ({$inStatus})
                  AND UPPER(COALESCE(office_code,'')) IN ({$inOffice})
                GROUP BY DATE_FORMAT(do_date, '%Y-%m')
                ORDER BY val DESC
                LIMIT 1";
        $params = array_merge([$startWindow], $this->revenueStatuses, $offices);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['value' => 0.0, 'period' => '-'];
        }
        return ['value' => (float)$row['val'], 'period' => (string)$row['ym']];
    }

    private function workdayOrdinal(string $monthStart, string $date): int
    {
        $d = strtotime($monthStart);
        $end = strtotime($date);
        $ord = 0;
        while ($d <= $end) {
            $n = (int)date('N', $d); // 1..7
            if ($n <= 6) { // Mon-Sat
                $ord++;
            }
            $d = strtotime('+1 day', $d);
        }
        return max(1, $ord);
    }

    private function dateAtWorkdayOrdinal(string $monthStart, int $ordinal): string
    {
        $eom = date('Y-m-t', strtotime($monthStart));
        $d = strtotime($monthStart);
        $end = strtotime($eom);
        $ord = 0;
        $lastWorkday = $monthStart;
        while ($d <= $end) {
            $n = (int)date('N', $d);
            if ($n <= 6) {
                $ord++;
                $lastWorkday = date('Y-m-d', $d);
                if ($ord >= $ordinal) {
                    return $lastWorkday;
                }
            }
            $d = strtotime('+1 day', $d);
        }
        return $lastWorkday;
    }
}

