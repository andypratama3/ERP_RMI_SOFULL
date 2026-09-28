# Setup Integrasi Hermina — Checklist

> Persiapan data & verifikasi sebelum integrasi H2H dengan Hermina.

## 1. Data Kantor Internal (dari master_office)

**Sumber:** `master_office` — Customer Internal dibuat otomatis per kantor.

### Jalankan seed (pilih salah satu):

```bash
# Via CLI (di NAS)
cd /volume4/web/ERP_RMI_SOFULL
php tools/setup/seed_pembelian_antar_kantor.php
```

**Atau via web:** Tools → Setup → "Seed Pembelian Antar Kantor" → Run Setup

**Atau migration SQL:**
```bash
mysql -u root -p erp_rmi_sofull < sql/migrations/133_seed_pembelian_antar_kantor.sql
```

**Hasil:** Customer BGR-INT, BDG-INT, BKS-INT, dll. (sesuai kantor di master_office)

---

## 2. Data RS Hermina (dari Excel template)

**File template:** `Master_Customers_RSHermina_Template.xlsx`

### Langkah:

1. **Konversi Excel → CSV:**
   ```bash
   python3 tools/import/hermina_excel_to_csv.py "path/to/Master_Customers_RSHermina_Template.xlsx" -o hermina_customers.csv
   ```

2. **Import CSV:**
   - Buka **Master Data** → **Import Customers**
   - Upload `hermina_customers.csv`
   - Preview → **Import Sekarang**

**Hasil:** Customer RS Hermina dengan kode unik per cabang (H002-YOGYA, H002-WONOGIRI, dll.)

---

## 3. Verifikasi

| Cek | Cara |
|-----|------|
| Kantor Internal | Master Customers → filter segment "Kantor" → pastikan BGR-INT, BDG-INT, dll. ada |
| RS Hermina | Master Customers → filter segment "Hermina" → pastikan cabang Hermina ada |
| Konsistensi | Edit customer Kantor → segment "Kantor", category "Internal" (dari master_office) |
| API H2H | `curl -X POST .../api/v1/partner/order_create.php` dengan customers_code valid |

---

## 4. API H2H (untuk Hermina)

- **Endpoint:** `POST /api/v1/partner/order_create.php`
- **Auth:** X-API-Key atau Authorization: Bearer
- **Payload:** customers_code, office_code, items, dll.
- **Dokumentasi:** `docs/postman/H2H_Order_Hermina.postman_collection.json`

**Pastikan:** customers_code yang dikirim Hermina ada di master_customers dan status=active.

---

## Ringkasan

| Data | Sumber | Cara |
|------|--------|------|
| Kantor Internal | master_office | Seed script / migration 133 |
| RS Hermina | Excel Hermina | Python convert → Import CSV |
