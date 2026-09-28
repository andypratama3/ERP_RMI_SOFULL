<?php
/**
 * _shared/helpers.php
 *
 * Ini adalah satu-satunya lokasi implementasi helper `rmi_*` (shared).
 * `helpers.php` di root proyek hanya mem-*require* file ini — jangan menduplikat isi ke root.
 *
 * Tujuan (M2 Helpers):
 * - Helper standar lintas modul (tanpa bikin breaking change ke modul lama).
 * - Semua fungsi memakai prefix rmi_ supaya aman dari konflik.
 *
 * Prinsip:
 * - Output escaping: rmi_h() untuk mencegah XSS.
 * - Normalisasi kode/ID: rmi_norm_code(), rmi_safe_code().
 * - Keamanan upload: rmi_safe_filename(), rmi_allowed_ext().
 * - HTTP helpers: rmi_redirect(), rmi_json().
 * - Flash message: rmi_flash_set/get().
 * - CSRF minimal: rmi_csrf_token(), rmi_csrf_verify().
 *
 * Catatan penting:
 * - Jangan define fungsi generik seperti h() di sini dulu, karena banyak modul lama masih
 *   mendefinisikan h() tanpa guard. Standar enterprise kita: pakai rmi_h().
 */

declare(strict_types=1);

// ---------------------------
// Output Escaping (XSS)
// ---------------------------
if (!function_exists('rmi_h')) {
    function rmi_h(mixed $value): string {
        if ($value === null) return '';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_int($value) || is_float($value)) return (string)$value;
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        // array/object -> json string
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) $json = '';
        return htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// ---------------------------
// Display SKU & Product Name (konsisten UPPER)
// ---------------------------
if (!function_exists('rmi_sku')) {
    /** Normalisasi SKU untuk display/simpan: UPPER, trim. */
    function rmi_sku(mixed $v): string {
        return strtoupper(trim((string)($v ?? '')));
    }
}
if (!function_exists('rmi_product_name')) {
    /** Normalisasi nama produk untuk display/simpan: UPPER, trim. */
    function rmi_product_name(mixed $v): string {
        return strtoupper(trim((string)($v ?? '')));
    }
}

// ---------------------------
// Normalisasi code / ID
// ---------------------------
if (!function_exists('rmi_norm_code')) {
    /**
     * Normalisasi kode umum (PO/PR/SKU/...)
     * - trim
     * - spasi -> '-'
     * - '/' '\\' -> '-'
     * - karakter selain [A-Z0-9_-] diganti '-'
     * - collapse '-' beruntun
     */
    function rmi_norm_code(string $s, bool $upper = true, int $maxLen = 80): string {
        $s = trim($s);
        if ($s === '') return '';

        // ubah whitespace jadi '-'
        $s = preg_replace('/\s+/u', '-', $s) ?? $s;
        $s = str_replace(['/', '\\'], '-', $s);

        if ($upper) {
            $s = strtoupper($s);
        }

        // keep only A-Z0-9_- ; replace others with '-'
        $s = preg_replace('/[^A-Z0-9_\-]+/', '-', $s) ?? $s;
        $s = preg_replace('/-+/', '-', $s) ?? $s;
        $s = trim($s, "-_ ");

        if ($maxLen > 0 && strlen($s) > $maxLen) {
            $s = substr($s, 0, $maxLen);
            $s = rtrim($s, "-_ ");
        }

        return $s;
    }
}

if (!function_exists('rmi_safe_code')) {
    /**
     * Safe code untuk folder/path segment.
     * Lebih ketat dari rmi_norm_code():
     * - uppercase
     * - hanya [A-Z0-9_-]
     */
    function rmi_safe_code(string $s, int $maxLen = 80): string {
        $s = strtoupper(trim($s));
        $s = preg_replace('/\s+/u', '_', $s) ?? $s;
        $s = str_replace(['/', '\\'], '_', $s);
        $s = preg_replace('/[^A-Z0-9_\-]+/', '_', $s) ?? $s;
        $s = preg_replace('/_+/', '_', $s) ?? $s;
        $s = preg_replace('/-+/', '-', $s) ?? $s;
        $s = trim($s, "-_ ");

        if ($maxLen > 0 && strlen($s) > $maxLen) {
            $s = substr($s, 0, $maxLen);
            $s = rtrim($s, "-_ ");
        }

        if ($s === '') {
            // fallback minimal kalau input benar-benar kosong
            $s = 'CODE_' . strtoupper(bin2hex(random_bytes(3)));
        }
        return $s;
    }
}

// ---------------------------
// Upload filename safety
// ---------------------------
if (!function_exists('rmi_safe_filename')) {
    function rmi_safe_filename(string $name, int $maxLen = 120): string {
        $name = str_replace("\0", '', $name);
        // remove any directories
        $name = basename($name);

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = pathinfo($name, PATHINFO_FILENAME);

        // sanitize base
        $base = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $base) ?? $base;
        $base = trim($base, "._- ");
        if ($base === '') $base = 'file';

        // sanitize ext
        if ($ext !== '') {
            $ext = preg_replace('/[^A-Za-z0-9]+/', '', $ext) ?? '';
            $ext = substr($ext, 0, 10);
        }

        $filename = $base . ($ext !== '' ? '.' . $ext : '');

        if ($maxLen > 0 && strlen($filename) > $maxLen) {
            $keepExt = ($ext !== '' ? (1 + strlen($ext)) : 0);
            $maxBase = max(1, $maxLen - $keepExt);
            $base2 = substr($base, 0, $maxBase);
            $base2 = rtrim($base2, "._- ");
            if ($base2 === '') $base2 = 'file';
            $filename = $base2 . ($ext !== '' ? '.' . $ext : '');
        }

        return $filename;
    }
}

if (!function_exists('rmi_allowed_ext')) {
    /**
     * Check extension allowed.
     * $allowed = ['pdf','jpg','png']
     */
    function rmi_allowed_ext(string $filename, array $allowed): bool {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '') return false;
        $allowedLower = array_map(fn($x) => strtolower((string)$x), $allowed);
        return in_array($ext, $allowedLower, true);
    }
}

// ---------------------------
// Request helpers
// ---------------------------
if (!function_exists('rmi_get_str')) {
    function rmi_get_str(string $key, string $default = ''): string {
        $v = $_GET[$key] ?? null;
        if ($v === null) return $default;
        if (is_array($v)) return $default;
        return trim((string)$v);
    }
}

if (!function_exists('rmi_get_int')) {
    function rmi_get_int(string $key, int $default = 0): int {
        $v = $_GET[$key] ?? null;
        if ($v === null) return $default;
        if (is_array($v)) return $default;
        $v = trim((string)$v);
        if ($v === '') return $default;
        return (int)$v;
    }
}

if (!function_exists('rmi_post_str')) {
    function rmi_post_str(string $key, string $default = ''): string {
        $v = $_POST[$key] ?? null;
        if ($v === null) return $default;
        if (is_array($v)) return $default;
        return trim((string)$v);
    }
}

if (!function_exists('rmi_post_int')) {
    function rmi_post_int(string $key, int $default = 0): int {
        $v = $_POST[$key] ?? null;
        if ($v === null) return $default;
        if (is_array($v)) return $default;
        $v = trim((string)$v);
        if ($v === '') return $default;
        return (int)$v;
    }
}

if (!function_exists('rmi_require_method')) {
    function rmi_require_method(string $method): void {
        $method = strtoupper(trim($method));
        $cur = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($cur !== $method) {
            http_response_code(405);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Method Not Allowed";
            exit;
        }
    }
}

// ---------------------------
// Response helpers
// ---------------------------
if (!function_exists('rmi_redirect')) {
    /**
     * HTTP redirect lalu exit. Default 302; gunakan 301 untuk URL canonical / penggantian permanen.
     * Fallback ke JS/meta redirect jika headers sudah terkirim.
     */
    function rmi_redirect(string $to, int $status = 302): void {
        if (!headers_sent()) {
            if ($status >= 300 && $status < 400) {
                http_response_code($status);
            }
            header('Location: ' . $to);
        } else {
            $safe = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
            echo '<script>window.location.replace(' . json_encode($to) . ');</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safe . '"></noscript>';
        }
        exit;
    }
}

if (!function_exists('rmi_json')) {
    function rmi_json(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ---------------------------
// Flash messages
// ---------------------------
if (!function_exists('rmi_flash_set')) {
    function rmi_flash_set(string $key, string $message): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['_flash'][$key] = $message;
    }
}

if (!function_exists('rmi_flash_get')) {
    function rmi_flash_get(string $key, string $default = ''): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['_flash'][$key])) return $default;
        $msg = (string)$_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);
        return $msg;
    }
}

// ---------------------------
// CSRF minimal (untuk form penting)
// ---------------------------
if (!function_exists('rmi_csrf_token')) {
    function rmi_csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf']) || strlen($_SESSION['_csrf']) < 20) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('rmi_csrf_input')) {
    function rmi_csrf_input(): string {
        $t = rmi_csrf_token();
        return '<input type="hidden" name="_csrf" value="' . rmi_h($t) . '">';
    }
}

if (!function_exists('rmi_csrf_verify')) {
    function rmi_csrf_verify(?string $token = null): void {
    // Legacy-friendly: allow calling without args. We'll read token from POST or header.
    if ($token === null || $token === '') {
        $token = $_POST['_csrf'] ?? $_POST['csrf'] ?? null;
        if (($token === null || $token === '') && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        }
    }

    $ok = $token && hash_equals(rmi_csrf_token(), (string)$token);
    if (!$ok && $token && isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && strlen($_SESSION['csrf_token']) >= 20) {
        $ok = hash_equals($_SESSION['csrf_token'], (string)$token);
    }
    if (!$ok) {
        http_response_code(403);
        echo "Forbidden (CSRF)";
        exit;
    }
}

}

// ---------------------------
// Path traversal guard helpers (untuk upload folder)
// ---------------------------
if (!function_exists('rmi_path_join')) {
    function rmi_path_join(string ...$parts): string {
        $clean = [];
        foreach ($parts as $p) {
            $p = str_replace('\\', '/', $p);
            $p = trim($p, '/');
            if ($p !== '') $clean[] = $p;
        }
        return implode('/', $clean);
    }
}

if (!function_exists('rmi_realpath_is_under')) {
    /**
     * Pastikan $path ada di bawah $base (anti path traversal).
     * Note: realpath() butuh path exist. Untuk folder baru, create dulu baru cek.
     */
    function rmi_realpath_is_under(string $base, string $path): bool {
        $baseReal = realpath($base);
        $pathReal = realpath($path);
        if ($baseReal === false || $pathReal === false) return false;
        $baseReal = rtrim(str_replace('\\', '/', $baseReal), '/') . '/';
        $pathReal = rtrim(str_replace('\\', '/', $pathReal), '/') . '/';
        return str_starts_with($pathReal, $baseReal);
    }
}


// ---------------------------
// Compatibility aliases (for legacy pages expecting non-prefixed helpers)
// ---------------------------
// CSRF aliases
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        return rmi_csrf_token();
    }
}
if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return rmi_csrf_input();
    }
}
if (!function_exists('csrf_verify_or_die')) {
    function csrf_verify_or_die(): void {
        rmi_csrf_verify();
    }
}

// Upload filename alias
if (!function_exists('safe_filename')) {
    function safe_filename(string $name, int $maxLen = 120): string {
        return rmi_safe_filename($name, $maxLen);
    }
}

