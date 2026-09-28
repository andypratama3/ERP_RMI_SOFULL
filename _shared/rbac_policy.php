<?php
declare(strict_types=1);

/**
 * _shared/rbac_policy.php
 *
 * Policy routing untuk require_rbac(): dept + level + method + cash_out (+ perms opsional).
 *
 * - depts: dokumentasi / daftar dept yang “biasanya” mengakses route (matrix RBAC). Runtime: hanya
 *   dicek jika env RMI_RBAC_POLICY_DEPT=1. Default: tidak — akses mengikuti can() + user dept/role/level.
 * - Izin can() / centang user: jika URL punya baris di config/page_registry.php, gate izin
 *   di runtime hanya lewat rmi_page_registry_guard (require_permission / require_any_permission),
 *   bukan ganda dengan rule['perms'] di sini.
 * - rule['perms'] dipakai hanya untuk URL yang tidak ada di page registry.
 *
 * Rule matching: first-match wins — jangan duplikat route yang sama; route lebih spesifik di atas wildcard.
 *
 * cash_out: true = tambahan MANAGER/SYS untuk POST non-GET (lihat require_rbac).
 */
function rmi_rbac_policy_config(): array
{
    $strictEnv = getenv('RMI_RBAC_POLICY_STRICT');
    $strict = in_array(strtolower((string)$strictEnv), ['1', 'true', 'yes', 'on'], true);

    return [
        'strict' => $strict,
        'rules'  => [

            // ── UNIVERSAL: all authenticated users ───────────────────────────────
            ['route' => '/dashboards/index.php',     'depts' => ['ALL'],   'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/chat/index.php',           'depts' => ['ALL'],   'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/chat/*',                   'depts' => ['ALL'],   'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/docs/help_center.php',     'depts' => ['ALL'],   'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/absensi/index.php',        'depts' => ['ALL'],   'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/absensi/*',                'depts' => ['ALL'],   'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/kpi/kpi_center.php',            'depts' => ['ALL'],   'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/kpi/*',                    'depts' => ['ALL'],   'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/hrl_process/index.php',    'depts' => ['ALL'],   'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/hrl_process/*',            'depts' => ['ACT','CRM','MPR','SCM','WQS','PQP','ITC','HRL','FIN','BRANCH','SYS'],
                                                     'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],

            // ── DASHBOARD — dept-specific landing pages ───────────────────────────
            ['route' => '/dashboards/branch/branch_dashboard.php',
                        'depts' => ['BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/dashboards/branch/*',
                        'depts' => ['BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            ['route' => '/sales/sales_dashboard.php',
                        'depts' => ['CRM','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            ['route' => '/dashboards/warehouse/wqs_dashboard.php',
                        'depts' => ['WQS','SCM','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/dashboards/warehouse/*',
                        'depts' => ['WQS','SCM','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            // Shared Sales DO recap — specific rule MUST be before /dashboards/finance/* (first-match wins).
            // CRM is limited to this recap route; other Finance dashboards remain FIN/ACT/SYS only.
            ['route' => '/dashboards/finance/sales_do_rekap.php',
                        'depts' => ['CRM','FIN','ACT','SYS'],
                        'levels' => ['MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/dashboards/finance/ar_ap_cash_dashboard.php',
                        'depts' => ['FIN','ACT','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/dashboards/finance/*',
                        'depts' => ['FIN','ACT','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            ['route' => '/dashboards/act/act_dashboard.php',
                        'depts' => ['ACT','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/dashboards/act/*',
                        'depts' => ['ACT','FIN','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            ['route' => '/dashboards/hrl/hrl_dashboard.php',
                        'depts' => ['HRL','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/dashboards/hrl/*',
                        'depts' => ['HRL','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            ['route' => '/dashboards/scm/scm_dashboard.php',
                        'depts' => ['SCM','PQP','WQS','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/dashboards/scm/*',
                        'depts' => ['SCM','PQP','WQS','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            ['route' => '/dashboards/quality/qc_complaint_dashboard.php',
                        'depts' => ['PQP','WQS','SCM','ACT','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/dashboards/quality/*',
                        'depts' => ['PQP','WQS','SCM','ACT','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            ['route' => '/dashboards/regulatory/license_docs_dashboard.php',
                        'depts' => ['PQP','HRL','FIN','ACT','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/dashboards/regulatory/*',
                        'depts' => ['PQP','HRL','FIN','ACT','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            ['route' => '/dashboards/itc/*',
                        'depts' => ['ITC','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            // Catch-all for dashboards not specifically listed
            ['route' => '/dashboards/*',
                        'depts' => ['SYS','FIN','ACT','CRM','WQS','PQP','SCM','HRL','MPR','ITC','BRANCH'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],

            // ── MASTER ────────────────────────────────────────────────────────────
            // Owner Activity Control — executive read-only history.
            // Hard gate OWNER / ADMIN / SUPERADMIN / SYS juga diterapkan di halaman itu sendiri.
            // Rule spesifik ini HARUS berada sebelum /master/* karena first-match wins.
            ['route' => '/master/owner_activity_control.php',
                        'depts' => ['ALL'],
                        'levels' => ['STAFF','MANAGER','SYS','OWNER','ADMIN','SUPERADMIN'], 'methods' => ['GET']],

            // Sensitive pages — SYS only
            ['route' => '/master/master_system_login.php',   'depts' => ['SYS'], 'levels' => ['SYS'], 'methods' => ['GET','POST']],
            ['route' => '/master/master_system_config.php',  'depts' => ['SYS'], 'levels' => ['SYS'], 'methods' => ['GET','POST']],
            ['route' => '/master/nav_manager.php',           'depts' => ['SYS'], 'levels' => ['SYS'], 'methods' => ['GET','POST']],
            ['route' => '/master/mfa_policy.php',            'depts' => ['SYS'], 'levels' => ['SYS'], 'methods' => ['GET','POST']],
            ['route' => '/master/mfa_bypass.php',            'depts' => ['SYS'], 'levels' => ['SYS'], 'methods' => ['GET','POST']],
            ['route' => '/master/mfa_admin_reset.php',       'depts' => ['SYS'], 'levels' => ['SYS'], 'methods' => ['GET','POST']],
            ['route' => '/master/jobs_monitor.php',          'depts' => ['SYS'], 'levels' => ['SYS'], 'methods' => ['GET','POST']],
            ['route' => '/master/rate_limit_policies.php',   'depts' => ['SYS'], 'levels' => ['SYS'], 'methods' => ['GET','POST']],
            // ITC support page
            ['route' => '/master/itc_reset_password.php',    'depts' => ['ITC','SYS'], 'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // FIN-specific master pages
            ['route' => '/master/company_bank_accounts.php', 'depts' => ['FIN','SYS'], 'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // General master index (ITC + SYS)
            ['route' => '/master/index.php',                 'depts' => ['ITC','SYS'], 'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            // Broad master pages (must come AFTER specific SYS-only overrides above)
            ['route' => '/master/*',
                        'depts' => ['CRM','SCM','PQP','HRL','FIN','ACT','ITC','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],

            // ── SALES / CRM ───────────────────────────────────────────────────────
            ['route' => '/sales/sales_dashboard.php', 'depts' => ['CRM','BRANCH','SYS'],                         'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/sales/sales_do.php',        'depts' => ['CRM','WQS','SCM','ACT','FIN','BRANCH','SYS'], 'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // /stock/wqs_do_tasks.php — hanya di blok STOCK (first-match; hindari duplikasi lintas modul)
            ['route' => '/sales/scm_do_tasks.php',    'depts' => ['SCM','BRANCH','SYS'],                                  'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/sales/act_do_tasks.php',    'depts' => ['ACT','SYS'],                                  'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/sales/fin_do_tasks.php',    'depts' => ['FIN','SYS'],                                  'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // Panduan read-only: HRL (dll.) memakai PANDUAN.SALES_VIEW — gate permission di halaman
            ['route' => '/sales/panduan.php',
                        'depts' => ['CRM','WQS','SCM','ACT','FIN','BRANCH','HRL','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            // Legacy direct URL (implementasi sama); kanonikal = /stock/wqs_do_tasks.php
            ['route' => '/sales/wqs_do_tasks.php',
                        'depts' => ['WQS','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/sales/*',                   'depts' => ['CRM','WQS','SCM','ACT','FIN','BRANCH','SYS'], 'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],

            // ── PURCHASES ─────────────────────────────────────────────────────────
            // Route-specific overrides BEFORE wildcard (first-match wins):
            ['route' => '/purchases/index.php',
                        'depts' => ['PQP','SCM','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/purchases/purchases_import_control_tower.php',
                        'depts' => ['PQP','SCM','FIN','ACT','WQS','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            // AP Invoice — ACT + FIN + SYS (PQP enters PO/GR, not AP invoice)
            ['route' => '/purchases/purchases_invoice_ap.php',
                        'depts' => ['ACT','FIN','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // CASH-OUT: AP Payment — FIN + SYS only; POST approve enforced by auth_require_fin_central_approver()
            ['route' => '/purchases/purchases_payment_ap.php',
                        'depts' => ['FIN','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST'], 'cash_out' => true],
            // CASH-OUT: GL Reversal — POST approve enforced by auth_require_fin_central_approver()
            ['route' => '/purchases/gl_reversal_approvals.php',
                        'depts' => ['FIN','ACT','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST'], 'cash_out' => true],
            // CASH-OUT: Forwarder payment
            ['route' => '/purchases/purchases_forwarder_payment.php',
                        'depts' => ['FIN','SCM','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST'], 'cash_out' => true],
            // PO creation — PQP + WQS + BRANCH (branch offices submit POs)
            ['route' => '/purchases/purchases_po.php',
                        'depts' => ['PQP','WQS','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // PQP/Quality — PQP + WQS + SYS
            ['route' => '/purchases/purchases_dashboard.php',
                        'depts' => ['PQP','SCM','FIN','ACT','WQS','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            // Panduan read-only: HRL (dll.) memakai PANDUAN.PURCHASES_VIEW — gate permission di halaman
            ['route' => '/purchases/panduan.php',
                        'depts' => ['PQP','SCM','WQS','ACT','FIN','BRANCH','HRL','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            // General purchases wildcard
            ['route' => '/purchases/*',
                        'depts' => ['PQP','SCM','WQS','ACT','FIN','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],

            // ── STOCK / WQS ───────────────────────────────────────────────────────
            // Specific locked route overrides before wildcard:
            ['route' => '/stock/index.php',
                        'depts' => ['WQS','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            // PR — WQS + BRANCH can create purchase requests
            ['route' => '/stock/wqs_pr.php',
                        'depts' => ['WQS','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // Incoming — WQS + SCM + BRANCH
            ['route' => '/stock/wqs_incoming.php',
                        'depts' => ['WQS','SCM','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // Stock adjustment — MANAGER only (high risk mutation)
            ['route' => '/stock/wqs_stock_adjustment.php',
                        'depts' => ['WQS','SYS'],
                        'levels' => ['MANAGER','SYS'], 'methods' => ['GET','POST']],
            // WQS stock (main) — WQS + SCM + BRANCH
            ['route' => '/stock/wqs_stock.php',
                        'depts' => ['WQS','SCM','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // Picking + Allocation
            ['route' => '/stock/wqs_picking.php',
                        'depts' => ['WQS','SCM','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/stock/wqs_allocation.php',
                        'depts' => ['WQS','SCM','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // Task DO — WQS + BRANCH + SYS. Page tetap melakukan permission/action guard dan office scope.
            ['route' => '/stock/wqs_do_tasks.php',
                        'depts' => ['WQS','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            // General stock wildcard
            ['route' => '/stock/*',
                        'depts' => ['WQS','SCM','PQP','BRANCH','SYS'],
                        'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],

            // ── HRL ───────────────────────────────────────────────────────────────
            ['route' => '/hrl/index.php',           'depts' => ['HRL','SYS'],                           'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/hrl/*',                   'depts' => ['HRL','SYS'],                           'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/hrl_reg_alkes/index.php', 'depts' => ['HRL','PQP','SYS'],                     'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/hrl_reg_alkes/*',         'depts' => ['HRL','PQP','SYS'],                     'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],

            // ── PAYROLL ───────────────────────────────────────────────────────────
            ['route' => '/payroll/index.php', 'depts' => ['FIN','HRL','SYS'],                           'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/payroll/*',         'depts' => ['FIN','HRL','SYS'],                           'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST'], 'cash_out' => true],

            // ── MPR ───────────────────────────────────────────────────────────────
            ['route' => '/mpr/index.php',                'depts' => ['MPR','FIN','SYS'],                'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/mpr/mpr_ops_daily_fin_pay.php','depts' => ['FIN','SYS'],                      'levels' => ['MANAGER','SYS'],         'methods' => ['POST'],        'cash_out' => true],
            ['route' => '/mpr/*',                        'depts' => ['MPR','FIN','SYS'],                'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],

            // ── FIXED ASSET ───────────────────────────────────────────────────────
            ['route' => '/Fixed_Asset/index.php', 'depts' => ['ACT','FIN','SYS'],                       'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET']],
            ['route' => '/Fixed_Asset/*',         'depts' => ['ACT','FIN','SYS'],                       'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/fixed_asset/*',         'depts' => ['ACT','FIN','SYS'],                       'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],

            // ── AUDIT LOG ─────────────────────────────────────────────────────────
            // /master/audit_logs.php — diakses via permission SYSTEM.AUDIT_LOG_VIEW.
            // Default hanya SYS+ITC; dept lain bisa dikonfigurasi via RBAC Center.
            ['route' => '/master/audit_logs.php', 'depts' => ['SYS','ITC','FIN','HRL','ACT'], 'levels' => ['MANAGER','SYS'], 'methods' => ['GET']],

            // ── RBAC Center — route policy (lapisan require_rbac) ───────────────────
            // User terautentikasi STAFF/MANAGER/SYS dari semua dept boleh mencapai URL.
            // Gate halaman: SYSTEM.RBAC_MANAGE | SYSTEM.RBAC_VIEW (di rbac/index.php, rbac/panduan.php).
            // /tools/* tetap SYS-only — script QA (diff/coverage) jalankan via CLI di NAS atau buka sebagai SYS.
            ['route' => '/rbac/index.php',     'depts' => ['ALL'], 'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/rbac/panduan.php',   'depts' => ['ALL'], 'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/rbac/v1_legacy.php', 'depts' => ['ALL'], 'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],
            ['route' => '/rbac/*',             'depts' => ['ALL'], 'levels' => ['STAFF','MANAGER','SYS'], 'methods' => ['GET','POST']],

            // ── TOOLS ─────────────────────────────────────────────────────────────
            // /tools/* — SYS only (Tools & RBAC management = SYS ONLY per governance)
            ['route' => '/tools/index.php', 'depts' => ['SYS'], 'levels' => ['SYS'], 'methods' => ['GET']],
            // All other tools — SYS only
            ['route' => '/tools/*',         'depts' => ['SYS'],        'levels' => ['SYS'],                  'methods' => ['GET','POST']],

        ],
    ];
}
