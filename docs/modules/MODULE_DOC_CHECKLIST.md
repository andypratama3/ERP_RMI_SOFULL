# Checklist dokumen per modul (1 halaman)

**Tujuan:** lembar kerja cepat *modul X — sudah ada apa / kurang apa* sebelum merapikan portal dokumen.  
**Update:** isi kolom status & catatan saat review; tidak perlu commit jika hanya untuk rapat internal.

**Legenda status (singkat):** `✓` = ada & dipakai · `~` = ada tapi perlu update · `—` = belum ada · `n/a` = tidak relevan

| Modul (`registry.json`) | Ringkasan | `*.md` di `docs/modules/` | `panduan_in_app` (HTML di ERP) | Diagram OfficePack (acuan) | SOP / form terkait (cek OfficePack) | Yang kurang / next step |
|-------------------------|-----------|---------------------------|--------------------------------|----------------------------|--------------------------------------|-------------------------|
| **crm_o2c** — CRM & Sales (O2C) | DO, tower, FIN task | `crm_o2c.md` | — | `DGM_O2C_DO_Lifecycle.svg` | cek playbook CRM / sosialisasi | |
| **wqs** — Gudang & Stok | Stok, GR, picking, opname | `wqs.md` | — | `DGM_WQS_Warehouse.svg` | Manual WQS, form GRN alkes | |
| **pqp_p2p** — PQP & Pembelian | PR→PO→AP | `pqp_p2p.md` | `/purchases/panduan.php` | `DGM_P2P_Import.svg` | Manual PQP, checklist import | |
| **scm_import** — SCM Import | Forwarding, PIB | `scm_import.md` | `/purchases/panduan_import_tower.php` | `DGM_P2P_Import.svg` | checklist penerimaan import | |
| **fin** — Finance | Piutang, AP, dashboard | `fin.md` | — | (dashboard finance) | Manual FIN, policy harga/diskon | |
| **hrl** — HRL, absensi, payroll | People, tower | `hrl.md` | — | `DGM_Payroll.svg`, `DGM_HRL_Process.svg` | HRL manual, form cuti, PP HRL | |
| **mpr_reg** — MPR & Reg Alkes | MPR, reg tower | `mpr_reg.md` | — | `DGM_Reg_Alkes.svg` | checklist pre-reg | |
| **kpi_analytics** — KPI | KPI center, SYS | `kpi_analytics.md` | — | — | training plan | |
| **master_data** — Master & office | MDC, produk | `master_data.md` | — | `DGM_Master_Data.svg` | form master data change | |
| **sys_itc** — SYS / ITC | RBAC, tools, MFA | `sys_itc.md` | — | `DGM_Access_Decision_RBAC.svg` | SOP MFA, form akses ERP | |
| **fixed_asset** — Aset tetap | FA module | `fixed_asset.md` | — | `DGM_FixedAsset.svg` | — | |
| **branch** — Cabang | Branch dashboard | `branch.md` | — | Branch playbooks (per `office_code`) | playbook cabang | |

---

## Di luar `registry.json` (tambahkan baris jika perlu)

| Area | `*.md` / hub | Panduan in-app | Catatan |
|------|----------------|-----------------|---------|
| **Absensi** | (bisa merge ke `hrl.md` atau file baru) | cek `absensi/`, `hrl_process/` | |
| **Chat** | — | — | CHAT manual di OfficePack `dept_training/` |
| **RBAC Center** | isi di `sys_itc.md` | `rbac/panduan.php` | |
| **Customer / Manufacturer portal** | — | cek `customer_portal/`, `manufacturer_portal/` | |
| **Dashboards** | — | per `dashboards/*` | |

---

## Cek cepat isi Markdown (opsional)

Untuk setiap `*.md`: ada **alur 1 paragraf** + **tabel halaman utama (path)** + **monitoring/audit** bila relevan? (`docs/modules/README.md`)

---

## Setelah checklist terisi

1. Prioritas isi yang **—** atau **~**.  
2. Sinkronkan **Help Center** / **manifest Office Pack** dengan file yang benar-benar ada di disk.  
3. Baru satukan navigasi **portal dokumen** (satu pintu utama + katalog).

**Link terkait:** `docs/modules_hub.php` · `docs/help_center.php` · `docs/link/officepack_portal.php` · `docs/internal/README.md`
