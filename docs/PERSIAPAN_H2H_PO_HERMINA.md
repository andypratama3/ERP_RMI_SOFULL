# Persiapan Host-to-Host (H2H) PO — Hermina Group

**Skenario:** Hermina Group mengajukan integrasi sistem ke sistem: PO dari sistem Hermina dikirim langsung ke ERP RMI, tanpa input manual.

---

## 1. Ringkasan Alur H2H

```
[Sistem Hermina]  →  POST /api/v1/partner/order_create  →  [ERP RMI]  →  Create DO
     (PO)                    (API Key auth)                    (sales_do)
```

- **Hermina:** Sistem purchasing (HIS/AFYA/custom) kirim order via API
- **RMI:** ERP terima → validasi → create Delivery Order (DO) otomatis
- **Output:** DO code, status; Staff CRM bisa review jika perlu

---

## 2. Yang Sudah Ada di ERP RMI

| Komponen | Status | Keterangan |
|----------|--------|------------|
| **API Partner Keys** | ✅ Ada | Master Data → API Partner Keys; auth X-API-Key / Bearer |
| **Partner Auth** | ✅ Ada | `api/_lib/partner_auth.php`; validasi key, scopes, environment |
| **Health endpoint** | ✅ Ada | `GET /api/v1/partner/health.php` — test koneksi |
| **Customer Portal** | ✅ Ada | Alternatif: Hermina login manual, order via web |
| **sales_do** | ✅ Ada | Tabel & flow DO; support `source='portal'` |
| **order_create** | ✅ Ada | `POST /api/v1/partner/order_create.php` |

---

## 3. Yang Perlu Dipersiapkan

### 3.1 Teknis (Development)

| # | Item | Deskripsi |
|---|------|------------|
| 1 | **Endpoint `order_create`** | `POST /api/v1/partner/order_create.php` — terima JSON order, validasi, create DO |
| 2 | **Format request** | Spesifikasi: customers_code, office_code, do_date, items (sku/qty), shipping_address, dll |
| 3 | **Idempotency** | Parameter `idempotency_key` atau `external_order_id` — cegah duplikat jika Hermina retry |
| 4 | **Mapping SKU** | Hermina pakai kode apa? (SKU RMI, barcode, kode internal Hermina?) — perlu mapping jika beda |
| 5 | **Scope API** | Tambah scope `order:create` di API Partner Keys |
| 6 | **Logging & audit** | Log request/response; simpan source='h2h' atau 'api_partner' di sales_do |
| 7 | **Error handling** | Response standar: 200 OK + do_code, 4xx/5xx + error message JSON |

### 3.2 Bisnis & Proses

| # | Item | Deskripsi |
|---|------|------------|
| 1 | **Kick-off meeting** | RMI (IT/CRM) + Hermina (IT/Purchasing) — align format, SLA, UAT |
| 2 | **Dokumentasi API** | Spesifikasi endpoint, sample request/response, error codes — berikan ke Hermina |
| 3 | **API Key** | Buat key khusus Hermina (Production) di Master Data → API Partner Keys |
| 4 | **Environment** | Staging/sandbox untuk UAT sebelum production |
| 5 | **Pricelist** | Pastikan pricelist Hermina sudah di master_pricelist (per customer/office) |
| 6 | **Alamat & PIC** | Validasi customers_code, office_code, shipping_address — sesuai master_customers |

### 3.3 Operasional

| # | Item | Deskripsi |
|---|------|------------|
| 1 | **SOP H2H** | Prosedur: Hermina kirim PO → RMI terima → CRM review (jika perlu) → proses DO |
| 2 | **Monitoring** | Dashboard atau log untuk cek order masuk via API |
| 3 | **Escalation** | Siapa yang dihubungi jika API error / order gagal? |
| 4 | **Rollback** | Jika Hermina kirim salah, proses pembatalan/revisi bagaimana? |

---

## 4. Format Request (Draft)

```json
{
  "idempotency_key": "HERMINA-20260304-001",
  "external_order_id": "PO-HERMINA-2026-001",
  "customers_code": "Hoo1",
  "office_code": "BGR",
  "do_date": "2026-03-05",
  "customer_pic": "Budi (Purchasing)",
  "customer_phone": "0211234567",
  "shipping_address": "Jl. Kesehatan No.1, Depok",
  "note": "Order via H2H - Hermina Depok",
  "items": [
    { "sku": "SKU-001", "qty": 10, "unit": "pcs" },
    { "sku": "SKU-002", "qty": 5, "unit": "box" }
  ]
}
```

**Catatan:** Format final tergantung kesepakatan dengan Hermina. Mereka mungkin pakai kode produk internal — perlu mapping table.

---

## 5. Checklist Persiapan

### Fase 1: Riset & Alignment
- [ ] Meeting kick-off RMI–Hermina (format data, SLA, UAT)
- [ ] Dapatkan sample format PO dari sistem Hermina
- [ ] Tentukan mapping: kode produk Hermina ↔ SKU RMI (jika beda)
- [ ] Tentukan idempotency: pakai external_order_id atau idempotency_key?

### Fase 2: Development
- [x] Buat `api/v1/partner/order_create.php` ✅
- [x] Implement validasi: customer, office, pricelist, SKU
- [x] Implement idempotency (api_partner_order_idempotency)
- [x] Set `source='h2h'` di sales_do
- [x] Postman collection (`docs/postman/H2H_Order_Hermina.postman_collection.json`)
- [x] Scope `order:create` (opsional, kosong = allow all)

### Fase 3: Testing
- [ ] Buat API Key Development (Master Data → API Partner Keys)
- [ ] UAT mandiri: ikuti `docs/UAT_H2H_ORDER_LANGKAH.md` (script/Postman/curl)
- [ ] Test error case: invalid customer, SKU tidak ada, duplikat
- [ ] Test success: DO tercreate, tampil di Sales DO dengan badge H2H
- [ ] (Nanti) UAT dengan Hermina saat koordinasi

### Fase 4: Go-Live
- [ ] Buat API Key Production untuk Hermina
- [ ] SOP H2H disosialisasi ke CRM & Hermina
- [ ] Monitoring & escalation path jelas

---

## 7. Opsi Tambahan (UAT & Monitoring)

| Item | Lokasi | Keterangan |
|------|--------|------------|
| **Postman collection** | `docs/postman/H2H_Order_Hermina.postman_collection.json` | Import ke Postman, set base_url & api_key |
| **Script UAT** | `tools/qa/h2h_order_test.sh` | `./h2h_order_test.sh <base_url> <api_key>` |
| **Filter Sales DO** | Sales DO → Filter: Semua \| H2H \| Portal \| CRM | Monitoring order H2H |
| **Webhook notifikasi** | Env `H2H_WEBHOOK_URL` | Opsional. Saat order H2H berhasil, POST JSON: `{event, do_id, do_code, status, total_amount, customers_code, partner, external_order_id, created_at}` |

---

## 8. Demo & Cloudflare

| Dokumen | Keterangan |
|---------|------------|
| `docs/DEMO_H2H_MEETING_HERMINA.md` | Script demo untuk meeting Hermina |
| `docs/CLOUDFLARE_H2H_API.md` | Atur Cloudflare agar API tidak di-challenge |

---

## 9. Referensi

| Dokumen | Keterangan |
|---------|------------|
| `docs/PANDUAN_ORDER_CUSTOMER_MUDAH.md` | Opsi 4: API Order |
| `docs/PANDUAN_INTEGRASI_AFYA.md` | Jika Hermina pakai AFYA Better HIS |
| `README.md` | API Partner Keys, Base URL |
| `api/v1/partner/health.php` | Sample endpoint, auth |

---

## 10. Kontak

- **RMI IT:** [isi]
- **RMI CRM:** [isi]
- **Hermina IT/Purchasing:** [isi]
