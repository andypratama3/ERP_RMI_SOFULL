<?php
require_once __DIR__ . '/../../_shared/assets.php'; // RMI asset loader
// Fixed_Asset_Lite/_inc/bootstrap.php
// Ringkas tapi enterprise: Asset Register + Depreciation + Ops + Audit
// Dependensi: memakai auth dari /master/auth.php (PDO + session)

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../master/auth.php';

// Shared helpers (CSRF, safe_filename, etc) + RBAC
require_once __DIR__ . '/../../_shared/helpers.php';
require_once __DIR__ . '/../../_shared/rbac.php';

// --- FIX: auth.php base path ketika dipanggil dari modul ---
// auth.php menghitung $BASE_PROJECT dari $_SERVER['SCRIPT_NAME'].
// Saat auth.php di-include dari /fixed_asset_lite/*, hasilnya jadi salah (/fixed_asset_lite),
// sehingga redirect login jadi /fixed_asset_lite/master/login.php.
// Di sini kita override URL login/logout ke folder /master yang sejajar dengan modul.
global $AUTH_LOGIN_URL, $AUTH_LOGOUT_URL;
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$baseProject = preg_replace('~/(fixed_asset_lite|Fixed_Asset_Lite|fixed_asset|Fixed_Asset)$~', '', $scriptDir);
$AUTH_LOGIN_URL  = $baseProject . '/master/login.php';
$AUTH_LOGOUT_URL = $baseProject . '/master/logout.php';

require_login(); // kalau mau lebih ketat: require_role(['ADMIN','SUPERADMIN','FIN','ACT','ITC']);

$pdo = db_pdo();

// Ensure RBAC schema exists (safe: ignore errors)
if (function_exists('rbac_ensure_tables')) {
    try { rbac_ensure_tables($pdo); } catch (Throwable $e) { /* ignore */ }
}


// BASE url project (mis: /ERP_RMI_SOFULL)
// dibuat robust: folder bisa Fixed_Asset_Lite / fixed_asset_lite / Fixed_Asset
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$moduleDir = basename($scriptDir); // nama folder modul yg sedang diakses
$BASE_PROJECT = rtrim(dirname($scriptDir), '/\\');
$BASE_FA = $BASE_PROJECT . '/' . $moduleDir;

// ------------------------- helpers UI -------------------------
if (!function_exists('h')) {
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function flash_set($type, $msg): void {
    // Satu bundel lewat rmi_flash_* agar konsisten dengan _shared/helpers.php
    if (function_exists('rmi_flash_set')) {
        $bundle = json_encode(['type' => (string)$type, 'msg' => (string)$msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        rmi_flash_set('_fa_ui', (string)$bundle);
        return;
    }
    $_SESSION['_fa_flash'] = ['type' => $type, 'msg' => $msg];
}

/** @return array{type:string,msg:string}|null */
function flash_get(): ?array {
    if (function_exists('rmi_flash_get')) {
        $raw = rmi_flash_get('_fa_ui', '');
        if ($raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d) && isset($d['type'], $d['msg'])) {
                return ['type' => (string)$d['type'], 'msg' => (string)$d['msg']];
            }
        }
        return null;
    }
    $f = $_SESSION['_fa_flash'] ?? null;
    unset($_SESSION['_fa_flash']);
    return is_array($f) ? $f : null;
}
function redirect_to($url){
    if (function_exists('rmi_redirect')) {
        rmi_redirect($url);
    }
    header('Location: '.$url);
    exit;
}

// ------------------------- CSRF helpers -------------------------
// Gunakan helper shared (rmi_csrf_*) jika ada. Fallback tetap aman.
if (!function_exists('fa_csrf_input')) {
    function fa_csrf_input(): string {
        if (function_exists('rmi_csrf_input')) return rmi_csrf_input();
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        $t = (string)$_SESSION['_csrf'];
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
    }
}
if (!function_exists('fa_csrf_verify')) {
    function fa_csrf_verify(?string $token = null): void {
        if ($token === null) {
            $token = (string)($_POST['_csrf'] ?? $_GET['_csrf'] ?? '');
        }
        if (function_exists('rmi_csrf_verify')) { rmi_csrf_verify((string)$token); return; }
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $expected = (string)($_SESSION['_csrf'] ?? '');
        if ($expected === '' || $token === '' || !hash_equals($expected, (string)$token)) {
            http_response_code(403);
            echo '<h3>CSRF validation failed</h3>';
            exit;
        }
    }
}

// ------------------------- preflight -------------------------
function fa_table_exists(PDO $pdo, string $table): bool {
    // NOTE: Dengan PDO::ATTR_EMULATE_PREPARES = false, MySQL tidak mendukung placeholder (?) pada statement "SHOW TABLES".
    // Jadi gunakan information_schema (tetap aman & bisa di-prepare).
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function fa_column_exists(PDO $pdo, string $table, string $col): bool {
    // NOTE: Hindari "SHOW COLUMNS ... LIKE ?" karena tidak selalu kompatibel dengan native prepare.
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    $stmt->execute([$table, $col]);
    return (bool)$stmt->fetchColumn();
}

function fa_pick_col(PDO $pdo, string $table, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (fa_column_exists($pdo, $table, $c)) return $c;
    }
    return null;
}


function fa_exec_safely(PDO $pdo, string $sql): void {
    try { $pdo->exec($sql); } catch (Throwable $e) { /* ignore */ }
}

// Upgrade ringan supaya versi lama tidak fatal error (mis. kolom audit_log belum lengkap)
function fa_auto_upgrade(PDO $pdo): void {
    // Audit log
    if (!fa_table_exists($pdo, 'fa_audit_log')) {
        fa_exec_safely($pdo, "CREATE TABLE fa_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id INT DEFAULT NULL,
            action VARCHAR(50) NOT NULL,
            entity VARCHAR(50) NOT NULL DEFAULT 'SYSTEM',
            entity_id INT NOT NULL DEFAULT 0,
            meta_json LONGTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        if (!fa_column_exists($pdo, 'fa_audit_log', 'created_at')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_audit_log ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
        if (!fa_column_exists($pdo, 'fa_audit_log', 'user_id')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_audit_log ADD COLUMN user_id INT DEFAULT NULL");
        }
        if (!fa_column_exists($pdo, 'fa_audit_log', 'action')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_audit_log ADD COLUMN action VARCHAR(50) NOT NULL DEFAULT 'ACTION'");
        }
        if (!fa_column_exists($pdo, 'fa_audit_log', 'entity')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_audit_log ADD COLUMN entity VARCHAR(50) NOT NULL DEFAULT 'SYSTEM'");
        }
        if (!fa_column_exists($pdo, 'fa_audit_log', 'entity_id')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_audit_log ADD COLUMN entity_id INT NOT NULL DEFAULT 0");
        }
        if (!fa_column_exists($pdo, 'fa_audit_log', 'meta_json')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_audit_log ADD COLUMN meta_json LONGTEXT NULL");
        }
    }

    // Core schema untuk versi lama (fa_assets sering beda kolom)
    if (fa_table_exists($pdo, 'fa_assets')) {
        // BACKFILL_ACQ_DATE / ACQ_COST for legacy schema
        // ensure acq_date exists
        if (!fa_column_exists($pdo, 'fa_assets', 'acq_date')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN acq_date DATE NULL");
            $src = fa_pick_col($pdo, 'fa_assets', ['purchase_date','acquired_date','acquisition_date','buy_date','tgl_perolehan','date_acq']);
            if ($src) {
                fa_exec_safely($pdo, "UPDATE fa_assets SET acq_date = `{$src}` WHERE acq_date IS NULL");
            }
        }
        // ensure acq_cost exists
        if (!fa_column_exists($pdo, 'fa_assets', 'acq_cost')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN acq_cost DECIMAL(18,2) NOT NULL DEFAULT 0");
            $src = fa_pick_col($pdo, 'fa_assets', ['acq_value','purchase_cost','cost','nilai_perolehan','harga_perolehan']);
            if ($src) {
                fa_exec_safely($pdo, "UPDATE fa_assets SET acq_cost = `{$src}` WHERE acq_cost = 0");
            }
        }

        // kolom minimal yang dipakai UI Lite
        if (!fa_column_exists($pdo, 'fa_assets', 'category')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN category VARCHAR(100) DEFAULT ''");
        }
        if (!fa_column_exists($pdo, 'fa_assets', 'office_code')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN office_code VARCHAR(30) DEFAULT ''");
        }
        if (!fa_column_exists($pdo, 'fa_assets', 'dept_code')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN dept_code VARCHAR(30) DEFAULT ''");
        }
        if (!fa_column_exists($pdo, 'fa_assets', 'custodian_emp_id')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN custodian_emp_id INT DEFAULT NULL");
        }
        if (!fa_column_exists($pdo, 'fa_assets', 'status')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'");
        }
        if (!fa_column_exists($pdo, 'fa_assets', 'salvage_value')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN salvage_value DECIMAL(18,2) NOT NULL DEFAULT 0");
        }
        if (!fa_column_exists($pdo, 'fa_assets', 'tax_group_code')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN tax_group_code VARCHAR(20) NOT NULL DEFAULT 'G1'");
        }
        if (!fa_column_exists($pdo, 'fa_assets', 'dep_method')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN dep_method VARCHAR(10) NOT NULL DEFAULT 'SL'");
        }
        if (!fa_column_exists($pdo, 'fa_assets', 'deleted_at')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN deleted_at DATETIME NULL");
        }
        if (!fa_column_exists($pdo, 'fa_assets', 'updated_at')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN updated_at DATETIME NULL");
        }
        if (!fa_column_exists($pdo, 'fa_assets', 'notes')) {
            fa_exec_safely($pdo, "ALTER TABLE fa_assets ADD COLUMN notes TEXT NULL");
        }
    }

}

function fa_preflight_or_die(PDO $pdo){
    global $BASE_FA;
    if (!fa_table_exists($pdo, 'fa_assets')) {
        echo "<!doctype html><html><head><meta charset='utf-8'><title>Fixed Asset - Install</title>
        <link href='<?= rmi_assets_base() ?>/public/assets/vendor/bootstrap/5.3.3/css/bootstrap.min.css?v=20260209' rel='stylesheet'>
        </head><body class='bg-light'><div class='container py-5'>
        <div class='alert alert-warning'>
        <b>Fixed Asset belum ter-install.</b><br>
        Jalankan SQL installer: <code>Fixed_Asset_Lite/_sql/fixed_asset_install.sql</code> di database ERP, lalu refresh halaman ini.
        </div>
        <a class='btn btn-primary' href='{$BASE_FA}/index.php'>Reload</a>
        </div></body></html>";
        exit;
    }

    // jalankan upgrade ringan (tidak membatalkan halaman kalau gagal)
    fa_auto_upgrade($pdo);
}
