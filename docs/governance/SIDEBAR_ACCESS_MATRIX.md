# Sidebar Access Matrix — ERP RMI

> Dokumen ini adalah **single source of truth** untuk pengaturan akses sidebar per departemen.
> Implementasi: `_shared/nav_config.php` + `_shared/rmi_layout.php` (server-side filtered).

---

## Mekanisme Keamanan

| Lapisan | File | Keterangan |
|---------|------|------------|
| **Sidebar server-side** | `rmi_layout.php` | Item sidebar di-filter PHP sebelum HTML dikirim ke browser. User tidak bisa "lihat" item yang diblokir. |
| **Auto branch-variant** | `rmi_layout.php` | Dept `BRANCH` selalu mendapat sidebar branch terlepas halaman mana yang dibuka. |
| **Page-level guard** | Tiap halaman | `require_any_permission()` / `rmi_block_branch()` — bahkan jika URL diketik manual. |
| **RBAC** | `tools/rbac_center.php` | Permission granular per dept/role via database. |

---

## Matrix Sidebar per Departemen

### 🏢 BRANCH
**Landing page:** `dashboards/branch/branch_dashboard.php`
**Sidebar variant:** `branch` (auto-forced untuk semua dept=BRANCH)

| Sidebar Item | Tujuan |
|---|---|
| 🏠 Branch Dashboard | Home |
| ⏱️ Absensi | Check-in/out harian |
| 💬 Internal Chat | Komunikasi internal |
| ❓ Help Center | Panduan |
| 📋 Delivery Order | Lihat DO kantor sendiri |
| 🗼 Control Tower | Pantau status DO |
| 🔄 Picking DO | Tugas utama harian |
| 📋 WQS – Task DO | Task dari CRM |
| 🚚 SCM – Task DO | Task dari SCM |
| 📊 Lihat Stok | Stok kantor sendiri (scoped) |
| 📝 Purchase Request | Ajukan PR |
| 📥 Incoming Barang | Penerimaan barang |
| 📄 Purchase Order (PO) | Pantau PO kantor sendiri |
| ✅ Good Receipt (GR) | Konfirmasi penerimaan |
| 🔁 HRL Process | Ajukan cuti/izin/lembur |

**TIDAK TAMPIL:** FIN, ACT, HRL Docs, MPR, Master Data, RBAC, Tools, Payroll, Fixed Asset, Reg Alkes, Tax Invoice, Bank Rekon.

---

### 💼 CRM
**Landing page:** `sales/sales_dashboard.php`

| Sidebar Item | Section |
|---|---|
| 📊 Dashboard Center | MAIN |
| ⏱️ Absensi | MAIN |
| 📈 KPI Center | MAIN |
| 💬 Internal Chat | MAIN |
| 🧾 Sales Dashboard | CRM |
| 🗼 Control Tower | CRM |
| 🎯 CRM Leads | CRM |
| 📋 Delivery Order | CRM |
| ⚡ Task DO (WQS) | CRM |
| 🔄 Picking DO | CRM |
| 🔁 HRL Process | HRL PROCESS |

---

### 🚢 SCM
**Landing page:** `dashboards/scm/scm_dashboard.php`

| Sidebar Item | Section |
|---|---|
| 📊 Dashboard Center | MAIN |
| ⏱️ Absensi | MAIN |
| 📈 KPI Center | MAIN |
| 🚢 SCM Dashboard | SCM |
| 🗼 Import Control Tower | SCM |
| 📦 Forwarding Tasks | SCM |
| 🚚 SCM Task DO | SCM |
| 📄 Purchase Order (PO) | SCM |
| ✅ Good Receipt (GR) | SCM |
| 📝 Purchase Request | SCM |
| 📦 WQS Dashboard | WQS |
| 📥 Incoming | WQS |
| 📊 Lihat Stok | WQS |
| 🔁 HRL Process | HRL PROCESS |

---

### 📦 WQS
**Landing page:** `dashboards/warehouse/wqs_dashboard.php`

| Sidebar Item | Section |
|---|---|
| 📊 Dashboard Center | MAIN |
| ⏱️ Absensi | MAIN |
| 📈 KPI Center | MAIN |
| 🔄 Picking DO | WQS |
| 📥 Incoming | WQS |
| 📊 Lihat Stok | WQS |
| 🗂️ Allocation | WQS |
| 📋 WQS Task DO | WQS |
| 🔢 Stock Opname | WQS |
| 📝 Purchase Request | WQS |
| 🚚 SCM Task DO | SCM |
| 🔁 HRL Process | HRL PROCESS |

---

### 🛒 PQP
**Landing page:** `purchases/purchases_dashboard.php`

| Sidebar Item | Section |
|---|---|
| 📊 Dashboard Center | MAIN |
| ⏱️ Absensi | MAIN |
| 📈 KPI Center | MAIN |
| 🛒 PQP Dashboard | PQP |
| 📋 RFQ | PQP |
| 📄 Purchase Order (PO) | PQP |
| ✅ Good Receipt (GR) | PQP |
| 🗼 Import Control Tower | PQP |
| ✈️ Forwarding Tasks | PQP |
| 🧪 Reg Alkes | PQP |
| 🔁 HRL Process | HRL PROCESS |

---

### 💰 FIN
**Landing page:** `dashboards/finance/ar_ap_cash_dashboard.php`
*(FIN Manager: akses penuh. FIN Staff: view-only)*

| Sidebar Item | Section |
|---|---|
| 📊 Dashboard Center | MAIN |
| ⏱️ Absensi | MAIN |
| 📈 KPI Center | MAIN |
| 💰 Finance Dashboard | FIN |
| ⚡ FIN Task DO | FIN |
| 🧾 AP Invoice | FIN |
| 💸 AP Payment | FIN |
| 🧾 Tax Invoice | FIN |
| 🏦 Bank Rekonsiliasi | FIN |
| 🏧 Rekening Perusahaan | FIN |
| 💰 Payroll | FIN |
| 🧩 MPR (Budget Approval) | FIN |
| 🔁 HRL Process | HRL PROCESS |

---

### 📝 ACT
**Landing page:** `dashboards/act/act_dashboard.php`

| Sidebar Item | Section |
|---|---|
| 📊 Dashboard Center | MAIN |
| ⏱️ Absensi | MAIN |
| 📈 KPI Center | MAIN |
| 📝 ACT Dashboard | ACT |
| ⚡ ACT Task DO | ACT |
| 🧾 AP Invoice | ACT |
| 💸 AP Payment | ACT |
| 🧾 Tax Invoice | ACT |
| 🏦 Bank Rekonsiliasi | ACT |
| 🏗️ Fixed Asset | ACT |
| 📊 Master Tax | ACT |
| 🔁 HRL Process | HRL PROCESS |

---

### 👥 HRL
**Landing page:** `dashboards/hrl/hrl_dashboard.php`

| Sidebar Item | Section |
|---|---|
| 📊 Dashboard Center | MAIN |
| ⏱️ Absensi | MAIN |
| 📈 KPI Center | MAIN |
| 👥 HRL Dashboard | HRL |
| 📚 HRL Docs | HRL |
| 🧪 Reg Alkes | HRL |
| ⏱️ Absensi (Admin) | HRL |
| 📋 Rekap Absensi | HRL |
| 💰 Payroll | HRL |
| 👤 Master Karyawan | HRL |
| 🔁 HRL Process | HRL PROCESS |

---

### 💻 ITC
**Landing page:** `dashboards/itc/itc_dashboard.php`

| Sidebar Item | Section |
|---|---|
| 📊 Dashboard Center | MAIN |
| ⏱️ Absensi | MAIN |
| 📈 KPI Center | MAIN |
| 💻 ITC Dashboard | ITC |
| 👥 Manajemen User | ITC |
| 🔐 RBAC | ITC |
| 🗂️ Nav Manager | ITC |
| 🛠️ Tools | ITC |
| ⚙️ System Config | ITC |
| 🏠 Master Data | SYSTEM |
| 🧭 Executive Summary | SYSTEM |
| 🔁 HRL Process | HRL PROCESS |

---

### 🧩 MPR
**Landing page:** `mpr/mpr_dashboard.php`

| Sidebar Item | Section |
|---|---|
| 📊 Dashboard Center | MAIN |
| ⏱️ Absensi | MAIN |
| 📈 KPI Center | MAIN |
| 🧩 MPR Dashboard | MPR |
| 🔁 HRL Process | HRL PROCESS |

---

### 👑 SYS
**Landing page:** `dashboards/index.php` (Dashboard Center)

Melihat **SEMUA** item dari semua departemen. Bypass semua role check.
Filter sidebar menggunakan dropdown "Menu view" di sidebar untuk preview tampilan per dept.

---

## Aturan yang TIDAK BOLEH Dilanggar

1. **BRANCH tidak boleh lihat:** FIN, ACT, HRL Docs, MPR, Master Data, RBAC, Tools, Payroll, Fixed Asset, Tax Invoice, Bank Rekon, Reg Alkes (strategis).
2. **FIN/ACT bersifat sensitif:** FIN data cross-office. Jangan tambahkan BRANCH ke section FIN/ACT.
3. **ITC hanya untuk sistem:** Jangan tambahkan dept lain ke RBAC/Tools/Master Data/Nav Manager.
4. **HRL Process lintas dept:** Semua dept operasional bisa mengajukan proses HR. Ini disengaja.
5. **Server-side filtering adalah satu-satunya yang berlaku.** `data-roles` di HTML adalah metadata UI saja.

---

## Cara Update

Edit **`_shared/nav_config.php`** langsung.
Jangan edit `rmi_layout.php` kecuali untuk perubahan mekanisme filtering.

Atau gunakan **Nav Manager** (`master/nav_manager.php`) untuk override roles via UI tanpa edit kode.

---

*Last updated: 2026-03-11 | Maintained by ITC / Admin*
