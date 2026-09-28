<?php
require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../master/auth.php';
require_login();
$_uid = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
if ($_uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'LOGIN_REQUIRED'], JSON_UNESCAPED_UNICODE);
  exit;
}
$_role = strtoupper((string)($_SESSION['role'] ?? ($_SESSION['user']['role'] ?? ($_SESSION['level'] ?? ($_SESSION['user']['level'] ?? '')))));
$_level = strtoupper((string)($_SESSION['level'] ?? ($_SESSION['user']['level'] ?? '')));
$_allowed = array_map('strtoupper', ['SYS','SUPERADMIN','ADMIN','PQP','MANAGER']);
if (!in_array($_role, $_allowed, true) && !in_array($_level, $_allowed, true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'msg'=>'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
  exit;
}

// purchases/purchases_pr_api.php
// Return PR header + items for PO autofill.
// business_group/category ikut dibawa sebagai snapshot klasifikasi.
require_once __DIR__ . '/_purchases_lib.php';
$pdo = p_pdo();
p_ensure_schema($pdo);

function pr_api_has_col(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table . ':' . $column;
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`','``',$table) . "` LIKE ?");
    $st->execute([$column]);
    return $cache[$key] = (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return $cache[$key] = false;
  }
}

function pr_api_business_group_from_category(string $category): string {
  $cat = strtoupper(trim($category));
  if ($cat === 'BMHP') return 'BMHP';
  if (in_array($cat, ['UNIT_ACC','ALKES','AKSESORIS'], true)) return 'UNIT_ACC';
  return '';
}

$pr_id = (int)($_GET['pr_id'] ?? 0);
$pr_code = up($_GET['pr_code'] ?? '');
if ($pr_id <= 0 && $pr_code === '') {
  echo json_encode(['ok'=>false,'message'=>'pr_id/pr_code wajib'], JSON_UNESCAPED_UNICODE);
  exit;
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
  if (!$hdr) {
    echo json_encode(['ok'=>false,'message'=>'PR tidak ditemukan'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $hasItemBg  = pr_api_has_col($pdo, 'wqs_pr_items', 'business_group');
  $hasItemCat = pr_api_has_col($pdo, 'wqs_pr_items', 'category');
  $hasMpBg    = pr_api_has_col($pdo, 'master_products', 'business_group');
  $hasMpCat   = pr_api_has_col($pdo, 'master_products', 'category');

  $bgExpr = $hasItemBg
    ? "NULLIF(UPPER(TRIM(i.business_group)),'')"
    : "NULL";
  if ($hasMpBg) {
    $bgExpr = "COALESCE({$bgExpr}, NULLIF(UPPER(TRIM(mp.business_group)),''))";
  }

  $catExpr = $hasItemCat
    ? "NULLIF(UPPER(TRIM(i.category)),'')"
    : "NULL";
  if ($hasMpCat) {
    $catExpr = "COALESCE({$catExpr}, NULLIF(UPPER(TRIM(mp.category)),''))";
  }

  $sql = "SELECT i.product_id, i.qty, i.unit,
                 COALESCE(i.sku,'') AS sku,
                 COALESCE(i.products_name,'') AS name,
                 {$bgExpr} AS business_group,
                 {$catExpr} AS category
          FROM wqs_pr_items i
          LEFT JOIN master_products mp ON mp.id=i.product_id
          WHERE i.pr_id=? AND i.deleted_at IS NULL
          ORDER BY i.line_no ASC, i.id ASC";
  $st2 = $pdo->prepare($sql);
  $st2->execute([(int)$hdr['id']]);
  $items = $st2->fetchAll(PDO::FETCH_ASSOC);

  $headerCategory = strtoupper(trim((string)($hdr['category'] ?? 'BMHP')));
  $headerBusinessGroup = '';
  if (array_key_exists('business_group', $hdr)) {
    $headerBusinessGroup = strtoupper(trim((string)($hdr['business_group'] ?? '')));
  }
  if (!in_array($headerBusinessGroup, ['BMHP','UNIT_ACC'], true)) {
    $headerBusinessGroup = pr_api_business_group_from_category($headerCategory);
  }
  if ($headerBusinessGroup === 'UNIT_ACC') $headerCategory = 'UNIT_ACC';
  elseif ($headerBusinessGroup === 'BMHP') $headerCategory = 'BMHP';

  echo json_encode([
    'ok'=>true,
    'header'=>[
      'id'=>(int)$hdr['id'],
      'pr_code'=>(string)$hdr['pr_code'],
      'pr_date'=>(string)$hdr['pr_date'],
      'office_code'=>up($hdr['office_code'] ?? ''),
      'status'=>up($hdr['status'] ?? ''),
      'note'=>(string)($hdr['note'] ?? ''),
      'business_group'=>$headerBusinessGroup,
      'category'=>$headerCategory,
    ],
    'items'=>array_map(function($it) use ($headerBusinessGroup, $headerCategory) {
      $cat = strtoupper(trim((string)($it['category'] ?? '')));
      $bg = strtoupper(trim((string)($it['business_group'] ?? '')));
      if ($bg === '') $bg = pr_api_business_group_from_category($cat);
      if ($bg === '') $bg = $headerBusinessGroup;
      if ($cat === '' && $bg === 'BMHP') $cat = 'BMHP';
      // UNIT_ACC wajib mempertahankan kategori item aktual ALKES/AKSESORIS.
      // Jangan fallback kategori item ke header UNIT_ACC.
      return [
        'product_id'=>(int)($it['product_id'] ?? 0),
        'sku'=>up($it['sku'] ?? ''),
        'name'=>up($it['name'] ?? ''),
        'qty'=>(float)($it['qty'] ?? 0),
        'unit'=>(string)($it['unit'] ?? ''),
        'business_group'=>$bg,
        'category'=>$cat,
      ];
    }, $items),
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
