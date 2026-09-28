<?php
/**
 * stock/_stock_office_helper.php
 * Helper untuk stock per office (branch).
 * Stock konsisten per branch — tidak lintas branch tanpa serah terima.
 * NOTE: Tidak menggunakan HO. Office code hanya BGR, BDG, BKS, TGR, SLO, SMG, KAL, JGY, SYS.
 */
declare(strict_types=1);

// Single source of truth: default office saat kosong (depo utama)
if (!defined('RMI_DEFAULT_OFFICE_CODE')) {
    define('RMI_DEFAULT_OFFICE_CODE', 'BGR');
}

if (!function_exists('wqs_stock_default_office')) {
    function wqs_stock_default_office(): string {
        return defined('RMI_DEFAULT_OFFICE_CODE') ? RMI_DEFAULT_OFFICE_CODE : 'BGR';
    }
}



/**
 * Normalisasi nilai office untuk perbandingan yang konsisten.
 */
if (!function_exists('rmi_office_normalize')) {
    function rmi_office_normalize(string $value): string {
        $value = strtoupper(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?: '';
        return $value;
    }
}

/**
 * Key pembanding nama/kode office.
 */
if (!function_exists('rmi_office_match_key')) {
    function rmi_office_match_key(string $value): string {
        $value = rmi_office_normalize($value);
        $value = str_replace(
            ['RIZQULLAH MEDISKA INDONESIA', 'PT ', 'KAB.', 'KOTA ', 'DEPO ', 'CABANG '],
            '',
            $value
        );
        return preg_replace('/[^A-Z0-9]/', '', $value) ?: '';
    }
}

/**
 * Daftar alias office. Nilai pertama selalu kode canonical bila ditemukan di master_office.
 */
if (!function_exists('rmi_office_aliases')) {
    function rmi_office_aliases(PDO $pdo, string $officeCode): array {
        $raw = rmi_office_normalize($officeCode);
        if ($raw === '') return [];

        $groups = [
            ['SMD', 'SMR', 'SAMARINDA', 'DEPO SAMARINDA'],
            ['MLG', 'MALANG', 'DEPO MALANG'],
        ];

        $aliases = [$raw];
        $rawKey = rmi_office_match_key($raw);
        foreach ($groups as $group) {
            $keys = array_map('rmi_office_match_key', $group);
            if (in_array($rawKey, $keys, true)) {
                foreach ($group as $v) $aliases[] = rmi_office_normalize($v);
            }
        }

        try {
            $cols = $pdo->query("SHOW COLUMNS FROM master_office")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (in_array('office_code', $cols, true)) {
                $select = ['office_code'];
                foreach (['office_name', 'city', 'address'] as $c) {
                    if (in_array($c, $cols, true)) $select[] = $c;
                }
                $sql = 'SELECT ' . implode(',', array_map(static fn($c) => "`{$c}`", $select)) . ' FROM master_office';
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $values = [];
                    foreach ($select as $c) {
                        $v = rmi_office_normalize((string)($row[$c] ?? ''));
                        if ($v !== '') $values[] = $v;
                    }
                    $matched = false;
                    foreach ($values as $v) {
                        $key = rmi_office_match_key($v);
                        if ($key !== '' && ($key === $rawKey || str_contains($key, $rawKey) || str_contains($rawKey, $key))) {
                            $matched = true;
                            break;
                        }
                    }
                    if ($matched) {
                        foreach ($values as $v) {
                            $aliases[] = $v;
                            if (str_starts_with($v, 'DEPO ')) $aliases[] = trim(substr($v, 5));
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Fail-soft: alias manual/raw tetap dapat dipakai.
        }

        $out = [];
        foreach ($aliases as $a) {
            $a = rmi_office_normalize((string)$a);
            if ($a !== '' && !in_array($a, $out, true)) $out[] = $a;
        }
        return $out;
    }
}

/**
 * Kembalikan office_code canonical yang benar-benar terdaftar di master_office.
 * Jika tidak ditemukan, gunakan kode input yang sudah dinormalisasi.
 */
if (!function_exists('rmi_office_canonical')) {
    function rmi_office_canonical(PDO $pdo, string $officeCode): string {
        $raw = rmi_office_normalize($officeCode);
        if ($raw === '') return '';

        $aliases = rmi_office_aliases($pdo, $raw);
        try {
            $ph = implode(',', array_fill(0, count($aliases), '?'));
            if ($ph !== '') {
                $st = $pdo->prepare("SELECT UPPER(TRIM(office_code)) FROM master_office WHERE UPPER(TRIM(office_code)) IN ({$ph}) ORDER BY CASE WHEN UPPER(TRIM(office_code)) = ? THEN 0 ELSE 1 END, office_code LIMIT 1");
                $st->execute(array_merge($aliases, [$raw]));
                $code = rmi_office_normalize((string)($st->fetchColumn() ?: ''));
                if ($code !== '') return $code;
            }
        } catch (Throwable $e) {
            // Fail-soft.
        }

        return $raw;
    }
}

if (!function_exists('rmi_office_same')) {
    function rmi_office_same(PDO $pdo, string $left, string $right): bool {
        $left = rmi_office_normalize($left);
        $right = rmi_office_normalize($right);
        if ($left === '' || $right === '') return false;
        if ($left === $right) return true;

        $leftAliases = rmi_office_aliases($pdo, $left);
        $rightAliases = rmi_office_aliases($pdo, $right);
        return count(array_intersect($leftAliases, $rightAliases)) > 0;
    }
}

/**
 * Tambahkan kondisi SQL office alias ke parameter positional yang diberikan.
 */
if (!function_exists('rmi_office_in_sql')) {
    function rmi_office_in_sql(PDO $pdo, string $sqlExpression, string $officeCode, array &$params): string {
        $aliases = rmi_office_aliases($pdo, $officeCode);
        if (!$aliases) $aliases = [rmi_office_normalize($officeCode)];
        $aliases = array_values(array_filter(array_unique($aliases)));
        $params = array_merge($params, $aliases);
        return 'UPPER(TRIM(' . $sqlExpression . ')) IN (' . implode(',', array_fill(0, count($aliases), '?')) . ')';
    }
}

if (!function_exists('wqs_stock_by_office_exists')) {
    function wqs_stock_by_office_exists(PDO $pdo): bool {
        try {
            $pdo->query("SELECT 1 FROM wqs_stock_by_office LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wqs_stock_office_add')) {
    /**
     * Tambah stock untuk office tertentu.
     */
    function wqs_stock_office_add(PDO $pdo, int $productId, float $qty, string $officeCode): void {
        $officeCode = rmi_office_canonical($pdo, $officeCode);
        if ($officeCode === '') $officeCode = wqs_stock_default_office();
        if ($qty <= 0) return;

        $pdo->prepare("INSERT INTO wqs_stock_by_office (product_id, office_code, stock_qty, updated_at)
                       VALUES (?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE stock_qty = stock_qty + VALUES(stock_qty), updated_at = NOW()")
            ->execute([$productId, $officeCode, $qty]);
    }
}

if (!function_exists('wqs_stock_office_reduce')) {
    /**
     * Kurangi stock untuk office tertentu.
     */
    function wqs_stock_office_reduce(PDO $pdo, int $productId, float $qty, string $officeCode): void {
        $officeCode = rmi_office_canonical($pdo, $officeCode);
        if ($officeCode === '') $officeCode = wqs_stock_default_office();
        if ($qty <= 0) return;

        $st = $pdo->prepare("SELECT stock_qty FROM wqs_stock_by_office WHERE product_id=? AND office_code=? LIMIT 1");
        $st->execute([$productId, $officeCode]);
        $r = $st->fetch();
        $new = $r ? max(0, (float)$r['stock_qty'] - $qty) : 0;

        if ($r) {
            $pdo->prepare("UPDATE wqs_stock_by_office SET stock_qty=?, updated_at=NOW() WHERE product_id=? AND office_code=?")
                ->execute([$new, $productId, $officeCode]);
        }
    }
}

if (!function_exists('wqs_stock_office_get')) {
    /**
     * Ambil stock qty untuk product + office.
     */
    function wqs_stock_office_get(PDO $pdo, int $productId, string $officeCode): float {
        $officeCode = rmi_office_canonical($pdo, $officeCode);
        if ($officeCode === '') $officeCode = wqs_stock_default_office();

        $st = $pdo->prepare("SELECT stock_qty FROM wqs_stock_by_office WHERE product_id=? AND office_code=? LIMIT 1");
        $st->execute([$productId, $officeCode]);
        $r = $st->fetch();
        return $r ? (float)$r['stock_qty'] : 0.0;
    }
}

if (!function_exists('wqs_stock_office_apply_delta')) {
    /**
     * Apply delta (bisa + atau -) ke stock office. Untuk opname.
     */
    function wqs_stock_office_apply_delta(PDO $pdo, int $productId, float $delta, string $officeCode): void {
        $officeCode = rmi_office_canonical($pdo, $officeCode);
        if ($officeCode === '') $officeCode = wqs_stock_default_office();

        $pdo->prepare("INSERT INTO wqs_stock_by_office (product_id, office_code, stock_qty, updated_at)
                       VALUES (?, ?, GREATEST(0, ?), NOW())
                       ON DUPLICATE KEY UPDATE stock_qty = GREATEST(0, stock_qty + ?), updated_at = NOW()")
            ->execute([$productId, $officeCode, $delta, $delta]);
    }
}
