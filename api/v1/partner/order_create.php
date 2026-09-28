<?php
/**
 * api/v1/partner/order_create.php
 * H2H: Terima order (PO) dari partner eksternal (Hermina, dll).
 * Auth: X-API-Key atau Authorization: Bearer <key>
 * Scope: order:create (atau kosong = allow all)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_lib/partner_auth.php';
require_once __DIR__ . '/../../../_shared/bootstrap.php';
require_once __DIR__ . '/../../../_shared/db.php';
require_once __DIR__ . '/../../../master/_audit_master.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'code' => 'ERR_METHOD', 'message' => 'Method not allowed']);
    exit;
}

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
$key = partner_api_key_from_request();

if ($key === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'ERR_MISSING_API_KEY', 'message' => 'X-API-Key or Authorization: Bearer required']);
    exit;
}

$partner = partner_api_key_validate($pdo, $key);
if ($partner === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'ERR_INVALID_API_KEY', 'message' => 'Invalid or inactive API key']);
    exit;
}

if (function_exists('partner_has_scope') && $partner['scopes'] !== '' && !partner_has_scope($partner, 'order:create')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'code' => 'ERR_SCOPE', 'message' => 'Scope order:create required']);
    exit;
}

partner_update_last_used($pdo, $partner['id']);

$raw = file_get_contents('php://input');
$payload = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'code' => 'ERR_INVALID_JSON', 'message' => 'Invalid JSON body']);
    exit;
}

$customers_code = strtoupper(trim((string)($payload['customers_code'] ?? '')));
$office_code = strtoupper(trim((string)($payload['office_code'] ?? '')));
$do_date_raw = trim((string)($payload['do_date'] ?? date('Y-m-d')));
$customer_pic = trim((string)($payload['customer_pic'] ?? ''));
$customer_phone = trim((string)($payload['customer_phone'] ?? ''));
$shipping_address = trim((string)($payload['shipping_address'] ?? ''));
$note = trim((string)($payload['note'] ?? ''));
$external_order_id = trim((string)($payload['external_order_id'] ?? ''));
$idempotency_key = trim((string)($payload['idempotency_key'] ?? $external_order_id)) ?: null;

if ($idempotency_key !== null && strlen($idempotency_key) > 120) {
    $idempotency_key = substr($idempotency_key, 0, 120);
}

// Idempotency check
if ($idempotency_key !== null && $idempotency_key !== '') {
    try {
        $st = $pdo->prepare("SELECT do_id, do_code FROM api_partner_order_idempotency WHERE partner_id=? AND idempotency_key=? LIMIT 1");
        $st->execute([$partner['id'], $idempotency_key]);
        $cached = $st->fetch(PDO::FETCH_ASSOC);
        if ($cached) {
            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'message' => 'Order already created (idempotent)',
                'do_id' => (int)$cached['do_id'],
                'do_code' => (string)$cached['do_code'],
                'status' => 'crm_to_wqs',
            ]);
            exit;
        }
    } catch (Throwable $e) {
        // table not exist, continue
    }
}

$errors = [];
if ($customers_code === '') $errors[] = 'customers_code required';
if ($office_code === '') $errors[] = 'office_code required';

$do_date = null;
if ($do_date_raw !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $do_date_raw);
    if ($d && $d->format('Y-m-d') === $do_date_raw) {
        $do_date = $do_date_raw;
    } else {
        $errors[] = 'do_date invalid (use Y-m-d)';
    }
} else {
    $do_date = date('Y-m-d');
}

$items = $payload['items'] ?? [];
if (!is_array($items)) $items = [];
if (empty($items)) $errors[] = 'items required (min 1)';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'code' => 'ERR_VALIDATION', 'message' => implode('; ', $errors), 'errors' => $errors]);
    exit;
}

// Validate customer exists
$stCust = $pdo->prepare("SELECT 1 FROM master_customers WHERE customers_code=? AND status='active' LIMIT 1");
$stCust->execute([$customers_code]);
if (!$stCust->fetch()) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'code' => 'ERR_CUSTOMER', 'message' => 'Customer not found or inactive']);
    exit;
}

// Get default address if empty
if ($shipping_address === '') {
    $stAddr = $pdo->prepare("SELECT address FROM master_customers WHERE customers_code=? LIMIT 1");
    $stAddr->execute([$customers_code]);
    $addr = $stAddr->fetch();
    $shipping_address = (string)($addr['address'] ?? '');
}

// Build pricelist for customer
$pricelist = [];
try {
    $stPl = $pdo->prepare("
        SELECT sku, sell_price FROM master_pricelist
        WHERE (customers_code = ? OR customers_code IS NULL) AND status = 1 AND (deleted_at IS NULL)
        ORDER BY (customers_code = ?) DESC
    ");
    $stPl->execute([$customers_code, $customers_code]);
    while ($r = $stPl->fetch(PDO::FETCH_ASSOC)) {
        $sku = strtoupper(trim((string)($r['sku'] ?? '')));
        if ($sku !== '' && !isset($pricelist[$sku])) $pricelist[$sku] = (float)$r['sell_price'];
    }
} catch (Throwable $e) {}

// Products map
$productsMap = [];
$stP = $pdo->query("SELECT id, sku, products_name, unit, price, barcode FROM master_products WHERE status='active'");
while ($r = $stP->fetch(PDO::FETCH_ASSOC)) {
    $sku = strtoupper(trim((string)($r['sku'] ?? '')));
    if ($sku !== '') $productsMap[$sku] = $r;
}

$validItems = [];
$lineNo = 0;
$totalAmount = 0;
foreach ($items as $it) {
    $sku = strtoupper(trim((string)($it['sku'] ?? '')));
    $qty = (float)($it['qty'] ?? 0);
    $unit = trim((string)($it['unit'] ?? 'pcs'));
    if ($unit === '') $unit = 'pcs';

    if ($sku === '' || $qty <= 0) continue;

    $prod = $productsMap[$sku] ?? null;
    if (!$prod) {
        $errors[] = "SKU not found: {$sku}";
        continue;
    }

    $price = isset($pricelist[$sku]) ? $pricelist[$sku] : (float)$prod['price'];
    $subtotal = $qty * $price;
    $lineNo++;

    $validItems[] = [
        'line_no' => $lineNo,
        'product_id' => (int)$prod['id'],
        'sku' => $prod['sku'],
        'products_name' => (string)$prod['products_name'],
        'qty' => $qty,
        'unit' => $unit,
        'unit_price' => $price,
        'disc_percent' => 0,
        'subtotal' => $subtotal,
        'barcode' => $prod['barcode'] ?? null,
        'stock_at_crm' => null,
        'show_package_items' => 0,
        'exp_date' => null,
        'serial_lot' => null,
    ];
    $totalAmount += $subtotal;
}

if ($lineNo === 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'code' => 'ERR_ITEMS', 'message' => 'No valid items', 'errors' => $errors]);
    exit;
}

// Append external_order_id to note if provided
if ($external_order_id !== '') {
    $note = $note === '' ? "H2H PO: {$external_order_id}" : $note . " [H2H: {$external_order_id}]";
}

// Generate DO code
$dateYmd6 = date('ymd', strtotime($do_date));
$prefix = 'RMI-' . $office_code . '-' . $dateYmd6 . '-';
$stCode = $pdo->prepare("SELECT do_code FROM sales_do WHERE do_code LIKE ? ORDER BY do_code DESC LIMIT 1");
$stCode->execute([$prefix . '%']);
$last = $stCode->fetchColumn();
$nextNo = $last ? ((int)substr($last, -3) + 1) : 1;
$do_code = $prefix . str_pad((string)$nextNo, 3, '0', STR_PAD_LEFT);

$status = 'crm_to_wqs';
$tax_code = '';
$tax_rate = 0;
$tax_amount = 0;
$grand_total = $totalAmount;
$now = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

    $hasSource = false;
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales_do' AND column_name='source'");
        if ($st && (int)$st->fetchColumn() > 0) $hasSource = true;
    } catch (Throwable $e) {}

    $cols = 'do_code, tracking_code, do_date, customers_code, office_code, sales_emp_code, shipping_address, customer_pic, customer_phone, status, note, total_amount, tax_code, tax_rate_percent, tax_amount, grand_total, is_price_include_tax, crm_created_at, crm_start_at, crm_finish_at, crm_duration_sec, created_at, updated_at';
    $vals = '?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 0, NOW(), NOW()';
    $params = [$do_code, $do_code, $do_date, $customers_code, $office_code, $shipping_address, $customer_pic, $customer_phone, $status, $note, $totalAmount, $tax_code, $tax_rate, $tax_amount, $grand_total, $now, $now, $now];

    if ($hasSource) {
        $cols .= ', source';
        $vals .= ', ?';
        $params[] = 'h2h';
    }

    $stIns = $pdo->prepare("INSERT INTO sales_do ($cols) VALUES ($vals)");
    $stIns->execute($params);
    $do_id = (int)$pdo->lastInsertId();

    $stItem = $pdo->prepare("
        INSERT INTO sales_do_items
        (do_id, line_no, product_id, sku, products_name, qty, unit, exp_date, serial_lot,
         unit_price, disc_percent, subtotal, barcode, stock_at_crm, show_package_items)
        VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, NULL, ?)
    ");

    foreach ($validItems as $it) {
        $stItem->execute([
            $do_id, $it['line_no'], $it['product_id'], $it['sku'], $it['products_name'],
            $it['qty'], $it['unit'], $it['unit_price'], $it['disc_percent'], $it['subtotal'],
            $it['barcode'], $it['show_package_items'],
        ]);
    }

    try {
        $actor = 'api_partner:' . ($partner['partner_name'] ?? 'unknown');
        $pdo->prepare("INSERT INTO sales_do_audit (do_id, status_from, status_to, actor_dept, actor_name, note, created_at) VALUES (?, 'NEW', ?, 'API', ?, 'CREATE_DO_H2H', NOW())")
            ->execute([$do_id, $status, $actor]);
    } catch (Throwable $e) {}

    if (function_exists('master_audit')) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['username'] = 'api_partner:' . ($partner['partner_name'] ?? $partner['partner_code'] ?? 'unknown');
        master_audit($pdo, 'api_partner', 'sales_do', 'CREATE_DO_H2H', $do_id, $do_code, 'Order created via H2H API', ['customers_code' => $customers_code, 'partner' => $partner['partner_name'] ?? '', 'total' => $totalAmount]);
    }

    if ($idempotency_key !== null && $idempotency_key !== '') {
        try {
            $pdo->prepare("INSERT INTO api_partner_order_idempotency (partner_id, idempotency_key, do_id, do_code, created_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$partner['id'], $idempotency_key, $do_id, $do_code]);
        } catch (Throwable $e) {
            // duplicate key = race, rollback and retry would return cached
        }
    }

    $pdo->commit();

    // Optional webhook: notifikasi ke sistem eksternal (CRM, Slack, dll)
    $webhookUrl = (string)(function_exists('rmi_env') ? rmi_env('H2H_WEBHOOK_URL', '') : (getenv('H2H_WEBHOOK_URL') ?: ''));
    if ($webhookUrl !== '') {
        $webhookPayload = json_encode([
            'event' => 'h2h_order_created',
            'do_id' => $do_id,
            'do_code' => $do_code,
            'status' => $status,
            'total_amount' => $totalAmount,
            'customers_code' => $customers_code,
            'office_code' => $office_code,
            'partner' => $partner['partner_name'] ?? '',
            'external_order_id' => $external_order_id ?: null,
            'created_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $webhookPayload,
                'timeout' => 3,
            ],
        ]);
        @file_get_contents($webhookUrl, false, $ctx);
    }

    // Log untuk monitoring (sesuai HERMINA_HANDOVER_CHECKLIST)
    $logDir = dirname(__DIR__, 3) . '/storage/logs';
    $logFile = $logDir . '/api_partner_h2h.log';
    if (is_dir($logDir) && is_writable($logDir)) {
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (is_string($clientIp) && str_contains($clientIp, ',')) {
            $clientIp = trim(explode(',', $clientIp)[0]);
        }
        $logEntry = json_encode([
            'ts' => date('c'),
            'ip' => $clientIp,
            'event' => 'created',
            'partner' => $partner['partner_name'] ?? 'unknown',
            'do_id' => $do_id,
            'do_code' => $do_code,
            'customers_code' => $customers_code,
            'office_code' => $office_code,
            'total_amount' => $totalAmount,
            'external_order_id' => $external_order_id ?: null,
        ], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    http_response_code(201);
    echo json_encode([
        'ok' => true,
        'message' => 'Order created',
        'do_id' => $do_id,
        'do_code' => $do_code,
        'status' => $status,
        'total_amount' => $totalAmount,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'code' => 'ERR_SERVER', 'message' => 'Failed to create order']);
    if (function_exists('error_log')) {
        error_log('[order_create] ' . $e->getMessage());
    }
}
