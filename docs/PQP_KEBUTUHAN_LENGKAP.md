# PQP — Kebutuhan Lengkap untuk Dashboard & Operasional

**Tujuan:** Dokumen ini merinci semua kebutuhan PQP (Procurement Quality / Purchasing) di ERP_RMI_SOFULL agar PQP Dashboard dan modul terkait jelas dan lengkap.

---

## 1. Ringkasan Alur PQP (P2P)

```
WQS PR (Purchase Request) → PQP RFQ (opsional) → PQP PO → SCM Forwarding → WQS GR → FIN AP Invoice → FIN AP Payment
```

---

## 2. Master Data yang Dibutuhkan PQP

### 2.1 PQP — Product & Manufactures (Owner: PQP)

| Master | File | Tabel | Keterangan |
|--------|------|-------|------------|
| **Master Manufactures (Pabrik)** | `master/master_manufactures.php` | `master_manufactures` | Data pabrikan/OEM. **Wajib** untuk RFQ, PO, AP, Import Control. Kolom penting: manufacture_code, manufacture_name, bank_name, bank_account_name, bank_account_number, bank_swift_code, bank_iban, bank_currency, email (untuk RFQ notifikasi). |
| **Master Products** | `master/master_products.php` | `master_products` | Produk single & paket. Dipakai di PO items, PR items, RFQ (product_sku). |
| **Manufacturer Portal Users** | `master/master_manufacturer_portal_users.php` | `manufacturer_portal_users` | User login portal pabrikan. Untuk RFQ: manufacturer submit quotation via portal. Link ke manufacture_id. |

### 2.2 SCM — Vendor (Logistik/Jasa)

| Master | File | Tabel | Keterangan |
|--------|------|-------|------------|
| **Master Vendors** | `master/master_vendors.php` | `master_vendors` | Vendor forwarder/logistik. Dipakai di Forwarding Tasks, Forwarder Quotes, Forwarder Invoice, Forwarder Payment. |

### 2.3 FIN — Pricing, Tax, Payment

| Master | File | Tabel | Keterangan |
|--------|------|-------|------------|
| **Master Payment Terms** | `master/master_payment_terms.php` | `master_payment_terms` | Term pembayaran (CBD, COD, TOP 7/14/30/45/60). Dipakai di PO. |
| **Master Pricelist (Buy)** | `master/master_pricelist.php` | `master_pricelist` | Harga beli + markup → sell. Hanya Admin/PQP/FIN. |
| **Master Tax Profile** | `master/master_tax.php` | `master_tax` | Profil pajak per kantor. |
| **Company Bank Accounts** | (rekening perusahaan) | `company_bank_accounts` | Rekening untuk pembayaran AP (opsional, saat ini bank info supplier dari master_manufactures). |

### 2.4 SYSTEM — Office & Config

| Master | File | Tabel | Keterangan |
|--------|------|-------|------------|
| **Master Office** | `master/master_office.php` | `master_office` | Kantor/depo. Dipakai di PO, AP, Import Control, PR. Default: BGR (bukan HO). |
| **Master Email Company** | `master/master_emailcompany.php` | `master_emailcompany` | Email resmi kantor. |
| **System Config** | `master/master_system_config.php` | `system_config` | Config RFQ: `RFQ.PQP_EMAIL`, `RFQ_CURRENCY` (rate_CNY, rate_IDR, rate_EUR). Pola penomoran PO, AP. |

---

## 3. Tabel Transaksi & Flow

### 3.1 RFQ (Request for Quotation)

| Tabel | Migrasi | Keterangan |
|-------|---------|------------|
| `pqp_rfq` | 151 | Header RFQ: rfq_code, title, product_description, product_sku, quantity, unit, deadline, status (draft/open/closed/cancelled). |
| `pqp_rfq_quotations` | 151 | Quotation dari manufacturer: rfq_id, manufacture_id, unit_price, currency, lead_time_days, payment_terms, file_rel, status (submitted/withdrawn). |
| `pqp_rfq_comments` | 152 | Chat/komentar per RFQ (PQP & manufacturer). |

### 3.2 PR → PO → AP

| Tabel | Migrasi | Keterangan |
|-------|---------|------------|
| `wqs_pr` | 64 | Purchase Request dari WQS. Status: SUBMITTED → PO_CREATED. |
| `wqs_pr_items` | 65 | Item PR. |
| `purchases_po` | 54 | Purchase Order. pr_id, manufacture_id, office_code, currency, payment_term, forwarder_vendor_id. |
| `purchases_po_items` | 55 | Item PO: product_id, sku, products_name, qty, unit, unit_price, subtotal. |
| `purchases_invoice_ap` | 52 | AP Invoice (PROFORMA, FINAL, PIB, OTHER). manufacture_id, office_code, po_id, total_amount, status (UNPAID/PARTIAL/PAID). |
| `purchases_invoice_ap_lines` | 52 | Line AP (opsional). |
| `purchases_payment_ap` | 53 | Pembayaran AP: pay_code, ap_id, pay_date, amount, method, bank_name, reference, doc_path. |

### 3.3 Import & Forwarding (SCM)

| Tabel | Migrasi | Keterangan |
|-------|---------|------------|
| `purchases_import_control` | 51 | Milestone import per PO: production_start_date, etd, eta, arrived_warehouse_date. |
| `purchases_forwarding_docs` | 50 | Dokumen import per PO: CIPL, BL_FINAL, FORM_E, BC11, NOA, SPPB, dll. |
| `purchases_ceisa_pib` | 46 | Data PIB/CEISA. |
| `purchases_ceisa_payment` | 45 | Pembayaran CEISA. |
| `purchases_forwarder_quotes` | 49 | Quote forwarder per PO. |
| `purchases_forwarder_invoice` | 47 | Invoice forwarder. |
| `purchases_forwarder_payment` | 48 | Pembayaran forwarder. |

### 3.4 WQS (Gudang)

| Tabel | Migrasi | Keterangan |
|-------|---------|------------|
| `wqs_incoming` | 102 | Incoming barang (PO/GR). po_code, received_date. |
| `purchases_gr` | (implied) | Goods Receipt. |

---

## 4. Modul / Halaman PQP

### 4.1 PQP Utama

| Modul | File | URL | Keterangan |
|-------|------|-----|------------|
| **PQP Dashboard** | `purchases/purchases_dashboard.php` | `/purchases/purchases_dashboard.php` | Ringkasan KPI + shortcut RFQ, PO. |
| **RFQ** | `purchases/pqp_rfq.php` | `/purchases/pqp_rfq.php` | Buat RFQ, bandingkan quotation, export CSV/Excel. |
| **PO** | `purchases/purchases_po.php` | `/purchases/purchases_po.php` | Buat & kelola PO dari PR. |

**Catatan:** AP Invoice & AP Payment adalah modul **FIN**. PQP tidak punya flow di AP Payment. Akses via sidebar jika ada permission.

### 4.2 Alur Bersama (PQP + SCM + WQS + FIN)

| Modul | File | Owner | Keterangan |
|-------|------|-------|------------|
| **Import Control Tower** | `purchases/purchases_import_control_tower.php` | PQP/SCM | Monitor pipeline import (PO → PIB/CEISA → GR → AP). |
| **Forwarding Tasks** | `purchases/purchases_forwarding_tasks.php` | SCM | Task forwarding: quotes, invoice forwarder, dokumen. |
| **Forwarder Quotes** | `purchases/purchases_forwarder_quotes.php` | SCM | Quote forwarder per PO. |
| **Forwarder Invoice** | `purchases/purchases_forwarder_invoice.php` | SCM | Invoice forwarder. |
| **CEISA/PIB** | `purchases/purchases_ceisa_pib.php` | **ACT** (utama); PQP/SCM | Kelola PIB/CEISA; pemilik proses bea cukai/kepatuhan = **ACT**. |
| **WQS PR** | `stock/wqs_pr.php` | WQS | Buat PR (sumber PO). |
| **WQS Incoming** | `stock/wqs_incoming.php` | WQS | Proses incoming barang. |
| **WQS GR** | `purchases/purchases_gr.php` | WQS | Goods Receipt. |

### 4.3 Laporan & Utility

| Modul | File | Keterangan |
|-------|------|------------|
| **Purchases Reports** | `purchases/purchases_reports.php` | Laporan PR/PO/GR/AP. |
| **RFQ Export** | `purchases/pqp_rfq_export.php` | Export RFQ ke CSV/Excel. |
| **RFQ Download** | `purchases/pqp_rfq_download.php` | Download attachment quotation. |

---

## 5. PQP Dashboard — KPI & Layout

### 5.1 KPI Snapshot (Saat Ini)

| KPI | Sumber | Keterangan |
|-----|--------|------------|
| **RFQ Open** | `pqp_rfq` status='open' | Menunggu quotation. |
| **RFQ Pending** | `pqp_rfq` open + belum ada quotation submitted | Belum ada quotation. |
| **PR Submitted** | `wqs_pr` status='SUBMITTED' | Menunggu dibuat PO. |
| **PO Open** | `purchases_po` status IN (OPEN, IN_PRODUCTION, READY) | PO aktif. |
| **PO Value (This Month)** | `purchases_po` total_amount, po_date bulan ini | Total nilai PO bulan ini (hanya jika can_view_buy_price). |
| **AP Outstanding** | `purchases_invoice_ap` status IN (UNPAID, PARTIAL) | Count + sum outstanding (total - paid). |

**Sumber:** `purchases_invoice_ap` (status UNPAID/PARTIAL) + `purchases_payment_ap` (paid_amount). Outstanding = total_amount - SUM(paid). Konsisten dengan `ap_rekap.php` / `ar_ap_cash_dashboard.php`.

### 5.2 Layout PQP Dashboard (Landing Page)

- **PQP Utama:** RFQ, PO — alur inti PQP.
- **Laporan:** Purchases Reports.
- **Sidebar PQP:** Dashboard, RFQ, PO (tanpa AP).
- **AP Invoice & AP Payment:** Di section FIN sidebar (modul FIN).

### 5.3 Saran UX (Belum Diimplementasi)

- KPI clickable → navigasi ke list + filter.
- Badge count di cards RFQ & PO.
- Collapse "Catatan UI".
- Manager section kondisional (sembunyikan untuk staff).

---

## 6. Config & Environment

| Config | Sumber | Keterangan |
|-------|--------|------------|
| **RFQ.PQP_EMAIL** | `system_config` | Email PQP untuk notifikasi quotation submitted. |
| **RFQ_CURRENCY** (rate_CNY, rate_IDR, rate_EUR) | `system_config` | Rate ke USD untuk perbandingan RFQ. |
| **APP_URL / TOOLS_BASE_URL_INTERNAL** | `.env` | Base URL untuk link notifikasi. |

---

## 7. RBAC & Permission

| Dept/Role | Scope PQP (setelah verifikasi & perbaikan) |
|-----------|-------------------------------------------|
| **SYS / ADMIN / SUPERADMIN** | Akses penuh. |
| **PQP** | Dashboard, RFQ, PO, AP, Import Control Tower, Forwarding Tasks, Forwarder Quotes/Invoice/Payment, GR, Master Manufactures. *(PIB/CEISA diurus **ACT**; PQP koordinasi.)* |
| **SCM** | Forwarding (owner), Import Tower (monitoring); koordinasi import. *(Bukan owner PIB — **ACT**.)* |
| **ACT** | **PIB/CEISA** (utama), AP Invoice/Payment & forwarder sesuai RBAC, GR jika diberi akses. |
| **FIN** | AP Invoice, AP Payment, Forwarder Invoice/Payment, GR (sesuai delegasi). |
| **WQS** | PR, Incoming, GR, Forwarding Tasks; CEISA/PIB hanya jika ada permission lintas dept. |
| **BRANCH / MANAGER / STAFF** | Sesuai delegasi (lihat `docs/PQP_RBAC_VERIFIKASI_AKSES.md`). |

**Permission:** `PURCHASES.PO_CRUD`, `PURCHASES.VIEW`, `PURCHASES.AP_INVOICE_CRUD`, `PURCHASES.AP_PAYMENT_CRUD`, `PURCHASES.FORWARDING_CRUD`, `PURCHASES.CEISA_PIB`, `PURCHASES.GR_PROCESS`, `DASHBOARD.PROCUREMENT_VIEW`, `PQP.VIEW`, `MASTER.MANUFACTURE_CRUD`, `MASTER.PRICELIST_BUY_CRUD`.

---

## 8. Migrasi Penting untuk PQP

| No | File | Keterangan |
|----|------|------------|
| 51 | `051_purchases_import_control.sql` | purchases_import_control |
| 52 | `052_purchases_invoice_ap.sql` | purchases_invoice_ap, purchases_invoice_ap_lines |
| 53 | `053_purchases_payment_ap.sql` | purchases_payment_ap |
| 54 | `054_purchases_po.sql` | purchases_po |
| 55 | `055_purchases_po_items.sql` | purchases_po_items |
| 64 | `064_wqs_pr.sql` | wqs_pr |
| 65 | `065_wqs_pr_items.sql` | wqs_pr_items |
| 140 | `140_manufacturer_portal_users.sql` | manufacturer_portal_users |
| 151 | `151_pqp_rfq_quotations.sql` | pqp_rfq, pqp_rfq_quotations |
| 152 | `152_pqp_rfq_extras.sql` | pqp_rfq_comments |
| 153 | `153_rfq_config_seed.sql` | system_config RFQ |

---

## 9. Checklist Readiness PQP Dashboard

- [ ] Master Manufactures lengkap (minimal 1 pabrik aktif).
- [ ] Master Products ada data.
- [ ] Master Office ada (BGR default).
- [ ] Master Payment Terms ada.
- [ ] Migration 151, 152, 153 dijalankan (RFQ).
- [ ] Migration 140 dijalankan (Manufacturer Portal Users, untuk RFQ).
- [ ] System Config: RFQ.PQP_EMAIL, RFQ_CURRENCY (opsional).
- [ ] User PQP/FIN/SCM/WQS terdaftar dengan role & department.

---

## 10. Referensi File

| Kategori | Path |
|----------|------|
| Dashboard | `purchases/purchases_dashboard.php` |
| RFQ | `purchases/pqp_rfq.php`, `purchases/pqp_rfq_helper.php` |
| PO | `purchases/purchases_po.php`, `purchases/purchases_po_view.php` |
| AP | `purchases/purchases_invoice_ap.php`, `purchases/purchases_payment_ap.php` |
| Lib | `purchases/_purchases_lib.php`, `purchases/_purchases_bootstrap.php` |
| Layout | `_shared/rmi_layout.php` (sidebar PQP) |
| Master Data | `master/master_data.php` (mod_card PQP) |
| Rancangan RFQ | `docs/RANCANGAN_MANUFACTURER_RFQ.md` |
