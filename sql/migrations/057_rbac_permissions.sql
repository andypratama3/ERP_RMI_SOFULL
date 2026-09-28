-- 057_rbac_permissions.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `rbac_permissions`
--

CREATE TABLE `rbac_permissions` (
  `perm_code` varchar(80) NOT NULL,
  `perm_name` varchar(120) NOT NULL,
  `module` varchar(40) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `rbac_permissions`
--

INSERT INTO `rbac_permissions` (`perm_code`, `perm_name`, `module`, `description`, `is_active`) VALUES
('ABSENSI.APPROVE', 'Absensi - Approval', 'ABSENSI', 'Approve request absensi.', 1),
('ABSENSI.CHECKIN', 'Absensi - Check-in/Check-out', 'ABSENSI', 'Check-in/out by photo.', 1),
('ABSENSI.OFFICE_SETTINGS', 'Absensi - Office Settings', 'ABSENSI', 'GeoFence/Office settings.', 1),
('ABSENSI.RECAP', 'Absensi - Rekap HR', 'ABSENSI', 'Rekap & laporan absensi.', 1),
('ABSENSI.REQUEST', 'Absensi - Request Izin/Sakit/Dinas', 'ABSENSI', 'Pengajuan izin/sakit/dinas.', 1),
('ABSENSI.VIEW', 'Absensi - View Dashboard', 'ABSENSI', 'Melihat dashboard absensi.', 1),
('MASTER.COMPANY_BANK_CRUD', 'Rekening Perusahaan CRUD', 'MASTER', 'Kelola rekening perusahaan (FIN).', 1),
('MASTER.DEPARTMENT_CRUD', 'Master Departments CRUD', 'MASTER', 'Kelola master departemen.', 1),
('MASTER.EMPLOYEE_CRUD', 'Master Employees CRUD', 'MASTER', 'Create/Read/Update/Delete karyawan.', 1),
('PAYROLL.AUDIT', 'Payroll - Audit Log', 'PAYROLL', 'Lihat audit payroll.', 1),
('PAYROLL.EXPORT_BANK', 'Payroll - Export Bank', 'PAYROLL', 'Export pembayaran bank.', 1),
('PAYROLL.LOANS', 'Payroll - Pinjaman/Kasbon', 'PAYROLL', 'Pengajuan pinjaman/kasbon payroll.', 1),
('PAYROLL.MATRIX_MANAGE', 'Payroll - Master Golongan Gaji', 'PAYROLL', 'Import/edit matrix golongan gaji.', 1),
('PAYROLL.RUN_CREATE', 'Payroll - Generate Run', 'PAYROLL', 'Generate payroll run.', 1),
('PAYROLL.RUN_EDIT', 'Payroll - Edit Run Items', 'PAYROLL', 'Edit item run (tunj/potongan/lembur).', 1),
('PAYROLL.RUN_PAID', 'Payroll - Mark Paid', 'PAYROLL', 'Set run paid / final.', 1),
('PAYROLL.RUN_POST', 'Payroll - Post Run', 'PAYROLL', 'Lock/post payroll run.', 1),
('PAYROLL.SETTINGS', 'Payroll - Settings', 'PAYROLL', 'Mapping & payroll settings.', 1),
('PAYROLL.VIEW', 'Payroll - View', 'PAYROLL', 'Melihat dashboard payroll.', 1),
('PURCHASES.APPROVE', 'Purchases - Approve', 'PURCHASES', 'Approve purchases/PO.', 1),
('PURCHASES.CREATE', 'Purchases - Create', 'PURCHASES', 'Membuat purchases/PO.', 1),
('PURCHASES.DELETE', 'Purchases - Delete', 'PURCHASES', 'Menghapus purchases/PO (manager only).', 1),
('PURCHASES.EDIT', 'Purchases - Edit', 'PURCHASES', 'Mengubah purchases/PO.', 1),
('PURCHASES.EXPORT', 'Purchases - Export', 'PURCHASES', 'Export data purchases.', 1),
('PURCHASES.VIEW', 'Purchases - View', 'PURCHASES', 'Melihat data purchases.', 1),
('SALES.CREATE', 'Sales - Create', 'SALES', 'Membuat transaksi/data sales.', 1),
('SALES.DELETE', 'Sales - Delete', 'SALES', 'Menghapus data sales (sebaiknya manager only).', 1),
('SALES.EDIT', 'Sales - Edit', 'SALES', 'Mengubah transaksi/data sales.', 1),
('SALES.EXPORT', 'Sales - Export', 'SALES', 'Export data sales.', 1),
('SALES.VIEW', 'Sales - View', 'SALES', 'Melihat data sales.', 1),
('SYSTEM.RBAC_MANAGE', 'Manage Role & Permission', 'SYSTEM', 'Hanya SUPERADMIN/ADMIN.', 1),
('SYSTEM.USER_MANAGE', 'Manage Users (Master System Login)', 'SYSTEM', 'Hanya SUPERADMIN/ADMIN.', 1);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
