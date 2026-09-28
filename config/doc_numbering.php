<?php
declare(strict_types=1);
/**
 * config/doc_numbering.php
 * ─────────────────────────────────────────────────────────────
 * Fungsi helper penomoran dokumen ERP.
 * Format prefix dibaca dari config/doc_numbering.json
 * Edit via web: master/doc_numbering_edit.php (SYS only)
 *
 * Placeholder di JSON:
 *   {CATEGORY} → kategori produk (BMHP / ALKES / AKSESORIS)
 *   {OFFICE}   → kode cabang (BGR, BKS, dll.)
 *   {DATE}     → tanggal format YYMMDD
 *   {YEAR}     → tahun 4 digit (YYYY)
 * ─────────────────────────────────────────────────────────────
 */

/** Kategori dokumen yang valid — harus sama dengan master_products.php */
const DOC_VALID_CATEGORIES = ['BMHP', 'ALKES', 'AKSESORIS'];

function _dn_load(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = __DIR__ . '/doc_numbering.json';
    if (!file_exists($file)) {
        $cfg = [
            'do'      => '{CATEGORY}-{OFFICE}-{DATE}-',
            'po'      => '{CATEGORY}-PO-{OFFICE}-{DATE}-',
            'pr'      => '{CATEGORY}-PR-{OFFICE}-{DATE}-',
            'ap'      => 'RMI-AP-{OFFICE}-{DATE}-',
            'pay'     => 'RMI-PAY-{OFFICE}-{DATE}-',
            'fap'     => 'RMI-FAP-{OFFICE}-{DATE}-',
            'fpay'    => 'RMI-FPAY-{OFFICE}-{DATE}-',
            'pib_pay' => 'RMI-PIB-PAY-{OFFICE}-{DATE}-',
            'rfq'     => 'RFQ-{YEAR}-',
        ];
        return $cfg;
    }
    $decoded = json_decode((string)file_get_contents($file), true);
    $cfg = is_array($decoded) ? $decoded : [];
    return $cfg;
}

function _dn_resolve(string $key, string $category = '', string $office = '', string $dateYmd = '', int $year = 0): string {
    $cfg = _dn_load();
    $tpl = (string)($cfg[$key] ?? '');
    // Sanitize category: hanya nilai valid, fallback ke BMHP
    $cat = strtoupper(trim($category));
    if (!in_array($cat, DOC_VALID_CATEGORIES, true)) $cat = 'BMHP';
    $tpl = str_replace('{CATEGORY}', $cat,                              $tpl);
    $tpl = str_replace('{OFFICE}',   strtoupper($office),               $tpl);
    $tpl = str_replace('{DATE}',     $dateYmd,                          $tpl);
    $tpl = str_replace('{YEAR}',     $year > 0 ? (string)$year : date('Y'), $tpl);
    return $tpl;
}

// DO — category wajib diisi (Opsi A: kategori di nomor)
function doc_prefix_do(string $office, string $dateYmd, string $category = 'BMHP'): string {
    return _dn_resolve('do', $category, $office, $dateYmd);
}

// PO — category dari form PO
function doc_prefix_po(string $office, string $dateYmd, string $category = 'BMHP'): string {
    return _dn_resolve('po', $category, $office, $dateYmd);
}

// PR — category dari form PR
function doc_prefix_pr(string $office, string $dateYmd, string $category = 'BMHP'): string {
    return _dn_resolve('pr', $category, $office, $dateYmd);
}

// AP, PAY, FAP, FPAY, PIB — tidak pakai {CATEGORY} (dokumen keuangan, tidak perlu)
function doc_prefix_ap(string $office, string $dateYmd): string      { return _dn_resolve('ap',      '', $office, $dateYmd); }
function doc_prefix_pay(string $office, string $dateYmd): string     { return _dn_resolve('pay',     '', $office, $dateYmd); }
function doc_prefix_fap(string $office, string $dateYmd): string     { return _dn_resolve('fap',     '', $office, $dateYmd); }
function doc_prefix_fpay(string $office, string $dateYmd): string    { return _dn_resolve('fpay',    '', $office, $dateYmd); }
function doc_prefix_pib_pay(string $office, string $dateYmd): string { return _dn_resolve('pib_pay', '', $office, $dateYmd); }

// RFQ — tidak pakai category / office
function doc_prefix_rfq(int $year): string { return _dn_resolve('rfq', '', '', '', $year); }
