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

    public function __construct(PDO $pdo, array $revenueStatuses = ['delivered', 'wait_payment', 'paid', 'fin_done', 'ready_scm'])
    {
        $this->pdo = $pdo;
        $this->revenueStatuses = array_values(array_filter(array_map('strtolower', $revenueStatuses)));
        if (count($this->revenueStatuses) === 0) {
            $this->revenueStatuses = ['delivered', 'wait_payment', 'paid', 'fin_done', 'ready_scm'];
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
        // UNIT ACC bukan office baru. Pencapaian dipisahkan dari master_products.business_group=UNIT_ACC,
        // sedangkan office operasional tetap BGR/BKS/TGR/dll.
        $unitAcc = $this->unitAccAggregates($monthStart, $asOfDate);
        foreach (($unitAcc['by_office'] ?? []) as $oc => $amt) {
            $sales['by_office_segment'][$oc]['ACCUNIT'] = [
                'gross_sales' => (float)$amt,
                'net_sales' => (float)$amt,
                'invoice_count' => (int)($unitAcc['invoice_count'][$oc] ?? 0),
            ];
            $sales['ar_new_by_office_segment'][$oc]['ACCUNIT'] = (float)($unitAcc['ar_new'][$oc] ?? 0);
            $sales['ar_old_by_office_segment'][$oc]['ACCUNIT'] = (float)($unitAcc['ar_old'][$oc] ?? 0);
        }
        $sales['segment_sales_mtd']['ACCUNIT'] = (float)($unitAcc['total'] ?? 0);
        $sales['unit_acc'] = $unitAcc;
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
            'finance' => $finance,
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

    /**
     * Filter agar DO FULL retur yang sudah selesai tidak dihitung sebagai pencapaian/AR.
     * Alur aman: data DO tetap tersimpan, tetapi angka dashboard memakai sales bersih.
     */
    private function fullReturnExclusionSql(string $alias = 'd'): string
    {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'd';
        $sql = '';
        if ($this->columnExists('sales_do', 'return_status')) {
            $sql .= " AND LOWER(COALESCE({$alias}.return_status,'')) NOT IN ('return_scm_completed','return_completed','returned','full_return','closed')";
        }
        if ($this->tableExists('sales_do_returns')) {
            $sql .= " AND NOT EXISTS (
" .
                "                    SELECT 1 FROM sales_do_returns rfull
" .
                "                    WHERE rfull.do_id = {$alias}.id
" .
                "                      AND UPPER(COALESCE(rfull.return_type,'')) = 'FULL'
" .
                "                      AND LOWER(COALESCE(rfull.status,'')) IN ('return_scm_completed','return_completed','completed','closed')
" .
                "                  )";
        }
        return $sql;
    }

    /**
     * Filter customer internal agar pencapaian KPI hanya berisi penjualan eksternal.
     * Catatan: jangan hanya mengandalkan nama customer, tetapi kombinasi segment/code/nama.
     * - Segment KANTOR/INTERNAL tidak dihitung.
     * - Kode internal *-INT tidak dihitung.
     * - Nama "Kantor Rizqullah Mediska Indonesia..." tidak dihitung meski segment lama salah.
     */
    private function externalCustomerSql(string $doAlias = 'd'): string
    {
        $doAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $doAlias) ?: 'd';
        $normCmSegment = "REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(cm.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')";
        $normCSegment  = "REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')";
        $normCGroup    = "REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_group,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')";
        $normCType     = "REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(c.customer_type,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')";
        $custCode      = "UPPER(CONVERT(COALESCE({$doAlias}.customers_code,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)";
        $custName      = "UPPER(CONVERT(COALESCE(c.customers_name,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)";

        return " AND {$normCmSegment} NOT IN ('KANTOR','INTERNAL')
                 AND {$normCSegment} NOT IN ('KANTOR','INTERNAL')
                 AND {$normCGroup} NOT IN ('KANTOR','INTERNAL')
                 AND {$normCType} NOT IN ('KANTOR','INTERNAL')
                 AND {$custCode} NOT LIKE '%-INT'
                 AND {$custName} NOT LIKE 'KANTOR RIZQULLAH MEDISKA INDONESIA%'";
    }

    /**
     * Status pencapaian: status revenue normal tetap dihitung.
     * ready_scm hanya dihitung untuk office TGR, sesuai pembanding KPI Agustus 2026.
     */
    private function revenueReadyScmOfficeSql(string $doAlias = 'd'): string
    {
        $doAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $doAlias) ?: 'd';
        return " AND (LOWER(COALESCE({$doAlias}.status,'')) <> 'ready_scm'
                  OR UPPER(COALESCE({$doAlias}.office_code,'')) = 'TGR')";
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
            : "LEFT JOIN (SELECT NULL AS id, NULL AS customers_code, NULL AS customers_name, NULL AS customer_group, NULL AS segment, NULL AS customer_type) c ON 1=0";
        $returnExcludeSql = $this->fullReturnExclusionSql('d');
        $externalCustomerSql = $this->externalCustomerSql('d');
        $readyScmOfficeSql = $this->revenueReadyScmOfficeSql('d');
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
                  {$readyScmOfficeSql}
                  {$externalCustomerSql}
                  {$returnExcludeSql}
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
            'ar_new_by_office_segment' => [],
            'ar_old_by_office_segment' => [],
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
            $rows['ar_new_by_office_segment'][$office][$segment] = ($rows['ar_new_by_office_segment'][$office][$segment] ?? 0.0) + (float)$r['ar_new'];
            $rows['ar_old_by_office_segment'][$office][$segment] = ($rows['ar_old_by_office_segment'][$office][$segment] ?? 0.0) + (float)$r['ar_old'];
            $rows['invoice_count'][$office] = ($rows['invoice_count'][$office] ?? 0) + (int)$r['invoice_count'];
        }

        // AR harus membaca seluruh DO outstanding s.d. as-of, bukan hanya DO bulan berjalan.
        // Query MTD di atas sengaja tetap monthStart..asOf agar angka penjualan tidak berubah.
        $arSql = "SELECT
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
                    COALESCE(SUM(CASE WHEN d.do_date >= ? THEN GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0),0) ELSE 0 END),0) AS ar_new,
                    COALESCE(SUM(CASE WHEN d.do_date < ? THEN GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0),0) ELSE 0 END),0) AS ar_old
                  FROM sales_do d
                  {$custJoin}
                  {$segJoin}
                  WHERE d.do_date <= ?
                    AND LOWER(COALESCE(d.status,'')) IN ($in)
                    {$readyScmOfficeSql}
                    {$externalCustomerSql}
                    {$returnExcludeSql}
                    AND GREATEST(COALESCE(NULLIF(d.grand_total,0), d.total_amount, 0) - COALESCE(d.fin_paid_amount,0),0) > 0
                  GROUP BY UPPER(COALESCE(d.office_code,'SYS')), segment";
        $arParams = array_merge([$monthStart, $monthStart, $asOfDate], $this->revenueStatuses);
        $arSt = $this->pdo->prepare($arSql);
        $arSt->execute($arParams);
        $rows['ar_new_office'] = [];
        $rows['ar_old_office'] = [];
        $rows['ar_new_by_office_segment'] = [];
        $rows['ar_old_by_office_segment'] = [];
        while ($ar = $arSt->fetch(PDO::FETCH_ASSOC)) {
            $office = (string)$ar['office_code'];
            $segment = (string)$ar['segment'];
            $newVal = (float)$ar['ar_new'];
            $oldVal = (float)$ar['ar_old'];
            $rows['ar_new_office'][$office] = ($rows['ar_new_office'][$office] ?? 0.0) + $newVal;
            $rows['ar_old_office'][$office] = ($rows['ar_old_office'][$office] ?? 0.0) + $oldVal;
            $rows['ar_new_by_office_segment'][$office][$segment] = ($rows['ar_new_by_office_segment'][$office][$segment] ?? 0.0) + $newVal;
            $rows['ar_old_by_office_segment'][$office][$segment] = ($rows['ar_old_by_office_segment'][$office][$segment] ?? 0.0) + $oldVal;
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
            : "LEFT JOIN (SELECT NULL AS id, NULL AS customers_code, NULL AS customers_name, NULL AS customer_group, NULL AS segment, NULL AS customer_type) c ON 1=0";
        $returnExcludeSql = $this->fullReturnExclusionSql('d');
        $externalCustomerSql = $useRevenueOnly ? $this->externalCustomerSql('d') : '';
        $readyScmOfficeSql = $useRevenueOnly ? $this->revenueReadyScmOfficeSql('d') : '';
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
                      {$readyScmOfficeSql}
                      {$externalCustomerSql}
                      {$returnExcludeSql}
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
                      {$readyScmOfficeSql}
                      {$externalCustomerSql}
                      {$returnExcludeSql}
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
                      {$readyScmOfficeSql}
                      {$externalCustomerSql}
                      {$returnExcludeSql}
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
                      {$readyScmOfficeSql}
                      {$externalCustomerSql}
                      {$returnExcludeSql}
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

    /**
     * Pencapaian UNIT ACC memakai snapshot sales_do_items.business_group; fallback master untuk kompatibilitas. Category legacy UNIT_ACC fallback ke master category setelah reklasifikasi.
     * Office tetap sales_do.office_code. Jenis Unit ACC dibagi ALKES dan AKSESORIS.
     */
    private function unitAccAggregates(string $monthStart, string $asOfDate): array
    {
        $out = [
            'by_office'=>[], 'by_office_category'=>[], 'category_total'=>['ALKES'=>0.0,'AKSESORIS'=>0.0],
            'invoice_count'=>[], 'ar_new'=>[], 'ar_old'=>[], 'total'=>0.0
        ];
        if (!$this->tableExists('sales_do') || !$this->tableExists('sales_do_items') || !$this->tableExists('master_products') || !$this->columnExists('master_products','business_group') || !$this->columnExists('sales_do_items','business_group')) {
            return $out;
        }

        $statuses = $this->revenueStatuses;
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $returnAlkes = "0";
        $returnAks = "0";
        if ($this->tableExists('sales_do_returns') && $this->tableExists('sales_do_return_items')) {
            $baseReturn = "COALESCE((SELECT SUM(COALESCE(ri.qty_return,0) * (COALESCE(di2.subtotal,0) / NULLIF(di2.qty,0)))
                         FROM sales_do_returns rr
                         JOIN sales_do_return_items ri ON ri.return_id=rr.id AND ri.do_id=rr.do_id
                         JOIN sales_do_items di2 ON di2.id=ri.do_item_id AND di2.do_id=rr.do_id
                         JOIN master_products mp2 ON mp2.id=di2.product_id
                         WHERE rr.do_id=d.id
                           AND LOWER(TRIM(COALESCE(rr.status,'')))='return_scm_completed'
                           AND UPPER(TRIM(COALESCE(rr.commercial_effect,''))) IN ('REVERSAL','REPLACEMENT')
                           AND UPPER(TRIM(COALESCE(NULLIF(di2.business_group,''),mp2.business_group,'BMHP')))='UNIT_ACC'
                           AND UPPER(TRIM(CASE WHEN UPPER(TRIM(COALESCE(di2.category,''))) IN ('ALKES','AKSESORIS') THEN di2.category ELSE COALESCE(mp2.category,'') END))='{CAT}'
                           AND COALESCE(ri.qty_return,0)>0
                           AND (rr.scm_completed_at IS NULL OR rr.scm_completed_at <= CONCAT(?, ' 23:59:59'))),0)";
            $returnAlkes = str_replace('{CAT}','ALKES',$baseReturn);
            $returnAks = str_replace('{CAT}','AKSESORIS',$baseReturn);
        }

        $hasCustomers = $this->tableExists('master_customers');
        $custJoin = $hasCustomers
            ? "LEFT JOIN master_customers c ON (c.id=d.customer_id OR c.customers_code=d.customers_code)"
            : "LEFT JOIN (SELECT NULL id,NULL customers_code,NULL customers_name,NULL customer_group,NULL segment,NULL customer_type) c ON 1=0";
        $external = $this->externalCustomerSql('d');
        $returnExclude = $this->fullReturnExclusionSql('d');

        $sql = "SELECT UPPER(TRIM(COALESCE(d.office_code,'SYS'))) office_code,
                       d.id, d.do_date,
                       GREATEST(0,
                         COALESCE((SELECT SUM(COALESCE(i.subtotal,0)) FROM sales_do_items i
                                   JOIN master_products mp ON mp.id=i.product_id
                                   WHERE i.do_id=d.id
                                     AND UPPER(TRIM(COALESCE(NULLIF(i.business_group,''),mp.business_group,'BMHP')))='UNIT_ACC'
                                     AND UPPER(TRIM(CASE WHEN UPPER(TRIM(COALESCE(i.category,''))) IN ('ALKES','AKSESORIS') THEN i.category ELSE COALESCE(mp.category,'') END))='ALKES'),0)
                         - {$returnAlkes}
                       ) unit_acc_alkes,
                       GREATEST(0,
                         COALESCE((SELECT SUM(COALESCE(i.subtotal,0)) FROM sales_do_items i
                                   JOIN master_products mp ON mp.id=i.product_id
                                   WHERE i.do_id=d.id
                                     AND UPPER(TRIM(COALESCE(NULLIF(i.business_group,''),mp.business_group,'BMHP')))='UNIT_ACC'
                                     AND UPPER(TRIM(CASE WHEN UPPER(TRIM(COALESCE(i.category,''))) IN ('ALKES','AKSESORIS') THEN i.category ELSE COALESCE(mp.category,'') END))='AKSESORIS'),0)
                         - {$returnAks}
                       ) unit_acc_aksesoris,
                       GREATEST(COALESCE(NULLIF(d.grand_total,0),d.total_amount,0)-COALESCE(d.fin_paid_amount,0),0) header_outstanding
                FROM sales_do d
                {$custJoin}
                LEFT JOIN (SELECT NULL customer_code, NULL segment) cm ON 1=0
                WHERE d.do_date BETWEEN ? AND ?
                  AND LOWER(TRIM(COALESCE(d.status,''))) IN ({$in})
                  AND EXISTS (
                      SELECT 1 FROM sales_do_items ix
                      JOIN master_products mpx ON mpx.id=ix.product_id
                      WHERE ix.do_id=d.id
                        AND UPPER(TRIM(COALESCE(NULLIF(ix.business_group,''),mpx.business_group,'BMHP')))='UNIT_ACC'
                        AND UPPER(TRIM(CASE WHEN UPPER(TRIM(COALESCE(ix.category,''))) IN ('ALKES','AKSESORIS') THEN ix.category ELSE COALESCE(mpx.category,'') END)) IN ('ALKES','AKSESORIS')
                  )
                  {$external}
                  {$returnExclude}
                GROUP BY d.id,d.office_code,d.do_date,d.grand_total,d.total_amount,d.fin_paid_amount";
        $params=[];
        if ($returnAlkes !== '0') { $params[]=$asOfDate; $params[]=$asOfDate; }
        $params[]=$monthStart; $params[]=$asOfDate;
        $params=array_merge($params,$statuses);
        try {
            $st=$this->pdo->prepare($sql); $st->execute($params);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r){
                $oc=(string)($r['office_code']??'SYS');
                $alkes=(float)($r['unit_acc_alkes']??0);
                $aks=(float)($r['unit_acc_aksesoris']??0);
                $net=$alkes+$aks;
                if($net<=0) continue;
                $out['by_office'][$oc]=($out['by_office'][$oc]??0.0)+$net;
                if(!isset($out['by_office_category'][$oc])) $out['by_office_category'][$oc]=['ALKES'=>0.0,'AKSESORIS'=>0.0];
                $out['by_office_category'][$oc]['ALKES'] += $alkes;
                $out['by_office_category'][$oc]['AKSESORIS'] += $aks;
                $out['category_total']['ALKES'] += $alkes;
                $out['category_total']['AKSESORIS'] += $aks;
                $out['invoice_count'][$oc]=($out['invoice_count'][$oc]??0)+1;
                $out['total']+=$net;
                $gross=(float)($r['header_outstanding']??0);
                if($gross>0){
                    if((string)$r['do_date'] >= $monthStart) $out['ar_new'][$oc]=($out['ar_new'][$oc]??0.0)+min($gross,$net);
                    else $out['ar_old'][$oc]=($out['ar_old'][$oc]??0.0)+min($gross,$net);
                }
            }
        } catch (\Throwable $e) {
            // Fail-soft: flow existing tetap berjalan bila business_group belum dimigrasikan.
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function targetAggregates(int $monthNo, int $yearNo): array
    {
        $out = ['by_segment_office' => [], 'segment_total' => [], 'office_total' => []];
        if ($this->tableExists('kpi_targets')) {
            $officeExpr = "UPPER(COALESCE(o.office_code,'ALL'))";
            $joinOffice = "LEFT JOIN master_office o ON o.id = t.office_id";
            // Beberapa instalasi menyimpan office langsung di kpi_targets, bukan office_id.
            // Ambil fallback ini supaya Target MTD dan Target vs Pencapaian tidak menjadi 0.
            if ($this->columnExists('kpi_targets', 'office_code')) {
                $officeExpr = "UPPER(COALESCE(NULLIF(t.office_code,''), o.office_code, 'ALL'))";
            }
            $amountCol = $this->columnExists('kpi_targets', 'target_amount') ? 'target_amount'
                       : ($this->columnExists('kpi_targets', 'amount') ? 'amount'
                       : ($this->columnExists('kpi_targets', 'target') ? 'target' : 'target_amount'));
            $sql = "SELECT
                        CASE
                        WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(t.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')='NONHERMINA' THEN 'NON_HERMINA'
                        WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(t.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')='HERMINA' THEN 'HERMINA'
                        WHEN REPLACE(REPLACE(REPLACE(UPPER(CONVERT(COALESCE(t.segment,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci),'-',''),'_',''),' ','')='ACCUNIT' THEN 'ACCUNIT'
                        ELSE UPPER(COALESCE(t.segment,''))
                        END AS segment,
                        {$officeExpr} AS office_code,
                        SUM(t.`{$amountCol}`) AS target_amount
                    FROM kpi_targets t
                    {$joinOffice}
                    WHERE t.month_no = ? AND t.year_no = ?
                    GROUP BY segment, {$officeExpr}";
            $st = $this->pdo->prepare($sql);
            $st->execute([$monthNo, $yearNo]);

            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $segment = (string)$r['segment'];
                $office = (string)$r['office_code'];
                $amount = (float)$r['target_amount'];
                if ($office === '' || $office === 'ALL' || $amount == 0.0) {
                    continue;
                }
                $out['by_segment_office'][$segment][$office] = ($out['by_segment_office'][$segment][$office] ?? 0.0) + $amount;
                $out['segment_total'][$segment] = ($out['segment_total'][$segment] ?? 0.0) + $amount;
                $out['office_total'][$office] = ($out['office_total'][$office] ?? 0.0) + $amount;
            }
        }

        // Guardrail bila target periode belum terbaca dari kpi_targets.
        // Sesuai baseline target Agustus 2026 yang dipakai monitoring finance.
        $hasTarget = false;
        foreach ($out['office_total'] as $amount) {
            if ((float)$amount > 0) { $hasTarget = true; break; }
        }
        if (!$hasTarget && $monthNo === 8 && $yearNo === 2026) {
            // Legacy baseline hanya valid untuk periode Agustus 2026.
            // Periode lain tidak boleh memakai target bulan lain secara diam-diam.
            $out = $this->baselineTargets();
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function baselineTargets(): array
    {
        $rows = [
            'HERMINA' => [
                'BGR' => 1000000000.0,
                'BKS' => 250000000.0,
                'TGR' => 650000000.0,
                'SLO' => 100000000.0,
                'BDG' => 225000000.0,
                'SMG' => 150000000.0,
                'JGY' => 100000000.0,
                'KAL' => 100000000.0,
            ],
            'NON_HERMINA' => [
                'BGR' => 500000000.0,
                'BKS' => 150000000.0,
                'TGR' => 100000000.0,
                'SLO' => 50000000.0,
                'BDG' => 150000000.0,
                'SMG' => 50000000.0,
            ],
            'ACCUNIT' => [],
        ];
        $out = ['by_segment_office' => [], 'segment_total' => [], 'office_total' => []];
        foreach ($rows as $segment => $offices) {
            foreach ($offices as $office => $amount) {
                $out['by_segment_office'][$segment][$office] = (float)$amount;
                $out['segment_total'][$segment] = ($out['segment_total'][$segment] ?? 0.0) + (float)$amount;
                $out['office_total'][$office] = ($out['office_total'][$office] ?? 0.0) + (float)$amount;
            }
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
        $out = [];

        // 1) AP ERP normal. Jangan hanya percaya status header: saldo real adalah
        //    total invoice dikurangi payment yang sudah terposting s.d. as-of.
        if ($this->tableExists('purchases_invoice_ap')) {
            $hasPayments = $this->tableExists('purchases_payment_ap');
            $officeExpr = $this->normalizedOfficeSql("COALESCE(ap.office_code,'SYS')");
            $paidJoin = $hasPayments
                ? "LEFT JOIN (
                     SELECT ap_id, SUM(amount) AS paid_amount
                     FROM purchases_payment_ap
                     WHERE deleted_at IS NULL AND pay_date <= ?
                     GROUP BY ap_id
                   ) paid ON paid.ap_id = ap.id"
                : "LEFT JOIN (SELECT NULL AS ap_id, 0 AS paid_amount) paid ON 1=0";
            $statusFilter = $this->columnExists('purchases_invoice_ap', 'status')
                ? " AND UPPER(COALESCE(ap.status,'UNPAID')) NOT IN ('PAID','CANCELLED','CANCELED','VOID')"
                : '';
            $sql = "SELECT {$officeExpr} AS office_code,
                           COALESCE(SUM(GREATEST(COALESCE(ap.total_amount,0)-COALESCE(paid.paid_amount,0),0)),0) AS outstanding
                    FROM purchases_invoice_ap ap
                    {$paidJoin}
                    WHERE ap.deleted_at IS NULL
                      AND ap.invoice_date <= ?
                      {$statusFilter}
                    GROUP BY office_code
                    HAVING outstanding > 0";
            $st = $this->pdo->prepare($sql);
            $params = $hasPayments ? [$asOfDate, $asOfDate] : [$asOfDate];
            $st->execute($params);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $oc = (string)$r['office_code'];
                $out[$oc] = ($out[$oc] ?? 0.0) + (float)$r['outstanding'];
            }
        }

        // 2) Hutang lama/import. Modul FIN menyimpannya terpisah agar tidak
        //    merusak alur PR -> PO -> GR -> AP Invoice -> AP Payment. Dashboard
        //    detail harus menggabungkannya agar sama dengan Finance Dashboard.
        if ($this->tableExists('fin_ap_opening')) {
            $hasOpeningPayments = $this->tableExists('fin_ap_opening_payments');
            $officeExpr = $this->normalizedOfficeSql("COALESCE(o.office_code,'SYS')");
            $extraJoin = $hasOpeningPayments
                ? "LEFT JOIN (
                     SELECT opening_ap_id, SUM(amount) AS paid_extra
                     FROM fin_ap_opening_payments
                     WHERE deleted_at IS NULL AND pay_date <= ?
                     GROUP BY opening_ap_id
                   ) px ON px.opening_ap_id=o.id"
                : "LEFT JOIN (SELECT NULL AS opening_ap_id, 0 AS paid_extra) px ON 1=0";
            $sql = "SELECT {$officeExpr} AS office_code,
                           COALESCE(SUM(GREATEST(COALESCE(o.original_amount,0)-COALESCE(o.paid_amount,0)-COALESCE(px.paid_extra,0),0)),0) AS outstanding
                    FROM fin_ap_opening o
                    {$extraJoin}
                    WHERE o.document_date <= ?
                      AND UPPER(COALESCE(o.status,'UNPAID')) NOT IN ('PAID','CANCELLED','CANCELED','VOID')
                    GROUP BY office_code
                    HAVING outstanding > 0";
            $st = $this->pdo->prepare($sql);
            $params = $hasOpeningPayments ? [$asOfDate, $asOfDate] : [$asOfDate];
            $st->execute($params);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $oc = (string)$r['office_code'];
                $out[$oc] = ($out[$oc] ?? 0.0) + (float)$r['outstanding'];
            }
        }

        return $out;
    }

    /** @return array<string,array<string,float>> */
    private function expenseByOffice(string $monthStart, string $asOfDate): array
    {
        if (!$this->tableExists('kpi_gl_category_map') || !$this->tableExists('gl_journal_headers') || !$this->tableExists('gl_journal_lines')) {
            return [];
        }
        $officeExpr = $this->normalizedOfficeSql("CASE
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
                      END");
        $categoryNorm = "REPLACE(REPLACE(REPLACE(UPPER(COALESCE(m.category,'')),'-',''),'_',''),' ','')";
        $categoryExpr = "CASE
                           WHEN {$categoryNorm} IN ('OPERASIONAL','OPERATIONAL','OPEX','BIAYAOPERASIONAL') THEN 'OPERASIONAL'
                           WHEN {$categoryNorm} IN ('BEBANGAJI','BEBANGAJIPENDAPATAN','GAJI','PAYROLL','SALARY') THEN 'BEBAN_GAJI'
                           WHEN {$categoryNorm} IN ('SUPPORT','BIAYASUPPORT') THEN 'SUPPORT'
                           WHEN {$categoryNorm} IN ('FEEMGMT','FEEMANAGEMENT','FEEMANAGEMENTHERMINA','MANAGEMENTFEE') THEN 'FEE_MGMT'
                           WHEN {$categoryNorm} IN ('PPH','PPH25','PPHFINAL','PPH25PPHFINAL') THEN 'PPH'
                           ELSE UPPER(COALESCE(m.category,''))
                         END";
        $sql = "SELECT
                    {$officeExpr} AS office_code,
                    {$categoryExpr} AS category,
                    COALESCE(SUM(l.dr_amount - l.cr_amount),0) AS amount
                FROM gl_journal_headers h
                JOIN gl_journal_lines l ON l.header_id = h.id
                JOIN kpi_gl_category_map m ON m.gl_account_id = l.account_id
                WHERE h.journal_date BETWEEN ? AND ?
                  AND h.status IN ('POSTED','DRAFT')
                GROUP BY office_code, category";
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
            $out = [];
            foreach ($rows as $r) {
                $val = (float)($r['stock_value'] ?? 0);
                if ($val > 0) {
                    $out[(string)$r['office_code']] = $val;
                }
            }
            if ($out) {
                return $out;
            }
        }

        // Fallback operasional: nilai stok per office dari wqs_stock_by_office.
        // Harga diambil dari master_pricelist terlebih dahulu, lalu fallback ke master_products.
        if ($this->tableExists('wqs_stock_by_office') && $this->tableExists('master_products')) {
            $mpPriceCols = [];
            foreach (['price','min_price','max_price','selling_price','harga_jual','harga','hpp','purchase_price','last_purchase_price','cost'] as $c) {
                if ($this->columnExists('master_products', $c)) { $mpPriceCols[] = "NULLIF(mp.`{$c}`,0)"; }
            }

            $joins = '';
            $priceParts = [];
            if ($this->tableExists('master_pricelist')) {
                $plPriceCols = [];
                foreach (['buy_price','hpp','cost','purchase_price','last_purchase_price','sell_price','selling_price','price','harga_jual','harga','min_price','price_default','amount'] as $c) {
                    if ($this->columnExists('master_pricelist', $c)) { $plPriceCols[] = "NULLIF(p.`{$c}`,0)"; }
                }
                if ($plPriceCols) {
                    $plExpr = 'COALESCE(' . implode(', ', $plPriceCols) . ', 0)';
                    $plActive = $this->columnExists('master_pricelist', 'deleted_at') ? " AND p.deleted_at IS NULL" : '';
                    if ($this->columnExists('master_pricelist', 'status')) {
                        $plActive .= " AND COALESCE(p.status,1)=1";
                    }

                    // Schema baru master_pricelist menggunakan SKU, sedangkan beberapa
                    // instalasi lama menggunakan product_id. Dukung keduanya.
                    if ($this->columnExists('master_pricelist', 'product_id')) {
                        if ($this->columnExists('master_pricelist', 'office_code')) {
                            $joins .= " LEFT JOIN (SELECT product_id, UPPER(COALESCE(office_code,'ALL')) AS office_code, MAX({$plExpr}) AS price_value FROM master_pricelist p WHERE 1=1 {$plActive} GROUP BY product_id, UPPER(COALESCE(office_code,'ALL'))) pl_off ON pl_off.product_id = mp.id AND pl_off.office_code = UPPER(COALESCE(ws.office_code,'SYS'))";
                            $joins .= " LEFT JOIN (SELECT product_id, MAX({$plExpr}) AS price_value FROM master_pricelist p WHERE (office_code IS NULL OR office_code='' OR UPPER(office_code)='ALL') {$plActive} GROUP BY product_id) pl_all ON pl_all.product_id = mp.id";
                            $priceParts[] = 'NULLIF(pl_off.price_value,0)';
                            $priceParts[] = 'NULLIF(pl_all.price_value,0)';
                        } else {
                            $joins .= " LEFT JOIN (SELECT product_id, MAX({$plExpr}) AS price_value FROM master_pricelist p WHERE 1=1 {$plActive} GROUP BY product_id) pl_prod ON pl_prod.product_id = mp.id";
                            $priceParts[] = 'NULLIF(pl_prod.price_value,0)';
                        }
                    } elseif ($this->columnExists('master_pricelist', 'sku')) {
                        $mpSku = null;
                        foreach (['sku','product_sku','product_code','item_code','kode','code','products_code'] as $c) {
                            if ($this->columnExists('master_products', $c)) { $mpSku = $c; break; }
                        }
                        if ($mpSku !== null) {
                            if ($this->columnExists('master_pricelist', 'office_code')) {
                                $joins .= " LEFT JOIN (SELECT UPPER(TRIM(sku)) AS sku_key, UPPER(COALESCE(office_code,'ALL')) AS office_code, MAX({$plExpr}) AS price_value FROM master_pricelist p WHERE 1=1 {$plActive} GROUP BY UPPER(TRIM(sku)), UPPER(COALESCE(office_code,'ALL'))) pl_sku_off ON pl_sku_off.sku_key=UPPER(TRIM(mp.`{$mpSku}`)) AND pl_sku_off.office_code=UPPER(COALESCE(ws.office_code,'SYS'))";
                                $joins .= " LEFT JOIN (SELECT UPPER(TRIM(sku)) AS sku_key, MAX({$plExpr}) AS price_value FROM master_pricelist p WHERE (office_code IS NULL OR office_code='' OR UPPER(office_code)='ALL') {$plActive} GROUP BY UPPER(TRIM(sku))) pl_sku_all ON pl_sku_all.sku_key=UPPER(TRIM(mp.`{$mpSku}`))";
                                $priceParts[] = 'NULLIF(pl_sku_off.price_value,0)';
                                $priceParts[] = 'NULLIF(pl_sku_all.price_value,0)';
                            } else {
                                $joins .= " LEFT JOIN (SELECT UPPER(TRIM(sku)) AS sku_key, MAX({$plExpr}) AS price_value FROM master_pricelist p WHERE 1=1 {$plActive} GROUP BY UPPER(TRIM(sku))) pl_sku ON pl_sku.sku_key=UPPER(TRIM(mp.`{$mpSku}`))";
                                $priceParts[] = 'NULLIF(pl_sku.price_value,0)';
                            }
                        }
                    }
                }
            }
            $priceParts = array_merge($priceParts, $mpPriceCols);
            if (!$priceParts) { return []; }
            $priceExpr = 'COALESCE(' . implode(', ', $priceParts) . ', 0)';

            $stockOfficeExpr = $this->normalizedOfficeSql("COALESCE(ws.office_code,'SYS')");
            $sql = "SELECT {$stockOfficeExpr} AS office_code,
                           COALESCE(SUM(ws.stock_qty * {$priceExpr}),0) AS stock_value
                    FROM wqs_stock_by_office ws
                    JOIN master_products mp ON mp.id = ws.product_id
                    {$joins}
                    WHERE ws.stock_qty > 0
                    GROUP BY office_code";
            $st = $this->pdo->query($sql);
            $out = [];
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $val = (float)($r['stock_value'] ?? 0);
                if ($val > 0) {
                    $out[(string)$r['office_code']] = $val;
                }
            }
            return $out;
        }

        // Legacy fallback jika masih ada tabel wqs_stock global.
        if (!$this->tableExists('wqs_stock') || !$this->tableExists('master_products')) {
            return [];
        }
        $sql = "SELECT 'ALL' AS office_code, COALESCE(SUM(ws.stock_qty * COALESCE(NULLIF(mp.price,0),0)),0) AS stock_value
                FROM wqs_stock ws
                JOIN master_products mp ON mp.id = ws.product_id
                WHERE ws.stock_qty > 0";
        $val = (float)$this->pdo->query($sql)->fetchColumn();
        return $val > 0 ? ['ALL' => $val] : [];
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
        $accunit = $this->rowsTargetAchievementByOffice('ACCUNIT', ['BGR','BKS','TGR','SLO','BDG','SMG','JGY','KAL'], $asOfDate, $officeMeta, $targets, $sales);

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
        $accunitOffices = ['BGR','BKS','TGR','SLO','BDG','SMG','JGY','KAL'];
        $tgrOffices = ['TGR'];
        $rates = $finance['corporate_rates'];

        // MAIN dan TANGERANG mengecualikan ACCUNIT agar penjualan/AR tidak terhitung dua kali.
        // ACCUNIT adalah blok segment tersendiri; AP, stok, dan GL tetap melekat ke office dan tidak diduplikasi ke blok segment.
        $mainRows = $this->financeRowsByOffices($mainOffices, 'MAIN', $officeMeta, $sales, $finance, $adjustments, null, 'ACCUNIT');
        $accRows = $this->financeRowsByOffices($accunitOffices, 'ACCUNIT', $officeMeta, $sales, $finance, $adjustments, 'ACCUNIT');
        $tgrRows = $this->financeRowsByOffices($tgrOffices, 'TANGERANG', $officeMeta, $sales, $finance, $adjustments, null, 'ACCUNIT');

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
    private function financeRowsByOffices(array $offices, string $rateSegment, array $officeMeta, array $sales, array $finance, array $adjustments, ?string $forceSalesSegment = null, ?string $excludeSalesSegment = null): array
    {
        $rows = [];
        $rate = (float)($finance['corporate_rates'][$rateSegment] ?? 0.0);

        foreach ($offices as $idx => $oc) {
            $officeSales = (float)($sales['office_sales_mtd'][$oc] ?? 0);
            $piuBaru = (float)($sales['ar_new_office'][$oc] ?? 0);
            $piuLama = (float)($sales['ar_old_office'][$oc] ?? 0);
            if ($forceSalesSegment !== null) {
                $officeSales = (float)($sales['by_office_segment'][$oc][$forceSalesSegment]['net_sales'] ?? 0);
                $piuBaru = (float)($sales['ar_new_by_office_segment'][$oc][$forceSalesSegment] ?? 0);
                $piuLama = (float)($sales['ar_old_by_office_segment'][$oc][$forceSalesSegment] ?? 0);
            } elseif ($excludeSalesSegment !== null) {
                $officeSales -= (float)($sales['by_office_segment'][$oc][$excludeSalesSegment]['net_sales'] ?? 0);
                $piuBaru -= (float)($sales['ar_new_by_office_segment'][$oc][$excludeSalesSegment] ?? 0);
                $piuLama -= (float)($sales['ar_old_by_office_segment'][$oc][$excludeSalesSegment] ?? 0);
                $officeSales = max(0.0, $officeSales);
                $piuBaru = max(0.0, $piuBaru);
                $piuLama = max(0.0, $piuLama);
            }

            // GL/AP/stock adalah atribut office, bukan atribut customer segment. Jangan diduplikasi ke blok ACCUNIT.
            $isSegmentOnly = $forceSalesSegment !== null;
            $opex = $isSegmentOnly ? 0.0 : (float)($finance['expense_by_office'][$oc]['OPERASIONAL'] ?? 0);
            $beban = $isSegmentOnly ? 0.0 : (float)($finance['expense_by_office'][$oc]['BEBAN_GAJI'] ?? 0);
            $support = $isSegmentOnly ? 0.0 : (float)($finance['expense_by_office'][$oc]['SUPPORT'] ?? 0);
            $fee = $isSegmentOnly ? 0.0 : (float)($finance['expense_by_office'][$oc]['FEE_MGMT'] ?? 0);
            $pph = $isSegmentOnly ? 0.0 : (float)($finance['expense_by_office'][$oc]['PPH'] ?? 0);
            $corporate = $officeSales * $rate;
            $costTotal = $opex + $beban + $support + $fee + $pph + $corporate;
            $pct = $this->pct($costTotal, $officeSales);
            $hutang = $isSegmentOnly ? 0.0 : (float)($finance['ap_outstanding'][$oc] ?? 0);
            $totalPiu = $piuBaru + $piuLama;
            $stock = $isSegmentOnly ? 0.0 : (float)($finance['stock_by_office'][$oc] ?? 0);
            $profit = $officeSales - $costTotal;
            $workingCap = $stock + $totalPiu - $hutang;
            $jumlah = $profit + $workingCap;
            $adj = $isSegmentOnly ? 0.0 : $this->adjustmentAmountForOffice($adjustments, (int)($officeMeta[$oc]['id'] ?? 0));
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

    /** Normalize common office labels/codes without changing canonical office codes. */
    private function normalizedOfficeSql(string $expr): string
    {
        $n = "REPLACE(REPLACE(REPLACE(UPPER(COALESCE({$expr},'')),'-',''),'_',''),' ','')";
        return "CASE
                  WHEN {$n} IN ('BGR','BOGOR') THEN 'BGR'
                  WHEN {$n} IN ('BKS','BEKASI') THEN 'BKS'
                  WHEN {$n} IN ('SLO','SOLO','SURAKARTA') THEN 'SLO'
                  WHEN {$n} IN ('BDG','BANDUNG') THEN 'BDG'
                  WHEN {$n} IN ('SMG','SEMARANG') THEN 'SMG'
                  WHEN {$n} IN ('JGY','JOGJA','YOGYA','YOGYAKARTA','DEPOYOGYA') THEN 'JGY'
                  WHEN {$n} IN ('KAL','SAMARINDA','DEPOSAMARINDA') THEN 'KAL'
                  WHEN {$n} IN ('TGR','TANGERANG','RMITANGERANG') THEN 'TGR'
                  WHEN {$n} IN ('','ALL','SYS') THEN 'ALL'
                  ELSE UPPER(COALESCE({$expr},'ALL'))
                END";
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

        // VS 3 Bulan: hanya bulan yang benar-benar memiliki data yang masuk rata-rata.
        $sum3 = 0.0;
        $count3 = 0;
        for ($i = 1; $i <= 3; $i++) {
            $s = date('Y-m-01', strtotime($monthStart . " -{$i} month"));
            $eom = date('Y-m-t', strtotime($s));
            $e = date('Y-m-d', strtotime($s . ' +' . max(0, $currentDay - 1) . ' day'));
            if ($e > $eom) $e = $eom;
            $hist = $this->salesRangeResult($s, $e, $offices);
            if ($hist['available']) {
                $sum3 += (float)$hist['value'];
                $count3++;
            }
        }
        $avg3 = $count3 > 0 ? ($sum3 / $count3) : 0.0;

        // VS Tahun: same month previous year with same day-cap.
        $prevYearStart = sprintf('%04d-%02d-01', $yearNo - 1, $monthNo);
        $prevYearEom = date('Y-m-t', strtotime($prevYearStart));
        $prevYearEnd = date('Y-m-d', strtotime($prevYearStart . ' +' . max(0, $currentDay - 1) . ' day'));
        if ($prevYearEnd > $prevYearEom) $prevYearEnd = $prevYearEom;
        $vsYear = $this->salesRangeResult($prevYearStart, $prevYearEnd, $offices);

        // VS Pencapaian Tertinggi: highest monthly total in last 24 months.
        $peak = $this->highestMonthlyAchievement($offices, $monthStart);

        // VS Hari Kerja yang Sama: compare to previous month at same workday ordinal.
        $workdayNo = $this->workdayOrdinal($monthStart, $asOfDate);
        $prevMonthStart = date('Y-m-01', strtotime($monthStart . ' -1 month'));
        $prevMonthEndByWorkday = $this->dateAtWorkdayOrdinal($prevMonthStart, $workdayNo);
        $vsWorkday = $this->salesRangeResult($prevMonthStart, $prevMonthEndByWorkday, $offices);

        return [
            'current_total' => $currentTotal,
            'vs_3_bulan' => $this->comparisonResult($currentTotal, $avg3, 'Rata-rata ' . $count3 . ' bulan sebelumnya yang tersedia (MTD day-cap)', $count3 > 0),
            'vs_tahun' => $this->comparisonResult($currentTotal, (float)$vsYear['value'], 'Bulan yang sama tahun lalu (MTD day-cap)', (bool)$vsYear['available']),
            'vs_tertinggi' => $this->comparisonResult($currentTotal, (float)$peak['value'], 'Pencapaian tertinggi bulanan (24 bulan) ' . (string)$peak['period'], (bool)$peak['available']),
            'vs_hari_kerja_sama' => $this->comparisonResult($currentTotal, (float)$vsWorkday['value'], 'Bulan lalu sampai hari kerja ke-' . $workdayNo, (bool)$vsWorkday['available']),
        ];
    }

    /** @return array<string,mixed> */
    private function comparisonResult(float $current, float $base, string $note, bool $available = true): array
    {
        if (!$available) {
            return [
                'base' => null,
                'delta' => null,
                'delta_pct' => null,
                'available' => false,
                'note' => $note . ' — data pembanding belum tersedia',
            ];
        }
        $delta = $current - $base;
        $pct = $base == 0.0 ? null : (($delta / $base) * 100.0);
        return [
            'base' => $base,
            'delta' => $delta,
            'delta_pct' => $pct,
            'available' => true,
            'note' => $note,
        ];
    }

    /** @return array{value:float,available:bool} */
    private function salesRangeResult(string $startDate, string $endDate, array $offices): array
    {
        if (!$this->tableExists('sales_do')) return ['value' => 0.0, 'available' => false];

        $inStatus = implode(',', array_fill(0, count($this->revenueStatuses), '?'));
        $inOffice = implode(',', array_fill(0, count($offices), '?'));
        $hasSegMap = $this->tableExists('kpi_customer_segment_map');
        $hasCustomers = $this->tableExists('master_customers');
        $collate = 'COLLATE utf8mb4_unicode_ci';
        $segJoin = $hasSegMap ? "LEFT JOIN kpi_customer_segment_map cm ON CONVERT(cm.customer_code USING utf8mb4) {$collate} = CONVERT(d.customers_code USING utf8mb4) {$collate}" : "LEFT JOIN (SELECT NULL AS customer_code, NULL AS segment) cm ON 1=0";
        $custJoin = $hasCustomers
            ? "LEFT JOIN master_customers c ON (c.id = d.customer_id OR CONVERT(c.customers_code USING utf8mb4) {$collate} = CONVERT(d.customers_code USING utf8mb4) {$collate})"
            : "LEFT JOIN (SELECT NULL AS id, NULL AS customers_code, NULL AS customers_name, NULL AS customer_group, NULL AS segment, NULL AS customer_type) c ON 1=0";
        $returnExcludeSql = $this->fullReturnExclusionSql('d');
        $externalCustomerSql = $this->externalCustomerSql('d');
        $readyScmOfficeSql = $this->revenueReadyScmOfficeSql('d');
        $sql = "SELECT COUNT(*) AS row_count,
                       COALESCE(SUM(COALESCE(NULLIF(d.total_amount,0),d.grand_total,0)),0) AS total_value
                FROM sales_do d
                {$custJoin}
                {$segJoin}
                WHERE d.do_date BETWEEN ? AND ?
                  AND LOWER(COALESCE(d.status,'')) IN ({$inStatus})
                  {$readyScmOfficeSql}
                  {$externalCustomerSql}
                  AND UPPER(COALESCE(d.office_code,'')) IN ({$inOffice})
                  {$returnExcludeSql}";
        $params = array_merge([$startDate, $endDate], $this->revenueStatuses, $offices);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['value' => (float)($r['total_value'] ?? 0), 'available' => ((int)($r['row_count'] ?? 0)) > 0];
    }

    private function salesTotalForRange(string $startDate, string $endDate, array $offices): float
    {
        return (float)$this->salesRangeResult($startDate, $endDate, $offices)['value'];
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
            : "LEFT JOIN (SELECT NULL AS id, NULL AS customers_code, NULL AS customers_name, NULL AS customer_group, NULL AS segment, NULL AS customer_type) c ON 1=0";
        $returnExcludeSql = $this->fullReturnExclusionSql('d');
        $externalCustomerSql = $this->externalCustomerSql('d');
        $readyScmOfficeSql = $this->revenueReadyScmOfficeSql('d');

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
                  {$readyScmOfficeSql}
                  {$externalCustomerSql}
                  {$returnExcludeSql}
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

    /** @return array{value:float,period:string,available:bool} */
    private function highestMonthlyAchievement(array $offices, string $currentMonthStart): array
    {
        $startWindow = date('Y-m-01', strtotime($currentMonthStart . ' -23 month'));
        $inStatus = implode(',', array_fill(0, count($this->revenueStatuses), '?'));
        $inOffice = implode(',', array_fill(0, count($offices), '?'));
        $hasSegMap = $this->tableExists('kpi_customer_segment_map');
        $hasCustomers = $this->tableExists('master_customers');
        $collate = 'COLLATE utf8mb4_unicode_ci';
        $segJoin = $hasSegMap ? "LEFT JOIN kpi_customer_segment_map cm ON CONVERT(cm.customer_code USING utf8mb4) {$collate} = CONVERT(d.customers_code USING utf8mb4) {$collate}" : "LEFT JOIN (SELECT NULL AS customer_code, NULL AS segment) cm ON 1=0";
        $custJoin = $hasCustomers
            ? "LEFT JOIN master_customers c ON (c.id = d.customer_id OR CONVERT(c.customers_code USING utf8mb4) {$collate} = CONVERT(d.customers_code USING utf8mb4) {$collate})"
            : "LEFT JOIN (SELECT NULL AS id, NULL AS customers_code, NULL AS customers_name, NULL AS customer_group, NULL AS segment, NULL AS customer_type) c ON 1=0";
        $returnExcludeSql = $this->fullReturnExclusionSql('d');
        $externalCustomerSql = $this->externalCustomerSql('d');
        $readyScmOfficeSql = $this->revenueReadyScmOfficeSql('d');
        $sql = "SELECT DATE_FORMAT(d.do_date, '%Y-%m') AS ym,
                       COALESCE(SUM(COALESCE(NULLIF(d.total_amount,0),d.grand_total,0)),0) AS val
                FROM sales_do d
                {$custJoin}
                {$segJoin}
                WHERE d.do_date >= ?
                  AND LOWER(COALESCE(d.status,'')) IN ({$inStatus})
                  {$readyScmOfficeSql}
                  {$externalCustomerSql}
                  AND UPPER(COALESCE(d.office_code,'')) IN ({$inOffice})
                  {$returnExcludeSql}
                GROUP BY DATE_FORMAT(d.do_date, '%Y-%m')
                ORDER BY val DESC
                LIMIT 1";
        $params = array_merge([$startWindow], $this->revenueStatuses, $offices);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['value' => 0.0, 'period' => '-', 'available' => false];
        return ['value' => (float)$row['val'], 'period' => (string)$row['ym'], 'available' => true];
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

