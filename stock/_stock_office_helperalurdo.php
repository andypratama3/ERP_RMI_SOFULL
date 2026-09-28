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
        $officeCode = strtoupper(trim($officeCode));
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
        $officeCode = strtoupper(trim($officeCode));
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
        $officeCode = strtoupper(trim($officeCode));
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
        $officeCode = strtoupper(trim($officeCode));
        if ($officeCode === '') $officeCode = wqs_stock_default_office();

        $pdo->prepare("INSERT INTO wqs_stock_by_office (product_id, office_code, stock_qty, updated_at)
                       VALUES (?, ?, GREATEST(0, ?), NOW())
                       ON DUPLICATE KEY UPDATE stock_qty = GREATEST(0, stock_qty + ?), updated_at = NOW()")
            ->execute([$productId, $officeCode, $delta, $delta]);
    }
}
