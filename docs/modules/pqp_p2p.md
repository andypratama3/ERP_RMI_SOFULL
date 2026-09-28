# PQP & Pembelian — Procure to Pay (P2P)

> **Panduan lengkap dalam aplikasi (disarankan):** buka **`/purchases/panduan.php`** — alur visual, langkah RFQ/PO/GR/AP, FAQ, sama layout Purchases.

## Ringkasan alur

**PR** (kebutuhan) → **PO** → **GR** → **AP invoice** → **Pembayaran AP**; melibatkan **PQP**, **SCM** (forwarding/import), **WQS**, **FIN**.

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `purchases/purchases_dashboard.php` | PQP Dashboard | PQP |
| `purchases/purchases_import_control_tower.php` | Pipeline import | PQP, SCM |
| `purchases/pqp_rfq.php` | RFQ & perbandingan quotation | PQP |
| `purchases/purchases_po.php` | Purchase Order | PQP, SCM |
| `purchases/purchases_gr.php` | Goods Receipt | WQS |
| `purchases/purchases_invoice_ap.php` | AP Invoice | FIN |
| `purchases/purchases_payment_ap.php` | Pembayaran AP | FIN, ACT |
| `purchases/purchases_forwarding_tasks.php` | Task forwarder | SCM |
| `purchases/purchases_ceisa_pib.php` | CEISA / PIB | **ACT** (utama); PQP, SCM (koordinasi pipeline) |
| `purchases/purchases_reports.php` | Laporan pembelian | PQP, FIN |

## Monitoring

- **Import Control Tower** — kontrol pipeline PO → PIB → GR → AP.
- **Purchases reports** — ekspor untuk analisis biaya.

## Deep-dive

- **RFQ / manufacturer portal**: `docs/UAT_RFQ.md`, `docs/RANCANGAN_MANUFACTURER_RFQ.md`.
- **Import funnel**: `docs/VERIFIKASI_FUNNEL_CHECKLIST.md`, Executive summary dashboard.
