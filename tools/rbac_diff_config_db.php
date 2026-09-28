<?php
/**
 * RBAC Diff: Bandingkan config/rbac_permissions.php dengan DB.
 * READ-ONLY — tidak mengubah apa pun.
 *
 * Via WEB (disarankan di NAS): /tools/rbac_diff_config_db.php
 * Via CLI: php tools/rbac_diff_config_db.php (perlu PDO MySQL di PHP CLI)
 */
declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');
if ($isCli) {
    require_once __DIR__ . '/../_shared/app_init.php';
    require_once __DIR__ . '/../_shared/db.php';
    require_once __DIR__ . '/../_shared/rbac.php';
} else {
    require_once __DIR__ . '/../master/auth.php';
    require_once __DIR__ . '/../_shared/rbac.php';
    require_login();
    if (!function_exists('rbac_is_privileged_session') || !rbac_is_privileged_session()) {
        http_response_code(403);
        die('Forbidden: admin only');
    }
}

$root = defined('RMI_ROOT') ? RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
$configPath = $root . '/config/rbac_permissions.php';
if (!is_file($configPath)) {
    echo "ERROR: config/rbac_permissions.php tidak ditemukan.\n";
    exit(1);
}

$configPerms = require $configPath;
$configCodes = [];
foreach ($configPerms as $p) {
    $code = function_exists('rbac_norm_code') ? rbac_norm_code((string)($p[0] ?? '')) : strtoupper(trim((string)($p[0] ?? '')));
    if ($code !== '') {
        $configCodes[$code] = true;
    }
}

$pdo = $isCli
    ? (function_exists('rmi_db_pdo') ? rmi_db_pdo() : null)
    : (function_exists('auth_pdo') ? auth_pdo() : (function_exists('rmi_db_pdo') ? rmi_db_pdo() : null));
if (!$pdo) {
    if ($isCli) {
        echo "ERROR: Tidak bisa koneksi DB. Coba jalankan via web: /tools/rbac_diff_config_db.php\n";
    } else {
        echo "ERROR: Tidak bisa koneksi DB.";
    }
    exit(1);
}

$st = $pdo->query("SELECT perm_code, perm_name, module FROM rbac_permissions WHERE is_active=1 ORDER BY perm_code");
$dbRows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
$dbCodes = [];
foreach ($dbRows as $r) {
    $code = function_exists('rbac_norm_code') ? rbac_norm_code((string)($r['perm_code'] ?? '')) : strtoupper(trim((string)($r['perm_code'] ?? '')));
    if ($code !== '') {
        $dbCodes[$code] = $r;
    }
}

$inDbNotConfig = array_diff_key($dbCodes, $configCodes);
$inConfigNotDb = array_diff_key($configCodes, $dbCodes);

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$out = '';
$out .= "Config (rbac_permissions.php): " . count($configCodes) . " permission\n";
$out .= "DB (rbac_permissions):         " . count($dbCodes) . " permission\n";
$out .= "\n";

if (!empty($inDbNotConfig)) {
    $out .= "--- Di DB tapi TIDAK di config (" . count($inDbNotConfig) . ") ---\n";
    $out .= "(Permission ini dari migration/seed lain; sync dari config TIDAK akan menghapusnya)\n";
    foreach ($inDbNotConfig as $code => $r) {
        $out .= "  " . $code . " | " . ($r['module'] ?? '') . " | " . ($r['perm_name'] ?? '') . "\n";
    }
    $out .= "\n";
}

if (!empty($inConfigNotDb)) {
    $out .= "--- Di config tapi TIDAK di DB (" . count($inConfigNotDb) . ") ---\n";
    $out .= "(Akan di-insert saat Sync Full Permissions)\n";
    foreach (array_keys($inConfigNotDb) as $code) {
        $out .= "  " . $code . "\n";
    }
    $out .= "\n";
}

if (empty($inDbNotConfig) && empty($inConfigNotDb)) {
    $out .= "Config dan DB sudah sinkron.\n";
} else {
    $out .= "--- Rekomendasi ---\n";
    if (!empty($inDbNotConfig)) {
        $out .= "Baris \"Di DB tapi TIDAK di config\" = orphan registry (sisa migrasi/seed lama). Sync Full bersifat ADDITIVE sehingga tidak menghapusnya.\n";
        $out .= "Setelah review daftar (pastikan tidak ada kode yang masih dipakai require_* di kode tapi sengaja belum dimasukkan config):\n";
        $out .= "  • RBAC Center → tab Sync → \"Hapus orphan registry\" (perlu SYSTEM.RBAC_MANAGE), atau\n";
        $out .= "  • CLI NAS: php tools/rbac/prune_rbac_orphan_permissions.php --dry-run  lalu  --execute\n";
        $out .= "Penghapusan menghapus baris rbac_permissions + referensi matrix/override (FK CASCADE).\n";
    }
    if (!empty($inConfigNotDb)) {
        $out .= "Untuk kode yang hanya di config: jalankan RBAC Center → Sync Full Permissions.\n";
    }
}

echo $out;
