<?php
require_once __DIR__ . '/../../_shared/assets.php';
/**
 * dashboards/owner/exec_summary.php
 * Executive Summary — DAILY AS-OF + PROCESS LIST BUILD v8
 *
 * Dibangun kembali sebagai halaman dashboard penuh (bukan wrapper / CLI tool).
 * Fokus: filter harian (as-of date), fail-soft, dan membaca data ERP sampai tanggal cutoff yang dipilih. Tidak ada hard-code angka manual.
 */

require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../master/_audit_master.php';
require_once __DIR__ . '/../../app/Dashboard/DashboardDetailService.php';

$ERP_ROOT     = $GLOBALS['ERP_ROOT'] ?? realpath(__DIR__ . '/../..');
$BASE_PROJECT = $GLOBALS['BASE_PROJECT'] ?? '';
$pdo          = $GLOBALS['pdo'] ?? null;
if (!$pdo && function_exists('kpi_require_pdo')) {
    $pdo = kpi_require_pdo();
}

if (function_exists('require_any_permission')) {
    require_any_permission(['DASHBOARD.OWNER_SUMMARY']);
} elseif (function_exists('require_login')) {
    require_login();
}

/* ============================================================
 * Helpers
 * ============================================================ */
function es_up($v): string { return strtoupper(trim((string)($v ?? ''))); }
function es_money($v): string {
    if ($v === null) return '—';
    return 'Rp ' . kpi_policy_money((float)$v);
}
function es_num($v): string {
    if ($v === null) return '—';
    return number_format((float)$v, 0, ',', '.');
}
function es_pct($v, int $dec=1): string {
    if ($v === null) return '—';
    return number_format((float)$v, $dec, ',', '.') . '%';
}
function es_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function es_col_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $k = strtolower($table.'.'.$column);
    if (array_key_exists($k, $cache)) return $cache[$k];
    try {
        $st = $pdo->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $st->execute([$table, $column]);
        return $cache[$k] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$k] = false;
    }
}
function es_first_col(PDO $pdo, string $table, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (es_col_exists($pdo, $table, $c)) return $c;
    }
    return null;
}
function es_item_group_norm_sql(PDO $pdo, string $alias): string {
    $raw="'BMHP'";
    $canMaster=es_table_exists($pdo,'master_products')
        && es_col_exists($pdo,'sales_do_items','product_id')
        && es_col_exists($pdo,'master_products','id')
        && es_col_exists($pdo,'master_products','business_group');
    if(es_col_exists($pdo,'sales_do_items','business_group') && $canMaster){
        $raw="COALESCE(NULLIF({$alias}.business_group,''),(SELECT mp_bg.business_group FROM master_products mp_bg WHERE mp_bg.id={$alias}.product_id LIMIT 1),'BMHP')";
    } elseif(es_col_exists($pdo,'sales_do_items','business_group')) {
        $raw="COALESCE(NULLIF({$alias}.business_group,''),'BMHP')";
    } elseif($canMaster) {
        $raw="COALESCE((SELECT mp_bg.business_group FROM master_products mp_bg WHERE mp_bg.id={$alias}.product_id LIMIT 1),'BMHP')";
    }
    return "REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE({$raw},'BMHP'))),' ',''),'-',''),'_','')";
}
function es_item_category_norm_sql(PDO $pdo, string $alias): string {
    $raw="''";
    $canMaster=es_table_exists($pdo,'master_products')
        && es_col_exists($pdo,'sales_do_items','product_id')
        && es_col_exists($pdo,'master_products','id')
        && es_col_exists($pdo,'master_products','category');
    if(es_col_exists($pdo,'sales_do_items','category') && $canMaster){
        $raw="COALESCE(NULLIF({$alias}.category,''),(SELECT mp_cat.category FROM master_products mp_cat WHERE mp_cat.id={$alias}.product_id LIMIT 1),'')";
    } elseif(es_col_exists($pdo,'sales_do_items','category')) {
        $raw="COALESCE(NULLIF({$alias}.category,''),'')";
    } elseif($canMaster) {
        $raw="COALESCE((SELECT mp_cat.category FROM master_products mp_cat WHERE mp_cat.id={$alias}.product_id LIMIT 1),'')";
    }
    return "REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE({$raw},''))),' ',''),'-',''),'_','')";
}
function es_in(array $vals): string {
    return '(' . implode(',', array_fill(0, max(1, count($vals)), '?')) . ')';
}
function es_status_list($raw, array $fallback): array {
    $raw = trim((string)$raw);
    if ($raw === '') return $fallback;
    $out = [];
    foreach (explode(',', $raw) as $s) {
        $s = strtolower(trim($s));
        if ($s !== '') $out[] = $s;
    }
    return $out ? array_values(array_unique($out)) : $fallback;
}


/**
 * Mengambil adjustment RETUR FINAL yang terhubung eksplisit ke DO asal.
 *
 * Prinsip fail-safe:
 * - tidak pernah menebak retur dari nominal/customer/SKU/tanggal yang mirip;
 * - hanya tabel retur/return yang punya referensi DO + status final yang dipakai;
 * - historical As-Of memakai timestamp penyelesaian/update <= cutoff;
 * - nilai yang dipakai adalah NET/subtotal retur (tanpa PPN) bila tersedia;
 * - bila struktur DB belum cukup jelas, fungsi mengembalikan adjustment kosong
 *   sehingga alur sales yang sudah baik tidak berubah.
 */
function es_final_return_adjustments(PDO $pdo, string $cutoffTs): array {
    /*
     * FINAL SOURCE OF TRUTH:
     *   sales_do_returns -> sales_do_return_items -> sales_do_items
     *
     * Tidak ada schema-discovery lagi. Modul retur sendiri membuat dan menulis
     * tiga tabel ini, jadi Executive Summary harus membaca ledger yang sama.
     */
    $out = [
        'by_do_id'=>[],
        'by_do_code'=>[],
        'replacement_do_ids'=>[],
        'replacement_do_codes'=>[],
        'final_return_ids'=>[],
        'return_parent_do_ids'=>[],
        'return_parent_do_codes'=>[],
        'rows'=>[],
        'source_table'=>'sales_do_returns',
        'source_item_table'=>'sales_do_return_items',
        'source_amount'=>'qty_return × exact original sales_do_items NET unit',
        'source_date'=>'scm_completed_at',
        'source_status'=>'status',
        'diagnostic'=>[
            'mode'=>'DIRECT_SALES_DO_RETURN_LEDGER',
            'amount_rule'=>'qty_return × (sales_do_items.subtotal / sales_do_items.qty)',
            'ignored'=>[],
        ],
    ];

    try {
        foreach (['sales_do_returns','sales_do_return_items','sales_do_items','sales_do'] as $t) {
            if (!es_table_exists($pdo,$t)) {
                $out['diagnostic']['error']="missing_table: {$t}";
                return $out;
            }
        }

        // Schema-safe commercial effect. Legacy NULL/blank is NEEDS_REVIEW and MUST NOT reduce KPI.
        if (!es_col_exists($pdo,'sales_do_returns','commercial_effect')) {
            try { $pdo->exec("ALTER TABLE `sales_do_returns` ADD COLUMN `commercial_effect` VARCHAR(30) NULL"); }
            catch(Throwable $e) {
                $out['diagnostic']['error']='commercial_effect_migration_failed: '.$e->getMessage();
                return $out;
            }
        }

        $returnGroupExpr=es_item_group_norm_sql($pdo,'di');
        $sql = "SELECT
                    r.id return_id,
                    r.do_id original_do_id,
                    UPPER(TRIM(COALESCE(r.do_code,d.do_code,''))) original_do_code,
                    LOWER(TRIM(COALESCE(r.status,''))) return_status,
                    r.scm_completed_at return_effective_at,
                    r.replacement_do_id,
                    r.replacement_created_at,
                    r.commercial_effect,
                    UPPER(TRIM(COALESCE(d.office_code,''))) original_office_code,
                    COALESCE(SUM(
                        COALESCE(ri.qty_return,0)
                        * (COALESCE(di.subtotal,0) / NULLIF(di.qty,0))
                    ),0) return_net
                FROM sales_do_returns r
                JOIN sales_do d
                  ON d.id=r.do_id
                JOIN sales_do_return_items ri
                  ON ri.return_id=r.id
                 AND ri.do_id=r.do_id
                JOIN sales_do_items di
                  ON di.id=ri.do_item_id
                 AND di.do_id=r.do_id
                WHERE LOWER(TRIM(COALESCE(r.status,'')))='return_scm_completed'
                  AND r.scm_completed_at IS NOT NULL
                  AND r.scm_completed_at<=?
                  AND COALESCE(ri.qty_return,0)>0
                  
                  AND UPPER(TRIM(COALESCE(r.commercial_effect,''))) IN ('REVERSAL','REPLACEMENT')
                GROUP BY r.id,r.do_id,r.do_code,d.do_code,r.status,r.scm_completed_at,
                         r.replacement_do_id,r.replacement_created_at,r.commercial_effect,d.office_code
                ORDER BY r.scm_completed_at,r.id";

        $st=$pdo->prepare($sql);
        $st->execute([$cutoffTs]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rr) {
            $rid=(int)($rr['return_id']??0);
            $did=(int)($rr['original_do_id']??0);
            $dcode=strtoupper(trim((string)($rr['original_do_code']??'')));
            $amount=round((float)($rr['return_net']??0),2);
            if($rid<=0 || $did<=0 || $amount<=0) continue;

            $out['final_return_ids'][$rid]=true;
            $out['return_parent_do_ids'][$rid]=$did;
            if($dcode!=='') $out['return_parent_do_codes'][$rid]=$dcode;

            $out['by_do_id'][$did]=($out['by_do_id'][$did]??0.0)+$amount;
            if($dcode!=='') $out['by_do_code'][$dcode]=($out['by_do_code'][$dcode]??0.0)+$amount;

            $replacementId=(int)($rr['replacement_do_id']??0);
            if($replacementId>0 && $replacementId!==$did){
                $q=$pdo->prepare("SELECT id,UPPER(TRIM(COALESCE(do_code,''))) do_code
                                  FROM sales_do
                                  WHERE id=? LIMIT 1");
                $q->execute([$replacementId]);
                $rep=$q->fetch(PDO::FETCH_ASSOC) ?: [];
                if((int)($rep['id']??0)===$replacementId){
                    $out['replacement_do_ids'][$replacementId]=true;
                    $repCode=(string)($rep['do_code']??'');
                    if($repCode!=='') $out['replacement_do_codes'][$repCode]=true;
                }
            }

            $out['rows'][]=$rr;
        }
        // Audit only: FINAL returns with blank/unknown commercial effect.
        // These NEVER reduce achievement until classified explicitly.
        try {
            $uq=$pdo->prepare("SELECT
                    r.id return_id,
                    r.do_id original_do_id,
                    UPPER(TRIM(COALESCE(r.do_code,d.do_code,''))) original_do_code,
                    r.scm_completed_at,
                    d.do_date,
                    UPPER(TRIM(COALESCE(r.commercial_effect,''))) commercial_effect,
                    COALESCE(SUM(
                        COALESCE(ri.qty_return,0)
                        * (COALESCE(di.subtotal,0) / NULLIF(di.qty,0))
                    ),0) return_net
                FROM sales_do_returns r
                JOIN sales_do d ON d.id=r.do_id
                JOIN sales_do_return_items ri ON ri.return_id=r.id AND ri.do_id=r.do_id
                JOIN sales_do_items di ON di.id=ri.do_item_id AND di.do_id=r.do_id
                WHERE LOWER(TRIM(COALESCE(r.status,'')))='return_scm_completed'
                  AND r.scm_completed_at IS NOT NULL
                  AND r.scm_completed_at<=?
                  AND COALESCE(ri.qty_return,0)>0
                  
                  AND UPPER(TRIM(COALESCE(d.do_code,''))) LIKE 'BMHP-%'
                  AND UPPER(TRIM(COALESCE(r.commercial_effect,''))) NOT IN ('REVERSAL','OPERATIONAL_ONLY','REPLACEMENT')
                GROUP BY r.id,r.do_id,r.do_code,d.do_code,r.scm_completed_at,d.do_date,r.commercial_effect
                ORDER BY r.scm_completed_at,r.id");
            $uq->execute([$cutoffTs]);
            $out['unclassified_rows']=$uq->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch(Throwable $e) {}
    } catch(Throwable $e) {
        $out['diagnostic']['error']=$e->getMessage();
        error_log('[exec_summary][return-direct] '.$e->getMessage());
    }
    return $out;
}

function es_latest_period(PDO $pdo, string $table, string $periodCol, string $wanted, array $statuses=[]): ?string {
    if (!es_table_exists($pdo,$table) || !es_col_exists($pdo,$table,$periodCol)) return null;
    try {
        $sql = "SELECT MAX(`{$periodCol}`) FROM `{$table}` WHERE `{$periodCol}` <= ?";
        $params = [$wanted];
        if ($statuses && es_col_exists($pdo,$table,'status')) {
            $ph = es_in($statuses);
            $sql .= " AND UPPER(status) IN {$ph}";
            $params = array_merge($params, array_map('strtoupper',$statuses));
        }
        $st=$pdo->prepare($sql);
        $st->execute($params);
        $v=$st->fetchColumn();
        return ($v && preg_match('/^\d{4}-\d{2}$/',(string)$v)) ? (string)$v : null;
    } catch(Throwable $e) {
        return null;
    }
}


function es_discover_direct_cash_balance(PDO $pdo, string $asOf, string $office=''): array {
    // Hanya memakai tabel yang benar-benar mempunyai kolom saldo/balance langsung.
    // Tidak menebak saldo dari debit/kredit GL.
    $balanceNames = ['current_balance','ending_balance','book_balance','available_balance','closing_balance','balance','saldo','saldo_akhir'];
    $dateNames = ['balance_date','as_of_date','statement_date','trx_date','transaction_date','updated_at','created_at'];
    try {
        $ph = '(' . implode(',', array_fill(0,count($balanceNames),'?')) . ')';
        $st = $pdo->prepare("
          SELECT TABLE_NAME, COLUMN_NAME
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE()
            AND LOWER(COLUMN_NAME) IN {$ph}
            AND (
                 LOWER(TABLE_NAME) LIKE '%bank%'
              OR LOWER(TABLE_NAME) LIKE '%cash%'
              OR LOWER(TABLE_NAME) LIKE '%rekening%'
              OR LOWER(TABLE_NAME) LIKE '%balance%'
            )
          ORDER BY
            CASE WHEN LOWER(TABLE_NAME) LIKE '%balance%' THEN 0 ELSE 1 END,
            TABLE_NAME
        ");
        $st->execute($balanceNames);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach($rows as $row){
            $t=(string)$row['TABLE_NAME'];
            $bc=(string)$row['COLUMN_NAME'];

            // Hindari tabel rekonsiliasi/match yang bukan saldo.
            $tl=strtolower($t);
            if(str_contains($tl,'match') || str_contains($tl,'recon_match')) continue;

            $dc=null;
            foreach($dateNames as $c){
                if(es_col_exists($pdo,$t,$c)){ $dc=$c; break; }
            }

            $sql="SELECT COALESCE(SUM(`{$bc}`),0) FROM `{$t}` WHERE 1=1";
            $params=[];
            if(es_col_exists($pdo,$t,'deleted_at')) $sql.=" AND deleted_at IS NULL";
            if(es_col_exists($pdo,$t,'is_active')) $sql.=" AND is_active=1";
            if(es_col_exists($pdo,$t,'status')) {
                $sql.=" AND UPPER(COALESCE(status,'ACTIVE')) IN ('ACTIVE','AKTIF','OPEN','POSTED','CLOSED','1')";
            }
            if($office!=='' && es_col_exists($pdo,$t,'office_code')){
                $sql.=" AND UPPER(office_code)=?";
                $params[]=$office;
            }
            if($dc!==null){
                // Ambil snapshot/tanggal terakhir <= as-of, bukan menjumlah semua sejarah.
                $sub="SELECT MAX(DATE(`{$dc}`)) FROM `{$t}` WHERE DATE(`{$dc}`)<=?";
                $sql.=" AND DATE(`{$dc}`)=({$sub})";
                $params[]=$asOf;
            }
            $q=$pdo->prepare($sql);
            $q->execute($params);
            $v=$q->fetchColumn();
            if(is_numeric($v) && (float)$v!=0.0){
                return ['value'=>(float)$v,'source'=>$t.'.'.$bc];
            }
        }
    } catch(Throwable $e){
        error_log('[exec_summary][cash-discovery] '.$e->getMessage());
    }
    return ['value'=>null,'source'=>null];
}


function es_bank_statement_cash(PDO $pdo, string $asOf, string $office=''): array {
    if (!es_table_exists($pdo,'bank_statements') || !es_col_exists($pdo,'bank_statements','closing_balance')) {
        return ['value'=>null,'source'=>null,'as_of'=>null];
    }
    try {
        $dateCol = es_first_col($pdo,'bank_statements',[
            'statement_date','balance_date','period_end','period_date',
            'closing_date','as_of_date','updated_at','created_at'
        ]);
        $hasAccount = es_col_exists($pdo,'bank_statements','bank_account_id');
        $hasId = es_col_exists($pdo,'bank_statements','id');

        // Jika rekening tersedia, ambil SATU statement terakhir per rekening.
        if ($hasAccount) {
            if ($dateCol !== null) {
                $sql = "SELECT COALESCE(SUM(bs.closing_balance),0) total_balance,
                               MAX(DATE(bs.`{$dateCol}`)) as_of_date
                        FROM bank_statements bs
                        JOIN (
                          SELECT bank_account_id, MAX(`{$dateCol}`) mx
                          FROM bank_statements
                          WHERE DATE(`{$dateCol}`) <= ?";
                $params = [$asOf];
                if (es_col_exists($pdo,'bank_statements','deleted_at')) {
                    $sql .= " AND deleted_at IS NULL";
                }
                $sql .= " GROUP BY bank_account_id
                        ) x ON x.bank_account_id=bs.bank_account_id
                           AND bs.`{$dateCol}`=x.mx";
                if (es_col_exists($pdo,'bank_statements','deleted_at')) {
                    $sql .= " WHERE bs.deleted_at IS NULL";
                } else {
                    $sql .= " WHERE 1=1";
                }
                if ($office !== '' && es_table_exists($pdo,'bank_accounts') && es_col_exists($pdo,'bank_accounts','id')) {
                    $sql .= " AND EXISTS (
                                SELECT 1 FROM bank_accounts ba
                                WHERE ba.id=bs.bank_account_id
                                  AND UPPER(ba.office_code)=?
                              )";
                    $params[] = $office;
                }
                $st=$pdo->prepare($sql); $st->execute($params);
                $r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
                $v=$r['total_balance'] ?? null;
                if (is_numeric($v)) {
                    return ['value'=>(float)$v,'source'=>'bank_statements.closing_balance','as_of'=>$r['as_of_date']??null];
                }
            } elseif ($hasId) {
                $sql = "SELECT COALESCE(SUM(bs.closing_balance),0) total_balance
                        FROM bank_statements bs
                        JOIN (
                          SELECT bank_account_id, MAX(id) mx
                          FROM bank_statements";
                if (es_col_exists($pdo,'bank_statements','deleted_at')) {
                    $sql .= " WHERE deleted_at IS NULL";
                }
                $sql .= " GROUP BY bank_account_id
                        ) x ON x.bank_account_id=bs.bank_account_id AND bs.id=x.mx
                        WHERE 1=1";
                $params=[];
                if ($office !== '' && es_table_exists($pdo,'bank_accounts')) {
                    $sql .= " AND EXISTS (
                                SELECT 1 FROM bank_accounts ba
                                WHERE ba.id=bs.bank_account_id
                                  AND UPPER(ba.office_code)=?
                              )";
                    $params[]=$office;
                }
                $st=$pdo->prepare($sql); $st->execute($params);
                $v=$st->fetchColumn();
                if (is_numeric($v)) {
                    return ['value'=>(float)$v,'source'=>'bank_statements.closing_balance (latest id/account)','as_of'=>null];
                }
            }
        }

        // Tanpa bank_account_id: gunakan snapshot terbaru tunggal.
        if ($dateCol !== null) {
            $sql = "SELECT closing_balance, DATE(`{$dateCol}`) as_of_date
                    FROM bank_statements
                    WHERE DATE(`{$dateCol}`)<=?";
            $params=[$asOf];
            if (es_col_exists($pdo,'bank_statements','deleted_at')) $sql.=" AND deleted_at IS NULL";
            $sql.=" ORDER BY `{$dateCol}` DESC";
            if ($hasId) $sql.=", id DESC";
            $sql.=" LIMIT 1";
            $st=$pdo->prepare($sql); $st->execute($params);
            $r=$st->fetch(PDO::FETCH_ASSOC);
            if ($r && is_numeric($r['closing_balance']??null)) {
                return ['value'=>(float)$r['closing_balance'],'source'=>'bank_statements.closing_balance','as_of'=>$r['as_of_date']??null];
            }
        }
    } catch(Throwable $e) {
        error_log('[exec_summary][bank-statements] '.$e->getMessage());
    }
    return ['value'=>null,'source'=>null,'as_of'=>null];
}

function es_gl_opex(PDO $pdo, string $from, string $to, string $office=''): array {
    foreach (['gl_journal_headers','gl_journal_lines','kpi_gl_category_map'] as $t) {
        if (!es_table_exists($pdo,$t)) return ['value'=>null,'source'=>null,'categories'=>[]];
    }
    $need = [
        ['gl_journal_headers','id'],['gl_journal_headers','journal_date'],
        ['gl_journal_lines','header_id'],['gl_journal_lines','account_id'],
        ['gl_journal_lines','dr_amount'],['gl_journal_lines','cr_amount'],
        ['kpi_gl_category_map','gl_account_id'],['kpi_gl_category_map','category'],
    ];
    foreach ($need as [$t,$c]) {
        if (!es_col_exists($pdo,$t,$c)) return ['value'=>null,'source'=>null,'categories'=>[]];
    }

    try {
        $categories=['OPERASIONAL','BEBAN_GAJI','SUPPORT','FEE_MGMT','PPH'];
        $ph=es_in($categories);
        $sql="SELECT
                UPPER(m.category) category,
                COALESCE(SUM(COALESCE(l.dr_amount,0)-COALESCE(l.cr_amount,0)),0) amount
              FROM gl_journal_headers h
              JOIN gl_journal_lines l ON l.header_id=h.id
              JOIN kpi_gl_category_map m ON m.gl_account_id=l.account_id
              WHERE h.journal_date BETWEEN ? AND ?
                AND UPPER(m.category) IN {$ph}";
        $params=array_merge([$from,$to],$categories);

        // Bila status POSTED memang ada, gunakan hanya jurnal POSTED.
        if (es_col_exists($pdo,'gl_journal_headers','status')) {
            $qs=$pdo->query("SELECT DISTINCT UPPER(status) s FROM gl_journal_headers WHERE status IS NOT NULL LIMIT 30");
            $statuses=$qs?$qs->fetchAll(PDO::FETCH_COLUMN):[];
            if (in_array('POSTED',$statuses,true)) $sql.=" AND UPPER(h.status)='POSTED'";
        }

        if ($office !== '') {
            if (es_col_exists($pdo,'gl_journal_headers','office_code')) {
                $sql.=" AND UPPER(h.office_code)=?";
                $params[]=$office;
            } elseif (es_col_exists($pdo,'gl_journal_lines','office_code')) {
                $sql.=" AND UPPER(l.office_code)=?";
                $params[]=$office;
            }
        }

        $sql.=" GROUP BY UPPER(m.category)";
        $st=$pdo->prepare($sql); $st->execute($params);

        $sum=0.0; $found=false; $byCat=[];
        while($r=$st->fetch(PDO::FETCH_ASSOC)){
            $cat=(string)($r['category']??'');
            $amt=(float)($r['amount']??0);
            $byCat[$cat]=$amt;
            $sum += $amt;
            $found=true;
        }
        return [
            'value'=>$found?$sum:null,
            'source'=>'GL posted journals / kpi_gl_category_map',
            'categories'=>$byCat
        ];
    } catch(Throwable $e) {
        error_log('[exec_summary][gl-opex] '.$e->getMessage());
        return ['value'=>null,'source'=>null,'categories'=>[]];
    }
}

/* ============================================================
 * Filter harian / AS-OF DATE
 * ============================================================ */
$today = date('Y-m-d');
$defaultAsOf = $today; // v20: default LIVE hari ini; historical hanya bila user memilih as_of

$asOf = trim((string)($_GET['as_of'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $legacyPeriod = trim((string)($_GET['m'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}$/', $legacyPeriod)) {
        [, $legacyEnd] = kpi_policy_month_range($legacyPeriod);
        $asOf = ($legacyPeriod === date('Y-m')) ? $defaultAsOf : $legacyEnd;
    } else {
        $asOf = $defaultAsOf;
    }
}
if (strtotime($asOf) === false) $asOf = $defaultAsOf;
if ($asOf > $today) $asOf = $today;

$period = substr($asOf,0,7);
$office = es_up($_GET['office'] ?? '');
$refresh = max(0, min(3600, (int)($_GET['refresh'] ?? 0)));

[$month_start, $month_end] = kpi_policy_month_range($period);
$mtd_end = $asOf;
if ($mtd_end < $month_start) $mtd_end = $month_start;
if ($mtd_end > $month_end) $mtd_end = $month_end;

$ytd_start = substr($period,0,4).'-01-01';

$prevPeriod = date('Y-m', strtotime($month_start.' -1 month'));
[$prev_start, $prev_month_end] = kpi_policy_month_range($prevPeriod);
$cutDay = (int)substr($mtd_end,8,2);
$prevLastDay = (int)substr($prev_month_end,8,2);
$prevCompareDay = min($cutDay,$prevLastDay);
$prev_end = $prevPeriod.'-'.str_pad((string)$prevCompareDay,2,'0',STR_PAD_LEFT);

$isHistoricalAsOf = ($mtd_end < $today);
$cutoffLabel = date('d-m-Y', strtotime($mtd_end));

/* ============================================================
 * Config
 * ============================================================ */
$revStatuses = es_status_list(
    kpi_policy_get($pdo, 'KPI_EXEC', 'REVENUE_STATUSES', null, 'delivered,wait_payment,paid,fin_done,ready_scm'),
    ['delivered','wait_payment','paid','fin_done','ready_scm']
);
if (!in_array('ready_scm', $revStatuses, true)) $revStatuses[] = 'ready_scm';

$arStatuses = es_status_list(
    kpi_policy_get($pdo, 'KPI_EXEC', 'AR_UNPAID_STATUSES', null, 'wait_payment'),
    ['wait_payment']
);

$alertStockDays = (int)kpi_policy_get($pdo,'KPI_ALERT','STOCK_COVER_RED_DAYS',null,'30');
$alertArDays    = (int)kpi_policy_get($pdo,'KPI_ALERT','AR_OVERDUE_RED_DAYS',null,'90');
$alertExpDays   = (int)kpi_policy_get($pdo,'KPI_ALERT','LICENSE_WARN_DAYS',null,'90');
$alertPoDays    = (int)kpi_policy_get($pdo,'KPI_ALERT','PO_READY_LATE_DAYS',null,'14');

$daysDue = 30;
try {
    $termCode = strtoupper((string)kpi_policy_get($pdo,'default','default_payment_all',null,'TOP30'));
    if (es_table_exists($pdo,'master_payment_terms')) {
        $st = $pdo->prepare("SELECT days_due FROM master_payment_terms WHERE payment_terms_code=? LIMIT 1");
        $st->execute([$termCode]);
        $d = $st->fetchColumn();
        if (is_numeric($d)) $daysDue = max(0,(int)$d);
    }
} catch (Throwable $e) {}

/* ============================================================
 * Offices
 * ============================================================ */
$canonicalOffices = [
    'BGR'=>'Rizqullah Mediska Indonesia Bogor',
    'BKS'=>'Rizqullah Mediska Indonesia Bekasi',
    'TGR'=>'Tangerang',
    'BDG'=>'Rizqullah Mediska Indonesia Bandung',
    'SLO'=>'Rizqullah Mediska Indonesia Jawa Tengah (Solo)',
    'SMG'=>'Rizqullah Mediska Indonesia Semarang',
    'JGY'=>'Depo Yogyakarta',
    'KAL'=>'Depo Samarinda',
];
$offices = [];
try {
    if (es_table_exists($pdo,'master_office')) {
        $offices = $pdo->query("SELECT office_code, office_name FROM master_office WHERE is_active=1 ORDER BY office_code")
                       ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {}
$seen = [];
foreach ($offices as $r) $seen[es_up($r['office_code'] ?? '')] = true;
foreach ($canonicalOffices as $c=>$n) {
    if (empty($seen[$c])) $offices[] = ['office_code'=>$c,'office_name'=>$n];
}
usort($offices, fn($a,$b)=>strcmp(es_up($a['office_code']??''), es_up($b['office_code']??'')));

/* ============================================================
 * Revenue
 * ============================================================ */
$rev_mtd = ['cnt'=>0,'net'=>0.0];
$rev_prev = ['cnt'=>0,'net'=>0.0];
$rev_ytd = ['cnt'=>0,'net'=>0.0];

if (es_table_exists($pdo,'sales_do')) {
    $amountCol = es_first_col($pdo,'sales_do',['total_amount','grand_total','nilai_do']);
    $statusCol = es_first_col($pdo,'sales_do',['status']);
    $dateCol   = es_first_col($pdo,'sales_do',['do_date']);
    if ($amountCol && $statusCol && $dateCol) {
        $ph = es_in($revStatuses);
        $runRevenue = function($from,$to) use ($pdo,$amountCol,$statusCol,$dateCol,$ph,$revStatuses,$office) {
            try {
                $sql = "SELECT COUNT(*) cnt, COALESCE(SUM(`{$amountCol}`),0) net
                        FROM sales_do
                        WHERE `{$dateCol}` BETWEEN ? AND ?
                          AND LOWER(`{$statusCol}`) IN {$ph}";
                $params = array_merge([$from,$to],$revStatuses);
                if ($office !== '' && es_col_exists($pdo,'sales_do','office_code')) {
                    $sql .= " AND UPPER(office_code)=?";
                    $params[] = $office;
                }
                $st=$pdo->prepare($sql); $st->execute($params);
                $r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
                return ['cnt'=>(int)($r['cnt']??0),'net'=>(float)($r['net']??0)];
            } catch (Throwable $e) {
                return ['cnt'=>0,'net'=>0.0];
            }
        };
        $rev_mtd  = $runRevenue($month_start,$mtd_end);
        $rev_prev = $runRevenue($prev_start,$prev_end);
        $rev_ytd  = $runRevenue($ytd_start,$mtd_end);
    }
}
$mom = ($rev_prev['net'] ?? 0) > 0 ? (($rev_mtd['net']-$rev_prev['net'])/$rev_prev['net'])*100 : null;

/* ============================================================
 * Finance Dashboard Detail alignment
 * ============================================================ */
$detailData = null;
$svc = null;
try {
    $svc = new \App\Dashboard\DashboardDetailService($pdo, $revStatuses);
    $detailData = $svc->build(substr($period,5,2),(int)substr($period,0,4),$mtd_end);
} catch (Throwable $e) {
    $detailData = null;
}

$target_value = 0.0;
$officePerfRows = [];
$opex_mtd = null;
$opex_source = null;
$cash_balance = null;
$cash_source = null;
$cash_as_of = null;

$cashRaw = kpi_policy_get($pdo,'KPI_FIN','CASH_BALANCE_TOTAL',$office!==''?$office:null,null);
if (is_numeric($cashRaw) && trim((string)$cashRaw)!=='') {
    $cash_balance = (float)$cashRaw;
    $cash_source = 'KPI_FIN / CASH_BALANCE_TOTAL';
}

$opexRaw = kpi_policy_get($pdo,'KPI_FIN',$period.'|OPEX',$office!==''?$office:null,null);
if (is_numeric($opexRaw) && trim((string)$opexRaw)!=='') { $opex_mtd=(float)$opexRaw; $opex_source='KPI_FIN / '.$period.'|OPEX'; }

if (is_array($detailData)) {
    if ($office !== '') {
        if (isset($detailData['sales']['office_sales_mtd'][$office])) {
            $rev_mtd['net'] = (float)$detailData['sales']['office_sales_mtd'][$office];
        }
        if (isset($detailData['sales']['invoice_count'][$office])) {
            $rev_mtd['cnt'] = (int)$detailData['sales']['invoice_count'][$office];
        }
        $target_value = (float)($detailData['targets']['office_total'][$office] ?? 0);
    } else {
        foreach (($detailData['section1']['all_cabang'] ?? []) as $row) {
            if (!empty($row['is_total'])) {
                $rev_mtd['net'] = (float)($row['pencapaian'] ?? $rev_mtd['net']);
                $target_value = (float)($row['target'] ?? 0);
                break;
            }
        }
        // DO count juga harus mengikuti Finance Dashboard Detail agar tidak beda
        // dengan nilai revenue yang sudah di-align.
        $alignedCount = 0;
        foreach (['BGR','BKS','SLO','BDG','SMG','JGY','KAL','TGR'] as $oc) {
            $alignedCount += (int)($detailData['sales']['invoice_count'][$oc] ?? 0);
        }
        if ($alignedCount > 0) $rev_mtd['cnt'] = $alignedCount;
    }

    if ($cash_balance === null && $office === '') {
        $sum=0.0; $found=false;
        foreach (['main'] as $blk) {
            $v = $detailData['section2'][$blk]['saldo_value'] ?? null;
            if (is_numeric($v)) { $sum += (float)$v; $found=true; }
        }
        if ($found && $sum != 0.0) {
            $cash_balance = $sum;
            $cash_source = 'Finance Dashboard Detail / saldo rekening';
        }
    }

    // Bila Finance Detail tidak punya saldo, cari snapshot saldo langsung
    // di tabel ERP yang memang mempunyai kolom balance/saldo.
    if ($cash_balance === null) {
        $cashAuto = es_discover_direct_cash_balance($pdo,$mtd_end,$office);
        if ($cashAuto['value'] !== null) {
            $cash_balance=(float)$cashAuto['value'];
            $cash_source='auto: '.$cashAuto['source'];
        }
    }

    $expense = (array)($detailData['section2'] ?? []);
    $opexCalc=0.0; $opexFound=false;
    if ($office === '') {
        foreach (['main','tangerang'] as $blk) {
            $tot=(array)($expense[$blk]['total']??[]);
            foreach (['operasional','beban_gaji','support','fee_management','pph'] as $k) {
                if (array_key_exists($k,$tot)) { $opexCalc+=(float)$tot[$k]; $opexFound=true; }
            }
        }
    } else {
        foreach (['main','tangerang'] as $blk) {
            foreach ((array)($expense[$blk]['rows']??[]) as $row) {
                if (es_up($row['office_code']??'') !== $office) continue;
                foreach (['operasional','beban_gaji','support','fee_management','pph'] as $k) {
                    if (array_key_exists($k,$row)) { $opexCalc+=(float)$row[$k]; $opexFound=true; }
                }
            }
        }
    }
    if ($opexFound && ($opexCalc != 0.0 || $opex_mtd === null)) { $opex_mtd=$opexCalc; $opex_source='Finance Dashboard Detail'; }

    $order=['BGR','BKS','SLO','BDG','SMG','JGY','KAL','TGR'];
    foreach ($order as $oc) {
        $tgt=(float)($detailData['targets']['office_total'][$oc]??0);
        $ach=(float)($detailData['sales']['office_sales_mtd'][$oc]??0);
        if ($tgt<=0 && $ach<=0) continue;
        $officePerfRows[]=[
            'office_code'=>$oc,
            'office_name'=>$canonicalOffices[$oc]??$oc,
            'target'=>$tgt,
            'pencapaian'=>$ach,
            'persentase'=>$tgt>0?($ach/$tgt)*100:null,
        ];
    }
}

if ($cash_balance === null) {
    $bsCash = es_bank_statement_cash($pdo,$mtd_end,$office);
    if ($bsCash['value'] !== null) {
        $cash_balance=(float)$bsCash['value'];
        $cash_source=$bsCash['source'];
        $cash_as_of=$bsCash['as_of'];
    } else {
        $cashAuto = es_discover_direct_cash_balance($pdo,$mtd_end,$office);
        if ($cashAuto['value'] !== null) {
            $cash_balance=(float)$cashAuto['value'];
            $cash_source='auto: '.$cashAuto['source'];
        }
    }
}

// OPEX aktual dari GL mengambil prioritas bila tersedia.
// DashboardDetail boleh tetap menjadi fallback bila mapping GL belum mempunyai transaksi.
$glOpex = es_gl_opex($pdo,$month_start,$mtd_end,$office);
if ($glOpex['value'] !== null) {
    $opex_mtd=(float)$glOpex['value'];
    $opex_source=$glOpex['source'];
}

/* target fallback */
$target_source_period=$period;
$target_is_fallback=false;
if ($target_value<=0 && $svc) {
    try {
        $probe=date('Y-m',strtotime($month_start.' -1 month'));
        for($i=0;$i<12;$i++) {
            [, $pe]=kpi_policy_month_range($probe);
            $pd=$svc->build(substr($probe,5,2),(int)substr($probe,0,4),$pe);
            if($office!=='') {
                $tv=(float)($pd['targets']['office_total'][$office]??0);
            } else {
                $tv=0.0;
                foreach(($pd['section1']['all_cabang']??[]) as $r) if(!empty($r['is_total'])){$tv=(float)($r['target']??0);break;}
            }
            if($tv>0){$target_value=$tv;$target_source_period=$probe;$target_is_fallback=true;break;}
            $probe=date('Y-m',strtotime($probe.'-01 -1 month'));
        }
    } catch(Throwable $e){}
}


// Konsistensi target per-office: bila target total memakai proxy periode sebelumnya,
// target per-office juga harus memakai periode proxy yang SAMA, sementara pencapaian
// tetap pencapaian MTD periode yang sedang ditampilkan.
if ($target_is_fallback && $svc) {
    try {
        [, $proxyEnd] = kpi_policy_month_range($target_source_period);
        $proxyData = $svc->build(substr($target_source_period,5,2),(int)substr($target_source_period,0,4),$proxyEnd);
        $rebuilt=[];
        foreach(['BGR','BKS','SLO','BDG','SMG','JGY','KAL','TGR'] as $oc){
            $ach=(float)($detailData['sales']['office_sales_mtd'][$oc] ?? 0);
            $tgt=(float)($proxyData['targets']['office_total'][$oc] ?? 0);
            if($ach<=0 && $tgt<=0) continue;
            $rebuilt[]=[
                'office_code'=>$oc,
                'office_name'=>$canonicalOffices[$oc]??$oc,
                'target'=>$tgt,
                'pencapaian'=>$ach,
                'persentase'=>$tgt>0?($ach/$tgt)*100:null,
                'target_proxy_period'=>$target_source_period,
            ];
        }
        if($rebuilt) $officePerfRows=$rebuilt;
    } catch(Throwable $e){
        error_log('[exec_summary][target-proxy-office] '.$e->getMessage());
    }
}


/* ============================================================
 * Sales Achievement — FINAL DYNAMIC RMI EXTERNAL SALES v22 RETURN/REPLACEMENT LINEAGE SAFE — STRUCTURE DISCOVERY
 *
 * TUJUAN:
 * Menyamakan "Pencapaian Penjualan" dengan transaksi bisnis aktual RMI,
 * bukan hanya status finance-recognized.
 *
 * KUNCI LOGIKA:
 * 1) Sumber pencapaian = sales_do HEADER, satu DO dihitung SATU kali.
 * 2) Hanya keluarga DO komersial RMI "BMHP-*" yang dihitung.
 *    ALKES-* dan keluarga lain tidak ikut pencapaian penjualan ini.
 * 3) Semua DO BMHP yang SUDAH AKTIF pada tanggal As-Of dihitung,
 *    termasuk crm_to_wqs / wqs_processing / ready_scm / on_delivery /
 *    delivered / wait_payment / paid / fin_done.
 * 4) DRAFT / CANCELLED / CANCELED / REJECTED / VOID / DELETED tidak dihitung.
 * 5) Historical As-Of:
 *    - MTD tetap milik periode bisnis sales_do.do_date;
 *    - created_at <= 23:59:59 tanggal As-Of hanya menjadi visibility cutoff;
 *    - DO bulan lama yang baru dibuat bulan ini TIDAK boleh pindah menjadi sales bulan ini;
 *    - DO backdate yang aktif/valid tetap dihitung satu kali saat sudah terlihat pada As-Of;
 *    - backdate TIDAK boleh otomatis dianggap replacement/duplikat hanya karena item sama;
 *    - hanya status cancel/reject/void/deleted atau internal/intercompany yang dikeluarkan;
 *    - status direkonstruksi dari sales_do_audit <= cutoff As-Of bila tersedia.
 * 6) Office utama diambil dari kode DO (BMHP-BGR-..., BMHP-TGR-..., dst.)
 *    agar tidak terpengaruh perubahan office_code sesudah transaksi.
 *    Jika pola kode tidak valid, fallback ke sales_do.office_code.
 * 7) Nilai achievement memakai NET SALES dari sales_do_items.subtotal per DO.
 *    Header total_amount/grand_total tetap dibaca hanya sebagai gross diagnostic.
 *    Tidak ada koreksi angka manual/hard-code tanggal tertentu.
 * 8) INTERNAL TRANSFER tidak dihitung sebagai penjualan eksternal.
 * 9) Semua customer eksternal aktif dihitung. Kode/type customer hanya dipakai
 *    untuk klasifikasi/diagnostik, bukan sebagai syarat agar sales dihitung.
 *    Hanya internal/intercompany yang dikeluarkan eksplisit.
 * 10) Unit ACC dihitung terpisah dan STRICT dari family DO UNITACC-*; office tetap office operasional dan ditambahkan ke consolidated achievement.
 *
 * Dengan metode ini tidak ada double-count status workflow, internal transfer
 * tidak menggelembungkan achievement, dan tidak ada
 * ketergantungan pada section1 Finance Dashboard Detail untuk achievement.
 * DashboardDetailService tetap dipakai hanya untuk TARGET / pembanding internal.
 * Target office mengikuti periode target total/proxy yang sama agar tidak tampil Rp0.
 * ============================================================ */

$officeOrderRmi = ['BGR','BKS','TGR','SLO','BDG','SMG','JGY','KAL'];

/*
 * Target office harus memakai sumber/periode yang sama dengan target total.
 * Jika bulan berjalan belum mempunyai target dan target total memakai proxy
 * bulan sebelumnya, office juga mengambil target dari $proxyData.
 */
$officeTargetFinal=[];
foreach ($officeOrderRmi as $oc) {
    $t=(float)($detailData['targets']['office_total'][$oc] ?? 0);
    if ($t<=0 && isset($proxyData) && is_array($proxyData)) {
        $t=(float)($proxyData['targets']['office_total'][$oc] ?? 0);
    }
    $officeTargetFinal[$oc]=$t;
}

$achievementByOffice = [];
foreach ($officeOrderRmi as $oc) {
    $achievementByOffice[$oc] = [
        'amount'=>0.0,
        'count'=>0,
        'target'=>(float)($officeTargetFinal[$oc] ?? 0),
        'recognized_internal'=>0.0, // finalized below from canonical ledger to prevent workflow-status drift
        'by_status'=>[],
    ];
}

$revRecon = [
    'enabled'=>($office===''),
    'compare_date'=>$mtd_end,
    'source'=>'sales_do BMHP-* current active; net items; exact RETUR FINAL mengurangi DO asal; revision/replacement metadata audit-only; MTD by do_date; created_at audit-only; current explicit terminal status only; legacy blank status accepted after ledger validation; internal excluded dari explicit flag/code + anchored Kantor RMI/Kantor Depo identity; return FINAL mengurangi item DO asal bila commercial_effect=REVERSAL/REPLACEMENT; ACCUNIT excluded entirely',
    'office_rows'=>[],
    'process_rows'=>[],
    'excluded_rows'=>[],
    'rmi_achievement'=>0.0,
    'recognized_internal'=>0.0,
    'recognized_gap'=>0.0,
    'achievement_count'=>0,
    'internal_transfer_total'=>0.0,
    'internal_transfer_count'=>0,
    'internal_transfer_rows'=>[],
    'unclassified_total'=>0.0,
    'unclassified_count'=>0,
    'unclassified_rows'=>[],
    'included_rows'=>[],
    'daily_totals'=>[],
    'daily_office_totals'=>[],
    'integrity'=>['office_sum'=>0.0,'kpi_sum'=>0.0,'delta'=>0.0,'ok'=>true],
    'small_included_rows'=>[],
    'gross_net_delta_total'=>0.0,
    'gross_net_delta_rows'=>[],
    'late_backdated_rows'=>[],
    'late_entry_after_asof_total'=>0.0,
    'late_entry_after_asof_count'=>0,
    'excluded_status_total'=>0.0,
    'excluded_status_count'=>0,
    'legacy_blank_status_total'=>0.0,
    'legacy_blank_status_count'=>0,
    'legacy_blank_status_rows'=>[],
    'return_adjustment_total'=>0.0,
    'return_adjustment_count'=>0,
    'return_adjustment_rows'=>[],
    'replacement_rows'=>[],
    'replacement_total'=>0.0,
    'replacement_count'=>0,
    'legacy_linkage_rows'=>[],
    'revision_duplicate_rows'=>[],
    'revision_duplicate_total'=>0.0,
    'revision_duplicate_count'=>0,
    'return_source'=>null,
    'accunit_display'=>0.0,
    'recon_diag'=>[
        'source_row_total'=>0.0,
        'source_row_count'=>0,
        'office_excluded_total'=>0.0,
        'office_excluded_count'=>0,
        'zero_after_return_total'=>0.0,
        'zero_after_return_count'=>0,
    ],
];


if (es_table_exists($pdo,'sales_do')) {
    try {
        $dateCol   = es_first_col($pdo,'sales_do',['do_date']);
        $statusCol = es_first_col($pdo,'sales_do',['status']);

        // Header gross hanya diagnostik.
        $amtParts=[];
        foreach (['total_amount','grand_total','nilai_do'] as $c) {
            if (es_col_exists($pdo,'sales_do',$c)) {
                $amtParts[]="NULLIF(d.`{$c}`,0)";
            }
        }
        $grossExpr = $amtParts ? ('COALESCE('.implode(',',$amtParts).',0)') : '0';

        /*
         * Achievement = net sales DO.
         * Prioritas:
         * 1) SUM(sales_do_items.subtotal) per do_id
         * 2) kolom net/subtotal header bila tersedia
         * 3) fallback gross header
         *
         * Correlated subquery menjaga satu sales_do tetap satu row.
         */
        $itemNetExpr = "NULL";
        if (es_table_exists($pdo,'sales_do_items')
            && es_col_exists($pdo,'sales_do_items','do_id')
            && es_col_exists($pdo,'sales_do_items','subtotal')) {
            $rmiGroupExpr=es_item_group_norm_sql($pdo,'i');
            $itemNetExpr = "(SELECT SUM(i.subtotal) FROM sales_do_items i WHERE i.do_id=d.id)";
        }

        $netHeaderParts=[];
        foreach (['net_amount','net_total','subtotal','dpp','amount_before_tax','total_before_tax','sales_amount'] as $c) {
            if (es_col_exists($pdo,'sales_do',$c)) {
                $netHeaderParts[]="NULLIF(d.`{$c}`,0)";
            }
        }
        $netHeaderExpr = $netHeaderParts ? ('COALESCE('.implode(',',$netHeaderParts).')') : 'NULL';
        $amountExpr = "COALESCE(NULLIF({$itemNetExpr},0), {$netHeaderExpr}, {$grossExpr}, 0)";

        if ($dateCol && $statusCol) {
            $cutoffTs = $mtd_end.' 23:59:59';

            // Retur final adalah audit operasional/stok dan tidak otomatis mengurangi DO ASAL.
            // DO pengganti ter-link adalah fulfillment operasional dan bernilai 0 tambahan achievement.
            // Tidak ada dedup berbasis nominal/customer/SKU/tanggal.
            $returnAdj = es_final_return_adjustments($pdo,$cutoffTs);

            /*
             * V30 STRICT EXPLICIT-LINKAGE GUARD.
             * Jangan percaya hasil generic discovery untuk menandai DO pengganti.
             * Generic column names seperti source_do_id / parent_do_id / return_id dapat
             * mempunyai arti lain pada data legacy dan sebelumnya menyebabkan DO sah
             * bernilai besar ikut menjadi replacement (achievement turun Rp8 jutaan).
             *
             * Replacement hanya boleh berasal dari:
             * 1) sales_do.replacement_for_do_id / replacement_return_id (dibaca per-row di bawah),
             * 2) sales_do_returns.replacement_do_id yang eksplisit, atau
             * 3) legacy matcher fail-closed di bawah.
             */
            $returnAdj['replacement_do_ids'] = [];
            $returnAdj['replacement_do_codes'] = [];
            // final_return_ids dari item-level discovery tetap dipertahankan.
            // Tambahkan validasi langsung dari sales_do_returns agar
            // replacement_return_id dapat dipakai walau replacement_do_id pada
            // header retur belum terisi.
            try {
                if (es_table_exists($pdo,'sales_do_returns')
                    && es_col_exists($pdo,'sales_do_returns','do_id')) {

                    $retStatusCol = es_first_col($pdo,'sales_do_returns',['status','return_status','retur_status','workflow_status']);
                    $retDateCol   = es_first_col($pdo,'sales_do_returns',['scm_completed_at','completed_at','updated_at','created_at']);
                    $retIdCol     = es_first_col($pdo,'sales_do_returns',['id']);
                    $hasReplacementDoId = es_col_exists($pdo,'sales_do_returns','replacement_do_id');

                    if ($retStatusCol && $retDateCol && $retIdCol) {
                        $repSel = $hasReplacementDoId ? "r.replacement_do_id" : "NULL";
                        $qExactRep=$pdo->prepare("SELECT r.`{$retIdCol}` return_id,
                                                        r.do_id original_do_id,
                                                        {$repSel} replacement_do_id,
                                                        LOWER(TRIM(COALESCE(r.`{$retStatusCol}`,''))) return_status,
                                                        r.`{$retDateCol}` effective_at
                                                 FROM sales_do_returns r
                                                 WHERE r.do_id IS NOT NULL
                                                   AND r.do_id>0
                                                   AND r.`{$retDateCol}`<=?");
                        $qExactRep->execute([$cutoffTs]);

                        $allowedFinal=['return_scm_completed','retur_selesai_scm','return_selesai_scm','selesai_scm','return_completed','retur_completed','completed','closed','final','finished'];
                        foreach($qExactRep->fetchAll(PDO::FETCH_ASSOC) ?: [] as $er){
                            $stx=strtolower(trim((string)($er['return_status']??'')));
                            if(!in_array($stx,$allowedFinal,true)
                               && !(str_contains($stx,'scm') && (str_contains($stx,'selesai') || str_contains($stx,'complete') || str_contains($stx,'final') || str_contains($stx,'done')))) {
                                continue;
                            }

                            $returnId=(int)($er['return_id']??0);
                            $oid=(int)($er['original_do_id']??0);
                            if($returnId<=0 || $oid<=0) continue;

                            // Parent DO harus benar-benar ada dan BMHP.
                            $qParent=$pdo->prepare("SELECT id,UPPER(TRIM(COALESCE(do_code,''))) do_code
                                                   FROM sales_do WHERE id=? LIMIT 1");
                            $qParent->execute([$oid]);
                            $parent=$qParent->fetch(PDO::FETCH_ASSOC) ?: [];
                            $parentCode=(string)($parent['do_code']??'');
                            if((int)($parent['id']??0)!==$oid || !str_starts_with($parentCode,'BMHP-')){
                                $returnAdj['diagnostic']['ignored_broken_return_parent'][]=$er;
                                continue;
                            }

                            // return_id final + parent-nya menjadi sumber validasi utama
                            // untuk sales_do.replacement_return_id.
                            $returnAdj['final_return_ids'][$returnId]=true;
                            $returnAdj['return_parent_do_ids'][$returnId]=$oid;
                            $returnAdj['return_parent_do_codes'][$returnId]=$parentCode;

                            $eid=(int)($er['replacement_do_id']??0);
                            if($eid<=0 || $eid===$oid) continue;

                            // Bila header return sudah menunjuk replacement_do_id,
                            // validasi kedua DO sebelum menandai replacement.
                            $qChk=$pdo->prepare("SELECT id,UPPER(TRIM(COALESCE(do_code,''))) do_code
                                                 FROM sales_do WHERE id IN (?,?)");
                            $qChk->execute([$oid,$eid]);
                            $found=[];
                            foreach($qChk->fetchAll(PDO::FETCH_ASSOC) ?: [] as $fr){
                                $found[(int)$fr['id']]=(string)($fr['do_code']??'');
                            }
                            if(!isset($found[$oid],$found[$eid])) {
                                $returnAdj['diagnostic']['ignored_broken_explicit_replacement'][]=$er;
                                continue;
                            }
                            if(!str_starts_with($found[$oid],'BMHP-') || !str_starts_with($found[$eid],'BMHP-')) {
                                $returnAdj['diagnostic']['ignored_non_bmhp_replacement'][]=$er;
                                continue;
                            }

                            $returnAdj['replacement_do_ids'][$eid]=true;
                            $returnAdj['replacement_do_codes'][$found[$eid]]=true;
                            $returnAdj['diagnostic']['explicit_replacement'][]=[
                                'return_id'=>$returnId,
                                'original_do_id'=>$oid,
                                'original_do_code'=>$found[$oid],
                                'replacement_do_id'=>$eid,
                                'replacement_do_code'=>$found[$eid],
                                'effective_at'=>$er['effective_at']??null,
                            ];
                        }
                    }
                }
            } catch(Throwable $e) {
                $returnAdj['diagnostic']['exact_replacement_error']=$e->getMessage();
            }

            /*
             * V30 — STRICT REPLACEMENT ONLY.
             *
             * Legacy inference berbasis office/customer/SKU/qty/PO dinonaktifkan untuk
             * perhitungan KPI. Walaupun dibuat fail-closed, inference tetap dapat salah
             * pada transaksi rumah sakit yang membeli SKU/PO serupa dan terbukti pada
             * V29 menyebabkan DO sah ikut ditandai sebagai replacement (jumlah DO turun
             * lebih dari satu).
             *
             * Replacement yang boleh mengubah achievement HANYA:
             * 1) linkage eksplisit pada row sales_do: replacement_for_do_id atau
             *    replacement_return_id; atau
             * 2) sales_do_returns.replacement_do_id yang eksplisit DAN tervalidasi ke
             *    return FINAL yang memang mempunyai original do_id.
             *
             * Tidak ada matching nominal/customer/SKU/tanggal/PO untuk menentukan
             * replacement. Jika linkage eksplisit tidak lengkap, sistem fail-open untuk
             * sales normal (DO tetap dihitung) dan hanya menampilkan diagnostic.
             */
            $returnAdj['diagnostic']['legacy_replacement_matching']='DISABLED_V30_STRICT_EXPLICIT_ONLY';

            /*
             * REPLACEMENT FULFILLMENT MAP — EXPLICIT ONLY.
             * Tidak memakai nominal/customer/SKU/date untuk menebak hubungan.
             *
             * Coverage per return:
             * - bila child DO eksplisit ditemukan -> child DO dihitung sebagai fulfillment;
             * - bila legacy return sudah memiliki replacement_created_at tetapi child ID
             *   tidak lagi tersambung -> buat synthetic fulfillment sebesar return_net
             *   pada tanggal replacement_created_at. Ini memakai event eksplisit dari
             *   ledger return, bukan tebak transaksi.
             */
            $replacementChildByReturn = [];
            $replacementChildIds = [];
            $replacementSyntheticRows = [];

            try {
                if (es_table_exists($pdo,'sales_do_returns')) {
                    foreach (($returnAdj['rows'] ?? []) as $rrx) {
                        $rid=(int)($rrx['return_id']??0);
                        $oid=(int)($rrx['original_do_id']??0);
                        if($rid<=0 || $oid<=0) continue;

                        $childIds=[];

                        // A. Header return explicit replacement_do_id.
                        $hdrChild=(int)($rrx['replacement_do_id']??0);
                        if($hdrChild>0 && $hdrChild!==$oid) $childIds[$hdrChild]=true;

                        // B/C. Child-side explicit linkage.
                        if (es_col_exists($pdo,'sales_do','replacement_return_id')
                            || es_col_exists($pdo,'sales_do','replacement_for_do_id')) {
                            $parts=[]; $pp=[];
                            if (es_col_exists($pdo,'sales_do','replacement_return_id')) {
                                $parts[]="replacement_return_id=?";
                                $pp[]=$rid;
                            }
                            if (es_col_exists($pdo,'sales_do','replacement_for_do_id')) {
                                $parts[]="replacement_for_do_id=?";
                                $pp[]=$oid;
                            }
                            if($parts){
                                $q=$pdo->prepare("SELECT id
                                                  FROM sales_do
                                                  WHERE id<>?
                                                    AND (".implode(' OR ',$parts).")
                                                    AND LOWER(COALESCE(status,'')) NOT IN
                                                        ('draft','cancelled','canceled','cancel','rejected','reject','void','voided','deleted','inactive')
                                                  ORDER BY id");
                                $q->execute(array_merge([$oid],$pp));
                                foreach($q->fetchAll(PDO::FETCH_COLUMN) ?: [] as $cid){
                                    $cid=(int)$cid;
                                    if($cid>0) $childIds[$cid]=true;
                                }
                            }
                        }

                        if($childIds){
                            $replacementChildByReturn[$rid]=array_keys($childIds);
                            foreach(array_keys($childIds) as $cid) $replacementChildIds[(int)$cid]=$rid;
                            continue;
                        }

                        // D. Legacy explicit replacement event.
                        // replacement_created_at hanya diisi oleh flow pembuatan DO pengganti.
                        $repCreated=trim((string)($rrx['replacement_created_at']??''));
                        $returnNet=(float)($rrx['return_net']??0);
                        if($repCreated!=='' && $returnNet>0 && $repCreated<=$cutoffTs){
                            $replacementSyntheticRows[]=[
                                'return_id'=>$rid,
                                'original_do_id'=>$oid,
                                'original_do_code'=>(string)($rrx['original_do_code']??''),
                                'office_code'=>(string)($rrx['original_office_code']??''),
                                'effective_date'=>substr($repCreated,0,10),
                                'amount'=>$returnNet,
                                'source'=>'sales_do_returns.replacement_created_at',
                            ];
                        }
                    }
                }
            } catch(Throwable $e) {
                $revRecon['return_diagnostic']['replacement_fulfillment_map_error']=$e->getMessage();
            }

            $revRecon['replacement_synthetic_rows']=$replacementSyntheticRows;

            // Exact fulfillment value per linked child = return_net original.
            // Jangan memakai subtotal child replacement karena child bukan order komersial baru.
            $replacementValueByDoId=[];
            $replacementEffectiveDateByDoId=[];
            foreach (($returnAdj['rows'] ?? []) as $rrx) {
                $rid=(int)($rrx['return_id']??0);
                $retNet=(float)($rrx['return_net']??0);
                $repAt=trim((string)($rrx['replacement_created_at']??''));
                foreach (($replacementChildByReturn[$rid] ?? []) as $cid) {
                    $cid=(int)$cid;
                    if($cid<=0) continue;
                    $replacementValueByDoId[$cid]=$retNet;
                    if($repAt!=='') $replacementEffectiveDateByDoId[$cid]=substr($repAt,0,10);
                }
            }

            $revRecon['return_source']=$returnAdj['source_table'] ?? null;
            $revRecon['return_diagnostic']=$returnAdj['diagnostic'] ?? [];

            // Data-quality governance: NEEDS_REVIEW periode aktif = blocker; historis lama = audit.
            $returnAdj['unclassified_current_rows']=[];
            $returnAdj['unclassified_historical_rows']=[];
            foreach(($returnAdj['unclassified_rows'] ?? []) as $ucr){
                $udd=substr((string)($ucr['do_date']??''),0,10);
                if($udd!=='' && $udd>=$month_start && $udd<=$mtd_end){
                    $returnAdj['unclassified_current_rows'][]=$ucr;
                } else {
                    $returnAdj['unclassified_historical_rows'][]=$ucr;
                }
            }

            /*
             * V12 — STATUS KPI = CURRENT sales_do.status.
             *
             * Untuk pencapaian manual/aktual, do_date adalah business date dan status
             * yang dipakai adalah status ledger saat ini. Audit history tidak boleh
             * membuat DO yang sekarang sah/aktif hilang dari MTD hanya karena pada
             * snapshot lama status_to masih draft/kosong.
             */
            $hasAudit = false;
            $statusExpr = "LOWER(TRIM(COALESCE(d.`{$statusCol}`,'')))";

            /*
             * PENTING: jangan JOIN master_customers secara langsung ke sales_do.
             * customers_code pada master lama dapat duplikat / customer_id dan code
             * dapat menunjuk baris berbeda. JOIN akan menggandakan satu DO.
             * Gunakan correlated subquery LIMIT 1 agar satu sales_do tetap satu row.
             */
            $hasCustomers = es_table_exists($pdo,'master_customers');
            $custNameExpr = "NULL";
            $custTypeExpr = "NULL";
            $custInternalExpr = "NULL";

            if ($hasCustomers) {
                $custNameCol = es_first_col($pdo,'master_customers',['customers_name','customer_name','name']);
                $custTypeCol = es_first_col($pdo,'master_customers',['customer_type','customers_type','type','category','customer_category']);
                $custInternalCol = es_first_col($pdo,'master_customers',['is_internal','internal_flag','is_intercompany']);

                /*
                 * V17 — customer master lookup harus deterministik.
                 * Jangan OR customer_id dengan customers_code karena bila data master
                 * punya duplicate code / stale customer_id, correlated subquery bisa
                 * memilih customer lain dan salah menandai DO sebagai INTERNAL.
                 *
                 * Prioritas:
                 * 1. customer_id exact bila tersedia dan >0;
                 * 2. fallback customers_code hanya bila customer_id kosong/0.
                 */
                $hasSalesCustomerId = es_col_exists($pdo,'sales_do','customer_id');
                $hasMasterId = es_col_exists($pdo,'master_customers','id');
                $hasMasterCode = es_col_exists($pdo,'master_customers','customers_code');

                if ($hasSalesCustomerId && $hasMasterId && $hasMasterCode) {
                    $custMatch = "((COALESCE(d.customer_id,0)>0 AND c.id=d.customer_id)
                                   OR (COALESCE(d.customer_id,0)<=0 AND c.customers_code=d.customers_code))";
                } elseif ($hasSalesCustomerId && $hasMasterId) {
                    $custMatch = "c.id=d.customer_id";
                } elseif ($hasMasterCode) {
                    $custMatch = "c.customers_code=d.customers_code";
                } else {
                    $custMatch = "1=0";
                }

                $custOrder = '';

                if ($custNameCol) {
                    $custNameExpr = "(SELECT c.`{$custNameCol}` FROM master_customers c WHERE {$custMatch}{$custOrder} LIMIT 1)";
                }
                if ($custTypeCol) {
                    $custTypeExpr = "(SELECT c.`{$custTypeCol}` FROM master_customers c WHERE {$custMatch}{$custOrder} LIMIT 1)";
                }
                if ($custInternalCol) {
                    $custInternalExpr = "(SELECT c.`{$custInternalCol}` FROM master_customers c WHERE {$custMatch}{$custOrder} LIMIT 1)";
                }
            }

            // Metadata sales_do yang mungkin menandai transfer/non-sales.
            $doMetaCols=[];
            foreach (['do_type','transaction_type','sales_type','order_type','source','channel','source_type','movement_type'] as $mc) {
                if (es_col_exists($pdo,'sales_do',$mc)) $doMetaCols[]=$mc;
            }
            if (!es_col_exists($pdo,'sales_do','is_replacement_fulfillment')) {
                try { $pdo->exec("ALTER TABLE `sales_do` ADD COLUMN `is_replacement_fulfillment` TINYINT(1) NOT NULL DEFAULT 0"); }
                catch(Throwable $e) {}
            }
            $doInternalFlag = es_first_col($pdo,'sales_do',['is_internal','is_internal_transfer','internal_flag','is_intercompany']);

            $sql = "SELECT
                        d.id,
                        d.do_code,
                        d.`{$dateCol}` AS do_date,
                        UPPER(TRIM(COALESCE(d.office_code,''))) AS office_code_db,
                        d.customers_code,
                        {$custNameExpr} AS customer_name,
                        {$custTypeExpr} AS customer_type,
                        {$custInternalExpr} AS customer_internal_flag,
                        {$statusExpr} AS status_asof,
                        {$grossExpr} AS nilai_gross,
                        {$amountExpr} AS nilai_net";

            // Source of truth DO pengganti: linkage eksplisit yang dibuat sales_do.php.
            // DO pengganti adalah fulfillment operasional, BUKAN penjualan baru.
            if (es_col_exists($pdo,'sales_do','replacement_for_do_id')) {
                $sql .= ", d.replacement_for_do_id";
            } else {
                $sql .= ", NULL AS replacement_for_do_id";
            }
            if (es_col_exists($pdo,'sales_do','replacement_return_id')) {
                $sql .= ", d.replacement_return_id";
            } else {
                $sql .= ", NULL AS replacement_return_id";
            }
            if (es_col_exists($pdo,'sales_do','revision_of_do_id')) {
                $sql .= ", d.revision_of_do_id";
            } else {
                $sql .= ", NULL AS revision_of_do_id";
            }
            if (es_col_exists($pdo,'sales_do','is_replacement_fulfillment')) {
                $sql .= ", d.is_replacement_fulfillment";
            } else {
                $sql .= ", 0 AS is_replacement_fulfillment";
            }

            foreach ($doMetaCols as $mc) {
                $sql .= ", d.`{$mc}` AS `meta_{$mc}`";
            }
            if ($doInternalFlag) {
                $sql .= ", d.`{$doInternalFlag}` AS do_internal_flag";
            } else {
                $sql .= ", NULL AS do_internal_flag";
            }

            $hasCreatedAt = es_col_exists($pdo,'sales_do','created_at');
            if ($hasCreatedAt) {
                $sql .= ", d.created_at, DATE(d.`{$dateCol}`) AS achievement_date";
            } else {
                $sql .= ", NULL AS created_at, DATE(d.`{$dateCol}`) AS achievement_date";
            }

            // Periode MTD selalu mengikuti business date pada sales_do.do_date.
            $sql .= " FROM sales_do d
                      WHERE DATE(d.`{$dateCol}`) BETWEEN ? AND ?
                        AND UPPER(TRIM(COALESCE(d.do_code,''))) LIKE 'BMHP-%'";

            $params = [$month_start,$mtd_end];

            /*
             * V11 — BUSINESS DATE = SOURCE OF TRUTH UNTUK SALES MTD.
             * created_at hanya audit late-entry/backdated, bukan filter KPI.
             */
            if (es_col_exists($pdo,'sales_do','deleted_at')) {
                $sql .= " AND d.deleted_at IS NULL";
            }

            $sql .= " ORDER BY DATE(d.`{$dateCol}`),d.id";

            $st=$pdo->prepare($sql);
            $st->execute($params);
            $rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            /*
             * V13 — legacy-safe status rule.
             *
             * Status kosong pada data lama TIDAK otomatis berarti draft/cancel.
             * Jika header BMHP punya do_date, nilai > 0, office canonical, dan bukan
             * internal/intercompany, tetap dihitung. Hanya status terminal eksplisit
             * yang dikeluarkan. Draft eksplisit tetap tidak dihitung.
             */
            $excludedStatuses = [
                'draft','cancelled','canceled','cancel','rejected','reject',
                'void','voided','deleted','inactive'
            ];

            // Backdated DO tidak didedup berdasarkan kemiripan item.
            // sales_do.id adalah identitas transaksi; bila aktif dan valid, dihitung satu kali.

            $seenDoIds=[];
            foreach($rows as $r){
                $doId=(string)($r['id']??'');
                if($doId!=='' && isset($seenDoIds[$doId])) {
                    // Safety-net: satu sales_do tidak boleh pernah dihitung dua kali.
                    continue;
                }
                if($doId!=='') $seenDoIds[$doId]=true;
                $doCode = strtoupper(trim((string)($r['do_code']??'')));
                $status = strtolower(trim((string)($r['status_asof']??'')));
                $originalValue = (float)($r['nilai_net']??0);
                $grossValue = (float)($r['nilai_gross']??0);

                // FINAL BUSINESS RULE V25 — PARTIAL RETURN + REPLACEMENT SAFE.
                // 1) DO asal tetap merupakan transaksi komersial dan tetap menjadi achievement.
                //    Retur SCM adalah pergerakan barang/stok, bukan credit note revenue.
                // 2) DO pengganti adalah fulfillment operasional atas item yang diretur, BUKAN penjualan baru.
                //    Maka DO pengganti selalu 0 untuk achievement.
                // 3) Return amount hanya berasal dari item-level return ledger dengan linkage eksplisit.
                //    Tidak ada hard-code nominal, tanggal, customer, SKU, atau kode DO tertentu.
                //
                // Lineage yang benar untuk partial return:
                //   effective commercial sales = ORIGINAL (selama tidak ada credit note/cancel/void)
                //   REPLACEMENT DO             = 0 revenue
                // Ini mencegah dua error sekaligus: item retur masih ikut sales dan DO pengganti ikut dihitung lagi.
                $doIdInt=(int)($r['id']??0);
                $explicitReplacementFor=(int)($r['replacement_for_do_id']??0);
                $explicitReplacementReturn=(int)($r['replacement_return_id']??0);

                /*
                 * V31 — VALIDATED REPLACEMENT LINKAGE.
                 * replacement_return_id SENDIRI tidak cukup untuk membuat DO = 0 revenue.
                 * Pada data legacy kolom tersebut dapat terisi pada DO biasa dan V30 terbukti
                 * menghilangkan 1 DO tambahan (selisih Rp604.080 pada screenshot 07-Sep).
                 *
                 * sales_do.replacement_for_do_id juga hanya dipercaya bila DO parent memang
                 * mempunyai FINAL item-return adjustment. Dengan demikian relasinya lengkap:
                 *     replacement DO -> original DO -> final returned item.
                 * Exact sales_do_returns.replacement_do_id tetap merupakan bukti eksplisit sah.
                 */
                $replacementByExactReturn = ($doIdInt>0 && !empty($returnAdj['replacement_do_ids'][$doIdInt]))
                    || ($doCode!=='' && !empty($returnAdj['replacement_do_codes'][$doCode]));

                $replacementByValidatedParent = false;
                if($explicitReplacementFor>0 && $explicitReplacementFor!==$doIdInt){
                    $parentReturn = (float)($returnAdj['by_do_id'][$explicitReplacementFor] ?? 0);
                    if($parentReturn>0){
                        $replacementByValidatedParent = true;
                    }
                }

                // replacement_return_id BOLEH menjadi classifier hanya setelah
                // return_id tersebut terbukti FINAL dan mempunyai parent DO BMHP.
                // Ini adalah jalur yang diperlukan untuk kasus DO pengganti yang
                // dibuat setelah retur selesai tetapi sales_do_returns.replacement_do_id
                // belum/tidak ikut terisi.
                $replacementByValidatedReturnId = false;
                if($explicitReplacementReturn>0
                   && !empty($returnAdj['final_return_ids'][$explicitReplacementReturn])) {
                    $parentFromReturn=(int)($returnAdj['return_parent_do_ids'][$explicitReplacementReturn] ?? 0);
                    if($parentFromReturn>0 && $parentFromReturn!==$doIdInt){
                        // Jika row replacement juga punya replacement_for_do_id,
                        // keduanya harus konsisten. Jika kolom parent kosong, return_id
                        // final sudah cukup karena linkage-nya eksplisit.
                        if($explicitReplacementFor<=0 || $explicitReplacementFor===$parentFromReturn){
                            $replacementByValidatedReturnId = true;
                        }
                    }
                }

                /*
                 * V21 — EXPLICIT LINEAGE ONLY.
                 * Tidak ada aturan berbasis nomor DO tertentu.
                 * DO normal dihitung normal. DO replacement hanya dikenali dari flag/linkage
                 * eksplisit yang tervalidasi dan dihitung sebagai fulfillment NET satu kali.
                 */
                $explicitReplacementFor=(int)($r['replacement_for_do_id']??0);
                $explicitReplacementReturn=(int)($r['replacement_return_id']??0);
                $revisionOfDoId=(int)($r['revision_of_do_id']??0);

                // Audit only — tidak memengaruhi value.
                $isReplacementDo=false;
                $isRevisionDuplicate=false;
                $r['replacement_for_do_id_audit']=$explicitReplacementFor;
                $r['replacement_return_id_audit']=$explicitReplacementReturn;
                $r['revision_of_do_id_audit']=$revisionOfDoId;
                $r['is_replacement_do']=0;
                $r['is_revision_duplicate']=0;

                $returnValueAudit=0.0;
                if($doIdInt>0 && isset($returnAdj['by_do_id'][$doIdInt])) {
                    $returnValueAudit=(float)$returnAdj['by_do_id'][$doIdInt];
                } elseif($doCode!=='' && isset($returnAdj['by_do_code'][$doCode])) {
                    $returnValueAudit=(float)$returnAdj['by_do_code'][$doCode];
                }
                $returnValueAudit=max(0.0,min($originalValue,$returnValueAudit));

                $value=max(0.0,$originalValue-$returnValueAudit);

                // Replacement fulfillment:
                // original return REPLACEMENT mengurangi item yang diretur,
                // sedangkan linked child hanya menyumbang NET item pengganti sebagai fulfillment.
                // Aman untuk partial replacement dari FULL return.
                $explicitReplacementFulfillment=((int)($r['is_replacement_fulfillment']??0)===1);
                if($explicitReplacementFulfillment){
                    $r['is_replacement_do']=1;
                    $value=max(0.0,$originalValue);
                }

                $r['nilai_net_original']=$originalValue;
                $revRecon['recon_diag']['source_row_total'] += $originalValue;
                $revRecon['recon_diag']['source_row_count']++;
                $r['return_adjustment']=$returnValueAudit;
                $r['return_operational_audit']=$returnValueAudit;
                $r['nilai_net_effective']=$value;
                $r['gross_net_delta'] = $grossValue - $originalValue;

                // Metadata lineage legacy yang belum tervalidasi hanya audit.
                if(($explicitReplacementFor>0 || $explicitReplacementReturn>0 || $revisionOfDoId>0)
                   && !$explicitReplacementFulfillment){
                    $metaRow=$r;
                    $metaRow['nilai_net_effective']=$value;
                    $metaRow['note']='Metadata lineage legacy belum menjadi classifier KPI tanpa flag/linkage replacement yang tervalidasi.';
                    $revRecon['legacy_linkage_rows'][]=$metaRow;
                }

                // Replacement sah: audit dan total fulfillment harus konsisten dengan KPI.
                if($explicitReplacementFulfillment){
                    $repRow=$r;
                    $repRow['nilai_net_effective']=$value;
                    $repRow['replacement_child_net_audit']=$originalValue;
                    $repRow['note']='Replacement fulfillment ter-link eksplisit; kontribusi NET dihitung satu kali.';
                    $revRecon['replacement_rows'][]=$repRow;
                    $revRecon['replacement_total'] += $value;
                    $revRecon['replacement_count']++;
                }

                if($returnValueAudit>0){
                    $rrAdj=$r;
                    $rrAdj['office_code_pending']=true;
                    $rrAdj['note']='Exact item RETUR FINAL mengurangi DO asal sesuai actual ledger.';
                    $revRecon['return_adjustment_total'] += $returnValueAudit;
                    $revRecon['return_adjustment_count']++;
                    $rrAdj['return_operational_audit']=$returnValueAudit;
                    $revRecon['return_adjustment_rows'][]=$rrAdj;
                }

                // Office canonical dari kode DO: BMHP-<OFFICE>-...
                $oc = '';
                if (preg_match('/^BMHP-([A-Z0-9]+)-/', $doCode, $m)) {
                    $cand = strtoupper($m[1]);
                    if (in_array($cand,$officeOrderRmi,true)) $oc=$cand;
                }
                if ($oc==='') $oc=es_up($r['office_code_db']??'');

                if($returnValueAudit>0 && !empty($revRecon['return_adjustment_rows'])){
                    $lastIdx=count($revRecon['return_adjustment_rows'])-1;
                    if(($revRecon['return_adjustment_rows'][$lastIdx]['id']??null)==($r['id']??null)){
                        $revRecon['return_adjustment_rows'][$lastIdx]['office_code']=$oc;
                        unset($revRecon['return_adjustment_rows'][$lastIdx]['office_code_pending']);
                    }
                }
                if($explicitReplacementFulfillment && !empty($revRecon['replacement_rows'])){
                    $lastRepIdx=count($revRecon['replacement_rows'])-1;
                    if(($revRecon['replacement_rows'][$lastRepIdx]['id']??null)==($r['id']??null)){
                        $revRecon['replacement_rows'][$lastRepIdx]['office_code']=$oc;
                    }
                }

                if (!isset($achievementByOffice[$oc])) {
                    $r['excluded_reason']='OFFICE_NON_CANONICAL';
                    $r['excluded_value']=$value;
                    $r['note']='Excluded: office bukan RMI canonical';
                    $revRecon['recon_diag']['office_excluded_total'] += $value;
                    $revRecon['recon_diag']['office_excluded_count']++;
                    $revRecon['excluded_rows'][]=$r;
                    continue;
                }

                if (in_array($status,$excludedStatuses,true)) {
                    $r['office_code']=$oc;
                    $r['excluded_reason']='STATUS_'.strtoupper($status);
                    $r['excluded_value']=$value;
                    $r['note']='Excluded: current status tidak aktif';
                    $revRecon['excluded_status_total'] += max(0.0,$value);
                    $revRecon['excluded_status_count']++;
                    $revRecon['excluded_rows'][]=$r;
                    continue;
                }

                if ($value<=0) {
                    $r['office_code']=$oc;
                    $r['excluded_reason']=$returnValueAudit>0 ? 'FULL_RETURN_FINAL' : 'ZERO_VALUE';
                    $r['excluded_value']=$originalValue;
                    $r['note']=$returnValueAudit>0
                        ? 'Excluded: seluruh nilai DO sudah RETUR FINAL'
                        : 'Excluded: nilai DO <= 0';
                    if($returnValueAudit>0){
                        $revRecon['recon_diag']['zero_after_return_total'] += $originalValue;
                        $revRecon['recon_diag']['zero_after_return_count']++;
                    }
                    $revRecon['excluded_rows'][]=$r;
                    continue;
                }

                // INTERNAL TRANSFER bukan penjualan eksternal.
                // Bukti nyata pada data 02-Sep: SLO-INT Rp31.785.648 ikut terjumlah
                // di versi sebelumnya dan menjelaskan hampir seluruh overstatement.
                $custCode = strtoupper(trim((string)($r['customers_code']??'')));
                $custName = strtoupper(trim((string)($r['customer_name']??'')));
                $custType = strtoupper(trim((string)($r['customer_type']??'')));
                $custInternalFlag = strtolower(trim((string)($r['customer_internal_flag']??'')));
                $doInternalFlagVal = strtolower(trim((string)($r['do_internal_flag']??'')));

                $metaTextParts=[];
                foreach($doMetaCols as $mc){
                    $metaTextParts[] = strtoupper(trim((string)($r['meta_'.$mc]??'')));
                }
                $metaText = implode(' | ',$metaTextParts);

                /*
                 * V17 — internal transfer hanya dari bukti eksplisit/high-confidence.
                 * Jangan exclude hanya karena nama customer / free-text metadata
                 * mengandung kata tertentu; itu pernah berisiko membuang DO eksternal valid.
                 */
                $truthyFlags=['1','Y','YES','TRUE','INTERNAL','INTERCOMPANY'];
                $explicitInternalCode = (bool)preg_match('/(^|[-_])(INT|INTERNAL)($|[-_])/', $custCode);
                $explicitCustomerType = in_array($custType,['INTERNAL','INTERCOMPANY'],true);
                $explicitCustomerFlag = in_array(strtoupper($custInternalFlag),$truthyFlags,true);
                $explicitDoFlag = in_array(strtoupper($doInternalFlagVal),$truthyFlags,true);

                $explicitInternalName =
                    str_starts_with($custName,'KANTOR RIZQULLAH MEDISKA INDONESIA')
                    || str_starts_with($custName,'KANTOR DEPO ');

                $isInternalTransfer =
                    $explicitInternalCode
                    || $explicitInternalName
                    || $explicitCustomerType
                    || $explicitCustomerFlag
                    || $explicitDoFlag;

                if ($isInternalTransfer) {
                    $r['office_code']=$oc;
                    $r['excluded_reason']='INTERNAL_TRANSFER';
                    $r['excluded_value']=$value;
                    $r['internal_reason'] = implode(',', array_filter([
                        $explicitInternalCode ? 'CUSTOMER_CODE' : '',
                        $explicitInternalName ? 'CUSTOMER_NAME_ANCHORED' : '',
                        $explicitCustomerType ? 'CUSTOMER_TYPE' : '',
                        $explicitCustomerFlag ? 'CUSTOMER_FLAG' : '',
                        $explicitDoFlag ? 'DO_FLAG' : '',
                    ]));
                    $r['note']='Excluded INTERNAL TRANSFER explicit: '.($r['internal_reason'] ?: 'UNKNOWN');
                    $revRecon['internal_transfer_total'] += $value;
                    $revRecon['internal_transfer_count']++;
                    $revRecon['internal_transfer_rows'][]=$r;
                    $revRecon['excluded_rows'][]=$r;
                    continue;
                }

                /*
                 * Late-backdated DO — DIAGNOSTIC ONLY.
                 * Jangan menganggap replacement/duplikat berdasarkan customer, item,
                 * qty, lot, harga, atau tanggal yang sama. Jika DO aktif, nilainya > 0,
                 * bukan internal, dan belum cancel/reject/void, maka tetap dihitung 1x.
                 * created_at hanya memastikan DO tidak muncul sebelum benar-benar dibuat.
                 */
                $createdAtStr=(string)($r['created_at']??'');
                $doDateStr=(string)($r['do_date']??'');
                if ($createdAtStr!=='' && $doDateStr!==''
                    && substr($createdAtStr,0,10) > substr($doDateStr,0,10)) {
                    $r['office_code']=$oc;
                    $r['late_entry_after_business_date']=1;
                    $r['created_after_asof']=($createdAtStr>$cutoffTs) ? 1 : 0;
                    $r['note']='Late-entry/backdated DO: tetap dihitung pada do_date karena KPI mengikuti business date';
                    $revRecon['late_backdated_rows'][]=$r;
                }

                /*
                 * Segment bisnis manual = Hermina + Non Hermina.
                 * Pola kode nyata pada ERP: Hxxx / NHxxx.
                 * Jangan masukkan customer lain yang tidak punya klasifikasi eksternal
                 * karena itu sumber residual kecil yang terus terbawa antar tanggal.
                 */
                $codeIsBusinessSegment =
                    preg_match('/^H[0-9A-Z_-]*$/', $custCode)
                    || preg_match('/^NH[0-9A-Z_-]*$/', $custCode);

                $typeIsBusinessSegment =
                    str_contains($custType,'HERMINA')
                    || str_contains($custType,'NON HERMINA')
                    || str_contains($custType,'NON_HERMINA')
                    || str_contains($custType,'EXTERNAL')
                    || str_contains($custType,'CUSTOMER');

                if (!$codeIsBusinessSegment && !$typeIsBusinessSegment) {
                    // Tetap dihitung bila bukan internal/intercompany.
                    // Hanya ditandai untuk governance master customer agar customer baru
                    // di bulan berikutnya tidak hilang dari achievement.
                    $r['office_code']=$oc;
                    $r['note']='UNCLASSIFIED EXTERNAL: tetap dihitung; review master customer';
                    $revRecon['unclassified_total'] += $value;
                    $revRecon['unclassified_count']++;
                    $revRecon['unclassified_rows'][]=$r;
                }

                if ($createdAtStr!=='' && $createdAtStr>$cutoffTs) {
                    $revRecon['late_entry_after_asof_total'] += $value;
                    $revRecon['late_entry_after_asof_count']++;
                }

                // Legacy row dengan status kosong: dihitung bila sudah lolos seluruh
                // validasi business ledger lain. Ditampilkan agar mudah diaudit.
                if ($status==='') {
                    $blankRow=$r;
                    $blankRow['office_code']=$oc;
                    $blankRow['note']='Legacy status kosong: dihitung karena DO BMHP valid, bernilai positif, office canonical, bukan internal, dan bukan status terminal.';
                    $revRecon['legacy_blank_status_total'] += $value;
                    $revRecon['legacy_blank_status_count']++;
                    $revRecon['legacy_blank_status_rows'][]=$blankRow;
                }

                // Satu row = satu header DO. Tidak join items => tidak double-count.
                $achievementByOffice[$oc]['amount'] += $value;
                $achievementByOffice[$oc]['count']++;

                // Evidence exact dari sales_do yang benar-benar ikut achievement.
                $r['office_code']=$oc;
                $r['included_reason']=$explicitReplacementFulfillment ? 'DO Pengganti ter-link: dihitung sebagai fulfillment NET, bukan order customer baru' : ($returnValueAudit>0 ? 'BMHP aktif setelah dikurangi exact RETUR FINAL' : 'BMHP external aktif; dihitung 1x');
                $revRecon['included_rows'][]=$r;

                if (abs((float)$r['gross_net_delta']) >= 0.5) {
                    $revRecon['gross_net_delta_total'] += (float)$r['gross_net_delta'];
                    $revRecon['gross_net_delta_rows'][] = $r;
                }

                // Mutasi business-date mengikuti do_date; snapshot historis tetap dikunci created_at.
                $bizDate = (string)($r['do_date'] ?? $r['achievement_date'] ?? '');
                if ($isReplacementDo) {
                    $effectiveReplacementDate='';
                    if ($explicitReplacementReturn>0) {
                        foreach (($returnAdj['rows'] ?? []) as $rrx) {
                            if ((int)($rrx['return_id']??0)===$explicitReplacementReturn) {
                                $effectiveReplacementDate=trim((string)($rrx['replacement_created_at']??''));
                                break;
                            }
                        }
                    }
                    if (!empty($replacementEffectiveDateByDoId[$doIdInt])) {
                        $effectiveReplacementDate=(string)$replacementEffectiveDateByDoId[$doIdInt];
                    }
                    if ($effectiveReplacementDate==='') {
                        $effectiveReplacementDate=trim((string)($r['created_at']??''));
                    }
                    if ($effectiveReplacementDate!=='') $bizDate=substr($effectiveReplacementDate,0,10);
                }
                $r['achievement_effective_date']=$bizDate;
                if ($bizDate!=='') {
                    if (!isset($revRecon['daily_totals'][$bizDate])) {
                        $revRecon['daily_totals'][$bizDate]=['amount'=>0.0,'count'=>0];
                    }
                    $revRecon['daily_totals'][$bizDate]['amount'] += $value;
                    $revRecon['daily_totals'][$bizDate]['count']++;

                    if (!isset($revRecon['daily_office_totals'][$bizDate])) {
                        $revRecon['daily_office_totals'][$bizDate]=[];
                    }
                    if (!isset($revRecon['daily_office_totals'][$bizDate][$oc])) {
                        $revRecon['daily_office_totals'][$bizDate][$oc]=['amount'=>0.0,'count'=>0];
                    }
                    $revRecon['daily_office_totals'][$bizDate][$oc]['amount'] += $value;
                    $revRecon['daily_office_totals'][$bizDate][$oc]['count']++;
                }

                // Kandidat residual kecil: hanya diagnostik, tidak mengubah angka.
                if ($value <= 500000) {
                    $revRecon['small_included_rows'][]=$r;
                }
                if (!isset($achievementByOffice[$oc]['by_status'][$status])) {
                    $achievementByOffice[$oc]['by_status'][$status]=['amount'=>0.0,'count'=>0];
                }
                $achievementByOffice[$oc]['by_status'][$status]['amount'] += $value;
                $achievementByOffice[$oc]['by_status'][$status]['count']++;

                if (in_array($status,['crm_to_wqs','wqs_processing','ready_scm','on_delivery'],true)) {
                    $r['office_code']=$oc;
                    $r['note']=$explicitReplacementFulfillment ? 'DO Pengganti ter-link: fulfillment NET' : ($returnValueAudit>0 ? 'Dihitung setelah pengurangan exact RETUR FINAL' : 'Dihitung 1x dalam pencapaian aktual BMHP');
                    $revRecon['process_rows'][]=$r;
                }
            }

        }
    } catch(Throwable $e) {
        error_log('[exec_summary][sales-achievement-v12] '.$e->getMessage());
    }
}

usort($revRecon['gross_net_delta_rows'], static function(array $a,array $b): int {
    $da = strcmp((string)($a['achievement_date']??$a['do_date']??''),(string)($b['achievement_date']??$b['do_date']??''));
    if ($da!==0) return $da;
    return abs((float)($b['gross_net_delta']??0)) <=> abs((float)($a['gross_net_delta']??0));
});

usort($revRecon['small_included_rows'], static function(array $a,array $b): int {
    $da = strcmp((string)($a['achievement_date']??$a['do_date']??''),(string)($b['achievement_date']??$b['do_date']??''));
    if ($da!==0) return $da;
    $oa = strcmp((string)($a['office_code']??''),(string)($b['office_code']??''));
    if ($oa!==0) return $oa;
    return ((float)($a['nilai_net']??0) <=> (float)($b['nilai_net']??0));
});

$rebuiltOfficeRows=[];
$rmiAchievement=0.0;
$rmiRecognizedInternal=0.0;
$totalAchievementCount=0;

foreach($officeOrderRmi as $oc){
    $amount=(float)$achievementByOffice[$oc]['amount'];
    $cnt=(int)$achievementByOffice[$oc]['count'];
    $target=(float)($officeTargetFinal[$oc] ?? $achievementByOffice[$oc]['target'] ?? 0);
    $recognizedInternal=(float)$achievementByOffice[$oc]['recognized_internal'];

    // Canonical reconciliation: Finance comparison must use the same eligible BMHP ledger
    // as achievement. Workflow status (crm_to_wqs/ready_scm/etc.) must not suppress
    // a valid external sales DO. This changes diagnostic/presentation only.
    $recognizedInternal = $amount;
    $achievementByOffice[$oc]['recognized_internal'] = $recognizedInternal;

    $rmiAchievement += $amount;
    $rmiRecognizedInternal += $recognizedInternal;
    $totalAchievementCount += $cnt;

    $rebuiltOfficeRows[]=[
        'office_code'=>$oc,
        'office_name'=>$canonicalOffices[$oc]??$oc,
        'target'=>$target,
        'pencapaian'=>$amount,
        'persentase'=>$target>0 ? ($amount/$target)*100.0 : null,
    ];

    $statusSummary=$achievementByOffice[$oc]['by_status'];
    $proc = function(string $s) use ($statusSummary): array {
        return $statusSummary[$s] ?? ['amount'=>0.0,'count'=>0];
    };
    $crm=$proc('crm_to_wqs');
    $wqs=$proc('wqs_processing');
    $ready=$proc('ready_scm');
    $delivery=$proc('on_delivery');

    $revRecon['office_rows'][]=[
        'office_code'=>$oc,
        'office_name'=>$canonicalOffices[$oc]??$oc,
        'achievement'=>$amount,
        'achievement_count'=>$cnt,
        'recognized_internal'=>$recognizedInternal,
        'gap'=>$amount-$recognizedInternal,
        'crm_to_wqs'=>(float)$crm['amount'],
        'crm_to_wqs_count'=>(int)$crm['count'],
        'wqs_processing'=>(float)$wqs['amount'],
        'wqs_processing_count'=>(int)$wqs['count'],
        'ready_scm'=>(float)$ready['amount'],
        'ready_scm_count'=>(int)$ready['count'],
        'on_delivery'=>(float)$delivery['amount'],
        'on_delivery_count'=>(int)$delivery['count'],
    ];
}

$officePerfRows=$rebuiltOfficeRows;
$revRecon['rmi_achievement']=$rmiAchievement;
$revRecon['recognized_internal']=$rmiRecognizedInternal;
$revRecon['recognized_gap']=$rmiAchievement-$rmiRecognizedInternal;
$revRecon['achievement_count']=$totalAchievementCount;

/*
 * Audit nominal per DO kecil untuk membantu menemukan residual yang konsisten
 * antar tanggal. Ini DIAGNOSTIK saja, tidak mengubah nilai.
 */
$revRecon['small_do_audit']=[];
if ($office==='' && es_table_exists($pdo,'sales_do')) {
    foreach ($officeOrderRmi as $oc) {
        // Ambil DO <= Rp500 ribu yang masuk achievement; kandidat residual kecil.
        // Sumber dari rows yang sudah dihitung, jadi tidak query ulang DB.
        $revRecon['small_do_audit'][$oc]=[];
    }
}

/*
 * UNIT ACC ACHIEVEMENT
 * --------------------
 * UNIT ACC mengikuti keluarga DO sales_do.do_code = UNITACC-*; bukan ditarik dari transaksi manual di luar ERP.
 * Office transaksi tetap BGR/BKS/TGR/dll. Jenis Unit ACC dibagi ALKES dan AKSESORIS.
 */
$unitAccAchievement=0.0;
$unitAccAlkes=0.0;
$unitAccAksesoris=0.0;
$unitAccCount=0;
$unitAccByOffice=[];
foreach($officeOrderRmi as $oc) $unitAccByOffice[$oc]=['amount'=>0.0,'alkes'=>0.0,'aksesoris'=>0.0,'count'=>0];

if(es_table_exists($pdo,'sales_do') && es_table_exists($pdo,'sales_do_items')
   && es_col_exists($pdo,'sales_do_items','do_id') && es_col_exists($pdo,'sales_do_items','subtotal')){
    try{
        $uaDateCol=es_first_col($pdo,'sales_do',['do_date']);
        $uaStatusCol=es_first_col($pdo,'sales_do',['status']);
        if($uaDateCol && $uaStatusCol){
            $uaExcluded=['draft','cancelled','canceled','cancel','rejected','reject','void','voided','deleted','inactive'];
            $uaPh=es_in($uaExcluded);
            $uaCustName="NULL";$uaCustType="NULL";$uaCustInternal="NULL";
            if(es_table_exists($pdo,'master_customers')){
                $n=es_first_col($pdo,'master_customers',['customers_name','customer_name','name']);
                $t=es_first_col($pdo,'master_customers',['customer_type','customers_type','type','category','customer_category']);
                $f=es_first_col($pdo,'master_customers',['is_internal','internal_flag','is_intercompany']);
                $match=es_col_exists($pdo,'sales_do','customer_id') && es_col_exists($pdo,'master_customers','id') && es_col_exists($pdo,'master_customers','customers_code')
                    ? "((COALESCE(d.customer_id,0)>0 AND c.id=d.customer_id) OR (COALESCE(d.customer_id,0)<=0 AND c.customers_code=d.customers_code))"
                    : (es_col_exists($pdo,'master_customers','customers_code') ? "c.customers_code=d.customers_code" : "1=0");
                if($n) $uaCustName="(SELECT c.`{$n}` FROM master_customers c WHERE {$match} LIMIT 1)";
                if($t) $uaCustType="(SELECT c.`{$t}` FROM master_customers c WHERE {$match} LIMIT 1)";
                if($f) $uaCustInternal="(SELECT c.`{$f}` FROM master_customers c WHERE {$match} LIMIT 1)";
            }
            $uaDoInternal=es_first_col($pdo,'sales_do',['is_internal_transfer','is_internal','internal_flag','is_intercompany']);
            $uaDoInternalExpr=$uaDoInternal?"d.`{$uaDoInternal}`":"NULL";
            $uaGroupI=es_item_group_norm_sql($pdo,'i');
            $uaGroupIx=es_item_group_norm_sql($pdo,'ix');
            $uaCatI=es_item_category_norm_sql($pdo,'i');

            $uaReturnTotal="0";$uaReturnAlkes="0";$uaReturnAks="0";
            $uaHasReturns=es_table_exists($pdo,'sales_do_returns') && es_table_exists($pdo,'sales_do_return_items')
                && es_col_exists($pdo,'sales_do_returns','commercial_effect');
            if($uaHasReturns){
                $retGroup=es_item_group_norm_sql($pdo,'di2');
                $retCat=es_item_category_norm_sql($pdo,'di2');
                $retBase="COALESCE((SELECT SUM(COALESCE(ri.qty_return,0)*(COALESCE(di2.subtotal,0)/NULLIF(di2.qty,0)))
                    FROM sales_do_returns rr
                    JOIN sales_do_return_items ri ON ri.return_id=rr.id AND ri.do_id=rr.do_id
                    JOIN sales_do_items di2 ON di2.id=ri.do_item_id AND di2.do_id=rr.do_id
                    WHERE rr.do_id=d.id
                      AND LOWER(TRIM(COALESCE(rr.status,'')))='return_scm_completed'
                      AND UPPER(TRIM(COALESCE(rr.commercial_effect,''))) IN ('REVERSAL','REPLACEMENT')
                      AND {CAT_FILTER}
                      AND COALESCE(ri.qty_return,0)>0";
                if(es_col_exists($pdo,'sales_do_returns','scm_completed_at')) $retBase.=" AND rr.scm_completed_at<=?";
                $retBase.="),0)";
                $uaReturnTotal=str_replace('{CAT_FILTER}','1=1',$retBase);
                $uaReturnAlkes=str_replace('{CAT_FILTER}',"{$retCat} IN ('ALKES','ALATKESEHATAN')",$retBase);
                $uaReturnAks=str_replace('{CAT_FILTER}',"{$retCat} IN ('AKSESORIS','AKSESORI','ACCESSORY','ACCESSORIES')",$retBase);
            }

            $uaSql="SELECT d.id,d.do_code,DATE(d.`{$uaDateCol}`) do_date,
                           UPPER(TRIM(COALESCE(d.office_code,''))) office_code,
                           LOWER(TRIM(COALESCE(d.`{$uaStatusCol}`,''))) status_now,
                           d.customers_code,
                           {$uaCustName} customer_name,{$uaCustType} customer_type,
                           {$uaCustInternal} customer_internal_flag,{$uaDoInternalExpr} do_internal_flag,
                           GREATEST(0,COALESCE((SELECT SUM(COALESCE(i.subtotal,0)) FROM sales_do_items i WHERE i.do_id=d.id),0)-{$uaReturnTotal}) unit_acc_total,
                           GREATEST(0,COALESCE((SELECT SUM(COALESCE(i.subtotal,0)) FROM sales_do_items i WHERE i.do_id=d.id AND {$uaCatI} IN ('ALKES','ALATKESEHATAN')),0)-{$uaReturnAlkes}) unit_acc_alkes,
                           GREATEST(0,COALESCE((SELECT SUM(COALESCE(i.subtotal,0)) FROM sales_do_items i WHERE i.do_id=d.id AND {$uaCatI} IN ('AKSESORIS','AKSESORI','ACCESSORY','ACCESSORIES')),0)-{$uaReturnAks}) unit_acc_aksesoris
                    FROM sales_do d
                    WHERE DATE(d.`{$uaDateCol}`) BETWEEN ? AND ?
                      AND LOWER(TRIM(COALESCE(d.`{$uaStatusCol}`,''))) NOT IN {$uaPh}
                      AND UPPER(TRIM(COALESCE(d.do_code,''))) LIKE 'UNITACC-%'";
            if(es_col_exists($pdo,'sales_do','deleted_at')) $uaSql.=" AND d.deleted_at IS NULL";
            $uaParams=[];
            if($uaHasReturns && es_col_exists($pdo,'sales_do_returns','scm_completed_at')){
                // total + ALKES + AKSESORIS return subqueries each carry one cutoff placeholder.
                $uaParams[]=$mtd_end.' 23:59:59';$uaParams[]=$mtd_end.' 23:59:59';$uaParams[]=$mtd_end.' 23:59:59';
            }
            $uaParams[]=$month_start;$uaParams[]=$mtd_end;$uaParams=array_merge($uaParams,$uaExcluded);
            $q=$pdo->prepare($uaSql);$q->execute($uaParams);
            $truthy=['1','Y','YES','TRUE','INTERNAL','INTERCOMPANY'];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ur){
                $total=(float)($ur['unit_acc_total']??0);if($total<=0)continue;
                $alkes=(float)($ur['unit_acc_alkes']??0);$aks=(float)($ur['unit_acc_aksesoris']??0);
                if(($alkes+$aks)<$total-0.01) $aks+=($total-($alkes+$aks));
                $custName=strtoupper(trim((string)($ur['customer_name']??'')));$custType=strtoupper(trim((string)($ur['customer_type']??'')));
                $custCode=strtoupper(trim((string)($ur['customers_code']??'')));$custFlag=strtoupper(trim((string)($ur['customer_internal_flag']??'')));$doFlag=strtoupper(trim((string)($ur['do_internal_flag']??'')));
                $isInternal=(bool)preg_match('/(^|[-_])(INT|INTERNAL)($|[-_])/',$custCode)
                    || str_starts_with($custName,'KANTOR RIZQULLAH MEDISKA INDONESIA') || str_starts_with($custName,'KANTOR DEPO ')
                    || in_array($custType,['INTERNAL','INTERCOMPANY'],true) || in_array($custFlag,$truthy,true) || in_array($doFlag,$truthy,true);
                if($isInternal) continue;
                $dc=strtoupper(trim((string)($ur['do_code']??'')));$oc='';if(preg_match('/^UNITACC-([A-Z0-9]+)-/',$dc,$mm)&&in_array(strtoupper($mm[1]),$officeOrderRmi,true))$oc=strtoupper($mm[1]);if($oc==='')$oc=strtoupper(trim((string)($ur['office_code']??'')));if(!in_array($oc,$officeOrderRmi,true)) continue;
                $unitAccByOffice[$oc]['amount']+=$total;$unitAccByOffice[$oc]['alkes']+=$alkes;$unitAccByOffice[$oc]['aksesoris']+=$aks;$unitAccByOffice[$oc]['count']++;
                $unitAccAlkes+=$alkes;$unitAccAksesoris+=$aks;$unitAccAchievement+=$total;$unitAccCount++;
            }
        }
    }catch(Throwable $e){error_log('[exec_summary][unit-acc] '.$e->getMessage());}
}
$revRecon['unit_acc_achievement']=$unitAccAchievement;
$revRecon['unit_acc_alkes']=$unitAccAlkes;
$revRecon['unit_acc_aksesoris']=$unitAccAksesoris;
$revRecon['unit_acc_count']=$unitAccCount;
$revRecon['unit_acc_by_office']=$unitAccByOffice;
$revRecon['consolidated_achievement']=$rmiAchievement+$unitAccAchievement;

// KPI utama consolidated: RMI + Unit ACC; Unit ACC STRICT berasal dari family DO UNITACC-*.
$achievement_mtd=[
    'net'=>$office==='' ? ($rmiAchievement+$unitAccAchievement) : 0.0,
    'cnt'=>$office==='' ? ($totalAchievementCount+$unitAccCount) : 0,
    'rmi_net'=>$rmiAchievement,
    'rmi_cnt'=>$totalAchievementCount,
    'unit_acc_net'=>$unitAccAchievement,
    'unit_acc_cnt'=>$unitAccCount,
];

if($office!=='' && isset($achievementByOffice[$office])){
    $achievement_mtd['net']=(float)$achievementByOffice[$office]['amount']+(float)($unitAccByOffice[$office]['amount']??0);
    $achievement_mtd['cnt']=(int)$achievementByOffice[$office]['count']+(int)($unitAccByOffice[$office]['count']??0);
}

/*
 * TARGET CONSOLIDATED CANONICAL
 * -----------------------------
 * Achievement dan target wajib memakai scope yang sama:
 * RMI (HERMINA + NON_HERMINA) + ACCUNIT.
 * Target ACCUNIT boleh tersimpan per-office ATAU sebagai target korporat/ALL.
 * Penting: jangan hanya fallback saat target RMI = 0, karena itu membuat target
 * consolidated berhenti di RMI Rp3,575 M dan mengabaikan ACCUNIT korporat.
 */
try{
    $rmiTargetCanonical=0.0;
    $accTargetCanonical=0.0;

    if(is_array($detailData)){
        if($office!==''){
            $rmiTargetCanonical += (float)($detailData['targets']['by_segment_office']['HERMINA'][$office]??0);
            $rmiTargetCanonical += (float)($detailData['targets']['by_segment_office']['NON_HERMINA'][$office]??0);
            $accTargetCanonical += (float)($detailData['targets']['by_segment_office']['ACCUNIT'][$office]??0);
        }else{
            foreach($officeOrderRmi as $oc){
                $rmiTargetCanonical += (float)($detailData['targets']['by_segment_office']['HERMINA'][$oc]??0);
                $rmiTargetCanonical += (float)($detailData['targets']['by_segment_office']['NON_HERMINA'][$oc]??0);
                $accTargetCanonical += (float)($detailData['targets']['by_segment_office']['ACCUNIT'][$oc]??0);
            }
        }
    }

    // Direct DB supplement khusus ACCUNIT. Ini additive, bukan mengganti target RMI yang sudah benar.
    // Mendukung target ACCUNIT yang disimpan sebagai office tertentu maupun corporate/ALL (office_id NULL/0).
    if(es_table_exists($pdo,'kpi_targets')){
        $ktCols=[];
        try{
            $qq=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='kpi_targets'");
            $ktCols=array_map('strtolower',$qq?$qq->fetchAll(PDO::FETCH_COLUMN,0):[]);
        }catch(Throwable $e){}
        $kh=static fn(string $c): bool => in_array(strtolower($c),$ktCols,true);

        if($kh('segment')&&$kh('target_amount')&&$kh('month_no')&&$kh('year_no')){
            $sqlAcc="SELECT COALESCE(SUM(t.target_amount),0) FROM kpi_targets t";
            $pp=[(int)substr($period,5,2),(int)substr($period,0,4)];
            if($office!=='' && $kh('office_id') && es_table_exists($pdo,'master_office')){
                $sqlAcc.=" LEFT JOIN master_office o ON o.id=t.office_id";
            }
            $sqlAcc.=" WHERE t.month_no=? AND t.year_no=? AND REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE(t.segment,''))),' ',''),'-',''),'_','') IN ('ACCUNIT','UNITACC')";
            if($office!=='' && $kh('office_id') && es_table_exists($pdo,'master_office')){
                $sqlAcc.=" AND UPPER(COALESCE(o.office_code,''))=?";
                $pp[]=$office;
            }
            $qAcc=$pdo->prepare($sqlAcc);$qAcc->execute($pp);
            $accTargetDirect=(float)$qAcc->fetchColumn();
            // Hindari double count: pilih representasi ACCUNIT terbesar/terlengkap, bukan dijumlah dua kali.
            if($accTargetDirect>$accTargetCanonical) $accTargetCanonical=$accTargetDirect;
        }
    }

    $targetCanonical=$rmiTargetCanonical+$accTargetCanonical;
    if($targetCanonical>0) $target_value=$targetCanonical;
    $revRecon['target_rmi']=$rmiTargetCanonical;
    $revRecon['target_unit_acc']=$accTargetCanonical;
    $revRecon['target_consolidated']=$target_value;
}catch(Throwable $e){
    error_log('[exec_summary][target-consolidated] '.$e->getMessage());
}

$target_achv=$target_value>0 ? (($achievement_mtd['net']??0)/$target_value)*100 : null;

// Production integrity: KPI harus sama persis dengan penjumlahan office RMI.
$reconUser = function_exists('auth_user') ? auth_user() : [];
$reconRole = strtoupper(trim((string)($reconUser['role'] ?? '')));
$reconLevel = strtoupper(trim((string)($reconUser['level'] ?? '')));
$reconDept = strtoupper(trim((string)($reconUser['department'] ?? '')));
$reconMode = (
    ((string)($_GET['recon'] ?? '') === '1')
    || in_array($reconRole,['SYS','ADMIN','SUPERADMIN','OWNER','DIREKSI','DIRECTOR'],true)
    || in_array($reconLevel,['SYS','OWNER','DIREKSI','DIRECTOR'],true)
    || in_array($reconDept,['SYS','OWNER','DIREKSI','DIRECTOR'],true)
);
$expectedMtdRaw = preg_replace('/[^0-9.\-]/','',(string)($_GET['expected_mtd'] ?? ''));
$expectedMtd = ($expectedMtdRaw !== '') ? (float)$expectedMtdRaw : null;
$reconGap = ($expectedMtd !== null) ? ($expectedMtd - (float)($achievement_mtd['net'] ?? 0)) : null;

$reconExcludedRows = $revRecon['excluded_rows'] ?? [];
usort($reconExcludedRows, static function(array $a,array $b): int {
    $av=(float)($a['excluded_value'] ?? $a['nilai_net_original'] ?? 0);
    $bv=(float)($b['excluded_value'] ?? $b['nilai_net_original'] ?? 0);
    return $bv <=> $av;
});

$reconGapCandidates=[];
if($reconGap !== null && abs($reconGap) >= 0.5){
    $needle=abs($reconGap);
    foreach($reconExcludedRows as $er){
        $vals=[
            'excluded_value'=>(float)($er['excluded_value']??0),
            'original'=>(float)($er['nilai_net_original']??0),
            'return_adjustment'=>(float)($er['return_adjustment']??0),
        ];
        foreach($vals as $kind=>$v){
            if($v>0 && abs($v-$needle)<0.5){
                $er['candidate_match']=$kind;
                $reconGapCandidates[]=$er;
                break;
            }
        }
    }
    foreach(($revRecon['return_adjustment_rows']??[]) as $rr){
        $v=(float)($rr['return_adjustment']??$rr['return_operational_audit']??0);
        if($v>0 && abs($v-$needle)<0.5){
            $rr['candidate_match']='return_adjustment';
            $reconGapCandidates[]=$rr;
        }
    }
}

$officeSumIntegrity=0.0;
foreach($officeOrderRmi as $oc){
    $officeSumIntegrity += (float)($achievementByOffice[$oc]['amount'] ?? 0);
}
$revRecon['integrity']['office_sum']=$officeSumIntegrity;
$revRecon['integrity']['kpi_sum']=(float)($achievement_mtd['rmi_net'] ?? $achievement_mtd['net'] ?? 0);
$revRecon['integrity']['delta']=$revRecon['integrity']['kpi_sum']-$revRecon['integrity']['office_sum'];
$revRecon['integrity']['ok']=abs($revRecon['integrity']['delta']) < 0.5;


/* ============================================================
 * Gross Margin / COGS — verified live path
 * sales_do_items SKU -> purchases_po_items SKU, avg unit_price
 * ============================================================ */
$gm = ['net_sales'=>null,'cogs'=>null,'gm'=>null,'gm_pct'=>null,'coverage_pct'=>null];
$gm_min_coverage=80.0;

if (es_table_exists($pdo,'sales_do_items') && es_table_exists($pdo,'purchases_po_items') && es_table_exists($pdo,'sales_do')) {
    try {
        $required = ['do_id','sku','qty','subtotal'];
        $ok=true; foreach($required as $c) if(!es_col_exists($pdo,'sales_do_items',$c)) $ok=false;
        if ($ok && es_col_exists($pdo,'purchases_po_items','sku') && es_col_exists($pdo,'purchases_po_items','unit_price')) {
            $ph=es_in($revStatuses);
            $sql="SELECT
                    COALESCE(SUM(i.subtotal),0) item_sales,
                    COALESCE(SUM(i.qty * pc.avg_buy_price),0) cogs,
                    COALESCE(SUM(CASE WHEN pc.avg_buy_price>0 THEN i.subtotal ELSE 0 END),0) sales_with_cost
                  FROM sales_do_items i
                  JOIN sales_do d ON d.id=i.do_id
                  LEFT JOIN (
                    SELECT UPPER(TRIM(sku)) sku, AVG(NULLIF(unit_price,0)) avg_buy_price
                    FROM purchases_po_items
                    WHERE NULLIF(TRIM(sku),'') IS NOT NULL
                      AND NULLIF(unit_price,0) IS NOT NULL
                    GROUP BY UPPER(TRIM(sku))
                  ) pc ON pc.sku=UPPER(TRIM(i.sku))
                  WHERE d.do_date BETWEEN ? AND ?
                    AND LOWER(d.status) IN {$ph}";
            $params=array_merge([$month_start,$mtd_end],$revStatuses);
            if($office!=='' && es_col_exists($pdo,'sales_do','office_code')){
                $sql.=" AND UPPER(d.office_code)=?";
                $params[]=$office;
            }
            $st=$pdo->prepare($sql);$st->execute($params);
            $r=$st->fetch(PDO::FETCH_ASSOC)?:[];
            $itemSales=(float)($r['item_sales']??0);
            $cogs=(float)($r['cogs']??0);
            $covered=(float)($r['sales_with_cost']??0);
            $coverage=$itemSales>0?($covered/$itemSales)*100:null;
            $net=(float)$rev_mtd['net'];
            if($net>0){
                $gmNom=$net-$cogs;
                $gm=[
                    'net_sales'=>$net,
                    'cogs'=>$cogs,
                    'gm'=>$gmNom,
                    'gm_pct'=>($gmNom/$net)*100,
                    'coverage_pct'=>$coverage,
                ];
            }
        }
    } catch(Throwable $e){
        error_log('[exec_summary][gm] '.$e->getMessage());
    }
}
$gm_is_reliable=$gm['coverage_pct']!==null && $gm['coverage_pct']>=$gm_min_coverage;

/* ============================================================
 * AR
 * ============================================================ */
$ar=['outstanding'=>null,'overdue'=>null,'macet_cnt'=>null,'top10'=>[]];
if(es_table_exists($pdo,'sales_do')){
    try{
        $ph=es_in($arStatuses);
        $grand=es_col_exists($pdo,'sales_do','grand_total')?'d.grand_total':'0';
        $total=es_col_exists($pdo,'sales_do','total_amount')?'d.total_amount':'0';
        $paid=es_col_exists($pdo,'sales_do','fin_paid_amount')?'d.fin_paid_amount':'0';
        $amt="COALESCE(NULLIF({$grand},0),{$total},0)";
        $out="GREATEST({$amt}-COALESCE({$paid},0),0)";
        $due=es_col_exists($pdo,'sales_do','fin_due_date')
            ? "COALESCE(d.fin_due_date,DATE_ADD(d.do_date,INTERVAL {$daysDue} DAY))"
            : "DATE_ADD(d.do_date,INTERVAL {$daysDue} DAY)";

        $sql="SELECT COALESCE(SUM({$out}),0) FROM sales_do d WHERE LOWER(d.status) IN {$ph} AND d.do_date<=?";
        $params=array_merge($arStatuses,[$mtd_end]);
        if($office!==''){$sql.=" AND UPPER(d.office_code)=?";$params[]=$office;}
        $st=$pdo->prepare($sql);$st->execute($params);$ar['outstanding']=(float)$st->fetchColumn();

        $sql="SELECT COALESCE(SUM({$out}),0) FROM sales_do d WHERE LOWER(d.status) IN {$ph} AND {$out}>0 AND {$due}<? AND d.do_date<=?";
        $params=array_merge($arStatuses,[$mtd_end,$mtd_end]);
        if($office!==''){$sql.=" AND UPPER(d.office_code)=?";$params[]=$office;}
        $st=$pdo->prepare($sql);$st->execute($params);$ar['overdue']=(float)$st->fetchColumn();

        $sql="SELECT COUNT(*) FROM sales_do d WHERE LOWER(d.status) IN {$ph} AND {$out}>0 AND {$due}<=DATE_SUB(?,INTERVAL {$alertArDays} DAY) AND d.do_date<=?";
        $params=array_merge($arStatuses,[$mtd_end,$mtd_end]);
        if($office!==''){$sql.=" AND UPPER(d.office_code)=?";$params[]=$office;}
        $st=$pdo->prepare($sql);$st->execute($params);$ar['macet_cnt']=(int)$st->fetchColumn();
    }catch(Throwable $e){}
}

/* ============================================================
 * Inventory valuation — actual historical purchase-cost proxy
 * Cost source: purchases_po_items.unit_price by SKU.
 * master_products.price is NOT used as COGS.
 * ============================================================ */
$inv=['value'=>null,'total_qty'=>null,'coverage_qty_pct'=>null,'days'=>null,'stock_risk_cnt'=>null];

if (es_table_exists($pdo,'master_products')
    && es_table_exists($pdo,'wqs_stock_by_office')
    && es_table_exists($pdo,'purchases_po_items')
    && es_col_exists($pdo,'wqs_stock_by_office','product_id')
    && es_col_exists($pdo,'wqs_stock_by_office','stock_qty')
    && es_col_exists($pdo,'purchases_po_items','sku')
    && es_col_exists($pdo,'purchases_po_items','unit_price')) {
    try {
        $sql="SELECT
                COALESCE(SUM(s.stock_qty),0) qty,
                COALESCE(SUM(CASE WHEN pc.avg_buy_price>0 THEN s.stock_qty ELSE 0 END),0) qty_costed,
                COALESCE(SUM(CASE WHEN pc.avg_buy_price>0 THEN s.stock_qty*pc.avg_buy_price ELSE 0 END),0) inv_value
              FROM wqs_stock_by_office s
              JOIN master_products mp ON mp.id=s.product_id
              LEFT JOIN (
                SELECT UPPER(TRIM(sku)) sku,
                       AVG(NULLIF(unit_price,0)) avg_buy_price
                FROM purchases_po_items
                WHERE NULLIF(TRIM(sku),'') IS NOT NULL
                  AND NULLIF(unit_price,0) IS NOT NULL
                GROUP BY UPPER(TRIM(sku))
              ) pc ON pc.sku=UPPER(TRIM(mp.sku))
              WHERE 1=1";
        $params=[];
        if($office!==''){
            $sql.=" AND UPPER(s.office_code)=?";
            $params[]=$office;
        }
        $st=$pdo->prepare($sql);
        $st->execute($params);
        $r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
        $q=(float)($r['qty']??0);
        $qc=(float)($r['qty_costed']??0);
        $iv=(float)($r['inv_value']??0);

        $inv['total_qty']=$q;
        $inv['coverage_qty_pct']=$q>0 ? ($qc/$q)*100.0 : null;
        $inv['value']=($q>0 && $qc>0) ? $iv : null;

        // Days of Inventory: current inventory value / last-30-day COGS * 30.
        if($inv['value']!==null && es_table_exists($pdo,'sales_do_items')){
            $date30=date('Y-m-d',strtotime($mtd_end.' -29 day'));
            $ph30=es_in($revStatuses);
            $sql30="SELECT COALESCE(SUM(i.qty*pc.avg_buy_price),0)
                    FROM sales_do_items i
                    JOIN sales_do d ON d.id=i.do_id
                    LEFT JOIN (
                      SELECT UPPER(TRIM(sku)) sku,
                             AVG(NULLIF(unit_price,0)) avg_buy_price
                      FROM purchases_po_items
                      WHERE NULLIF(TRIM(sku),'') IS NOT NULL
                        AND NULLIF(unit_price,0) IS NOT NULL
                      GROUP BY UPPER(TRIM(sku))
                    ) pc ON pc.sku=UPPER(TRIM(i.sku))
                    WHERE d.do_date BETWEEN ? AND ?
                      AND LOWER(d.status) IN {$ph30}";
            $p30=array_merge([$date30,$mtd_end],$revStatuses);
            if($office!==''){
                $sql30.=" AND UPPER(d.office_code)=?";
                $p30[]=$office;
            }
            $st30=$pdo->prepare($sql30);
            $st30->execute($p30);
            $cogs30=(float)($st30->fetchColumn()?:0);
            if($cogs30>0){
                $inv['days']=((float)$inv['value']*30.0)/$cogs30;
            }

            // Stock-cover risk count using 30-day sales velocity.
            $sqlSold="SELECT UPPER(TRIM(i.sku)) sku,COALESCE(SUM(i.qty),0) sold_qty
                      FROM sales_do_items i
                      JOIN sales_do d ON d.id=i.do_id
                      WHERE d.do_date BETWEEN ? AND ?
                        AND LOWER(d.status) IN {$ph30}";
            $pSold=array_merge([$date30,$mtd_end],$revStatuses);
            if($office!==''){
                $sqlSold.=" AND UPPER(d.office_code)=?";
                $pSold[]=$office;
            }
            $sqlSold.=" GROUP BY UPPER(TRIM(i.sku))";
            $stSold=$pdo->prepare($sqlSold);
            $stSold->execute($pSold);
            $soldMap=[];
            while($sr=$stSold->fetch(PDO::FETCH_ASSOC)){
                $sku=(string)($sr['sku']??'');
                if($sku!=='') $soldMap[$sku]=(float)($sr['sold_qty']??0);
            }

            $sqlStock="SELECT UPPER(TRIM(mp.sku)) sku,COALESCE(SUM(s.stock_qty),0) stock_qty
                       FROM wqs_stock_by_office s
                       JOIN master_products mp ON mp.id=s.product_id
                       WHERE 1=1";
            $pStock=[];
            if($office!==''){
                $sqlStock.=" AND UPPER(s.office_code)=?";
                $pStock[]=$office;
            }
            $sqlStock.=" GROUP BY UPPER(TRIM(mp.sku))";
            $stStock=$pdo->prepare($sqlStock);
            $stStock->execute($pStock);
            $risk=0;
            while($sr=$stStock->fetch(PDO::FETCH_ASSOC)){
                $sku=(string)($sr['sku']??'');
                $stock=(float)($sr['stock_qty']??0);
                $sold30=(float)($soldMap[$sku]??0);
                if($sku==='' || $stock<=0 || $sold30<=0) continue;
                $cover=$stock/($sold30/30.0);
                if($cover<$alertStockDays) $risk++;
            }
            $inv['stock_risk_cnt']=$risk;
        }
    } catch(Throwable $e){
        error_log('[exec_summary][inventory] '.$e->getMessage());
    }
}

/* ============================================================
 * PO pipeline + READY late — verified ID relation
 * ============================================================ */
$po=['value'=>null,'count'=>null,'ready_late_cnt'=>null,'pib_cnt'=>null,'gr_cnt'=>null,'ap_cnt'=>null];
if(es_table_exists($pdo,'purchases_po')){
    try{
        $sql="SELECT COALESCE(SUM(total_amount),0) v,COUNT(*) c
              FROM purchases_po
              WHERE deleted_at IS NULL
                AND status IN ('OPEN','IN_PRODUCTION','READY')
                AND po_date<=?";
        $p=[$mtd_end];if($office!==''){$sql.=" AND UPPER(office_code)=?";$p[]=$office;}
        $st=$pdo->prepare($sql);$st->execute($p);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];
        $po['value']=(float)($r['v']??0);$po['count']=(int)($r['c']??0);

        if(es_table_exists($pdo,'wqs_incoming')){
            $sql="SELECT COUNT(*)
                  FROM purchases_po p
                  WHERE p.deleted_at IS NULL
                    AND UPPER(TRIM(p.status))='READY'
                    AND p.po_date<=DATE_SUB(?,INTERVAL {$alertPoDays} DAY)
                    AND NOT EXISTS(
                      SELECT 1 FROM wqs_incoming i
                      WHERE i.po_id=p.id AND i.deleted_at IS NULL
                    )";
            $params=[$mtd_end];
            if($office!==''){$sql.=" AND UPPER(p.office_code)=?";$params[]=$office;}
            $st=$pdo->prepare($sql);$st->execute($params);
            $po['ready_late_cnt']=(int)$st->fetchColumn();

            if(es_col_exists($pdo,'wqs_incoming','received_date')){
                $stGr=$pdo->prepare("SELECT COUNT(DISTINCT po_id)
                                     FROM wqs_incoming
                                     WHERE deleted_at IS NULL
                                       AND po_id IS NOT NULL
                                       AND received_date<=?");
                $stGr->execute([$mtd_end]);
                $po['gr_cnt']=(int)$stGr->fetchColumn();
            } else {
                $po['gr_cnt']=(int)$pdo->query("SELECT COUNT(DISTINCT po_id) FROM wqs_incoming WHERE deleted_at IS NULL AND po_id IS NOT NULL")->fetchColumn();
            }
        }
        if(es_table_exists($pdo,'purchases_ceisa_pib')) {
            $pibDate=es_first_col($pdo,'purchases_ceisa_pib',['pib_date','document_date','created_at','updated_at']);
            if($pibDate){
                $stPib=$pdo->prepare("SELECT COUNT(*) FROM purchases_ceisa_pib WHERE DATE(`{$pibDate}`)<=?");
                $stPib->execute([$mtd_end]);
                $po['pib_cnt']=(int)$stPib->fetchColumn();
            } else {
                $po['pib_cnt']=(int)$pdo->query("SELECT COUNT(*) FROM purchases_ceisa_pib")->fetchColumn();
            }
        }
        if(es_table_exists($pdo,'purchases_invoice_ap')) {
            $apDate=es_first_col($pdo,'purchases_invoice_ap',['invoice_date','ap_date','document_date','created_at','updated_at']);
            $where=[];
            $apParams=[];
            if(es_col_exists($pdo,'purchases_invoice_ap','deleted_at')) $where[]="deleted_at IS NULL";
            if($apDate){ $where[]="DATE(`{$apDate}`)<=?"; $apParams[]=$mtd_end; }
            $sqlAp="SELECT COUNT(*) FROM purchases_invoice_ap".($where?' WHERE '.implode(' AND ',$where):'');
            $stAp=$pdo->prepare($sqlAp); $stAp->execute($apParams);
            $po['ap_cnt']=(int)$stAp->fetchColumn();
        }
    }catch(Throwable $e){
        error_log('[exec_summary][po] '.$e->getMessage());
    }
}

/* ============================================================
 * Product expiry
 * ============================================================ */
$reg=['warn_cnt'=>null,'expired_cnt'=>null];
if(es_table_exists($pdo,'master_products') && es_col_exists($pdo,'master_products','exp_date')){
    try{
        $st=$pdo->prepare("SELECT
             SUM(CASE WHEN exp_date IS NOT NULL AND exp_date<? THEN 1 ELSE 0 END) expired_cnt,
             SUM(CASE WHEN exp_date IS NOT NULL AND exp_date>=? AND exp_date<=DATE_ADD(?,INTERVAL {$alertExpDays} DAY) THEN 1 ELSE 0 END) warn_cnt
             FROM master_products WHERE status='active'");
        $st->execute([$mtd_end,$mtd_end,$mtd_end]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];
        $reg['expired_cnt']=(int)($r['expired_cnt']??0);$reg['warn_cnt']=(int)($r['warn_cnt']??0);
    }catch(Throwable $e){}
}

/* ============================================================
 * Payroll, depreciation, OPEX, weekly burn, NP
 * Current period first; if not yet run, use latest available prior period as proxy.
 * ============================================================ */
$payroll=['gross'=>null,'net'=>null,'count'=>null,'source_period'=>null,'is_fallback'=>false];
if(es_table_exists($pdo,'payroll_runs') && es_table_exists($pdo,'payroll_run_items')){
    $payPeriod=$period;
    try{
        $sql="SELECT COALESCE(SUM(i.gross_pay),0) gross,
                     COALESCE(SUM(i.net_pay),0) net,
                     COUNT(DISTINCT i.employee_id) cnt
              FROM payroll_run_items i
              JOIN (
                SELECT MAX(id) id
                FROM payroll_runs
                WHERE period_ym=? AND status IN ('DRAFT','APPROVED','LOCKED')
              ) r ON r.id=i.run_id";
        $st=$pdo->prepare($sql);
        $st->execute([$payPeriod]);
        $r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
        if((float)($r['gross']??0)>0 || (int)($r['cnt']??0)>0){
            $payroll=['gross'=>(float)$r['gross'],'net'=>(float)$r['net'],'count'=>(int)$r['cnt'],'source_period'=>$payPeriod,'is_fallback'=>false];
        }
    }catch(Throwable $e){}

    if($payroll['gross']===null){
        $fallback=es_latest_period($pdo,'payroll_runs','period_ym',$period,['DRAFT','APPROVED','LOCKED']);
        if($fallback && $fallback!==$period){
            try{
                $sql="SELECT COALESCE(SUM(i.gross_pay),0) gross,
                             COALESCE(SUM(i.net_pay),0) net,
                             COUNT(DISTINCT i.employee_id) cnt
                      FROM payroll_run_items i
                      JOIN (
                        SELECT MAX(id) id
                        FROM payroll_runs
                        WHERE period_ym=? AND status IN ('DRAFT','APPROVED','LOCKED')
                      ) r ON r.id=i.run_id";
                $st=$pdo->prepare($sql);
                $st->execute([$fallback]);
                $r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
                if((float)($r['gross']??0)>0){
                    $payroll=['gross'=>(float)$r['gross'],'net'=>(float)$r['net'],'count'=>(int)$r['cnt'],'source_period'=>$fallback,'is_fallback'=>true];
                }
            }catch(Throwable $e){}
        }
    }
}

$dep=['amount'=>null,'assets'=>null,'source_period'=>null,'is_fallback'=>false];
if(es_table_exists($pdo,'fa_dep_runs')){
    try{
        $st=$pdo->prepare("SELECT total_amount,total_assets FROM fa_dep_runs WHERE period_ym=? ORDER BY run_at DESC LIMIT 1");
        $st->execute([$period]);
        $r=$st->fetch(PDO::FETCH_ASSOC);
        if($r){
            $dep=['amount'=>(float)$r['total_amount'],'assets'=>(int)$r['total_assets'],'source_period'=>$period,'is_fallback'=>false];
        }
    }catch(Throwable $e){}

    if($dep['amount']===null){
        $fallback=es_latest_period($pdo,'fa_dep_runs','period_ym',$period);
        if($fallback && $fallback!==$period){
            try{
                $st=$pdo->prepare("SELECT total_amount,total_assets FROM fa_dep_runs WHERE period_ym=? ORDER BY run_at DESC LIMIT 1");
                $st->execute([$fallback]);
                $r=$st->fetch(PDO::FETCH_ASSOC);
                if($r){
                    $dep=['amount'=>(float)$r['total_amount'],'assets'=>(int)$r['total_assets'],'source_period'=>$fallback,'is_fallback'=>true];
                }
            }catch(Throwable $e){}
        }
    }
}

$weekly_burn=null;
$burn_source=null;
$burnRaw=kpi_policy_get($pdo,'KPI_FIN','WEEKLY_BURN',$office!==''?$office:null,null);
if(is_numeric($burnRaw)&&trim((string)$burnRaw)!==''){
    $weekly_burn=(float)$burnRaw;
    $burn_source='KPI_FIN / WEEKLY_BURN';
}elseif($payroll['gross']!==null && $dep['amount']!==null && $opex_mtd!==null){
    $weekly_burn=((float)$payroll['gross']+(float)$dep['amount']+(float)$opex_mtd)/4.345;
    $burn_source='proxy biaya bulanan / 4,345';
}

$np=['np'=>null,'np_pct'=>null];
if($gm_is_reliable && $payroll['gross']!==null && $dep['amount']!==null && $opex_mtd!==null){
    $val=(float)$rev_mtd['net']-(float)$gm['cogs']-(float)$payroll['gross']-(float)$dep['amount']-(float)$opex_mtd;
    $np=['np'=>$val,'np_pct'=>$rev_mtd['net']>0?($val/$rev_mtd['net'])*100:null];
}

/* ============================================================
 * Audit
 * ============================================================ */
$audit=[];
$login=['success'=>0,'denied'=>0,'logout'=>0];
try{
    if(function_exists('master_audit_ensure_table')) master_audit_ensure_table($pdo);
    if(es_table_exists($pdo,'system_audit_logs')){
        $st=$pdo->query("SELECT created_at,module,action,record_code,username,description FROM system_audit_logs WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY created_at DESC LIMIT 50");
        $audit=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($audit as $a){
            $ac=strtoupper((string)($a['action']??''));
            if($ac==='LOGIN_SUCCESS')$login['success']++;
            elseif($ac==='LOGIN_DENIED')$login['denied']++;
            elseif($ac==='LOGOUT')$login['logout']++;
        }
    }
}catch(Throwable $e){}

/* ============================================================
 * UI
 * ============================================================ */
require_once __DIR__ . '/../../_shared/rmi_layout.php';

$extraHead='';
if($refresh>0)$extraHead.='<meta http-equiv="refresh" content="'.$refresh.'">';
$extraHead.='<style>
.es-filter{display:flex;gap:10px;flex-wrap:wrap;align-items:end;padding:12px 16px;background:var(--rmi-card,#182133);border:1px solid var(--rmi-border,rgba(255,255,255,.08));border-radius:12px;margin-bottom:14px}
.es-filter label{font-size:10px;text-transform:uppercase;color:#94a3b8;font-weight:700;display:block;margin-bottom:4px}
.es-meta{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}.es-pill{font-size:11px;padding:4px 9px;border-radius:14px;background:rgba(6,182,212,.08);border:1px solid rgba(6,182,212,.2);color:#67e8f9}
.es-head{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;font-weight:800;margin:19px 0 9px;display:flex;align-items:center;gap:8px}.es-head:after{content:"";height:1px;background:rgba(255,255,255,.08);flex:1}
.es-card{background:var(--rmi-card,#182133);border:1px solid var(--rmi-border,rgba(255,255,255,.08));border-radius:13px;padding:15px;height:100%}
.es-label{font-size:10px;text-transform:uppercase;color:#94a3b8;font-weight:700;letter-spacing:.04em}.es-value{font-size:20px;font-weight:800;color:#67e8f9;margin-top:5px}.es-sub{font-size:11px;color:#94a3b8;margin-top:5px;line-height:1.45}
.es-alert{border-radius:12px;padding:13px 15px;border:1px solid rgba(34,197,94,.22);background:rgba(34,197,94,.07)}.es-alert.red{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08)}.es-alert .n{font-size:24px;font-weight:800}.es-alert .l{font-size:10px;text-transform:uppercase;font-weight:800}.es-alert .s{font-size:11px;opacity:.8}
.es-table{width:100%;border-collapse:collapse;font-size:12px}.es-table th,.es-table td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.06)}.es-table th{color:#94a3b8;text-transform:uppercase;font-size:10px;text-align:left}.text-end{text-align:right}
</style>';

rmi_header('Executive Summary',[
    'active'=>'exec_summary',
    'subtitle'=>'As of '.h($mtd_end).' · Periode '.h($period).($office!==''?' · '.h($office):' · All Office'),
    'breadcrumbs'=>[['label'=>'Dashboard Center','url'=>'../../dashboards/index.php'],'Executive Summary'],
    'extra_head'=>$extraHead,
    'actions'=>[
        ['label'=>rmi_icon('books').' Panduan','url'=>'../../dashboards/owner/panduan.php'],
        ['label'=>rmi_icon('trend').' KPI Center','url'=>'../../kpi/kpi_center.php'],
        ['label'=>rmi_icon('clipboard').' Audit','url'=>'../../master/audit_logs.php'],
    ],
]);
?>
<form class="es-filter" method="get">
  <div><label>Tanggal / As Of</label><input type="date" class="form-control form-control-sm" name="as_of" value="<?=h($mtd_end)?>" max="<?=h($today)?>" style="width:155px"></div>
  <div style="min-width:240px"><label>Office</label><select class="form-select form-select-sm" name="office">
    <option value="">ALL OFFICE</option>
    <?php foreach($offices as $o):$oc=es_up($o['office_code']??'');?>
    <option value="<?=h($oc)?>" <?=$office===$oc?'selected':''?>><?=h($oc)?> — <?=h($o['office_name']??'')?></option>
    <?php endforeach;?>
  </select></div>
  <div><label>Auto Refresh</label><input class="form-control form-control-sm" name="refresh" value="<?=$refresh?>" style="width:90px"></div>
  <button class="btn btn-sm btn-rmi">Tampilkan</button>
</form>

<div class="es-meta">
 <span class="es-pill">As of <?=h($mtd_end)?></span><span class="es-pill">MTD <?=h($month_start)?> → <?=h($mtd_end)?></span>
 <span class="es-pill">Office <?=$office!==''?h($office):'ALL'?></span>
 <span class="es-pill">Updated <?=date('d/m H:i')?></span>
 <span class="es-pill">Due default <?=$daysDue?> hari</span>
</div>

<div class="es-head"><?=rmi_icon('warn')?> Alert & Risiko</div>
<div class="row g-3">
 <?php
 $alerts=[
 ['AR Macet > '.$alertArDays.' hari',$ar['macet_cnt'],$ar['macet_cnt']===0?'Tidak ada DO overdue berat':'DO overdue berat'],
 ['Stock Cover < '.$alertStockDays.' hari',$inv['stock_risk_cnt'],'SKU berisiko stockout'],
 ['Produk / Batch Expired',$reg['expired_cnt'],'Sumber master_products.exp_date'],
 ['PO READY Telat > '.$alertPoDays.' hari',$po['ready_late_cnt'],$po['ready_late_cnt']===0?'Tidak ada PO READY terlambat':'Status READY & belum incoming'],
 ];
 foreach($alerts as $a):$red=($a[1]!==null&&(int)$a[1]>0);
 ?>
 <div class="col-6 col-md-3"><div class="es-alert <?=$red?'red':''?>"><div class="l"><?=h($a[0])?></div><div class="n"><?=$a[1]===null?'—':es_num($a[1])?></div><div class="s"><?=h($a[2])?></div></div></div>
 <?php endforeach;?>
</div>

<div class="es-head"><?=rmi_icon('money')?> Revenue & Profitabilitas</div>
<div class="row g-3">
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">Consolidated Achievement MTD</div><div class="es-value"><?=es_money($achievement_mtd['net'])?></div><div class="es-sub">RMI <?=es_money($achievement_mtd['rmi_net'])?> + Unit ACC <?=es_money($achievement_mtd['unit_acc_net'])?><br><small><?=es_num($achievement_mtd['cnt'])?> transaksi effective · cutoff <?=h($mtd_end)?></small></div></div></div>
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">Revenue YTD (Net)</div><div class="es-value"><?=es_money($rev_ytd['net'])?></div><div class="es-sub"><?=h($ytd_start)?> → <?=h($mtd_end)?></div></div></div>
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">vs Target Bulanan <?=h($period)?></div><div class="es-value"><?=$target_achv===null?'—':es_pct($target_achv)?></div><div class="es-sub">Target <?=es_money($target_value)?><?php if($target_is_fallback):?> · proxy <?=h($target_source_period)?><?php endif;?><br>Gap <?=$target_value>0?es_money(max(0,$target_value-($achievement_mtd['net']??0))):'—'?></div></div></div>
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">Gross Margin (Proxy)</div><div class="es-value"><?=$gm_is_reliable?es_pct($gm['gm_pct']):'Belum valid'?></div><div class="es-sub">GM <?=$gm_is_reliable?es_money($gm['gm']):'—'?><br>Coverage <?=$gm['coverage_pct']===null?'—':es_pct($gm['coverage_pct'])?> (min 80%)<br><small>HPP: purchases_po_items.unit_price / SKU</small></div></div></div>
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">Net Profit (Proxy)</div><div class="es-value"><?=$np['np_pct']===null?'—':es_pct($np['np_pct'])?></div><div class="es-sub">NP <?=$np['np']===null?'—':es_money($np['np'])?><br>OPEX <?=es_money($opex_mtd)?><?=$opex_source?'<br><small>'.h($opex_source).'</small>':''?></div></div></div>
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">Cash Balance</div><div class="es-value"><?=es_money($cash_balance)?></div><div class="es-sub"><?=$cash_balance===null?'Belum ada sumber saldo aktual yang dapat dibaca':h($cash_source??'cash source')?><?=$cash_as_of?'<br><small>as of '.h($cash_as_of).'</small>':''?></div></div></div>
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">Cash Runway</div><div class="es-value"><?php if($cash_balance!==null&&$weekly_burn!==null&&$weekly_burn>0)echo number_format($cash_balance/$weekly_burn,1,',','.').' minggu';else echo '—';?></div><div class="es-sub">Weekly burn <?=es_money($weekly_burn)?><?=$burn_source?' · '.h($burn_source):''?></div></div></div>
</div>

<?php if($reconMode):?>
<div class="es-head"><?=rmi_icon('search')?> Exact Reconciliation Diagnostic V21</div>
<div class="rmi-card p-4" style="border-color:#f59e0b">
  <form method="get" class="row g-2 align-items-end" style="margin-bottom:14px">
    <input type="hidden" name="as_of" value="<?=h($as_of)?>">
    <input type="hidden" name="office" value="<?=h($office)?>">
    <input type="hidden" name="refresh" value="<?=h($refresh)?>">
    <input type="hidden" name="recon" value="1">
    <div class="col-md-4">
      <label class="es-label">Expected MTD / Data Manual</label>
      <input class="form-control" name="expected_mtd"
             value="<?=h($expectedMtdRaw)?>"
             placeholder="Contoh 289419605">
    </div>
    <div class="col-md-2">
      <button class="btn btn-warning w-100">Hitung Gap Exact</button>
    </div>
    <div class="col-md-6 es-sub">
      Panel ini selalu terlihat untuk SYS/Admin. Masukkan total manual agar sistem mencari row/exclusion yang nilainya persis sama dengan gap.
    </div>
  </form>
  <div class="row g-3">
    <div class="col-md-3"><div class="es-label">MTD ERP</div><div class="es-value"><?=es_money($achievement_mtd['net']??0)?></div><div class="es-sub"><?=es_num($achievement_mtd['cnt']??0)?> DO effective.</div></div>
    <div class="col-md-3"><div class="es-label">Expected / Manual</div><div class="es-value"><?=$expectedMtd===null?'—':es_money($expectedMtd)?></div><div class="es-sub">Dari parameter <code>expected_mtd</code>.</div></div>
    <div class="col-md-3"><div class="es-label">Gap Exact</div><div class="es-value"><?=$reconGap===null?'—':es_money($reconGap)?></div><div class="es-sub">Expected − ERP.</div></div>
    <div class="col-md-3"><div class="es-label">Raw sales_do rows</div><div class="es-value"><?=es_money($revRecon['recon_diag']['source_row_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['recon_diag']['source_row_count']??0)?> BMHP header sebelum exclusion/return.</div></div>
  </div>

  <div style="margin-top:14px;padding:10px;border:1px solid #334155;border-radius:9px">
    <b>Komponen yang sedang mengurangi / mengecualikan MTD:</b>
    <div class="row g-2" style="margin-top:4px">
      <div class="col-md-3"><div class="es-label">Retur FINAL</div><div class="es-value"><?=es_money($revRecon['return_adjustment_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['return_adjustment_count']??0)?> DO</div></div>
      <div class="col-md-3"><div class="es-label">Status Excluded</div><div class="es-value"><?=es_money($revRecon['excluded_status_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['excluded_status_count']??0)?> DO</div></div>
      <div class="col-md-3"><div class="es-label">Internal Transfer</div><div class="es-value"><?=es_money($revRecon['internal_transfer_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['internal_transfer_count']??0)?> DO</div></div>
      <div class="col-md-3"><div class="es-label">Office Non-Canonical</div><div class="es-value"><?=es_money($revRecon['recon_diag']['office_excluded_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['recon_diag']['office_excluded_count']??0)?> DO</div></div>
    </div>
  </div>

  <?php if($reconGap!==null && abs($reconGap)>=0.5):?>
  <div style="margin-top:14px;padding:10px;border:1px solid #92400e;border-radius:9px">
    <b>Kandidat yang nilainya PERSIS sama dengan gap <?=es_money(abs($reconGap))?>:</b>
    <?php if(empty($reconGapCandidates)):?>
      <span class="es-sub"> tidak ada satu row tunggal yang cocok. Gap berasal dari kombinasi beberapa row/adjustment.</span>
    <?php else:?>
      <div style="overflow:auto;margin-top:8px"><table class="es-table">
        <thead><tr><th>DO</th><th>ID</th><th>Office</th><th>Status</th><th>Original</th><th>Retur</th><th>Effective/Excluded</th><th>Match</th><th>Reason</th></tr></thead>
        <tbody><?php foreach($reconGapCandidates as $c):?>
          <tr>
            <td><b><?=h($c['do_code']??'')?></b></td>
            <td><?=h($c['id']??'')?></td>
            <td><?=h($c['office_code']??$c['office_code_db']??'')?></td>
            <td><?=h($c['status_asof']??'')?></td>
            <td><?=es_money($c['nilai_net_original']??0)?></td>
            <td><?=es_money($c['return_adjustment']??$c['return_operational_audit']??0)?></td>
            <td><?=es_money($c['excluded_value']??$c['nilai_net_effective']??0)?></td>
            <td><code><?=h($c['candidate_match']??'')?></code></td>
            <td><?=h($c['note']??$c['excluded_reason']??'')?></td>
          </tr>
        <?php endforeach;?></tbody>
      </table></div>
    <?php endif;?>
  </div>
  <?php endif;?>

  <div style="margin-top:14px"><b>Semua DO yang dikeluarkan dari KPI</b></div>
  <div style="overflow:auto;margin-top:6px;max-height:440px"><table class="es-table">
    <thead><tr><th>DO</th><th>ID</th><th>Office</th><th>Tanggal</th><th>Status</th><th>Customer</th><th>Original</th><th>Retur</th><th>Excluded</th><th>Reason</th></tr></thead>
    <tbody><?php foreach($reconExcludedRows as $er):?>
      <tr>
        <td><b><?=h($er['do_code']??'')?></b></td>
        <td><?=h($er['id']??'')?></td>
        <td><?=h($er['office_code']??$er['office_code_db']??'')?></td>
        <td><?=h($er['do_date']??'')?></td>
        <td><?=h($er['status_asof']??'')?></td>
        <td><?=h($er['customers_code']??'')?></td>
        <td><?=es_money($er['nilai_net_original']??0)?></td>
        <td><?=es_money($er['return_adjustment']??0)?></td>
        <td><?=es_money($er['excluded_value']??0)?></td>
        <td><?=h($er['excluded_reason']??$er['note']??'')?></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>

  <div style="margin-top:14px"><b>Semua Retur FINAL yang mengurangi KPI</b></div>
  <div style="overflow:auto;margin-top:6px"><table class="es-table">
    <thead><tr><th>DO</th><th>ID</th><th>Office</th><th>Original</th><th>Return Adjustment</th><th>Effective</th><th>Note</th></tr></thead>
    <tbody><?php foreach(($revRecon['return_adjustment_rows']??[]) as $rr):?>
      <tr>
        <td><b><?=h($rr['do_code']??'')?></b></td>
        <td><?=h($rr['id']??'')?></td>
        <td><?=h($rr['office_code']??$rr['office_code_db']??'')?></td>
        <td><?=es_money($rr['nilai_net_original']??0)?></td>
        <td><b><?=es_money($rr['return_adjustment']??$rr['return_operational_audit']??0)?></b></td>
        <td><?=es_money($rr['nilai_net_effective']??0)?></td>
        <td><?=h($rr['note']??'')?></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
</div>
<?php endif;?>

<?php if($officePerfRows):?>
<div class="es-head"><?=rmi_icon('target')?> Target vs Pencapaian per Office</div>
<div class="rmi-card p-0 overflow-hidden"><div style="overflow:auto"><table class="es-table"><thead><tr><th>Office</th><th class="text-end">Target</th><th class="text-end">Pencapaian</th><th class="text-end">%</th></tr></thead><tbody>
<?php foreach($officePerfRows as $r):?>
<tr><td><b><?=h($r['office_code'])?></b><br><small><?=h($r['office_name'])?></small></td><td class="text-end"><?=es_money($r['target'])?><?php if(!empty($r['target_proxy_period'])):?><br><small>proxy <?=h($r['target_proxy_period'])?></small><?php endif;?></td><td class="text-end"><?=es_money($r['pencapaian'])?></td><td class="text-end"><?=$r['persentase']===null?'—':es_pct($r['persentase'])?></td></tr>
<?php endforeach;?>
</tbody></table></div></div>
<?php endif;?>

<?php if(!empty($revRecon['enabled'])):?>
<div class="es-head"><?=rmi_icon('gear')?> Rekonsiliasi Penjualan Dinamis — As Of <?=h($revRecon['compare_date']??$mtd_end)?></div>
<div class="rmi-card p-4">
  <div class="row g-3">
    <div class="col-md-3"><div class="es-label">RMI Pencapaian Aktual</div><div class="es-value"><?=es_money($revRecon['rmi_achievement'])?></div><div class="es-sub"><?=es_num($revRecon['achievement_count'])?> DO BMHP aktif.</div></div>
    <div class="col-md-3"><div class="es-label">Unit ACC Achievement</div><div class="es-value"><?=es_money($revRecon['unit_acc_achievement']??0)?></div><div class="es-sub">ALKES <?=es_money($revRecon['unit_acc_alkes']??0)?> · AKSESORIS <?=es_money($revRecon['unit_acc_aksesoris']??0)?> · <?=es_num($revRecon['unit_acc_count']??0)?> DO.</div></div>
    <div class="col-md-3"><div class="es-label">Consolidated Achievement</div><div class="es-value"><?=es_money($revRecon['consolidated_achievement']??0)?></div><div class="es-sub">RMI + Unit ACC, tanpa office ACCUNIT.</div></div>
    <div class="col-md-3"><div class="es-label">Canonical Recognized RMI</div><div class="es-value"><?=es_money($revRecon['recognized_internal'])?></div><div class="es-sub">Satu ledger dengan pencapaian RMI; tidak dipengaruhi status workflow.</div></div>
  </div>
  <div class="row g-3" style="margin-top:2px">
    <div class="col-md-4"><div class="es-label">Internal Transfer Dikeluarkan</div><div class="es-value"><?=es_money($revRecon['internal_transfer_total'])?></div><div class="es-sub"><?=es_num($revRecon['internal_transfer_count'])?> DO internal; tidak masuk sales eksternal.</div></div>
    <div class="col-md-4"><div class="es-label">Late-entry setelah As-Of</div><div class="es-value"><?=es_money($revRecon['late_entry_after_asof_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['late_entry_after_asof_count']??0)?> DO dengan do_date valid yang diinput setelah cutoff; tetap dihitung sesuai business date.</div></div>
    <div class="col-md-4"><div class="es-label">Status Tidak Aktif (Current)</div><div class="es-value"><?=es_money($revRecon['excluded_status_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['excluded_status_count']??0)?> DO current draft/cancel/reject/void/inactive dikeluarkan.</div></div>
    <div class="col-md-4"><div class="es-label">Legacy Status Kosong</div><div class="es-value"><?=es_money($revRecon['legacy_blank_status_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['legacy_blank_status_count']??0)?> DO BMHP legacy status kosong tetap dihitung setelah lolos validasi ledger.</div></div>
    <div class="col-md-4"><div class="es-label">RETUR FINAL Pengurang MTD</div><div class="es-value"><?=es_money($revRecon['return_adjustment_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['return_adjustment_count']??0)?> DO return_scm_completed saat ini mengurangi achievement.</div></div>
    <div class="col-md-4"><div class="es-label">Retur Final (Audit Operasional)</div><div class="es-value"><?=es_money($revRecon['return_adjustment_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['return_adjustment_count']??0)?> DO retur final terdeteksi · mengurangi DO asal berdasarkan item FINAL; replacement ter-link mengisi kembali nilai fulfillment<?php if(!empty($revRecon['return_source'])):?> · sumber <?=h($revRecon['return_source'])?><?php if(!empty($returnAdj['source_amount'])):?> · <?=h($returnAdj['source_amount'])?><?php endif;?><?php else:?> · <b>belum menemukan linkage retur final</b><?php endif;?></div></div>
    <div class="col-md-4"><div class="es-label">DO Pengganti Terhubung</div><div class="es-value"><?=es_money($revRecon['replacement_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['replacement_count']??0)?> DO replacement terhubung eksplisit; nilai ini adalah fulfillment NET yang ikut pencapaian satu kali.</div></div>
    <div class="col-md-4"><div class="es-label">DO Revisi Salah Operator</div><div class="es-value"><?=es_money($revRecon['revision_duplicate_total']??0)?></div><div class="es-sub"><?=es_num($revRecon['revision_duplicate_count']??0)?> DO ter-link sebagai turunan revisi; history tetap ada, contribution achievement Rp0.</div></div>
    <div class="col-md-4"><div class="es-label">Customer Belum Terklasifikasi</div><div class="es-value"><?=es_money($revRecon['unclassified_total'])?></div><div class="es-sub"><?=es_num($revRecon['unclassified_count'])?> DO tetap dihitung; review master customer.</div></div>
    <div class="col-md-4"><div class="es-label">Gross − Net DO</div><div class="es-value"><?=es_money($revRecon['gross_net_delta_total'])?></div><div class="es-sub">PPN/komponen header yang tidak masuk net sales.</div></div>
    <div class="col-md-4"><div class="es-label">Integrity KPI vs Office</div><div class="es-value"><?=$revRecon['integrity']['ok']?'OK':'CHECK'?></div><div class="es-sub">Delta <?=es_money($revRecon['integrity']['delta'])?> · KPI harus = Σ office RMI.</div></div>
  </div>

  <div style="margin-top:12px;padding:10px 12px;border:1px solid #334155;border-radius:10px;font-size:12px;line-height:1.5">
    <b>Logika final v48:</b> pencapaian dihitung dari header <code>sales_do</code> BMHP eksternal yang statusnya aktif pada ledger saat ini. Satu DO dihitung satu kali. <b>Internal transfer</b> dikeluarkan berdasarkan kode/nama/tipe customer serta flag/jenis transaksi bila tersedia. Query customer tidak memakai JOIN langsung sehingga satu DO tidak bisa tergandakan. MTD mengikuti <code>do_date</code>. <code>created_at</code> hanya audit late-entry/backdated dan tidak boleh membuang DO valid dari KPI. DO backdate/late-entry yang aktif dan valid tetap dihitung satu kali pada tanggal bisnisnya. Retur hanya mengurangi item DO asal bila status <code>return_scm_completed</code>, item terhubung ke <code>sales_do_items</code> asal, dan <code>commercial_effect=REVERSAL</code> atau <code>REPLACEMENT</code>. Retur <code>OPERATIONAL_ONLY</code> tetap memproses stok tetapi tidak mengurangi penjualan. Untuk <code>REPLACEMENT</code>, nilai retur mengurangi DO asal dan DO pengganti yang ter-link menyumbang hanya NET item pengganti sebagai fulfillment, bukan order customer baru. Model ini aman untuk penggantian sebagian dari retur FULL. Dengan demikian barang retur tidak double-count dan penggantian tidak membuat order komersial baru. DO pengganti hanya dikenali dari linkage eksplisit yang tervalidasi: replacement_for_do_id, replacement_return_id yang menunjuk retur FINAL, atau sales_do_returns.replacement_do_id. Sistem tidak menebak replacement/duplikat dari nominal, customer, SKU, PO, atau tanggal. Customer eksternal tetap dihitung walaupun kode/type master belum terklasifikasi; hanya internal/intercompany yang dikeluarkan. Angka utama tidak dikoreksi dengan angka manual. Achievement memakai net sales item <code>sales_do_items.subtotal</code> per DO; header DO hanya untuk audit gross. Draft/cancel/reject/void juga tidak dihitung. <b>Unit ACC dihitung dari sales_do.do_code=UNITACC-*; jenis ALKES + AKSESORIS dibaca dari item, dan office mengikuti prefix DO.</b>
  </div>

  <div style="overflow:auto;margin-top:16px"><table class="es-table">
    <thead><tr><th>Office</th><th class="text-end">Pencapaian Aktual</th><th class="text-end">Recognized Internal</th><th class="text-end">Gap</th><th class="text-end">CRM→WQS</th><th class="text-end">WQS Processing</th><th class="text-end">Ready SCM</th><th class="text-end">On Delivery</th></tr></thead>
    <tbody><?php foreach($revRecon['office_rows'] as $rr):?>
      <tr>
        <td><b><?=h($rr['office_code'])?></b><br><small><?=h($rr['office_name'])?></small></td>
        <td class="text-end"><b><?=es_money($rr['achievement'])?></b></td>
        <td class="text-end"><?=es_money($rr['recognized_internal'])?></td>
        <td class="text-end"><?=es_money($rr['gap'])?></td>
        <td class="text-end"><?=es_num($rr['crm_to_wqs_count'])?> DO<br><small><?=es_money($rr['crm_to_wqs'])?></small></td>
        <td class="text-end"><?=es_num($rr['wqs_processing_count'])?> DO<br><small><?=es_money($rr['wqs_processing'])?></small></td>
        <td class="text-end"><?=es_num($rr['ready_scm_count'])?> DO<br><small><?=es_money($rr['ready_scm'])?></small></td>
        <td class="text-end"><?=es_num($rr['on_delivery_count'])?> DO<br><small><?=es_money($rr['on_delivery'])?></small></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>

  <?php if(!empty($revRecon['return_diagnostic']['ignored_header_only'])):?>
  <div class="es-sub" style="margin-top:8px">
    Return header diabaikan (tidak punya bukti item-level yang aman):
    <b><?=h(implode(', ', $revRecon['return_diagnostic']['ignored_header_only']))?></b>.
    Ini sengaja agar partial retur tidak mengurangi seluruh nilai DO.
  </div>
<?php endif;?>



<?php if(!empty($revRecon['revision_duplicate_rows'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('gear')?> Koreksi Kesalahan Operator — Turunan Revisi Dikeluarkan dari Achievement</div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Office</th><th>Tanggal</th><th>DO Salah/Turunan</th><th>Parent DO ID</th><th>Customer</th><th class="text-end">Nilai Dikeluarkan</th><th>Keterangan</th></tr></thead>
    <tbody><?php foreach($revRecon['revision_duplicate_rows'] as $rd):?>
      <tr>
        <td><b><?=h($rd['office_code']??$rd['office_code_db']??'')?></b></td>
        <td><?=h($rd['do_date']??'')?></td>
        <td><?=h($rd['do_code']??'')?></td>
        <td><?=h($rd['revision_of_do_id']??'')?></td>
        <td><?=h($rd['customers_code']??'')?></td>
        <td class="text-end"><b><?=es_money($rd['nilai_net_original']??0)?></b></td>
        <td><?=h($rd['note']??'DO turunan revisi; bukan sales baru')?></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
<?php endif;?>

<?php if(!empty($revRecon['legacy_blank_status_rows'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('receipt')?> Legacy Status Kosong — Tetap Dihitung</div>
  <div class="es-sub" style="margin-bottom:8px">
    Status kosong pada data lama bukan status terminal. Baris di bawah tetap masuk achievement
    karena DO BMHP valid, nilai positif, office canonical, dan bukan internal/intercompany.
  </div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>ID</th><th>DO</th><th>Office</th><th>Tanggal</th><th>Customer</th><th class="text-end">Nilai Efektif</th></tr></thead>
    <tbody><?php foreach($revRecon['legacy_blank_status_rows'] as $lr):?>
      <tr>
        <td><?=h($lr['id']??'')?></td>
        <td><b><?=h($lr['do_code']??'')?></b></td>
        <td><?=h($lr['office_code']??'')?></td>
        <td><?=h($lr['do_date']??'')?></td>
        <td><?=h($lr['customers_code']??'')?></td>
        <td class="text-end"><b><?=es_money($lr['nilai_net_effective']??0)?></b></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
<?php endif;?>

<?php if(!empty($returnAdj['unclassified_current_rows'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('warn')?> DATA QUALITY BLOCKER — Retur FINAL MTD Belum Diklasifikasi</div>
  <div class="es-sub" style="margin-bottom:8px">
    Retur BMHP periode aktif berikut sudah selesai SCM tetapi belum memiliki <code>commercial_effect</code>.
    Baris ini tidak mengurangi KPI sampai diklasifikasi. Return baru tidak boleh masuk kondisi ini karena finalisasi SCM mewajibkan klasifikasi.
  </div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Return ID</th><th>DO</th><th>DO Date</th><th>Selesai SCM</th><th>Nilai Retur</th><th>Status</th></tr></thead>
    <tbody><?php foreach($returnAdj['unclassified_current_rows'] as $ur):?>
      <tr>
        <td><?=h($ur['return_id']??'')?></td>
        <td><b><?=h($ur['original_do_code']??'')?></b></td>
        <td><?=h($ur['do_date']??'')?></td>
        <td><?=h($ur['scm_completed_at']??'')?></td>
        <td><?=es_money($ur['return_net']??0)?></td>
        <td><b>NEEDS_REVIEW</b></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
<?php endif;?>
<?php if(!empty($returnAdj['unclassified_historical_rows'])):?>
  <div class="es-sub" style="margin-top:8px">
    Audit historis: <?=es_num(count($returnAdj['unclassified_historical_rows']))?> retur BMHP lama masih NEEDS_REVIEW,
    tetapi berada di luar MTD yang sedang ditampilkan dan tidak mengubah angka hari ini.
  </div>
<?php endif;?>

<?php if(!empty($revRecon['return_adjustment_rows'])):?>
  <div class="es-head" style="margin-top:18px">↩️ Retur Final — Pengurang DO Asal</div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Office</th><th>Tanggal DO</th><th>DO Asal</th><th>Customer</th><th class="text-end">Net Asal</th><th class="text-end">Retur Final</th><th class="text-end">Net Efektif</th><th>Keterangan</th></tr></thead>
    <tbody><?php foreach($revRecon['return_adjustment_rows'] as $ra):?>
      <tr>
        <td><b><?=h($ra['office_code']??'')?></b></td>
        <td><?=h($ra['do_date']??'')?></td>
        <td><?=h($ra['do_code']??'')?></td>
        <td><?=h($ra['customers_code']??'')?></td>
        <td class="text-end"><?=es_money($ra['nilai_net_original']??0)?></td>
        <td class="text-end">-<?=es_money($ra['return_adjustment']??0)?></td>
        <td class="text-end"><b><?=es_money($ra['nilai_net_effective']??0)?></b></td>
        <td><?=h($ra['note']??'original - retur + replacement')?></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
  <?php endif;?>

  <?php if(!empty($revRecon['legacy_linkage_rows'])):?>
  <details style="margin-top:12px">
    <summary class="es-sub" style="cursor:pointer">Audit metadata lineage legacy (tidak memengaruhi KPI): <?=es_num(count($revRecon['legacy_linkage_rows']))?> row</summary>
    <div style="overflow:auto;margin-top:8px"><table class="es-table">
      <thead><tr><th>DO</th><th>Customer</th><th>replacement_for</th><th>replacement_return</th><th>revision_of</th><th>Nilai</th></tr></thead>
      <tbody><?php foreach($revRecon['legacy_linkage_rows'] as $lr):?>
        <tr>
          <td><?=h($lr['do_code']??'')?></td>
          <td><?=h($lr['customers_code']??'')?></td>
          <td><?=h($lr['replacement_for_do_id_audit']??'')?></td>
          <td><?=h($lr['replacement_return_id_audit']??'')?></td>
          <td><?=h($lr['revision_of_do_id_audit']??'')?></td>
          <td><?=es_money($lr['nilai_net_effective']??0)?></td>
        </tr>
      <?php endforeach;?></tbody>
    </table></div>
  </details>
<?php endif;?>

<?php if(!empty($revRecon['replacement_rows'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('refresh')?> DO Pengganti Terhubung — Fulfillment Item Retur</div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Office</th><th>Tanggal DO</th><th>DO Pengganti</th><th>Customer</th><th>Status</th><th class="text-end">Fulfillment</th><th class="text-end">Child Net (Audit)</th><th>Keterangan</th></tr></thead>
    <tbody><?php foreach($revRecon['replacement_rows'] as $rp):?>
      <tr>
        <td><b><?=h($rp['office_code']??'')?></b></td>
        <td><?=h($rp['do_date']??'')?></td>
        <td><?=h($rp['do_code']??'')?></td>
        <td><?=h($rp['customers_code']??'')?></td>
        <td><code><?=h($rp['status_asof']??'')?></code></td>
        <td class="text-end"><b><?=es_money($rp['nilai_net_effective']??0)?></b></td>
        <td class="text-end"><?=es_money($rp['replacement_child_net_audit']??$rp['nilai_net']??0)?></td>
        <td><?=h($rp['note']??'Replacement linked; fulfillment item retur FINAL')?></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
  <?php endif;?>

  <?php if($revRecon['process_rows']):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('box')?> DO Aktif yang Masih Berproses — Diagnostik</div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Office</th><th>Tanggal</th><th>DO</th><th>Customer</th><th>Status As-Of</th><th class="text-end">Nilai DO</th><th>Keterangan</th></tr></thead>
    <tbody><?php foreach($revRecon['process_rows'] as $pr):?>
      <tr><td><b><?=h($pr['office_code'])?></b></td><td><?=h($pr['do_date'])?></td><td><?=h($pr['do_code'])?></td><td><?=h($pr['customers_code'])?></td><td><code><?=h($pr['status_asof']??'')?></code></td><td class="text-end"><?=es_money($pr['nilai_net'])?></td><td><?=h($pr['note']??'Diagnostik workflow')?></td></tr>
    <?php endforeach;?></tbody>
  </table></div>
  <?php endif;?>



  <?php if(!empty($revRecon['gross_net_delta_rows'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('receipt')?> Audit Gross vs Net DO — Komponen yang Tidak Masuk Pencapaian</div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Office</th><th>Tanggal</th><th>DO</th><th>Customer</th><th>Status</th><th class="text-end">Header Gross</th><th class="text-end">Net Sales</th><th class="text-end">Selisih</th></tr></thead>
    <tbody><?php foreach($revRecon['gross_net_delta_rows'] as $gr):?>
      <tr>
        <td><b><?=h($gr['office_code']??'')?></b></td>
        <td><?=h($gr['do_date']??'')?></td>
        <td><?=h($gr['do_code']??'')?></td>
        <td><?=h($gr['customers_code']??'')?></td>
        <td><code><?=h($gr['status_asof']??'')?></code></td>
        <td class="text-end"><?=es_money($gr['nilai_gross']??0)?></td>
        <td class="text-end"><b><?=es_money($gr['nilai_net']??0)?></b></td>
        <td class="text-end"><?=es_money($gr['gross_net_delta']??0)?></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
  <?php endif;?>

  <?php if(!empty($revRecon['daily_office_totals'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('money')?> Audit Harian per Office — Sales DO Aktual</div>
  <div style="overflow:auto"><table class="es-table">
    <thead>
      <tr>
        <th>Tanggal</th>
        <?php foreach($officeOrderRmi as $oc):?><th class="text-end"><?=h($oc)?></th><?php endforeach;?>
        <th class="text-end">TOTAL HARI</th>
      </tr>
    </thead>
    <tbody>
      <?php ksort($revRecon['daily_office_totals']); foreach($revRecon['daily_office_totals'] as $d=>$offMap): $rowTotal=0.0; ?>
      <tr>
        <td><b><?=h($d)?></b></td>
        <?php foreach($officeOrderRmi as $oc): $v=(float)($offMap[$oc]['amount']??0); $rowTotal+=$v; ?>
          <td class="text-end"><?=es_money($v)?></td>
        <?php endforeach;?>
        <td class="text-end"><b><?=es_money($rowTotal)?></b></td>
      </tr>
      <?php endforeach;?>
    </tbody>
  </table></div>
  <div class="es-sub" style="margin-top:6px">Sumber murni sales_do eksternal aktif. Internal/intercompany tidak masuk. Tabel ini membantu membandingkan rekap manual per tanggal tanpa mengubah angka ERP.</div>
  <?php endif;?>

  <?php if(!empty($revRecon['daily_totals'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('calendar')?> Mutasi Pencapaian Harian dari sales_do</div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Tanggal DO</th><th class="text-end">Jumlah DO</th><th class="text-end">Nilai Hari Itu</th><th class="text-end">MTD Kumulatif</th></tr></thead>
    <tbody>
    <?php $cum=0.0; ksort($revRecon['daily_totals']); foreach($revRecon['daily_totals'] as $d=>$dt): $cum+=(float)$dt['amount']; ?>
      <tr>
        <td><b><?=h($d)?></b></td>
        <td class="text-end"><?=es_num($dt['count'])?></td>
        <td class="text-end"><?=es_money($dt['amount'])?></td>
        <td class="text-end"><b><?=es_money($cum)?></b></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
  <?php endif;?>

  <?php if(!empty($revRecon['small_included_rows'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('search')?> Audit DO Kecil yang IKUT Pencapaian (≤ Rp500.000)</div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Office</th><th>Tanggal DO</th><th>Created At / Effective</th><th>DO</th><th>Customer</th><th>Nama</th><th>Status As-Of</th><th class="text-end">Nilai</th></tr></thead>
    <tbody><?php foreach($revRecon['small_included_rows'] as $sr):?>
      <tr>
        <td><b><?=h($sr['office_code']??'')?></b></td>
        <td><?=h($sr['do_date']??'')?></td>
        <td><?=h($sr['created_at']??'')?><?php if(!empty($sr['achievement_date'])):?><br><small>Eff: <?=h($sr['achievement_date'])?></small><?php endif;?></td>
        <td><?=h($sr['do_code']??'')?></td>
        <td><?=h($sr['customers_code']??'')?></td>
        <td><?=h($sr['customer_name']??'')?></td>
        <td><code><?=h($sr['status_asof']??'')?></code></td>
        <td class="text-end"><b><?=es_money($sr['nilai_net']??0)?></b></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
  <div class="es-sub" style="margin-top:6px">Tabel ini hanya audit. Jika selisih manual tetap konstan dari satu tanggal ke tanggal berikutnya, cari satu DO pada tanggal awal selisih dengan nominal yang sama.</div>
  <?php endif;?>

  <?php if(!empty($revRecon['unclassified_rows'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('search')?> Customer Belum Terklasifikasi — Tetap Dihitung</div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Office</th><th>Tanggal</th><th>DO</th><th>Customer</th><th>Nama Customer</th><th>Status</th><th class="text-end">Nilai</th></tr></thead>
    <tbody><?php foreach($revRecon['unclassified_rows'] as $ur):?>
      <tr>
        <td><b><?=h($ur['office_code']??'')?></b></td>
        <td><?=h($ur['do_date']??'')?></td>
        <td><?=h($ur['do_code']??'')?></td>
        <td><?=h($ur['customers_code']??'')?></td>
        <td><?=h($ur['customer_name']??'')?></td>
        <td><code><?=h($ur['status_asof']??'')?></code></td>
        <td class="text-end"><?=es_money($ur['nilai_net']??0)?></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
  <?php endif;?>

  <?php if(!empty($revRecon['late_backdated_rows'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('calendar')?> Backdated DO Aktif — Tetap Dihitung 1x</div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Office</th><th>DO Date</th><th>Created At</th><th>DO</th><th>Customer</th><th>Status</th><th class="text-end">Nilai</th><th>Keterangan</th></tr></thead>
    <tbody><?php foreach($revRecon['late_backdated_rows'] as $fr):?>
      <tr>
        <td><b><?=h($fr['office_code']??'')?></b></td>
        <td><?=h($fr['do_date']??'')?></td>
        <td><?=h($fr['created_at']??'')?></td>
        <td><?=h($fr['do_code']??'')?></td>
        <td><?=h($fr['customers_code']??'')?></td>
        <td><code><?=h($fr['status_asof']??'')?></code></td>
        <td class="text-end"><b><?=es_money($fr['nilai_net']??0)?></b></td>
        <td><?=h($fr['note']??'')?></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
  <?php endif;?>

  <?php if(!empty($revRecon['internal_transfer_rows'])):?>
  <div class="es-head" style="margin-top:18px"><?=rmi_icon('refresh')?> Internal Transfer — Dikeluarkan dari Pencapaian</div>
  <div style="overflow:auto"><table class="es-table">
    <thead><tr><th>Office</th><th>Tanggal</th><th>DO</th><th>Customer</th><th>Nama Customer</th><th>Status</th><th class="text-end">Nilai</th></tr></thead>
    <tbody><?php foreach($revRecon['internal_transfer_rows'] as $ir):?>
      <tr>
        <td><b><?=h($ir['office_code']??'')?></b></td>
        <td><?=h($ir['do_date']??'')?></td>
        <td><?=h($ir['do_code']??'')?></td>
        <td><?=h($ir['customers_code']??'')?></td>
        <td><?=h($ir['customer_name']??'')?></td>
        <td><code><?=h($ir['status_asof']??'')?></code></td>
        <td class="text-end"><?=es_money($ir['nilai_net']??0)?></td>
      </tr>
    <?php endforeach;?></tbody>
  </table></div>
  <?php endif;?>

  <div style="margin-top:14px;padding:12px;border:1px solid #334155;border-radius:10px;font-size:12px;line-height:1.55">
    <b>Sumber:</b> <?=h($revRecon['source'])?>. Tidak ada hard-code angka tanggal 02/03/04. Periode MTD mengikuti do_date; created_at dan audit status hanya diagnostik, bukan filter KPI. Backdated DO aktif tetap dihitung 1x dan tidak didedup berdasarkan kemiripan item. Retur final mengurangi item DO asal melalui linkage eksplisit. DO pengganti terhubung menggantikan item retur FINAL. Jika linkage child legacy hilang tetapi replacement_created_at tercatat, dashboard merekonstruksi fulfillment dari event return tersebut tanpa hard-code. Dashboard menampilkan audit gross-vs-net dan DO kecil untuk penelusuran transaksi tanpa hard-code koreksi.
  </div>
</div>
<?php endif;?>


<div class="es-head"><?=rmi_icon('office')?> AR & Inventory</div>
<div class="row g-3">
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">AR Outstanding</div><div class="es-value"><?=es_money($ar['outstanding'])?></div><div class="es-sub">Status <?=h(implode(', ',$arStatuses))?> · posisi s.d. <?=h($mtd_end)?></div></div></div>
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">AR Overdue</div><div class="es-value"><?=es_money($ar['overdue'])?></div><div class="es-sub">Due proxy +<?=$daysDue?> hari · aging per <?=h($mtd_end)?></div></div></div>
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">Inventory Value</div><div class="es-value"><?=es_money($inv['value'])?></div><div class="es-sub">Qty <?=es_num($inv['total_qty'])?><br>Coverage HPP <?=$inv['coverage_qty_pct']===null?'—':es_pct($inv['coverage_qty_pct'])?><br><small>Snapshot stok saat ini · valuasi cost historis PO/SKU<?= $isHistoricalAsOf?' · bukan rekonstruksi stok historis':''?></small></div></div></div>
 <div class="col-6 col-md-3"><div class="es-card"><div class="es-label">Days of Inventory</div><div class="es-value"><?=$inv['days']===null?'—':es_num($inv['days']).' hari'?></div><div class="es-sub">Proxy inventory / COGS 30d</div></div></div>
</div>

<div class="es-head"><?=rmi_icon('box')?> Import & PO Pipeline</div>
<div class="rmi-card p-4">
 <div class="row g-3"><div class="col-md-4"><div class="es-label">PO Pipeline Value</div><div class="es-value"><?=es_money($po['value'])?></div><div class="es-sub"><?=es_num($po['count'])?> PO aktif · dibuat s.d. <?=h($mtd_end)?></div></div>
 <div class="col-md-8"><table class="es-table"><thead><tr><th>PO</th><th>PIB</th><th>GR</th><th>AP</th></tr></thead><tbody><tr><td><?=es_num($po['count'])?></td><td><?=es_num($po['pib_cnt'])?></td><td><?=es_num($po['gr_cnt'])?></td><td><?=es_num($po['ap_cnt'])?></td></tr></tbody></table></div></div>
</div>

<div class="es-head"><?=rmi_icon('chart')?> P&L Cross-Module</div>
<div class="rmi-card p-4"><div class="row g-3">
 <div class="col-md-3"><div class="es-label">Revenue</div><div class="es-value"><?=es_money($rev_mtd['net'])?></div></div>
 <div class="col-md-3"><div class="es-label">COGS Proxy</div><div class="es-value"><?=$gm_is_reliable?es_money($gm['cogs']):'Belum valid'?></div><div class="es-sub">PO historical unit_price · coverage <?=$gm['coverage_pct']===null?'—':es_pct($gm['coverage_pct'])?></div></div>
 <div class="col-md-3"><div class="es-label">Payroll Gross</div><div class="es-value"><?=es_money($payroll['gross'])?></div><div class="es-sub"><?=es_num($payroll['count'])?> karyawan<?=!empty($payroll['is_fallback'])?' · proxy '.h($payroll['source_period']):''?></div></div>
 <div class="col-md-3"><div class="es-label">Depresiasi</div><div class="es-value"><?=es_money($dep['amount'])?></div><div class="es-sub"><?=es_num($dep['assets'])?> aset<?=!empty($dep['is_fallback'])?' · proxy '.h($dep['source_period']):''?></div></div>
</div></div>


<div class="es-head"><?=rmi_icon('search')?> Status Sumber Data</div>
<div class="rmi-card p-4">
  <div class="row g-3" style="font-size:12px">
    <div class="col-md-3"><b>Revenue</b><br><?=is_array($detailData)?'Finance Dashboard Detail · cutoff '.h($mtd_end).' · status tidak dipaksa':'sales_do fallback · cutoff '.h($mtd_end)?></div>
    <div class="col-md-3"><b>COGS / GM</b><br><?=$gm['coverage_pct']!==null?'PO historical cost · coverage '.es_pct($gm['coverage_pct']).' · cutoff '.h($mtd_end):'Belum ada coverage HPP'?></div>
    <div class="col-md-3"><b>Inventory</b><br><?=$inv['coverage_qty_pct']!==null?'PO historical cost · coverage '.es_pct($inv['coverage_qty_pct']):'Belum ada cost coverage stok'?></div>
    <div class="col-md-3"><b>Cash</b><br><?=$cash_balance!==null?h($cash_source??'saldo aktual').($cash_as_of?' · as of '.h($cash_as_of):''):'Belum ditemukan saldo aktual langsung'?><br><b>OPEX</b>: <?=$opex_mtd!==null?es_money($opex_mtd).' · '.h($opex_source??'source'):'belum tersedia'?></div>
  </div>
</div>

<div class="es-head"><?=rmi_icon('clipboard')?> Audit Log — 24 Jam</div>
<div style="display:flex;gap:8px;margin-bottom:8px"><span class="es-pill">Login berhasil <?=$login['success']?></span><span class="es-pill">Ditolak <?=$login['denied']?></span><span class="es-pill">Logout <?=$login['logout']?></span></div>
<div class="rmi-card p-0 overflow-hidden">
<?php if(!$audit):?><div style="padding:18px;color:#94a3b8">Belum ada aktivitas 24 jam terakhir.</div>
<?php else:?><div style="overflow:auto"><table class="es-table"><thead><tr><th>Waktu</th><th>Modul</th><th>Aksi</th><th>Kode</th><th>User</th><th>Keterangan</th></tr></thead><tbody>
<?php foreach($audit as $a):?><tr><td><?=rmi_h($a['created_at']??'')?></td><td><?=rmi_h($a['module']??'')?></td><td><?=rmi_h($a['action']??'')?></td><td><?=rmi_h($a['record_code']??'')?></td><td><?=rmi_h($a['username']??'')?></td><td><?=rmi_h($a['description']??'')?></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?>
</div>
<?php rmi_footer(); ?>
