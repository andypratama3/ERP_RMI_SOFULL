-- 117_rbac_edit_delete_permissions.sql
-- Permission EDIT & DELETE untuk pengaturan granular di semua modul.
-- Idempotent: aman dijalankan berulang.
-- Catatan: Permission juga akan ter-seed otomatis saat akses RBAC Center (rbac_ensure_tables).

-- MASTER
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('MASTER.CUSTOMER_EDIT', 'Master Customers - Edit', 'MASTER', 'Edit data pelanggan/RS/klinik.', 1),
('MASTER.CUSTOMER_DELETE', 'Master Customers - Delete', 'MASTER', 'Hapus data pelanggan (high risk).', 1),
('MASTER.PIC_CUSTOMER_EDIT', 'Master PIC Customer - Edit', 'MASTER', 'Edit mapping PIC customer.', 1),
('MASTER.PIC_CUSTOMER_DELETE', 'Master PIC Customer - Delete', 'MASTER', 'Hapus mapping PIC customer.', 1),
('MASTER.PRODUCT_EDIT', 'Master Products - Edit', 'MASTER', 'Edit data produk.', 1),
('MASTER.PRODUCT_DELETE', 'Master Products - Delete', 'MASTER', 'Hapus data produk (high risk).', 1),
('MASTER.PRODUCT_PACKAGE_EDIT', 'Master Product Package - Edit', 'MASTER', 'Edit paket produk.', 1),
('MASTER.PRODUCT_PACKAGE_DELETE', 'Master Product Package - Delete', 'MASTER', 'Hapus paket produk.', 1),
('MASTER.MANUFACTURE_EDIT', 'Master Manufacture - Edit', 'MASTER', 'Edit data pabrik.', 1),
('MASTER.MANUFACTURE_DELETE', 'Master Manufacture - Delete', 'MASTER', 'Hapus data pabrik.', 1),
('MASTER.VENDOR_VIEW', 'Master Vendors - View', 'MASTER', 'Lihat data vendor (read-only).', 1),
('MASTER.VENDOR_EDIT', 'Master Vendors - Edit', 'MASTER', 'Edit data vendor.', 1),
('MASTER.VENDOR_DELETE', 'Master Vendors - Delete', 'MASTER', 'Hapus data vendor.', 1),
('MASTER.PRICELIST_SELL_EDIT', 'Master Pricelist Sell - Edit', 'MASTER', 'Edit harga jual.', 1),
('MASTER.PRICELIST_SELL_DELETE', 'Master Pricelist Sell - Delete', 'MASTER', 'Hapus harga jual.', 1),
('MASTER.PRICELIST_BUY_EDIT', 'Master Pricelist Buy - Edit', 'MASTER', 'Edit harga beli.', 1),
('MASTER.PRICELIST_BUY_DELETE', 'Master Pricelist Buy - Delete', 'MASTER', 'Hapus harga beli.', 1),
('MASTER.OFFICE_EDIT', 'Master Office - Edit', 'MASTER', 'Edit data kantor.', 1),
('MASTER.OFFICE_DELETE', 'Master Office - Delete', 'MASTER', 'Hapus data kantor.', 1),
('MASTER.TAX_EDIT', 'Master Tax - Edit', 'MASTER', 'Edit data pajak.', 1),
('MASTER.TAX_DELETE', 'Master Tax - Delete', 'MASTER', 'Hapus data pajak.', 1),
('MASTER.PAYMENT_TERMS_EDIT', 'Master Payment Terms - Edit', 'MASTER', 'Edit termin pembayaran.', 1),
('MASTER.PAYMENT_TERMS_DELETE', 'Master Payment Terms - Delete', 'MASTER', 'Hapus termin pembayaran.', 1),
('MASTER.EMAIL_COMPANY_EDIT', 'Master Email Company - Edit', 'MASTER', 'Edit konfigurasi email.', 1),
('MASTER.EMAIL_COMPANY_DELETE', 'Master Email Company - Delete', 'MASTER', 'Hapus konfigurasi email.', 1),
('MASTER.EMPLOYEE_EDIT', 'Master Employees - Edit', 'MASTER', 'Edit data karyawan.', 1),
('MASTER.EMPLOYEE_DELETE', 'Master Employees - Delete', 'MASTER', 'Hapus data karyawan.', 1),
('MASTER.DEPARTMENT_EDIT', 'Master Department - Edit', 'MASTER', 'Edit data departemen.', 1),
('MASTER.DEPARTMENT_DELETE', 'Master Department - Delete', 'MASTER', 'Hapus data departemen.', 1),
('MASTER.COMPANY_BANK_EDIT', 'Rekening Perusahaan - Edit', 'MASTER', 'Edit rekening perusahaan.', 1),
('MASTER.COMPANY_BANK_DELETE', 'Rekening Perusahaan - Delete', 'MASTER', 'Hapus rekening perusahaan.', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- PURCHASES
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('PURCHASES.PO_EDIT', 'PO - Edit', 'PURCHASES', 'Edit PO & detail item.', 1),
('PURCHASES.PO_DELETE', 'PO - Delete', 'PURCHASES', 'Hapus/batalkan PO (high risk).', 1),
('PURCHASES.GR_EDIT', 'GR - Edit', 'PURCHASES', 'Edit Goods Receipt.', 1),
('PURCHASES.GR_DELETE', 'GR - Delete', 'PURCHASES', 'Hapus/batalkan GR.', 1),
('PURCHASES.AP_INVOICE_EDIT', 'Invoice AP - Edit', 'PURCHASES', 'Edit Invoice AP.', 1),
('PURCHASES.AP_INVOICE_DELETE', 'Invoice AP - Delete', 'PURCHASES', 'Hapus Invoice AP.', 1),
('PURCHASES.AP_PAYMENT_EDIT', 'Payment AP - Edit', 'PURCHASES', 'Edit pembayaran AP.', 1),
('PURCHASES.AP_PAYMENT_DELETE', 'Payment AP - Delete', 'PURCHASES', 'Hapus pembayaran AP.', 1),
('PURCHASES.FORWARDING_EDIT', 'Forwarding - Edit', 'PURCHASES', 'Edit quotes/invoice/payment forwarder.', 1),
('PURCHASES.FORWARDING_DELETE', 'Forwarding - Delete', 'PURCHASES', 'Hapus data forwarder.', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- STOCK, WQS
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('STOCK.EDIT', 'Stock - Edit', 'STOCK', 'Edit penyesuaian stok.', 1),
('STOCK.DELETE', 'Stock - Delete', 'STOCK', 'Hapus penyesuaian stok (high risk).', 1),
('WQS.INCOMING_EDIT', 'WQS Incoming - Edit', 'WQS', 'Edit data incoming.', 1),
('WQS.INCOMING_DELETE', 'WQS Incoming - Delete', 'WQS', 'Hapus data incoming.', 1),
('WQS.PICKING_EDIT', 'WQS Picking - Edit', 'WQS', 'Edit data picking.', 1),
('WQS.PICKING_DELETE', 'WQS Picking - Delete', 'WQS', 'Hapus data picking.', 1),
('WQS.ALLOCATION_EDIT', 'WQS Allocation - Edit', 'WQS', 'Edit alokasi.', 1),
('WQS.ALLOCATION_DELETE', 'WQS Allocation - Delete', 'WQS', 'Hapus alokasi.', 1),
('WQS.PR_EDIT', 'WQS PR - Edit', 'WQS', 'Edit Purchase Request.', 1),
('WQS.PR_DELETE', 'WQS PR - Delete', 'WQS', 'Hapus Purchase Request.', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- PAYROLL, ABSENSI, FIXED_ASSET
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('PAYROLL.RUN_DELETE', 'Payroll - Delete Run', 'PAYROLL', 'Hapus payroll run (high risk).', 1),
('PAYROLL.LOANS_EDIT', 'Payroll Loans - Edit', 'PAYROLL', 'Edit data pinjaman/kasbon.', 1),
('PAYROLL.LOANS_DELETE', 'Payroll Loans - Delete', 'PAYROLL', 'Hapus data pinjaman/kasbon.', 1),
('ABSENSI.REQUEST_EDIT', 'Absensi Request - Edit', 'ABSENSI', 'Edit pengajuan izin/sakit/dinas.', 1),
('ABSENSI.REQUEST_DELETE', 'Absensi Request - Delete', 'ABSENSI', 'Hapus pengajuan absensi.', 1),
('FIXED_ASSET.ASSET_EDIT', 'Fixed Asset - Edit Asset', 'FIXED_ASSET', 'Edit data aset.', 1),
('FIXED_ASSET.ASSET_DELETE', 'Fixed Asset - Delete Asset', 'FIXED_ASSET', 'Hapus data aset (high risk).', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- HRL, PQP, KPI
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('HRL.REG_ALKES_EDIT', 'HRL Reg Alkes - Edit', 'HRL', 'Edit data registrasi alat kesehatan.', 1),
('HRL.REG_ALKES_DELETE', 'HRL Reg Alkes - Delete', 'HRL', 'Hapus data registrasi alat kesehatan.', 1),
('HRL.DOC_VIEW', 'HRL Document - View', 'HRL', 'Lihat dokumen HRL.', 1),
('HRL.DOC_EDIT', 'HRL Document - Edit', 'HRL', 'Edit dokumen HRL.', 1),
('HRL.DOC_DELETE', 'HRL Document - Delete', 'HRL', 'Hapus dokumen HRL.', 1),
('PQP.EDIT', 'PQP - Edit', 'PQP', 'Edit data quality/produk PQP.', 1),
('PQP.DELETE', 'PQP - Delete', 'PQP', 'Hapus data quality PQP.', 1),
('PQP.QUALITY_CRUD', 'PQP Quality CRUD', 'PQP', 'Kelola data quality assurance.', 1),
('KPI.VIEW', 'KPI Center - View', 'KPI', 'Akses KPI Center & laporan KPI.', 1),
('KPI.EDIT', 'KPI Center - Edit', 'KPI', 'Edit konfigurasi/target KPI.', 1),
('KPI.DELETE', 'KPI Center - Delete', 'KPI', 'Hapus data KPI (high risk).', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;
