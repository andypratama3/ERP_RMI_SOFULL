# RBAC All Modules V1 — Enterprise Permission Codes

**Versi:** 1.0  
**Target:** ERP_RMI_SOFULL — Full coverage per modul  
**Konsisten dengan:** RBAC_MATRIX_RMI.md, SOP RMI

---

## 1. Roles (Minimum)

| Role | Deskripsi |
|------|-----------|
| **SYS** | Privileged sistem — akses penuh |
| **SYS** (alias) | SYS = ADMIN = SUPERADMIN, tidak ada perbedaan level |
| **ROLE_ITC** | IT/Infrastructure |
| **ROLE_MPR** | MPR / Policy |
| **ROLE_CRM** | CRM / Sales |
| **ROLE_SCM** | SCM / Supply Chain |
| **ROLE_ACT** | ACT / Accounting |
| **ROLE_FIN** | Finance |
| **ROLE_HRL** | HR & Legal |
| **ROLE_WQS** | Warehouse |
| **ROLE_PQP** | PQP / Procurement |
| **ROLE_PQP** | Quality (PQP) |

**Catatan:** Dept + Role mapping di `rbac_dept_role_permissions`. DB sudah bersih — role canonical: sys/manager/staff.

---

## 2. Permission Codes per Modul

### Master

| Code | Deskripsi |
|------|-----------|
| MASTER_READ | Lihat Master Data Center |
| MASTER_WRITE | Create/Edit master data |
| SYSTEM.USER_MANAGE | Master System Login, user mgmt (SYS only) |

### Purchases (PR→PQP→PO→GR→AP)

| Code | Deskripsi |
|------|-----------|
| PURCHASES_READ | Lihat dashboard & transaksi |
| PR_CREATE | Buat Purchase Request |
| PR_SUBMIT | Submit PR |
| PR_APPROVE | Approve PR |
| PQP_CREATE | Buat PQP |
| PQP_APPROVE | Approve PQP |
| PQP_LOCK | Lock PQP |
| PO_CREATE | Buat PO |
| PO_APPROVE | Approve PO |
| PO_ISSUE | Issue PO |
| GR_CREATE | Buat Goods Receipt |
| GR_POST | Post GR |
| GR_VERIFY | Verifikasi GR |
| AP_CREATE | Buat Invoice AP |
| AP_VALIDATE | Validasi AP |
| AP_POST | Post AP |
| AP_PAYMENT_CREATE | Buat pembayaran AP |
| AP_PAYMENT_APPROVE | Approve pembayaran AP |

### Sales

| Code | Deskripsi |
|------|-----------|
| SALES_READ | Lihat dashboard & DO |
| SO_CREATE | Buat Sales Order |
| SO_APPROVE | Approve SO |
| DO_CREATE | Buat DO |
| DO_POST | Post DO |
| AR_CREATE | Buat AR (jika ada) |
| AR_POST | Post AR |
| CRM_LEADS_READ | Lihat leads CRM |
| CRM_LEADS_WRITE | Kelola leads CRM |

### Stock / WQS

| Code | Deskripsi |
|------|-----------|
| STOCK_READ | Lihat stok |
| STOCK_OPNAME_CREATE | Buat opname |
| STOCK_OPNAME_APPLY | Apply opname |
| STOCK_ADJ_CREATE | Buat adjustment |
| STOCK_ADJ_APPROVE | Approve adjustment |
| STOCK_ADJ_POST | Post adjustment |
| STOCK_PICKING | Picking |
| STOCK_ALLOCATION | Alokasi stok |
| STOCK_ALLOW_NEGATIVE | Izinkan stok negatif (default OFF) |

### Finance / Accounting

| Code | Deskripsi |
|------|-----------|
| FIN_READ | Lihat finance |
| GL_READ | Lihat GL |
| GL_POST | Post GL |
| BANK_READ | Lihat bank |
| BANK_RECON_WRITE | Rekonsiliasi bank |

### HRL / Reg Alkes

| Code | Deskripsi |
|------|-----------|
| HRL_READ | Lihat HRL (mirror; kanonik: `HRL.VIEW`) |
| HRL_WRITE | Kelola HRL (mirror) |
| HRL.REG_ALKES_VIEW / EDIT / EXPORT | Reg Alkes (kanonik di RBAC Center) |

### Payroll

| Code | Deskripsi |
|------|-----------|
| PAYROLL_READ | Lihat payroll |
| PAYROLL_PROCESS | Proses payroll |
| PAYROLL_APPROVE | Approve payroll |

### Fixed Asset

| Code | Deskripsi |
|------|-----------|
| FA_READ | Lihat fixed asset |
| FA_WRITE | Kelola fixed asset |
| FA_APPROVE | Approve fixed asset |

### KPI / MPR

| Code | Deskripsi |
|------|-----------|
| KPI_READ | Lihat KPI |
| KPI.VIEW | KPI Center |
| MPR_READ | Lihat MPR |
| MPR_APPROVE_POLICY | Approve policy MPR |

### Chat

| Code | Deskripsi |
|------|-----------|
| CHAT_READ | Baca chat |
| CHAT_SEND | Kirim chat |
| CHAT.ADMIN_SETTINGS | Admin chat settings |
| CHAT.DELETE | Hapus chat (SYS only) |

### Tools (Ops)

| Code | Deskripsi |
|------|-----------|
| TOOLS_READ | Akses Tools |
| TOOLS_BACKUP_RUN | Jalankan backup |
| TOOLS_RESTORE_RUN | Jalankan restore |
| TOOLS_MIGRATE_RUN | Jalankan migrate |
| TOOLS_RELEASE_GATE_RUN | Jalankan release gate |

---

## 3. Mapping Role → Permissions (SOP RMI)

| Dept | Role | Permissions (minimal) |
|------|------|------------------------|
| WQS | MANAGER/STAFF | PR_*, GR_*, STOCK_OPNAME_*, STOCK_ADJ_*, STOCK_PICKING, STOCK_ALLOCATION, PURCHASES_READ, SALES_READ |
| PQP | MANAGER/STAFF | PQP_*, PO_*, GR_VERIFY, PURCHASES_READ |
| SCM | MANAGER/STAFF | Forwarder/logistics, tracking, stock mutation |
| ACT | MANAGER/STAFF | AP_VALIDATE, AP_POST, tax/GL support |
| FIN | MANAGER/STAFF | AP_PAYMENT_*, BANK_*, GL_* |
| HRL | MANAGER/STAFF | REG_ALKES_*, HRL_* |
| ITC | MANAGER/STAFF | TOOLS_READ, TOOLS_ITC_RESET_PASSWORD |
| MPR | MANAGER/STAFF | MPR_READ, MPR_APPROVE_POLICY, PR_APPROVE (optional) |
| CRM | MANAGER/STAFF | CRM_LEADS_*, SO_*, DO_* (read) |
| SYS | SYS | Semua permission |

---

## 4. Guard Rules

- Semua halaman private: `require_login()`
- Semua aksi mutasi: POST + CSRF + `require_permission()`
- Tools ops: admin-only default
- Default deny: jika ragu, BLOCK
