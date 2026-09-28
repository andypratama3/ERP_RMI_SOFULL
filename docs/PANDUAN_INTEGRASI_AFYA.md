# Panduan Integrasi ERP RMI SOFULL dengan AFYA

**AFYA** = Platform digital kesehatan dari PT Daya Medika Pratama (HIS, AFYACARE).  
**Teknologi:** REST, SOAP API. Dokumentasi API tidak publik — perlu hubungi vendor.

---

## Langkah Integrasi (Urutan)

### Fase 1: Persiapan & Riset

| # | Langkah | Tindakan |
|---|---------|----------|
| 1 | **Hubungi vendor** | Kontak Daya Medika Pratama / AFYACARE untuk minta akses API & dokumentasi |
| 2 | **Tentukan scope** | Data apa yang akan dipertukarkan? (pasien, produk, transaksi, klaim?) |
| 3 | **Dapatkan credential** | API key, token, atau mekanisme auth yang dipakai AFYA |
| 4 | **Dapatkan dokumentasi** | Endpoint, format request/response, error code |

**Kontak AFYACARE:**
- Email: afyacare.co.id@gmail.com
- Telp: +62 (21) 63864723
- Web: dayamedikapratama.com, afyacare.co.id

---

### Fase 2: Mapping Data

| # | Langkah | Tindakan |
|---|---------|----------|
| 1 | **Buat tabel mapping** | ERP field ↔ AFYA field |
| 2 | **Identifikasi sumber data** | Tabel/modul ERP yang dipakai |
| 3 | **Identifikasi transformasi** | Format tanggal, kode, nilai yang perlu dikonversi |

**Contoh mapping (jika integrasi produk/transaksi):**

| ERP RMI SOFULL | AFYA |
|----------------|------|
| master_products (sku, products_name) | Item/obat di AFYA |
| sales_do (do_code, customer, items) | Order/transaksi |
| master_customers | Pasien/pemberi layanan |

---

### Fase 3: Development

| # | Langkah | Tindakan |
|---|---------|----------|
| 1 | **Buat folder modul** | `integrations/afya/` |
| 2 | **Buat service class** | `AfyaApiClient.php` — handle auth, request, response |
| 3 | **Buat endpoint/job** | Script atau API internal untuk push/pull data |
| 4 | **Simpan config** | API URL, key di `.env` (jangan hardcode) |
| 5 | **Logging** | Log request/response untuk debugging & audit |

**Struktur file yang disarankan:**
```
integrations/
  afya/
    AfyaApiClient.php      # HTTP client, auth
    AfyaProductSync.php    # Sync produk (jika ada)
    AfyaOrderSync.php      # Sync order/transaksi (jika ada)
    config.php             # URL, timeout (baca dari env)
```

---

### Fase 4: Testing

| # | Langkah | Tindakan |
|---|---------|----------|
| 1 | **Sandbox/Staging** | Minta environment test ke AFYA |
| 2 | **Test koneksi** | Ping/health endpoint |
| 3 | **Test CRUD** | Create, read, update (jika applicable) |
| 4 | **Test error handling** | Timeout, invalid data, auth gagal |
| 5 | **UAT** | Uji dengan user bisnis |

---

### Fase 5: Go-Live & Monitoring

| # | Langkah | Tindakan |
|---|---------|----------|
| 1 | **Deploy ke produksi** | Set credential production di `.env` |
| 2 | **Monitoring** | Log, alert jika sync gagal |
| 3 | **Retry mechanism** | Queue/job untuk retry jika gagal |
| 4 | **Dokumentasi** | Update docs, runbook untuk tim ops |

---

## Checklist Singkat

```
□ Hubungi AFYA/Daya Medika Pratama
□ Dapatkan dokumentasi API & credential
□ Tentukan scope integrasi (data apa)
□ Buat mapping ERP ↔ AFYA
□ Develop modul di integrations/afya/
□ Simpan config di .env
□ Test di sandbox
□ Deploy & monitor
```

---

## Variabel Environment (.env)

```env
# AFYA Integration (isi setelah dapat credential)
AFYA_API_URL=https://api.afya.example.com
AFYA_API_KEY=your-api-key
AFYA_ENABLED=0
```

---

## Pola yang Sudah Ada di ERP RMI SOFULL

- **Partner API:** `/api/v1/partner/` — auth API Key
- **Webhook:** `api/webhooks/` — terima callback dari eksternal
- **Internal API:** `api/v1/internal/` — untuk konsumsi internal

Integrasi AFYA bisa mengikuti pola yang sama: buat client yang memanggil AFYA API, atau endpoint yang menerima callback dari AFYA.

---

*Dokumen ini panduan umum. Detail teknis tergantung dokumentasi yang diberikan oleh Daya Medika Pratama.*
