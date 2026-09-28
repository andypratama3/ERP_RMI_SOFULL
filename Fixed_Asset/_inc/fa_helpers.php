<?php
// Fixed_Asset_Lite/_inc/fa_helpers.php
require_once __DIR__ . '/bootstrap.php';

// Explicit auth guard (for static scan + safety)
if (function_exists('require_login')) { require_login(); }


function fa_tax_groups(): array {
    return [
        ['code'=>'G1','name'=>'Kelompok 1 (4 thn)','life_months'=>48,'rate_sl'=>0.25,'rate_ddb'=>0.50,'allow_ddb'=>1],
        ['code'=>'G2','name'=>'Kelompok 2 (8 thn)','life_months'=>96,'rate_sl'=>0.125,'rate_ddb'=>0.25,'allow_ddb'=>1],
        ['code'=>'G3','name'=>'Kelompok 3 (16 thn)','life_months'=>192,'rate_sl'=>0.0625,'rate_ddb'=>0.125,'allow_ddb'=>1],
        ['code'=>'G4','name'=>'Kelompok 4 (20 thn)','life_months'=>240,'rate_sl'=>0.05,'rate_ddb'=>0.10,'allow_ddb'=>1],
        ['code'=>'B-PERM','name'=>'Bangunan Permanen (20 thn)','life_months'=>240,'rate_sl'=>0.05,'rate_ddb'=>0.00,'allow_ddb'=>0],
        ['code'=>'B-NONPERM','name'=>'Bangunan Tidak Permanen (10 thn)','life_months'=>120,'rate_sl'=>0.10,'rate_ddb'=>0.00,'allow_ddb'=>0],
    ];
}

function fa_group_map(): array {
    $m=[];
    foreach(fa_tax_groups() as $g){ $m[$g['code']]=$g; }
    return $m;
}

function fa_log(PDO $pdo, string $action, string $entity, int $entity_id=0, array $meta=[]): void {
    $u = (int)($_SESSION['user_id'] ?? 0);
    $stmt=$pdo->prepare("INSERT INTO fa_audit_log(created_at,user_id,action,entity,entity_id,meta_json) VALUES (NOW(),?,?,?,?,?)");
    $stmt->execute([$u,$action,$entity,$entity_id,json_encode($meta, JSON_UNESCAPED_UNICODE)]);
}

function fa_month_start(string $ym): string { // YYYY-MM
    return $ym . "-01";
}
function fa_month_end(string $ym): string { // last day
    $dt = DateTime::createFromFormat('Y-m-d', $ym.'-01');
    $dt->modify('last day of this month');
    return $dt->format('Y-m-d');
}

function fa_next_month_ym(string $ym): string {
    $dt = DateTime::createFromFormat('Y-m-d', $ym.'-01');
    $dt->modify('+1 month');
    return $dt->format('Y-m');
}

function fa_calc_monthly_dep(array $asset, array $group, float $opening_book, float $accum_before, string $ym): float {
    // asset: cost, salvage, method
    $cost = (float)$asset['acq_cost'];
    $salv = (float)$asset['salvage_value'];
    $method = $asset['dep_method']; // SL/DDB
    $life = (int)$group['life_months'];
    if ($life <= 0) return 0.0;

    $remaining = max(0.0, ($cost - $salv) - $accum_before);
    if ($remaining <= 0.00001) return 0.0;

    if ($method === 'DDB' && (int)$group['allow_ddb'] === 1) {
        $annual = (float)$group['rate_ddb'];
        $monthly = $annual / 12.0;
        $amt = $opening_book * $monthly;
        // jangan lewat sisa
        if ($amt > $remaining) $amt = $remaining;
        return round($amt, 2);
    }

    // default SL
    $monthly_amt = (($cost - $salv) / $life);
    if ($monthly_amt > $remaining) $monthly_amt = $remaining;
    return round($monthly_amt, 2);
}

// ------------------------- master lookups (Office & Departement) -------------------------
function fa_master_offices(PDO $pdo): array {
    // Return: [['office_code'=>'BGR','office_name'=>'Bogor'], ...]
    try{
        $stmt = $pdo->query("SHOW TABLES LIKE 'master_office'");
        if(!$stmt->fetchColumn()) return [];
        $q = $pdo->query("SELECT office_code, office_name FROM master_office WHERE (is_active=1 OR is_active='1' OR is_active='Y' OR is_active IS NULL) ORDER BY office_name, office_code");
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Throwable $e){
        return [];
    }
}

function fa_master_departements(PDO $pdo, string $office_code=''): array {
    // Return: [['dept_code'=>'FIN','dept_name'=>'Finance','office_code'=>'BGR'], ...]
    try{
        $stmt = $pdo->query("SHOW TABLES LIKE 'master_departements'");
        if(!$stmt->fetchColumn()) return [];
        if($office_code !== '' && strtoupper($office_code) !== 'ALL'){
            $q = $pdo->prepare("SELECT dept_code, dept_name, office_code FROM master_departements WHERE (office_code=? OR office_code='' OR office_code IS NULL) AND (status='ACTIVE' OR status='1' OR status='Y' OR status IS NULL) ORDER BY dept_code");
            $q->execute([$office_code]);
            return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $q = $pdo->query("SELECT dept_code, dept_name, office_code FROM master_departements WHERE (status='ACTIVE' OR status='1' OR status='Y' OR status IS NULL) ORDER BY dept_code");
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Throwable $e){
        return [];
    }
}

function fa_build_options(array $rows, string $valueKey, string $labelKey, string $selected=''): string {
    $html = '';
    foreach($rows as $r){
        $val = (string)($r[$valueKey] ?? '');
        if($val==='') continue;
        $label = (string)($r[$labelKey] ?? $val);
        $sel = ($val === $selected) ? 'selected' : '';
        $html .= '<option value="'.h($val).'" '.$sel.'>'.h($val.' - '.$label).'</option>';
    }
    return $html;
}

