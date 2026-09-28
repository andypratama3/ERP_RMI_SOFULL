<?php
/** Shared read-only funnel data service. */
declare(strict_types=1);

if (!function_exists('rmi_funnel_table_exists')) {
    function rmi_funnel_table_exists(PDO $pdo, string $table): bool {
        try {
            $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('rmi_funnel_column_exists')) {
    function rmi_funnel_column_exists(PDO $pdo, string $table, string $column): bool {
        static $cache=[]; $k=strtolower($table.'.'.$column);
        if (array_key_exists($k,$cache)) return $cache[$k];
        try {
            $st=$pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
            $st->execute([$table,$column]);
            return $cache[$k]=(bool)$st->fetchColumn();
        } catch(Throwable $e){ return $cache[$k]=false; }
    }
}
if (!function_exists('rmi_funnel_first_column')) {
    function rmi_funnel_first_column(PDO $pdo,string $table,array $candidates): ?string {
        foreach($candidates as $c) if(rmi_funnel_column_exists($pdo,$table,$c)) return $c;
        return null;
    }
}
if (!function_exists('rmi_funnel_ident')) {
    function rmi_funnel_ident(string $name): string { return '`'.str_replace('`','``',$name).'`'; }
}
if (!function_exists('rmi_funnel_valid_date')) {
    function rmi_funnel_valid_date(?string $value,string $fallback): string {
        $value=trim((string)$value); $dt=DateTimeImmutable::createFromFormat('Y-m-d',$value);
        return ($dt && $dt->format('Y-m-d')===$value)?$value:$fallback;
    }
}
if (!function_exists('rmi_funnel_do_stage')) {
    function rmi_funnel_do_stage(string $raw): string {
        $s=strtolower(trim($raw));
        $map=[
            'new'=>'CRM','draft'=>'CRM','crm'=>'CRM','submitted'=>'CRM','approved'=>'CRM',
            'crm_to_wqs'=>'WQS','sent_wqs'=>'WQS','wqs'=>'WQS','wqs_processing'=>'WQS','allocation'=>'WQS','allocated'=>'WQS','picking'=>'WQS','picked'=>'WQS','wqs_picked'=>'WQS','wqs_done'=>'WQS',
            'ready_scm'=>'SCM','ready_for_scm'=>'SCM','scm'=>'SCM','scm_processing'=>'SCM','on_delivery'=>'SCM','in_delivery'=>'SCM',
            'delivered'=>'ACT','received_customer'=>'ACT','scm_done'=>'ACT','act'=>'ACT','act_processing'=>'ACT','ready_act'=>'ACT','invoiced'=>'ACT','invoice_created'=>'ACT',
            'ready_fin'=>'FIN','act_done'=>'FIN','fin'=>'FIN','fin_processing'=>'FIN','wait_payment'=>'FIN','waiting_payment'=>'FIN','partial_paid'=>'FIN',
            'paid'=>'DONE','paid_done'=>'DONE','fin_done'=>'DONE','closed'=>'DONE','done'=>'DONE','completed'=>'DONE',
            'cancelled'=>'CANCELLED','canceled'=>'CANCELLED','rejected'=>'CANCELLED','void'=>'CANCELLED',
        ];
        return $map[$s]??'OTHER';
    }
}
if (!function_exists('rmi_funnel_mpr_stage')) {
    function rmi_funnel_mpr_stage(string $raw): string {
        $s=strtoupper(trim($raw));
        $s=str_replace(['-',' '],['_','_'],$s);
        $map=[
            'PROSPEK'=>'PROSPEK','PROSPECT'=>'PROSPEK','LEAD'=>'PROSPEK','NEW'=>'PROSPEK',
            'KUNJUNGAN'=>'KUNJUNGAN','VISIT'=>'KUNJUNGAN','VISITING'=>'KUNJUNGAN',
            'FOLLOW_UP'=>'FOLLOW_UP','FOLLOWUP'=>'FOLLOW_UP','FU'=>'FOLLOW_UP',
            'PRESENTASI'=>'PRESENTASI','PRESENTATION'=>'PRESENTASI','PRESENT'=>'PRESENTASI',
            'NEGOSIASI'=>'NEGOSIASI','NEGOTIATION'=>'NEGOSIASI','NEGOTIATE'=>'NEGOSIASI',
            'WON'=>'WON','WIN'=>'WON','CLOSED_WON'=>'WON',
            'LOST'=>'LOST','LOSE'=>'LOST','CLOSED_LOST'=>'LOST','CANCELLED'=>'LOST','CANCELED'=>'LOST',
        ];
        return $map[$s]??'OTHER';
    }
}
if (!function_exists('rmi_funnel_read')) {
    function rmi_funnel_read(PDO $pdo,string $dateFrom,string $dateTo): array {
        $out=[
            'date_from'=>$dateFrom,'date_to'=>$dateTo,'warnings'=>[],
            'crm_leads'=>[
                'label'=>'MPR Sales Pipeline','source'=>'none','mode'=>'snapshot','source_ready'=>false,
                'stages'=>['PROSPEK'=>0,'KUNJUNGAN'=>0,'FOLLOW_UP'=>0,'PRESENTASI'=>0,'NEGOSIASI'=>0,'WON'=>0,'LOST'=>0,'OTHER'=>0],
                'total'=>0,'won_pct'=>0.0,'active_total'=>0,'date_column'=>null,'stage_column'=>null
            ],
            'sales_do'=>[
                'stages'=>['CRM'=>0,'WQS'=>0,'SCM'=>0,'ACT'=>0,'FIN'=>0,'DONE'=>0,'CANCELLED'=>0,'OTHER'=>0],
                'total'=>0,'active_total'=>0,'paid'=>0,'paid_pct'=>0.0,'source_ready'=>false,'stage_column'=>null,'date_column'=>null,'paid_date_column'=>null,'mode'=>'snapshot_as_of'
            ],
            'reg_alkes'=>['stages'=>array_fill(1,15,0),'total'=>0,'avg_days'=>[],'active_age_days'=>[],'source_ready'=>false,'final_open'=>0],
            'import'=>['stages'=>['PO'=>null,'PIB'=>null,'GR'=>null,'AP'=>null],'value'=>null,'source_ready'=>false,'period_applied'=>['PO'=>false,'PIB'=>false,'GR'=>false,'AP'=>false],'mode'=>'snapshot_activity'],
        ];

        // 1) MPR current pipeline as-of dateTo. Fallback to legacy crm_leads only when mpr_pipeline is unavailable.
        try {
            if(rmi_funnel_table_exists($pdo,'mpr_pipeline')){
                $out['crm_leads']['source_ready']=true; $out['crm_leads']['source']='mpr_pipeline';
                $sc=rmi_funnel_first_column($pdo,'mpr_pipeline',['stage','pipeline_stage','status']);
                $dc=rmi_funnel_first_column($pdo,'mpr_pipeline',['created_at','lead_date','prospect_date','date_created']);
                $out['crm_leads']['stage_column']=$sc; $out['crm_leads']['date_column']=$dc;
                if(!$sc){ $out['warnings'][]='MPR Pipeline: kolom stage/status tidak ditemukan.'; }
                else {
                    $w=[];$p=[];
                    if($dc){$w[]='DATE('.rmi_funnel_ident($dc).')<=?';$p[]=$dateTo;}
                    if(rmi_funnel_column_exists($pdo,'mpr_pipeline','deleted_at'))$w[]='`deleted_at` IS NULL';
                    $q='SELECT '.rmi_funnel_ident($sc).' st,COUNT(*) c FROM `mpr_pipeline`'.($w?' WHERE '.implode(' AND ',$w):'').' GROUP BY '.rmi_funnel_ident($sc);
                    $st=$pdo->prepare($q);$st->execute($p);
                    while($r=$st->fetch(PDO::FETCH_ASSOC)){
                        $b=rmi_funnel_mpr_stage((string)$r['st']);$c=(int)$r['c'];
                        $out['crm_leads']['stages'][$b]+=$c;$out['crm_leads']['total']+=$c;
                    }
                    $out['crm_leads']['active_total']=$out['crm_leads']['stages']['PROSPEK']+$out['crm_leads']['stages']['KUNJUNGAN']+$out['crm_leads']['stages']['FOLLOW_UP']+$out['crm_leads']['stages']['PRESENTASI']+$out['crm_leads']['stages']['NEGOSIASI'];
                    $closed=$out['crm_leads']['stages']['WON']+$out['crm_leads']['stages']['LOST'];
                    $out['crm_leads']['won_pct']=$closed>0?round($out['crm_leads']['stages']['WON']/$closed*100,1):0.0;
                }
            } elseif(rmi_funnel_table_exists($pdo,'crm_leads')) {
                $out['crm_leads']['source_ready']=true;$out['crm_leads']['source']='crm_leads_legacy';$out['crm_leads']['label']='CRM Leads (Legacy)';
                $sc=rmi_funnel_first_column($pdo,'crm_leads',['status','lead_status']);$dc=rmi_funnel_first_column($pdo,'crm_leads',['created_at','lead_date','date_created']);
                $out['crm_leads']['stage_column']=$sc;$out['crm_leads']['date_column']=$dc;
                if($sc){$w=[];$p=[];if($dc){$w[]='DATE('.rmi_funnel_ident($dc).') BETWEEN ? AND ?';$p=[$dateFrom,$dateTo];}
                    $q='SELECT '.rmi_funnel_ident($sc).' st,COUNT(*) c FROM `crm_leads`'.($w?' WHERE '.implode(' AND ',$w):'').' GROUP BY '.rmi_funnel_ident($sc);
                    $st=$pdo->prepare($q);$st->execute($p);while($r=$st->fetch(PDO::FETCH_ASSOC)){$s=strtoupper(trim((string)$r['st']));$c=(int)$r['c'];$b=in_array($s,['CLOSED','CANCELLED'],true)?($s==='CLOSED'?'WON':'LOST'):'PROSPEK';$out['crm_leads']['stages'][$b]+=$c;$out['crm_leads']['total']+=$c;}
                    $out['crm_leads']['active_total']=$out['crm_leads']['stages']['PROSPEK'];
                }
                $out['warnings'][]='MPR Pipeline tidak ditemukan; card memakai CRM Leads legacy.';
            } else $out['warnings'][]='MPR/CRM Pipeline: tabel sumber tidak ditemukan.';
        } catch(Throwable $e){$out['warnings'][]='MPR/CRM Pipeline gagal dibaca.';}

        // 2) Sales DO current queue as-of dateTo. STATUS is source of truth; flow_status only fallback.
        try {
            if(rmi_funnel_table_exists($pdo,'sales_do')){
                $out['sales_do']['source_ready']=true;
                $sc=rmi_funnel_first_column($pdo,'sales_do',['status','stage','flow_status']);
                $dc=rmi_funnel_first_column($pdo,'sales_do',['do_date','created_at','date_created']);
                $pc=rmi_funnel_first_column($pdo,'sales_do',['fin_paid_at','paid_at','payment_date','closed_at']);
                $out['sales_do']['stage_column']=$sc;$out['sales_do']['date_column']=$dc;$out['sales_do']['paid_date_column']=$pc;
                if(!$sc){$out['warnings'][]='Sales DO: kolom status/stage tidak ditemukan.';}
                else {
                    $w=[];$p=[];if($dc){$w[]='DATE('.rmi_funnel_ident($dc).')<=?';$p[]=$dateTo;}
                    if(rmi_funnel_column_exists($pdo,'sales_do','deleted_at'))$w[]='`deleted_at` IS NULL';
                    $q='SELECT '.rmi_funnel_ident($sc).' st,COUNT(*) c FROM `sales_do`'.($w?' WHERE '.implode(' AND ',$w):'').' GROUP BY '.rmi_funnel_ident($sc);
                    $st=$pdo->prepare($q);$st->execute($p);
                    while($r=$st->fetch(PDO::FETCH_ASSOC)){$b=rmi_funnel_do_stage((string)$r['st']);$c=(int)$r['c'];$out['sales_do']['stages'][$b]+=$c;$out['sales_do']['total']+=$c;}
                    $out['sales_do']['active_total']=$out['sales_do']['stages']['CRM']+$out['sales_do']['stages']['WQS']+$out['sales_do']['stages']['SCM']+$out['sales_do']['stages']['ACT']+$out['sales_do']['stages']['FIN'];
                    // Paid activity is period-based, independent of DO creation date.
                    if($pc){$pw=[];$pp=[];$pw[]='DATE('.rmi_funnel_ident($pc).') BETWEEN ? AND ?';$pp=[$dateFrom,$dateTo];if(rmi_funnel_column_exists($pdo,'sales_do','deleted_at'))$pw[]='`deleted_at` IS NULL';
                        $q='SELECT COUNT(*) FROM `sales_do` WHERE '.implode(' AND ',$pw);$st=$pdo->prepare($q);$st->execute($pp);$out['sales_do']['paid']=(int)$st->fetchColumn();
                    } else {
                        $pw=[];$pp=[];if($dc){$pw[]='DATE('.rmi_funnel_ident($dc).') BETWEEN ? AND ?';$pp=[$dateFrom,$dateTo];}$pw[]='LOWER(TRIM('.rmi_funnel_ident($sc).")) IN ('paid','paid_done','fin_done','closed','done','completed')";
                        $q='SELECT COUNT(*) FROM `sales_do`'.($pw?' WHERE '.implode(' AND ',$pw):'');$st=$pdo->prepare($q);$st->execute($pp);$out['sales_do']['paid']=(int)$st->fetchColumn();
                        $out['warnings'][]='Sales DO: tanggal pembayaran tidak ditemukan; paid memakai fallback status dalam periode DO.';
                    }
                    $den=$out['sales_do']['stages']['DONE']+$out['sales_do']['active_total'];
                    $out['sales_do']['paid_pct']=$den>0?round($out['sales_do']['stages']['DONE']/$den*100,1):0.0;
                }
            } else $out['warnings'][]='Sales DO: tabel sumber tidak ditemukan.';
        } catch(Throwable $e){$out['warnings'][]='Sales DO gagal dibaca.';}

        // 3) Reg Alkes current OPEN backlog as-of dateTo.
        try {
            if(rmi_funnel_table_exists($pdo,'hrl_reg_alkes_cases')){
                $out['reg_alkes']['source_ready']=true;
                $sc=rmi_funnel_first_column($pdo,'hrl_reg_alkes_cases',['stage_no','current_stage']);$stc=rmi_funnel_first_column($pdo,'hrl_reg_alkes_cases',['status','case_status']);$dc=rmi_funnel_first_column($pdo,'hrl_reg_alkes_cases',['created_at','opened_at','case_date']);
                if($sc){$w=[];$p=[];if($stc)$w[]="UPPER(COALESCE(".rmi_funnel_ident($stc).",'OPEN'))='OPEN'";if($dc){$w[]='DATE('.rmi_funnel_ident($dc).')<=?';$p[]=$dateTo;}
                    $q='SELECT '.rmi_funnel_ident($sc).' s,COUNT(*) c FROM `hrl_reg_alkes_cases`'.($w?' WHERE '.implode(' AND ',$w):'').' GROUP BY '.rmi_funnel_ident($sc);$st=$pdo->prepare($q);$st->execute($p);
                    while($r=$st->fetch(PDO::FETCH_ASSOC)){$n=(int)$r['s'];if($n>=1&&$n<=15){$out['reg_alkes']['stages'][$n]=(int)$r['c'];$out['reg_alkes']['total']+=(int)$r['c'];}}
                    $out['reg_alkes']['final_open']=$out['reg_alkes']['stages'][15]??0;
                } else $out['warnings'][]='Reg Alkes: kolom current stage tidak ditemukan.';
                if(rmi_funnel_table_exists($pdo,'hrl_reg_alkes_case_stage_log')){
                    $ls=rmi_funnel_first_column($pdo,'hrl_reg_alkes_case_stage_log',['stage_no','stage']);$en=rmi_funnel_first_column($pdo,'hrl_reg_alkes_case_stage_log',['entered_at','started_at','created_at']);$ex=rmi_funnel_first_column($pdo,'hrl_reg_alkes_case_stage_log',['exited_at','ended_at','completed_at']);
                    if($ls&&$en&&$ex){
                        $q='SELECT '.rmi_funnel_ident($ls).' s,AVG(TIMESTAMPDIFF(SECOND,'.rmi_funnel_ident($en).','.rmi_funnel_ident($ex).'))/86400 d FROM `hrl_reg_alkes_case_stage_log` WHERE '.rmi_funnel_ident($ex).' IS NOT NULL AND DATE('.rmi_funnel_ident($ex).') BETWEEN ? AND ? GROUP BY '.rmi_funnel_ident($ls);$st=$pdo->prepare($q);$st->execute([$dateFrom,$dateTo]);while($r=$st->fetch(PDO::FETCH_ASSOC)){$n=(int)$r['s'];if($n>=1&&$n<=15&&$r['d']!==null)$out['reg_alkes']['avg_days'][$n]=round((float)$r['d'],1);}
                        // age as-of dateTo, not NOW(), so historical filters remain truthful.
                        $q='SELECT '.rmi_funnel_ident($ls).' s,AVG(TIMESTAMPDIFF(SECOND,'.rmi_funnel_ident($en).',CONCAT(?,\' 23:59:59\')))/86400 d FROM `hrl_reg_alkes_case_stage_log` WHERE '.rmi_funnel_ident($ex).' IS NULL AND DATE('.rmi_funnel_ident($en).')<=? GROUP BY '.rmi_funnel_ident($ls);$st=$pdo->prepare($q);$st->execute([$dateTo,$dateTo]);while($r=$st->fetch(PDO::FETCH_ASSOC)){$n=(int)$r['s'];if($n>=1&&$n<=15&&$r['d']!==null)$out['reg_alkes']['active_age_days'][$n]=round(max(0,(float)$r['d']),1);}
                    }
                }
            } else $out['warnings'][]='Reg Alkes: tabel sumber tidak ditemukan.';
        } catch(Throwable $e){$out['warnings'][]='Reg Alkes gagal dibaca.';}

        // 4) Procurement / Import snapshot. PO = current active snapshot as-of dateTo; PIB/GR/AP = activity in selected period.
        try {
            if(rmi_funnel_table_exists($pdo,'purchases_po')){
                $out['import']['source_ready']=true;$table='purchases_po';$dc=rmi_funnel_first_column($pdo,$table,['po_date','created_at','date_created']);$idc=rmi_funnel_first_column($pdo,$table,['id','po_id','po_code','po_no']);$amt=rmi_funnel_first_column($pdo,$table,['total_amount','grand_total','total','po_value']);$sc=rmi_funnel_first_column($pdo,$table,['status','po_status']);$w=[];$p=[];
                if(rmi_funnel_column_exists($pdo,$table,'deleted_at'))$w[]='`deleted_at` IS NULL';if($sc)$w[]='UPPER('.rmi_funnel_ident($sc).") IN ('OPEN','IN_PRODUCTION','IN_PROD','READY')";if($dc){$w[]='DATE('.rmi_funnel_ident($dc).')<=?';$p[]=$dateTo;$out['import']['period_applied']['PO']=true;}
                $count=$idc?'COUNT(DISTINCT '.rmi_funnel_ident($idc).')':'COUNT(*)';$sel=$count.' c'.($amt?',COALESCE(SUM('.rmi_funnel_ident($amt).'),0) v':'');$q='SELECT '.$sel.' FROM `'.$table.'`'.($w?' WHERE '.implode(' AND ',$w):'');$st=$pdo->prepare($q);$st->execute($p);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];$out['import']['stages']['PO']=(int)($r['c']??0);$out['import']['value']=$amt?(float)($r['v']??0):null;
            } else $out['warnings'][]='Procurement: tabel purchases_po tidak ditemukan.';
            $cfg=[
                'PIB'=>['purchases_ceisa_pib',['pib_date','created_at','date_created'],['po_code','po_id','id']],
                'GR'=>['wqs_incoming',['received_at','incoming_date','created_at'],['po_code','po_id','id']],
                'AP'=>['purchases_invoice_ap',['invoice_date','created_at','date_created'],['po_code','po_id','id']],
            ];
            foreach($cfg as $key=>$c){[$table,$dates,$ids]=$c;if(!rmi_funnel_table_exists($pdo,$table)){continue;}$out['import']['source_ready']=true;$dc=rmi_funnel_first_column($pdo,$table,$dates);$idc=rmi_funnel_first_column($pdo,$table,$ids);$w=[];$p=[];if(rmi_funnel_column_exists($pdo,$table,'deleted_at'))$w[]='`deleted_at` IS NULL';if($dc){$w[]='DATE('.rmi_funnel_ident($dc).') BETWEEN ? AND ?';$p=[$dateFrom,$dateTo];$out['import']['period_applied'][$key]=true;}else{$out['warnings'][]="{$key}: kolom tanggal tidak ditemukan; angka tidak ditampilkan agar tidak menyesatkan.";$out['import']['stages'][$key]=null;continue;}$count=$idc?'COUNT(DISTINCT '.rmi_funnel_ident($idc).')':'COUNT(*)';$q='SELECT '.$count.' c FROM '.rmi_funnel_ident($table).($w?' WHERE '.implode(' AND ',$w):'');$st=$pdo->prepare($q);$st->execute($p);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];$out['import']['stages'][$key]=(int)($r['c']??0);}
        } catch(Throwable $e){$out['warnings'][]='Procurement/Import gagal dibaca.';}

        $out['warnings']=array_values(array_unique($out['warnings']));
        return $out;
    }
}
