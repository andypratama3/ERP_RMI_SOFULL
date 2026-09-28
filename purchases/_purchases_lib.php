<?php
// purchases/_purchases_lib.php (Corrected for Manufactures + PR)
// Enterprise helper: schema ensure, audit, RBAC buy price, code generator

require_once __DIR__ . '/../master/auth.php';

require_login();
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('up')) {
  function up($s){ return strtoupper(trim((string)($s ?? ''))); }
}

function p_pdo(): PDO { return db_pdo(); }

// Role/level auto-detect (best-effort)
function p_role(): string {
  $keys = ['role','user_role','role_name','department','dept','akses'];
  foreach ($keys as $k) { if (!empty($_SESSION[$k])) return up($_SESSION[$k]); }
  return 'ADMIN'; // safe fallback
}
function p_level(): string {
  $keys = ['level','user_level','level_name','jabatan_level','jabatan'];
  foreach ($keys as $k) { if (!empty($_SESSION[$k])) {
    $v = up($_SESSION[$k]);
    if ($v === 'SUPER_ADMIN') return 'SUPERADMIN';
    if ($v === 'ADMINISTRATOR') return 'ADMIN';
    if ($v === 'MGR') return 'MANAGER';
    if ($v === 'STAF') return 'STAFF';
    if (in_array($v, ['SUPERADMIN','ADMIN','MANAGER','STAFF'], true)) return $v;
  } }
  return 'STAFF';
}
function p_username(): string {
  $keys = ['username','user_name','name','email','user_email'];
  foreach ($keys as $k) { if (!empty($_SESSION[$k])) return (string)$_SESSION[$k]; }
  return 'unknown';
}

function p_level_rank(?string $lvl=null): int {
  $lvl = $lvl ? up($lvl) : p_level();
  return match($lvl) {
    'SUPERADMIN' => 4,
    'ADMIN' => 3,
    'MANAGER' => 2,
    'STAFF' => 1,
    default => 1,
  };
}
function p_is_admin_plus(): bool { return p_level_rank() >= 3; }
function p_is_manager_plus(): bool { return p_level_rank() >= 2; }

function p_can_view_buy_price(): bool {
  // Explicit rule: only FIN, PQP, SYS can know buy price (+ Admin/SuperAdmin)
  if (function_exists('can') && can('MASTER.PRICELIST_BUY_CRUD')) return true;
  if (p_is_admin_plus()) return true;
  return in_array(p_role(), ['FIN','PQP','SYS'], true);
}
function p_can_create_pr(): bool {
  // WQS creates PR (and Admin/SuperAdmin)
  if (function_exists('can') && can('WQS.PR_CRUD')) return true;
  return p_is_admin_plus() || in_array(p_role(), ['WQS','SYS'], true);
}
function p_can_create_po(): bool {
  // PQP owns PO (and Admin/SuperAdmin)
  if (function_exists('can') && can('PURCHASES.PO_CRUD')) return true;
  return p_is_admin_plus() || in_array(p_role(), ['PQP','SYS'], true);
}
function p_can_fin_ops(): bool {
  if (function_exists('can_any') && can_any(['PURCHASES.AP_INVOICE_CRUD','PURCHASES.AP_PAYMENT_CRUD'])) return true;
  return p_is_admin_plus() || in_array(p_role(), ['FIN','ACT','SYS'], true);
}

/**
 * Cek apakah user boleh APPROVE / CREATE pembayaran (bukan hanya lihat).
 * Hanya Manager FIN, ADMIN, SUPERADMIN, SYS.
 * ACT hanya boleh view/rekonsiliasi — tidak boleh buat payment baru.
 */
function p_can_approve_payment(): bool {
  if (function_exists('can_any') && can_any(['PURCHASES.AP_PAYMENT_CRUD'])) return true;
  if (function_exists('auth_is_fin_manager') && auth_is_fin_manager()) return true;
  return p_is_admin_plus();
}

function p_can_scm_ops(): bool {
  if (function_exists('can') && can('PURCHASES.FORWARDING_CRUD')) return true;
  // SCM owns forwarding/shipments (and Admin/SuperAdmin/Owner/Manager for monitoring)
  if (p_is_admin_plus()) return true;
  $allow = ['SCM','SYS','MANAGER'];
  $r = p_role(); $l = p_level();
  return in_array($r, $allow, true) || in_array($l, $allow, true);
}


/**
 * Canonical procurement flow for PO.
 *
 * Rule:
 * 1. Explicit [FLOW:LOCAL] / [FLOW:IMPORT] in PO note is authoritative.
 * 2. Legacy PO without marker may be inferred as IMPORT only if import evidence exists.
 * 3. Legacy PO without import evidence defaults to LOCAL.
 *
 * IMPORTANT:
 * Explicit LOCAL must never be promoted to IMPORT merely because a downstream
 * import-like record accidentally exists.
 */
function p_po_explicit_flow(?string $note): ?string {
  $note = strtoupper((string)$note);
  if (strpos($note, '[FLOW:LOCAL]') !== false) return 'LOCAL';
  if (strpos($note, '[FLOW:IMPORT]') !== false) return 'IMPORT';
  return null;
}

function p_po_has_import_evidence(array $po): bool {
  foreach ([
    'has_import_evidence',
    'import_control_id',
    'ceisa_pib_id',
    'forwarding_docs_count',
    'forwarding_doc_count',
  ] as $k) {
    if (!empty($po[$k]) && (int)$po[$k] > 0) return true;
  }

  // Forwarder on a legacy PO is considered import evidence.
  // Explicit [FLOW:LOCAL] is checked first by p_po_flow(), so LOCAL stays LOCAL.
  if (!empty($po['forwarder_vendor_id'])) return true;

  return false;
}

function p_po_flow(array $po): string {
  $explicit = p_po_explicit_flow((string)($po['note'] ?? ''));
  if ($explicit !== null) return $explicit;
  return p_po_has_import_evidence($po) ? 'IMPORT' : 'LOCAL';
}

function p_po_flow_source(array $po): string {
  $explicit = p_po_explicit_flow((string)($po['note'] ?? ''));
  if ($explicit !== null) return 'EXPLICIT';
  return p_po_has_import_evidence($po) ? 'LEGACY_INFERRED' : 'LEGACY_DEFAULT';
}

function p_po_is_import(array $po): bool {
  return p_po_flow($po) === 'IMPORT';
}

function p_po_is_local(array $po): bool {
  return p_po_flow($po) === 'LOCAL';
}


// Flash
function p_flash_set(string $type, string $msg): void {
  $_SESSION['_p_flash'] = ['type'=>$type,'msg'=>$msg,'ts'=>time()];
}
function p_flash_get(): ?array {
  if (empty($_SESSION['_p_flash'])) return null;
  $f = $_SESSION['_p_flash']; unset($_SESSION['_p_flash']);
  return $f;
}

function p_has_table(PDO $pdo, string $table): bool {
  try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; } catch (Throwable $e) { return false; }
}
function p_cols(PDO $pdo, string $table): array {
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `$table`");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r)=>$r['Field'], $rows);
  } catch (Throwable $e) { return []; }
}
function p_ensure_col(PDO $pdo, string $table, string $col, string $definition): void {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $st->execute([$table, $col]);
    if ((int)$st->fetchColumn() === 0) $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
  } catch (Throwable $e) {}
}
function p_try_index(PDO $pdo, string $sql): void { try { $pdo->exec($sql); } catch (Throwable $e) {} }

function p_generate_code(PDO $pdo, string $table, string $col, string $prefix): string {
  $st = $pdo->prepare("SELECT `$col` FROM `$table` WHERE `$col` LIKE ? ORDER BY `$col` DESC LIMIT 1");
  $st->execute([$prefix.'%']);
  $last = (string)($st->fetchColumn() ?: '');
  $next = 1;
  if ($last !== '') {
    $seq = (int)substr($last, -3);
    $next = $seq + 1;
  }
  return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function p_money($n, string $cur='IDR'): string {
  if ($n === null || $n === '') $n = 0;
  $n = (float)$n;
  if ($cur === 'IDR') return number_format($n, 0, ',', '.');
  return number_format($n, 2, ',', '.');
}

function p_ensure_schema(PDO $pdo): void {
  // --- WQS PR (request) ---
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `wqs_pr` (
      `id` int NOT NULL AUTO_INCREMENT,
      `pr_code` varchar(60) NOT NULL,
      `pr_date` date NOT NULL,
      `office_code` varchar(20) DEFAULT NULL,
      `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
      `note` text,
      `created_by` varchar(100) DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `deleted_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  p_try_index($pdo, "ALTER TABLE `wqs_pr` ADD UNIQUE KEY `uq_pr_code` (`pr_code`)");
  p_ensure_col($pdo, 'wqs_pr', 'category', "category VARCHAR(20) NOT NULL DEFAULT 'BMHP'");
  p_ensure_col($pdo, 'wqs_pr_items', 'category', "category VARCHAR(20) NOT NULL DEFAULT 'BMHP'");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `wqs_pr_items` (
      `id` int NOT NULL AUTO_INCREMENT,
      `pr_id` int NOT NULL,
      `line_no` int NOT NULL DEFAULT 1,
      `product_id` int DEFAULT NULL,
      `sku` varchar(50) DEFAULT NULL,
      `products_name` varchar(255) DEFAULT NULL,
      `qty` decimal(18,2) NOT NULL DEFAULT 0,
      `unit` varchar(30) DEFAULT NULL,
      `deleted_at` datetime DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_pr` (`pr_id`),
      KEY `idx_product` (`product_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // --- Purchases PO (product procurement, supplier=manufacture) ---
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `purchases_po` (
      `id` int NOT NULL AUTO_INCREMENT,
      `po_code` varchar(60) NOT NULL,
      `po_date` date NOT NULL,
      `pr_id` int DEFAULT NULL,
      `manufacture_id` int DEFAULT NULL,
      `office_code` varchar(20) DEFAULT NULL,
      `currency` varchar(10) NOT NULL DEFAULT 'IDR',
      `payment_term` varchar(30) DEFAULT NULL,
      `note` text,
      `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
      `total_amount` decimal(18,2) NOT NULL DEFAULT 0,
      `created_by` varchar(100) DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `deleted_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  p_try_index($pdo, "ALTER TABLE `purchases_po` ADD UNIQUE KEY `uq_po_code` (`po_code`)");
  p_ensure_col($pdo, 'purchases_po', 'category', "category VARCHAR(20) NOT NULL DEFAULT 'BMHP'");

  // Ensure columns if table existed from old patch
  p_ensure_col($pdo,'purchases_po','pr_id'," `pr_id` int DEFAULT NULL AFTER `po_date`");
  p_ensure_col($pdo,'purchases_po','manufacture_id'," `manufacture_id` int DEFAULT NULL AFTER `pr_id`");
  p_ensure_col($pdo,'purchases_po','total_amount'," `total_amount` decimal(18,2) NOT NULL DEFAULT 0 AFTER `status`");
  p_ensure_col($pdo,'purchases_po','created_by'," `created_by` varchar(100) DEFAULT NULL AFTER `total_amount`");
  p_ensure_col($pdo,'purchases_po','deleted_at'," `deleted_at` datetime DEFAULT NULL AFTER `updated_at`");

  // Forwarding (SCM) - info dari pabrik + vendor forwarder lokal (master_vendors)
  p_ensure_col($pdo,'purchases_po','factory_forwarding_info'," `factory_forwarding_info` text DEFAULT NULL AFTER `manufacture_id`");
  p_ensure_col($pdo,'purchases_po','forwarder_vendor_id'," `forwarder_vendor_id` int DEFAULT NULL AFTER `factory_forwarding_info`");
  p_ensure_col($pdo,'purchases_po','forwarder_status'," `forwarder_status` varchar(30) NOT NULL DEFAULT 'PENDING' AFTER `forwarder_vendor_id`");
  p_ensure_col($pdo,'purchases_po','forwarder_note'," `forwarder_note` text DEFAULT NULL AFTER `forwarder_status`");


  // Purchases PO items
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `purchases_po_items` (
      `id` int NOT NULL AUTO_INCREMENT,
      `po_id` int NOT NULL,
      `line_no` int NOT NULL DEFAULT 1,
      `product_id` int DEFAULT NULL,
      `sku` varchar(50) DEFAULT NULL,
      `products_name` varchar(255) DEFAULT NULL,
      `qty` decimal(18,2) NOT NULL DEFAULT 0,
      `unit` varchar(30) DEFAULT NULL,
      `unit_price` decimal(18,2) NOT NULL DEFAULT 0,
      `subtotal` decimal(18,2) NOT NULL DEFAULT 0,
      `deleted_at` datetime DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_po` (`po_id`),
      KEY `idx_product` (`product_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  p_ensure_col($pdo,'purchases_po_items','deleted_at'," `deleted_at` datetime DEFAULT NULL AFTER `subtotal`");
  p_ensure_col($pdo, 'purchases_po_items', 'discount_percent', "`discount_percent` decimal(9,2) NOT NULL DEFAULT 0 AFTER `unit_price`");
p_ensure_col($pdo, 'purchases_po_items', 'discount_amount', "`discount_amount` decimal(18,2) NOT NULL DEFAULT 0 AFTER `discount_percent`");
p_ensure_col($pdo, 'purchases_po_items', 'ppn_percent', "`ppn_percent` decimal(9,2) NOT NULL DEFAULT 11 AFTER `discount_amount`");
p_ensure_col($pdo, 'purchases_po_items', 'ppn_amount', "`ppn_amount` decimal(18,2) NOT NULL DEFAULT 0 AFTER `ppn_percent`");
p_ensure_col($pdo, 'purchases_po_items', 'total_after_tax', "`total_after_tax` decimal(18,2) NOT NULL DEFAULT 0 AFTER `subtotal`");

  // Invoice AP (manufacture)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `purchases_invoice_ap` (
      `id` int NOT NULL AUTO_INCREMENT,
      `ap_code` varchar(60) NOT NULL,
      `invoice_type` varchar(30) NOT NULL DEFAULT 'PROFORMA',
      `invoice_number` varchar(80) DEFAULT NULL,
      `invoice_date` date DEFAULT NULL,
      `due_date` date DEFAULT NULL,
      `manufacture_id` int DEFAULT NULL,
      `office_code` varchar(20) DEFAULT NULL,
      `po_id` int DEFAULT NULL,
      `currency` varchar(10) NOT NULL DEFAULT 'IDR',
      `subtotal` decimal(18,2) NOT NULL DEFAULT 0,
      `tax_percent` decimal(6,2) NOT NULL DEFAULT 0,
      `tax_amount` decimal(18,2) NOT NULL DEFAULT 0,
      `total_amount` decimal(18,2) NOT NULL DEFAULT 0,
      `status` varchar(30) NOT NULL DEFAULT 'UNPAID',
      `note` text,
      `doc_path` varchar(255) DEFAULT NULL,
      `created_by` varchar(100) DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `deleted_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  p_try_index($pdo, "ALTER TABLE `purchases_invoice_ap` ADD UNIQUE KEY `uq_ap_code` (`ap_code`)");
  // if old column vendor_id exists, keep; if not, ok
  p_ensure_col($pdo,'purchases_invoice_ap','invoice_type'," `invoice_type` varchar(30) NOT NULL DEFAULT 'PROFORMA' AFTER `ap_code`");
  p_ensure_col($pdo,'purchases_invoice_ap','manufacture_id'," `manufacture_id` int DEFAULT NULL AFTER `due_date`");

  // Payment AP
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `purchases_payment_ap` (
      `id` int NOT NULL AUTO_INCREMENT,
      `pay_code` varchar(60) NOT NULL,
      `ap_id` int NOT NULL,
      `pay_date` date NOT NULL,
      `amount` decimal(18,2) NOT NULL DEFAULT 0,
      `method` varchar(30) DEFAULT NULL,
      `bank_name` varchar(120) DEFAULT NULL,
      `reference` varchar(120) DEFAULT NULL,
      `note` text,
      `doc_path` varchar(255) DEFAULT NULL,
      `created_by` varchar(100) DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `deleted_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_ap` (`ap_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  p_try_index($pdo, "ALTER TABLE `purchases_payment_ap` ADD UNIQUE KEY `uq_pay_code` (`pay_code`)");

  // Audit log
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `purchases_audit_log` (
      `id` int NOT NULL AUTO_INCREMENT,
      `module` varchar(20) NOT NULL,
      `ref_code` varchar(80) DEFAULT NULL,
      `action` varchar(50) NOT NULL,
      `details_json` longtext,
      `user_name` varchar(120) DEFAULT NULL,
      `user_role` varchar(50) DEFAULT NULL,
      `user_level` varchar(50) DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_module` (`module`),
      KEY `idx_ref` (`ref_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

// --- Forwarding Docs (SCM/PQP/ACT) ---
// Unified dokumen import per PO (CIPL/BL/Form E/PIB/BC11/NOA/SPPB/...)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS `purchases_forwarding_docs` (
    `id` int NOT NULL AUTO_INCREMENT,
    `po_id` int NOT NULL,
    `doc_type` varchar(30) NOT NULL,
    `doc_number` varchar(80) DEFAULT NULL,
    `doc_date` date DEFAULT NULL,
    `file_path` varchar(255) DEFAULT NULL,
    `note` text,
    `uploaded_by` varchar(100) DEFAULT NULL,
    `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_po` (`po_id`),
    KEY `idx_type` (`doc_type`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
// Backward compatible upgrades
p_ensure_col($pdo,'purchases_forwarding_docs','doc_number'," `doc_number` varchar(80) DEFAULT NULL AFTER `doc_type`");
p_ensure_col($pdo,'purchases_forwarding_docs','doc_date'," `doc_date` date DEFAULT NULL AFTER `doc_number`");
p_ensure_col($pdo,'purchases_forwarding_docs','uploaded_at'," `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `uploaded_by`");
p_ensure_col($pdo,'purchases_forwarding_docs','deleted_at'," `deleted_at` datetime DEFAULT NULL AFTER `uploaded_at`");

// --- Import Control (milestones ringkas per PO) ---
$pdo->exec("
  CREATE TABLE IF NOT EXISTS `purchases_import_control` (
    `id` int NOT NULL AUTO_INCREMENT,
    `po_id` int NOT NULL,
    `production_start_date` date DEFAULT NULL,
    `production_done_date` date DEFAULT NULL,
    `pickup_date` date DEFAULT NULL,
    `etd` date DEFAULT NULL,
    `eta` date DEFAULT NULL,
    `arrived_id_date` date DEFAULT NULL,
    `arrived_warehouse_date` date DEFAULT NULL,
    `note` text,
    `updated_by` varchar(100) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_po` (`po_id`),
    KEY `idx_prod_done` (`production_done_date`),
    KEY `idx_eta` (`eta`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
p_ensure_col($pdo,'purchases_import_control','note_prod',"`note_prod` text DEFAULT NULL AFTER `note`");
p_ensure_col($pdo,'purchases_import_control','note_ship',"`note_ship` text DEFAULT NULL AFTER `note_prod`");

// --- Forwarder Quotes (SCM) ---
$pdo->exec("
  CREATE TABLE IF NOT EXISTS `purchases_forwarder_quotes` (
    `id` int NOT NULL AUTO_INCREMENT,
    `po_id` int NOT NULL,
    `vendor_id` int NOT NULL,
    `quote_date` date NOT NULL,
    `currency` varchar(10) NOT NULL DEFAULT 'IDR',
    `total_cost` decimal(18,2) NOT NULL DEFAULT 0,
    `leadtime_days` int NOT NULL DEFAULT 0,
    `note` text,
    `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
    `created_by` varchar(100) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_po` (`po_id`),
    KEY `idx_vendor` (`vendor_id`),
    KEY `idx_status` (`status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Forwarder Invoice & Payment (FIN) ---
$pdo->exec("
  CREATE TABLE IF NOT EXISTS `purchases_forwarder_invoice` (
    `id` int NOT NULL AUTO_INCREMENT,
    `fap_code` varchar(60) NOT NULL,
    `invoice_type` varchar(30) NOT NULL DEFAULT 'FORWARDER',
    `invoice_number` varchar(80) DEFAULT NULL,
    `invoice_date` date DEFAULT NULL,
    `due_date` date DEFAULT NULL,
    `vendor_id` int DEFAULT NULL,
    `office_code` varchar(20) DEFAULT NULL,
    `po_id` int DEFAULT NULL,
    `currency` varchar(10) NOT NULL DEFAULT 'IDR',
    `subtotal` decimal(18,2) NOT NULL DEFAULT 0,
    `tax_percent` decimal(6,2) NOT NULL DEFAULT 0,
    `tax_amount` decimal(18,2) NOT NULL DEFAULT 0,
    `total_amount` decimal(18,2) NOT NULL DEFAULT 0,
    `status` varchar(30) NOT NULL DEFAULT 'UNPAID',
    `note` text,
    `doc_path` varchar(255) DEFAULT NULL,
    `created_by` varchar(100) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_fap_code` (`fap_code`),
    KEY `idx_vendor` (`vendor_id`),
    KEY `idx_po` (`po_id`),
    KEY `idx_status` (`status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS `purchases_forwarder_payment` (
    `id` int NOT NULL AUTO_INCREMENT,
    `pay_code` varchar(60) NOT NULL,
    `fap_id` int NOT NULL,
    `pay_date` date NOT NULL,
    `amount` decimal(18,2) NOT NULL DEFAULT 0,
    `method` varchar(30) DEFAULT NULL,
    `bank_name` varchar(120) DEFAULT NULL,
    `reference` varchar(120) DEFAULT NULL,
    `note` text,
    `doc_path` varchar(255) DEFAULT NULL,
    `created_by` varchar(100) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_fpay_code` (`pay_code`),
    KEY `idx_fap` (`fap_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- CEISA / PIB Tracking (ACT + FIN) ---
$pdo->exec("
  CREATE TABLE IF NOT EXISTS `purchases_ceisa_pib` (
    `id` int NOT NULL AUTO_INCREMENT,
    `po_id` int NOT NULL,
    `ceisa_status` varchar(30) NOT NULL DEFAULT 'DRAFT',
    `submitted_date` date DEFAULT NULL,
    `reject_count` int NOT NULL DEFAULT 0,
    `reject_reason` text,
    `bc11_no` varchar(80) DEFAULT NULL,
    `bc11_date` date DEFAULT NULL,
    `noa_no` varchar(80) DEFAULT NULL,
    `noa_date` date DEFAULT NULL,
    `billing_aju_no` varchar(80) DEFAULT NULL,
    `billing_aju_date` date DEFAULT NULL,
    `billing_amount` decimal(18,2) NOT NULL DEFAULT 0,
    `billing_currency` varchar(10) NOT NULL DEFAULT 'IDR',
    `sppb_no` varchar(80) DEFAULT NULL,
    `sppb_date` date DEFAULT NULL,
    `final_pib_no` varchar(80) DEFAULT NULL,
    `final_pib_date` date DEFAULT NULL,
    `note` text,
    `updated_by` varchar(100) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_po` (`po_id`),
    KEY `idx_status` (`ceisa_status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS `purchases_ceisa_payment` (
    `id` int NOT NULL AUTO_INCREMENT,
    `pay_code` varchar(60) NOT NULL,
    `pib_id` int NOT NULL,
    `pay_date` date NOT NULL,
    `amount` decimal(18,2) NOT NULL DEFAULT 0,
    `method` varchar(30) DEFAULT NULL,
    `bank_name` varchar(120) DEFAULT NULL,
    `reference` varchar(120) DEFAULT NULL,
    `note` text,
    `doc_path` varchar(255) DEFAULT NULL,
    `created_by` varchar(100) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pib_pay_code` (`pay_code`),
    KEY `idx_pib` (`pib_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
}

function p_audit(PDO $pdo, string $module, ?string $ref, string $action, array $details=[]): void {
  try {
    $st = $pdo->prepare("INSERT INTO purchases_audit_log (module, ref_code, action, details_json, user_name, user_role, user_level)
                         VALUES (?,?,?,?,?,?,?)");
    $st->execute([$module,$ref,$action,json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),p_username(),p_role(),p_level()]);
  } catch (Throwable $e) {}
}

function p_recalc_po_total(PDO $pdo, int $po_id): void
{
    try {
        $st = $pdo->prepare("
            SELECT
                COALESCE(SUM(
                    CASE
                        WHEN COALESCE(total_after_tax, 0) > 0
                        THEN total_after_tax
                        ELSE subtotal
                    END
                ), 0) AS total
            FROM purchases_po_items
            WHERE po_id = ?
              AND deleted_at IS NULL
        ");
        $st->execute([$po_id]);

        $total = (float)$st->fetchColumn();

        $up = $pdo->prepare("
            UPDATE purchases_po
            SET total_amount = ?
            WHERE id = ?
        ");
        $up->execute([$total, $po_id]);

    } catch (Throwable $e) {
        // silent fail agar modul lain tidak fatal
    }
}

function p_recalc_ap_status(PDO $pdo, int $ap_id): void {
  try {
    $st = $pdo->prepare("SELECT total_amount FROM purchases_invoice_ap WHERE id=?");
    $st->execute([$ap_id]);
    $total = (float)$st->fetchColumn();

    $st2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM purchases_payment_ap WHERE ap_id=? AND deleted_at IS NULL");
    $st2->execute([$ap_id]);
    $paid = (float)$st2->fetchColumn();

    $status = 'UNPAID';
    if ($paid <= 0.00001) $status = 'UNPAID';
    else if ($paid + 0.00001 < $total) $status = 'PARTIAL';
    else $status = 'PAID';

    $pdo->prepare("UPDATE purchases_invoice_ap SET status=? WHERE id=?")->execute([$status,$ap_id]);
  } catch (Throwable $e) {}
}


function p_recalc_fap_status(PDO $pdo, int $fap_id): void {
  try {
    $st = $pdo->prepare("SELECT total_amount FROM purchases_forwarder_invoice WHERE id=?");
    $st->execute([$fap_id]);
    $total = (float)$st->fetchColumn();

    $st2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM purchases_forwarder_payment WHERE fap_id=? AND deleted_at IS NULL");
    $st2->execute([$fap_id]);
    $paid = (float)$st2->fetchColumn();

    $status = 'UNPAID';
    if ($paid <= 0.00001) $status = 'UNPAID';
    else if ($paid + 0.00001 < $total) $status = 'PARTIAL';
    else $status = 'PAID';

    $pdo->prepare("UPDATE purchases_forwarder_invoice SET status=? WHERE id=?")->execute([$status,$fap_id]);
  } catch (Throwable $e) {}
}

