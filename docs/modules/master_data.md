# Master Data & Office

## Ringkasan alur

**Master Data Center** mengumpulkan entitas referensi (produk, customer, vendor, kantor, pajak, dsb.) yang dipakai lintas modul **CRM, WQS, PQP, FIN, HRL**.

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `master/master_data.php` | Pusat kartu ke semua master | Semua (sesuai RBAC) |
| `master/master_products.php` | Produk & paket | PQP |
| `master/master_customers.php` | Customer & cover area | CRM |
| `master/master_vendors.php` | Vendor logistik/jasa | SCM |
| `master/master_office.php` | Kantor / depo / cabang | SYS, ITC |
| `master/master_system_config.php` | Config read-only privileged | SYS |
| `master/master_pricelist.php` | Pricelist buy→sell (terbatas) | FIN, PQP, SYS |
| `master/api_partner_keys.php` | API key partner | SYS |

## Monitoring

- Perubahan master penting tercatat di **Audit Log** (modul `master_*` / terkait).
- **Enterprise Audit** (tools) — scan statis keamanan.

## Deep-dive

- Office code valid (tanpa HO): lihat rule project **office-codes**.
- Customer / manufacturer portal: modul terpisah di dokumentasi portal.
