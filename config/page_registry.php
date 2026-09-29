<?php
declare(strict_types=1);
require_once __DIR__ . '/../_shared/rmi_icons.php';
/**
 * config/page_registry.php — Satu registry halaman ERP × 9 kolom aksi (ACCESS, CREATE, APPROVE, EDIT, VIEW,
 * DELETE, IMPORT, EXPORT, PRINT). Kode per kolom = permission di RBAC Center (seed dari config/rbac_permissions.php).
 * Susunan mengikuti Nav Manager sections (nav_config.php).
 *
 * Aksi: access | create | approve | edit | view | delete | import | export | print
 *
 * Semantik (kolom UI dipisah; gate di halaman .php tidak berubah otomatis):
 * - access → boleh mengakses halaman/file (route / URL).
 * - view   → boleh melihat konten (baca/tampil); boleh kode sama atau beda dari access.
 * Kolom ACCESS dan VIEW di RBAC “per user” selalu terisi bila memungkina: jika Anda hanya set `access`, `view` disamakan
 * otomatis (dan sebaliknya jika hanya `view`) agar semua baris HALAMAN/URL konsisten di UI.
 * Runtime: jangan memakai require_any_permission([ACCESS, …, VIEW_LEBAR]) — gunakan require_route_access() untuk URL
 * dan require_content_view() bila konten wajib permission lihat terpisah (lihat master/auth.php).
 *
 * route_any (opsional): list kode — require_any_permission di rmi_page_registry_guard.
 * Untuk URL yang punya baris di file ini, gate can() untuk buka halaman = guard registry saja (require_rbac tidak mengulang rule['perms'] policy).
 *
 * special = true  → hanya SYS yang bisa assign (FIN approval, GL reversal, dll.)
 * sys_only = true → tidak ditampilkan untuk dept selain SYS
 */

// Helper: defaults + mirror access↔view agar setiap halaman punya kedua kolom di UI (kecuali keduanya null).
if (!function_exists('_p')) {
    function _p(array $p): array {
        $base = array_merge([
            'access' => null,
            'create' => null,
            'approve' => null,
            'edit' => null,
            'view' => null,
            'delete' => null,
            'import' => null,
            'export' => null,
            'print' => null,
        ], $p);
        $a = $base['access'];
        $v = $base['view'];
        if ($v === null && $a !== null) {
            $base['view'] = $a;
        } elseif ($a === null && $v !== null) {
            $base['access'] = $v;
        }
        return $base;
    }
}

$__rmi_registry = [

    // ══════════════════════════════════════════════════════════════════════════
    // MAIN — lintas semua dept
    // ══════════════════════════════════════════════════════════════════════════
    'MAIN' => [
        ['label'=>'Dashboard Center',        'url'=>'dashboards/index.php',
         'perms'=>_p(['access'=>'DASHBOARD.VIEW','view'=>'DASHBOARD.VIEW'])],
        ['label'=>'Executive Summary',       'url'=>'dashboards/owner/exec_summary.php',
         'note'=>'SYS & Manager senior','perms'=>_p(['access'=>'DASHBOARD.OWNER_SUMMARY','view'=>'DASHBOARD.OWNER_SUMMARY','export'=>'DASHBOARD.OWNER_VIEW'])],
        ['label'=>'Absensi — Check-in',     'url'=>'absensi/checkin.php',
         'perms'=>_p(['access'=>'ABSENSI.CHECKIN','view'=>'ABSENSI.VIEW'])],
        ['label'=>'Absensi — Check-out',    'url'=>'absensi/checkout.php',
         'perms'=>_p(['access'=>'ABSENSI.CHECKIN','view'=>'ABSENSI.VIEW'])],
        ['label'=>'Absensi — Riwayat',      'url'=>'absensi/history.php',
         'perms'=>_p(['access'=>'ABSENSI.VIEW','view'=>'ABSENSI.VIEW'])],
        ['label'=>'Absensi — Izin/Dinas',   'url'=>'absensi/izin.php',
         'perms'=>_p(['access'=>'ABSENSI.REQUEST','create'=>'ABSENSI.REQUEST','view'=>'ABSENSI.VIEW'])],
        ['label'=>'Absensi — Pengajuan',    'url'=>'absensi/request.php',
         'perms'=>_p(['access'=>'ABSENSI.REQUEST','create'=>'ABSENSI.REQUEST','edit'=>'ABSENSI.REQUEST_EDIT','view'=>'ABSENSI.VIEW','delete'=>'ABSENSI.REQUEST_DELETE'])],
        ['label'=>'Absensi — Approval',     'url'=>'absensi/approval.php',
         'perms'=>_p(['access'=>'ABSENSI.APPROVE','approve'=>'ABSENSI.APPROVE','view'=>'ABSENSI.VIEW'])],
        ['label'=>'Absensi — Panduan',      'url'=>'absensi/panduan.php',
         'perms'=>_p(['access'=>'ABSENSI.VIEW','view'=>'ABSENSI.VIEW'])],
        ['label'=>'KPI Dashboard (Daily)',  'url'=>'kpi/kpi_dashboard_daily.php',
         'perms'=>_p(['access'=>'KPI.VIEW','view'=>'KPI.VIEW','export'=>'KPI.DO_AUDIT'])],
        ['label'=>'KPI Dashboard (Monthly)','url'=>'kpi/kpi_dashboard_monthly.php',
         'perms'=>_p(['access'=>'KPI.VIEW','view'=>'KPI.VIEW','export'=>'KPI.DO_AUDIT'])],
        ['label'=>'Internal Chat',          'url'=>'chat/index.php',
         'perms'=>_p(['access'=>'CHAT.VIEW','create'=>'CHAT.VIEW','view'=>'CHAT.VIEW'])],
        ['label'=>'Help Center',            'url'=>'docs/help_center.php',
         'perms'=>_p(['access'=>'DOCS.VIEW','view'=>'DOCS.VIEW'])],
        ['label'=>'Modules Hub',            'url'=>'docs/modules_hub.php',
         'perms'=>_p(['access'=>'DOCS.VIEW','view'=>'DOCS.VIEW'])],
        ['label'=>'Struktur Organisasi',    'url'=>'docs/link/struktur_organisasi.php',
         'perms'=>_p(['access'=>'DOCS.VIEW','view'=>'DOCS.VIEW'])],
        ['label'=>'User Guide RMI',         'url'=>'docs/link/user_guide_rmi.php',
         'perms'=>_p(['access'=>'DOCS.VIEW','view'=>'DOCS.VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // CRM / SALES
    // ══════════════════════════════════════════════════════════════════════════
    'CRM / SALES' => [
        ['label'=>'Sales Dashboard',         'url'=>'sales/sales_dashboard.php',
         'perms'=>_p(['access'=>'SALES.VIEW','view'=>'SALES.VIEW'])],
        ['label'=>'Sales Control Tower',     'url'=>'sales/sales_control_tower.php',
         'route_any'=>['SALES.CONTROL_TOWER_VIEW','SALES.EDIT'],
         'perms'=>_p(['access'=>'SALES.CONTROL_TOWER_VIEW','view'=>'SALES.VIEW','export'=>'SALES.EXPORT'])],
        ['label'=>'DO / Surat Jalan',        'url'=>'sales/sales_do.php',
         'perms'=>_p(['access'=>'SALES.VIEW','create'=>'SALES.CREATE','edit'=>'SALES.EDIT','view'=>'SALES.VIEW','delete'=>'SALES.DELETE','export'=>'SALES.EXPORT','print'=>'SALES.PRINT'])],
        ['label'=>'DO — Detail / View',      'url'=>'sales/sales_do_view.php',
         'perms'=>_p(['access'=>'SALES.VIEW','view'=>'SALES.VIEW','print'=>'SALES.PRINT'])],
        ['label'=>'DO — Print CF',           'url'=>'sales/sales_do_print_cf.php',
         'perms'=>_p(['access'=>'SALES.PRINT','view'=>'SALES.VIEW','print'=>'SALES.PRINT'])],
        ['label'=>'DO — Rekap',              'url'=>'sales/sales_do_rekap.php',
         'perms'=>_p(['access'=>'SALES.AUDIT','view'=>'SALES.AUDIT','export'=>'SALES.EXPORT'])],
        ['label'=>'DO — Download Dokumen',   'url'=>'sales/sales_do_doc_download.php',
         'perms'=>_p(['access'=>'SALES.VIEW','view'=>'SALES.VIEW'])],
        ['label'=>'Tax Invoice',             'url'=>'sales/tax_invoices.php',
         'perms'=>_p(['access'=>'SALES.VIEW','view'=>'SALES.VIEW','export'=>'SALES.EXPORT'])],
        ['label'=>'Export KPI DO CSV',       'url'=>'sales/export_kpi_do_csv.php',
         'perms'=>_p(['access'=>'SALES.AUDIT','view'=>'SALES.AUDIT','export'=>'SALES.EXPORT'])],
        ['label'=>'WQS Task DO',             'url'=>'stock/wqs_do_tasks.php',
         'perms'=>_p(['access'=>'SALES.VIEW','edit'=>'SALES.EDIT','view'=>'SALES.VIEW'])],
        ['label'=>'SCM Task DO',             'url'=>'sales/scm_do_tasks.php',
         'perms'=>_p(['access'=>'SALES.VIEW','edit'=>'SALES.EDIT','view'=>'SALES.VIEW'])],
        ['label'=>'ACT Task DO',             'url'=>'sales/act_do_tasks.php',
         'perms'=>_p(['access'=>'SALES.VIEW','edit'=>'SALES.EDIT','view'=>'SALES.VIEW'])],
        ['label'=>'FIN Task DO',             'url'=>'sales/fin_do_tasks.php',
         'perms'=>_p(['access'=>'SALES.VIEW','edit'=>'SALES.EDIT','view'=>'SALES.VIEW'])],
        ['label'=>'KPI DO Audit',            'url'=>'sales/kpi_do_audit.php',
         'perms'=>_p(['access'=>'SALES.AUDIT','view'=>'SALES.AUDIT','export'=>'SALES.EXPORT'])],
        ['label'=>'KPI SLA DO',              'url'=>'sales/kpi_do_sla.php',
         'route_any'=>['SALES.TRACKING_VIEW','SALES.AUDIT'],
         'perms'=>_p(['access'=>'SALES.TRACKING_VIEW','view'=>'SALES.TRACKING_VIEW','export'=>'SALES.EXPORT'])],
        ['label'=>'SCM Tracker Mobile',      'url'=>'sales/scm_tracker_mobile.php',
         'route_any'=>['SALES.EDIT','MASTER.ADMIN_CENTER'],
         'perms'=>_p(['access'=>'SALES.EDIT','edit'=>'SALES.EDIT','view'=>'SALES.VIEW'])],
        ['label'=>'Panduan Control Tower',   'url'=>'sales/panduan_control_tower.php',
         'perms'=>_p(['access'=>'SALES.VIEW','view'=>'SALES.VIEW'])],
        ['label'=>'Panduan Task DO',         'url'=>'sales/panduan_do_tasks.php',
         'perms'=>_p(['access'=>'SALES.VIEW','view'=>'SALES.VIEW'])],
        ['label'=>'Panduan Sales & DO',      'url'=>'sales/panduan.php',
         'perms'=>_p(['access'=>'PANDUAN.SALES_VIEW','view'=>'PANDUAN.SALES_VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // SCM
    // ══════════════════════════════════════════════════════════════════════════
    'SCM' => [
        ['label'=>'SCM Dashboard',           'url'=>'dashboards/scm/scm_dashboard.php',
         'perms'=>_p(['access'=>'DASHBOARD.SCM_VIEW','view'=>'DASHBOARD.SCM_VIEW'])],
        ['label'=>'SCM — Panduan',           'url'=>'dashboards/scm/panduan.php',
         'perms'=>_p(['access'=>'DASHBOARD.SCM_VIEW','view'=>'DASHBOARD.SCM_VIEW'])],
        ['label'=>'Import Control Tower',    'url'=>'purchases/purchases_import_control_tower.php',
         'route_any'=>['PURCHASES.IMPORT_CONTROL'],
         'perms'=>_p(['access'=>'PURCHASES.IMPORT_CONTROL','edit'=>'PURCHASES.IMPORT_CONTROL_EDIT','view'=>'PURCHASES.IMPORT_CONTROL','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Import CT — Detail',      'url'=>'purchases/purchases_import_control_view.php',
         'perms'=>_p(['access'=>'PURCHASES.IMPORT_CONTROL','view'=>'PURCHASES.IMPORT_CONTROL'])],
        ['label'=>'Forwarding Tasks',        'url'=>'purchases/purchases_forwarding_tasks.php',
         'route_any'=>['PURCHASES.FORWARDING_VIEW','PURCHASES.FORWARDING_CREATE','PURCHASES.FORWARDING_EDIT'],
         'perms'=>_p(['access'=>'PURCHASES.FORWARDING_VIEW','edit'=>'PURCHASES.FORWARDING_EDIT','view'=>'PURCHASES.FORWARDING_VIEW'])],
        ['label'=>'Forwarder Quotes',        'url'=>'purchases/purchases_forwarder_quotes.php',
         'route_any'=>['PURCHASES.FORWARDING_VIEW','PURCHASES.FORWARDING_CREATE','PURCHASES.FORWARDING_EDIT'],
         'perms'=>_p(['access'=>'PURCHASES.FORWARDING_VIEW','create'=>'PURCHASES.FORWARDING_CREATE','edit'=>'PURCHASES.FORWARDING_EDIT','view'=>'PURCHASES.FORWARDING_VIEW','delete'=>'PURCHASES.FORWARDING_DELETE','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Panduan Import Tower',    'url'=>'purchases/panduan_import_tower.php',
         'perms'=>_p(['access'=>'PANDUAN.PURCHASES_VIEW','view'=>'PANDUAN.PURCHASES_VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // WQS / STOCK / WAREHOUSE
    // ══════════════════════════════════════════════════════════════════════════
    'WQS / STOCK' => [
        ['label'=>'WQS Dashboard',           'url'=>'dashboards/warehouse/wqs_dashboard.php',
         'perms'=>_p(['access'=>'DASHBOARD.WAREHOUSE_VIEW','view'=>'DASHBOARD.WAREHOUSE_VIEW','export'=>'STOCK.AUDIT'])],
        ['label'=>'WQS Dashboard — Export',  'url'=>'dashboards/warehouse/wqs_dashboard_export.php',
         'perms'=>_p(['access'=>'STOCK.AUDIT','view'=>'STOCK.AUDIT','export'=>'STOCK.AUDIT'])],
        ['label'=>'Warehouse — Panduan',     'url'=>'dashboards/warehouse/panduan.php',
         'perms'=>_p(['access'=>'DASHBOARD.WAREHOUSE_VIEW','view'=>'DASHBOARD.WAREHOUSE_VIEW'])],
        ['label'=>'Stock Overview',          'url'=>'stock/wqs_stock.php',
         'route_any'=>['WQS.INCOMING_VIEW','WQS.INCOMING_CREATE','WQS.INCOMING_EDIT','STOCK.CREATE','WQS.PR_VIEW','WQS.PR_CREATE','WQS.PR_EDIT','WQS.PICKING_VIEW','WQS.PICKING_CREATE','WQS.PICKING_EDIT'],
         'perms'=>_p(['access'=>'STOCK.VIEW','view'=>'STOCK.VIEW','export'=>'STOCK.AUDIT'])],
        ['label'=>'Stock Audit Log',         'url'=>'stock/wqs_stock_audit.php',
         'perms'=>_p(['access'=>'STOCK.AUDIT','view'=>'STOCK.AUDIT','export'=>'STOCK.AUDIT'])],
        ['label'=>'Stock Opname',            'url'=>'stock/wqs_stock_opname.php',
         'perms'=>_p(['access'=>'STOCK.VIEW','create'=>'STOCK.CREATE','edit'=>'STOCK.EDIT','view'=>'STOCK.VIEW'])],
        ['label'=>'Opname Report',           'url'=>'stock/wqs_stock_opname_report.php',
         'perms'=>_p(['access'=>'STOCK.AUDIT','view'=>'STOCK.AUDIT','export'=>'STOCK.AUDIT'])],
        ['label'=>'Stock Adjustment',        'url'=>'stock/wqs_stock_adjustment.php',
         'perms'=>_p(['access'=>'STOCK.VIEW','create'=>'STOCK.CREATE','edit'=>'STOCK.EDIT','view'=>'STOCK.VIEW','delete'=>'STOCK.DELETE','export'=>'STOCK.AUDIT'])],
        ['label'=>'Transfer Stok',           'url'=>'stock/wqs_stock_transfer.php',
         'note'=>'Create = Manager/SYS',
         'perms'=>_p(['access'=>'WQS.TRANSFER_VIEW','create'=>'WQS.TRANSFER_CREATE','view'=>'WQS.TRANSFER_VIEW'])],
        ['label'=>'Incoming Barang',         'url'=>'stock/wqs_incoming.php',
         'perms'=>_p(['access'=>'WQS.INCOMING_VIEW','create'=>'WQS.INCOMING_CREATE','edit'=>'WQS.INCOMING_EDIT','view'=>'WQS.INCOMING_VIEW','delete'=>'WQS.INCOMING_DELETE'])],
        ['label'=>'Incoming — View Detail',  'url'=>'stock/wqs_incoming_view.php',
         'perms'=>_p(['access'=>'WQS.INCOMING_VIEW','view'=>'WQS.INCOMING_VIEW'])],
        ['label'=>'Picking DO',              'url'=>'stock/wqs_picking.php',
         'perms'=>_p(['access'=>'WQS.PICKING_VIEW','create'=>'WQS.PICKING_CREATE','edit'=>'WQS.PICKING_EDIT','view'=>'WQS.PICKING_VIEW','delete'=>'WQS.PICKING_DELETE'])],
        ['label'=>'Picking — View Detail',   'url'=>'stock/wqs_picking_view.php',
         'perms'=>_p(['access'=>'WQS.PICKING_VIEW','view'=>'WQS.PICKING_VIEW'])],
        ['label'=>'Allocation Stok',         'url'=>'stock/wqs_allocation.php',
         'perms'=>_p(['access'=>'WQS.ALLOCATION_VIEW','create'=>'WQS.ALLOCATION_CREATE','edit'=>'WQS.ALLOCATION_EDIT','view'=>'WQS.ALLOCATION_VIEW','delete'=>'WQS.ALLOCATION_DELETE'])],
        ['label'=>'Purchase Request (PR)',   'url'=>'stock/wqs_pr.php',
         'perms'=>_p(['access'=>'WQS.PR_VIEW','create'=>'WQS.PR_CREATE','edit'=>'WQS.PR_EDIT','view'=>'WQS.PR_VIEW','delete'=>'WQS.PR_DELETE','print'=>'WQS.PR_PRINT'])],
        ['label'=>'PR — View Detail',        'url'=>'stock/wqs_pr_view.php',
         'perms'=>_p(['access'=>'WQS.PR_VIEW','view'=>'WQS.PR_VIEW'])],
        ['label'=>'PR — Print',              'url'=>'stock/wqs_pr_print.php',
         'perms'=>_p(['access'=>'WQS.PR_VIEW','view'=>'WQS.PR_VIEW','print'=>'WQS.PR_PRINT'])],
        ['label'=>'Stock — Panduan',         'url'=>'stock/panduan.php',
         'perms'=>_p(['access'=>'STOCK.VIEW','view'=>'STOCK.VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // PQP / PURCHASES
    // ══════════════════════════════════════════════════════════════════════════
    'PQP / PURCHASES' => [
        ['label'=>'Purchases Dashboard',     'url'=>'purchases/purchases_dashboard.php',
         'route_any'=>['PURCHASES.VIEW','DASHBOARD.PROCUREMENT_VIEW'],
         'perms'=>_p(['access'=>'PURCHASES.VIEW','view'=>'PURCHASES.VIEW'])],
        ['label'=>'RFQ',                     'url'=>'purchases/pqp_rfq.php',
         'perms'=>_p(['access'=>'PQP.VIEW','create'=>'PQP.CREATE','edit'=>'PQP.EDIT','view'=>'PQP.VIEW','delete'=>'PQP.DELETE','export'=>'PQP.QUALITY_VIEW'])],
        ['label'=>'RFQ — Export',            'url'=>'purchases/pqp_rfq_export.php',
         'perms'=>_p(['access'=>'PQP.VIEW','view'=>'PQP.VIEW','export'=>'PQP.QUALITY_VIEW'])],
        ['label'=>'Purchase Order (PO)',      'url'=>'purchases/purchases_po.php',
         'perms'=>_p(['access'=>'PURCHASES.PO_VIEW','create'=>'PURCHASES.PO_CREATE','approve'=>'PURCHASES.PO_APPROVE','edit'=>'PURCHASES.PO_EDIT','view'=>'PURCHASES.PO_VIEW','delete'=>'PURCHASES.PO_DELETE','export'=>'PURCHASES.EXPORT','print'=>'PURCHASES.PO_PRINT'])],
        ['label'=>'PO — View Detail',        'url'=>'purchases/purchases_po_view.php',
         'perms'=>_p(['access'=>'PURCHASES.PO_VIEW','view'=>'PURCHASES.PO_VIEW','print'=>'PURCHASES.PO_PRINT'])],
        ['label'=>'PO — Print',              'url'=>'purchases/purchases_po_print.php',
         'perms'=>_p(['access'=>'PURCHASES.PO_VIEW','view'=>'PURCHASES.PO_VIEW','print'=>'PURCHASES.PO_PRINT'])],
        ['label'=>'Goods Receipt (GR)',       'url'=>'purchases/purchases_gr.php',
         'route_any'=>['PURCHASES.GR_VIEW','PURCHASES.GR_PROCESS','WQS.INCOMING_VIEW','WQS.INCOMING_CREATE','WQS.INCOMING_EDIT'],
         'perms'=>_p(['access'=>'PURCHASES.GR_VIEW','create'=>'PURCHASES.GR_PROCESS','edit'=>'PURCHASES.GR_EDIT','view'=>'PURCHASES.GR_VIEW','delete'=>'PURCHASES.GR_DELETE','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'CEISA / PIB',             'url'=>'purchases/purchases_ceisa_pib.php',
         'route_any'=>['PURCHASES.CEISA_VIEW','PURCHASES.CEISA_EDIT','PURCHASES.CEISA_PIB'],
         'perms'=>_p(['access'=>'PURCHASES.CEISA_VIEW','edit'=>'PURCHASES.CEISA_EDIT','view'=>'PURCHASES.CEISA_VIEW','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'CEISA / PIB — View',      'url'=>'purchases/purchases_ceisa_pib_view.php',
         'route_any'=>['PURCHASES.CEISA_VIEW','PURCHASES.CEISA_PIB'],
         'perms'=>_p(['access'=>'PURCHASES.CEISA_VIEW','view'=>'PURCHASES.CEISA_VIEW','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Purchases Reports',       'url'=>'purchases/purchases_reports.php',
         'perms'=>_p(['access'=>'PURCHASES.REPORTS_VIEW','view'=>'PURCHASES.REPORTS_VIEW','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Panduan Purchases',       'url'=>'purchases/panduan.php',
         'perms'=>_p(['access'=>'PANDUAN.PURCHASES_VIEW','view'=>'PANDUAN.PURCHASES_VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // FIN — Finance
    // ══════════════════════════════════════════════════════════════════════════
    'FIN' => [
        ['label'=>'Finance Dashboard',           'url'=>'dashboards/finance/ar_ap_cash_dashboard.php',
         'perms'=>_p(['access'=>'DASHBOARD.FINANCE_VIEW','view'=>'DASHBOARD.FINANCE_VIEW','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Finance — AP Rekap',          'url'=>'dashboards/finance/ap_rekap.php',
         'perms'=>_p(['access'=>'DASHBOARD.FINANCE_VIEW','view'=>'DASHBOARD.FINANCE_VIEW','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Finance — GL Rekap',          'url'=>'dashboards/finance/gl_rekap.php',
         'perms'=>_p(['access'=>'DASHBOARD.FINANCE_VIEW','view'=>'DASHBOARD.FINANCE_VIEW','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Finance — Dashboard Detail',  'url'=>'dashboards/finance/dashboard_detail.php',
         'perms'=>_p(['access'=>'DASHBOARD.FINANCE_DETAIL','view'=>'DASHBOARD.FINANCE_DETAIL'])],
        ['label'=>'Finance — Sales DO Rekap',    'url'=>'dashboards/finance/sales_do_rekap.php',
         // Shared Sales/Finance recap: CRM/Sales users may open it with SALES.VIEW,
         // while existing FIN users retain DASHBOARD.FINANCE_VIEW.
         'route_any'=>['DASHBOARD.FINANCE_VIEW','DASHBOARD.SALES_VIEW','SALES.DASHBOARD_VIEW','SALES.VIEW'],
         'perms'=>_p(['access'=>'DASHBOARD.FINANCE_VIEW','view'=>'DASHBOARD.FINANCE_VIEW','export'=>'SALES.EXPORT'])],
        ['label'=>'Finance — Target Rekap',      'url'=>'dashboards/finance/target_rekap.php',
         'perms'=>_p(['access'=>'DASHBOARD.FINANCE_VIEW','view'=>'DASHBOARD.FINANCE_VIEW','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Finance — Panduan',           'url'=>'dashboards/finance/panduan.php',
         'perms'=>_p(['access'=>'DASHBOARD.FINANCE_VIEW','view'=>'DASHBOARD.FINANCE_VIEW'])],
        ['label'=>'AP Invoice',                  'url'=>'purchases/purchases_invoice_ap.php',
         'perms'=>_p(['access'=>'PURCHASES.AP_INVOICE_VIEW','create'=>'PURCHASES.AP_INVOICE_CREATE','edit'=>'PURCHASES.AP_INVOICE_EDIT','view'=>'PURCHASES.AP_INVOICE_VIEW','delete'=>'PURCHASES.AP_INVOICE_DELETE','export'=>'PURCHASES.EXPORT','print'=>'PURCHASES.PO_PRINT'])],
        ['label'=>'AP Invoice — Edit',           'url'=>'purchases/purchases_invoice_ap_edit.php',
         'route_any'=>['PURCHASES.AP_INVOICE_VIEW','PURCHASES.AP_INVOICE_CREATE','PURCHASES.AP_INVOICE_EDIT','PURCHASES.AP_PAYMENT_VIEW','PURCHASES.AP_PAYMENT_CREATE','PURCHASES.AP_PAYMENT_EDIT'],
         'perms'=>_p(['access'=>'PURCHASES.AP_INVOICE_EDIT','edit'=>'PURCHASES.AP_INVOICE_EDIT','view'=>'PURCHASES.AP_INVOICE_VIEW'])],
        ['label'=>'AP Payment',                  'url'=>'purchases/purchases_payment_ap.php',
         'perms'=>_p(['access'=>'PURCHASES.AP_PAYMENT_VIEW','create'=>'PURCHASES.AP_PAYMENT_CREATE','edit'=>'PURCHASES.AP_PAYMENT_EDIT','view'=>'PURCHASES.AP_PAYMENT_VIEW','delete'=>'PURCHASES.AP_PAYMENT_DELETE','export'=>'PURCHASES.EXPORT'])],
        ['label'=>rmi_icon('target') . ' GL Reversal Approve',      'url'=>'purchases/gl_reversal_approvals.php',
         'note'=>'SYS assign ke MgrFIN_BGR saja','special'=>true,
         'perms'=>_p(['access'=>'PURCHASES.GL_REVERSAL_APPROVE','approve'=>'PURCHASES.GL_REVERSAL_APPROVE','view'=>'PURCHASES.GL_REVERSAL_APPROVE'])],
        ['label'=>'GL Auto (Admin)',             'url'=>'purchases/fin_gl_auto.php',
         'note'=>'SYS/Admin only','sys_only'=>true,
         'perms'=>_p(['access'=>'PURCHASES.ADMIN_GL_AUTO','edit'=>'PURCHASES.ADMIN_GL_AUTO','view'=>'PURCHASES.ADMIN_GL_AUTO'])],
        ['label'=>'Forwarder AP Invoice',        'url'=>'purchases/purchases_forwarder_invoice.php',
         'route_any'=>['PURCHASES.AP_INVOICE_VIEW','PURCHASES.AP_INVOICE_CREATE','PURCHASES.AP_INVOICE_EDIT','PURCHASES.FORWARDING_VIEW','PURCHASES.FORWARDING_CREATE','PURCHASES.FORWARDING_EDIT'],
         'perms'=>_p(['access'=>'PURCHASES.FORWARDING_VIEW','create'=>'PURCHASES.FORWARDING_CREATE','edit'=>'PURCHASES.FORWARDING_EDIT','view'=>'PURCHASES.FORWARDING_VIEW','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Forwarder Payment',           'url'=>'purchases/purchases_forwarder_payment.php',
         'route_any'=>['PURCHASES.AP_PAYMENT_VIEW','PURCHASES.AP_PAYMENT_CREATE','PURCHASES.AP_PAYMENT_EDIT','PURCHASES.FORWARDING_VIEW','PURCHASES.FORWARDING_CREATE','PURCHASES.FORWARDING_EDIT'],
         'perms'=>_p(['access'=>'PURCHASES.FORWARDING_VIEW','create'=>'PURCHASES.FORWARDING_CREATE','edit'=>'PURCHASES.FORWARDING_EDIT','view'=>'PURCHASES.FORWARDING_VIEW','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Bank Rekonsiliasi',           'url'=>'purchases/bank_recon.php',
         'perms'=>_p(['access'=>'PURCHASES.AP_PAYMENT_VIEW','view'=>'PURCHASES.AP_PAYMENT_VIEW','export'=>'PURCHASES.EXPORT'])],
        ['label'=>'Bank Statement Import',       'url'=>'purchases/bank_statement_import.php',
         'perms'=>_p(['access'=>'PURCHASES.AP_PAYMENT_VIEW','view'=>'PURCHASES.AP_PAYMENT_VIEW','import'=>'PURCHASES.AP_PAYMENT_CREATE'])],
        ['label'=>'Rekening Perusahaan',         'url'=>'master/company_bank_accounts.php',
         'perms'=>_p(['access'=>'MASTER.COMPANY_BANK_VIEW','create'=>'MASTER.COMPANY_BANK_CREATE','edit'=>'MASTER.COMPANY_BANK_EDIT','view'=>'MASTER.COMPANY_BANK_VIEW','delete'=>'MASTER.COMPANY_BANK_DELETE'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // ACT — Akuntansi
    // ══════════════════════════════════════════════════════════════════════════
    'ACT' => [
        ['label'=>'ACT Dashboard',           'url'=>'dashboards/act/act_dashboard.php',
         'perms'=>_p(['access'=>'DASHBOARD.ACT_VIEW','view'=>'DASHBOARD.ACT_VIEW'])],
        ['label'=>'ACT Dashboard — Panduan', 'url'=>'dashboards/act/panduan.php',
         'perms'=>_p(['access'=>'DASHBOARD.ACT_VIEW','view'=>'DASHBOARD.ACT_VIEW'])],
        ['label'=>'Master Tax',              'url'=>'master/master_tax.php',
         'perms'=>_p(['access'=>'MASTER.TAX_VIEW','create'=>'MASTER.TAX_CREATE','edit'=>'MASTER.TAX_EDIT','view'=>'MASTER.TAX_VIEW','delete'=>'MASTER.TAX_DELETE'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // FIXED ASSET
    // ══════════════════════════════════════════════════════════════════════════
    'FIXED ASSET' => [
        ['label'=>'Fixed Asset Dashboard',   'url'=>'Fixed_Asset/index.php',
         'perms'=>_p(['access'=>'FIXED_ASSET.VIEW','view'=>'FIXED_ASSET.VIEW'])],
        ['label'=>'Daftar Aset',             'url'=>'Fixed_Asset/assets.php',
         'route_any'=>['FIXED_ASSET.ASSET_CRUD','FIXED_ASSET.ASSET_VIEW'],
         'perms'=>_p(['access'=>'FIXED_ASSET.ASSET_CRUD','view'=>'FIXED_ASSET.ASSET_VIEW','export'=>'FIXED_ASSET.AUDIT'])],
        ['label'=>'Operasional Aset',        'url'=>'Fixed_Asset/ops.php',
         'route_any'=>['FIXED_ASSET.OPERATIONS','FIXED_ASSET.OPS_VIEW'],
         'perms'=>_p(['access'=>'FIXED_ASSET.OPERATIONS','edit'=>'FIXED_ASSET.OPS_EDIT','view'=>'FIXED_ASSET.OPS_VIEW'])],
        ['label'=>'Depresiasi Aset',         'url'=>'Fixed_Asset/depreciation.php',
         'note'=>'Run = Manager/SYS',
         'perms'=>_p(['access'=>'FIXED_ASSET.VIEW','edit'=>'FIXED_ASSET.DEPRECIATION_RUN','view'=>'FIXED_ASSET.VIEW','export'=>'FIXED_ASSET.AUDIT'])],
        ['label'=>'Tax Tahunan Aset',        'url'=>'Fixed_Asset/tax_annual.php',
         'route_any'=>['FIXED_ASSET.TAX_ANNUAL','FIXED_ASSET.TAX_ANNUAL_VIEW'],
         'perms'=>_p(['access'=>'FIXED_ASSET.TAX_ANNUAL','edit'=>'FIXED_ASSET.TAX_ANNUAL_EDIT','view'=>'FIXED_ASSET.TAX_ANNUAL_VIEW','export'=>'FIXED_ASSET.AUDIT'])],
        ['label'=>'Audit Aset',              'url'=>'Fixed_Asset/audit.php',
         'route_any'=>['FIXED_ASSET.AUDIT_VIEW','FIXED_ASSET.AUDIT'],
         'perms'=>_p(['access'=>'FIXED_ASSET.AUDIT_VIEW','view'=>'FIXED_ASSET.AUDIT'])],
        ['label'=>'Fixed Asset — Panduan',   'url'=>'Fixed_Asset/panduan.php',
         'perms'=>_p(['access'=>'FIXED_ASSET.VIEW','view'=>'FIXED_ASSET.VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // HRL — Human Resources & Legal
    // ══════════════════════════════════════════════════════════════════════════
    'HRL' => [
        ['label'=>'HRL Dashboard',           'url'=>'dashboards/hrl/hrl_dashboard.php',
         'perms'=>_p(['access'=>'DASHBOARD.HRL_VIEW','view'=>'DASHBOARD.HRL_VIEW'])],
        ['label'=>'HRL Dashboard — Panduan', 'url'=>'dashboards/hrl/panduan.php',
         'perms'=>_p(['access'=>'DASHBOARD.HRL_VIEW','view'=>'DASHBOARD.HRL_VIEW'])],
        ['label'=>'HRL Docs',                'url'=>'hrl/hrl_docs.php',
         'perms'=>_p(['access'=>'HRL.DOC_VIEW','create'=>'HRL.DOC_CREATE','edit'=>'HRL.DOC_EDIT','view'=>'HRL.DOC_VIEW','delete'=>'HRL.DOC_DELETE'])],
        ['label'=>'HRL Doc — View',          'url'=>'hrl/hrl_doc_view.php',
         'perms'=>_p(['access'=>'HRL.DOC_VIEW','view'=>'HRL.DOC_VIEW'])],
        ['label'=>'HRL Tower',               'url'=>'hrl/hrl_tower.php',
         'perms'=>_p(['access'=>'HRL.PROCESS_VIEW','view'=>'HRL.PROCESS_VIEW'])],
        ['label'=>'HR Report Center',        'url'=>'hrl/hr_report_center.php',
         'perms'=>_p(['access'=>'ABSENSI.RECAP','view'=>'ABSENSI.RECAP','export'=>'ABSENSI.RECAP'])],
        ['label'=>'HRL Ack Report',          'url'=>'hrl/hrl_ack_report.php',
         'perms'=>_p(['access'=>'HRL.COMPLIANCE_EXPORT','view'=>'HRL.COMPLIANCE_EXPORT','export'=>'HRL.COMPLIANCE_EXPORT'])],
        ['label'=>'HRL — Panduan',           'url'=>'hrl/panduan.php',
         'perms'=>_p(['access'=>'HRL.VIEW','view'=>'HRL.VIEW'])],
        ['label'=>'Master Karyawan',         'url'=>'master/master_employees.php',
         'perms'=>_p(['access'=>'MASTER.EMPLOYEE_VIEW','create'=>'MASTER.EMPLOYEE_CREATE','edit'=>'MASTER.EMPLOYEE_EDIT','view'=>'MASTER.EMPLOYEE_VIEW','delete'=>'MASTER.EMPLOYEE_DELETE'])],
        ['label'=>'Import Rekening Karyawan','url'=>'master/import_rekening_final.php',
         'perms'=>_p(['access'=>'HRL.IMPORT_REKENING','import'=>'HRL.IMPORT_REKENING','view'=>'MASTER.EMPLOYEE_VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // HRL PROCESS — Pengajuan (lintas dept)
    // ══════════════════════════════════════════════════════════════════════════
    'HRL PROCESS' => [
        ['label'=>'HRL Process Tower',       'url'=>'hrl_process/tower.php',
         'note'=>'Semua tipe; menu sidebar pakai deep link per tipe di bawah',
         'perms'=>_p(['access'=>'HRL.PROCESS_VIEW','create'=>'HRL.PROCESS_CREATE','approve'=>'HRL.PROCESS_EDIT','edit'=>'HRL.PROCESS_EDIT','view'=>'HRL.PROCESS_VIEW','delete'=>'HRL.PROCESS_DELETE'])],
        ['label'=>'Pengajuan — Cuti',        'url'=>'hrl_process/tower.php?req_type=CUTI',
         'note'=>'Nav: hrl_tower_cuti · permission granular',
         'perms'=>_p(['access'=>'HRL.REQ_CUTI_VIEW','create'=>'HRL.REQ_CUTI_CREATE','approve'=>'HRL.REQ_CUTI_EDIT','edit'=>'HRL.REQ_CUTI_EDIT','view'=>'HRL.REQ_CUTI_VIEW','delete'=>'HRL.REQ_CUTI_DELETE'])],
        ['label'=>'Pengajuan — Izin',        'url'=>'hrl_process/tower.php?req_type=IZIN',
         'note'=>'Nav: hrl_tower_izin',
         'perms'=>_p(['access'=>'HRL.REQ_IZIN_VIEW','create'=>'HRL.REQ_IZIN_CREATE','approve'=>'HRL.REQ_IZIN_EDIT','edit'=>'HRL.REQ_IZIN_EDIT','view'=>'HRL.REQ_IZIN_VIEW','delete'=>'HRL.REQ_IZIN_DELETE'])],
        ['label'=>'Pengajuan — Lembur',      'url'=>'hrl_process/tower.php?req_type=LEMBUR',
         'note'=>'Nav: hrl_tower_lembur',
         'perms'=>_p(['access'=>'HRL.REQ_LEMBUR_VIEW','create'=>'HRL.REQ_LEMBUR_CREATE','approve'=>'HRL.REQ_LEMBUR_EDIT','edit'=>'HRL.REQ_LEMBUR_EDIT','view'=>'HRL.REQ_LEMBUR_VIEW','delete'=>'HRL.REQ_LEMBUR_DELETE'])],
        ['label'=>'Pengajuan — Perjadin',    'url'=>'hrl_process/tower.php?req_type=PERJADIN',
         'note'=>'Nav: hrl_tower_perjadin',
         'perms'=>_p(['access'=>'HRL.REQ_PERJADIN_VIEW','create'=>'HRL.REQ_PERJADIN_CREATE','approve'=>'HRL.REQ_PERJADIN_EDIT','edit'=>'HRL.REQ_PERJADIN_EDIT','view'=>'HRL.REQ_PERJADIN_VIEW','delete'=>'HRL.REQ_PERJADIN_DELETE'])],
        ['label'=>'Pengajuan — Permintaan Karyawan', 'url'=>'hrl_process/tower.php?req_type=PERMINTAAN_KARYAWAN',
         'note'=>'Nav: hrl_tower_permintaan_karyawan',
         'perms'=>_p(['access'=>'HRL.REQ_PERMINTAAN_KARYAWAN_VIEW','create'=>'HRL.REQ_PERMINTAAN_KARYAWAN_CREATE','approve'=>'HRL.REQ_PERMINTAAN_KARYAWAN_EDIT','edit'=>'HRL.REQ_PERMINTAAN_KARYAWAN_EDIT','view'=>'HRL.REQ_PERMINTAAN_KARYAWAN_VIEW','delete'=>'HRL.REQ_PERMINTAAN_KARYAWAN_DELETE'])],
        ['label'=>'Pengajuan — Kenaikan Gaji', 'url'=>'hrl_process/tower.php?req_type=KENAIKAN_GAJI',
         'note'=>'Nav: hrl_tower_kenaikan_gaji',
         'perms'=>_p(['access'=>'HRL.REQ_KENAIKAN_GAJI_VIEW','create'=>'HRL.REQ_KENAIKAN_GAJI_CREATE','approve'=>'HRL.REQ_KENAIKAN_GAJI_EDIT','edit'=>'HRL.REQ_KENAIKAN_GAJI_EDIT','view'=>'HRL.REQ_KENAIKAN_GAJI_VIEW','delete'=>'HRL.REQ_KENAIKAN_GAJI_DELETE'])],
        ['label'=>'Pengajuan — Rekrutmen',   'url'=>'hrl_process/tower.php?req_type=REKRUTMEN',
         'note'=>'Nav: hrl_tower_rekrutmen',
         'perms'=>_p(['access'=>'HRL.REQ_REKRUTMEN_VIEW','create'=>'HRL.REQ_REKRUTMEN_CREATE','approve'=>'HRL.REQ_REKRUTMEN_EDIT','edit'=>'HRL.REQ_REKRUTMEN_EDIT','view'=>'HRL.REQ_REKRUTMEN_VIEW','delete'=>'HRL.REQ_REKRUTMEN_DELETE'])],
        ['label'=>'Request — View Detail',   'url'=>'hrl_process/request_view.php',
         'perms'=>_p(['access'=>'HRL.PROCESS_VIEW','view'=>'HRL.PROCESS_VIEW'])],
        ['label'=>'Request — Print',         'url'=>'hrl_process/request_print.php',
         'perms'=>_p(['access'=>'HRL.PROCESS_VIEW','view'=>'HRL.PROCESS_VIEW','print'=>'HRL.PROCESS_VIEW'])],
        ['label'=>'HRL Process — Panduan',   'url'=>'hrl_process/panduan.php',
         'perms'=>_p(['access'=>'HRL.PROCESS_VIEW','view'=>'HRL.PROCESS_VIEW'])],
        ['label'=>'HRL Process — My PIN',    'url'=>'hrl_process/my_pin.php',
         'perms'=>_p(['access'=>'HRL.PROCESS_VIEW','view'=>'HRL.PROCESS_VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // HRL REG ALKES — Registrasi Alat Kesehatan
    // ══════════════════════════════════════════════════════════════════════════
    'HRL REG ALKES' => [
        ['label'=>'Reg Alkes — Dashboard',      'url'=>'hrl_reg_alkes/index.php',
         'perms'=>_p(['access'=>'HRL.REG_ALKES_VIEW','view'=>'HRL.REG_ALKES_VIEW'])],
        ['label'=>'Reg Alkes — Data NIE',       'url'=>'hrl_reg_alkes/reg_alkes.php',
         'perms'=>_p(['access'=>'HRL.REG_ALKES_VIEW','create'=>'HRL.REG_ALKES_CREATE','edit'=>'HRL.REG_ALKES_EDIT','view'=>'HRL.REG_ALKES_VIEW','delete'=>'HRL.REG_ALKES_DELETE','export'=>'HRL.REG_ALKES_EXPORT'])],
        ['label'=>'Reg Alkes — Case',           'url'=>'hrl_reg_alkes/reg_alkes_case.php',
         'perms'=>_p(['access'=>'HRL.REG_ALKES_VIEW','view'=>'HRL.REG_ALKES_VIEW'])],
        ['label'=>'Reg Alkes — Control Tower',  'url'=>'hrl_reg_alkes/reg_alkes_control_tower.php',
         'perms'=>_p(['access'=>'HRL.REG_ALKES_VIEW','view'=>'HRL.REG_ALKES_VIEW','export'=>'HRL.REG_ALKES_EXPORT'])],
        ['label'=>'Reg Alkes — Expiry Check',   'url'=>'hrl_reg_alkes/reg_alkes_expiry_check.php',
         'perms'=>_p(['access'=>'HRL.REG_ALKES_VIEW','view'=>'HRL.REG_ALKES_VIEW','export'=>'HRL.REG_ALKES_EXPORT'])],
        ['label'=>'Reg Alkes — Export Compliance','url'=>'hrl_reg_alkes/reg_alkes_export_compliance.php',
         'perms'=>_p(['access'=>'HRL.COMPLIANCE_EXPORT','view'=>'HRL.COMPLIANCE_EXPORT','export'=>'HRL.COMPLIANCE_EXPORT'])],
        ['label'=>'Reg Alkes — SKU by NIE',     'url'=>'hrl_reg_alkes/reg_alkes_sku_by_nie.php',
         'perms'=>_p(['access'=>'HRL.REG_ALKES_VIEW','view'=>'HRL.REG_ALKES_VIEW'])],
        ['label'=>'Reg Alkes — Panduan',        'url'=>'hrl_reg_alkes/panduan.php',
         'perms'=>_p(['access'=>'HRL.REG_ALKES_VIEW','view'=>'HRL.REG_ALKES_VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // ABSENSI ADMIN
    // ══════════════════════════════════════════════════════════════════════════
    'ABSENSI ADMIN' => [
        ['label'=>'Absensi — Dashboard',       'url'=>'absensi/index.php',
         'perms'=>_p(['access'=>'ABSENSI.VIEW','view'=>'ABSENSI.VIEW'])],
        ['label'=>'Absensi — Admin Panel',     'url'=>'absensi/admin.php',
         'perms'=>_p(['access'=>'ABSENSI.ADMIN_USERS','view'=>'ABSENSI.ADMIN_USERS'])],
        ['label'=>'Admin — Rekap Absensi',     'url'=>'absensi/admin/rekap.php',
         'perms'=>_p(['access'=>'ABSENSI.RECAP','view'=>'ABSENSI.RECAP','export'=>'ABSENSI.RECAP'])],
        ['label'=>'Admin — Approval',          'url'=>'absensi/admin/approval.php',
         'perms'=>_p(['access'=>'ABSENSI.APPROVE','approve'=>'ABSENSI.APPROVE','view'=>'ABSENSI.VIEW'])],
        ['label'=>'Admin — Office/GeoFence',   'url'=>'absensi/admin/offices.php',
         'perms'=>_p(['access'=>'ABSENSI.OFFICE_SETTINGS','edit'=>'ABSENSI.OFFICE_SETTINGS','view'=>'ABSENSI.OFFICE_SETTINGS'])],
        ['label'=>'Admin — Pins',              'url'=>'absensi/admin/pins.php',
         'perms'=>_p(['access'=>'ABSENSI.ADMIN_PINS','edit'=>'ABSENSI.ADMIN_PINS','view'=>'ABSENSI.ADMIN_PINS'])],
        ['label'=>'Admin — Users Mapping',     'url'=>'absensi/admin/users.php',
         'perms'=>_p(['access'=>'ABSENSI.ADMIN_USERS','create'=>'ABSENSI.ADMIN_USERS','edit'=>'ABSENSI.ADMIN_EDIT','view'=>'ABSENSI.ADMIN_USERS','delete'=>'ABSENSI.ADMIN_USERS'])],
        ['label'=>'Admin — Shifts',            'url'=>'absensi/admin/shifts.php',
         'perms'=>_p(['access'=>'ABSENSI.ADMIN_EDIT','edit'=>'ABSENSI.ADMIN_EDIT','view'=>'ABSENSI.ADMIN_EDIT'])],
        ['label'=>'Admin — Settings',          'url'=>'absensi/admin/settings.php',
         'perms'=>_p(['access'=>'ABSENSI.ADMIN_EDIT','edit'=>'ABSENSI.ADMIN_EDIT','view'=>'ABSENSI.ADMIN_EDIT'])],
        ['label'=>'Admin — Broadcast',         'url'=>'absensi/admin/broadcast.php',
         'perms'=>_p(['access'=>'ABSENSI.ADMIN_EDIT','create'=>'ABSENSI.ADMIN_EDIT','view'=>'ABSENSI.ADMIN_EDIT'])],
        ['label'=>'Admin — Payroll Gate',      'url'=>'absensi/admin/payroll_gate.php',
         'perms'=>_p(['access'=>'ABSENSI.ADMIN_EDIT','edit'=>'ABSENSI.ADMIN_EDIT','view'=>'ABSENSI.ADMIN_EDIT'])],
        ['label'=>'Absensi — Kiosk',           'url'=>'absensi/kiosk.php',
         'note'=>'Kiosk mode (tidak butuh login normal)',
         'perms'=>_p(['access'=>'ABSENSI.CHECKIN','view'=>'ABSENSI.VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // PAYROLL
    // ══════════════════════════════════════════════════════════════════════════
    'PAYROLL' => [
        ['label'=>'Payroll Dashboard',       'url'=>'payroll/index.php',
         'perms'=>_p(['access'=>'PAYROLL.VIEW','view'=>'PAYROLL.VIEW'])],
        ['label'=>'Payroll Run',             'url'=>'payroll/payroll_run.php',
         'perms'=>_p(['access'=>'PAYROLL.VIEW','create'=>'PAYROLL.CREATE','edit'=>'PAYROLL.EDIT','view'=>'PAYROLL.VIEW','delete'=>'PAYROLL.DELETE','export'=>'PAYROLL.EXPORT'])],
        ['label'=>'Matrix Kompensasi',       'url'=>'payroll/salary_matrix.php',
         'perms'=>_p(['access'=>'PAYROLL.MATRIX_VIEW','create'=>'PAYROLL.MATRIX_SAVE','edit'=>'PAYROLL.MATRIX_SAVE','view'=>'PAYROLL.MATRIX_VIEW','delete'=>'PAYROLL.MATRIX_DELETE','import'=>'PAYROLL.MATRIX_IMPORT','export'=>'PAYROLL.MATRIX_EXPORT'])],
        ['label'=>'Kasbon / Pinjaman',       'url'=>'payroll/loans.php',
         'perms'=>_p(['access'=>'PAYROLL.LOANS_VIEW','create'=>'PAYROLL.LOANS_CREATE','edit'=>'PAYROLL.LOANS_EDIT','view'=>'PAYROLL.LOANS_VIEW','delete'=>'PAYROLL.LOANS_DELETE','export'=>'PAYROLL.EXPORT'])],
        ['label'=>'Payslip',                 'url'=>'payroll/payslip.php',
         'note'=>'Semua karyawan lihat slip sendiri',
         'perms'=>_p(['access'=>'PAYROLL.PAYSLIP_VIEW','view'=>'PAYROLL.PAYSLIP_VIEW','print'=>'PAYROLL.PAYSLIP_VIEW'])],
        ['label'=>'Payroll Settings',        'url'=>'payroll/payroll_settings.php',
         'note'=>'SYS / Admin saja',
         'perms'=>_p(['access'=>'PAYROLL.SETTINGS','edit'=>'PAYROLL.SETTINGS','view'=>'PAYROLL.SETTINGS'])],
        ['label'=>'Payroll Audit Log',       'url'=>'payroll/audit.php',
         'perms'=>_p(['access'=>'PAYROLL.AUDIT','view'=>'PAYROLL.AUDIT','export'=>'PAYROLL.EXPORT'])],
        ['label'=>'Payroll — Panduan',       'url'=>'payroll/panduan.php',
         'perms'=>_p(['access'=>'PAYROLL.VIEW','view'=>'PAYROLL.VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // KPI
    // ══════════════════════════════════════════════════════════════════════════
    'KPI' => [
        ['label'=>'KPI Center',              'url'=>'kpi/kpi_center.php',
         'perms'=>_p(['access'=>'KPI.VIEW','view'=>'KPI.VIEW'])],
        ['label'=>'KPI DO Audit',            'url'=>'kpi/kpi_do_audit.php',
         'perms'=>_p(['access'=>'KPI.DO_AUDIT','view'=>'KPI.DO_AUDIT','export'=>'KPI.DO_AUDIT'])],
        ['label'=>'KPI SLA DO',              'url'=>'kpi/kpi_do_sla.php',
         'route_any'=>['KPI.DO_VIEW','SALES.AUDIT'],
         'perms'=>_p(['access'=>'KPI.DO_VIEW','view'=>'KPI.DO_VIEW','export'=>'KPI.DO_AUDIT'])],
        ['label'=>'KPI Purchases',           'url'=>'kpi/kpi_purchases.php',
         'perms'=>_p(['access'=>'KPI.PURCHASES_VIEW','view'=>'KPI.PURCHASES_VIEW','export'=>'KPI.PURCHASES_VIEW'])],
        ['label'=>'KPI Stock',               'url'=>'kpi/kpi_stock.php',
         'perms'=>_p(['access'=>'KPI.STOCK_VIEW','view'=>'KPI.STOCK_VIEW','export'=>'KPI.STOCK_VIEW'])],
        ['label'=>'KPI Employee',            'url'=>'kpi/kpi_employee.php',
         'note'=>'Manager & HRL saja',
         'perms'=>_p(['access'=>'KPI.EMPLOYEE_VIEW','view'=>'KPI.EMPLOYEE_VIEW','export'=>'KPI.EMPLOYEE_VIEW'])],
        ['label'=>'KPI Office / Cabang',     'url'=>'kpi/kpi_office.php',
         'note'=>'Manager & SYS saja',
         'perms'=>_p(['access'=>'KPI.OFFICE_VIEW','view'=>'KPI.OFFICE_VIEW','export'=>'KPI.OFFICE_VIEW'])],
        ['label'=>'KPI Snapshot',            'url'=>'kpi/kpi_snapshot.php',
         'perms'=>_p(['access'=>'KPI.VIEW','view'=>'KPI.VIEW','export'=>'KPI.DO_AUDIT'])],
        ['label'=>'KPI Audit',               'url'=>'kpi/kpi_audit.php',
         'perms'=>_p(['access'=>'KPI.DO_AUDIT','view'=>'KPI.DO_AUDIT','export'=>'KPI.DO_AUDIT'])],
        ['label'=>'KPI — Panduan',           'url'=>'kpi/panduan.php',
         'perms'=>_p(['access'=>'KPI.VIEW','view'=>'KPI.VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // DASHBOARDS — semua dashboard per modul
    // ══════════════════════════════════════════════════════════════════════════
    'DASHBOARDS' => [
        ['label'=>'Dashboard Funnels',       'url'=>'dashboards/funnels.php',
         'perms'=>_p(['access'=>'DASHBOARD.SALES_VIEW','view'=>'DASHBOARD.SALES_VIEW','export'=>'SALES.EXPORT'])],
        ['label'=>'Branch Dashboard',        'url'=>'dashboards/branch/branch_dashboard.php',
         'perms'=>_p(['access'=>'DASHBOARD.BRANCH_VIEW','view'=>'DASHBOARD.BRANCH_VIEW'])],
        ['label'=>'Branch — Panduan',        'url'=>'dashboards/branch/panduan.php',
         'perms'=>_p(['access'=>'DASHBOARD.BRANCH_VIEW','view'=>'DASHBOARD.BRANCH_VIEW'])],
        ['label'=>'Procurement Dashboard',   'url'=>'dashboards/procurement/import_po_dashboard.php',
         'perms'=>_p(['access'=>'DASHBOARD.PROCUREMENT_VIEW','view'=>'DASHBOARD.PROCUREMENT_VIEW'])],
        ['label'=>'Quality Dashboard',       'url'=>'dashboards/quality/qc_complaint_dashboard.php',
         'perms'=>_p(['access'=>'DASHBOARD.QUALITY_VIEW','view'=>'DASHBOARD.QUALITY_VIEW'])],
        ['label'=>'Regulatory Dashboard',    'url'=>'dashboards/regulatory/license_docs_dashboard.php',
         'perms'=>_p(['access'=>'DASHBOARD.REGULATORY_VIEW','view'=>'DASHBOARD.REGULATORY_VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // MPR — Marketing & Project
    // ══════════════════════════════════════════════════════════════════════════
    'MPR' => [
        ['label'=>'MPR Dashboard',           'url'=>'mpr/mpr_dashboard.php',
         'note'=>'Guard akses: mpr/_inc/bootstrap.php (dept MPR / FIN / Admin / RBAC MPR.*). Registry tidak men-gate access/view agar dept-based bootstrap bisa jalan.',
         'perms'=>_p([])],
        ['label'=>'MPR Plans',               'url'=>'mpr/mpr_plans.php',
         'perms'=>_p(['create'=>'MPR.PLAN_CREATE','approve'=>'MPR.PLAN_APPROVE','edit'=>'MPR.PLAN_EDIT','delete'=>'MPR.PLAN_DELETE','import'=>'MPR.PLAN_IMPORT','export'=>'MPR.PLAN_EXPORT'])],
        ['label'=>'MPR Plan — View',         'url'=>'mpr/mpr_plan_view.php',
         'perms'=>_p([])],
        ['label'=>'MPR Pipeline',            'url'=>'mpr/mpr_pipeline.php',
         'perms'=>_p([])],
        ['label'=>'MPR Visits',              'url'=>'mpr/mpr_visits.php',
         'perms'=>_p(['create'=>'MPR.PLAN_CREATE'])],
        ['label'=>'MPR Budget (FIN)',         'url'=>'mpr/mpr_budget_fin.php',
         'note'=>'Guard akses: bootstrap.php; runtime file: hanya FIN Manager atau Admin/SYS.',
         'perms'=>_p(['export'=>'MPR.PLAN_EXPORT'])],
        ['label'=>'MPR Ops Daily FIN',       'url'=>'mpr/mpr_ops_daily_fin.php',
         'note'=>'Guard akses: bootstrap.php; runtime: hanya dept FIN atau Admin/SYS.',
         'perms'=>_p(['export'=>'MPR.PLAN_EXPORT'])],
        ['label'=>'MPR Ops Daily — Detail',  'url'=>'mpr/mpr_ops_daily_fin_detail.php',
         'note'=>'Sama seperti MPR Ops Daily FIN — FIN/Admin saja.',
         'perms'=>_p([])],
        ['label'=>'MPR Ops — Pay',           'url'=>'mpr/mpr_ops_daily_fin_pay.php',
         'note'=>'POST only; FIN/Admin saja.',
         'perms'=>_p(['edit'=>'MPR.PLAN_EDIT'])],
        ['label'=>'MPR — Panduan',           'url'=>'mpr/panduan.php',
         'perms'=>_p([])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // MASTER DATA
    // ══════════════════════════════════════════════════════════════════════════
    'MASTER DATA' => [
        ['label'=>'Master Data Center',          'url'=>'master/master_data.php',
         'perms'=>_p(['access'=>'MASTER.VIEW','view'=>'MASTER.VIEW'])],
        ['label'=>'Customers',                   'url'=>'master/master_customers.php',
         'perms'=>_p(['access'=>'MASTER.CUSTOMER_VIEW','create'=>'MASTER.CUSTOMER_CREATE','edit'=>'MASTER.CUSTOMER_EDIT','view'=>'MASTER.CUSTOMER_VIEW','delete'=>'MASTER.CUSTOMER_DELETE','import'=>'MASTER.IMPORT_CUSTOMERS','export'=>'MASTER.CUSTOMER_EXPORT'])],
        ['label'=>'Customers — Import',          'url'=>'master/master_import_customers.php',
         'perms'=>_p(['access'=>'MASTER.IMPORT_CUSTOMERS','import'=>'MASTER.IMPORT_CUSTOMERS','view'=>'MASTER.CUSTOMER_VIEW'])],
        ['label'=>'Customers — Export',          'url'=>'master/master_export_customers.php',
         'perms'=>_p(['access'=>'MASTER.CUSTOMER_EXPORT','export'=>'MASTER.CUSTOMER_EXPORT','view'=>'MASTER.CUSTOMER_VIEW'])],
        ['label'=>'Products / Produk',           'url'=>'master/master_products.php',
         'perms'=>_p(['access'=>'MASTER.PRODUCT_VIEW','create'=>'MASTER.PRODUCT_CREATE','edit'=>'MASTER.PRODUCT_EDIT','view'=>'MASTER.PRODUCT_VIEW','delete'=>'MASTER.PRODUCT_DELETE','import'=>'MASTER.IMPORT_PRODUCTS','print'=>'MASTER.PRODUCT_VIEW'])],
        ['label'=>'Products — Import',           'url'=>'master/master_import_products.php',
         'perms'=>_p(['access'=>'MASTER.IMPORT_PRODUCTS','import'=>'MASTER.IMPORT_PRODUCTS','view'=>'MASTER.PRODUCT_VIEW'])],
        ['label'=>'Products — Print',            'url'=>'master/master_products_print.php',
         'perms'=>_p(['access'=>'MASTER.PRODUCT_VIEW','view'=>'MASTER.PRODUCT_VIEW','print'=>'MASTER.PRODUCT_VIEW'])],
        ['label'=>'Products — Doc',              'url'=>'master/master_products_doc.php',
         'perms'=>_p(['access'=>'MASTER.PRODUCT_VIEW','view'=>'MASTER.PRODUCT_VIEW'])],
        ['label'=>'Products — Media',            'url'=>'master/products_media_view.php',
         'perms'=>_p(['access'=>'MASTER.PRODUCT_VIEW','edit'=>'MASTER.PRODUCT_MEDIA_UPLOAD','view'=>'MASTER.PRODUCT_VIEW'])],
        ['label'=>'Paket Produk',                'url'=>'master/master_products_package.php',
         'perms'=>_p(['access'=>'MASTER.PRODUCT_PACKAGE_VIEW','create'=>'MASTER.PRODUCT_PACKAGE_CREATE','edit'=>'MASTER.PRODUCT_PACKAGE_EDIT','view'=>'MASTER.PRODUCT_PACKAGE_VIEW','delete'=>'MASTER.PRODUCT_PACKAGE_DELETE'])],
        ['label'=>'Manufactures / Pabrikan',     'url'=>'master/master_manufactures.php',
         'perms'=>_p(['access'=>'MASTER.MANUFACTURE_VIEW','create'=>'MASTER.MANUFACTURE_CREATE','edit'=>'MASTER.MANUFACTURE_EDIT','view'=>'MASTER.MANUFACTURE_VIEW','delete'=>'MASTER.MANUFACTURE_DELETE'])],
        ['label'=>'Manufactures Docs',           'url'=>'master/manufactures_docs.php',
         'perms'=>_p(['access'=>'MANUFACTURES_DOCS.ACCESS','view'=>'MANUFACTURES_DOCS.ACCESS'])],
        ['label'=>'Vendors',                     'url'=>'master/master_vendors.php',
         'perms'=>_p(['access'=>'MASTER.VENDOR_VIEW','create'=>'MASTER.VENDOR_CREATE','edit'=>'MASTER.VENDOR_EDIT','view'=>'MASTER.VENDOR_VIEW','delete'=>'MASTER.VENDOR_DELETE','import'=>'MASTER.IMPORT_VENDORS'])],
        ['label'=>'Vendors — Import',            'url'=>'master/master_import_vendors.php',
         'perms'=>_p(['access'=>'MASTER.IMPORT_VENDORS','import'=>'MASTER.IMPORT_VENDORS','view'=>'MASTER.VENDOR_VIEW'])],
        ['label'=>'Pricelist Jual',              'url'=>'master/master_pricelist_sell.php',
         'perms'=>_p(['access'=>'MASTER.PRICELIST_SELL_VIEW','create'=>'MASTER.PRICELIST_SELL_CREATE','edit'=>'MASTER.PRICELIST_SELL_EDIT','view'=>'MASTER.PRICELIST_SELL_VIEW','delete'=>'MASTER.PRICELIST_SELL_DELETE'])],
        ['label'=>'Pricelist (Jual & Beli)',     'url'=>'master/master_pricelist.php',
         'perms'=>_p(['access'=>'MASTER.PRICELIST_SELL_VIEW','create'=>'MASTER.PRICELIST_SELL_CREATE','edit'=>'MASTER.PRICELIST_SELL_EDIT','view'=>'MASTER.PRICELIST_SELL_VIEW'])],
        ['label'=>'Office / Cabang',             'url'=>'master/master_office.php',
         'note'=>'Edit hanya SYS',
         'perms'=>_p(['access'=>'MASTER.OFFICE_VIEW','create'=>'MASTER.OFFICE_CREATE','edit'=>'MASTER.OFFICE_EDIT','view'=>'MASTER.OFFICE_VIEW','delete'=>'MASTER.OFFICE_DELETE'])],
        ['label'=>'Payment Terms',               'url'=>'master/master_payment_terms.php',
         'perms'=>_p(['access'=>'MASTER.PAYMENT_TERMS_VIEW','create'=>'MASTER.PAYMENT_TERMS_CREATE','edit'=>'MASTER.PAYMENT_TERMS_EDIT','view'=>'MASTER.PAYMENT_TERMS_VIEW','delete'=>'MASTER.PAYMENT_TERMS_DELETE'])],
        ['label'=>'Email Perusahaan',            'url'=>'master/master_emailcompany.php',
         'perms'=>_p(['access'=>'MASTER.EMAIL_COMPANY_VIEW','create'=>'MASTER.EMAIL_COMPANY_CREATE','edit'=>'MASTER.EMAIL_COMPANY_EDIT','view'=>'MASTER.EMAIL_COMPANY_VIEW','delete'=>'MASTER.EMAIL_COMPANY_DELETE'])],
        ['label'=>'Departemen',                  'url'=>'master/master_departements.php',
         'perms'=>_p(['access'=>'MASTER.DEPARTMENT_VIEW','create'=>'MASTER.DEPARTMENT_CREATE','edit'=>'MASTER.DEPARTMENT_EDIT','view'=>'MASTER.DEPARTMENT_VIEW','delete'=>'MASTER.DEPARTMENT_DELETE'])],
        ['label'=>'Format Penomoran Dokumen',    'url'=>'master/doc_numbering_edit.php',
         'note'=>'SYS only','sys_only'=>true,
         'perms'=>_p(['access'=>'MASTER.ADMIN_CENTER','edit'=>'MASTER.ADMIN_CENTER','view'=>'MASTER.ADMIN_CENTER'])],
        ['label'=>'Org Structure Editor',        'url'=>'master/org_structure_edit.php',
         'note'=>'SYS only — halaman tetap cek auth_is_sys(); registry ACCESS=ADMIN_CENTER (tanpa OR VIEW) agar tidak lolos guard lalu 403.',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'MASTER.ADMIN_CENTER','edit'=>'MASTER.ADMIN_CENTER','view'=>'MASTER.VIEW'])],
        ['label'=>'Customer Portal Users',       'url'=>'master/master_customer_portal_users.php',
         'perms'=>_p(['access'=>'MASTER.CUSTOMER_VIEW','edit'=>'MASTER.CUSTOMER_EDIT','view'=>'MASTER.CUSTOMER_VIEW'])],
        ['label'=>'Manufacturer Portal Users',   'url'=>'master/master_manufacturer_portal_users.php',
         'perms'=>_p(['access'=>'MASTER.MANUFACTURE_VIEW','edit'=>'MASTER.MANUFACTURE_EDIT','view'=>'MASTER.MANUFACTURE_VIEW'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // ITC / SYSTEM — Khusus ITC & SYS
    // ══════════════════════════════════════════════════════════════════════════
    'ITC / SYSTEM' => [
        ['label'=>'ITC Dashboard',               'url'=>'dashboards/itc/itc_dashboard.php',
         'perms'=>_p(['access'=>'DASHBOARD.ITC_VIEW','view'=>'DASHBOARD.ITC_VIEW'])],
        ['label'=>'ITC — Panduan',               'url'=>'dashboards/itc/panduan.php',
         'perms'=>_p(['access'=>'DASHBOARD.ITC_VIEW','view'=>'DASHBOARD.ITC_VIEW'])],
        ['label'=>'Reset Password User (ITC)',    'url'=>'master/itc_reset_password.php',
         'route_any'=>['TOOLS.ITC_RESET_PASSWORD','SYSTEM.USER_MANAGE'],
         'perms'=>_p(['access'=>'TOOLS.ITC_RESET_PASSWORD','edit'=>'TOOLS.ITC_RESET_PASSWORD','view'=>'TOOLS.ITC_RESET_PASSWORD'])],
        ['label'=>'RBAC Center',                 'url'=>'rbac/index.php',
         'sys_only'=>true,
         'route_any'=>['SYSTEM.RBAC_MANAGE','SYSTEM.RBAC_VIEW'],
         'perms'=>_p(['access'=>'SYSTEM.RBAC_MANAGE','edit'=>'SYSTEM.RBAC_MANAGE','view'=>'SYSTEM.RBAC_VIEW','export'=>'SYSTEM.RBAC_MANAGE'])],
        ['label'=>'RBAC — Nav / sidebar audit', 'url'=>'rbac/nav_parallel_report.php',
         'note'=>'UI laporan sumber navigasi paralel',
         'sys_only'=>true,
         'route_any'=>['SYSTEM.RBAC_MANAGE','SYSTEM.RBAC_VIEW'],
         'perms'=>_p(['access'=>'SYSTEM.RBAC_MANAGE','view'=>'SYSTEM.RBAC_VIEW'])],
        ['label'=>'Nav Manager',                 'url'=>'master/nav_manager.php',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'SYSTEM.CONFIG_MANAGE','edit'=>'SYSTEM.CONFIG_MANAGE','view'=>'SYSTEM.CONFIG_MANAGE'])],
        ['label'=>'System Login / Users',        'url'=>'master/master_system_login.php',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'SYSTEM.USER_MANAGE','create'=>'SYSTEM.USER_MANAGE','edit'=>'SYSTEM.USER_MANAGE','view'=>'SYSTEM.USER_MANAGE','delete'=>'SYSTEM.USER_MANAGE','export'=>'SYSTEM.USER_MANAGE'])],
        ['label'=>'Master User',                 'url'=>'master/master_user.php',
         'sys_only'=>true,
         'route_any'=>['SYSTEM.USER_MANAGE','MASTER.PIC_CUSTOMER_VIEW','MASTER.PIC_CUSTOMER_CREATE','MASTER.PIC_CUSTOMER_EDIT','MASTER.VIEW'],
         'perms'=>_p(['access'=>'SYSTEM.USER_MANAGE','edit'=>'SYSTEM.USER_MANAGE','view'=>'SYSTEM.USER_MANAGE'])],
        ['label'=>'System Config',               'url'=>'master/master_system_config.php',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'SYSTEM.CONFIG_MANAGE','edit'=>'SYSTEM.CONFIG_MANAGE','view'=>'SYSTEM.CONFIG_MANAGE'])],
        ['label'=>'Audit Log',                   'url'=>'master/audit_logs.php',
         'perms'=>_p(['access'=>'SYSTEM.AUDIT_LOG_VIEW','view'=>'SYSTEM.AUDIT_LOG_VIEW','export'=>'SYSTEM.AUDIT_LOG_VIEW'])],
        ['label'=>'Monitoring Center',           'url'=>'master/monitoring_center.php',
         'perms'=>_p(['access'=>'SYSTEM.AUDIT_LOG_VIEW','view'=>'SYSTEM.AUDIT_LOG_VIEW'])],
        ['label'=>'Jobs Monitor',                'url'=>'master/jobs_monitor.php',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'SYSTEM.JOBS_MONITOR','view'=>'SYSTEM.JOBS_MONITOR'])],
        ['label'=>'Security',                    'url'=>'master/security.php',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'SYSTEM.SECURITY_VIEW','view'=>'SYSTEM.SECURITY_VIEW'])],
        ['label'=>'MFA Policy',                  'url'=>'master/mfa_policy.php',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'SYSTEM.MFA_POLICY_MANAGE','edit'=>'SYSTEM.MFA_POLICY_MANAGE','view'=>'SYSTEM.MFA_POLICY_MANAGE'])],
        ['label'=>'MFA Bypass',                  'url'=>'master/mfa_bypass.php',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'SYSTEM.MFA_BYPASS_MANAGE','edit'=>'SYSTEM.MFA_BYPASS_MANAGE','view'=>'SYSTEM.MFA_BYPASS_MANAGE'])],
        ['label'=>'MFA Reset User (SYS)',        'url'=>'master/mfa_admin_reset.php',
         'sys_only'=>true,
         'note'=>'Gate: auth_is_sys_tier — reset MFA akun lain',
         'perms'=>_p(['access'=>'SYSTEM.MFA_USER_RESET','edit'=>'SYSTEM.MFA_USER_RESET','view'=>'SYSTEM.MFA_USER_RESET'])],
        ['label'=>'Rate Limit Policies',         'url'=>'master/rate_limit_policies.php',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'SYSTEM.RATE_LIMIT_MANAGE','edit'=>'SYSTEM.RATE_LIMIT_MANAGE','view'=>'SYSTEM.RATE_LIMIT_MANAGE'])],
        ['label'=>'API Partner Keys',            'url'=>'master/api_partner_keys.php',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'SYSTEM.API_PARTNER_KEYS','create'=>'SYSTEM.API_PARTNER_KEYS','edit'=>'SYSTEM.API_PARTNER_KEYS','view'=>'SYSTEM.API_PARTNER_KEYS','delete'=>'SYSTEM.API_PARTNER_KEYS'])],
        ['label'=>'Account Readiness',           'url'=>'master/account_readiness.php',
         'perms'=>_p(['access'=>'SYSTEM.ACCOUNT_READINESS','view'=>'SYSTEM.ACCOUNT_READINESS'])],
        ['label'=>'Tools / Backup',              'url'=>'tools/index.php',
         'sys_only'=>true,
         'perms'=>_p(['access'=>'TOOLS.VIEW','edit'=>'TOOLS.BACKUP_MANAGE','view'=>'TOOLS.VIEW','export'=>'TOOLS.ENTERPRISE_AUDIT_EXPORT'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // CHAT ADMIN
    // ══════════════════════════════════════════════════════════════════════════
    'CHAT ADMIN' => [
        ['label'=>'Chat Admin — Settings',   'url'=>'chat/admin_settings.php',
         'perms'=>_p(['access'=>'CHAT.ADMIN_SETTINGS','edit'=>'CHAT.ADMIN_SETTINGS','view'=>'CHAT.ADMIN_SETTINGS'])],
        ['label'=>'Chat Admin — Channels',   'url'=>'chat/admin/channels.php',
         'perms'=>_p(['access'=>'CHAT.ADMIN_SETTINGS','edit'=>'CHAT.ADMIN_SETTINGS','view'=>'CHAT.ADMIN_SETTINGS'])],
        ['label'=>'Chat Admin — Exports',    'url'=>'chat/admin_exports.php',
         'perms'=>_p(['access'=>'CHAT.ADMIN_SETTINGS','view'=>'CHAT.ADMIN_SETTINGS','export'=>'CHAT.ADMIN_SETTINGS'])],
        ['label'=>'Chat Admin — Audit',      'url'=>'chat/admin/audit.php',
         'perms'=>_p(['access'=>'CHAT.ADMIN_SETTINGS','view'=>'CHAT.ADMIN_SETTINGS','export'=>'CHAT.ADMIN_SETTINGS'])],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // DOCS
    // ══════════════════════════════════════════════════════════════════════════
    'DOCS' => [
        ['label'=>'SOP MFA',                 'url'=>'docs/link/sop_mfa.php',
         'perms'=>_p(['access'=>'DOCS.VIEW','view'=>'DOCS.VIEW'])],
        ['label'=>'Monitoring Guide',        'url'=>'docs/monitoring_guide.php',
         'perms'=>_p(['access'=>'SYSTEM.AUDIT_LOG_VIEW','view'=>'SYSTEM.AUDIT_LOG_VIEW'])],
    ],


    // ══════════════════════════════════════════════════════════════════════════
    // HALAMAN PUBLIK & KHUSUS
    // ══════════════════════════════════════════════════════════════════════════
    'PUBLIK & KHUSUS' => [
        ['label'=>'Tracking Public (Customer)', 'url'=>'sales/tracking_public.php',
         'note'=>'Public — tidak butuh login, untuk customer (registry: access=view untuk kolom UI)',
         'perms'=>_p(['access'=>'SALES.TRACKING_VIEW','view'=>'SALES.TRACKING_VIEW'])],
        ['label'=>'Tracking Live (Customer)',   'url'=>'sales/tracking_public_live.php',
         'note'=>'Public — live GPS tracking untuk customer (registry: access=view untuk kolom UI)',
         'perms'=>_p(['access'=>'SALES.TRACKING_VIEW','view'=>'SALES.TRACKING_VIEW'])],
        ['label'=>'SCM Tracker SOP',            'url'=>'sales/scm_tracker_sop.php',
         'perms'=>_p(['access'=>'SALES.VIEW','view'=>'SALES.VIEW'])],
        ['label'=>'Absensi — Kiosk Poster',    'url'=>'absensi/admin/kiosk_poster.php',
         'perms'=>_p(['access'=>'ABSENSI.ADMIN_EDIT','view'=>'ABSENSI.ADMIN_EDIT'])],
        ['label'=>'MFA — Settings User',        'url'=>'master/mfa_settings.php',
         'note'=>'Setiap user atur MFA sendiri',
         'perms'=>_p(['access'=>'SYSTEM.ACCOUNT_READINESS','edit'=>'SYSTEM.ACCOUNT_READINESS','view'=>'SYSTEM.ACCOUNT_READINESS'])],
        ['label'=>'Finance — Cek CRM Submitted','url'=>'dashboards/finance/cek_crm_submitted.php',
         'perms'=>_p(['access'=>'DASHBOARD.FINANCE_VIEW','view'=>'DASHBOARD.FINANCE_VIEW'])],
        ['label'=>'MPR — Export Ops Daily',     'url'=>'mpr/mpr_ops_daily_fin_export.php',
         'note'=>'Guard akses: bootstrap.php; hanya FIN atau Admin/SYS.',
         'perms'=>_p(['export'=>'MPR.PLAN_EXPORT'])],
        ['label'=>'Docs — View Dokumen',        'url'=>'docs/docs_view.php',
         'perms'=>_p(['access'=>'DOCS.VIEW','view'=>'DOCS.VIEW'])],
        ['label'=>'Docs — User Guide',          'url'=>'docs/user_guide/index.php',
         'perms'=>_p(['access'=>'DOCS.VIEW','view'=>'DOCS.VIEW'])],
        ['label'=>'Docs — Panduan (file)',      'url'=>'docs/panduan_view.php',
         'perms'=>_p(['access'=>'DOCS.VIEW','view'=>'DOCS.VIEW'])],
        ['label'=>'RBAC Diff Config vs DB',     'url'=>'tools/rbac_diff_config_db.php',
         'note'=>'SYS diagnostic tool','sys_only'=>true,
         'perms'=>_p(['access'=>'SYSTEM.RBAC_MANAGE','view'=>'SYSTEM.RBAC_MANAGE'])],
    ],


];

if (is_file(__DIR__ . '/page_registry_panduan_generated.php')) {
    /** @var list<array<string,mixed>> $panduanRows */
    $panduanRows = require __DIR__ . '/page_registry_panduan_generated.php';
    if (is_array($panduanRows) && $panduanRows !== []) {
        $__rmi_registry['PANDUAN PER HALAMAN'] = $panduanRows;
    }
}

return $__rmi_registry;
