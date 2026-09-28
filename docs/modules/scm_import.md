# SCM — Import & Logistik

> **Panduan Import Control Tower (dalam ERP):** **`/purchases/panduan_import_tower.php`**

## Ringkasan alur

**SCM** mengatur **pengiriman DO**, **forwarding/import**, **vendor logistik**, dan koordinasi dengan **WQS** / **PQP** / **FIN**.

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `sales/scm_do_tasks.php` | Task pengiriman DO | SCM |
| `purchases/purchases_forwarding_tasks.php` | Quote, invoice forwarder, tracking | SCM |
| `purchases/purchases_import_control_tower.php` | Monitoring import (lintas modul) | SCM, PQP |
| `purchases/purchases_ceisa_pib.php` | Data PIB / CEISA | **ACT** (utama); PQP, SCM (koordinasi) |
| `master/master_vendors.php` | Master vendor logistik/jasa | SCM, Master |

## Monitoring

- **Import Control Tower** — satu layar untuk status import.
- **Sales Control Tower** — status DO tahap SCM.

## Deep-dive

- Vendor qualification / SOP terkait ada di **Office Pack** (Help Center) bila tersedia.
- Dokumen impor & compliance mengikuti kebijakan RA/PQP perusahaan.
