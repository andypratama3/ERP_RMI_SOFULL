<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Security/TotpService.php';
require_once __DIR__ . '/../app/Support/RequestContext.php';
require_once __DIR__ . '/../app/Accounting/TaxInvoiceService.php';
require_once __DIR__ . '/../app/Accounting/GLReportService.php';
require_once __DIR__ . '/../app/Accounting/GLPostingService.php';
require_once __DIR__ . '/../app/Accounting/ThreeWayMatchValidator.php';
require_once __DIR__ . '/../app/CRM/LeadDedupeService.php';
require_once __DIR__ . '/../app/Services/ChatService.php';
require_once __DIR__ . '/../app/Services/ChatMentionService.php';
require_once __DIR__ . '/../app/Services/ChatAttachmentService.php';

use App\Security\TotpService;
use App\Support\RequestContext;
use App\Accounting\TaxInvoiceService;
use App\Accounting\GLReportService;
use App\Accounting\GLPostingService;
use App\Accounting\ThreeWayMatchValidator;
use App\CRM\LeadDedupeService;
use App\Services\ChatService;
use App\Services\ChatMentionService;
use App\Services\ChatAttachmentService;

$pass = 0;
$fail = 0;

function t_assert(bool $cond, string $name): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name\n";
    }
}

function t_totp_code(string $secret, int $timeSlice): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
    $bits = '';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $v = strpos($alphabet, $b32[$i]);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $key .= chr(bindec($byte));
    }
    $time = pack('N*', 0, $timeSlice);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $truncated = (
        ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff)
    );
    return str_pad((string)($truncated % 1000000), 6, '0', STR_PAD_LEFT);
}

// 1-4: TotpService tests
$totp = new TotpService();
$secret = $totp->generateSecret();
t_assert(strlen($secret) === 32, 'TOTP secret length is 32');
t_assert((bool)preg_match('/^[A-Z2-7]{32}$/', $secret), 'TOTP secret charset is base32');
$uri = $totp->getOtpAuthUri('ERP', 'user1', $secret);
t_assert(strpos($uri, 'otpauth://totp/') === 0, 'TOTP URI starts with otpauth');
$knownSecret = 'JBSWY3DPEHPK3PXP';
$code = t_totp_code($knownSecret, (int)floor(time() / 30));
t_assert($totp->verifyCode($knownSecret, $code), 'TOTP verifies valid current code');
t_assert(!$totp->verifyCode($knownSecret, '000000'), 'TOTP rejects invalid code');

// 5-6: RequestContext tests
$_SERVER['HTTP_X_REQUEST_ID'] = 'req-fixed-1';
t_assert(RequestContext::ensureRequestId() === 'req-fixed-1', 'RequestContext uses incoming request id');
unset($_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['RMI_REQUEST_ID']);
$rid = RequestContext::ensureRequestId();
t_assert((bool)preg_match('/^[a-f0-9]{24}$/', $rid), 'RequestContext generates 24 hex chars');

// SQLite setup for accounting service tests
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 7-8: TaxInvoiceService number generation tests
$pdo->exec("CREATE TABLE tax_invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, sales_invoice_ref TEXT, tax_no TEXT, tax_date TEXT, status TEXT, file_path TEXT, created_by INT, created_at TEXT, updated_at TEXT)");
$taxSvc = new TaxInvoiceService();
$n1 = $taxSvc->generateNumber($pdo);
t_assert((bool)preg_match('/^TAX-\d{6}-\d{5}$/', $n1), 'Tax number format is valid');
$pdo->prepare("INSERT INTO tax_invoices (sales_invoice_ref,tax_no,tax_date,status,created_at,updated_at) VALUES (?,?,?,?,datetime('now'),datetime('now'))")
    ->execute(['SO-1', $n1, date('Y-m-d'), 'DRAFT']);
$n2 = $taxSvc->generateNumber($pdo);
t_assert((int)substr($n2, -5) === ((int)substr($n1, -5) + 1), 'Tax number increments sequence');

// 9-11: GLReportService tests
$pdo->exec("CREATE TABLE gl_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, account_type TEXT, status TEXT)");
$pdo->exec("CREATE TABLE gl_journal_headers (id INTEGER PRIMARY KEY AUTOINCREMENT, journal_no TEXT, journal_date TEXT, source_module TEXT, source_event TEXT, source_ref TEXT, description TEXT, status TEXT)");
$pdo->exec("CREATE TABLE gl_journal_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, header_id INT, line_no INT, account_id INT, dr_amount REAL, cr_amount REAL, memo TEXT)");
$pdo->exec("INSERT INTO gl_accounts (id,code,name,account_type,status) VALUES (1,'111001','Cash/Bank','ASSET','ACTIVE')");
$pdo->exec("INSERT INTO gl_accounts (id,code,name,account_type,status) VALUES (2,'211001','AP','LIABILITY','ACTIVE')");
$pdo->exec("INSERT INTO gl_journal_headers (id,journal_no,journal_date,source_module,source_event,source_ref,description,status) VALUES (1,'JRN-1','2026-02-01','PURCHASES','AP_INVOICE_CREATED','AP-1','test','POSTED')");
$pdo->exec("INSERT INTO gl_journal_lines (header_id,line_no,account_id,dr_amount,cr_amount,memo) VALUES (1,1,1,1000,0,'debit')");
$pdo->exec("INSERT INTO gl_journal_lines (header_id,line_no,account_id,dr_amount,cr_amount,memo) VALUES (1,2,2,0,1000,'credit')");

$glReport = new GLReportService();
$tb = $glReport->trialBalance($pdo, '2026-02-01', '2026-02-28');
t_assert(count($tb) === 2, 'Trial balance returns 2 accounts');
$ledger = $glReport->generalLedger($pdo, 1, '2026-02-01', '2026-02-28');
t_assert(count($ledger) === 1 && abs((float)$ledger[0]['running_balance'] - 1000.0) < 0.0001, 'General ledger running balance computed');
$jl = $glReport->journalListing($pdo, '2026-02-01', '2026-02-28', 'PURCHASES', 'AP-1');
t_assert(count($jl) === 1 && abs((float)$jl[0]['total_dr'] - (float)$jl[0]['total_cr']) < 0.0001, 'Journal listing totals are balanced');

// 12-14: GLPostingService integration-style tests (post + idempotent + reverse)
$pdo2 = new PDO('sqlite::memory:');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo2->exec("CREATE TABLE gl_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, account_type TEXT, parent_id INT NULL, is_postable INT, status TEXT, created_at TEXT, updated_at TEXT)");
$pdo2->exec("CREATE TABLE gl_journal_headers (id INTEGER PRIMARY KEY AUTOINCREMENT, journal_no TEXT, journal_date TEXT, source_module TEXT, source_event TEXT, source_ref TEXT, description TEXT, status TEXT, created_by INT NULL, created_at TEXT)");
$pdo2->exec("CREATE UNIQUE INDEX uq_gl_source_ref ON gl_journal_headers(source_module, source_event, source_ref)");
$pdo2->exec("CREATE TABLE gl_journal_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, header_id INT, line_no INT, account_id INT, dr_amount REAL, cr_amount REAL, memo TEXT, created_at TEXT)");
$pdo2->exec("CREATE TABLE gl_posting_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_code TEXT, posted_at TEXT, posted_by INT NULL, note TEXT)");
$pdo2->exec("CREATE TABLE gl_mappings (id INTEGER PRIMARY KEY AUTOINCREMENT, module_name TEXT, event_name TEXT, debit_account_id INT, credit_account_id INT, rule_json TEXT, is_active INT, created_at TEXT, updated_at TEXT)");
$pdo2->exec("INSERT INTO gl_accounts (id,code,name,account_type,is_postable,status,created_at,updated_at) VALUES (1,'111001','Cash','ASSET',1,'ACTIVE',datetime('now'),datetime('now'))");
$pdo2->exec("INSERT INTO gl_accounts (id,code,name,account_type,is_postable,status,created_at,updated_at) VALUES (2,'211001','AP','LIABILITY',1,'ACTIVE',datetime('now'),datetime('now'))");
$pdo2->exec("INSERT INTO gl_mappings (module_name,event_name,debit_account_id,credit_account_id,rule_json,is_active,created_at,updated_at) VALUES ('PURCHASES','AP_INVOICE_CREATED',1,2,NULL,1,datetime('now'),datetime('now'))");

$glPosting = new GLPostingService();
$hid1 = $glPosting->createJournalFromMapping($pdo2, 'PURCHASES', 'AP_INVOICE_CREATED', 'AP-100', 500.0, 'test', 1);
t_assert($hid1 !== null && $hid1 > 0, 'GLPosting creates first journal from mapping');
$hid2 = $glPosting->createJournalFromMapping($pdo2, 'PURCHASES', 'AP_INVOICE_CREATED', 'AP-100', 500.0, 'test', 1);
t_assert($hid2 === $hid1, 'GLPosting idempotent on same module/event/ref');
$rev = $glPosting->reverseBySource($pdo2, 'PURCHASES', 'AP_INVOICE_CREATED', 'AP-100', 'void', 1);
t_assert($rev !== null && $rev > 0 && $rev !== $hid1, 'GLPosting reversal created for source');
$stCount = $pdo2->query("SELECT COUNT(*) FROM gl_journal_headers")->fetchColumn();
t_assert((int)$stCount === 2, 'GLPosting has original + reversal journal count');

// 15-16: Optional MySQL integration test (run only if env provided)
$mysqlDsn = getenv('TEST_MYSQL_DSN') ?: '';
$mysqlUser = getenv('TEST_MYSQL_USER') ?: '';
$mysqlPass = getenv('TEST_MYSQL_PASS') ?: '';
if ($mysqlDsn !== '') {
    try {
        $pdo3 = new PDO($mysqlDsn, $mysqlUser, $mysqlPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo3->exec("CREATE TABLE IF NOT EXISTS gl_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL,
            name VARCHAR(200) NOT NULL,
            account_type VARCHAR(20) NOT NULL,
            parent_id BIGINT NULL,
            is_postable TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo3->exec("CREATE TABLE IF NOT EXISTS gl_journal_headers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_no VARCHAR(60) NOT NULL,
            journal_date DATE NOT NULL,
            source_module VARCHAR(60) NOT NULL,
            source_event VARCHAR(80) NOT NULL,
            source_ref VARCHAR(120) NOT NULL,
            reversal_of_header_id BIGINT NULL,
            reverse_reason VARCHAR(255) NULL,
            reversed_at DATETIME NULL,
            reversed_by BIGINT NULL,
            description VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
            created_by BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_gl_source_ref (source_module, source_event, source_ref)
        ) ENGINE=InnoDB");
        $pdo3->exec("CREATE TABLE IF NOT EXISTS gl_journal_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            header_id BIGINT UNSIGNED NOT NULL,
            line_no INT NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            dr_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            cr_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            memo VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo3->exec("CREATE TABLE IF NOT EXISTS gl_posting_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            batch_code VARCHAR(80) NOT NULL,
            posted_at DATETIME NOT NULL,
            posted_by BIGINT NULL,
            note VARCHAR(255) NULL
        ) ENGINE=InnoDB");
        $pdo3->exec("CREATE TABLE IF NOT EXISTS gl_mappings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            module_name VARCHAR(60) NOT NULL,
            event_name VARCHAR(80) NOT NULL,
            debit_account_id BIGINT UNSIGNED NOT NULL,
            credit_account_id BIGINT UNSIGNED NOT NULL,
            rule_json LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_gl_mapping_event (module_name, event_name)
        ) ENGINE=InnoDB");

        $pdo3->exec("DELETE FROM gl_journal_lines");
        $pdo3->exec("DELETE FROM gl_journal_headers");
        $pdo3->exec("DELETE FROM gl_mappings");
        $pdo3->exec("DELETE FROM gl_accounts");

        $pdo3->exec("INSERT INTO gl_accounts (id,code,name,account_type,is_postable,status) VALUES (1001,'T111','Test Cash','ASSET',1,'ACTIVE')");
        $pdo3->exec("INSERT INTO gl_accounts (id,code,name,account_type,is_postable,status) VALUES (1002,'T211','Test AP','LIABILITY',1,'ACTIVE')");
        $pdo3->exec("INSERT INTO gl_mappings (module_name,event_name,debit_account_id,credit_account_id,is_active) VALUES ('TESTMOD','TESTEVENT',1001,1002,1)");

        $svc3 = new GLPostingService();
        $h = $svc3->createJournalFromMapping($pdo3, 'TESTMOD', 'TESTEVENT', 'REF-001', 123.45, 'mysql test', 1);
        t_assert($h !== null && $h > 0, 'MySQL integration: create journal');
        $r = $svc3->reverseBySource($pdo3, 'TESTMOD', 'TESTEVENT', 'REF-001', 'mysql reverse', 1);
        t_assert($r !== null && $r > 0, 'MySQL integration: reverse journal');
    } catch (Throwable $e) {
        t_assert(false, 'MySQL integration failed: ' . $e->getMessage());
    }
} else {
    echo "[SKIP] MySQL integration tests skipped (TEST_MYSQL_DSN not set)\n";
}

// 17-20: ThreeWayMatchValidator qty checks
function t_build_3wm_pdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE purchases_po (id INTEGER PRIMARY KEY, po_code TEXT, total_amount REAL)");
    $pdo->exec("CREATE TABLE purchases_po_items (id INTEGER PRIMARY KEY, po_id INT, product_id INT, sku TEXT, qty REAL, unit_price REAL, deleted_at TEXT NULL)");
    $pdo->exec("CREATE TABLE wqs_incoming (id INTEGER PRIMARY KEY, po_id INT, po_code TEXT)");
    $pdo->exec("CREATE TABLE wqs_incoming_items (id INTEGER PRIMARY KEY, incoming_id INT, po_item_id INT NULL, product_id INT, sku TEXT, qty REAL)");
    $pdo->exec("CREATE TABLE purchases_invoice_ap (id INTEGER PRIMARY KEY, po_id INT, total_amount REAL, status TEXT, deleted_at TEXT NULL)");
    $pdo->exec("CREATE TABLE purchases_invoice_ap_lines (id INTEGER PRIMARY KEY, ap_id INT, po_item_id INT NULL, sku TEXT, qty REAL)");
    $pdo->exec("CREATE TABLE procurement_match_rules (id INTEGER PRIMARY KEY, qty_tolerance_pct REAL, price_tolerance_pct REAL, is_active INT)");
    $pdo->exec("INSERT INTO procurement_match_rules (id, qty_tolerance_pct, price_tolerance_pct, is_active) VALUES (1,0,0,1)");
    $pdo->exec("INSERT INTO purchases_po (id, po_code, total_amount) VALUES (1,'PO-1',1000)");
    $pdo->exec("INSERT INTO purchases_po_items (id, po_id, product_id, sku, qty, unit_price, deleted_at) VALUES (10,1,101,'SKU-A',100,10,NULL)");
    $pdo->exec("INSERT INTO wqs_incoming (id, po_id, po_code) VALUES (1,1,'PO-1')");
    $pdo->exec("INSERT INTO wqs_incoming (id, po_id, po_code) VALUES (2,1,'PO-1')");
    $pdo->exec("INSERT INTO wqs_incoming_items (incoming_id, po_item_id, product_id, sku, qty) VALUES (1,10,101,'SKU-A',20)");
    $pdo->exec("INSERT INTO wqs_incoming_items (incoming_id, po_item_id, product_id, sku, qty) VALUES (2,10,101,'SKU-A',40)");
    return $pdo;
}

$v = new ThreeWayMatchValidator();
$pdo3wmA = t_build_3wm_pdo();
$rA = $v->validateApAgainstPo($pdo3wmA, 1, 700, 0, 0, [['po_item_id' => 10, 'qty' => 70]]);
t_assert($rA['ok'] === false, '3WM rejects invoice qty 70 when received only 60');

$pdo3wmB = t_build_3wm_pdo();
$rB = $v->validateApAgainstPo($pdo3wmB, 1, 600, 0, 0, [['po_item_id' => 10, 'qty' => 60]]);
t_assert($rB['ok'] === true, '3WM accepts invoice qty 60 when received 60');

$pdo3wmC = t_build_3wm_pdo();
$pdo3wmC->exec("INSERT INTO purchases_invoice_ap (id, po_id, total_amount, status, deleted_at) VALUES (1,1,300,'UNPAID',NULL)");
$pdo3wmC->exec("INSERT INTO purchases_invoice_ap_lines (ap_id, po_item_id, sku, qty) VALUES (1,10,'SKU-A',30)");
$rC = $v->validateApAgainstPo($pdo3wmC, 1, 350, 0, 0, [['po_item_id' => 10, 'qty' => 35]]);
t_assert($rC['ok'] === false, '3WM counts partial incoming and existing invoice quantities correctly');
t_assert((int)round((float)($rC['metrics']['received_qty_by_po_item'][10] ?? 0)) === 60, '3WM aggregates multiple incoming rows into received qty');

// 21-23: CRM dedupe normalization
$dedupe = new LeadDedupeService();
t_assert($dedupe->normalizeEmail('  Foo.Bar@Example.COM ') === 'foo.bar@example.com', 'CRM dedupe normalizes email');
t_assert($dedupe->normalizePhone('0812-3456-7890') === '+6281234567890', 'CRM dedupe normalizes local phone');

$pdoD = new PDO('sqlite::memory:');
$pdoD->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoD->exec("CREATE TABLE crm_leads (id INTEGER PRIMARY KEY AUTOINCREMENT, lead_no TEXT, status TEXT, lead_name TEXT, company_name TEXT, normalized_email TEXT, normalized_phone TEXT, deleted_at TEXT NULL)");
$pdoD->prepare("INSERT INTO crm_leads (lead_no,status,lead_name,company_name,normalized_email,normalized_phone,deleted_at) VALUES (?,?,?,?,?,?,NULL)")
    ->execute(['LEAD-1', 'DRAFT', 'A', 'ACME', 'a@example.com', '+628111111111']);
$dup = $dedupe->findDuplicate($pdoD, 'a@example.com', '');
t_assert(is_array($dup) && (int)$dup['id'] > 0, 'CRM dedupe finds duplicate by normalized email');

// 24-29: Chat module unit-level tests
$mentionSvc = new ChatMentionService();
$parsed = $mentionSvc->parseMentionUsernames("Hi @alice and @bob_01, ping @alice.");
sort($parsed);
t_assert($parsed === ['alice', 'bob_01'], 'Chat mention parser extracts unique usernames');

$pdoChat = new PDO('sqlite::memory:');
$pdoChat->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoChat->exec("CREATE TABLE master_system_login (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, full_name TEXT, status TEXT, department TEXT, office_code TEXT)");
$pdoChat->exec("INSERT INTO master_system_login (username, full_name, status, department, office_code) VALUES ('alice','Alice A','active','FIN','HQ')");
$pdoChat->exec("INSERT INTO master_system_login (username, full_name, status, department, office_code) VALUES ('bob_01','Bob B','active','SCM','HQ')");
$resolved = $mentionSvc->resolveUsersByUsername($pdoChat, ['alice', 'bob_01']);
t_assert(count($resolved) === 2, 'Chat mention resolver maps usernames to users');

$attachSvc = new ChatAttachmentService();
$tmpFile = tempnam(sys_get_temp_dir(), 'chat_test_');
file_put_contents($tmpFile, "%PDF-1.4\n%chat-test\n");
$meta = $attachSvc->validateFileMeta([
    'error' => UPLOAD_ERR_OK,
    'name' => 'sample.pdf',
    'tmp_name' => $tmpFile,
    'size' => filesize($tmpFile),
], ['application/pdf'], 1024 * 1024);
t_assert(($meta['mime_type'] ?? '') === 'application/pdf', 'Chat attachment validation verifies MIME');
@unlink($tmpFile);

$chatSvc = new ChatService();
$pdoUnread = new PDO('sqlite::memory:');
$pdoUnread->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoUnread->exec("CREATE TABLE chat_channels (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, created_by INT, created_at TEXT, is_private INT, dm_user_low INT, dm_user_high INT)");
$pdoUnread->exec("CREATE TABLE chat_channel_members (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INT, user_id INT, joined_at TEXT, last_read_message_id INT)");
$pdoUnread->exec("CREATE TABLE chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INT, user_id INT, sender_username TEXT, message_text TEXT, created_at TEXT, is_deleted INT, deleted_at TEXT, deleted_by INT, delete_reason TEXT)");
$pdoUnread->exec("INSERT INTO chat_channels (id,type,name,created_by,created_at,is_private,dm_user_low,dm_user_high) VALUES (1,'CHANNEL','general',1,datetime('now'),0,NULL,NULL)");
$pdoUnread->exec("INSERT INTO chat_channel_members (channel_id,user_id,joined_at,last_read_message_id) VALUES (1,100,datetime('now'),1)");
$pdoUnread->exec("INSERT INTO chat_messages (id,channel_id,user_id,sender_username,message_text,created_at,is_deleted) VALUES (1,1,100,'me','first',datetime('now'),0)");
$pdoUnread->exec("INSERT INTO chat_messages (id,channel_id,user_id,sender_username,message_text,created_at,is_deleted) VALUES (2,1,101,'other','second',datetime('now'),0)");
$rowsUnread = $chatSvc->listChannelsWithUnread($pdoUnread, 100);
t_assert((int)($rowsUnread[0]['unread_count'] ?? -1) === 1, 'Chat unread counter counts newer messages from other user');
t_assert($chatSvc->isAdminLike('ADMIN') && !$chatSvc->isAdminLike('USER'), 'Chat RBAC delete check admin-only');

echo "-----------------------------\n";
echo "Total PASS: {$pass}\n";
echo "Total FAIL: {$fail}\n";
exit($fail > 0 ? 1 : 0);
