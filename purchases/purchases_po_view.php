<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../_shared/assets.php'; // RMI asset loader
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    $allowed = function_exists('can_any') && can_any(['PURCHASES.PO_VIEW', 'PURCHASES.PO_CREATE', 'PURCHASES.PO_EDIT', 'PURCHASES.VIEW']);
    if (!$allowed && function_exists('require_role')) {
        require_role(['ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
    } elseif (!$allowed) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
} else {
    require_role(['ADMIN','SUPERADMIN','SYS','PQP','FIN','ACT','WQS','SCM','BRANCH','MANAGER','STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
require_once __DIR__ . '/../master/_audit_master.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$flash = p_flash_get();
$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { echo "PO ID tidak valid."; exit; }

$po=null;
try {
  $st=$pdo->prepare("SELECT po.*, m.manufacture_name, m.manufacture_code, o.office_name, pr.pr_code
                     FROM purchases_po po
                     LEFT JOIN master_manufactures m ON m.id=po.manufacture_id
                     LEFT JOIN master_office o ON o.office_code=po.office_code
                     LEFT JOIN wqs_pr pr ON pr.id=po.pr_id
                     WHERE po.id=? LIMIT 1");
  $st->execute([$id]);
  $po=$st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (!$po) { echo "PO tidak ditemukan."; exit; }

$canPrice = p_can_view_buy_price();
$canEdit = p_can_create_po() || p_is_admin_plus();

function reload_here($id): void { rmi_redirect('purchases_po_view.php?id=' . $id); }


// -----------------------------------------------------------------------------
// Safe PO cancellation / revision helpers.
// Four different business situations are intentionally separated:
// 1) material revision -> cancel PO + return PR to WQS,
// 2) commercial correction -> edit PO only,
// 3) requirement cancelled -> cancel PO + cancel PR,
// 4) downstream already started -> NO direct cancel; reversal/operational close first.
// Existing downstream documents are never deleted or silently changed.
// -----------------------------------------------------------------------------
function rmi_po_table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function rmi_po_column_exists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $st->execute([$table,$column]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}
function rmi_po_count_linked(PDO $pdo, string $table, int $poId, string $poCode, bool $ignoreDeleted=true): ?int {
  if (!rmi_po_table_exists($pdo,$table)) return null;
  $where=[]; $params=[];
  if (rmi_po_column_exists($pdo,$table,'po_id')) { $where[]='po_id=?'; $params[]=$poId; }
  if (rmi_po_column_exists($pdo,$table,'po_code')) { $where[]='po_code=?'; $params[]=$poCode; }
  if (!$where) return null;
  try {
    $sql='SELECT COUNT(*) FROM `'.$table.'` WHERE ('.implode(' OR ',$where).')';
    if ($ignoreDeleted && rmi_po_column_exists($pdo,$table,'deleted_at')) $sql.=' AND deleted_at IS NULL';
    $st=$pdo->prepare($sql); $st->execute($params);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) { return null; }
}
function rmi_po_ap_summary(PDO $pdo, int $poId): array {
  $out=['invoice'=>0,'proforma'=>0,'payment'=>0,'unknown'=>false];
  if (!rmi_po_table_exists($pdo,'purchases_invoice_ap') || !rmi_po_column_exists($pdo,'purchases_invoice_ap','po_id')) return $out;
  try {
    $del=rmi_po_column_exists($pdo,'purchases_invoice_ap','deleted_at') ? ' AND deleted_at IS NULL' : '';
    $st=$pdo->prepare('SELECT COUNT(*) FROM purchases_invoice_ap WHERE po_id=?'.$del); $st->execute([$poId]);
    $out['invoice']=(int)$st->fetchColumn();
    if (rmi_po_column_exists($pdo,'purchases_invoice_ap','invoice_type')) {
      $st=$pdo->prepare("SELECT COUNT(*) FROM purchases_invoice_ap WHERE po_id=? AND UPPER(COALESCE(invoice_type,''))='PROFORMA'".$del);
      $st->execute([$poId]); $out['proforma']=(int)$st->fetchColumn();
    }
    if (rmi_po_table_exists($pdo,'purchases_payment_ap') && rmi_po_column_exists($pdo,'purchases_payment_ap','ap_id')) {
      $pdel=rmi_po_column_exists($pdo,'purchases_payment_ap','deleted_at') ? ' AND pay.deleted_at IS NULL' : '';
      $st=$pdo->prepare('SELECT COUNT(*) FROM purchases_payment_ap pay JOIN purchases_invoice_ap ap ON ap.id=pay.ap_id WHERE ap.po_id=?'.$pdel);
      $st->execute([$poId]); $out['payment']=(int)$st->fetchColumn();
    }
  } catch (Throwable $e) { $out['unknown']=true; }
  return $out;
}
function rmi_po_ceisa_summary(PDO $pdo, int $poId): array {
  $out=['record'=>0,'meaningful'=>0,'payment'=>0,'unknown'=>false];
  if (!rmi_po_table_exists($pdo,'purchases_ceisa_pib') || !rmi_po_column_exists($pdo,'purchases_ceisa_pib','po_id')) return $out;
  try {
    $st=$pdo->prepare('SELECT * FROM purchases_ceisa_pib WHERE po_id=? LIMIT 1'); $st->execute([$poId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $out;
    $out['record']=1;
    $meaningful=false;
    foreach (['submitted_date','bc11_no','bc11_date','noa_no','noa_date','billing_aju_no','billing_aju_date','billing_amount','sppb_no','sppb_date','final_pib_no','final_pib_date'] as $c) {
      if (array_key_exists($c,$row) && $row[$c] !== null && $row[$c] !== '' && (string)$row[$c] !== '0' && (string)$row[$c] !== '0.00') { $meaningful=true; break; }
    }
    $stt=strtoupper(trim((string)($row['ceisa_status'] ?? $row['status'] ?? '')));
    if ($stt !== '' && !in_array($stt,['DRAFT','NEW'],true)) $meaningful=true;
    $out['meaningful']=$meaningful ? 1 : 0;
    if (!empty($row['id']) && rmi_po_table_exists($pdo,'purchases_ceisa_payment') && rmi_po_column_exists($pdo,'purchases_ceisa_payment','pib_id')) {
      $sql='SELECT COUNT(*) FROM purchases_ceisa_payment WHERE pib_id=?';
      if (rmi_po_column_exists($pdo,'purchases_ceisa_payment','deleted_at')) $sql.=' AND deleted_at IS NULL';
      $st=$pdo->prepare($sql); $st->execute([(int)$row['id']]); $out['payment']=(int)$st->fetchColumn();
    }
  } catch (Throwable $e) { $out['unknown']=true; }
  return $out;
}
function rmi_po_forwarder_payment_count(PDO $pdo, int $poId): ?int {
  if (!rmi_po_table_exists($pdo,'purchases_forwarder_invoice') || !rmi_po_table_exists($pdo,'purchases_forwarder_payment')
      || !rmi_po_column_exists($pdo,'purchases_forwarder_invoice','po_id') || !rmi_po_column_exists($pdo,'purchases_forwarder_payment','fap_id')) return 0;
  try {
    $sql='SELECT COUNT(*) FROM purchases_forwarder_payment pay JOIN purchases_forwarder_invoice f ON f.id=pay.fap_id WHERE f.po_id=?';
    if (rmi_po_column_exists($pdo,'purchases_forwarder_payment','deleted_at')) $sql.=' AND pay.deleted_at IS NULL';
    $st=$pdo->prepare($sql); $st->execute([$poId]); return (int)$st->fetchColumn();
  } catch (Throwable $e) { return null; }
}
function rmi_po_selected_quote_count(PDO $pdo, int $poId, string $poCode): ?int {
  foreach (['purchases_forwarder_quotes','purchases_forwarder_quote'] as $table) {
    if (!rmi_po_table_exists($pdo,$table)) continue;
    $where=[]; $params=[];
    if (rmi_po_column_exists($pdo,$table,'po_id')) { $where[]='po_id=?'; $params[]=$poId; }
    if (rmi_po_column_exists($pdo,$table,'po_code')) { $where[]='po_code=?'; $params[]=$poCode; }
    if (!$where) return null;
    try {
      $sql='SELECT COUNT(*) FROM `'.$table.'` WHERE ('.implode(' OR ',$where).')';
      if (rmi_po_column_exists($pdo,$table,'deleted_at')) $sql.=' AND deleted_at IS NULL';
      if (rmi_po_column_exists($pdo,$table,'status')) $sql.=" AND UPPER(COALESCE(status,'')) IN ('SELECTED','APPROVED','AWARDED','CONFIRMED')";
      elseif (rmi_po_column_exists($pdo,$table,'is_selected')) $sql.=' AND COALESCE(is_selected,0)=1';
      else return 0; // quote existence alone is not a posted transaction
      $st=$pdo->prepare($sql); $st->execute($params); return (int)$st->fetchColumn();
    } catch (Throwable $e) { return null; }
  }
  return 0;
}
function rmi_po_import_control_summary(PDO $pdo, int $poId): array {
  $out=['record'=>0,'meaningful'=>0,'unknown'=>false,'detail'=>''];
  if (!rmi_po_table_exists($pdo,'purchases_import_control') || !rmi_po_column_exists($pdo,'purchases_import_control','po_id')) return $out;
  try {
    $st=$pdo->prepare('SELECT * FROM purchases_import_control WHERE po_id=? LIMIT 1'); $st->execute([$poId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $out;
    $out['record']=1; $used=[];
    foreach (['production_start_date','production_done_date','pickup_date','etd','eta','arrived_id_date','arrived_warehouse_date'] as $c) {
      if (array_key_exists($c,$row) && $row[$c] !== null && $row[$c] !== '' && (string)$row[$c] !== '0000-00-00') $used[]=$c.'='.$row[$c];
    }
    foreach (['note_prod','note_ship'] as $c) {
      if (array_key_exists($c,$row) && trim((string)$row[$c]) !== '') $used[]=$c;
    }
    $out['meaningful']=$used ? 1 : 0;
    $out['detail']=$used ? implode(', ',$used) : 'Draft row kosong; tidak dianggap proses aktif.';
  } catch (Throwable $e) { $out['unknown']=true; }
  return $out;
}
function rmi_po_impact_snapshot(PDO $pdo, int $poId, string $poCode, string $poStatus): array {
  $ap=rmi_po_ap_summary($pdo,$poId);
  $ceisa=rmi_po_ceisa_summary($pdo,$poId);
  $importCtrl=rmi_po_import_control_summary($pdo,$poId);
  $gr=rmi_po_count_linked($pdo,'wqs_incoming',$poId,$poCode);
  $docs=rmi_po_count_linked($pdo,'purchases_forwarding_docs',$poId,$poCode);
  $fwdInv=rmi_po_count_linked($pdo,'purchases_forwarder_invoice',$poId,$poCode);
  $fwdPay=rmi_po_forwarder_payment_count($pdo,$poId);
  $selectedQuote=rmi_po_selected_quote_count($pdo,$poId,$poCode);

  $rows=[];
  $add=function(string $key,string $label,$count,string $severity,string $detail='') use (&$rows){
    $rows[$key]=['label'=>$label,'count'=>$count,'severity'=>$severity,'detail'=>$detail];
  };
  $add('production','Production / PO status',in_array($poStatus,['IN_PRODUCTION','READY','CLOSED'],true)?1:0,
      in_array($poStatus,['IN_PRODUCTION','READY','CLOSED'],true)?'BLOCK':'OK',$poStatus);
  $add('import_control','Production / ETD / ETA / Shipping milestone',$importCtrl['meaningful'],$importCtrl['unknown']?'UNKNOWN':($importCtrl['meaningful']>0?'BLOCK':'OK'),$importCtrl['detail']);
  $add('quote','Forwarder selected quote',$selectedQuote,$selectedQuote===null?'UNKNOWN':(($selectedQuote??0)>0?'BLOCK':'OK'),'Quote biasa tetap histori; hanya selected/approved yang memblokir.');
  $add('docs','Forwarding / DOCS / BL',$docs,$docs===null?'UNKNOWN':(($docs??0)>0?'BLOCK':'OK'),'Dokumen tidak pernah dihapus otomatis.');
  $add('fwd_invoice','Forwarder invoice',$fwdInv,$fwdInv===null?'UNKNOWN':(($fwdInv??0)>0?'BLOCK':'OK'));
  $add('fwd_payment','Forwarder payment',$fwdPay,$fwdPay===null?'UNKNOWN':(($fwdPay??0)>0?'BLOCK':'OK'));
  $add('ceisa','CEISA / PIB',$ceisa['meaningful'],$ceisa['unknown']?'UNKNOWN':($ceisa['meaningful']>0?'BLOCK':'OK'),$ceisa['record'] && !$ceisa['meaningful']?'Draft kosong terdeteksi; tidak dianggap proses aktif.':'');
  $add('ceisa_payment','PIB / Customs payment',$ceisa['payment'],$ceisa['unknown']?'UNKNOWN':($ceisa['payment']>0?'BLOCK':'OK'));
  $add('gr','GR / WQS Incoming',$gr,$gr===null?'UNKNOWN':(($gr??0)>0?'BLOCK':'OK'),'Jika sudah GR, koreksi melalui return/adjustment stock.');
  $add('ap','AP Invoice / DP',$ap['invoice'],$ap['unknown']?'UNKNOWN':($ap['invoice']>0?'BLOCK':'OK'),$ap['proforma']>0?($ap['proforma'].' proforma/DP'):'');
  $add('ap_payment','AP Payment',$ap['payment'],$ap['unknown']?'UNKNOWN':($ap['payment']>0?'BLOCK':'OK'),'Pembayaran harus reversal/refund terpisah.');

  $blockers=[]; $unknown=[];
  foreach($rows as $r){
    if($r['severity']==='BLOCK') $blockers[]=$r['label'].(($r['count']??0)>0?' ('.$r['count'].')':'');
    if($r['severity']==='UNKNOWN') $unknown[]=$r['label'];
  }
  return ['rows'=>$rows,'blockers'=>$blockers,'unknown'=>$unknown,'safe'=>empty($blockers)&&empty($unknown)];
}
function rmi_po_downstream_blockers(PDO $pdo, int $poId, string $poCode, string $poStatus='OPEN'): array {
  $impact=rmi_po_impact_snapshot($pdo,$poId,$poCode,$poStatus);
  $out=$impact['blockers'];
  foreach($impact['unknown'] as $x) $out[]='Tidak dapat memverifikasi '.$x;
  return array_values(array_unique($out));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  verify_csrf();
}

$currentPoStatus = up((string)($po['status'] ?? 'OPEN'));
$canCommercialEdit = $canEdit && in_array($currentPoStatus,['DRAFT','OPEN'],true);
$canStatusEdit = $canEdit && in_array($currentPoStatus,['DRAFT','OPEN','IN_PRODUCTION','READY'],true);


// Cancel PO OPEN/DRAFT and return its PR to WQS for material revision.
// This is deliberately separate from generic status changes so a reason is mandatory
// and downstream GR/AP/payment documents cannot be bypassed.
if (isset($_POST['cancel_return_wqs']) && $canEdit) {
  $reason = trim((string)($_POST['revision_reason'] ?? ''));
  $currentStatus = up((string)($po['status'] ?? 'OPEN'));
  if ($reason === '') {
    p_flash_set('danger','Alasan revisi wajib diisi.');
    reload_here($id);
  }
  if (!in_array($currentStatus,['DRAFT','OPEN'],true)) {
    p_flash_set('danger','PO status '.$currentStatus.' tidak dapat dibatalkan langsung untuk revisi WQS. Jika sudah IN_PRODUCTION/READY gunakan proses amendment/cancellation operasional; jika sudah GR/AP lakukan adjustment/return, bukan mengubah PO lama.');
    reload_here($id);
  }
  if (empty($po['pr_id'])) {
    p_flash_set('danger','PO ini tidak terhubung ke PR WQS sehingga tidak dapat menggunakan alur Return to WQS.');
    reload_here($id);
  }
  $blockers = rmi_po_downstream_blockers($pdo,$id,(string)($po['po_code'] ?? ''),$currentStatus);
  if ($blockers) {
    p_flash_set('danger','PO tidak boleh dibatalkan untuk revisi karena: '.implode('; ',$blockers).'. Gunakan adjustment/return/koreksi AP sesuai dokumen downstream.');
    reload_here($id);
  }
  try {
    $pdo->beginTransaction();
    $prId = (int)$po['pr_id'];
    $stOther = $pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE pr_id=? AND id!=? AND deleted_at IS NULL AND UPPER(COALESCE(status,'')) NOT IN ('CANCELLED','CANCELED','VOID')");
    $stOther->execute([$prId,$id]);
    if ((int)$stOther->fetchColumn() > 0) throw new RuntimeException('Masih ada PO aktif lain untuk PR yang sama. Selesaikan PO aktif tersebut terlebih dahulu.');

    $newPoNote = trim((string)($po['note'] ?? ''));
    $tag = '[CANCEL REVISI WQS] '.$reason;
    $newPoNote = $newPoNote === '' ? $tag : $newPoNote."\n".$tag;
    $stCancel=$pdo->prepare("UPDATE purchases_po SET status='CANCELLED', note=? WHERE id=? AND status IN ('DRAFT','OPEN')");
    $stCancel->execute([$newPoNote,$id]);
    if ($stCancel->rowCount() !== 1) throw new RuntimeException('PO berubah status oleh proses lain. Refresh halaman lalu coba kembali.');

    $stPr=$pdo->prepare("SELECT pr_code,note,status FROM wqs_pr WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $stPr->execute([$prId]); $pr=$stPr->fetch(PDO::FETCH_ASSOC);
    if (!$pr) throw new RuntimeException('PR sumber tidak ditemukan.');
    $prNote = trim((string)($pr['note'] ?? ''));
    $prTag = '[REVISI SETELAH PO '.(string)($po['po_code'] ?? '').'] '.$reason;
    $prNote = $prNote === '' ? $prTag : $prNote."\n".$prTag;
    $pdo->prepare("UPDATE wqs_pr SET status='REVISION_WQS', note=? WHERE id=?")
        ->execute([$prNote,$prId]);

    $pdo->commit();
    p_audit($pdo,'PO',(string)$po['po_code'],'CANCEL_RETURN_WQS',['id'=>$id,'pr_id'=>$prId,'reason'=>$reason]);
    p_audit($pdo,'PR',(string)($pr['pr_code'] ?? ''),'REVISION_AFTER_PO_CANCEL',['id'=>$prId,'po_id'=>$id,'reason'=>$reason]);
    if (function_exists('master_audit')) {
      master_audit($pdo,'purchases_po','purchases_po','CANCEL_RETURN_WQS',$id,(string)$po['po_code'],'PO cancelled and PR returned to WQS',['pr_id'=>$prId,'reason'=>$reason]);
      master_audit($pdo,'wqs_pr','wqs_pr','REVISION_AFTER_PO_CANCEL',$prId,(string)($pr['pr_code'] ?? ''),'PR reopened after PO cancellation',['po_id'=>$id,'reason'=>$reason]);
    }
    p_flash_set('success','PO '.h((string)$po['po_code']).' dibatalkan dengan audit. PR dikembalikan ke REVISION_WQS. Jika PR memang salah dan tidak akan direvisi, buka WQS PR lalu gunakan Hapus Terpilih. PO lama tetap tersimpan sebagai CANCELLED.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    p_flash_set('danger','Gagal membatalkan PO untuk revisi: '.h($e->getMessage()));
  }
  reload_here($id);
}


// Cancel the business requirement completely. PR is cancelled, NOT returned for revision.
// Allowed only before downstream operational/financial processing has started.
if (isset($_POST['cancel_requirement']) && $canEdit) {
  $reason = trim((string)($_POST['cancel_reason'] ?? ''));
  $currentStatus = up((string)($po['status'] ?? 'OPEN'));
  if ($reason === '') { p_flash_set('danger','Alasan pembatalan kebutuhan wajib diisi.'); reload_here($id); }
  if (!in_array($currentStatus,['DRAFT','OPEN'],true)) {
    p_flash_set('danger','PO status '.$currentStatus.' tidak dapat dibatalkan sebagai kebutuhan biasa. Gunakan operational cancellation/reversal sesuai proses downstream.');
    reload_here($id);
  }
  if (empty($po['pr_id'])) { p_flash_set('danger','PO tidak terhubung ke PR WQS. Pembatalan kebutuhan harus direview manual.'); reload_here($id); }
  $blockers = rmi_po_downstream_blockers($pdo,$id,(string)($po['po_code'] ?? ''),$currentStatus);
  if ($blockers) {
    p_flash_set('danger','Pembatalan kebutuhan diblokir karena: '.implode('; ',$blockers).'. Selesaikan reversal/cancellation pada modul terkait terlebih dahulu.');
    reload_here($id);
  }
  try {
    $pdo->beginTransaction();
    $prId=(int)$po['pr_id'];
    $stOther=$pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE pr_id=? AND id!=? AND deleted_at IS NULL AND UPPER(COALESCE(status,'')) NOT IN ('CANCELLED','CANCELED','VOID')");
    $stOther->execute([$prId,$id]);
    if ((int)$stOther->fetchColumn()>0) throw new RuntimeException('Masih ada PO aktif lain untuk PR yang sama. Requirement tidak boleh dibatalkan sepihak.');

    $poNote=trim((string)($po['note'] ?? ''));
    $tag='[CANCEL KEBUTUHAN] '.$reason;
    $poNote=$poNote===''?$tag:$poNote."\n".$tag;
    $st=$pdo->prepare("UPDATE purchases_po SET status='CANCELLED', note=? WHERE id=? AND status IN ('DRAFT','OPEN')");
    $st->execute([$poNote,$id]);
    if ($st->rowCount()!==1) throw new RuntimeException('PO berubah status oleh proses lain. Refresh halaman.');

    $stPr=$pdo->prepare("SELECT pr_code,note,status FROM wqs_pr WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $stPr->execute([$prId]); $pr=$stPr->fetch(PDO::FETCH_ASSOC);
    if (!$pr) throw new RuntimeException('PR sumber tidak ditemukan.');
    $prNote=trim((string)($pr['note'] ?? ''));
    $prTag='[KEBUTUHAN DIBATALKAN SETELAH PO '.(string)($po['po_code'] ?? '').'] '.$reason;
    $prNote=$prNote===''?$prTag:$prNote."\n".$prTag;
    $pdo->prepare("UPDATE wqs_pr SET status='CANCELLED', note=? WHERE id=?")->execute([$prNote,$prId]);

    $pdo->commit();
    p_audit($pdo,'PO',(string)$po['po_code'],'CANCEL_REQUIREMENT',['id'=>$id,'pr_id'=>$prId,'reason'=>$reason]);
    p_audit($pdo,'PR',(string)($pr['pr_code'] ?? ''),'CANCEL_REQUIREMENT',['id'=>$prId,'po_id'=>$id,'reason'=>$reason]);
    if (function_exists('master_audit')) {
      master_audit($pdo,'purchases_po','purchases_po','CANCEL_REQUIREMENT',$id,(string)$po['po_code'],'PO cancelled because requirement was cancelled',['pr_id'=>$prId,'reason'=>$reason]);
      master_audit($pdo,'wqs_pr','wqs_pr','CANCEL_REQUIREMENT',$prId,(string)($pr['pr_code'] ?? ''),'PR cancelled because requirement no longer exists',['po_id'=>$id,'reason'=>$reason]);
    }
    p_flash_set('success','PO dibatalkan dan PR ditutup sebagai CANCELLED. Dokumen histori tetap disimpan.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    p_flash_set('danger','Gagal membatalkan kebutuhan: '.h($e->getMessage()));
  }
  reload_here($id);
}

// Update header (PQP/Admin)
if (isset($_POST['update_header']) && $canEdit) {
  // Setelah PO masuk produksi, data komersial dikunci; hanya transisi status berurutan yang boleh.
  $po_date = $canCommercialEdit ? ($_POST['po_date'] ?? $po['po_date']) : $po['po_date'];
  $note = $canCommercialEdit ? trim((string)($_POST['note'] ?? $po['note'])) : (string)$po['note'];
  $payment_term = $canCommercialEdit ? up($_POST['payment_term'] ?? $po['payment_term']) : up((string)$po['payment_term']);
  $status = $canStatusEdit ? up($_POST['status'] ?? $po['status']) : up((string)$po['status']);
  if (!in_array($status,['DRAFT','OPEN','IN_PRODUCTION','READY','CLOSED','CANCELLED'],true)) $status = $po['status'];
  if ($status === 'CANCELLED' && up((string)($po['status'] ?? '')) !== 'CANCELLED') {
    p_flash_set('danger','Untuk CANCEL PO gunakan form "Batalkan PO & Kembalikan ke WQS" agar alasan, audit, serta pengecekan GR/AP/payment tidak terlewati.');
    reload_here($id);
  }
  $currentStatus = up((string)($po['status'] ?? 'OPEN'));
  $allowedTransitions = [
    'DRAFT' => ['OPEN'],
    'OPEN' => ['IN_PRODUCTION'],
    'IN_PRODUCTION' => ['READY'],
    'READY' => ['CLOSED'],
    'CLOSED' => [],
    'CANCELLED' => [],
  ];
  if ($status !== $currentStatus) {
    $nextAllowed = $allowedTransitions[$currentStatus] ?? [];
    if (!in_array($status, $nextAllowed, true)) {
      p_flash_set('danger', 'Transisi status tidak valid: ' . $currentStatus . ' -> ' . $status);
      reload_here($id);
    }
  }

  // Guard: jangan lanjut status produksi kalau total PO masih 0 (harga belum diinput)
  $po_total_now = (float)($po['total_amount'] ?? 0);
  if (in_array($status,['IN_PRODUCTION','READY','CLOSED'],true) && $po_total_now <= 0) {
    p_flash_set('danger','Tidak bisa set status '.$status.' karena Total PO masih 0. Isi harga beli (unit price) di item PO terlebih dahulu.');
    reload_here($id);
  }
  if ($status==='OPEN' && $po_total_now <= 0) {
    p_flash_set('warning','PO diset OPEN tapi Total PO masih 0. DP invoice otomatis tidak bisa dihitung sebelum harga beli diisi.');
  }


  try {
    $pdo->prepare("UPDATE purchases_po SET po_date=?, payment_term=?, note=?, status=? WHERE id=?")
        ->execute([$po_date,$payment_term,$note,$status,$id]);

    // CANCELLED business flow is handled only by cancel_return_wqs above.
    p_audit($pdo,'PO',$po['po_code'],'UPDATE_HEADER',['id'=>$id,'status'=>$status]);
    if (function_exists('master_audit')) {
      master_audit($pdo, 'purchases_po', 'purchases_po', 'UPDATE_HEADER', $id, $po['po_code'], "PO header updated: {$po['po_code']}", ['status' => $status]);
    }
    p_flash_set('success','Header PO updated.');
  } catch (Throwable $e) { p_flash_set('danger','Gagal update header PO.'); }
  reload_here($id);
}

// Save items
if (isset($_POST['save_items']) && $canEdit) {
    if (!$canCommercialEdit) { p_flash_set('danger','Harga/diskon/PPN hanya dapat diubah saat PO DRAFT/OPEN.'); reload_here($id); }
    $item_ids = $_POST['item_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $units = $_POST['unit'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $discounts = $_POST['discount_percent'] ?? [];
    $ppns = $_POST['ppn_percent'] ?? [];
    $zeroPriceCount = 0;

    $pdo->beginTransaction();

    try {
        for ($i = 0; $i < count($item_ids); $i++) {
            $iid = (int)$item_ids[$i];
            // Product/qty/unit are owned by WQS PR. Never trust browser POST for material fields.
            $stLocked = $pdo->prepare("SELECT qty,unit FROM purchases_po_items WHERE id=? AND po_id=? AND deleted_at IS NULL LIMIT 1");
            $stLocked->execute([$iid,$id]);
            $locked = $stLocked->fetch(PDO::FETCH_ASSOC);
            if (!$locked) continue;
            $qty = (float)($locked['qty'] ?? 0);
            $unit = trim((string)($locked['unit'] ?? 'pcs'));
            $price = (float)($prices[$i] ?? 0);

            if (!$canPrice) {
                $price = 0;
            }

            if ($canPrice && $price <= 0) {
                $zeroPriceCount++;
            }

            $discount_percent = (float)($discounts[$i] ?? 0);
            $ppn_percent = (float)($ppns[$i] ?? 11);

            if ($discount_percent < 0) $discount_percent = 0;
            if ($discount_percent > 100) $discount_percent = 100;
            if ($ppn_percent < 0) $ppn_percent = 0;

            $gross = $qty * $price;
            $discount_amount = $gross * ($discount_percent / 100);
            $subtotal = $gross - $discount_amount;
            $ppn_amount = $subtotal * ($ppn_percent / 100);
            $total_after_tax = $subtotal + $ppn_amount;

            $pdo->prepare("
                UPDATE purchases_po_items
                SET
                    unit_price = ?,
                    discount_percent = ?,
                    discount_amount = ?,
                    ppn_percent = ?,
                    ppn_amount = ?,
                    subtotal = ?,
                    total_after_tax = ?
                WHERE id = ?
                  AND po_id = ?
            ")->execute([
                $price,
                $discount_percent,
                $discount_amount,
                $ppn_percent,
                $ppn_amount,
                $subtotal,
                $total_after_tax,
                $iid,
                $id
            ]);
        }

        p_recalc_po_total($pdo, $id);

        $pdo->commit();

        if ($canPrice && $zeroPriceCount > 0) {
            p_flash_set('warning', 'Ada ' . $zeroPriceCount . ' item dengan harga 0.');
        } else {
            p_flash_set('success', 'Items updated.');
        }

    } catch (Throwable $e) {
        $pdo->rollBack();
        p_flash_set('danger', 'Gagal update item PO: ' . $e->getMessage());
    }

    reload_here($id);
}

// Items load
$items=[];
try {
  $st=$pdo->prepare("SELECT * FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL ORDER BY line_no ASC,id ASC");
  $st->execute([$id]);
  $items=$st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$terms=[];
try { $terms=$pdo->query("SELECT term_code FROM master_payment_terms ORDER BY term_code")->fetchAll(); } catch (Throwable $e) {}

$audit_rows = [];
try {
  if (function_exists('master_audit_ensure_table')) { master_audit_ensure_table($pdo); }
  $st = $pdo->prepare("SELECT action, record_code, username, description, created_at FROM system_audit_logs WHERE (module = 'purchases_po' OR record_table = 'purchases_po') AND record_id = ? ORDER BY created_at DESC LIMIT 50");
  $st->execute([$id]);
  $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$poImpact = rmi_po_impact_snapshot($pdo,$id,(string)($po['po_code'] ?? ''),up((string)($po['status'] ?? 'OPEN')));

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$extraHead = '<style>
    body{background:radial-gradient(1200px 800px at 20% 10%, #1f2937 0%, #0b1220 55%, #050814 100%);color:#e5e7eb;min-height:100vh;padding:18px}
    .card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 35px rgba(0,0,0,.45);border-radius:16px}
    .muted{color:#9ca3af;font-size:12px}
    .form-control,.form-select{background:rgba(255,255,255,.06)!important;color:#e5e7eb!important;border:1px solid rgba(255,255,255,.14)!important}
    label{color:#cbd5e1;font-size:12px}
    .btn-soft{border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e5e7eb}
    .btn-soft:hover{background:rgba(255,255,255,.10);color:#fff}
    .num{text-align:right}
  </style>';

rmi_header('PO View', 'purchases', [
  'subtitle' => 'Detail Purchase Order.',
  'breadcrumbs' => [
    ['label' => 'Purchases (PQP)', 'url' => $baseProject . '/purchases/index.php'],
    ['label' => 'PO List', 'url' => $baseProject . '/purchases/purchases_po.php'],
    'PO View',
  ],
  'actions' => [
    ['label' => 'PO List', 'url' => 'purchases_po.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Print', 'url' => 'purchases_po_print.php?id=' . h($id), 'class' => 'btn btn-sm btn-outline-light', 'attrs' => 'target="_blank"'],
    ['label' => 'WQS Incoming', 'url' => '../stock/wqs_incoming.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => 'Open Chat', 'url' => $baseProject . '/chat/index.php?context=PO:' . (int)$id, 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'extra_head' => $extraHead,
]);
?>

<?php
  $poCat = strtoupper(trim((string)($po['category'] ?? 'BMHP')));
  $catColors = ['BMHP' => '#60a5fa', 'ALKES' => '#34d399', 'AKSESORIS' => '#fbbf24'];
  $catColor = $catColors[$poCat] ?? '#9ca3af';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="muted">PO</div>
    <h3 class="mb-0"><?=h(strtoupper($po['po_code'] ?? ''))?> <span class="badge bg-info text-dark" style="font-size:10px;vertical-align:middle">FINAL-PO-CANCEL-V2-2026-09-03</span> <span style="font-size:13px;background:rgba(255,255,255,.08);border:1px solid <?= $catColor ?>;color:<?= $catColor ?>;border-radius:8px;padding:2px 10px;vertical-align:middle"><?= h($poCat) ?></span></h3>
    <div class="muted">
      PR: <?php if (!empty($po['pr_id'])): ?><a href="../stock/wqs_pr_view.php?id=<?= (int)$po['pr_id'] ?>" style="color:#60a5fa;text-decoration:none;font-weight:600"><?= h(strtoupper($po['pr_code'] ?? '-')) ?></a><?php else: ?><?= h(strtoupper($po['pr_code'] ?? '-')) ?><?php endif; ?>
      • Manufacture: <?=h(strtoupper($po['manufacture_name'] ?? '-'))?>
      • Office: <?=h(strtoupper($po['office_name'] ?? $po['office_code'] ?? ''))?>
    </div>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">Header</div>
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="update_header" value="1">
          <div class="col-md-4">
            <label class="form-label">PO Date</label>
            <input class="form-control form-control-sm" type="date" name="po_date" value="<?=h($po['po_date'])?>" <?= $canCommercialEdit?'':'disabled' ?>>
          </div>
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select class="form-select form-select-sm" name="status" <?= $canStatusEdit?'':'disabled' ?>>
              <?php foreach(['OPEN','IN_PRODUCTION','READY','CLOSED'] as $s): ?>
                <option value="<?=h($s)?>" <?= up($po['status'])===$s?'selected':'' ?>><?=h($s)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Payment Term</label>
            <select class="form-select form-select-sm" name="payment_term" <?= $canCommercialEdit?'':'disabled' ?>>
              <option value="">--</option>
              <?php foreach($terms as $t): ?>
                <option value="<?=h(strtoupper($t['term_code']??''))?>" <?= up($po['payment_term'] ?? '')===up($t['term_code'])?'selected':'' ?>><?=h(strtoupper($t['term_code']??''))?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Note</label>
            <textarea class="form-control form-control-sm" name="note" rows="2" <?= $canCommercialEdit?'':'disabled' ?>><?=h($po['note'])?></textarea>
          </div>
          <div class="col-12">
            <?php if ($canCommercialEdit || $canStatusEdit): ?>
              <button class="btn btn-primary btn-sm">Save Header / Status</button>
            <?php else: ?>
              <div class="muted">Read-only</div>
            <?php endif; ?>
          </div>
        </form>

        <?php if ($canEdit): ?>
        <hr>
        <div class="fw-semibold mb-2">PO Cancellation Control</div>
        <div class="muted mb-2">Pilih aksi sesuai penyebab. Dokumen downstream tidak pernah dihapus otomatis.</div>

        <div class="card mb-3" style="background:rgba(2,6,23,.35)">
          <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <b>Cancel Impact Preview</b>
              <?php if ($poImpact['safe']): ?>
                <span class="badge bg-success">SAFE TO CANCEL</span>
              <?php else: ?>
                <span class="badge bg-danger">REQUIRES REVERSAL / REVIEW</span>
              <?php endif; ?>
            </div>
            <div class="table-responsive">
              <table class="table table-sm table-dark mb-1">
                <tbody>
                <?php foreach($poImpact['rows'] as $imp): ?>
                  <?php $sev=$imp['severity']; $cls=$sev==='OK'?'text-success':($sev==='BLOCK'?'text-danger':'text-warning'); ?>
                  <tr>
                    <td><?=h($imp['label'])?></td>
                    <td class="num <?= $cls ?>"><?= $imp['count']===null?'N/A':h((string)$imp['count']) ?></td>
                    <td class="<?= $cls ?>"><?=h($sev)?></td>
                    <td class="muted"><?=h($imp['detail'] ?? '')?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php if (!$poImpact['safe']): ?>
              <div class="alert alert-danger py-2 mb-0" style="font-size:12px">
                PO tidak boleh dicancel langsung. Selesaikan reversal/refund/return/cancellation pada modul terkait sampai blocker bersih.
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if (in_array(up((string)($po['status'] ?? '')),['DRAFT','OPEN'],true) && !empty($po['pr_id']) && $poImpact['safe']): ?>
        <div class="row g-2">
          <div class="col-md-6">
            <form method="post" onsubmit="return confirm('Revisi kebutuhan: batalkan PO lama dan kembalikan PR ke WQS? PO lama tetap CANCELLED sebagai histori.');">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="cancel_return_wqs" value="1">
              <label class="form-label">🟡 Revisi Kebutuhan WQS</label>
              <textarea class="form-control form-control-sm mb-2" name="revision_reason" rows="2" required placeholder="Produk/qty/unit/office/kebutuhan berubah"></textarea>
              <button class="btn btn-warning btn-sm w-100">↩ Cancel PO + Return PR to WQS</button>
              <div class="muted mt-1">Hasil: PO=CANCELLED, PR=REVISION_WQS. Jika PR salah total, WQS dapat menghapusnya lewat Hapus Terpilih; jika hanya perlu koreksi, revisi lalu SUBMIT ulang.</div>
            </form>
          </div>
          <div class="col-md-6">
            <form method="post" onsubmit="return confirm('Batalkan kebutuhan seluruhnya? PO dan PR akan menjadi CANCELLED dan tidak dikirim kembali untuk revisi.');">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="cancel_requirement" value="1">
              <label class="form-label">🔴 Batalkan Kebutuhan</label>
              <textarea class="form-control form-control-sm mb-2" name="cancel_reason" rows="2" required placeholder="Alasan kebutuhan/order tidak dilanjutkan"></textarea>
              <button class="btn btn-danger btn-sm w-100">✕ Cancel PO + Close PR</button>
              <div class="muted mt-1">Hasil: PO=CANCELLED, PR=CANCELLED. Gunakan hanya bila kebutuhan benar-benar tidak dilanjutkan.</div>
            </form>
          </div>
        </div>
        <?php elseif (up((string)($po['status'] ?? ''))==='CANCELLED'): ?>
          <div class="alert alert-secondary py-2 mb-0" style="font-size:12px">PO sudah CANCELLED dan dikunci sebagai histori. Jangan diaktifkan kembali; bila kebutuhan muncul lagi gunakan PR/PO baru sesuai audit trail.</div>
        <?php else: ?>
          <div class="alert alert-warning py-2 mb-0" style="font-size:12px">Direct cancel/revisi WQS tidak tersedia. PO sudah memiliki status/proses downstream atau dependency belum dapat diverifikasi. Gunakan operational cancellation/reversal pada Finance/SCM/ACT/WQS sesuai Impact Preview.</div>
        <?php endif; ?>
        <div class="alert alert-info py-2 mt-2 mb-0" style="font-size:12px"><b>🔵 Koreksi komersial:</b> harga, diskon, PPN, currency, payment term tidak mengembalikan PR ke WQS dan hanya boleh diedit saat PO DRAFT/OPEN.</div>
        <?php endif; ?>

        <hr>
        <div class="fw-semibold">Total</div>
        <div class="display-6"><?=h($canPrice ? p_money($po['total_amount'], $po['currency']) : '0')?></div>
        <div class="muted">Buy price visible: <?= $canPrice ? 'YES' : 'NO' ?></div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-2">Items</div>
        <div class="muted mb-2">Produk / Qty / Unit dikunci dari PR WQS. Di PO hanya data komersial yang boleh diubah (harga, diskon, PPN).</div>

        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="save_items" value="1">
          <div class="table-responsive">
            <table class="table table-sm table-dark align-middle">
    <thead>
        <tr>
            <th>#</th>
            <th>SKU</th>
            <th>Product</th>
            <th class="num">Qty</th>
            <th>Unit</th>
            <th class="num">Unit Price</th>
            <th class="num">Diskon %</th>
            <th class="num">Diskon Rp</th>
            <th class="num">Subtotal</th>
            <th class="num">PPN %</th>
            <th class="num">PPN Rp</th>
            <th class="num">Total + PPN</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach ($items as $it): ?>
            <?php
                $qty = (float)($it['qty'] ?? 0);
                $price = (float)($it['unit_price'] ?? 0);
                $discount_percent = (float)($it['discount_percent'] ?? 0);
                $ppn_percent = (float)($it['ppn_percent'] ?? 11);

                $gross = $qty * $price;
                $discount_amount = (float)($it['discount_amount'] ?? ($gross * ($discount_percent / 100)));
                $subtotal = (float)($it['subtotal'] ?? ($gross - $discount_amount));
                $ppn_amount = (float)($it['ppn_amount'] ?? ($subtotal * ($ppn_percent / 100)));
                $total_after_tax = (float)($it['total_after_tax'] ?? ($subtotal + $ppn_amount));
            ?>

            <tr>
                <td>
                    <?= h($it['line_no']) ?>
                    <input type="hidden" name="item_id[]" value="<?= h($it['id']) ?>">
                </td>

                <td>
                    <b><?= h(rmi_sku($it['sku'])) ?></b>
                </td>

                <td>
                    <?= h(rmi_product_name($it['products_name'])) ?>
                </td>

                <td class="num">
                    <input class="form-control form-control-sm text-end"
                           type="number"
                           step="0.01"
                           name="qty[]"
                           value="<?= h($qty) ?>"
                           readonly>
                </td>

                <td>
                    <input class="form-control form-control-sm"
                           name="unit[]"
                           value="<?= h(strtoupper($it['unit'] ?? '')) ?>"
                           readonly>
                </td>

                <?php if ($canPrice): ?>
                    <td class="num">
                        <input class="form-control form-control-sm text-end"
                               type="number"
                               step="0.01"
                               name="unit_price[]"
                               value="<?= h($price) ?>"
                               <?= $canCommercialEdit ? '' : 'disabled' ?>>
                    </td>

                    <td class="num">
                        <input class="form-control form-control-sm text-end"
                               type="number"
                               step="0.01"
                               name="discount_percent[]"
                               value="<?= h($discount_percent) ?>"
                               <?= $canCommercialEdit ? '' : 'disabled' ?>>
                    </td>

                    <td class="num">
                        <?= h(number_format($discount_amount, 0, ',', '.')) ?>
                    </td>

                    <td class="num">
                        <?= h(number_format($subtotal, 0, ',', '.')) ?>
                    </td>

                    <td class="num">
                        <input class="form-control form-control-sm text-end"
                               type="number"
                               step="0.01"
                               name="ppn_percent[]"
                               value="<?= h($ppn_percent) ?>"
                               <?= $canCommercialEdit ? '' : 'disabled' ?>>
                    </td>

                    <td class="num">
                        <?= h(number_format($ppn_amount, 0, ',', '.')) ?>
                    </td>

                    <td class="num fw-bold">
                        <?= h(number_format($total_after_tax, 0, ',', '.')) ?>
                    </td>
                <?php else: ?>
                    <td class="muted">
                        Restricted
                        <input type="hidden" name="unit_price[]" value="0">
                    </td>
                    <td class="num">0<input type="hidden" name="discount_percent[]" value="0"></td>
                    <td class="num">0</td>
                    <td class="num">0</td>
                    <td class="num">0<input type="hidden" name="ppn_percent[]" value="0"></td>
                    <td class="num">0</td>
                    <td class="num">0</td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>

        <?php if (!$items): ?>
            <tr>
                <td colspan="12" class="muted">Tidak ada item.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>
          </div>

          <?php if ($canEdit): ?>
            <button class="btn btn-primary btn-sm">Save Items</button>
          <?php endif; ?>
        </form>

      </div>
    </div>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Audit Log <span class="muted">(Last 50 events)</span></div>
  <?php if (empty($audit_rows)): ?>
    <div class="muted">Belum ada audit log.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-dark align-middle">
        <thead><tr><th style="width:180px">Time</th><th style="width:120px">Action</th><th style="width:140px">Code</th><th style="width:120px">User</th><th>Description</th></tr></thead>
        <tbody>
        <?php foreach ($audit_rows as $a): ?>
          <tr><td><?= h($a['created_at'] ?? '') ?></td><td><?= h($a['action'] ?? '') ?></td><td><?= h($a['record_code'] ?? '') ?></td><td><?= h($a['username'] ?? '') ?></td><td><?= h($a['description'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php rmi_footer(); ?>
