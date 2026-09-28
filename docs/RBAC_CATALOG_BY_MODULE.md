# Katalog RBAC per modul (generated)

**Sumber data:** `config/rbac_permissions.php`  
**Dibuat:** 2026-03-25 02:59:00 UTC  
**Total baris:** 427 permission  

> Regenerate: `php tools/rbac/build_rbac_catalog_md.php`

---

## Ringkas: pola CRUD (selaras HRL Process / Accurate-like)

| Kelas | Arti singkat |
|-------|----------------|
| **VIEW** | Baca / buka halaman / list / detail / print read-only |
| **CREATE** | Tambah baris / dokumen baru / draft |
| **EDIT** | Ubah data / submit workflow / approve / posting non-final |
| **DELETE** | Hapus / void / soft-delete (risiko tinggi) |
| **CRUD (alias)** | Satu kode men-cover beberapa aksi — disarankan diganti quartet bertahap |
| **WORKFLOW / OTHER** | Kode khusus; saat normalisasi dipetakan ke **EDIT** (approval) atau **VIEW** |

Dokumen aturan lengkap: [`RBAC_CRUD_STANDARD.md`](./RBAC_CRUD_STANDARD.md) · Panduan permission: [`RBAC_PERMISSION_GUIDE.md`](./RBAC_PERMISSION_GUIDE.md)

---

## Daftar isi (modul)
- [ABSENSI](#module-absensi) — 14 permission
- [ACT](#module-act) — 9 permission
- [API](#module-api) — 3 permission
- [CHAT](#module-chat) — 2 permission
- [DASHBOARD](#module-dashboard) — 17 permission
- [DOCS](#module-docs) — 3 permission
- [FIN](#module-fin) — 11 permission
- [FIXED_ASSET](#module-fixed-asset) — 28 permission
- [HRL](#module-hrl) — 12 permission
- [HRL_PROCESS](#module-hrl-process) — 32 permission
- [KPI](#module-kpi) — 10 permission
- [MASTER](#module-master) — 87 permission
- [MPR](#module-mpr) — 8 permission
- [PANDUAN](#module-panduan) — 2 permission
- [PAYROLL](#module-payroll) — 31 permission
- [PQP](#module-pqp) — 8 permission
- [PURCHASES](#module-purchases) — 39 permission
- [RBAC](#module-rbac) — 3 permission
- [SALES](#module-sales) — 24 permission
- [SCM](#module-scm) — 9 permission
- [STOCK](#module-stock) — 11 permission
- [SYSTEM](#module-system) — 12 permission
- [TOOLS](#module-tools) — 23 permission
- [WEB_ADMIN](#module-web-admin) — 5 permission
- [WF](#module-wf) — 2 permission
- [WQS](#module-wqs) — 22 permission

---

## `ABSENSI` {#module-absensi}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| ADMIN / MANAGE (map: EDIT atau SYS) | `ABSENSI.ADMIN_PINS` | Absensi - Admin Pins | Kelola PIN absensi. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `ABSENSI.ADMIN_USERS` | Absensi - Admin Users | Kelola user absensi (mapping pin). |
| ADMIN / MANAGE (map: EDIT atau SYS) | `ABSENSI.OFFICE_SETTINGS` | Absensi - Office Settings | GeoFence/Office settings. |
| AUDIT / REPORT (map: VIEW) | `ABSENSI.RECAP` | Absensi - Rekap HR | Rekap & laporan absensi. |
| DELETE | `ABSENSI.REQUEST_DELETE` | Absensi Request - Delete | Hapus pengajuan absensi. |
| EDIT | `ABSENSI.ADMIN_EDIT` | Absensi Admin - Edit | Edit pengaturan admin absensi. |
| EDIT | `ABSENSI.REQUEST_EDIT` | Absensi Request - Edit | Edit pengajuan izin/sakit/dinas. |
| OPERASIONAL (spesifik modul) | `ABSENSI.CHECKIN` | Absensi - Check-in/Check-out | Check-in/out by photo. |
| OTHER / legacy | `ABSENSI.REQUEST` | Absensi - Request Izin/Sakit/Dinas | Pengajuan izin/sakit/dinas. |
| VIEW | `ABSENSI.REPORT_VIEW` | Absensi - Report View | Lihat rekap absensi. |
| VIEW | `ABSENSI.VIEW` | Absensi - View Dashboard | Melihat dashboard absensi. |
| WORKFLOW (map ke EDIT saat migrasi) | `ABSENSI.APPROVE` | Absensi - Approval | Approve request absensi. |
| WORKFLOW (map ke EDIT saat migrasi) | `ABSENSI.CLOCK_IN` | Absensi - Clock In | Clock in absensi. |
| WORKFLOW (map ke EDIT saat migrasi) | `ABSENSI.CLOCK_OUT` | Absensi - Clock Out | Clock out absensi. |

## `ACT` {#module-act}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| AUDIT / REPORT (map: VIEW) | `ACT.CEISA.AUDIT_NOTE` | ACT: Ceisa Audit Note | Catatan audit CEISA (ACT). |
| CREATE | `ACT.CEISA.CREATE` | ACT: Ceisa Create | Buat dokumen CEISA. |
| CREATE | `ACT.REVERSAL.CREATE` | ACT: Reversal Create | Buat reversal GL. |
| OTHER / legacy | `ACT.CEISA.UPDATE_STATUS` | ACT: Ceisa Update Status | Ubah status CEISA. |
| OTHER / legacy | `ACT.PERIOD.CLOSE` | ACT: Period Close | Tutup periode akuntansi. |
| VIEW | `ACT.CEISA.VIEW` | ACT: Ceisa View | Lihat CEISA. |
| WORKFLOW (map ke EDIT saat migrasi) | `ACT.CEISA.RESUBMIT` | ACT: Ceisa Re-Submit | Kirim ulang CEISA. |
| WORKFLOW (map ke EDIT saat migrasi) | `ACT.CEISA.SUBMIT` | ACT: Ceisa Submit | Submit CEISA. |
| WORKFLOW (map ke EDIT saat migrasi) | `ACT.GL.POST` | ACT: GL Post | Posting jurnal GL (ACT). |

## `API` {#module-api}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| OTHER / legacy | `API.INTERNAL_ACCESS` | API Internal Access | Akses API internal. |
| OTHER / legacy | `API.MOBILE_ACCESS` | API Mobile Access | Akses API mobile. |
| VIEW | `API.VIEW` | API - View | Akses view API. |

## `CHAT` {#module-chat}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| ADMIN / MANAGE (map: EDIT atau SYS) | `CHAT.ADMIN_SETTINGS` | Chat - Settings | Kelola pengaturan chat (reactions, retention, ACL, exports, audit). |
| VIEW | `CHAT.VIEW` | Chat - Lihat | Akses Internal Chat (baca & kirim pesan). |

## `DASHBOARD` {#module-dashboard}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| OTHER / legacy | `DASHBOARD.FINANCE_DETAIL` | Dashboard Finance Detail | Dashboard Detail target vs pencapaian per office. |
| OTHER / legacy | `DASHBOARD.OWNER_SUMMARY` | Dashboard Executive Summary | Ringkasan bisnis untuk SYS/Manager. |
| VIEW | `DASHBOARD.ACT_VIEW` | Dashboard ACT | Akses ACT Dashboard. |
| VIEW | `DASHBOARD.BRANCH_VIEW` | Dashboard Branch | Akses Branch Dashboard. |
| VIEW | `DASHBOARD.DETAIL_VIEW` | Dashboard Detail - View | Lihat dashboard detail. |
| VIEW | `DASHBOARD.FINANCE_VIEW` | Dashboard Finance | Akses Finance Dashboard. |
| VIEW | `DASHBOARD.HRL_VIEW` | Dashboard HRL | Akses HRL Dashboard. |
| VIEW | `DASHBOARD.ITC_VIEW` | Dashboard ITC | Akses ITC Dashboard. |
| VIEW | `DASHBOARD.KPI_VIEW` | Dashboard KPI | Akses KPI dashboard via menu dashboard. |
| VIEW | `DASHBOARD.OWNER_VIEW` | Dashboard Executive | Akses Executive Dashboard. |
| VIEW | `DASHBOARD.PROCUREMENT_VIEW` | Dashboard Procurement | Akses Procurement/Purchases Dashboard. |
| VIEW | `DASHBOARD.QUALITY_VIEW` | Dashboard Quality | Akses Quality Dashboard. |
| VIEW | `DASHBOARD.REGULATORY_VIEW` | Dashboard Regulatory | Akses Regulatory Dashboard. |
| VIEW | `DASHBOARD.SALES_VIEW` | Dashboard Sales | Akses Sales Dashboard. |
| VIEW | `DASHBOARD.SCM_VIEW` | Dashboard SCM | Akses SCM Dashboard. |
| VIEW | `DASHBOARD.VIEW` | Dashboard - Semua | Akses semua dashboard (override section-specific). |
| VIEW | `DASHBOARD.WAREHOUSE_VIEW` | Dashboard Warehouse | Akses Warehouse/WQS Dashboard. |

## `DOCS` {#module-docs}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| DELETE | `DOC.DELETE` | Doc - Delete | Hapus dokumen. |
| EDIT | `DOCS.EDIT` | Docs - Edit | Edit dokumentasi. |
| VIEW | `DOCS.VIEW` | Docs - View | Lihat dokumentasi. |

## `FIN` {#module-fin}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| CREATE | `FIN.AP.INVOICE.CREATE` | FIN: AP Invoice Create | Buat invoice AP. |
| CREATE | `FIN.FORWARDER.INVOICE.CREATE` | FIN: Forwarder Invoice Create | Invoice forwarder. |
| EDIT | `FIN.AP.INVOICE.EDIT` | FIN: AP Invoice Edit | Edit invoice AP. |
| OTHER / legacy | `FIN.AP.PAYMENT.DRAFT` | FIN: AP Payment Draft | Draft pembayaran AP. |
| OTHER / legacy | `FIN.FORWARDER.PAYMENT.DRAFT` | FIN: Forwarder Payment Draft | Draft bayar forwarder. |
| OTHER / legacy | `FIN.PIB.PAY` | FIN: PIB Pay | Bayar PIB. |
| VIEW | `FIN.AP.INVOICE.VIEW` | FIN: AP Invoice View | Lihat invoice AP. |
| VIEW | `FIN.AP.PAYMENT.VIEW` | FIN: AP Payment View | Lihat pembayaran AP. |
| WORKFLOW (map ke EDIT saat migrasi) | `FIN.AP.PAYMENT.APPROVE` | FIN: AP Payment Approve | Approve pembayaran AP. |
| WORKFLOW (map ke EDIT saat migrasi) | `FIN.FORWARDER.PAYMENT.APPROVE` | FIN: Forwarder Payment Approve | Approve bayar forwarder. |
| WORKFLOW (map ke EDIT saat migrasi) | `FIN.PAYMENT_APPROVE` | FIN - Approve Pembayaran | Approval final pengeluaran kas (HANYA MgrFIN_BGR + SYS). Segregation of duty. |

## `FIXED_ASSET` {#module-fixed-asset}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| AUDIT / REPORT (map: VIEW) | `FA.AUDIT_CLOSE` | FA - Tutup Audit | Tutup periode audit fixed asset. |
| AUDIT / REPORT (map: VIEW) | `FA.AUDIT_SAVE_LINES` | FA - Simpan Audit | Simpan baris hasil audit fixed asset. |
| AUDIT / REPORT (map: VIEW) | `FIXED_ASSET.AUDIT` | Aset - Audit Log | Lihat audit log aset. |
| AUDIT / REPORT (map: VIEW) | `FIXED_ASSET.REPORT_TAX_ANNUAL` | Fixed Asset - Tax Tahunan | Lihat laporan pajak tahunan fixed asset. |
| CREATE | `FA.AUDIT_CREATE` | FA - Buat Audit | Buat sesi audit fixed asset. |
| CREATE | `FIXED_ASSET.ASSET_CREATE` | Aset - Tambah | Input aset baru (acquisition). |
| DELETE | `FA.ASSET_DELETE` | FA - Hapus Asset | Hapus data asset. |
| DELETE | `FIXED_ASSET.ASSET_DELETE` | Aset - Hapus | Hapus data aset (high risk). |
| EDIT | `FIXED_ASSET.ASSET_EDIT` | Aset - Edit | Edit data aset. |
| EDIT | `FIXED_ASSET.DISPOSAL_EDIT` | Aset Disposal - Edit | Edit disposisi aset. |
| EDIT | `FIXED_ASSET.OPS_EDIT` | Aset Ops - Edit | Edit operasional aset. |
| EDIT | `FIXED_ASSET.TAX_ANNUAL_EDIT` | Aset Tax Annual - Edit | Edit perhitungan pajak tahunan. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `FA.ASSET_IMPORT` | FA - Import Asset | Import data asset dari file. |
| OTHER / legacy | `FA.ASSET_BULK` | FA - Bulk Action | Bulk action asset. |
| OTHER / legacy | `FA.ASSET_SAVE` | FA - Simpan Asset | Buat/edit data asset. |
| OTHER / legacy | `FA.ASSET_SEED` | FA - Seed Asset | Seed initial asset data. |
| OTHER / legacy | `FA.ASSET_TEMPLATE` | FA - Template Asset | Download template import asset. |
| OTHER / legacy | `FIXED_ASSET.DEPRECIATION_RUN` | Aset - Depresiasi | Hitung depresiasi periodik. |
| OTHER / legacy | `FIXED_ASSET.DEP_RUN` | Fixed Asset - Depresiasi | Jalankan kalkulasi depresiasi. |
| OTHER / legacy | `FIXED_ASSET.OPERATIONS` | Aset - Operasional | Operasional aset (move, repair, dispose). |
| OTHER / legacy | `FIXED_ASSET.OPS` | Fixed Asset - Operasional | Input maintenance, disposal asset. |
| OTHER / legacy | `FIXED_ASSET.TAX_ANNUAL` | Aset - Pajak Tahunan | Perhitungan pajak tahunan aset. |
| VIEW | `FIXED_ASSET.ASSET_VIEW` | Aset - Lihat | Lihat daftar & detail aset. |
| VIEW | `FIXED_ASSET.DASHBOARD_VIEW` | Fixed Asset - Dashboard | Akses Fixed Asset Dashboard. |
| VIEW | `FIXED_ASSET.DISPOSAL_VIEW` | Aset Disposal - Lihat | Lihat disposisi aset. |
| VIEW | `FIXED_ASSET.OPS_VIEW` | Aset Ops - Lihat | Lihat operasional aset. |
| VIEW | `FIXED_ASSET.TAX_ANNUAL_VIEW` | Aset Tax Annual - Lihat | Lihat perhitungan pajak tahunan. |
| VIEW | `FIXED_ASSET.VIEW` | Fixed Asset - Dashboard | Lihat dashboard fixed asset. |

## `HRL` {#module-hrl}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| CREATE | `HRL.DOC_CREATE` | Dokumen HRL - Tambah | Upload/tambah dokumen HRL baru. |
| CREATE | `HRL.REG_ALKES_CREATE` | Reg Alkes - Tambah | Input NIE/registrasi alkes baru. |
| DELETE | `HRL.DOC_DELETE` | Dokumen HRL - Hapus | Hapus dokumen HRL (high risk). |
| DELETE | `HRL.REG_ALKES_DELETE` | Reg Alkes - Hapus | Hapus data registrasi alkes (high risk). |
| EDIT | `HRL.DOC_EDIT` | Dokumen HRL - Edit | Edit metadata/dokumen HRL. |
| EDIT | `HRL.REG_ALKES_EDIT` | Reg Alkes - Edit | Edit data registrasi alkes. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `HRL.COMPLIANCE_EXPORT` | Compliance - Export | Export laporan compliance reg alkes. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `HRL.IMPORT_REKENING` | Rekening - Import | Import rekening bank karyawan. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `HRL.REG_ALKES_EXPORT` | Reg Alkes - Export | Export data reg alkes ke CSV/Excel. |
| VIEW | `HRL.DOC_VIEW` | Dokumen HRL - Lihat | Lihat dokumen HRL (legal, kontrak, ijin). |
| VIEW | `HRL.REG_ALKES_VIEW` | Reg Alkes - Lihat | Lihat data registrasi alat kesehatan. |
| VIEW | `HRL.VIEW` | HRL - Dashboard | Akses modul HRL (dokumen, reg alkes, compliance). |

## `HRL_PROCESS` {#module-hrl-process}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| CREATE | `HRL.PROCESS_CREATE` | HRL Process - Buat | Buat/submit request cuti/izin/dinas. |
| CREATE | `HRL.REQ_CUTI_CREATE` | Cuti — Buat | Buat/draft/submit pengajuan Cuti. |
| CREATE | `HRL.REQ_IZIN_CREATE` | Izin — Buat | Buat pengajuan Izin. |
| CREATE | `HRL.REQ_KENAIKAN_GAJI_CREATE` | Kenaikan Gaji — Buat | Buat pengajuan kenaikan gaji. |
| CREATE | `HRL.REQ_LEMBUR_CREATE` | Lembur — Buat | Buat pengajuan Lembur. |
| CREATE | `HRL.REQ_PERJADIN_CREATE` | Perjadin — Buat | Buat pengajuan Perjadin. |
| CREATE | `HRL.REQ_PERMINTAAN_KARYAWAN_CREATE` | Permintaan Karyawan — Buat | Buat pengajuan permintaan karyawan. |
| CREATE | `HRL.REQ_REKRUTMEN_CREATE` | Rekrutmen — Buat | Buat pengajuan rekrutmen. |
| DELETE | `HRL.PROCESS_DELETE` | HRL Process - Hapus | Hapus/batalkan request (soft delete). |
| DELETE | `HRL.REQ_CUTI_DELETE` | Cuti — Hapus | Soft delete pengajuan Cuti. |
| DELETE | `HRL.REQ_IZIN_DELETE` | Izin — Hapus | Hapus pengajuan Izin. |
| DELETE | `HRL.REQ_KENAIKAN_GAJI_DELETE` | Kenaikan Gaji — Hapus | Hapus pengajuan kenaikan gaji. |
| DELETE | `HRL.REQ_LEMBUR_DELETE` | Lembur — Hapus | Hapus pengajuan Lembur. |
| DELETE | `HRL.REQ_PERJADIN_DELETE` | Perjadin — Hapus | Hapus pengajuan Perjadin. |
| DELETE | `HRL.REQ_PERMINTAAN_KARYAWAN_DELETE` | Permintaan Karyawan — Hapus | Hapus pengajuan permintaan karyawan. |
| DELETE | `HRL.REQ_REKRUTMEN_DELETE` | Rekrutmen — Hapus | Hapus pengajuan rekrutmen. |
| EDIT | `HRL.PROCESS_EDIT` | HRL Process - Edit & Approve | Edit request & approve/reject (manager). |
| EDIT | `HRL.REQ_CUTI_EDIT` | Cuti — Edit & approve | Edit draft/reject + approve/reject alur. |
| EDIT | `HRL.REQ_IZIN_EDIT` | Izin — Edit & approve | Edit & approve Izin. |
| EDIT | `HRL.REQ_KENAIKAN_GAJI_EDIT` | Kenaikan Gaji — Edit & approve | Edit & approve kenaikan gaji. |
| EDIT | `HRL.REQ_LEMBUR_EDIT` | Lembur — Edit & approve | Edit & approve Lembur. |
| EDIT | `HRL.REQ_PERJADIN_EDIT` | Perjadin — Edit & approve | Edit & approve Perjadin. |
| EDIT | `HRL.REQ_PERMINTAAN_KARYAWAN_EDIT` | Permintaan Karyawan — Edit | Edit & approve permintaan karyawan. |
| EDIT | `HRL.REQ_REKRUTMEN_EDIT` | Rekrutmen — Edit & approve | Edit & approve rekrutmen. |
| VIEW | `HRL.PROCESS_VIEW` | HRL Process - Lihat | Lihat list & detail request cuti/izin/dinas. |
| VIEW | `HRL.REQ_CUTI_VIEW` | Cuti — Lihat | Lihat daftar/detail & alur approval pengajuan Cuti. |
| VIEW | `HRL.REQ_IZIN_VIEW` | Izin — Lihat | Lihat pengajuan Izin. |
| VIEW | `HRL.REQ_KENAIKAN_GAJI_VIEW` | Kenaikan Gaji — Lihat | Lihat pengajuan kenaikan gaji. |
| VIEW | `HRL.REQ_LEMBUR_VIEW` | Lembur — Lihat | Lihat pengajuan Lembur. |
| VIEW | `HRL.REQ_PERJADIN_VIEW` | Perjadin — Lihat | Lihat pengajuan Perjadin (form). |
| VIEW | `HRL.REQ_PERMINTAAN_KARYAWAN_VIEW` | Permintaan Karyawan — Lihat | Lihat pengajuan permintaan karyawan/ATK. |
| VIEW | `HRL.REQ_REKRUTMEN_VIEW` | Rekrutmen — Lihat | Lihat pengajuan rekrutmen. |

## `KPI` {#module-kpi}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| AUDIT / REPORT (map: VIEW) | `KPI.DO_AUDIT` | KPI DO - Audit | Audit KPI DO. |
| CREATE | `KPI.CREATE` | KPI - Tambah | Legacy/menu. Mutasi KPI efektif hanya SYS di kode. |
| DELETE | `KPI.DELETE` | KPI - Hapus | Legacy/menu. Hapus di app hanya SYS (kpi_can_manage). |
| EDIT | `KPI.EDIT` | KPI - Edit | Legacy/menu. Bukan gate utama mutasi — level SYS (rmi_sys_gate.php). |
| VIEW | `KPI.DO_VIEW` | KPI DO - View | Lihat KPI DO. |
| VIEW | `KPI.EMPLOYEE_VIEW` | KPI Employee - View | Lihat KPI employee. |
| VIEW | `KPI.OFFICE_VIEW` | KPI Office - View | Lihat KPI office. |
| VIEW | `KPI.PURCHASES_VIEW` | KPI Purchases - View | Lihat KPI purchases. |
| VIEW | `KPI.STOCK_VIEW` | KPI Stock - View | Lihat KPI stock. |
| VIEW | `KPI.VIEW` | KPI - Dashboard | Akses KPI Center & laporan KPI (RBAC: buka halaman). |

## `MASTER` {#module-master}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| ADMIN / MANAGE (map: EDIT atau SYS) | `MASTER.ADMIN_CENTER` | Master - Admin Center | Akses admin-level Master Data Center. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `MASTER_CUSTOMERS.MANAGE` | Master Customer - Kelola | Kelola data customer. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `MASTER_DEPARTEMENTS.MANAGE` | Master Dept - Kelola | Kelola departemen. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `MASTER_EMAILCOMPANY.MANAGE` | Master Email - Kelola | Kelola email perusahaan. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `MASTER_EMPLOYEES.MANAGE` | Master Karyawan - Kelola | Kelola data karyawan. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `MASTER_TAX.MANAGE` | Master Tax - Kelola | Kelola master pajak. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `MASTER_USER.MANAGE` | Master User - Kelola | Kelola data user. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `MASTER_VENDORS.MANAGE` | Master Vendor - Kelola | Kelola data vendor. |
| AUDIT / REPORT (map: VIEW) | `MASTER_SYSTEM.LOGIN_MANAGE` | Master System - Login Kelola | Kelola user login sistem. |
| CREATE | `MASTER.COMPANY_BANK_CREATE` | Rekening Perusahaan - Tambah | Tambah rekening perusahaan. |
| CREATE | `MASTER.CUSTOMER_CREATE` | Customer - Tambah | Tambah pelanggan baru. |
| CREATE | `MASTER.DEPARTMENT_CREATE` | Departemen - Tambah | Tambah departemen baru. |
| CREATE | `MASTER.EMAIL_COMPANY_CREATE` | Email Perusahaan - Tambah | Tambah konfigurasi email baru. |
| CREATE | `MASTER.EMPLOYEE_CREATE` | Karyawan - Tambah | Tambah karyawan baru. |
| CREATE | `MASTER.MANUFACTURE_CREATE` | Manufacture - Tambah | Tambah data pabrik baru. |
| CREATE | `MASTER.OFFICE_CREATE` | Office - Tambah | Tambah kantor baru. |
| CREATE | `MASTER.PAYMENT_TERMS_CREATE` | Payment Terms - Tambah | Tambah termin pembayaran baru. |
| CREATE | `MASTER.PIC_CUSTOMER_CREATE` | PIC Customer - Tambah | Tambah mapping PIC customer. |
| CREATE | `MASTER.PRICELIST_BUY_CREATE` | Pricelist Beli - Tambah | Tambah harga beli baru. |
| CREATE | `MASTER.PRICELIST_SELL_CREATE` | Pricelist Jual - Tambah | Tambah harga jual baru. |
| CREATE | `MASTER.PRODUCT_CREATE` | Produk - Tambah | Tambah produk baru. |
| CREATE | `MASTER.PRODUCT_PACKAGE_CREATE` | Paket Produk - Tambah | Tambah paket produk baru. |
| CREATE | `MASTER.TAX_CREATE` | Pajak - Tambah | Tambah pajak baru. |
| CREATE | `MASTER.VENDOR_CREATE` | Vendor - Tambah | Tambah vendor baru. |
| DELETE | `MASTER.COMPANY_BANK_DELETE` | Rekening Perusahaan - Hapus | Hapus rekening perusahaan. |
| DELETE | `MASTER.CUSTOMER_DELETE` | Customer - Hapus | Hapus data pelanggan (high risk). |
| DELETE | `MASTER.DEPARTMENT_DELETE` | Departemen - Hapus | Hapus data departemen. |
| DELETE | `MASTER.EMAIL_COMPANY_DELETE` | Email Perusahaan - Hapus | Hapus konfigurasi email. |
| DELETE | `MASTER.EMPLOYEE_DELETE` | Karyawan - Hapus | Hapus data karyawan. |
| DELETE | `MASTER.MANUFACTURE_DELETE` | Manufacture - Hapus | Hapus data pabrik. |
| DELETE | `MASTER.OFFICE_DELETE` | Office - Hapus | Hapus data kantor. |
| DELETE | `MASTER.PAYMENT_TERMS_DELETE` | Payment Terms - Hapus | Hapus termin pembayaran. |
| DELETE | `MASTER.PIC_CUSTOMER_DELETE` | PIC Customer - Hapus | Hapus mapping PIC customer. |
| DELETE | `MASTER.PRICELIST_BUY_DELETE` | Pricelist Beli - Hapus | Hapus harga beli. |
| DELETE | `MASTER.PRICELIST_SELL_DELETE` | Pricelist Jual - Hapus | Hapus harga jual. |
| DELETE | `MASTER.PRODUCT_DELETE` | Produk - Hapus | Hapus data produk (high risk). |
| DELETE | `MASTER.PRODUCT_PACKAGE_DELETE` | Paket Produk - Hapus | Hapus paket produk. |
| DELETE | `MASTER.TAX_DELETE` | Pajak - Hapus | Hapus data pajak. |
| DELETE | `MASTER.VENDOR_DELETE` | Vendor - Hapus | Hapus data vendor. |
| EDIT | `MASTER.COMPANY_BANK_EDIT` | Rekening Perusahaan - Edit | Edit rekening perusahaan. |
| EDIT | `MASTER.CUSTOMER_EDIT` | Customer - Edit | Edit data pelanggan. |
| EDIT | `MASTER.DEPARTMENT_EDIT` | Departemen - Edit | Edit data departemen. |
| EDIT | `MASTER.EMAIL_COMPANY_EDIT` | Email Perusahaan - Edit | Edit konfigurasi email. |
| EDIT | `MASTER.EMPLOYEE_EDIT` | Karyawan - Edit | Edit data karyawan. |
| EDIT | `MASTER.MANUFACTURE_EDIT` | Manufacture - Edit | Edit data pabrik. |
| EDIT | `MASTER.OFFICE_EDIT` | Office - Edit | Edit data kantor. |
| EDIT | `MASTER.PAYMENT_TERMS_EDIT` | Payment Terms - Edit | Edit termin pembayaran. |
| EDIT | `MASTER.PIC_CUSTOMER_EDIT` | PIC Customer - Edit | Edit mapping PIC customer. |
| EDIT | `MASTER.PRICELIST_BUY_EDIT` | Pricelist Beli - Edit | Edit harga beli. |
| EDIT | `MASTER.PRICELIST_SELL_EDIT` | Pricelist Jual - Edit | Edit harga jual. |
| EDIT | `MASTER.PRODUCT_EDIT` | Produk - Edit | Edit data produk. |
| EDIT | `MASTER.PRODUCT_PACKAGE_EDIT` | Paket Produk - Edit | Edit paket produk. |
| EDIT | `MASTER.TAX_EDIT` | Pajak - Edit | Edit data pajak. |
| EDIT | `MASTER.VENDOR_EDIT` | Vendor - Edit | Edit data vendor. |
| EDIT | `MASTER_PRODUCTS.PACKAGE_EDIT` | Master Produk - Paket Edit | Edit paket produk. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `MASTER.CUSTOMER_EXPORT` | Customer - Export | Export daftar customer (CSV/Excel). |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `MASTER.IMPORT_CUSTOMERS` | Customer - Import | Import data customer dari CSV. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `MASTER.IMPORT_PRODUCTS` | Produk - Import | Import data produk dari CSV. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `MASTER.IMPORT_VENDORS` | Vendor - Import | Import data vendor dari CSV. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `MASTER.PRODUCT_MEDIA_UPLOAD` | Produk - Upload Media | Upload foto/video produk. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `MASTER_CUSTOMERS.EXPORT` | Master Customer - Export | Export data customer. |
| OTHER / legacy | `MANUFACTURES_DOCS.ACCESS` | Mfr Docs - Akses | Akses dokumen manufactures. |
| OTHER / legacy | `MASTER.PRICELIST` | Master - Pricelist | Kelola pricelist. |
| OTHER / legacy | `MASTER.PRODUCTS` | Master - Produk | Kelola produk. |
| OTHER / legacy | `MASTER_CUSTOMERS.ACCESS` | Master Customer - Akses | Lihat data customer. |
| OTHER / legacy | `MASTER_DATA.ACCESS` | Master Data - Akses | Akses Master Data Center. |
| OTHER / legacy | `MASTER_WRITE` | Master Write | Legacy tulis master — tinjau matrix setelah migrasi 162; pertimbangkan granular MASTER.*. |
| VIEW | `MASTER.COMPANY_BANK_VIEW` | Rekening Perusahaan - Lihat | Lihat rekening perusahaan. |
| VIEW | `MASTER.CUSTOMER_VIEW` | Customer - Lihat | Lihat data pelanggan/RS/klinik. |
| VIEW | `MASTER.DEPARTMENT_VIEW` | Departemen - Lihat | Lihat data departemen. |
| VIEW | `MASTER.EMAIL_COMPANY_VIEW` | Email Perusahaan - Lihat | Lihat konfigurasi email perusahaan. |
| VIEW | `MASTER.EMPLOYEE_VIEW` | Karyawan - Lihat | Lihat data karyawan. |
| VIEW | `MASTER.MANUFACTURE_VIEW` | Manufacture - Lihat | Lihat data pabrik/manufacturer. |
| VIEW | `MASTER.OFFICE_VIEW` | Office - Lihat | Lihat data kantor/office code. |
| VIEW | `MASTER.PAYMENT_TERMS_VIEW` | Payment Terms - Lihat | Lihat termin pembayaran. |
| VIEW | `MASTER.PIC_CUSTOMER_VIEW` | PIC Customer - Lihat | Lihat mapping PIC customer. |
| VIEW | `MASTER.PRICELIST_BUY_VIEW` | Pricelist Beli - Lihat | Lihat harga beli. |
| VIEW | `MASTER.PRICELIST_SELL_VIEW` | Pricelist Jual - Lihat | Lihat harga jual. |
| VIEW | `MASTER.PRODUCT_PACKAGE_VIEW` | Paket Produk - Lihat | Lihat paket produk. |
| VIEW | `MASTER.PRODUCT_VIEW` | Produk - Lihat | Lihat daftar & detail produk. |
| VIEW | `MASTER.TAX_VIEW` | Pajak - Lihat | Lihat data pajak/PPN/withholding. |
| VIEW | `MASTER.VENDOR_VIEW` | Vendor - Lihat | Lihat data vendor. |
| VIEW | `MASTER.VIEW` | Master - Dashboard | Akses dashboard Master Data Center. |
| VIEW | `MASTER_PRODUCTS.MEDIA_VIEW` | Master Produk - Media | Lihat media produk. |
| VIEW | `MASTER_PRODUCTS.PACKAGE_VIEW` | Master Produk - Paket View | Lihat paket produk. |
| VIEW | `MASTER_PRODUCTS.PRINT_VIEW` | Master Produk - Print | Lihat/print info produk. |
| VIEW | `MASTER_SYSTEM.CONFIG_VIEW` | Master System - Config View | Lihat konfigurasi sistem. |

## `MPR` {#module-mpr}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| CREATE | `MPR.PLAN_CREATE` | Plan - Buat | Buat plan MPR baru. |
| DELETE | `MPR.PLAN_DELETE` | Plan - Hapus | Hapus/restore plan MPR (soft delete). |
| EDIT | `MPR.PLAN_EDIT` | Plan - Edit | Edit plan MPR. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `MPR.PLAN_EXPORT` | Plan - Export | Export report/rekap plan MPR. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `MPR.PLAN_IMPORT` | Plan - Import | Import plan MPR dari CSV. |
| VIEW | `MPR.PLAN_VIEW` | Plan - Lihat | Lihat plan MPR. |
| VIEW | `MPR.VIEW` | MPR - Dashboard | Akses modul MPR (Marketing & Project: plans, daily ops, budget). |
| WORKFLOW (map ke EDIT saat migrasi) | `MPR.PLAN_APPROVE` | Plan - Approve | Approve/reject plan MPR. |

## `PANDUAN` {#module-panduan}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| VIEW | `PANDUAN.PURCHASES_VIEW` | Panduan - Purchases | Baca purchases/panduan.php tanpa hak Purchases penuh. |
| VIEW | `PANDUAN.SALES_VIEW` | Panduan - Sales & DO | Baca sales/panduan.php tanpa hak CRUD Sales penuh. |

## `PAYROLL` {#module-payroll}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| ADMIN / MANAGE (map: EDIT atau SYS) | `PAYROLL.SETTINGS` | Payroll - Pengaturan | Konfigurasi payroll, matrix golongan gaji. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `PAYROLL.SETTINGS_SAVE` | Payroll - Simpan Settings | Simpan konfigurasi payroll. |
| AUDIT / REPORT (map: VIEW) | `PAYROLL.AUDIT` | Payroll - Audit Log | Lihat audit payroll. |
| CREATE | `PAYROLL.CREATE` | Payroll - Generate Run | Generate payroll run per periode. |
| CREATE | `PAYROLL.LOANS_CREATE` | Kasbon - Tambah | Input pinjaman/kasbon baru. |
| DELETE | `PAYROLL.DELETE` | Payroll - Hapus Run | Hapus payroll run (high risk). |
| DELETE | `PAYROLL.LOANS_DELETE` | Kasbon - Hapus | Hapus data pinjaman/kasbon. |
| DELETE | `PAYROLL.LOAN_DELETE` | Payroll - Hapus Pinjaman | Hapus data pinjaman. |
| DELETE | `PAYROLL.MATRIX_DELETE` | Payroll - Hapus Matrix | Hapus matrix kompensasi. |
| EDIT | `PAYROLL.EDIT` | Payroll - Edit Run | Edit item run (tunjangan/potongan/lembur). |
| EDIT | `PAYROLL.LOANS_EDIT` | Kasbon - Edit | Edit data pinjaman/kasbon. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `PAYROLL.EXPORT` | Payroll - Export Bank | Export file pembayaran bank. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `PAYROLL.LOAN_EXPORT` | Payroll - Export Pinjaman | Export data pinjaman. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `PAYROLL.MATRIX_EXPORT` | Payroll - Export Matrix | Export matrix kompensasi. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `PAYROLL.MATRIX_IMPORT` | Payroll - Import Matrix | Import matrix kompensasi. |
| OTHER / legacy | `PAYROLL.CREATE_RUN` | Payroll - Buat Run | Buat payroll run baru. |
| OTHER / legacy | `PAYROLL.LOAN_SAVE` | Payroll - Simpan Pinjaman | Input/edit pinjaman. |
| OTHER / legacy | `PAYROLL.LOAN_STATUS` | Payroll - Status Pinjaman | Update status pinjaman. |
| OTHER / legacy | `PAYROLL.MATRIX_SAVE` | Payroll - Simpan Matrix | Input/edit matrix kompensasi. |
| OTHER / legacy | `PAYROLL.MATRIX_TEMPLATE` | Payroll - Template Matrix | Download template matrix. |
| OTHER / legacy | `PAYROLL.RUN_RECALC_ABSENSI` | Payroll - Rekalkul Absensi | Rekalkul absensi di run. |
| OTHER / legacy | `PAYROLL.RUN_SYNC_LOANS` | Payroll - Sync Pinjaman | Sync pinjaman ke run. |
| OTHER / legacy | `PAYROLL.RUN_SYNC_MATRIX` | Payroll - Sync Matrix | Sync matrix ke run. |
| OTHER / legacy | `PAYROLL.RUN_UPDATE_ITEM` | Payroll - Update Item Run | Update item di payroll run. |
| VIEW | `PAYROLL.LOANS_VIEW` | Kasbon - Lihat | Lihat data pinjaman/kasbon. |
| VIEW | `PAYROLL.MATRIX_VIEW` | Payroll - Lihat Matrix | Lihat matrix kompensasi. |
| VIEW | `PAYROLL.PAYSLIP_VIEW` | Payslip - Lihat | Lihat/print payslip. |
| VIEW | `PAYROLL.RUN_VIEW` | Payroll - Lihat Run | Lihat payroll run. |
| VIEW | `PAYROLL.SETTINGS_VIEW` | Payroll - Lihat Settings | Lihat konfigurasi payroll. |
| VIEW | `PAYROLL.VIEW` | Payroll - Dashboard | Lihat dashboard payroll & history runs. |
| WORKFLOW (map ke EDIT saat migrasi) | `PAYROLL.APPROVE` | Payroll - Post/Lock | Lock/post & finalisasi payroll run. |

## `PQP` {#module-pqp}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| CREATE | `PQP.CREATE` | PQP - Tambah | Buat data PQP baru. |
| CREATE | `PQP.QUALITY_CREATE` | Quality - Tambah | Input data QA baru. |
| DELETE | `PQP.DELETE` | PQP - Hapus | Hapus data quality PQP. |
| DELETE | `PQP.QUALITY_DELETE` | Quality - Hapus | Hapus data QA (high risk). |
| EDIT | `PQP.EDIT` | PQP - Edit | Edit data quality/produk PQP. |
| EDIT | `PQP.QUALITY_EDIT` | Quality - Edit | Edit data QA. |
| VIEW | `PQP.QUALITY_VIEW` | Quality - Lihat | Lihat data quality assurance. |
| VIEW | `PQP.VIEW` | PQP - Dashboard | Akses modul PQP (produk, quality, reg alkes). |

## `PURCHASES` {#module-purchases}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| ADMIN / MANAGE (map: EDIT atau SYS) | `PURCHASES.ADMIN_GL_AUTO` | Purchases - GL Auto | Generate jurnal otomatis (high risk). |
| ADMIN / MANAGE (map: EDIT atau SYS) | `PURCHASES.ADMIN_STOCK_UPDATE` | Purchases - Stock Update GR | Update stock dari GR (high risk). |
| CREATE | `PURCHASES.AP_INVOICE_CREATE` | Invoice AP - Buat | Input Invoice AP baru. |
| CREATE | `PURCHASES.AP_PAYMENT_CREATE` | Payment AP - Buat | Input pembayaran AP baru. |
| CREATE | `PURCHASES.CREATE` | Purchases - Buat | Membuat purchases/PO (alias). |
| CREATE | `PURCHASES.FORWARDING_CREATE` | Forwarding - Buat | Buat data forwarder. |
| CREATE | `PURCHASES.PO_CREATE` | PO - Buat | Buat PO baru. |
| DELETE | `PURCHASES.AP_INVOICE_DELETE` | Invoice AP - Hapus | Hapus Invoice AP. |
| DELETE | `PURCHASES.AP_PAYMENT_DELETE` | Payment AP - Hapus | Hapus pembayaran AP. |
| DELETE | `PURCHASES.DELETE` | Purchases - Hapus | Menghapus purchases/PO (alias, manager only). |
| DELETE | `PURCHASES.FORWARDING_DELETE` | Forwarding - Hapus | Hapus data forwarder. |
| DELETE | `PURCHASES.GR_DELETE` | GR - Hapus | Hapus/batalkan GR. |
| DELETE | `PURCHASES.PO_DELETE` | PO - Hapus | Hapus/batalkan PO (high risk). |
| EDIT | `PURCHASES.AP_INVOICE_EDIT` | Invoice AP - Edit | Edit Invoice AP. |
| EDIT | `PURCHASES.AP_PAYMENT_EDIT` | Payment AP - Edit | Edit pembayaran AP. |
| EDIT | `PURCHASES.CEISA_EDIT` | CEISA - Edit | Edit dokumen CEISA/PIB. |
| EDIT | `PURCHASES.EDIT` | Purchases - Edit | Mengubah purchases/PO (alias). |
| EDIT | `PURCHASES.FORWARDING_EDIT` | Forwarding - Edit | Edit data forwarder. |
| EDIT | `PURCHASES.GR_EDIT` | GR - Edit | Edit Goods Receipt. |
| EDIT | `PURCHASES.IMPORT_CONTROL_EDIT` | Import Control - Edit | Edit import control tower. |
| EDIT | `PURCHASES.PO_EDIT` | PO - Edit | Edit PO & detail item. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `PURCHASES.EXPORT` | Purchases - Export | Export laporan/data purchases. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `PURCHASES.IMPORT_CONTROL` | Import - Control Tower | Monitoring import & compliance (control tower). |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `PURCHASES.PO_PRINT` | PO - Print | Print PO. |
| OTHER / legacy | `PURCHASES.API_PR` | Purchases - API PR | Endpoint API PR (internal). |
| OTHER / legacy | `PURCHASES.CEISA_PIB` | CEISA PIB | Entry/view dokumen PIB/CEISA (alias). |
| OTHER / legacy | `PURCHASES.GR_PROCESS` | GR - Proses/Terima | Input/terima barang dari PO. |
| VIEW | `PURCHASES.AP_INVOICE_VIEW` | Invoice AP - Lihat | Lihat Invoice AP. |
| VIEW | `PURCHASES.AP_PAYMENT_VIEW` | Payment AP - Lihat | Lihat pembayaran AP. |
| VIEW | `PURCHASES.CEISA_VIEW` | CEISA - View | Lihat dokumen CEISA/PIB. |
| VIEW | `PURCHASES.FORWARDING_VIEW` | Forwarding - Lihat | Lihat data forwarding/logistik. |
| VIEW | `PURCHASES.GR_VIEW` | GR - Lihat | Lihat Goods Receipt. |
| VIEW | `PURCHASES.PO_VIEW` | PO - Lihat | Lihat daftar & detail PO. |
| VIEW | `PURCHASES.REPORTS_VIEW` | Purchases - Laporan | Lihat laporan purchases. |
| VIEW | `PURCHASES.VIEW` | Purchases - Dashboard | Lihat dashboard & daftar transaksi purchases. |
| WORKFLOW (map ke EDIT saat migrasi) | `PURCHASES.APPROVE` | Purchases - Approve | Approve purchases/PO (alias). |
| WORKFLOW (map ke EDIT saat migrasi) | `PURCHASES.AP_PAYMENT_APPROVE_POST` | Payment AP - Approve/Post | Approve dan post pembayaran AP (HANYA MgrFIN_BGR + SYS). Cash outflow final. |
| WORKFLOW (map ke EDIT saat migrasi) | `PURCHASES.GL_REVERSAL_APPROVE` | GL Reversal - Approve | Approve GL Reversal Request (HANYA MgrFIN_BGR + SYS). High-risk dual-control. |
| WORKFLOW (map ke EDIT saat migrasi) | `PURCHASES.PO_APPROVE` | PO - Approve | Approve/lock PO. |

## `RBAC` {#module-rbac}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| OTHER / legacy | `RBAC.ROLE_ASSIGN` | RBAC Role Assign | Assign role ke user/dept. |
| OTHER / legacy | `RBAC.USER_PERMISSIONS` | RBAC User Permissions | Kelola override permission per user. |
| VIEW | `RBAC.VIEW` | RBAC - View | Akses halaman RBAC Center (read-only). |

## `SALES` {#module-sales}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| AUDIT / REPORT (map: VIEW) | `SALES.AUDIT` | Sales - Audit & KPI | Lihat audit log DO, SLA, dan KPI penjualan. |
| AUDIT / REPORT (map: VIEW) | `SALES.KPI_AUDIT` | Sales KPI - Audit | Audit detail KPI sales/DO. |
| CREATE | `SALES.CREATE` | Sales - Buat DO | Membuat DO / transaksi sales baru. |
| DELETE | `SALES.DELETE` | Sales - Hapus | Hapus/batalkan DO (high risk). |
| EDIT | `SALES.CRM_DO_EDIT` | Sales - CRM DO Edit | Edit DO dari CRM. |
| EDIT | `SALES.EDIT` | Sales - Edit & Proses | Edit DO & proses semua tahap workflow DO. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `SALES.DO_PRINT_CF` | Sales - DO Print CF | Print confirmation DO. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `SALES.EXPORT` | Sales - Export | Export data sales CSV. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `SALES.PRINT` | Sales - Print DO | Print CF/DO. |
| OTHER / legacy | `SALES.FIN_TASKS` | Sales - FIN Tasks | Akses FIN Task DO. |
| OTHER / legacy | `SALES.SCM_TASKS` | Sales - SCM Tasks | Akses SCM Task DO. |
| OTHER / legacy | `SALES.TASK_ACT` | Sales - ACT Tasks | Task ACT pada DO. |
| OTHER / legacy | `SALES.TASK_FIN` | Sales - FIN Tasks | Task FIN pada DO. |
| OTHER / legacy | `SALES.TASK_SCM` | Sales - SCM Tasks | Task SCM pada DO. |
| OTHER / legacy | `SALES.TASK_WQS` | Sales - WQS Tasks | Task WQS pada DO. |
| VIEW | `SALES.AUDIT_VIEW` | Sales - Audit View | Lihat audit sales. |
| VIEW | `SALES.CONTROL_TOWER_VIEW` | Sales - Control Tower | Akses Sales Control Tower. |
| VIEW | `SALES.DASHBOARD_VIEW` | Sales - Dashboard | Lihat Sales Dashboard. |
| VIEW | `SALES.DO_VIEW` | Sales - DO View | Lihat Delivery Order. |
| VIEW | `SALES.KPI_AUDIT_VIEW` | Sales - KPI Audit | Lihat KPI Audit DO. |
| VIEW | `SALES.KPI_SLA_VIEW` | Sales - KPI SLA | Lihat KPI SLA DO. |
| VIEW | `SALES.KPI_VIEW` | Sales KPI & SLA View | KPI/SLA sales. |
| VIEW | `SALES.TRACKING_VIEW` | Sales Tracking - View | Lihat tracking DO/shipment. |
| VIEW | `SALES.VIEW` | Sales - Lihat | Lihat dashboard, control tower, daftar & detail DO. |

## `SCM` {#module-scm}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| EDIT | `SCM.FWD.QUOTES.EDIT` | SCM Edit Forwarder Quotes | Edit quote forwarder. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `SCM.BL.UPLOAD_DRAFT` | SCM Upload BL Draft | Upload draft BL. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `SCM.PURCHASES.DOC.UPLOAD` | SCM Upload Purchases Forwarder Docs (QUOTES/BL/TRACKING) | Upload dokumen forwarder. |
| OTHER / legacy | `SCM.SHIPMENT.TRACK.UPDATE` | SCM Update Shipment Tracking & Forwarder Fields | Update tracking kiriman. |
| VIEW | `SCM.BL.VIEW` | SCM View Bill of Lading docs | Lihat dokumen BL. |
| VIEW | `SCM.FWD.QUOTES.VIEW` | SCM View Forwarder Quotes | Lihat quote forwarder. |
| VIEW | `SCM.SHIPMENT.TRACK.VIEW` | SCM View Shipment Tracking | Lihat tracking kiriman. |
| WORKFLOW (map ke EDIT saat migrasi) | `SCM.BL.FINALIZE` | SCM Upload/Finalize BL Final | Finalisasi BL. |
| WORKFLOW (map ke EDIT saat migrasi) | `SCM.FWD.QUOTES.APPROVE` | SCM Approve/Lock Forwarder Quote Selection | Approve quote forwarder. |

## `STOCK` {#module-stock}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| AUDIT / REPORT (map: VIEW) | `STOCK.AUDIT` | Stock - Audit Log | Lihat audit stok & movement log. |
| CREATE | `STOCK.CREATE` | Stock - Input Opname | Input penyesuaian/opname stok baru. |
| DELETE | `STOCK.DELETE` | Stock - Hapus | Hapus penyesuaian stok (high risk). |
| EDIT | `STOCK.EDIT` | Stock - Edit | Edit data penyesuaian stok. |
| OTHER / legacy | `STOCK.OPNAME` | Stock - Opname | Proses stock opname. |
| OTHER / legacy | `STOCK.TRANSFER` | Stock - Transfer | Transfer stok antar kantor. |
| OTHER / legacy | `STOCK.WQS_ALLOCATION` | Stock - Allocation | Kelola alokasi stok. |
| OTHER / legacy | `STOCK.WQS_INCOMING` | Stock - Incoming | Proses incoming barang. |
| OTHER / legacy | `STOCK.WQS_PICKING` | Stock - Picking | Proses picking DO. |
| OTHER / legacy | `STOCK.WQS_STOCK_ADJUSTMENT` | Stock - Adjustment | Input stock adjustment. |
| VIEW | `STOCK.VIEW` | Stock - Lihat | Lihat stok per office (list/summary). |

## `SYSTEM` {#module-system}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| ADMIN / MANAGE (map: EDIT atau SYS) | `SYSTEM.API_PARTNER_KEYS` | System - API Partner Keys | Kelola API key untuk partner eksternal. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `SYSTEM.CONFIG_MANAGE` | System - Konfigurasi | Konfigurasi global (seed KPI, office code, feature toggle). |
| ADMIN / MANAGE (map: EDIT atau SYS) | `SYSTEM.MFA_BYPASS_MANAGE` | System - MFA Bypass | Kelola MFA bypass tickets. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `SYSTEM.MFA_POLICY_MANAGE` | System - MFA Policy | Kelola policy MFA per role/dept. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `SYSTEM.RATE_LIMIT_MANAGE` | System - Rate Limit | Konfigurasi threshold API write per scope. |
| ADMIN / MANAGE (map: EDIT atau SYS) | `SYSTEM.RBAC_MANAGE` | System - Kelola RBAC | Kelola registry permission & matrix Dept+Role (RBAC Center). |
| ADMIN / MANAGE (map: EDIT atau SYS) | `SYSTEM.USER_MANAGE` | System - Kelola User | Buat/edit/hapus user login, dept/role/office, reset password, lock/unlock. |
| AUDIT / REPORT (map: VIEW) | `SYSTEM.JOBS_MONITOR` | System - Jobs Monitor | Monitoring worker queue, retry/run-now/cancel. |
| VIEW | `SYSTEM.AUDIT_LOG_VIEW` | System - Lihat Audit Log | Lihat Audit Log semua aktivitas sistem (system_audit_logs). Bisa dikonfigurasi per Dept+Role di RBAC Center. |
| VIEW | `SYSTEM.SECURITY_VIEW` | System - Lihat Keamanan | Lihat info security/session (read-only). |
| VIEW | `SYSTEM.VIEW` | System - View | Akses view halaman sistem (read-only). |
| VIEW (READ alias) | `SYSTEM.ACCOUNT_READINESS` | System - Account Readiness | Cek kesiapan akun (MFA, password, dll). |

## `TOOLS` {#module-tools}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| ADMIN / MANAGE (map: EDIT atau SYS) | `TOOLS.BACKUP_MANAGE` | Tools - Backup | Backup, restore, schedule, retention. |
| AUDIT / REPORT (map: VIEW) | `TOOLS.READINESS_AUDIT` | Tools - Readiness Audit | Audit kesiapan deploy & cutover. |
| AUDIT / REPORT (map: VIEW) | `TOOLS.SECURITY_AUDIT` | Tools - Security Audit | Static scan keamanan & konsistensi. |
| EDIT | `TOOLS.RESTORE_EDIT` | Tools Restore - Edit | Jalankan restore. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `TOOLS.ENTERPRISE_AUDIT_EXPORT` | Tools - Audit Export | Export audit report (CSV/JSON). |
| OTHER / legacy | `TOOLS.COMPLIANCE_EVIDENCE` | Tools Compliance - Evidence | Lihat/upload evidence compliance. |
| OTHER / legacy | `TOOLS.DR_BACKUP` | Tools DR - Backup | Jalankan DR backup. |
| OTHER / legacy | `TOOLS.DR_RESTORE` | Tools DR - Restore | Jalankan DR restore. |
| OTHER / legacy | `TOOLS.ITC_RESET_PASSWORD` | Tools - Reset Password | Reset password user (ITC support). |
| OTHER / legacy | `TOOLS.OPS_CONTROL_CENTER` | Tools Ops Control Center | Akses Ops Control Center. |
| OTHER / legacy | `TOOLS.PURCHASES_M2_APPLY` | Tools - M2 Patch | Jalankan patch/repair purchases (high risk). |
| OTHER / legacy | `TOOLS.RELEASE_DEPLOY` | Tools Release - Deploy | Jalankan deploy. |
| OTHER / legacy | `TOOLS.REVIEW_KIT` | Tools - Review Kit | Review kit workspace & signoff. |
| VIEW | `TOOLS.COMPLIANCE_VIEW` | Tools Compliance - View | Lihat compliance. |
| VIEW | `TOOLS.CONTRACT_VIEW` | Tools Contract - View | Lihat kontrak/agreement. |
| VIEW | `TOOLS.DR_VIEW` | Tools DR - View | Lihat Disaster Recovery. |
| VIEW | `TOOLS.ENTERPRISE_AUDIT_VIEW` | Tools - Audit View | Lihat hasil static scan audit keamanan/konsistensi. |
| VIEW | `TOOLS.OPS_VIEW` | Tools Ops - View | Lihat operasional/ops. |
| VIEW | `TOOLS.RELEASE_VIEW` | Tools Release - View | Lihat release/deploy. |
| VIEW | `TOOLS.RESTORE_VIEW` | Tools Restore - View | Lihat restore/backup. |
| VIEW | `TOOLS.RFC_VIEW` | Tools RFC - View | Lihat Request for Change. |
| VIEW | `TOOLS.VIEW` | Tools - Dashboard | Akses menu Tools/Diagnostics. |
| WORKFLOW (map ke EDIT saat migrasi) | `TOOLS.RFC_APPROVE` | Tools RFC - Approve | Approve Request for Change. |

## `WEB_ADMIN` {#module-web-admin}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| EDIT | `WEB_ADMIN.THRESHOLDS_EDIT` | Web Admin Thresholds - Edit | Edit thresholds di Web Admin. |
| VIEW | `WEB_ADMIN.OPS_VIEW` | Web Admin Ops - View | Lihat ops di Web Admin. |
| VIEW | `WEB_ADMIN.RFC_VIEW` | Web Admin RFC - View | Lihat RFC di Web Admin. |
| VIEW | `WEB_ADMIN.VIEW` | Web Admin - View | Akses Web Admin. |
| WORKFLOW (map ke EDIT saat migrasi) | `WEB_ADMIN.RFC_APPROVE` | Web Admin RFC - Approve | Approve RFC di Web Admin. |

## `WF` {#module-wf}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| VIEW | `WF.OUTBOX.VIEW` | Workflow: View Outbox | Lihat outbox workflow. |
| VIEW | `WF.TASK.VIEW` | Workflow: View Tasks | Lihat task workflow. |

## `WQS` {#module-wqs}

| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |
|---|---|---|---|
| CREATE | `WQS.ALLOCATION_CREATE` | Allocation - Buat | Buat alokasi stok untuk order/DO. |
| CREATE | `WQS.INCOMING_CREATE` | Incoming - Buat | Input penerimaan barang baru. |
| CREATE | `WQS.PICKING_CREATE` | Picking - Buat | Buat picking baru untuk DO/Order. |
| CREATE | `WQS.PR_CREATE` | PR - Buat | Buat Purchase Request baru. |
| CREATE | `WQS.TRANSFER_CREATE` | Transfer - Buat | Buat transfer stok antar kantor (SYS only). |
| DELETE | `WQS.ALLOCATION_DELETE` | Allocation - Hapus | Hapus alokasi. |
| DELETE | `WQS.INCOMING_DELETE` | Incoming - Hapus | Hapus data incoming. |
| DELETE | `WQS.PICKING_DELETE` | Picking - Hapus | Hapus data picking. |
| DELETE | `WQS.PR_DELETE` | PR - Hapus | Hapus Purchase Request. |
| EDIT | `WQS.ALLOCATION_EDIT` | Allocation - Edit | Edit alokasi. |
| EDIT | `WQS.INCOMING_EDIT` | Incoming - Edit | Edit data incoming. |
| EDIT | `WQS.PICKING_EDIT` | Picking - Edit | Edit data picking. |
| EDIT | `WQS.PR_EDIT` | PR - Edit | Edit Purchase Request. |
| EXPORT/IMPORT (map: VIEW atau EDIT) | `WQS.PR_PRINT` | PR - Print | Print PR. |
| OTHER / legacy | `WQS.API_INCOMING_PO` | WQS - API Incoming | API untuk load item PO ke incoming. |
| OTHER / legacy | `WQS.DO_TASKS` | WQS DO Tasks (Sales) | Tugas WQS pada DO. |
| VIEW | `WQS.ALLOCATION_VIEW` | Allocation - Lihat | Lihat alokasi stok. |
| VIEW | `WQS.INCOMING_VIEW` | Incoming - Lihat | Lihat daftar penerimaan barang. |
| VIEW | `WQS.PICKING_VIEW` | Picking - Lihat | Lihat daftar picking. |
| VIEW | `WQS.PR_VIEW` | PR - Lihat | Lihat daftar Purchase Request. |
| VIEW | `WQS.TRANSFER_VIEW` | Transfer - Lihat | Lihat list mutasi stok antar kantor. |
| VIEW | `WQS.VIEW` | WQS - Dashboard | Lihat dashboard & menu WQS. |

