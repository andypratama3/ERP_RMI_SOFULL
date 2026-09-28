-- 134_rbac_wqs_transfer.sql
-- Permission WQS.TRANSFER_CRUD: Transfer Antar Kantor (hanya Admin/SYS).
-- Kantor & Depo gunakan Pembelian (PO + Sales DO).
--
-- Jalankan: mysql -u root -p erp_rmi_sofull < sql/migrations/134_rbac_wqs_transfer.sql

INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('WQS.TRANSFER_CRUD', 'WQS Transfer Antar Kantor', 'WQS', 'Transfer/mutasi stok antar kantor. Hanya Admin/SYS. Kantor gunakan Pembelian.', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- Assign ke SYS (Admin sistem)
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('SYS', 'MANAGER', 'WQS.TRANSFER_CRUD', 1),
('SYS', 'STAFF', 'WQS.TRANSFER_CRUD', 1),
('SYS', 'SYS', 'WQS.TRANSFER_CRUD', 1);
