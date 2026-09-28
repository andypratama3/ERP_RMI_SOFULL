# Demo H2H — Meeting Hermina

Script singkat untuk demo integrasi H2H saat meeting dengan pihak Hermina.

---

## 1. Persiapan (sebelum meeting)

- [ ] Pastikan UAT internal sudah OK (health, order_create, idempotency)
- [ ] Siapkan laptop/device yang bisa akses ERP (internal: http://10.10.60.20 atau public jika Cloudflare sudah diatur)
- [ ] API Key Development siap (untuk demo)
- [ ] Buka Sales DO di tab terpisah, filter H2H

---

## 2. Alur Demo (≈5–10 menit)

### A. Konteks (1 menit)

> "Kami sudah siapkan API untuk terima PO langsung dari sistem Hermina. Order yang dikirim via API akan otomatis masuk ke Sales DO kami."

### B. Demo Health Check (1 menit)

```bash
curl -H "X-API-Key: rpk_xxx" "http://10.10.60.20/ERP_RMI_SOFULL/api/v1/partner/health.php"
```

**Tunjukkan:** Response `{"ok":true,"partner":"Hermina Group UAT"}` — API Key valid.

### C. Demo Order Create (2–3 menit)

```bash
curl -X POST "http://10.10.60.20/ERP_RMI_SOFULL/api/v1/partner/order_create.php" \
  -H "X-API-Key: rpk_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "DEMO-HERMINA-001",
    "customers_code": "Hoo1",
    "office_code": "BGR",
    "items": [{"sku": "OBT-001", "qty": 1}]
  }'
```

**Tunjukkan:** Response `do_code`, `do_id`, `status`. Lalu buka Sales DO → filter H2H → DO baru muncul dengan badge H2H.

### D. Demo Idempotency (1–2 menit)

> "Jika Hermina kirim request yang sama 2x (mis. retry), kami tidak buat duplikat."

Kirim request yang sama lagi.

**Tunjukkan:** Response `"Order already created (idempotent)"` dengan `do_code` yang sama.

### E. Format Data (2 menit)

> "Format request yang kami terima: customers_code, office_code, items (sku, qty). Kami punya dokumentasi lengkap."

Tunjukkan `docs/PERSIAPAN_H2H_PO_HERMINA.md` — bagian Spesifikasi API.

---

## 3. Poin untuk disepakati di meeting

| Topik | Pertanyaan |
|-------|------------|
| **Format PO** | Hermina pakai format apa? (JSON, field apa saja?) |
| **Mapping SKU** | Hermina pakai kode produk internal atau SKU RMI? |
| **Idempotency** | Hermina punya nomor PO unik? → pakai sebagai idempotency_key |
| **Environment** | Kapan go-live? Staging dulu atau langsung production? |
| **IP whitelist** | IP Hermina untuk akses API (jika pakai Cloudflare) |

---

## 4. Handout untuk Hermina (setelah meeting)

- `docs/PERSIAPAN_H2H_PO_HERMINA.md` — spesifikasi lengkap
- `docs/postman/H2H_Order_Hermina.postman_collection.json` — untuk testing
- API Key Production (setelah dibuat)

---

## 5. Checklist pasca-meeting

- [ ] Buat API Key Production untuk Hermina
- [ ] Atur Cloudflare: skip challenge untuk `/api/v1/partner/*` atau whitelist IP Hermina
- [ ] Berikan dokumentasi + API Key ke Hermina
- [ ] UAT bersama Hermina (jika mereka punya environment)
- [ ] Go-live
