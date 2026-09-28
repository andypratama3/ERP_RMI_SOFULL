<?php
declare(strict_types=1);

/**
 * Legacy / mirror RBAC codes → kode kanonik (satu target per kode lama).
 *
 * Dipakai oleh:
 * - `_shared/rbac.php` — `rbac_alias_candidates()` menambah kandidat legacy saat cek matrix
 * - `sql/migrations/162_rbac_merge_legacy_mirror.sql` — merge grant + hapus registry lama
 *
 * Setelah migrasi 162 di NAS + Sync Permissions, baris-baris ini dihapus dari `rbac_permissions.php`.
 *
 * @return array<string,string> OLD_PERM_CODE => NEW_PERM_CODE (uppercase)
 */
return [
    // ── Purchases / WQS / PQP (underscore) ──────────────────────────
    'AP_CREATE' => 'PURCHASES.AP_INVOICE_CREATE',
    'AP_POST' => 'PURCHASES.AP_INVOICE_EDIT',
    'AP_VALIDATE' => 'PURCHASES.AP_INVOICE_EDIT',
    'AP_PAYMENT_CREATE' => 'PURCHASES.AP_PAYMENT_CREATE',
    'AP_PAYMENT_APPROVE' => 'PURCHASES.AP_PAYMENT_EDIT',
    'PO_CREATE' => 'PURCHASES.PO_CREATE',
    'PO_APPROVE' => 'PURCHASES.PO_APPROVE',
    'PO_ISSUE' => 'PURCHASES.PO_PRINT',
    'GR_CREATE' => 'PURCHASES.GR_PROCESS',
    'GR_POST' => 'PURCHASES.GR_PROCESS',
    'GR_VERIFY' => 'PURCHASES.GR_PROCESS',
    'PR_CREATE' => 'WQS.PR_CREATE',
    'PR_APPROVE' => 'WQS.PR_EDIT',
    'PR_SUBMIT' => 'WQS.PR_EDIT',
    'PQP_CREATE' => 'PQP.CREATE',
    'PQP_APPROVE' => 'PQP.EDIT',
    'PQP_LOCK' => 'PQP.EDIT',
    'PURCHASES_READ' => 'PURCHASES.VIEW',
    'PURCHASES.PAYMENT_AP_EDIT' => 'PURCHASES.AP_PAYMENT_EDIT',

    // ── Sales ───────────────────────────────────────────────────────
    'SALES_READ' => 'SALES.VIEW',
    'DO_CREATE' => 'SALES.CREATE',
    'DO_POST' => 'SALES.EDIT',
    'SO_CREATE' => 'SALES.CREATE',
    'SO_APPROVE' => 'SALES.EDIT',
    'CRM_LEADS_READ' => 'SALES.VIEW',
    'CRM_LEADS_WRITE' => 'SALES.EDIT',

    // ── Chat ────────────────────────────────────────────────────────
    'CHAT_READ' => 'CHAT.VIEW',
    'CHAT_SEND' => 'CHAT.VIEW',
    'CHAT_ADMIN' => 'CHAT.ADMIN_SETTINGS',
    'CHAT_DELETE_ADMIN_ONLY' => 'CHAT.ADMIN_SETTINGS',

    // ── Master ──────────────────────────────────────────────────────
    'MASTER_READ' => 'MASTER.VIEW',
    'MASTER_SYSTEM_ADMIN' => 'SYSTEM.USER_MANAGE',

    // ── HRL ─────────────────────────────────────────────────────────
    'HRL_READ' => 'HRL.VIEW',
    'HRL_WRITE' => 'HRL.DOC_EDIT',

    // ── Stock / WQS legacy names ────────────────────────────────────
    'STOCK_READ' => 'STOCK.VIEW',
    'STOCK_ADJ_APPROVE' => 'STOCK.WQS_STOCK_ADJUSTMENT',
    'STOCK_ADJ_CREATE' => 'STOCK.WQS_STOCK_ADJUSTMENT',
    'STOCK_ADJ_POST' => 'STOCK.WQS_STOCK_ADJUSTMENT',
    'STOCK_ALLOCATION' => 'STOCK.WQS_ALLOCATION',
    'STOCK_OPNAME_APPLY' => 'STOCK.OPNAME',
    'STOCK_OPNAME_CREATE' => 'STOCK.OPNAME',
    'STOCK_PICKING' => 'STOCK.WQS_PICKING',
    'STOCK.ADJUST' => 'STOCK.WQS_STOCK_ADJUSTMENT',
    'STOCK.AUDIT_VIEW' => 'STOCK.AUDIT',

    // ── Fixed asset (underscore) ─────────────────────────────────────
    'FA_READ' => 'FIXED_ASSET.VIEW',
    'FA_WRITE' => 'FIXED_ASSET.ASSET_EDIT',
    'FA_APPROVE' => 'FIXED_ASSET.ASSET_EDIT',

    // ── Finance mirror (underscore) ─────────────────────────────────
    'FIN_READ' => 'FIN.AP.INVOICE.VIEW',
    'GL_READ' => 'FIN.AP.INVOICE.VIEW',
    'GL_POST' => 'ACT.GL.POST',
    'BANK_READ' => 'FIN.AP.PAYMENT.VIEW',
    'BANK_RECON_WRITE' => 'FIN.AP.PAYMENT.DRAFT',

    // ── KPI / MPR / Payroll underscore ───────────────────────────────
    'KPI_READ' => 'KPI.VIEW',
    'KPI_ADMIN' => 'KPI.VIEW',
    'MPR_READ' => 'MPR.VIEW',
    'MPR_APPROVE_POLICY' => 'MPR.PLAN_APPROVE',
    'PAYROLL_READ' => 'PAYROLL.VIEW',
    'PAYROLL_PROCESS' => 'PAYROLL.EDIT',
    'PAYROLL_APPROVE' => 'PAYROLL.APPROVE',

    // ── Payroll dotted duplicates (mirror vs kanonik baru) ───────────
    'PAYROLL.RUN_CREATE' => 'PAYROLL.CREATE',
    'PAYROLL.RUN_DELETE' => 'PAYROLL.DELETE',
    'PAYROLL.RUN_EDIT' => 'PAYROLL.EDIT',
    'PAYROLL.RUN_PAID' => 'PAYROLL.APPROVE',
    'PAYROLL.RUN_POST' => 'PAYROLL.APPROVE',
    'PAYROLL.LOANS' => 'PAYROLL.LOANS_VIEW',
    'PAYROLL.EXPORT_BANK' => 'PAYROLL.EXPORT',
    'PAYROLL.MATRIX_MANAGE' => 'PAYROLL.MATRIX_SAVE',

    // ── PQP / FA dotted duplicates ───────────────────────────────────
    'PQP.QUALITY_CRUD' => 'PQP.QUALITY_EDIT',
    'FIXED_ASSET.ASSET_CRUD' => 'FIXED_ASSET.ASSET_EDIT',
    'FIXED_ASSET.AUDIT_VIEW' => 'FIXED_ASSET.AUDIT',

    // ── Docs / System / Tools ────────────────────────────────────────
    'DOC.DOWNLOAD' => 'DOCS.VIEW',
    'DOC.UPLOAD' => 'DOCS.EDIT',
    'SYS.AUDIT.VIEW' => 'SYSTEM.AUDIT_LOG_VIEW',
    'TOOLS_READ' => 'TOOLS.VIEW',
    'TOOLS_BACKUP_RUN' => 'TOOLS.BACKUP_MANAGE',
    'TOOLS_RESTORE_RUN' => 'TOOLS.RESTORE_EDIT',
    'TOOLS_MIGRATE_RUN' => 'TOOLS.VIEW',
    'TOOLS_RELEASE_GATE_RUN' => 'TOOLS.RELEASE_VIEW',

    // ── HRL legacy module / underscore (rapi 2026-03) ─────────────────
    'HRL_COMPLIANCE_EXPORT.VIEW' => 'HRL.REG_ALKES_VIEW',
    'HRL_COMPLIANCE_EXPORT' => 'HRL.COMPLIANCE_EXPORT',
    'HRL_REG_ALKES.VIEW' => 'HRL.REG_ALKES_VIEW',
    'HRL_REG_ALKES.EXPORT' => 'HRL.REG_ALKES_EXPORT',
    'HRL.DOCS_EDIT' => 'HRL.DOC_EDIT',

    // ── Alias modul pendek ────────────────────────────────────────────
    'SALES.DO' => 'SALES.DO_VIEW',
    'PURCHASES.PO' => 'PURCHASES.PO_VIEW',
    'MPR.ACCESS' => 'MPR.VIEW',
    'SYSTEM.RBAC_VIEW' => 'RBAC.VIEW',

    // ── MASTER.*_CRUD → *_EDIT (tinjau DELETE di matrix bila perlu) ───
    'MASTER.CUSTOMER_CRUD' => 'MASTER.CUSTOMER_EDIT',
    'MASTER.PIC_CUSTOMER_CRUD' => 'MASTER.PIC_CUSTOMER_EDIT',
    'MASTER.PRODUCT_CRUD' => 'MASTER.PRODUCT_EDIT',
    'MASTER.PRODUCT_PACKAGE_CRUD' => 'MASTER.PRODUCT_PACKAGE_EDIT',
    'MASTER.MANUFACTURE_CRUD' => 'MASTER.MANUFACTURE_EDIT',
    'MASTER.VENDOR_CRUD' => 'MASTER.VENDOR_EDIT',
    'MASTER.PRICELIST_SELL_CRUD' => 'MASTER.PRICELIST_SELL_EDIT',
    'MASTER.PRICELIST_BUY_CRUD' => 'MASTER.PRICELIST_BUY_EDIT',
    'MASTER.OFFICE_CRUD' => 'MASTER.OFFICE_EDIT',
    'MASTER.TAX_CRUD' => 'MASTER.TAX_EDIT',
    'MASTER.PAYMENT_TERMS_CRUD' => 'MASTER.PAYMENT_TERMS_EDIT',
    'MASTER.EMAIL_COMPANY_CRUD' => 'MASTER.EMAIL_COMPANY_EDIT',
    'MASTER.EMPLOYEE_CRUD' => 'MASTER.EMPLOYEE_EDIT',
    'MASTER.DEPARTMENT_CRUD' => 'MASTER.DEPARTMENT_EDIT',
    'MASTER.COMPANY_BANK_CRUD' => 'MASTER.COMPANY_BANK_EDIT',

    // ── PURCHASES / WQS *_CRUD ────────────────────────────────────────
    'PURCHASES.PO_CRUD' => 'PURCHASES.PO_EDIT',
    'PURCHASES.AP_INVOICE_CRUD' => 'PURCHASES.AP_INVOICE_EDIT',
    'PURCHASES.AP_PAYMENT_CRUD' => 'PURCHASES.AP_PAYMENT_EDIT',
    'PURCHASES.FORWARDING_CRUD' => 'PURCHASES.FORWARDING_EDIT',
    'PURCHASES.PAYMENT_AP_VIEW' => 'PURCHASES.AP_PAYMENT_VIEW',
    'WQS.INCOMING_CRUD' => 'WQS.INCOMING_EDIT',
    'WQS.PICKING_CRUD' => 'WQS.PICKING_EDIT',
    'WQS.ALLOCATION' => 'WQS.ALLOCATION_EDIT',
    'WQS.PR_CRUD' => 'WQS.PR_EDIT',
    'WQS.TRANSFER_CRUD' => 'WQS.TRANSFER_EDIT',
];
