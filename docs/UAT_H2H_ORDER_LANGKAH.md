# UAT H2H Order — Langkah demi Langkah

Panduan untuk testing H2H order_create **sebelum** koordinasi dengan Hermina. Bisa dijalankan sendiri untuk validasi teknis.

---

## Prasyarat

- [ ] Migration 143 sudah dijalankan (`api_partner_order_idempotency`)
- [ ] Ada customer aktif (mis. Hoo1) di master_customers
- [ ] Ada produk aktif (mis. OBT-001, OBT-002) di master_products
- [ ] Pricelist untuk customer (opsional; fallback ke master_products.price)

---

## Langkah 1: Buat API Key

1. Login ke ERP (ADMIN atau SUPERADMIN)
2. **Master Data** → **API Partner Keys**
3. Form "Tambah Partner":
   - **Partner name:** `Hermina UAT` (atau `Hermina H2H`)
   - **Environment:** `Development` (untuk UAT)
   - **Scopes:** `order:create` atau kosongkan
   - **Rate limit:** 1000 (default)
4. Klik **Generate API Key**
5. **Simpan API key** yang muncul — hanya ditampilkan sekali. Contoh: `rpk_a1b2c3d4e5f6...`

---

## Langkah 2: Test Health

```bash
# Ganti BASE_URL dan API_KEY
curl -H "X-API-Key: rpk_xxx" "https://[domain]/ERP_RMI_SOFULL/api/v1/partner/health.php"
```

**Expected:** `{"ok":true,"message":"OK","partner":"Hermina UAT",...}`

---

## Langkah 3: Test Order Create

### Opsi A: Pakai script (paling mudah)

```bash
cd /volume4/web/ERP_RMI_SOFULL
chmod +x tools/qa/h2h_order_test.sh
./tools/qa/h2h_order_test.sh "https://[domain]/ERP_RMI_SOFULL" "rpk_xxx"
```

Script akan:
1. Test health
2. Create 1 order
3. Test idempotency (2x request sama → return DO sama)

### Opsi B: Pakai Postman

1. Import `docs/postman/H2H_Order_Hermina.postman_collection.json` ke Postman
2. Set variable:
   - `base_url` = `https://[domain]/ERP_RMI_SOFULL`
   - `api_key` = API key dari Langkah 1
3. Jalankan request **Order Create (H2H)**

### Opsi C: Pakai curl manual

```bash
curl -X POST "https://[domain]/ERP_RMI_SOFULL/api/v1/partner/order_create.php" \
  -H "X-API-Key: rpk_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "UAT-001",
    "customers_code": "Hoo1",
    "office_code": "BGR",
    "items": [{"sku": "OBT-001", "qty": 1}]
  }'
```

**Expected:** `{"ok":true,"do_id":...,"do_code":"RMI-BGR-...","status":"crm_to_wqs",...}`

---

## Langkah 4: Cek di Sales DO

1. Buka **Sales** → **Sales DO**
2. Klik filter **H2H** (hanya tampilkan order dari API)
3. Pastikan DO baru muncul dengan badge **H2H** (hijau)
4. Cek detail: customer, items, total amount sesuai

---

## Langkah 5: Test Idempotency

Kirim request yang **sama** 2x (idempotency_key sama):

```bash
# Request 1
curl -X POST "..." -H "X-API-Key: rpk_xxx" -H "Content-Type: application/json" \
  -d '{"idempotency_key":"IDEM-001","customers_code":"Hoo1","office_code":"BGR","items":[{"sku":"OBT-001","qty":1}]}' \
  "https://[domain]/ERP_RMI_SOFULL/api/v1/partner/order_create.php"

# Request 2 (sama persis)
curl -X POST "..." -H "X-API-Key: rpk_xxx" -H "Content-Type: application/json" \
  -d '{"idempotency_key":"IDEM-001","customers_code":"Hoo1","office_code":"BGR","items":[{"sku":"OBT-001","qty":1}]}' \
  "https://[domain]/ERP_RMI_SOFULL/api/v1/partner/order_create.php"
```

**Expected:** Keduanya return `do_id` dan `do_code` yang sama. Hanya 1 DO tercreate di database.

---

## Langkah 6: Test Error Case (opsional)

| Test | Payload | Expected |
|------|---------|----------|
| Invalid customer | `customers_code: "INVALID"` | 422, ERR_CUSTOMER |
| Invalid SKU | `items: [{"sku":"XXX", "qty":1}]` | 422, ERR_ITEMS |
| No API key | Tanpa header X-API-Key | 401, ERR_MISSING_API_KEY |
| Invalid API key | Key salah | 401, ERR_INVALID_API_KEY |

---

## Checklist UAT

- [ ] API Key berhasil dibuat
- [ ] Health check return ok
- [ ] Order create return do_code
- [ ] DO muncul di Sales DO dengan badge H2H
- [ ] Filter H2H berfungsi
- [ ] Idempotency: 2x request sama → 1 DO
- [ ] Error case: invalid customer/SKU return 422

---

## Setelah UAT Berhasil

- Simpan API Key Development untuk Hermina (saat koordinasi)
- Buat API Key Production saat go-live
- Berikan dokumentasi: `docs/PERSIAPAN_H2H_PO_HERMINA.md`
