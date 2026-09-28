-- =====================================================================
-- Migration 157: BRANCH Dept RBAC Permissions
-- Memberikan permission yang diperlukan untuk BRANCH MANAGER & STAFF
-- agar bisa akses modul operasional sesuai nav_config.php
-- =====================================================================

-- Hapus dulu supaya tidak duplikat
DELETE FROM `rbac_dept_role_permissions`
WHERE `dept_code` = 'BRANCH';

-- Pastikan DASHBOARD.* permissions ter-seed ke rbac_permissions terlebih dahulu
-- (jika belum, jalankan Sync Permissions di RBAC Center)

-- ── BRANCH STAFF ──────────────────────────────────────────────────────
INSERT INTO `rbac_dept_role_permissions` (`dept_code`, `role_code`, `perm_code`, `allow_flag`)
SELECT 'BRANCH', 'STAFF', perm_code, 1 FROM `rbac_permissions` WHERE `perm_code` IN (
    -- Dashboard
    'DASHBOARD.BRANCH_VIEW',
    'DASHBOARD.SALES_VIEW',
    'DASHBOARD.VIEW',
    -- Sales / DO
    'SALES.VIEW',
    'SALES.CREATE',
    'SALES.EDIT',
    'SALES.EXPORT',
    -- WQS / Stock
    'STOCK.VIEW',
    'WQS.INCOMING_VIEW',
    'WQS.INCOMING_CREATE',
    'WQS.INCOMING_EDIT',
    -- Purchase Request
    'PURCHASES.VIEW',
    'PURCHASES.PO_VIEW',
    'PURCHASES.PO_CREATE',
    'PURCHASES.PO_EDIT',
    -- HRL Process (lintas dept)
    'HRL.PROCESS_VIEW',
    -- KPI (view saja)
    'KPI.VIEW',
    -- Dashboard
    'DASHBOARD.SALES_VIEW',
    'DASHBOARD.WQS_VIEW'
)
AND `is_active` = 1
ON DUPLICATE KEY UPDATE `allow_flag` = 1;

-- ── BRANCH MANAGER (tambah: approve & delete) ─────────────────────────
INSERT INTO `rbac_dept_role_permissions` (`dept_code`, `role_code`, `perm_code`, `allow_flag`)
SELECT 'BRANCH', 'MANAGER', perm_code, 1 FROM `rbac_permissions` WHERE `perm_code` IN (
    -- Sales / DO (manager + approval)
    'SALES.VIEW',
    'SALES.CREATE',
    'SALES.EDIT',
    'SALES.DELETE',
    'SALES.EXPORT',
    -- WQS / Stock (manager)
    'STOCK.VIEW',
    'STOCK.CREATE',
    'STOCK.EDIT',
    'WQS.INCOMING_VIEW',
    'WQS.INCOMING_CREATE',
    'WQS.INCOMING_EDIT',
    -- Purchase Request & Order
    'PURCHASES.VIEW',
    'PURCHASES.PO_VIEW',
    'PURCHASES.PO_CREATE',
    'PURCHASES.PO_EDIT',
    -- HRL Process
    'HRL.PROCESS_VIEW',
    -- KPI
    'KPI.VIEW',
    -- Dashboard
    'DASHBOARD.SALES_VIEW',
    'DASHBOARD.WQS_VIEW'
)
AND `is_active` = 1
ON DUPLICATE KEY UPDATE `allow_flag` = 1;
