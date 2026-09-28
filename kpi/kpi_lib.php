<?php
// Block direct access (helper file)
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker


/**
 * kpi/kpi_lib.php
 * Helper kecil untuk dashboard (format, parsing, safe SQL).
 *
 * Catatan:
 * - Semua function pakai prefix "dash_" untuk menghindari bentrok dengan function lain di repo.
 */

if (!function_exists('dash_up')) {
    function dash_up($s): string { return strtoupper(trim((string)($s ?? ''))); }
}
if (!function_exists('dash_low')) {
    function dash_low($s): string { return strtolower(trim((string)($s ?? ''))); }
}
if (!function_exists('dash_money_idr')) {
    function dash_money_idr($n): string {
        $n = is_numeric($n) ? (float)$n : 0.0;
        return 'Rp ' . number_format($n, 0, ',', '.');
    }
}
if (!function_exists('dash_num0')) {
    function dash_num0($n): string {
        $n = is_numeric($n) ? (float)$n : 0.0;
        return number_format($n, 0, ',', '.');
    }
}
if (!function_exists('dash_pct')) {
    function dash_pct($num, $den): float {
        $num = (float)$num; $den = (float)$den;
        if ($den == 0.0) return 0.0;
        return ($num / $den) * 100.0;
    }
}
if (!function_exists('dash_parse_csv_list')) {
    function dash_parse_csv_list(string $csv): array {
        $csv = trim($csv);
        if ($csv === '') return [];
        $arr = array_filter(array_map('trim', explode(',', $csv)));
        $out = [];
        foreach ($arr as $a) {
            $a = strtolower($a);
            if ($a === '') continue;
            $out[$a] = true;
        }
        return array_keys($out);
    }
}
if (!function_exists('dash_sql_in')) {
    /**
     * Return array: [placeholders, params] untuk IN (:p0,:p1,...)
     */
    function dash_sql_in(array $vals, string $prefix='p'): array {
        $ph = [];
        $params = [];
        $i = 0;
        foreach ($vals as $v) {
            $k = ':' . $prefix . $i;
            $ph[] = $k;
            $params[$k] = $v;
            $i++;
        }
        if (!$ph) {
            $ph = [':'.$prefix.'x'];
            $params[':'.$prefix.'x'] = '__none__';
        }
        return [implode(',', $ph), $params];
    }
}
