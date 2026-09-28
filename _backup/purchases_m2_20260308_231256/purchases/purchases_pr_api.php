<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../master/auth.php';
require_login();
$_uid = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
if ($_uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'LOGIN_REQUIRED'], JSON_UNESCAPED_UNICODE); exit; }
$_role = strtoupper((string)($_SESSION['role'] ?? ($_SESSION['user']['role'] ?? ($_SESSION['level'] ?? ($_SESSION['user']['level'] ?? '')))));
$_level = strtoupper((string)($_SESSION['level'] ?? ($_SESSION['user']['level'] ?? '')));
$_allowed = array_map('strtoupper', ['SYS','SUPERADMIN','ADMIN','PQP']);
if (!in_array($_role, $_allowed, true) && !in_array($_level, $_allowed, true)) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'FORBIDDEN'], JSON_UNESCAPED_UNICODE); exit; }
// purchases/purchases_pr_api.php
// Return PR header + items for PO autofill
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

$pr_id = (int)($_GET['pr_id'] ?? 0);
$pr_code = up($_GET['pr_code'] ?? '');

if ($pr_id <= 0 && $pr_code === '') {
  echo json_encode(['ok'=>false,'message'=>'pr_id/pr_code wajib']); exit;
}

try {
  if ($pr_id > 0) {
    $st = $pdo->prepare("SELECT * FROM wqs_pr WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$pr_id]);
  } else {
    $st = $pdo->prepare("SELECT * FROM wqs_pr WHERE UPPER(pr_code)=UPPER(?) AND deleted_at IS NULL LIMIT 1");
    $st->execute([$pr_code]);
  }
  $hdr = $st->fetch(PDO::FETCH_ASSOC);
  if (!$hdr) { echo json_encode(['ok'=>false,'message'=>'PR tidak ditemukan']); exit; }

  $st2 = $pdo->prepare("SELECT i.product_id, i.qty, i.unit, COALESCE(i.sku,'') sku, COALESCE(i.products_name,'') name
                        FROM wqs_pr_items i
                        WHERE i.pr_id=? AND i.deleted_at IS NULL
                        ORDER BY i.line_no ASC, i.id ASC");
  $st2->execute([(int)$hdr['id']]);
  $items = $st2->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'ok'=>true,
    'header'=>[
      'id'=>(int)$hdr['id'],
      'pr_code'=>(string)$hdr['pr_code'],
      'pr_date'=>(string)$hdr['pr_date'],
      'office_code'=>up($hdr['office_code'] ?? ''),
      'status'=>up($hdr['status'] ?? ''),
      'note'=>(string)($hdr['note'] ?? ''),
    ],
    'items'=>array_map(function($it){
      return [
        'product_id'=>(int)($it['product_id'] ?? 0),
        'sku'=>up($it['sku'] ?? ''),
        'name'=>up($it['name'] ?? ''),
        'qty'=>(float)($it['qty'] ?? 0),
        'unit'=>(string)($it['unit'] ?? ''),
      ];
    }, $items),
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
