<?php
/** Shared read-only funnel data service. */
declare(strict_types=1);

if (!function_exists('rmi_funnel_table_exists')) {
    function rmi_funnel_table_exists(PDO $pdo, string $table): bool {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }
}
if (!function_exists('rmi_funnel_column_exists')) {
    function rmi_funnel_column_exists(PDO $pdo, string $table, string $column): bool {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
        $st->execute([$table, $column]);
        return (bool)$st->fetchColumn();
    }
}
if (!function_exists('rmi_funnel_first_column')) {
    function rmi_funnel_first_column(PDO $pdo, string $table, array $candidates): ?string {
        foreach ($candidates as $column) if (rmi_funnel_column_exists($pdo, $table, $column)) return $column;
        return null;
    }
}
if (!function_exists('rmi_funnel_ident')) {
    function rmi_funnel_ident(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
}
if (!function_exists('rmi_funnel_valid_date')) {
    function rmi_funnel_valid_date(?string $value, string $fallback): string {
        $value = trim((string)$value);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return ($dt && $dt->format('Y-m-d') === $value) ? $value : $fallback;
    }
}
if (!function_exists('rmi_funnel_do_stage')) {
    function rmi_funnel_do_stage(string $raw): string {
        $s = strtolower(trim($raw));
        $map = [
            'new'=>'CRM','draft'=>'CRM','crm'=>'CRM','crm_to_wqs'=>'CRM','submitted'=>'CRM','approved'=>'CRM',
            'wqs'=>'WQS','wqs_processing'=>'WQS','allocation'=>'WQS','allocated'=>'WQS','picking'=>'WQS','picked'=>'WQS','ready_scm'=>'WQS','ready_for_scm'=>'WQS',
            'scm'=>'SCM','scm_processing'=>'SCM','on_delivery'=>'SCM','in_delivery'=>'SCM','delivered'=>'SCM','received_customer'=>'SCM',
            'act'=>'ACT','act_processing'=>'ACT','ready_act'=>'ACT','invoiced'=>'ACT','invoice_created'=>'ACT','ready_fin'=>'ACT',
            'fin'=>'FIN','fin_processing'=>'FIN','wait_payment'=>'FIN','waiting_payment'=>'FIN','partial_paid'=>'FIN','paid'=>'FIN','closed'=>'FIN',
            'cancelled'=>'CANCELLED','canceled'=>'CANCELLED','rejected'=>'CANCELLED','void'=>'CANCELLED',
        ];
        return $map[$s] ?? 'OTHER';
    }
}
if (!function_exists('rmi_funnel_read')) {
    function rmi_funnel_read(PDO $pdo, string $dateFrom, string $dateTo): array {
        $out = [
            'date_from'=>$dateFrom,'date_to'=>$dateTo,'warnings'=>[],
            'crm_leads'=>['stages'=>['DRAFT'=>0,'SUBMITTED'=>0,'APPROVED'=>0,'CLOSED'=>0,'CANCELLED'=>0,'OTHER'=>0],'total'=>0,'closed_pct'=>0.0,'progressed_pct'=>0.0,'source_ready'=>false],
            'sales_do'=>['stages'=>['CRM'=>0,'WQS'=>0,'SCM'=>0,'ACT'=>0,'FIN'=>0,'CANCELLED'=>0,'OTHER'=>0],'total'=>0,'paid'=>0,'paid_pct'=>0.0,'source_ready'=>false,'stage_column'=>null,'date_column'=>null],
            'reg_alkes'=>['stages'=>array_fill(1,15,0),'total'=>0,'avg_days'=>[],'active_age_days'=>[],'source_ready'=>false],
            'import'=>['stages'=>['PO'=>0,'PIB'=>0,'GR'=>0,'AP'=>0],'value'=>null,'source_ready'=>false,'period_applied'=>['PO'=>false,'PIB'=>false,'GR'=>false,'AP'=>false]],
        ];

        // CRM
        try {
            if (rmi_funnel_table_exists($pdo,'crm_leads')) {
                $out['crm_leads']['source_ready']=true;
                $sc=rmi_funnel_first_column($pdo,'crm_leads',['status','lead_status']);
                $dc=rmi_funnel_first_column($pdo,'crm_leads',['created_at','lead_date','date_created']);
                if (!$sc) $out['warnings'][]='CRM Leads: kolom status tidak ditemukan.';
                else {
                    $sql='SELECT '.rmi_funnel_ident($sc).' st,COUNT(*) c FROM `crm_leads`'; $p=[];
                    if ($dc) { $sql.=' WHERE DATE('.rmi_funnel_ident($dc).') BETWEEN ? AND ?'; $p=[$dateFrom,$dateTo]; }
                    else $out['warnings'][]='CRM Leads: filter periode tidak diterapkan karena kolom tanggal tidak ditemukan.';
                    $sql.=' GROUP BY '.rmi_funnel_ident($sc);
                    $st=$pdo->prepare($sql); $st->execute($p);
                    while($r=$st->fetch(PDO::FETCH_ASSOC)){
                        $s=strtoupper(trim((string)$r['st'])); $c=(int)$r['c'];
                        $b=array_key_exists($s,$out['crm_leads']['stages'])?$s:'OTHER';
                        $out['crm_leads']['stages'][$b]+=$c; $out['crm_leads']['total']+=$c;
                    }
                    $t=$out['crm_leads']['total'];
                    $out['crm_leads']['closed_pct']=$t?round($out['crm_leads']['stages']['CLOSED']/$t*100,1):0.0;
                    $prog=$out['crm_leads']['stages']['SUBMITTED']+$out['crm_leads']['stages']['APPROVED']+$out['crm_leads']['stages']['CLOSED'];
                    $out['crm_leads']['progressed_pct']=$t?round($prog/$t*100,1):0.0;
                }
            }
        } catch(Throwable $e){ $out['warnings'][]='CRM Leads gagal dibaca.'; }

        // Sales DO: current queue, not cumulative journey.
        try {
            if (rmi_funnel_table_exists($pdo,'sales_do')) {
                $out['sales_do']['source_ready']=true;
                $sc=rmi_funnel_first_column($pdo,'sales_do',['flow_status','stage','status']);
                $dc=rmi_funnel_first_column($pdo,'sales_do',['do_date','created_at','date_created']);
                $out['sales_do']['stage_column']=$sc; $out['sales_do']['date_column']=$dc;
                if (!$sc) $out['warnings'][]='Sales DO: kolom flow_status/stage/status tidak ditemukan.';
                else {
                    $where=[];$p=[];
                    if ($dc){$where[]='DATE('.rmi_funnel_ident($dc).') BETWEEN ? AND ?';$p=[$dateFrom,$dateTo];}
                    else $out['warnings'][]='Sales DO: filter periode tidak diterapkan karena kolom tanggal tidak ditemukan.';
                    if(rmi_funnel_column_exists($pdo,'sales_do','deleted_at'))$where[]='`deleted_at` IS NULL';
                    $sql='SELECT '.rmi_funnel_ident($sc).' st,COUNT(*) c FROM `sales_do`'.($where?' WHERE '.implode(' AND ',$where):'').' GROUP BY '.rmi_funnel_ident($sc);
                    $st=$pdo->prepare($sql);$st->execute($p);
                    while($r=$st->fetch(PDO::FETCH_ASSOC)){
                        $c=(int)$r['c'];$b=rmi_funnel_do_stage((string)$r['st']);
                        $out['sales_do']['stages'][$b]+=$c;$out['sales_do']['total']+=$c;
                    }
                    $pw=$where;$pp=$p;
                    if(rmi_funnel_column_exists($pdo,'sales_do','fin_paid_at'))$pw[]='`fin_paid_at` IS NOT NULL';
                    else $pw[]='LOWER(TRIM('.rmi_funnel_ident($sc).")) IN ('paid','closed')";
                    $q='SELECT COUNT(*) FROM `sales_do`'.($pw?' WHERE '.implode(' AND ',$pw):'');
                    $st=$pdo->prepare($q);$st->execute($pp);$out['sales_do']['paid']=(int)$st->fetchColumn();
                    $out['sales_do']['paid_pct']=$out['sales_do']['total']?round($out['sales_do']['paid']/$out['sales_do']['total']*100,1):0.0;
                }
            }
        } catch(Throwable $e){ $out['warnings'][]='Sales DO gagal dibaca.'; }

        // Reg Alkes open backlog as of date_to.
        try {
            if(rmi_funnel_table_exists($pdo,'hrl_reg_alkes_cases')){
                $out['reg_alkes']['source_ready']=true;
                $sc=rmi_funnel_first_column($pdo,'hrl_reg_alkes_cases',['stage_no','current_stage']);
                $stc=rmi_funnel_first_column($pdo,'hrl_reg_alkes_cases',['status','case_status']);
                $dc=rmi_funnel_first_column($pdo,'hrl_reg_alkes_cases',['created_at','opened_at','case_date']);
                if($sc){$w=[];$p=[];if($stc)$w[]="UPPER(COALESCE(".rmi_funnel_ident($stc).",'OPEN'))='OPEN'";if($dc){$w[]='DATE('.rmi_funnel_ident($dc).')<=?';$p[]=$dateTo;}
                    $q='SELECT '.rmi_funnel_ident($sc).' s,COUNT(*) c FROM `hrl_reg_alkes_cases`'.($w?' WHERE '.implode(' AND ',$w):'').' GROUP BY '.rmi_funnel_ident($sc);
                    $st=$pdo->prepare($q);$st->execute($p);while($r=$st->fetch(PDO::FETCH_ASSOC)){$n=(int)$r['s'];if($n>=1&&$n<=15){$out['reg_alkes']['stages'][$n]=(int)$r['c'];$out['reg_alkes']['total']+=(int)$r['c'];}}
                }
                if(rmi_funnel_table_exists($pdo,'hrl_reg_alkes_case_stage_log')){
                    $ls=rmi_funnel_first_column($pdo,'hrl_reg_alkes_case_stage_log',['stage_no','stage']);$en=rmi_funnel_first_column($pdo,'hrl_reg_alkes_case_stage_log',['entered_at','started_at','created_at']);$ex=rmi_funnel_first_column($pdo,'hrl_reg_alkes_case_stage_log',['exited_at','ended_at','completed_at']);
                    if($ls&&$en&&$ex){
                        $q='SELECT '.rmi_funnel_ident($ls).' s,AVG(TIMESTAMPDIFF(SECOND,'.rmi_funnel_ident($en).','.rmi_funnel_ident($ex).'))/86400 d FROM `hrl_reg_alkes_case_stage_log` WHERE '.rmi_funnel_ident($ex).' IS NOT NULL AND DATE('.rmi_funnel_ident($ex).') BETWEEN ? AND ? GROUP BY '.rmi_funnel_ident($ls);
                        $st=$pdo->prepare($q);$st->execute([$dateFrom,$dateTo]);while($r=$st->fetch(PDO::FETCH_ASSOC)){$n=(int)$r['s'];if($n>=1&&$n<=15&&$r['d']!==null)$out['reg_alkes']['avg_days'][$n]=round((float)$r['d'],1);}
                        $q='SELECT '.rmi_funnel_ident($ls).' s,AVG(TIMESTAMPDIFF(SECOND,'.rmi_funnel_ident($en).',NOW()))/86400 d FROM `hrl_reg_alkes_case_stage_log` WHERE '.rmi_funnel_ident($ex).' IS NULL GROUP BY '.rmi_funnel_ident($ls);
                        foreach($pdo->query($q) as $r){$n=(int)$r['s'];if($n>=1&&$n<=15&&$r['d']!==null)$out['reg_alkes']['active_age_days'][$n]=round((float)$r['d'],1);}
                    }
                }
            }
        } catch(Throwable $e){$out['warnings'][]='Reg Alkes gagal dibaca.';}

        // Import stages are independent period-filtered operational counts, not a cohort conversion.
        try {
            $cfg=[
                'PO'=>['purchases_po',['po_date','created_at','date_created'],['id'],['total_amount','grand_total','total']],
                'PIB'=>['purchases_ceisa_pib',['pib_date','created_at','date_created'],['po_code','po_id','id'],[]],
                'GR'=>['wqs_incoming',['received_at','incoming_date','created_at'],['po_code','po_id','id'],[]],
                'AP'=>['purchases_invoice_ap',['invoice_date','created_at','date_created'],['po_code','po_id','id'],[]],
            ];
            foreach($cfg as $key=>$c){[$table,$dates,$ids,$amounts]=$c;if(!rmi_funnel_table_exists($pdo,$table))continue;$out['import']['source_ready']=true;$dc=rmi_funnel_first_column($pdo,$table,$dates);$idc=rmi_funnel_first_column($pdo,$table,$ids);$w=[];$p=[];
                if(rmi_funnel_column_exists($pdo,$table,'deleted_at'))$w[]='`deleted_at` IS NULL';
                if($key==='PO'&&rmi_funnel_column_exists($pdo,$table,'status'))$w[]="UPPER(`status`) IN ('OPEN','IN_PRODUCTION','IN_PROD','READY')";
                if($dc){$w[]='DATE('.rmi_funnel_ident($dc).') BETWEEN ? AND ?';$p=[$dateFrom,$dateTo];$out['import']['period_applied'][$key]=true;}else$out['warnings'][]="Import/PO {$key}: kolom tanggal tidak ditemukan; angka dapat mencakup seluruh periode.";
                $count=$idc?'COUNT(DISTINCT '.rmi_funnel_ident($idc).')':'COUNT(*)';$amt=$amounts?rmi_funnel_first_column($pdo,$table,$amounts):null;$sel=$count.' c'.($amt?',COALESCE(SUM('.rmi_funnel_ident($amt).'),0) v':'');
                $q='SELECT '.$sel.' FROM '.rmi_funnel_ident($table).($w?' WHERE '.implode(' AND ',$w):'');$st=$pdo->prepare($q);$st->execute($p);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];$out['import']['stages'][$key]=(int)($r['c']??0);if($key==='PO')$out['import']['value']=$amt?(float)($r['v']??0):null;
            }
        } catch(Throwable $e){$out['warnings'][]='Import/PO gagal dibaca.';}

        $out['warnings']=array_values(array_unique($out['warnings']));
        return $out;
    }
}
