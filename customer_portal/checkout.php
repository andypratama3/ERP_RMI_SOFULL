<?php
/**
 * customer_portal/checkout.php
 * Submit order → create sales_do.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_portal_login();

$pdo = rmi_db_pdo();
$user = portal_user();
$base = portal_base();

$cart = $_SESSION['portal_cart'] ?? [];
if (!is_array($cart)) $cart = [];

// Default alamat dari master_customers
$defaultAddr = '';
$defaultPic = $user['full_name'];
$defaultPhone = '';
$st = $pdo->prepare("SELECT address, name, phone FROM master_customers WHERE customers_code = ? LIMIT 1");
$st->execute([$user['customers_code']]);
$cRow = $st->fetch(PDO::FETCH_ASSOC);
if ($cRow) {
    $defaultAddr = (string)($cRow['address'] ?? '');
    if (empty($defaultPic)) $defaultPic = (string)($cRow['name'] ?? '');
    $defaultPhone = (string)($cRow['phone'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    }

    $shipping_address = trim((string)($_POST['shipping_address'] ?? ''));
    $customer_pic = trim((string)($_POST['customer_pic'] ?? ''));
    $customer_phone = trim((string)($_POST['customer_phone'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $office_code = strtoupper(trim((string)($_POST['office_code'] ?? '')));

    $errors = [];
    if (empty($cart)) {
        $errors[] = 'Keranjang kosong.';
    }
    if ($shipping_address === '') {
        $errors[] = 'Alamat pengiriman wajib diisi.';
    }

    if (empty($errors)) {
        $customers_code = $user['customers_code'];
        if ($office_code === '') {
            $office_code = $user['office_code'];
            if ($office_code === '') {
                $stO = $pdo->prepare("SELECT office_code FROM master_customers WHERE customers_code = ? LIMIT 1");
                $stO->execute([$customers_code]);
                $oRow = $stO->fetch();
                $office_code = $oRow ? strtoupper(trim((string)($oRow['office_code'] ?? 'BGR'))) : 'BGR';
            }
        }
        // Validasi office ada di master_office (exclude HO)
        $validOffices = [];
        try {
            $stOff = $pdo->query("SELECT office_code FROM master_office WHERE office_code IS NOT NULL AND office_code != '' AND office_code != 'HO' AND (is_active=1 OR is_active IS NULL)");
            while ($r = $stOff->fetch(PDO::FETCH_ASSOC)) $validOffices[] = strtoupper(trim($r['office_code']));
        } catch (Throwable $e) {}
        if (!empty($validOffices) && !in_array($office_code, $validOffices, true)) {
            $office_code = $validOffices[0] ?? 'BGR';
        }

        $do_date = date('Y-m-d');
        $dateYmd6 = date('ymd', strtotime($do_date));
        $prefix = 'RMI-' . strtoupper($office_code) . '-' . $dateYmd6 . '-';
        $stCode = $pdo->prepare("SELECT do_code FROM sales_do WHERE do_code LIKE ? ORDER BY do_code DESC LIMIT 1");
        $stCode->execute([$prefix . '%']);
        $last = $stCode->fetchColumn();
        $nextNo = 1;
        if ($last) {
            $nextNo = (int)substr($last, -3) + 1;
        }
        $do_code = $prefix . str_pad((string)$nextNo, 3, '0', STR_PAD_LEFT);

        $validItems = [];
        $totalAmount = 0;
        $lineNo = 0;
        $productsMap = [];
        $stP = $pdo->query("SELECT id, sku, products_name, unit, barcode FROM master_products WHERE status='active'");
        while ($r = $stP->fetch(PDO::FETCH_ASSOC)) {
            $productsMap[$r['id']] = $r;
        }

        foreach ($cart as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            $prod = $productsMap[$pid] ?? null;
            if (!$prod) continue;
            $lineNo++;
            $price = (float)($it['unit_price'] ?? 0);
            $subtotal = $qty * $price;
            $totalAmount += $subtotal;
            $validItems[] = [
                'line_no' => $lineNo,
                'product_id' => $pid,
                'sku' => strtoupper(trim($prod['sku'])),
                'products_name' => strtoupper(trim($prod['products_name'])),
                'qty' => $qty,
                'unit' => $prod['unit'] ?? 'unit',
                'unit_price' => $price,
                'disc_percent' => 0,
                'subtotal' => $subtotal,
                'barcode' => $prod['barcode'] ?? null,
                'stock_at_crm' => null,
                'show_package_items' => 0,
                'exp_date' => null,
                'serial_lot' => null,
            ];
        }

        if (empty($validItems)) {
            $errors[] = 'Tidak ada item valid di keranjang.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                $status = 'crm_to_wqs';
                $tax_code = '';
                $tax_rate = 0;
                $tax_amount = 0;
                $grand_total = $totalAmount;
                $now = date('Y-m-d H:i:s');

                $hasSource = false;
                $hasPortalUserId = false;
                try {
                    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales_do' AND column_name='source'");
                    if ($st && (int)$st->fetchColumn() > 0) $hasSource = true;
                    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales_do' AND column_name='portal_user_id'");
                    if ($st && (int)$st->fetchColumn() > 0) $hasPortalUserId = true;
                } catch (Throwable $e) {}
                $cols = 'do_code, tracking_code, do_date, customers_code, office_code, sales_emp_code, shipping_address, customer_pic, customer_phone, status, note, total_amount, tax_code, tax_rate_percent, tax_amount, grand_total, is_price_include_tax, crm_created_at, crm_start_at, crm_finish_at, crm_duration_sec, created_at, updated_at';
                $vals = '?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 0, NOW(), NOW()';
                $params = [$do_code, $do_code, $do_date, $customers_code, $office_code, $shipping_address, $customer_pic, $customer_phone, $status, $note, $totalAmount, $tax_code, $tax_rate, $tax_amount, $grand_total, $now, $now, $now];
                if ($hasSource) { $cols .= ', source'; $vals .= ', ?'; $params[] = 'portal'; }
                if ($hasPortalUserId) { $cols .= ', portal_user_id'; $vals .= ', ?'; $params[] = $user['id']; }
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

                if (function_exists('log_sales_do_audit')) {
                    log_sales_do_audit($pdo, $do_id, 'NEW', $status, 'PORTAL', $user['username'], 'CREATE_DO');
                } else {
                    try {
                        $pdo->prepare("
                            INSERT INTO sales_do_audit (do_id, status_from, status_to, actor_dept, actor_name, note, created_at)
                            VALUES (?, 'NEW', ?, 'PORTAL', ?, 'CREATE_DO', NOW())
                        ")->execute([$do_id, $status, $user['username']]);
                    } catch (Throwable $e) {
                        // fail-soft
                    }
                }

                if (function_exists('master_audit')) {
                    $_SESSION['username'] = $_SESSION['username'] ?? 'portal_' . ($user['username'] ?? $user['full_name'] ?? '');
                    master_audit($pdo, 'customer_portal', 'sales_do', 'CREATE_DO', $do_id, $do_code, 'Order created via portal', ['customers_code' => $customers_code, 'total' => $totalAmount]);
                }

                $pdo->commit();
                $_SESSION['portal_cart'] = [];

                // Notifikasi email ke CRM (multi-email: system_config → CUSTOMER_PORTAL_CRM_EMAIL env → ALERT_EMAIL_TO)
                $crmEmails = [];
                try {
                    $st = $pdo->prepare("SELECT config_value FROM system_config WHERE config_group='CUSTOMER_PORTAL' AND config_key='CRM_EMAIL' AND is_active=1 LIMIT 1");
                    $st->execute();
                    $v = $st->fetchColumn();
                    if ($v !== false && trim((string)$v) !== '') {
                        $crmEmails = array_filter(array_map('trim', explode(',', (string)$v)));
                        $crmEmails = array_values(array_filter($crmEmails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
                    }
                } catch (Throwable $e) {}
                if (empty($crmEmails)) {
                    $env = trim((string)(getenv('CUSTOMER_PORTAL_CRM_EMAIL') ?: getenv('ALERT_EMAIL_TO') ?: ''));
                    if ($env !== '') {
                        $crmEmails = array_values(array_filter(array_map('trim', explode(',', $env)), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
                    }
                }
                if (!empty($crmEmails) && trim((string)(getenv('ENABLE_EMAIL_ALERTS') ?: '0')) === '1') {
                    $appUrl = function_exists('rmi_env') ? rmi_env('APP_URL') : (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                    $doUrl = rtrim($appUrl, '/') . '/sales/sales_do_view.php?id=' . $do_id;
                    $subject = '[RMI Portal] Order baru: ' . $do_code;
                    $body = "Customer " . $customers_code . " membuat order via portal.\n\nDO: " . $do_code . "\nTotal: Rp " . number_format($grand_total, 0, ',', '.') . "\n\nLihat: " . $doUrl;
                    foreach ($crmEmails as $to) {
                        @mail($to, $subject, $body, 'Content-Type: text/plain; charset=UTF-8');
                    }
                }

                rmi_redirect($base . '/customer_portal/order_detail.php?id=' . $do_id . '&created=1');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Gagal membuat order: ' . $e->getMessage();
            }
        }
    }

    if (!empty($errors)) {
        $flash = ['type' => 'danger', 'msg' => implode('<br>', $errors)];
    }
}

if (empty($cart)) {
    rmi_redirect($base . '/customer_portal/cart.php');
}

// Daftar office untuk dropdown (multi-office)
$officeList = [];
$defaultOffice = $user['office_code'] ?: '';
if ($defaultOffice === '') {
    $stO = $pdo->prepare("SELECT office_code FROM master_customers WHERE customers_code = ? LIMIT 1");
    $stO->execute([$user['customers_code']]);
    $oRow = $stO->fetch();
    $defaultOffice = $oRow ? strtoupper(trim((string)($oRow['office_code'] ?? 'BGR'))) : 'BGR';
}
try {
    $stOff = $pdo->query("SELECT office_code, office_name FROM master_office WHERE office_code IS NOT NULL AND office_code != '' AND office_code != 'HO' AND (is_active=1 OR is_active IS NULL) ORDER BY office_name");
    $officeList = $stOff->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$pageTitle = 'Checkout';
$csrf  = function_exists('csrf_token') ? csrf_token() : '';
$total = array_sum(array_map(fn($i) => ($i['qty']??0)*($i['unit_price']??0), $cart));
ob_start();
?>

<!-- Back link -->
<div class="mb-3">
  <a href="<?= rmi_h($base) ?>/customer_portal/cart.php" style="color:#16a34a;text-decoration:none;font-size:14px">← Kembali ke Keranjang</a>
</div>

<h4 class="fw-bold mb-4">✅ Konfirmasi & Checkout</h4>

<?php if (!empty($flash)): ?>
  <div class="alert cp-alert alert-<?= rmi_h($flash['type']??'danger') ?> mb-4"><?= $flash['msg']??'' ?></div>
<?php endif; ?>

<div class="row g-4">

  <!-- Form -->
  <div class="col-lg-7">
    <form method="post" id="checkoutForm">
      <input type="hidden" name="csrf_token" value="<?= rmi_h($csrf) ?>">

      <!-- Office -->
      <?php if (count($officeList) > 1): ?>
      <div class="cp-card mb-3">
        <div class="cp-card-header">🏢 Kantor RMI Tujuan</div>
        <div class="p-3">
          <select name="office_code" class="form-select">
            <?php foreach ($officeList as $o):
              $oc = strtoupper(trim($o['office_code']??''));
            ?>
              <option value="<?= rmi_h($oc) ?>" <?= ($_POST['office_code']??$defaultOffice)===$oc?'selected':'' ?>>
                <?= rmi_h($o['office_name']??$oc) ?> (<?= rmi_h($oc) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted mt-1" style="font-size:12px">Pilih kantor RMI yang akan memproses order ini</div>
        </div>
      </div>
      <?php else: ?>
        <input type="hidden" name="office_code" value="<?= rmi_h($defaultOffice) ?>">
      <?php endif; ?>

      <!-- Pengiriman -->
      <div class="cp-card mb-3">
        <div class="cp-card-header">📍 Info Pengiriman</div>
        <div class="p-3">
          <div class="mb-3">
            <label class="form-label fw-semibold" style="font-size:13px">Alamat Pengiriman <span class="text-danger">*</span></label>
            <textarea name="shipping_address" class="form-control" rows="3" required
                      placeholder="Masukkan alamat lengkap pengiriman..."
                      style="border-radius:10px;border:2px solid #e2e8f0;font-size:14px"><?= rmi_h($_POST['shipping_address'] ?? $defaultAddr) ?></textarea>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold" style="font-size:13px">PIC / Nama Pemesan</label>
              <input type="text" name="customer_pic" class="form-control"
                     style="border-radius:10px;border:2px solid #e2e8f0;font-size:14px"
                     placeholder="Nama PIC..."
                     value="<?= rmi_h($_POST['customer_pic'] ?? $defaultPic) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold" style="font-size:13px">No. Telepon / WA</label>
              <input type="text" name="customer_phone" class="form-control"
                     style="border-radius:10px;border:2px solid #e2e8f0;font-size:14px"
                     placeholder="08xx..."
                     value="<?= rmi_h($_POST['customer_phone'] ?? $defaultPhone) ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Catatan -->
      <div class="cp-card mb-3">
        <div class="cp-card-header">📝 Catatan (Opsional)</div>
        <div class="p-3">
          <textarea name="note" class="form-control" rows="2"
                    style="border-radius:10px;border:2px solid #e2e8f0;font-size:14px"
                    placeholder="Catatan khusus untuk tim CRM RMI..."><?= rmi_h($_POST['note'] ?? '') ?></textarea>
        </div>
      </div>

      <!-- Submit -->
      <button type="submit" class="btn btn-rmi w-100 py-3 fw-bold" style="font-size:16px">
        🛒 Buat Order Sekarang
      </button>
      <div class="text-center text-muted mt-2" style="font-size:12px">
        Dengan menekan tombol di atas, order akan dikirim ke tim CRM RMI untuk diproses
      </div>
    </form>
  </div>

  <!-- Order Summary -->
  <div class="col-lg-5">
    <div class="cp-card" style="position:sticky;top:20px">
      <div class="cp-card-header">📋 Ringkasan Order</div>
      <div class="p-3">
        <!-- Items -->
        <div class="mb-3" style="max-height:280px;overflow-y:auto">
          <?php foreach ($cart as $it): $sub = ($it['qty']??0)*($it['unit_price']??0); ?>
          <div class="d-flex gap-2 mb-2 pb-2" style="border-bottom:1px solid #f1f5f9">
            <div style="font-size:16px;flex-shrink:0">📦</div>
            <div class="flex-grow-1 min-width-0">
              <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= rmi_h($it['name']??'') ?></div>
              <div style="font-size:11px;color:#94a3b8"><?= (int)($it['qty']??0) ?> <?= rmi_h($it['unit']??'unit') ?> × Rp <?= number_format((float)($it['unit_price']??0),0,',','.') ?></div>
            </div>
            <div style="font-size:13px;font-weight:600;color:#16a34a;flex-shrink:0">Rp <?= number_format($sub,0,',','.') ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Total -->
        <div style="background:#f0fdf4;border-radius:10px;padding:14px">
          <div class="d-flex justify-content-between mb-1" style="font-size:13px">
            <span class="text-muted">Subtotal (<?= count($cart) ?> produk)</span>
            <span>Rp <?= number_format($total,0,',','.') ?></span>
          </div>
          <div class="d-flex justify-content-between mb-1" style="font-size:13px">
            <span class="text-muted">Ongkos Kirim</span>
            <span class="text-muted">Diinformasikan CRM</span>
          </div>
          <hr style="border-color:#dcfce7;margin:10px 0">
          <div class="d-flex justify-content-between">
            <span class="fw-bold">Total Order</span>
            <span class="fw-bold" style="font-size:18px;color:#16a34a">Rp <?= number_format($total,0,',','.') ?></span>
          </div>
        </div>

        <!-- Info -->
        <div class="mt-3 p-3" style="background:#eff6ff;border-radius:10px;font-size:12px;color:#1e40af">
          ℹ️ <strong>Proses Selanjutnya:</strong><br>
          Tim CRM RMI akan menghubungi Anda untuk konfirmasi order dan informasi pengiriman.
        </div>
      </div>
    </div>
  </div>

</div>

<?php
$content = ob_get_clean();
$flash = $flash ?? [];
require __DIR__ . '/layout.php';
