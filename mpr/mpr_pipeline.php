<?php
if (!function_exists('rmi_icon')) { require_once __DIR__ . '/../_shared/rmi_icons.php'; }
// --- Auth guard ---
$__rmi_guard_dir = __DIR__;
for ($__rmi_guard_i = 0; $__rmi_guard_i < 5; $__rmi_guard_i++) {
    $__rmi_guard_auth = $__rmi_guard_dir . '/master/auth.php';
    if (file_exists($__rmi_guard_auth)) { require_once $__rmi_guard_auth; if (function_exists('require_login')) require_login(); break; }
    $__rmi_guard_parent = dirname($__rmi_guard_dir);
    if ($__rmi_guard_parent === $__rmi_guard_dir) break;
    $__rmi_guard_dir = $__rmi_guard_parent;
}
unset($__rmi_guard_dir,$__rmi_guard_i,$__rmi_guard_auth,$__rmi_guard_parent);
// --- /Auth guard ---

require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/_inc/schema.php';
mpr_schema_ensure($pdo);
require_once __DIR__ . '/../master/_audit_master.php';

// Pipeline stages definition
define('MPR_STAGES', [
    'PROSPEK'     => ['label'=>rmi_icon('search').' Prospek',       'color'=>'#64748b', 'order'=>1],
    'KUNJUNGAN'   => ['label'=>rmi_icon('target').' Kunjungan',      'color'=>'#14b8a6', 'order'=>2],
    'FOLLOW_UP'   => ['label'=>rmi_icon('refresh').' Follow-Up',      'color'=>'#f59e0b', 'order'=>3],
    'PRESENTASI'  => ['label'=>rmi_icon('chart').' Presentasi',     'color'=>'#3b82f6', 'order'=>4],
    'NEGOSIASI'   => ['label'=>rmi_icon('users').' Negosiasi',      'color'=>'#8b5cf6', 'order'=>5],
    'WON'         => ['label'=>rmi_icon('target').' DEAL WON',       'color'=>'#22c55e', 'order'=>6],
    'LOST'        => ['label'=>rmi_icon('cross').' Lost',            'color'=>'#ef4444', 'order'=>7],
]);

const MPR_PROSPECT_TYPES = ['RS','KLINIK','APOTEK','LABORATORIUM','OPTIK','LAINNYA'];

$isBranchOperational = !empty($MPR_BRANCH_OPERATIONAL);
$MPR_DEPO_RESTRICTED = !empty($MPR_IS_DEPO_BRANCH);
$can_manage = $can_create = $isBranchOperational ? true : (function_exists('can_any') ? can_any(['MPR.VIEW','MPR.PLAN_CREATE']) : true);
$is_manager = in_array($MPR_USER['level'], ['MANAGER'], true) || in_array($MPR_USER['role'], ['MANAGER'], true);

// EXPORT
if ((string)($_GET['export'] ?? '') === '1') {
    $ep=[]; $ew='deleted_at IS NULL';
    if (!$MPR_IS_ADMIN) { $ew.=' AND office_code=?'; $ep[]=$MPR_USER['office_code']; }
    $stE=$pdo->prepare("SELECT * FROM mpr_pipeline WHERE {$ew} ORDER BY stage,created_at DESC");
    $stE->execute($ep); $rows_e=$stE->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mpr_pipeline_'.date('Ymd').'.csv"');
    $fh=fopen('php://output','w'); fprintf($fh,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($fh,['prospect_code','prospect_name','prospect_type','prospect_city','stage','pic_name','pic_role','pic_phone','est_deal_value','est_closing_date','owner_username','owner_level','source','notes','created_at','converted_at']);
    foreach ($rows_e as $r) fputcsv($fh,[$r['prospect_code'],$r['prospect_name'],$r['prospect_type'],$r['prospect_city'],$r['stage'],$r['pic_name'],$r['pic_role'],$r['pic_phone'],$r['est_deal_value'],$r['est_closing_date'],$r['owner_username'],$r['owner_level'],$r['source'],$r['notes'],$r['created_at'],$r['converted_at']]);
    fclose($fh); exit;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create' || $action === 'update') {
            if (!$can_create) throw new Exception("Tidak ada izin.");
            $id              = (int)($_POST['id'] ?? 0);
            $name            = trim((string)($_POST['prospect_name'] ?? ''));
            $type            = strtoupper(trim((string)($_POST['prospect_type'] ?? 'RS')));
            $city            = trim((string)($_POST['prospect_city'] ?? ''));
            $address         = trim((string)($_POST['prospect_address'] ?? ''));
            $stage           = strtoupper(trim((string)($_POST['stage'] ?? 'PROSPEK')));
            $pic_name        = trim((string)($_POST['pic_name'] ?? ''));
            $pic_role        = trim((string)($_POST['pic_role'] ?? ''));
            $pic_phone       = trim((string)($_POST['pic_phone'] ?? ''));
            $est_deal        = (string)($_POST['est_deal_value'] ?? '');
            $est_close       = (string)($_POST['est_closing_date'] ?? '');
            $source          = trim((string)($_POST['source'] ?? ''));
            $notes           = trim((string)($_POST['notes'] ?? ''));
            $lost_reason     = trim((string)($_POST['lost_reason'] ?? ''));
            $office_code     = $MPR_IS_ADMIN ? strtoupper(trim((string)($_POST['office_code'] ?? $MPR_USER['office_code']))) : $MPR_USER['office_code'];

            if ($name === '') throw new Exception("Nama prospek wajib diisi.");
            if (!array_key_exists($stage, MPR_STAGES)) throw new Exception("Stage tidak valid.");

            // Owner = user yang login (staff atau manager)
            $owner_username = $MPR_USER['username'];
            $owner_level    = $MPR_USER['level'] ?: $MPR_USER['role'];

            if ($action === 'create') {
                $code = 'PL-' . $office_code . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,5));
                $pdo->prepare("
                    INSERT INTO mpr_pipeline
                      (prospect_code,prospect_name,prospect_type,prospect_city,prospect_address,stage,
                       pic_name,pic_role,pic_phone,est_deal_value,est_closing_date,source,notes,lost_reason,
                       owner_username,owner_level,office_code,dept_code,created_by,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'MPR',?,NOW())
                ")->execute([
                    $code,$name,$type,$city,$address,$stage,
                    $pic_name,$pic_role,$pic_phone,
                    ($est_deal!==''?(float)$est_deal:null),($est_close?:null),
                    $source,$notes,$lost_reason?:null,
                    $owner_username,$owner_level,$office_code,$owner_username,
                ]);
                $new_id=(int)$pdo->lastInsertId();
                mpr_audit($pdo,$MPR_USER,'PIPELINE_CREATE','mpr_pipeline',$new_id,$code,'Tambah prospek',['name'=>$name,'stage'=>$stage,'owner'=>$owner_username]);
                flash_set('success',"Prospek <b>".e($name)."</b> ditambahkan ke pipeline.");
            } else {
                $stP=$pdo->prepare("SELECT * FROM mpr_pipeline WHERE id=? LIMIT 1"); $stP->execute([$id]); $ex=$stP->fetch();
                if (!$ex) throw new Exception("Prospek tidak ditemukan.");
                if (!$MPR_IS_ADMIN && strtoupper(trim((string)$ex['office_code'])) !== strtoupper(trim((string)$MPR_USER['office_code']))) throw new Exception("Scope office tidak sesuai.");

                // Log stage change
                $oldStage = strtoupper((string)$ex['stage']);
                $convertedAt = null;
                if ($stage === 'WON' && $oldStage !== 'WON') $convertedAt = date('Y-m-d H:i:s');

                $pdo->prepare("
                    UPDATE mpr_pipeline SET
                      prospect_name=?,prospect_type=?,prospect_city=?,prospect_address=?,stage=?,
                      pic_name=?,pic_role=?,pic_phone=?,est_deal_value=?,est_closing_date=?,
                      source=?,notes=?,lost_reason=?,
                      converted_at=COALESCE(converted_at,?),updated_at=NOW()
                    WHERE id=?
                ")->execute([
                    $name,$type,$city,$address,$stage,
                    $pic_name,$pic_role,$pic_phone,
                    ($est_deal!==''?(float)$est_deal:null),($est_close?:null),
                    $source,$notes,$lost_reason?:null,
                    $convertedAt,$id,
                ]);
                mpr_audit($pdo,$MPR_USER,'PIPELINE_UPDATE','mpr_pipeline',$id,(string)$ex['prospect_code'],'Update pipeline',['stage_from'=>$oldStage,'stage_to'=>$stage]);
                flash_set('success',"Prospek <b>".e($name)."</b> diupdate. Stage: {$oldStage} → {$stage}.");
            }
            rmi_redirect(url_mpr('mpr_pipeline.php'));
        }

        if ($action === 'delete') {
            if ($MPR_DEPO_RESTRICTED) throw new Exception("Akun Depo tidak diizinkan menghapus histori pipeline.");
            $id=(int)($_POST['id']??0);
            $stDel=$pdo->prepare("SELECT * FROM mpr_pipeline WHERE id=? AND deleted_at IS NULL LIMIT 1");
            $stDel->execute([$id]);
            $del=$stDel->fetch(PDO::FETCH_ASSOC);
            if (!$del) throw new Exception("Prospek tidak ditemukan.");
            if (!$MPR_IS_ADMIN && strtoupper(trim((string)($del['office_code']??''))) !== strtoupper(trim((string)$MPR_USER['office_code']))) {
                throw new Exception("Scope office tidak sesuai.");
            }
            if (!$MPR_IS_ADMIN && (string)($del['owner_username']??'') !== (string)$MPR_USER['username'] && !$is_manager) {
                throw new Exception("Hanya owner, Manager, atau Admin yang boleh menghapus prospek.");
            }
            $pdo->prepare("UPDATE mpr_pipeline SET deleted_at=NOW() WHERE id=?")->execute([$id]);
            flash_set('warning',"Prospek dihapus dari pipeline.");
            rmi_redirect(url_mpr('mpr_pipeline.php'));
        }

        // Serah Terima ke CRM: isi PIC Purchasing + assign ke CRM staff
        if ($action === 'handover_crm') {
            $id               = (int)($_POST['id'] ?? 0);
            $purchasing_name  = trim((string)($_POST['purchasing_pic_name']  ?? ''));
            $purchasing_role  = trim((string)($_POST['purchasing_pic_role']  ?? ''));
            $purchasing_phone = trim((string)($_POST['purchasing_pic_phone'] ?? ''));
            $purchasing_email = trim((string)($_POST['purchasing_pic_email'] ?? ''));
            $handover_to      = trim((string)($_POST['handover_to']         ?? ''));
            $handover_note    = trim((string)($_POST['handover_note']       ?? ''));

            if ($purchasing_name === '') throw new Exception("Nama PIC Purchasing wajib diisi.");
            $stP = $pdo->prepare("SELECT * FROM mpr_pipeline WHERE id=? AND deleted_at IS NULL LIMIT 1");
            $stP->execute([$id]);
            $pr = $stP->fetch();
            if (!$pr) throw new Exception("Prospek tidak ditemukan.");
            if (!$MPR_IS_ADMIN && strtoupper(trim((string)($pr['office_code'] ?? ''))) !== strtoupper(trim((string)$MPR_USER['office_code']))) {
                throw new Exception("Scope office tidak sesuai.");
            }
            if (strtoupper((string)$pr['stage']) !== 'WON') throw new Exception("Serah terima hanya untuk prospek berstatus WON.");

            $pdo->prepare("
                UPDATE mpr_pipeline SET
                  purchasing_pic_name=?, purchasing_pic_role=?,
                  purchasing_pic_phone=?, purchasing_pic_email=?,
                  handover_to=?, handover_note=?, handover_at=NOW(),
                  updated_at=NOW()
                WHERE id=?
            ")->execute([
                $purchasing_name, $purchasing_role,
                $purchasing_phone, $purchasing_email,
                $handover_to ?: null, $handover_note ?: null,
                $id,
            ]);
            mpr_audit($pdo,$MPR_USER,'HANDOVER_CRM','mpr_pipeline',$id,(string)$pr['prospect_code'],'Serah terima ke CRM',['purchasing_pic'=>$purchasing_name,'handover_to'=>$handover_to]);
            flash_set('success',rmi_icon('check')." Serah terima <b>".e((string)$pr['prospect_name'])."</b> ke CRM berhasil. PIC Purchasing: <b>".e($purchasing_name)."</b>".(($handover_to!=='')?" → ".e($handover_to):'').".");
            rmi_redirect(url_mpr('mpr_pipeline.php'));
        }

    } catch (Throwable $e) {
        if (function_exists('rmi_log_module_error')) rmi_log_module_error('mpr_pipeline', $e, ['action' => 'MPR_PIPELINE_SAVE']);
        flash_set('danger',"Error: ".e($e->getMessage()));
        rmi_redirect(url_mpr('mpr_pipeline.php'));
    }
}

require_once __DIR__ . '/_layout_top.php';

// Fetch pipeline
$f_stage = strtoupper(trim((string)($_GET['stage'] ?? '')));
$f_q     = trim((string)($_GET['q'] ?? ''));
$f_who   = trim((string)($_GET['who'] ?? ''));

$where  = 'deleted_at IS NULL';
$params = [];
if (!$MPR_IS_ADMIN) { $where .= ' AND office_code=?'; $params[] = $MPR_USER['office_code']; }
if ($f_stage && $f_stage !== 'ALL') { $where .= ' AND stage=?'; $params[] = $f_stage; }
if ($f_q !== '') { $where .= ' AND (prospect_name LIKE ? OR prospect_city LIKE ? OR pic_name LIKE ?)'; $lk="%$f_q%"; array_push($params,$lk,$lk,$lk); }
if ($f_who !== '') { $where .= ' AND owner_username=?'; $params[] = $f_who; }

$stPL = $pdo->prepare("SELECT * FROM mpr_pipeline WHERE {$where} ORDER BY FIELD(stage,'NEGOSIASI','PRESENTASI','FOLLOW_UP','KUNJUNGAN','PROSPEK','WON','LOST'), est_closing_date ASC, created_at DESC");
$stPL->execute($params);
$pipeline = $stPL->fetchAll();

// Group by stage
$by_stage = []; foreach (MPR_STAGES as $k=>$_) $by_stage[$k] = [];
foreach ($pipeline as $pr) { $s=strtoupper((string)$pr['stage']); if (isset($by_stage[$s])) $by_stage[$s][] = $pr; }

// Summary
$total_est = array_sum(array_column(array_filter($pipeline, fn($r) => $r['stage'] !== 'LOST'), 'est_deal_value'));
$won_count = count(array_filter($pipeline, fn($r) => $r['stage'] === 'WON'));
$active_count = count(array_filter($pipeline, fn($r) => !in_array($r['stage'],['WON','LOST'], true)));

$edit_id=(int)($_GET['edit']??0); $edit=null;
if ($edit_id) {
    if ($MPR_IS_ADMIN) {
        $stEd=$pdo->prepare("SELECT * FROM mpr_pipeline WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $stEd->execute([$edit_id]);
    } else {
        $stEd=$pdo->prepare("SELECT * FROM mpr_pipeline WHERE id=? AND deleted_at IS NULL AND UPPER(TRIM(COALESCE(office_code,'')))=? LIMIT 1");
        $stEd->execute([$edit_id, strtoupper(trim((string)$MPR_USER['office_code']))]);
    }
    $edit=$stEd->fetch(PDO::FETCH_ASSOC)?:null;
    if (!$edit) {
        http_response_code(403);
        exit('Forbidden: prospek bukan milik office akun ini.');
    }
}

$today = date('Y-m-d');
?>

<style>
.pipeline-kanban{display:grid;grid-template-columns:repeat(5,minmax(160px,1fr));gap:8px;overflow-x:auto;padding-bottom:8px;margin-top:12px}
.pipeline-col{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:10px;min-height:100px}
.pipeline-col-head{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06);border-radius:10px 10px 0 0}
.pipeline-col-count{font-size:11px;background:rgba(255,255,255,.1);border-radius:4px;padding:1px 6px;margin-left:6px}
.pipeline-col-body{padding:8px}
.pipeline-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:9px 10px;margin-bottom:6px;font-size:12px;cursor:pointer;transition:all .15s}
.pipeline-card:hover{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.15)}
.pipeline-card-name{font-weight:700;color:#e2e8f0;margin-bottom:2px}
.pipeline-card-meta{font-size:10px;color:#64748b}
.pipeline-card-deal{font-size:11px;color:#22c55e;font-weight:700;margin-top:3px}
.pipeline-overdue{border-left:2px solid #ef4444}
.pipeline-won{border-left:2px solid #22c55e;background:rgba(34,197,94,.07)}
.kanban-wrap{overflow-x:auto}
.pf-strip{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.pf-box{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:8px 14px;text-align:center;min-width:100px}
.pf-val{font-size:18px;font-weight:800;color:#fff}
.pf-lbl{font-size:10px;color:#64748b;text-transform:uppercase}
</style>

<div class="rmi-card">
  <div class="rmi-card-header d-flex flex-wrap gap-2 justify-content-between align-items-start">
    <div>
      <h5><?=rmi_icon('target')?> Pipeline Akuisisi Customer Baru</h5>
      <div class="sub">Kelola semua prospek — Staff &amp; Manager wajib update status pipeline</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-sm btn-outline-light" href="<?= e(url_mpr('mpr_pipeline.php?export=1')) ?>"><?=rmi_icon('outbox')?> Export</a>
    </div>
  </div>
  <div class="rmi-card-body">

    <!-- Summary -->
    <div class="pf-strip">
      <div class="pf-box" style="border-top:3px solid #14b8a6">
        <div class="pf-val"><?= $active_count ?></div><div class="pf-lbl">Pipeline Aktif</div>
      </div>
      <div class="pf-box" style="border-top:3px solid #22c55e">
        <div class="pf-val"><?= $won_count ?></div><div class="pf-lbl">Deal Won</div>
      </div>
      <div class="pf-box" style="border-top:3px solid #10b981">
        <div class="pf-val" style="font-size:13px"><?= $total_est>0?'Rp '.number_format($total_est/1e6,1).'jt':'-' ?></div>
        <div class="pf-lbl">Est. Pipeline Value</div>
      </div>
      <div class="pf-box" style="border-top:3px solid #f59e0b">
        <div class="pf-val"><?= $active_count > 0 ? round($won_count / count($pipeline) * 100) : 0 ?>%</div>
        <div class="pf-lbl">Win Rate</div>
      </div>
    </div>

    <!-- Filter -->
    <form class="d-flex gap-2 mb-3 flex-wrap" method="get">
      <select name="stage" class="form-select form-select-sm" style="max-width:160px">
        <option value="">Semua Stage</option>
        <?php foreach (MPR_STAGES as $k=>$s): ?>
          <option value="<?= e($k) ?>" <?= $f_stage===$k?'selected':'' ?>><?= e($s['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <input name="q" class="form-control form-control-sm" style="max-width:200px" placeholder="Cari nama / kota..." value="<?= e($f_q) ?>">
      <?php if ($MPR_IS_ADMIN): ?>
      <input name="who" class="form-control form-control-sm" style="max-width:140px" placeholder="Owner (username)" value="<?= e($f_who) ?>">
      <?php endif; ?>
      <button class="btn btn-sm btn-primary">Filter</button>
      <a class="btn btn-sm btn-outline-light" href="<?= e(url_mpr('mpr_pipeline.php')) ?>">Reset</a>
    </form>

    <div class="row g-3">
      <!-- Form Add/Edit -->
      <div class="col-lg-4">
        <div class="p-3 rounded-3" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1)">
          <div class="mini fw-semibold mb-1"><?= $edit ? rmi_icon('memo').' Edit Prospek' : rmi_icon('check').' Tambah Prospek Baru' ?></div>
          <div class="mini mb-2" style="color:#64748b">Staff &amp; Manager wajib update pipeline setiap ada perubahan.</div>
          <?php if ($can_create): ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
            <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

            <div class="mb-2">
              <label class="mini">Nama RS / Klinik / Customer <span style="color:#ef4444">*</span></label>
              <input class="form-control form-control-sm" name="prospect_name" value="<?= e($edit['prospect_name'] ?? '') ?>" required placeholder="RSU Harapan Bunda, Klinik Sehat...">
            </div>
            <div class="row g-1 mb-2">
              <div class="col-5">
                <label class="mini">Tipe</label>
                <select class="form-select form-select-sm" name="prospect_type">
                  <?php foreach (MPR_PROSPECT_TYPES as $pt): ?>
                    <option value="<?= e($pt) ?>" <?= ($edit['prospect_type']??'RS')===$pt?'selected':'' ?>><?= e($pt) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-7">
                <label class="mini">Kota</label>
                <input class="form-control form-control-sm" name="prospect_city" value="<?= e($edit['prospect_city'] ?? '') ?>" placeholder="Bogor, Bandung...">
              </div>
            </div>
            <div class="mb-2">
              <label class="mini">Stage Pipeline <span style="color:#ef4444">*</span></label>
              <select class="form-select form-select-sm" name="stage" id="stageSelect">
                <?php foreach (MPR_STAGES as $k=>$s): ?>
                  <option value="<?= e($k) ?>" <?= strtoupper((string)($edit['stage']??'PROSPEK'))===$k?'selected':'' ?> style="color:<?= e($s['color']) ?>"><?= e($s['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="lostReasonWrap" class="mb-2 <?= strtoupper((string)($edit['stage']??''))!=='LOST'?'d-none':'' ?>">
              <label class="mini">Alasan Lost</label>
              <textarea class="form-control form-control-sm" name="lost_reason" rows="2" placeholder="Kenapa tidak jadi?"><?= e($edit['lost_reason'] ?? '') ?></textarea>
            </div>
            <div class="row g-1 mb-2">
              <div class="col-7">
                <label class="mini">Nama Kontak / PIC</label>
                <input class="form-control form-control-sm" name="pic_name" value="<?= e($edit['pic_name'] ?? '') ?>" placeholder="Nama PIC">
              </div>
              <div class="col-5">
                <label class="mini">Jabatan PIC</label>
                <input class="form-control form-control-sm" name="pic_role" value="<?= e($edit['pic_role'] ?? '') ?>" placeholder="Direktur...">
              </div>
            </div>
            <div class="mb-2">
              <label class="mini">No. HP / WA PIC</label>
              <input class="form-control form-control-sm" name="pic_phone" value="<?= e($edit['pic_phone'] ?? '') ?>" placeholder="08xx...">
            </div>
            <div class="row g-1 mb-2">
              <div class="col-6">
                <label class="mini">Est. Nilai Deal (Rp)</label>
                <input class="form-control form-control-sm" name="est_deal_value" value="<?= e($edit['est_deal_value'] ?? '') ?>" placeholder="5000000">
              </div>
              <div class="col-6">
                <label class="mini">Target Closing</label>
                <input class="form-control form-control-sm" type="date" name="est_closing_date" value="<?= e($edit['est_closing_date'] ?? '') ?>">
              </div>
            </div>
            <div class="mb-2">
              <label class="mini">Sumber Lead</label>
              <input class="form-control form-control-sm" name="source" value="<?= e($edit['source'] ?? '') ?>" placeholder="Referral, Cold Call, Pameran...">
            </div>
            <div class="mb-2">
              <label class="mini">Catatan</label>
              <textarea class="form-control form-control-sm" name="notes" rows="2"><?= e($edit['notes'] ?? '') ?></textarea>
            </div>
            <div class="d-flex gap-2 mt-2">
              <button class="btn btn-primary btn-sm flex-fill"><?=rmi_icon('doc')?> Simpan</button>
              <?php if ($edit): ?><a class="btn btn-outline-light btn-sm" href="<?= e(url_mpr('mpr_pipeline.php')) ?>">Cancel</a><?php endif; ?>
            </div>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- Kanban View -->
      <div class="col-lg-8">
        <div class="kanban-wrap">
          <div class="pipeline-kanban">
            <?php
            $activeStages = ['PROSPEK','KUNJUNGAN','FOLLOW_UP','PRESENTASI','NEGOSIASI'];
            foreach ($activeStages as $stageKey):
              $stageDef = MPR_STAGES[$stageKey];
              $cards    = $by_stage[$stageKey] ?? [];
              $stageEst = array_sum(array_column($cards,'est_deal_value'));
            ?>
            <div class="pipeline-col">
              <div class="pipeline-col-head" style="border-top:3px solid <?= e($stageDef['color']) ?>">
                <div style="font-size:11px;font-weight:700;color:<?= e($stageDef['color']) ?>"><?= e($stageDef['label']) ?></div>
                <div style="font-size:10px;color:#64748b;margin-top:2px">
                  <span class="pipeline-col-count"><?= count($cards) ?></span>
                  <?= $stageEst > 0 ? ' • '.number_format($stageEst/1e6,1).'jt' : '' ?>
                </div>
              </div>
              <div class="pipeline-col-body">
                <?php foreach ($cards as $pr):
                  $overdue = !empty($pr['est_closing_date']) && $pr['est_closing_date'] < $today;
                ?>
                <div class="pipeline-card <?= $overdue ? 'pipeline-overdue' : '' ?>">
                  <div class="pipeline-card-name"><?= e(mb_strimwidth((string)$pr['prospect_name'],0,32,'…')) ?></div>
                  <div class="pipeline-card-meta">
                    <?= e($pr['prospect_type']) ?>
                    <?= !empty($pr['prospect_city']) ? ' • '.e($pr['prospect_city']) : '' ?>
                  </div>
                  <?php if (!empty($pr['pic_name'])): ?>
                    <div class="pipeline-card-meta"><?=rmi_icon('user')?> <?= e(mb_strimwidth((string)$pr['pic_name'],0,25,'…')) ?></div>
                  <?php endif; ?>
                  <div class="pipeline-card-meta">
                    <?=rmi_icon('user')?> <?= e($pr['owner_username']) ?>
                    <span style="color:<?= in_array(strtoupper((string)$pr['owner_level']),['MANAGER'],true)?'#fbbf24':'#64748b' ?>">
                      (<?= e(strtolower((string)$pr['owner_level'] ?: 'staff')) ?>)
                    </span>
                  </div>
                  <?php if (!empty($pr['est_deal_value'])): ?>
                    <div class="pipeline-card-deal">Rp <?= number_format((float)$pr['est_deal_value'],0,',','.') ?></div>
                  <?php endif; ?>
                  <?php if (!empty($pr['est_closing_date'])): ?>
                    <div class="pipeline-card-meta <?= $overdue?'text-danger':'' ?>"><?=rmi_icon('calendar')?> <?= e($pr['est_closing_date']) ?><?= $overdue?' '.rmi_icon('warn'):'' ?></div>
                  <?php endif; ?>
                  <div class="d-flex gap-1 mt-2">
                    <a class="btn btn-xs btn-outline-light" href="<?= e(url_mpr('mpr_pipeline.php?edit='.(int)$pr['id'])) ?>"><?=rmi_icon('memo')?></a>
                    <?php if (!$MPR_DEPO_RESTRICTED && ($MPR_IS_ADMIN || $pr['owner_username']===$MPR_USER['username'] || $is_manager)): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus prospek ini?')">
                      <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$pr['id'] ?>">
                      <button class="btn btn-xs btn-outline-danger"><?=rmi_icon('x')?></button>
                    </form>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($cards)): ?><div style="font-size:11px;color:var(--rmi-muted);text-align:center;padding:12px">Kosong</div><?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Won & Lost rows -->
          <div class="row g-2 mt-1">
            <?php foreach (['WON','LOST'] as $sl):
              $slDef = MPR_STAGES[$sl]; $slCards = $by_stage[$sl] ?? [];
            ?>
            <div class="col-6">
              <div class="pipeline-col">
                <div class="pipeline-col-head" style="border-top:3px solid <?= e($slDef['color']) ?>">
                  <div style="font-size:11px;font-weight:700;color:<?= e($slDef['color']) ?>"><?= e($slDef['label']) ?> <span class="pipeline-col-count"><?= count($slCards) ?></span></div>
                </div>
                <div class="pipeline-col-body" style="max-height:200px;overflow-y:auto">
                  <?php foreach ($slCards as $pr):
                    $hasHandover = !empty($pr['handover_at']);
                  ?>
                  <div class="pipeline-card <?= $sl==='WON'?'pipeline-won':'' ?>">
                    <div class="pipeline-card-name"><?= e(mb_strimwidth((string)$pr['prospect_name'],0,28,'…')) ?></div>
                    <div class="pipeline-card-meta"><?=rmi_icon('user')?> <?= e($pr['owner_username']) ?> (<?= e(strtolower((string)$pr['owner_level']?:'staff')) ?>)</div>
                    <?php if (!empty($pr['est_deal_value'])): ?><div class="pipeline-card-deal">Rp <?= number_format((float)$pr['est_deal_value'],0,',','.') ?></div><?php endif; ?>
                    <?php if ($sl==='LOST' && !empty($pr['lost_reason'])): ?><div class="pipeline-card-meta" style="color:#94a3b8"><?= e(mb_strimwidth((string)$pr['lost_reason'],0,50,'…')) ?></div><?php endif; ?>
                    <?php if ($sl==='WON'): ?>
                      <?php if ($hasHandover): ?>
                        <div class="pipeline-card-meta" style="color:#4ade80;margin-top:4px">
                          <?=rmi_icon('check')?> Serah terima ke CRM<br>
                          <span style="color:#94a3b8">PIC: <?= e((string)$pr['purchasing_pic_name']) ?></span>
                          <?php if (!empty($pr['handover_to'])): ?>
                            <br><span style="color:#64748b">→ <?= e((string)$pr['handover_to']) ?></span>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <button class="btn btn-xs mt-2"
                                style="background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3);width:100%"
                                data-bs-toggle="modal"
                                data-bs-target="#modalHandover"
                                data-id="<?= (int)$pr['id'] ?>"
                                data-name="<?= e((string)$pr['prospect_name']) ?>">
                          <?=rmi_icon('users')?> Serah Terima ke CRM
                        </button>
                      <?php endif; ?>
                    <?php endif; ?>
                    <a class="btn btn-xs btn-outline-light mt-1" href="<?= e(url_mpr('mpr_pipeline.php?edit='.(int)$pr['id'])) ?>"><?=rmi_icon('memo')?> Update</a>
                  </div>
                  <?php endforeach; ?>
                  <?php if (empty($slCards)): ?><div style="font-size:11px;color:var(--rmi-muted);text-align:center;padding:12px">Kosong</div><?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Serah Terima ke CRM -->
<div class="modal fade" id="modalHandover" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content" style="background:#0b1220;color:#e5e7eb;border:1px solid rgba(255,255,255,.12)">
      <div class="modal-header py-2" style="border-bottom:1px solid rgba(255,255,255,.08)">
        <h6 class="modal-title"><?=rmi_icon('users')?> Serah Terima ke CRM</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mini mb-3" style="color:#94a3b8">
          Isi <b>Kontak Purchasing (Buyer)</b> di customer ini yang akan menjadi PIC untuk pemesanan.<br>
          Setelah serah terima, MPR kembali fokus mencari customer baru.
        </div>
        <form id="formHandover" method="post">
          <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
          <input type="hidden" name="action" value="handover_crm">
          <input type="hidden" name="id" id="handoverId" value="">

          <div class="mb-2">
            <div class="mini fw-semibold mb-1" style="color:#4ade80">Prospek: <span id="handoverName" style="color:#e2e8f0"></span></div>
          </div>

          <div class="mb-2">
            <label class="mini">Nama PIC Purchasing / Buyer <span style="color:#ef4444">*</span></label>
            <input class="form-control form-control-sm" name="purchasing_pic_name"
                   placeholder="Nama kontak yang akan order ke Rizqullah Mediska" required>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="mini">Jabatan PIC</label>
              <input class="form-control form-control-sm" name="purchasing_pic_role"
                     placeholder="Staff Pengadaan, Kepala Apotek...">
            </div>
            <div class="col-6">
              <label class="mini">No. HP / WA PIC</label>
              <input class="form-control form-control-sm" name="purchasing_pic_phone" placeholder="08xx...">
            </div>
          </div>
          <div class="mb-2">
            <label class="mini">Email PIC</label>
            <input class="form-control form-control-sm" type="email" name="purchasing_pic_email"
                   placeholder="purchasing@rumahsakit.com">
          </div>
          <div class="mb-2">
            <label class="mini">Serahkan ke Staff CRM (username, opsional)</label>
            <input class="form-control form-control-sm" name="handover_to"
                   placeholder="contoh: StaffCRM_BGR">
          </div>
          <div class="mb-3">
            <label class="mini">Catatan Serah Terima</label>
            <textarea class="form-control form-control-sm" name="handover_note" rows="2"
                      placeholder="Konteks penting yang perlu diketahui CRM..."></textarea>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm flex-fill"
                    style="background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.4)">
              <?=rmi_icon('check')?> Konfirmasi Serah Terima
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" data-bs-dismiss="modal">Batal</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<style>.btn-xs{padding:2px 7px;font-size:11px;border-radius:5px}.text-danger{color:#f87171!important}</style>
<script>
document.getElementById('stageSelect')?.addEventListener('change',function(){
  document.getElementById('lostReasonWrap').classList.toggle('d-none', this.value !== 'LOST');
});

// Populate handover modal
document.getElementById('modalHandover')?.addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('handoverId').value = btn.getAttribute('data-id');
  document.getElementById('handoverName').textContent = btn.getAttribute('data-name');
  // Reset form
  this.querySelectorAll('input:not([type="hidden"]),textarea').forEach(function(el){ el.value=''; });
});
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
