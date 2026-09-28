# Hermina H2H — Checklist Handover

Checklist untuk koordinasi dan handover ke Hermina Group.

---

## 1. Informasi untuk Hermina

| Item | Nilai |
|------|-------|
| **Base URL (UAT)** | `https://erp.rizqullahmediska.com/ERP_RMI_SOFULL` |
| **Health endpoint** | `GET /api/v1/partner/health.php` |
| **Order create** | `POST /api/v1/partner/order_create.php` |
| **Auth** | Header `X-API-Key: rpk_xxx` |
| **Content-Type** | `application/json` |

---

## 2. File untuk Dikirim ke Hermina

- [ ] **API Key UAT** (dari Master Data → API Partner Keys)
- [ ] **PERSIAPAN_H2H_PO_HERMINA.md** — Spesifikasi API
- [ ] **H2H_Order_Hermina.postman_collection.json** — Postman collection
- [ ] **UAT_H2H_ORDER_LANGKAH.md** — Langkah UAT

---

## 3. Mapping Data

| Field Hermina | Field RMI | Keterangan |
|---------------|-----------|------------|
| customers_code | Hoo1, dll | Kode customer di master_customers |
| office_code | BGR, dll | Kode office/cabang |
| items[].sku | OBT-001, dll | SKU produk di master_products |
| idempotency_key | - | Wajib untuk retry aman |

**Pastikan:** Hermina punya daftar customers_code dan office_code yang valid.

---

## 4. UAT Bersama

- [ ] Hermina hit health.php → return OK
- [ ] Hermina hit order_create dengan payload sample → return do_code
- [ ] Cek DO muncul di Sales DO (filter H2H)
- [ ] Test idempotency: kirim 2x request sama → return do_code sama

---

## 5. Go-Live (setelah migrasi data selesai)

- [ ] Buat **API Key Production** di Master Data
- [ ] Berikan ke Hermina
- [ ] Hermina ganti base URL ke production (jika beda)
- [ ] Monitor log: `storage/logs/api_partner_h2h.log`

---

## 6. Kontak Escalation

| Role | Untuk |
|------|-------|
| RMI IT | API error, config |
| RMI CRM | Data customer, DO, proses bisnis |

---

## 7. Log Monitoring

Setelah order_create, log ditulis ke:

```
storage/logs/api_partner_h2h.log
```

Format: JSON lines. Contoh:

```json
{"ts":"2026-03-08T02:00:00+07:00","ip":"x.x.x.x","event":"created","partner":"Hermina Group UAT","do_id":123,"do_code":"RMI-BGR-260308-001","customers_code":"Hoo1","total_amount":150000}
```

```bash
tail -f storage/logs/api_partner_h2h.log
```
