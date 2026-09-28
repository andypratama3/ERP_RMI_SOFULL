<?php
/**
 * _shared/db.php
 *
 * Tujuan:
 * - Satu pintu koneksi PDO (dan opsional mysqli) yang bisa dipakai lintas modul.
 * - Aman dipanggil dari modul lama/baru tanpa memaksa refactor besar.
 *
 * Cara pakai (modul):
 *   require_once __DIR__ . '/../_shared/bootstrap.php';
 *   $pdo = rmi_db_pdo();
 */

declare(strict_types=1);



// --- ENV (optional) ---
$__env = __DIR__ . '/env.php';
if (is_file($__env)) {
    require_once $__env;
    if (function_exists('rmi_env_load')) { rmi_env_load(); }
}
/**
 * Legacy DB_* globals (kompatibilitas modul lama)
 * - Banyak file lama masih memakai $DB_HOST/$DB_NAME/$DB_USER/$DB_PASS/$DB_PORT langsung.
 * - Bootstrap baru tidak selalu include config.php, jadi kita set default aman di sini.
 * - Kalau config.php sudah set, kita tidak override.
 */
if (!isset($GLOBALS['DB_HOST'])) { $GLOBALS['DB_HOST'] = '127.0.0.1'; }
if (!isset($GLOBALS['DB_PORT'])) { $GLOBALS['DB_PORT'] = (int)(getenv('DB_PORT_DEFAULT') ?: 3306); }
if (!isset($GLOBALS['DB_NAME'])) { $GLOBALS['DB_NAME'] = 'ERP_RMI_SOFULL'; }
if (!isset($GLOBALS['DB_USER'])) { $GLOBALS['DB_USER'] = 'root'; }
if (!isset($GLOBALS['DB_PASS'])) { $GLOBALS['DB_PASS'] = (string)(getenv('DB_PASS_DEFAULT') ?: ''); }

// Expose juga sebagai variable biasa ($DB_HOST, dst) supaya modul legacy
// yang tidak memakai $GLOBALS tetap tidak memunculkan warning "undefined variable".
// Jangan override kalau sudah diset sebelumnya (misalnya dari config.php).
if (!isset($DB_HOST) && isset($GLOBALS['DB_HOST'])) { $DB_HOST = $GLOBALS['DB_HOST']; }
if (!isset($DB_PORT) && isset($GLOBALS['DB_PORT'])) { $DB_PORT = $GLOBALS['DB_PORT']; }
if (!isset($DB_NAME) && isset($GLOBALS['DB_NAME'])) { $DB_NAME = $GLOBALS['DB_NAME']; }
if (!isset($DB_USER) && isset($GLOBALS['DB_USER'])) { $DB_USER = $GLOBALS['DB_USER']; }
if (!isset($DB_PASS) && array_key_exists('DB_PASS', $GLOBALS)) { $DB_PASS = $GLOBALS['DB_PASS']; }

/**
 * Ambil konfigurasi DB dari berbagai sumber.
 * Prioritas:
 * 1) ENV: ERP_DB_HOST/PORT/NAME/USER/PASS
 * 2) Constant: DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS
 * 3) Global variable: $DB_HOST/$DB_PORT/$DB_NAME/$DB_USER/$DB_PASS (dari config.php)
 * 4) Default runtime profile: NAS=3306, pass dari .env/config
 */
if (!function_exists('rmi_db_config')) {
    function rmi_db_config(): array {
        // 1) config-db.php atau config-db.example.php (prioritas tertinggi)
        $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $cfgFile = $root . '/config-db.php';
        if (!is_file($cfgFile)) {
            $cfgFile = $root . '/config-db.example.php';
        }
        if (is_file($cfgFile)) {
            $c = require $cfgFile;
            if (is_array($c)) {
                return [
                    'host' => (string)($c['host'] ?? '127.0.0.1'),
                    'port' => (int)($c['port'] ?? 3306),
                    'name' => (string)($c['name'] ?? 'erp_rmi_sofull'),
                    'user' => (string)($c['user'] ?? 'root'),
                    'pass' => (string)($c['pass'] ?? ''),
                ];
            }
        }
        // 2) ENV (pakai rmi_env agar .env terbaca di Synology saat putenv disabled)
        $envHost = rmi_env('ERP_DB_HOST') ?: rmi_env('DB_HOST') ?: null;
        $envPort = rmi_env('ERP_DB_PORT') ?: rmi_env('DB_PORT') ?: null;
        $envName = rmi_env('ERP_DB_NAME') ?: rmi_env('DB_DATABASE') ?: rmi_env('DB_NAME') ?: null;
        $envUser = rmi_env('ERP_DB_USER') ?: rmi_env('DB_USERNAME') ?: null;
        $envPass = rmi_env('ERP_DB_PASS') ?: rmi_env('DB_PASSWORD') ?: rmi_env('DB_PASS') ?: null;
        // Constants
        $cHost = defined('DB_HOST') ? (string)DB_HOST : null;
        $cPort = defined('DB_PORT') ? (string)DB_PORT : null;
        $cName = defined('DB_NAME') ? (string)DB_NAME : null;
        $cUser = defined('DB_USER') ? (string)DB_USER : null;
        $cPass = defined('DB_PASS') ? (string)DB_PASS : null;

        // Globals from config.php
        $gHost = $GLOBALS['DB_HOST'] ?? null;
        $gPort = $GLOBALS['DB_PORT'] ?? null;
        $gName = $GLOBALS['DB_NAME'] ?? null;
        $gUser = $GLOBALS['DB_USER'] ?? null;
        $gPass = $GLOBALS['DB_PASS'] ?? null;

        $defaultPort = (string)((int)(getenv('DB_PORT_DEFAULT') ?: 3306));
        $defaultPass = (string)(getenv('DB_PASS_DEFAULT') ?: '');
        $host = $envHost ?? $cHost ?? (is_string($gHost) ? $gHost : null) ?? '127.0.0.1';
        $port = $envPort ?? $cPort ?? (is_int($gPort) ? (string)$gPort : (is_string($gPort) ? $gPort : null)) ?? $defaultPort;
        $name = $envName ?? $cName ?? (is_string($gName) ? $gName : null) ?? 'ERP_RMI_SOFULL';
        $user = $envUser ?? $cUser ?? (is_string($gUser) ? $gUser : null) ?? 'root';
        $pass = $envPass ?? $cPass ?? (is_string($gPass) ? $gPass : null) ?? $defaultPass;

        // Normalisasi
        $host = trim((string)$host);
        $name = trim((string)$name);
        $user = trim((string)$user);
        $port = (int)trim((string)$port);

        return [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => (string)$pass,
        ];
    }
}

/**
 * PDO singleton.
 *
 * Catatan:
 * - Kalau project kamu sudah punya db_pdo() di master/auth.php, kita pakai itu.
 * - Kalau belum ada, kita buat koneksi sendiri.
 */
if (!function_exists('rmi_db_pdo')) {
    function rmi_db_pdo(): PDO {
        static $pdo = null;

        // Prefer fungsi existing (compat) kalau ada
        if (function_exists('db_pdo')) {
            /** @var PDO $p */
            $p = db_pdo();
            return $p;
        }

        if ($pdo instanceof PDO) return $pdo;

        $cfg = rmi_db_config();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $cfg['host'], (int)$cfg['port'], $cfg['name']);

        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

        return $pdo;
    }
}

/**
 * Optional: mysqli singleton (kompatibilitas lama / skrip diagnostik).
 * Jalur aplikasi web utama sebaiknya memakai rmi_db_pdo() saja.
 */
if (!function_exists('rmi_db_mysqli')) {
    function rmi_db_mysqli(): mysqli {
        static $conn = null;

        // Kalau config.php sudah bikin $conn, pakai itu.
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            return $GLOBALS['conn'];
        }

        if ($conn instanceof mysqli) return $conn;

        $cfg = rmi_db_config();
        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }

        $conn = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], (int)$cfg['port']);
        $conn->set_charset('utf8mb4');
        return $conn;
    }
}

// ============================================================================
// Schema helpers (M2): ensure_table / ensure_col / table_cols / pick_col
// Tujuan: bikin bootstrap modul (contoh STOCK/WQS) idempotent tanpa fatal error.
// ============================================================================

if (!function_exists('table_cols')) {
  /**
   * Ambil daftar kolom existing dari suatu tabel.
   * Return: array nama kolom (as-is dari DB).
   */
  function table_cols(PDO $pdo, string $table): array {
    $table = trim($table);
    if ($table === '') return [];

    $sql = "SELECT COLUMN_NAME\n            FROM information_schema.columns\n            WHERE table_schema = DATABASE()\n              AND table_name = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$table]);
    $cols = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    return is_array($cols) ? $cols : [];
  }
}

if (!function_exists('pick_col')) {
  /**
   * Pilih nama kolom yang tersedia dari kandidat.
   *
   * @param array $cols daftar kolom (hasil table_cols())
   * @param array $candidates kandidat kolom (urut prioritas)
   * @param mixed $fallback default bila tidak ada yg match (boleh null)
   * @return mixed string|null
   */
  function pick_col(array $cols, array $candidates, $fallback = null) {
    $map = [];
    foreach ($cols as $c) {
      if (!is_string($c)) continue;
      $map[strtolower($c)] = $c;
    }
    foreach ($candidates as $cand) {
      if (!is_string($cand) || $cand === '') continue;
      $k = strtolower($cand);
      if (isset($map[$k])) return $map[$k];
    }
    return $fallback;
  }
}

if (!function_exists('ensure_table')) {
  /**
   * Pastikan table ada. Kompat:
   *  - ensure_table($pdo, "CREATE TABLE IF NOT EXISTS ...")
   *  - ensure_table($pdo, "table_name", "CREATE TABLE ...")
   */
  function ensure_table(PDO $pdo, string $sqlOrTable, ?string $createSql = null): void {
    $table = '';
    $sql = '';

    if ($createSql === null) {
      $sql = $sqlOrTable;
      // coba parse nama tabel dari CREATE TABLE ...
      if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
        $table = $m[1] ?? '';
      }
    } else {
      $table = trim($sqlOrTable);
      $sql = $createSql;
    }

    // Kalau nama tabel kebaca, cek dulu via information_schema.
    if ($table !== '') {
      $st = $pdo->prepare("SELECT COUNT(*)\n                         FROM information_schema.tables\n                         WHERE table_schema = DATABASE()\n                           AND table_name = ?");
      $st->execute([$table]);
      $exists = (int)$st->fetchColumn();
      if ($exists > 0) return;
    }

    // Jalankan CREATE TABLE (idealnya sudah IF NOT EXISTS)
    $pdo->exec($sql);
  }
}

if (!function_exists('ensure_col')) {
  /**
   * Pastikan kolom ada. Jika belum ada → ALTER TABLE ADD COLUMN.
   * Contoh: ensure_col($pdo, 'wqs_stock', 'mnf_code', 'VARCHAR(50) NULL');
   */
  function ensure_col(PDO $pdo, string $table, string $col, string $definition): void {
    $table = trim($table);
    $col = trim($col);
    if ($table === '' || $col === '') return;

    $cols = table_cols($pdo, $table);
    $map = [];
    foreach ($cols as $c) {
      if (is_string($c)) $map[strtolower($c)] = true;
    }
    if (isset($map[strtolower($col)])) return;

    $def = trim($definition);
    if ($def === '') return;

    // Normalisasi jika definition mengulang nama kolom
    // contoh legacy: "source VARCHAR(40) NULL" → jadikan "VARCHAR(40) NULL"
    $def = preg_replace('/^ADD\s+COLUMN\s+/i', '', $def);
    $def = preg_replace('/^COLUMN\s+/i', '', $def);
    $colPattern = '/^`?' . preg_quote($col, '/') . '`?\s+/i';
    $def = preg_replace($colPattern, '', $def, 1);

    $ddl = "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}";
    $pdo->exec($ddl);
  }
}
