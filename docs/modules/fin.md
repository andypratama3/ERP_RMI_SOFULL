# FIN — Piutang, AP & Kas (terkait ERP)

## Ringkasan alur

**FIN** menangani **invoice/piutang** dari alur DO, **AP** dari pembelian, dan dashboard keuangan terintegrasi (sesuai deployment).

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `sales/fin_do_tasks.php` | Task DO — pembayaran / AR | FIN |
| `purchases/purchases_invoice_ap.php` | AP Invoice supplier | FIN |
| `purchases/purchases_payment_ap.php` | Pembayaran AP | FIN, ACT |
| `dashboards/finance/ar_ap_cash_dashboard.php` | Dashboard AR/AP/kas (jika dipakai) | FIN |
| `master/company_bank_accounts.php` | Rekening perusahaan | FIN, SYS |

## Monitoring

- **Sales Control Tower** — DO yang menunggu pembayaran.
- **PQP dashboard / reports** — komitmen AP.

## Deep-dive

- Skema & guard finance: `docs/governance/FINANCE_SCHEMA_GUARD.md` (bila relevan).
- Pajak & terms: master **Tax**, **Payment Terms** di Master Data.
