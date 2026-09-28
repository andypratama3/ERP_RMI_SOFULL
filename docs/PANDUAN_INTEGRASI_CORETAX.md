# Panduan Integrasi ERP RMI SOFULL dengan Coretax

**Coretax** = Sistem perpajakan terpadu DJP (Jan 2025). Mendukung API & impor XML untuk faktur pajak.

---

## 1. Langkah Umum (Integrasi Apa Pun)

| # | Langkah | Keterangan |
|---|---------|-------------|
| 1 | **Riset sistem target** | Baca dokumentasi API/format data (Coretax, dll) |
| 2 | **Mapping data** | Tentukan field ERP ↔ field sistem target |
| 3 | **Pilih metode** | API, file (XML/CSV), atau hybrid |
| 4 | **Buat modul integrasi** | Endpoint/script export/import |
| 5 | **Testing** | Uji di sandbox/staging dulu |
| 6 | **Monitoring** | Log, retry, alert jika gagal |

---

## 2. Coretax — Yang Perlu Diketahui

| Aspek | Detail |
|-------|--------|
| **Portal** | https://coretaxdjp.pajak.go.id/ |
| **Dokumentasi** | Panduan Ringkas Coretax DJP (pajak.go.id) |
| **Metode integrasi** | API, Impor XML |
| **Data yang bisa diintegrasikan** | Faktur PPN, SPT, Bukti Potong, Billing |
| **Format XML** | Template dari DJP, converter tersedia (Maret 2025) |

---

## 3. Data ERP RMI SOFULL yang Relevan

| Sumber ERP | Tabel/Modul | Data untuk Coretax |
|------------|-------------|---------------------|
| **Tax Invoice** | `tax_invoices` | sales_invoice_ref (DO), tax_no, tax_date, status |
| **Sales DO** | `sales_do` | Customer, item, nilai, PPN |
| **Master Customer** | `master_customers` | NPWP, nama, alamat |
| **Master Company** | `master_emailcompany`, config | NPWP perusahaan |

---

## 4. Opsi Integrasi dengan Coretax

### Opsi A: Export XML (Impor ke Coretax)

1. **Buat fitur Export XML** di `tax_invoices.php` atau modul baru
2. **Format** mengikuti template DJP (cek Panduan Ringkas Coretax)
3. **Alur:** ERP → Generate XML → User upload ke Coretax
4. **Pro:** Lebih sederhana, tidak perlu API key DJP
5. **Kekurangan:** Semi-manual (export → upload)

### Opsi B: API Coretax (Jika Tersedia)

1. **Daftar/aktivasi** akses API di portal DJP
2. **Buat modul** `api/coretax/` atau `integrations/coretax/`
3. **Service class** untuk kirim data ke Coretax API
4. **Pro:** Otomatis, real-time
5. **Kekurangan:** Perlu credential API, dokumentasi API resmi

### Opsi C: Middleware (Pihak Ketiga)

- Gunakan jasa integrator (Accurate, Mekari, dll) yang sudah support Coretax
- ERP kirim data ke middleware → middleware ke Coretax
- Cocok jika tidak ingin develop in-house

---

## 5. Checklist Sebelum Mulai

```
□ Unduh Panduan Ringkas Coretax DJP (pajak.go.id)
□ Cek format XML faktur pajak keluaran
□ Pastikan NPWP customer & perusahaan valid
□ Mapping: sales_do + tax_invoices → format Coretax
□ Siapkan sandbox/staging untuk uji
□ Konsultasi dengan konsultan pajak jika perlu
```

---

## 6. Struktur File yang Disarankan

```
integrations/
  coretax/
    CoretaxXmlExporter.php   # Generate XML sesuai format DJP
    coretax_export.php       # Web: export XML untuk upload manual
api/
  v1/
    internal/
      coretax_sync.php       # (Opsional) API untuk push ke Coretax
```

---

## 7. Sumber Resmi

- **Portal Coretax:** https://coretaxdjp.pajak.go.id/
- **Panduan DJP:** https://www.pajak.go.id/ (cari "Coretax")
- **Kring Pajak:** 1500200
- **Artikel integrasi:** masirwin.com/integrasi-teknologi-pajak

---

## 8. Integrasi dengan Sistem Lain (Umum)

Untuk integrasi selain Coretax (marketplace, bank, dll):

| Sistem | Metode | Yang Ada di ERP |
|--------|--------|-----------------|
| **Partner eksternal** | API Key | `/api/v1/partner/` |
| **Webhook** | Callback | `api/webhooks/payment_callback.php`, `marketplace_order.php` |
| **Mobile** | JWT | `/api/v1/mobile/` |

**Pola umum:** Buat endpoint di `api/` atau modul `integrations/`, gunakan auth yang sesuai (API Key, OAuth, signature).

---

*Dokumen ini panduan awal. Format XML dan API Coretax dapat berubah — selalu cek dokumentasi terbaru DJP.*
