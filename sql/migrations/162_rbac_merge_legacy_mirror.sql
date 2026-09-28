-- 162: Merge legacy / mirror RBAC codes → kanonik (seluruh modul)
-- Sumber: config/rbac_legacy_merge_map.php
-- WAJIB: Sync Permissions di RBAC Center dulu agar kode target ada di rbac_permissions.
-- Jalankan di NAS: mysql ... < sql/migrations/162_rbac_merge_legacy_mirror.sql

SET NAMES utf8mb4;

START TRANSACTION;

-- AP_CREATE → PURCHASES.AP_INVOICE_CREATE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.AP_INVOICE_CREATE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'AP_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.AP_INVOICE_CREATE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'AP_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- AP_POST → PURCHASES.AP_INVOICE_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.AP_INVOICE_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'AP_POST' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.AP_INVOICE_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'AP_POST' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- AP_VALIDATE → PURCHASES.AP_INVOICE_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.AP_INVOICE_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'AP_VALIDATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.AP_INVOICE_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'AP_VALIDATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- AP_PAYMENT_CREATE → PURCHASES.AP_PAYMENT_CREATE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.AP_PAYMENT_CREATE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'AP_PAYMENT_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.AP_PAYMENT_CREATE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'AP_PAYMENT_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- AP_PAYMENT_APPROVE → PURCHASES.AP_PAYMENT_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.AP_PAYMENT_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'AP_PAYMENT_APPROVE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.AP_PAYMENT_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'AP_PAYMENT_APPROVE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PO_CREATE → PURCHASES.PO_CREATE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.PO_CREATE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PO_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.PO_CREATE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PO_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PO_APPROVE → PURCHASES.PO_APPROVE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.PO_APPROVE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PO_APPROVE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.PO_APPROVE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PO_APPROVE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PO_ISSUE → PURCHASES.PO_PRINT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.PO_PRINT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PO_ISSUE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.PO_PRINT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PO_ISSUE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- GR_CREATE → PURCHASES.GR_PROCESS
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.GR_PROCESS', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'GR_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.GR_PROCESS', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'GR_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- GR_POST → PURCHASES.GR_PROCESS
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.GR_PROCESS', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'GR_POST' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.GR_PROCESS', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'GR_POST' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- GR_VERIFY → PURCHASES.GR_PROCESS
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.GR_PROCESS', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'GR_VERIFY' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.GR_PROCESS', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'GR_VERIFY' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PR_CREATE → WQS.PR_CREATE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'WQS.PR_CREATE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PR_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'WQS.PR_CREATE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PR_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PR_APPROVE → WQS.PR_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'WQS.PR_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PR_APPROVE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'WQS.PR_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PR_APPROVE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PR_SUBMIT → WQS.PR_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'WQS.PR_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PR_SUBMIT' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'WQS.PR_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PR_SUBMIT' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PQP_CREATE → PQP.CREATE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PQP.CREATE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PQP_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PQP.CREATE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PQP_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PQP_APPROVE → PQP.EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PQP.EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PQP_APPROVE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PQP.EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PQP_APPROVE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PQP_LOCK → PQP.EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PQP.EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PQP_LOCK' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PQP.EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PQP_LOCK' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PURCHASES_READ → PURCHASES.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PURCHASES_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PURCHASES_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PURCHASES.PAYMENT_AP_EDIT → PURCHASES.AP_PAYMENT_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.AP_PAYMENT_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PURCHASES.PAYMENT_AP_EDIT' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.AP_PAYMENT_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PURCHASES.PAYMENT_AP_EDIT' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- SALES_READ → SALES.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'SALES.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'SALES_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'SALES.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'SALES_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- DO_CREATE → SALES.CREATE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'SALES.CREATE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'DO_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'SALES.CREATE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'DO_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- DO_POST → SALES.EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'SALES.EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'DO_POST' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'SALES.EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'DO_POST' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- SO_CREATE → SALES.CREATE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'SALES.CREATE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'SO_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'SALES.CREATE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'SO_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- SO_APPROVE → SALES.EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'SALES.EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'SO_APPROVE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'SALES.EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'SO_APPROVE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- CRM_LEADS_READ → SALES.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'SALES.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'CRM_LEADS_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'SALES.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'CRM_LEADS_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- CRM_LEADS_WRITE → SALES.EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'SALES.EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'CRM_LEADS_WRITE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'SALES.EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'CRM_LEADS_WRITE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- CHAT_READ → CHAT.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'CHAT.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'CHAT_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'CHAT.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'CHAT_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- CHAT_SEND → CHAT.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'CHAT.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'CHAT_SEND' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'CHAT.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'CHAT_SEND' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- CHAT_ADMIN → CHAT.ADMIN_SETTINGS
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'CHAT.ADMIN_SETTINGS', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'CHAT_ADMIN' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'CHAT.ADMIN_SETTINGS', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'CHAT_ADMIN' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- CHAT_DELETE_ADMIN_ONLY → CHAT.ADMIN_SETTINGS
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'CHAT.ADMIN_SETTINGS', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'CHAT_DELETE_ADMIN_ONLY' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'CHAT.ADMIN_SETTINGS', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'CHAT_DELETE_ADMIN_ONLY' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER_READ → MASTER.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER_SYSTEM_ADMIN → SYSTEM.USER_MANAGE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'SYSTEM.USER_MANAGE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER_SYSTEM_ADMIN' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'SYSTEM.USER_MANAGE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER_SYSTEM_ADMIN' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- HRL_READ → HRL.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'HRL_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'HRL_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- HRL_WRITE → HRL.DOC_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.DOC_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'HRL_WRITE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.DOC_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'HRL_WRITE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- STOCK_READ → STOCK.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'STOCK.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'STOCK_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'STOCK.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'STOCK_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- STOCK_ADJ_APPROVE → STOCK.WQS_STOCK_ADJUSTMENT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'STOCK.WQS_STOCK_ADJUSTMENT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'STOCK_ADJ_APPROVE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'STOCK.WQS_STOCK_ADJUSTMENT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'STOCK_ADJ_APPROVE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- STOCK_ADJ_CREATE → STOCK.WQS_STOCK_ADJUSTMENT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'STOCK.WQS_STOCK_ADJUSTMENT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'STOCK_ADJ_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'STOCK.WQS_STOCK_ADJUSTMENT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'STOCK_ADJ_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- STOCK_ADJ_POST → STOCK.WQS_STOCK_ADJUSTMENT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'STOCK.WQS_STOCK_ADJUSTMENT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'STOCK_ADJ_POST' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'STOCK.WQS_STOCK_ADJUSTMENT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'STOCK_ADJ_POST' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- STOCK_ALLOCATION → STOCK.WQS_ALLOCATION
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'STOCK.WQS_ALLOCATION', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'STOCK_ALLOCATION' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'STOCK.WQS_ALLOCATION', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'STOCK_ALLOCATION' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- STOCK_OPNAME_APPLY → STOCK.OPNAME
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'STOCK.OPNAME', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'STOCK_OPNAME_APPLY' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'STOCK.OPNAME', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'STOCK_OPNAME_APPLY' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- STOCK_OPNAME_CREATE → STOCK.OPNAME
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'STOCK.OPNAME', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'STOCK_OPNAME_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'STOCK.OPNAME', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'STOCK_OPNAME_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- STOCK_PICKING → STOCK.WQS_PICKING
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'STOCK.WQS_PICKING', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'STOCK_PICKING' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'STOCK.WQS_PICKING', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'STOCK_PICKING' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- STOCK.ADJUST → STOCK.WQS_STOCK_ADJUSTMENT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'STOCK.WQS_STOCK_ADJUSTMENT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'STOCK.ADJUST' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'STOCK.WQS_STOCK_ADJUSTMENT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'STOCK.ADJUST' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- STOCK.AUDIT_VIEW → STOCK.AUDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'STOCK.AUDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'STOCK.AUDIT_VIEW' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'STOCK.AUDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'STOCK.AUDIT_VIEW' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- FA_READ → FIXED_ASSET.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'FIXED_ASSET.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'FA_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'FIXED_ASSET.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'FA_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- FA_WRITE → FIXED_ASSET.ASSET_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'FIXED_ASSET.ASSET_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'FA_WRITE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'FIXED_ASSET.ASSET_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'FA_WRITE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- FA_APPROVE → FIXED_ASSET.ASSET_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'FIXED_ASSET.ASSET_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'FA_APPROVE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'FIXED_ASSET.ASSET_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'FA_APPROVE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- FIN_READ → FIN.AP.INVOICE.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'FIN.AP.INVOICE.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'FIN_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'FIN.AP.INVOICE.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'FIN_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- GL_READ → FIN.AP.INVOICE.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'FIN.AP.INVOICE.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'GL_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'FIN.AP.INVOICE.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'GL_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- GL_POST → ACT.GL.POST
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'ACT.GL.POST', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'GL_POST' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'ACT.GL.POST', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'GL_POST' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- BANK_READ → FIN.AP.PAYMENT.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'FIN.AP.PAYMENT.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'BANK_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'FIN.AP.PAYMENT.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'BANK_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- BANK_RECON_WRITE → FIN.AP.PAYMENT.DRAFT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'FIN.AP.PAYMENT.DRAFT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'BANK_RECON_WRITE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'FIN.AP.PAYMENT.DRAFT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'BANK_RECON_WRITE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- KPI_READ → KPI.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'KPI.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'KPI_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'KPI.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'KPI_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- KPI_ADMIN → KPI.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'KPI.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'KPI_ADMIN' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'KPI.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'KPI_ADMIN' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MPR_READ → MPR.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MPR.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MPR_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MPR.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MPR_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MPR_APPROVE_POLICY → MPR.PLAN_APPROVE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MPR.PLAN_APPROVE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MPR_APPROVE_POLICY' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MPR.PLAN_APPROVE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MPR_APPROVE_POLICY' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL_READ → PAYROLL.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL_PROCESS → PAYROLL.EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL_PROCESS' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL_PROCESS' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL_APPROVE → PAYROLL.APPROVE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.APPROVE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL_APPROVE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.APPROVE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL_APPROVE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL.RUN_CREATE → PAYROLL.CREATE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.CREATE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL.RUN_CREATE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.CREATE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL.RUN_CREATE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL.RUN_DELETE → PAYROLL.DELETE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.DELETE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL.RUN_DELETE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.DELETE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL.RUN_DELETE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL.RUN_EDIT → PAYROLL.EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL.RUN_EDIT' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL.RUN_EDIT' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL.RUN_PAID → PAYROLL.APPROVE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.APPROVE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL.RUN_PAID' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.APPROVE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL.RUN_PAID' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL.RUN_POST → PAYROLL.APPROVE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.APPROVE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL.RUN_POST' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.APPROVE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL.RUN_POST' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL.LOANS → PAYROLL.LOANS_VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.LOANS_VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL.LOANS' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.LOANS_VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL.LOANS' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL.EXPORT_BANK → PAYROLL.EXPORT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.EXPORT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL.EXPORT_BANK' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.EXPORT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL.EXPORT_BANK' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PAYROLL.MATRIX_MANAGE → PAYROLL.MATRIX_SAVE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PAYROLL.MATRIX_SAVE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PAYROLL.MATRIX_MANAGE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PAYROLL.MATRIX_SAVE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PAYROLL.MATRIX_MANAGE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PQP.QUALITY_CRUD → PQP.QUALITY_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PQP.QUALITY_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PQP.QUALITY_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PQP.QUALITY_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PQP.QUALITY_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- FIXED_ASSET.ASSET_CRUD → FIXED_ASSET.ASSET_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'FIXED_ASSET.ASSET_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'FIXED_ASSET.ASSET_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'FIXED_ASSET.ASSET_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'FIXED_ASSET.ASSET_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- FIXED_ASSET.AUDIT_VIEW → FIXED_ASSET.AUDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'FIXED_ASSET.AUDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'FIXED_ASSET.AUDIT_VIEW' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'FIXED_ASSET.AUDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'FIXED_ASSET.AUDIT_VIEW' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- DOC.DOWNLOAD → DOCS.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'DOCS.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'DOC.DOWNLOAD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'DOCS.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'DOC.DOWNLOAD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- DOC.UPLOAD → DOCS.EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'DOCS.EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'DOC.UPLOAD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'DOCS.EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'DOC.UPLOAD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- SYS.AUDIT.VIEW → SYSTEM.AUDIT_LOG_VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'SYSTEM.AUDIT_LOG_VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'SYS.AUDIT.VIEW' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'SYSTEM.AUDIT_LOG_VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'SYS.AUDIT.VIEW' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- TOOLS_READ → TOOLS.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'TOOLS.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'TOOLS_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'TOOLS.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'TOOLS_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- TOOLS_BACKUP_RUN → TOOLS.BACKUP_MANAGE
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'TOOLS.BACKUP_MANAGE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'TOOLS_BACKUP_RUN' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'TOOLS.BACKUP_MANAGE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'TOOLS_BACKUP_RUN' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- TOOLS_RESTORE_RUN → TOOLS.RESTORE_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'TOOLS.RESTORE_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'TOOLS_RESTORE_RUN' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'TOOLS.RESTORE_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'TOOLS_RESTORE_RUN' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- TOOLS_MIGRATE_RUN → TOOLS.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'TOOLS.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'TOOLS_MIGRATE_RUN' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'TOOLS.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'TOOLS_MIGRATE_RUN' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- TOOLS_RELEASE_GATE_RUN → TOOLS.RELEASE_VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'TOOLS.RELEASE_VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'TOOLS_RELEASE_GATE_RUN' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'TOOLS.RELEASE_VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'TOOLS_RELEASE_GATE_RUN' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- HRL_COMPLIANCE_EXPORT.VIEW → HRL.REG_ALKES_VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.REG_ALKES_VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'HRL_COMPLIANCE_EXPORT.VIEW' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.REG_ALKES_VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'HRL_COMPLIANCE_EXPORT.VIEW' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- HRL_COMPLIANCE_EXPORT → HRL.COMPLIANCE_EXPORT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.COMPLIANCE_EXPORT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'HRL_COMPLIANCE_EXPORT' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.COMPLIANCE_EXPORT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'HRL_COMPLIANCE_EXPORT' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- HRL_REG_ALKES.VIEW → HRL.REG_ALKES_VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.REG_ALKES_VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'HRL_REG_ALKES.VIEW' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.REG_ALKES_VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'HRL_REG_ALKES.VIEW' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- HRL_REG_ALKES.EXPORT → HRL.REG_ALKES_EXPORT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.REG_ALKES_EXPORT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'HRL_REG_ALKES.EXPORT' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.REG_ALKES_EXPORT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'HRL_REG_ALKES.EXPORT' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- HRL.DOCS_EDIT → HRL.DOC_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.DOC_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'HRL.DOCS_EDIT' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.DOC_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'HRL.DOCS_EDIT' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- SALES.DO → SALES.DO_VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'SALES.DO_VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'SALES.DO' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'SALES.DO_VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'SALES.DO' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PURCHASES.PO → PURCHASES.PO_VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.PO_VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PURCHASES.PO' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.PO_VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PURCHASES.PO' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MPR.ACCESS → MPR.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MPR.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MPR.ACCESS' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MPR.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MPR.ACCESS' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- SYSTEM.RBAC_VIEW → RBAC.VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'RBAC.VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'SYSTEM.RBAC_VIEW' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'RBAC.VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'SYSTEM.RBAC_VIEW' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.CUSTOMER_CRUD → MASTER.CUSTOMER_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.CUSTOMER_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.CUSTOMER_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.CUSTOMER_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.CUSTOMER_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.PIC_CUSTOMER_CRUD → MASTER.PIC_CUSTOMER_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.PIC_CUSTOMER_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.PIC_CUSTOMER_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.PIC_CUSTOMER_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.PIC_CUSTOMER_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.PRODUCT_CRUD → MASTER.PRODUCT_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.PRODUCT_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.PRODUCT_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.PRODUCT_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.PRODUCT_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.PRODUCT_PACKAGE_CRUD → MASTER.PRODUCT_PACKAGE_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.PRODUCT_PACKAGE_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.PRODUCT_PACKAGE_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.PRODUCT_PACKAGE_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.PRODUCT_PACKAGE_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.MANUFACTURE_CRUD → MASTER.MANUFACTURE_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.MANUFACTURE_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.MANUFACTURE_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.MANUFACTURE_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.MANUFACTURE_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.VENDOR_CRUD → MASTER.VENDOR_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.VENDOR_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.VENDOR_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.VENDOR_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.VENDOR_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.PRICELIST_SELL_CRUD → MASTER.PRICELIST_SELL_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.PRICELIST_SELL_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.PRICELIST_SELL_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.PRICELIST_SELL_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.PRICELIST_SELL_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.PRICELIST_BUY_CRUD → MASTER.PRICELIST_BUY_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.PRICELIST_BUY_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.PRICELIST_BUY_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.PRICELIST_BUY_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.PRICELIST_BUY_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.OFFICE_CRUD → MASTER.OFFICE_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.OFFICE_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.OFFICE_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.OFFICE_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.OFFICE_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.TAX_CRUD → MASTER.TAX_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.TAX_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.TAX_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.TAX_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.TAX_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.PAYMENT_TERMS_CRUD → MASTER.PAYMENT_TERMS_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.PAYMENT_TERMS_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.PAYMENT_TERMS_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.PAYMENT_TERMS_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.PAYMENT_TERMS_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.EMAIL_COMPANY_CRUD → MASTER.EMAIL_COMPANY_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.EMAIL_COMPANY_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.EMAIL_COMPANY_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.EMAIL_COMPANY_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.EMAIL_COMPANY_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.EMPLOYEE_CRUD → MASTER.EMPLOYEE_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.EMPLOYEE_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.EMPLOYEE_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.EMPLOYEE_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.EMPLOYEE_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.DEPARTMENT_CRUD → MASTER.DEPARTMENT_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.DEPARTMENT_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.DEPARTMENT_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.DEPARTMENT_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.DEPARTMENT_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- MASTER.COMPANY_BANK_CRUD → MASTER.COMPANY_BANK_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'MASTER.COMPANY_BANK_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'MASTER.COMPANY_BANK_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'MASTER.COMPANY_BANK_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'MASTER.COMPANY_BANK_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PURCHASES.PO_CRUD → PURCHASES.PO_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.PO_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PURCHASES.PO_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.PO_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PURCHASES.PO_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PURCHASES.AP_INVOICE_CRUD → PURCHASES.AP_INVOICE_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.AP_INVOICE_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PURCHASES.AP_INVOICE_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.AP_INVOICE_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PURCHASES.AP_INVOICE_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PURCHASES.AP_PAYMENT_CRUD → PURCHASES.AP_PAYMENT_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.AP_PAYMENT_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PURCHASES.AP_PAYMENT_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.AP_PAYMENT_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PURCHASES.AP_PAYMENT_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PURCHASES.FORWARDING_CRUD → PURCHASES.FORWARDING_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.FORWARDING_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PURCHASES.FORWARDING_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.FORWARDING_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PURCHASES.FORWARDING_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- PURCHASES.PAYMENT_AP_VIEW → PURCHASES.AP_PAYMENT_VIEW
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'PURCHASES.AP_PAYMENT_VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'PURCHASES.PAYMENT_AP_VIEW' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'PURCHASES.AP_PAYMENT_VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'PURCHASES.PAYMENT_AP_VIEW' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- WQS.INCOMING_CRUD → WQS.INCOMING_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'WQS.INCOMING_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'WQS.INCOMING_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'WQS.INCOMING_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'WQS.INCOMING_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- WQS.PICKING_CRUD → WQS.PICKING_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'WQS.PICKING_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'WQS.PICKING_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'WQS.PICKING_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'WQS.PICKING_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- WQS.ALLOCATION → WQS.ALLOCATION_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'WQS.ALLOCATION_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'WQS.ALLOCATION' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'WQS.ALLOCATION_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'WQS.ALLOCATION' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- WQS.PR_CRUD → WQS.PR_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'WQS.PR_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'WQS.PR_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'WQS.PR_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'WQS.PR_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- WQS.TRANSFER_CRUD → WQS.TRANSFER_EDIT
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'WQS.TRANSFER_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'WQS.TRANSFER_CRUD' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'WQS.TRANSFER_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'WQS.TRANSFER_CRUD' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

DELETE FROM rbac_dept_role_permissions WHERE perm_code IN (
  'AP_CREATE',
  'AP_PAYMENT_APPROVE',
  'AP_PAYMENT_CREATE',
  'AP_POST',
  'AP_VALIDATE',
  'BANK_READ',
  'BANK_RECON_WRITE',
  'CHAT_ADMIN',
  'CHAT_DELETE_ADMIN_ONLY',
  'CHAT_READ',
  'CHAT_SEND',
  'CRM_LEADS_READ',
  'CRM_LEADS_WRITE',
  'DOC.DOWNLOAD',
  'DOC.UPLOAD',
  'DO_CREATE',
  'DO_POST',
  'FA_APPROVE',
  'FA_READ',
  'FA_WRITE',
  'FIN_READ',
  'FIXED_ASSET.ASSET_CRUD',
  'FIXED_ASSET.AUDIT_VIEW',
  'GL_POST',
  'GL_READ',
  'GR_CREATE',
  'GR_POST',
  'GR_VERIFY',
  'HRL.DOCS_EDIT',
  'HRL_COMPLIANCE_EXPORT',
  'HRL_COMPLIANCE_EXPORT.VIEW',
  'HRL_READ',
  'HRL_REG_ALKES.EXPORT',
  'HRL_REG_ALKES.VIEW',
  'HRL_WRITE',
  'KPI_ADMIN',
  'KPI_READ',
  'MASTER.COMPANY_BANK_CRUD',
  'MASTER.CUSTOMER_CRUD',
  'MASTER.DEPARTMENT_CRUD',
  'MASTER.EMAIL_COMPANY_CRUD',
  'MASTER.EMPLOYEE_CRUD',
  'MASTER.MANUFACTURE_CRUD',
  'MASTER.OFFICE_CRUD',
  'MASTER.PAYMENT_TERMS_CRUD',
  'MASTER.PIC_CUSTOMER_CRUD',
  'MASTER.PRICELIST_BUY_CRUD',
  'MASTER.PRICELIST_SELL_CRUD',
  'MASTER.PRODUCT_CRUD',
  'MASTER.PRODUCT_PACKAGE_CRUD',
  'MASTER.TAX_CRUD',
  'MASTER.VENDOR_CRUD',
  'MASTER_READ',
  'MASTER_SYSTEM_ADMIN',
  'MPR.ACCESS',
  'MPR_APPROVE_POLICY',
  'MPR_READ',
  'PAYROLL.EXPORT_BANK',
  'PAYROLL.LOANS',
  'PAYROLL.MATRIX_MANAGE',
  'PAYROLL.RUN_CREATE',
  'PAYROLL.RUN_DELETE',
  'PAYROLL.RUN_EDIT',
  'PAYROLL.RUN_PAID',
  'PAYROLL.RUN_POST',
  'PAYROLL_APPROVE',
  'PAYROLL_PROCESS',
  'PAYROLL_READ',
  'PO_APPROVE',
  'PO_CREATE',
  'PO_ISSUE',
  'PQP.QUALITY_CRUD',
  'PQP_APPROVE',
  'PQP_CREATE',
  'PQP_LOCK',
  'PR_APPROVE',
  'PR_CREATE',
  'PR_SUBMIT',
  'PURCHASES.AP_INVOICE_CRUD',
  'PURCHASES.AP_PAYMENT_CRUD',
  'PURCHASES.FORWARDING_CRUD',
  'PURCHASES.PAYMENT_AP_EDIT',
  'PURCHASES.PAYMENT_AP_VIEW',
  'PURCHASES.PO',
  'PURCHASES.PO_CRUD',
  'PURCHASES_READ',
  'SALES.DO',
  'SALES_READ',
  'SO_APPROVE',
  'SO_CREATE',
  'STOCK.ADJUST',
  'STOCK.AUDIT_VIEW',
  'STOCK_ADJ_APPROVE',
  'STOCK_ADJ_CREATE',
  'STOCK_ADJ_POST',
  'STOCK_ALLOCATION',
  'STOCK_OPNAME_APPLY',
  'STOCK_OPNAME_CREATE',
  'STOCK_PICKING',
  'STOCK_READ',
  'SYS.AUDIT.VIEW',
  'SYSTEM.RBAC_VIEW',
  'TOOLS_BACKUP_RUN',
  'TOOLS_MIGRATE_RUN',
  'TOOLS_READ',
  'TOOLS_RELEASE_GATE_RUN',
  'TOOLS_RESTORE_RUN',
  'WQS.ALLOCATION',
  'WQS.INCOMING_CRUD',
  'WQS.PICKING_CRUD',
  'WQS.PR_CRUD',
  'WQS.TRANSFER_CRUD'
);

DELETE FROM rbac_user_permissions WHERE perm_code IN (
  'AP_CREATE',
  'AP_PAYMENT_APPROVE',
  'AP_PAYMENT_CREATE',
  'AP_POST',
  'AP_VALIDATE',
  'BANK_READ',
  'BANK_RECON_WRITE',
  'CHAT_ADMIN',
  'CHAT_DELETE_ADMIN_ONLY',
  'CHAT_READ',
  'CHAT_SEND',
  'CRM_LEADS_READ',
  'CRM_LEADS_WRITE',
  'DOC.DOWNLOAD',
  'DOC.UPLOAD',
  'DO_CREATE',
  'DO_POST',
  'FA_APPROVE',
  'FA_READ',
  'FA_WRITE',
  'FIN_READ',
  'FIXED_ASSET.ASSET_CRUD',
  'FIXED_ASSET.AUDIT_VIEW',
  'GL_POST',
  'GL_READ',
  'GR_CREATE',
  'GR_POST',
  'GR_VERIFY',
  'HRL.DOCS_EDIT',
  'HRL_COMPLIANCE_EXPORT',
  'HRL_COMPLIANCE_EXPORT.VIEW',
  'HRL_READ',
  'HRL_REG_ALKES.EXPORT',
  'HRL_REG_ALKES.VIEW',
  'HRL_WRITE',
  'KPI_ADMIN',
  'KPI_READ',
  'MASTER.COMPANY_BANK_CRUD',
  'MASTER.CUSTOMER_CRUD',
  'MASTER.DEPARTMENT_CRUD',
  'MASTER.EMAIL_COMPANY_CRUD',
  'MASTER.EMPLOYEE_CRUD',
  'MASTER.MANUFACTURE_CRUD',
  'MASTER.OFFICE_CRUD',
  'MASTER.PAYMENT_TERMS_CRUD',
  'MASTER.PIC_CUSTOMER_CRUD',
  'MASTER.PRICELIST_BUY_CRUD',
  'MASTER.PRICELIST_SELL_CRUD',
  'MASTER.PRODUCT_CRUD',
  'MASTER.PRODUCT_PACKAGE_CRUD',
  'MASTER.TAX_CRUD',
  'MASTER.VENDOR_CRUD',
  'MASTER_READ',
  'MASTER_SYSTEM_ADMIN',
  'MPR.ACCESS',
  'MPR_APPROVE_POLICY',
  'MPR_READ',
  'PAYROLL.EXPORT_BANK',
  'PAYROLL.LOANS',
  'PAYROLL.MATRIX_MANAGE',
  'PAYROLL.RUN_CREATE',
  'PAYROLL.RUN_DELETE',
  'PAYROLL.RUN_EDIT',
  'PAYROLL.RUN_PAID',
  'PAYROLL.RUN_POST',
  'PAYROLL_APPROVE',
  'PAYROLL_PROCESS',
  'PAYROLL_READ',
  'PO_APPROVE',
  'PO_CREATE',
  'PO_ISSUE',
  'PQP.QUALITY_CRUD',
  'PQP_APPROVE',
  'PQP_CREATE',
  'PQP_LOCK',
  'PR_APPROVE',
  'PR_CREATE',
  'PR_SUBMIT',
  'PURCHASES.AP_INVOICE_CRUD',
  'PURCHASES.AP_PAYMENT_CRUD',
  'PURCHASES.FORWARDING_CRUD',
  'PURCHASES.PAYMENT_AP_EDIT',
  'PURCHASES.PAYMENT_AP_VIEW',
  'PURCHASES.PO',
  'PURCHASES.PO_CRUD',
  'PURCHASES_READ',
  'SALES.DO',
  'SALES_READ',
  'SO_APPROVE',
  'SO_CREATE',
  'STOCK.ADJUST',
  'STOCK.AUDIT_VIEW',
  'STOCK_ADJ_APPROVE',
  'STOCK_ADJ_CREATE',
  'STOCK_ADJ_POST',
  'STOCK_ALLOCATION',
  'STOCK_OPNAME_APPLY',
  'STOCK_OPNAME_CREATE',
  'STOCK_PICKING',
  'STOCK_READ',
  'SYS.AUDIT.VIEW',
  'SYSTEM.RBAC_VIEW',
  'TOOLS_BACKUP_RUN',
  'TOOLS_MIGRATE_RUN',
  'TOOLS_READ',
  'TOOLS_RELEASE_GATE_RUN',
  'TOOLS_RESTORE_RUN',
  'WQS.ALLOCATION',
  'WQS.INCOMING_CRUD',
  'WQS.PICKING_CRUD',
  'WQS.PR_CRUD',
  'WQS.TRANSFER_CRUD'
);

DELETE FROM rbac_permissions WHERE perm_code IN (
  'AP_CREATE',
  'AP_PAYMENT_APPROVE',
  'AP_PAYMENT_CREATE',
  'AP_POST',
  'AP_VALIDATE',
  'BANK_READ',
  'BANK_RECON_WRITE',
  'CHAT_ADMIN',
  'CHAT_DELETE_ADMIN_ONLY',
  'CHAT_READ',
  'CHAT_SEND',
  'CRM_LEADS_READ',
  'CRM_LEADS_WRITE',
  'DOC.DOWNLOAD',
  'DOC.UPLOAD',
  'DO_CREATE',
  'DO_POST',
  'FA_APPROVE',
  'FA_READ',
  'FA_WRITE',
  'FIN_READ',
  'FIXED_ASSET.ASSET_CRUD',
  'FIXED_ASSET.AUDIT_VIEW',
  'GL_POST',
  'GL_READ',
  'GR_CREATE',
  'GR_POST',
  'GR_VERIFY',
  'HRL.DOCS_EDIT',
  'HRL_COMPLIANCE_EXPORT',
  'HRL_COMPLIANCE_EXPORT.VIEW',
  'HRL_READ',
  'HRL_REG_ALKES.EXPORT',
  'HRL_REG_ALKES.VIEW',
  'HRL_WRITE',
  'KPI_ADMIN',
  'KPI_READ',
  'MASTER.COMPANY_BANK_CRUD',
  'MASTER.CUSTOMER_CRUD',
  'MASTER.DEPARTMENT_CRUD',
  'MASTER.EMAIL_COMPANY_CRUD',
  'MASTER.EMPLOYEE_CRUD',
  'MASTER.MANUFACTURE_CRUD',
  'MASTER.OFFICE_CRUD',
  'MASTER.PAYMENT_TERMS_CRUD',
  'MASTER.PIC_CUSTOMER_CRUD',
  'MASTER.PRICELIST_BUY_CRUD',
  'MASTER.PRICELIST_SELL_CRUD',
  'MASTER.PRODUCT_CRUD',
  'MASTER.PRODUCT_PACKAGE_CRUD',
  'MASTER.TAX_CRUD',
  'MASTER.VENDOR_CRUD',
  'MASTER_READ',
  'MASTER_SYSTEM_ADMIN',
  'MPR.ACCESS',
  'MPR_APPROVE_POLICY',
  'MPR_READ',
  'PAYROLL.EXPORT_BANK',
  'PAYROLL.LOANS',
  'PAYROLL.MATRIX_MANAGE',
  'PAYROLL.RUN_CREATE',
  'PAYROLL.RUN_DELETE',
  'PAYROLL.RUN_EDIT',
  'PAYROLL.RUN_PAID',
  'PAYROLL.RUN_POST',
  'PAYROLL_APPROVE',
  'PAYROLL_PROCESS',
  'PAYROLL_READ',
  'PO_APPROVE',
  'PO_CREATE',
  'PO_ISSUE',
  'PQP.QUALITY_CRUD',
  'PQP_APPROVE',
  'PQP_CREATE',
  'PQP_LOCK',
  'PR_APPROVE',
  'PR_CREATE',
  'PR_SUBMIT',
  'PURCHASES.AP_INVOICE_CRUD',
  'PURCHASES.AP_PAYMENT_CRUD',
  'PURCHASES.FORWARDING_CRUD',
  'PURCHASES.PAYMENT_AP_EDIT',
  'PURCHASES.PAYMENT_AP_VIEW',
  'PURCHASES.PO',
  'PURCHASES.PO_CRUD',
  'PURCHASES_READ',
  'SALES.DO',
  'SALES_READ',
  'SO_APPROVE',
  'SO_CREATE',
  'STOCK.ADJUST',
  'STOCK.AUDIT_VIEW',
  'STOCK_ADJ_APPROVE',
  'STOCK_ADJ_CREATE',
  'STOCK_ADJ_POST',
  'STOCK_ALLOCATION',
  'STOCK_OPNAME_APPLY',
  'STOCK_OPNAME_CREATE',
  'STOCK_PICKING',
  'STOCK_READ',
  'SYS.AUDIT.VIEW',
  'SYSTEM.RBAC_VIEW',
  'TOOLS_BACKUP_RUN',
  'TOOLS_MIGRATE_RUN',
  'TOOLS_READ',
  'TOOLS_RELEASE_GATE_RUN',
  'TOOLS_RESTORE_RUN',
  'WQS.ALLOCATION',
  'WQS.INCOMING_CRUD',
  'WQS.PICKING_CRUD',
  'WQS.PR_CRUD',
  'WQS.TRANSFER_CRUD'
);

COMMIT;
