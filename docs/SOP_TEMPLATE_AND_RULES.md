# SOP ERP_RMI_SOFULL — Template & Aturan (Detail & Konsisten)

**Tujuan:** Memastikan semua SOP modul ERP detail, menyeluruh, dan konsisten.

---

## 1. Struktur Standar SOP

Setiap SOP modul ERP **wajib** mengikuti struktur berikut:

| # | Section | Wajib | Keterangan |
|---|---------|-------|------------|
| - | **Tabel metadata** | Ya | Doc Code, Judul, Versi, Owner, Berlaku sejak, Review cycle |
| 1 | **Tujuan** | Ya | Paragraf jelas: apa yang dicapai, siapa bertanggung jawab |
| 2 | **Ruang Lingkup** | Ya | Daftar file/path yang tercakup (ul/li dengan code) |
| 3 | **Prasyarat** | Ya | Login, role, data master, kondisi awal |
| 4 | **Langkah Operasional Umum** | Ya | Ringkasan alur (4–6 langkah) |
| 5 | **SOP Detail per Halaman** | Modul | Per halaman: Tujuan, ol (4–6 langkah), Tips. Gunakan id anchor |
| 6 | **Kontrol dan Kepatuhan** | Ya | Aturan wajib, validasi, DoA |
| 7 | **Bukti Operasional** | Ya | Simpan bukti, audit log, monitoring |
| 8 | **Eskalasi** | Ya | Anomali, keamanan, PIC |

---

## 2. Tabel Metadata

```html
<table>
  <tr><th style="width:22%">Doc Code</th><td>HRL-HR-SOP-ERP-XXX-001</td></tr>
  <tr><th>Judul</th><td>SOP [Modul] [Judul]</td></tr>
  <tr><th>Versi</th><td>v3.1</td></tr>
  <tr><th>Owner</th><td>CRM / WQS / FIN / MPR / ...</td></tr>
  <tr><th>Berlaku sejak</th><td>2026-XX-XX</td></tr>
  <tr><th>Review cycle</th><td>12 bulan</td></tr>
</table>
```

---

## 3. SOP Detail per Halaman (Modul dengan banyak halaman)

Untuk modul yang punya banyak halaman (CRM, WQS, MPR, FIN, dll):

- Section header: `<h2 id="sop_per_halaman">5. SOP Detail per Halaman</h2>`
- Setiap halaman: `<h3 id="nama_file">nama_file.php — Judul</h3>`
- Format per halaman:
  - `<p><strong>Tujuan:</strong> ...</p>`
  - `<ol><li>...</li></ol>` (4–6 langkah konkret)
  - `<p><strong>Tips:</strong> ...</p>`

**Id anchor** harus match nama file (tanpa .php) agar F1 bisa link ke section spesifik jika diperlukan.

---

## 4. Konsistensi

- **Paragraf pembuka:** Selalu sertakan `<strong>Rizqullah Mediska Indonesia</strong> —` di paragraf pertama.
- **Style:** Gunakan style yang sama (table, th/td, code, badge) seperti SOP_CRM, SOP_FIN, SOP_MPR.
- **help_sop_map.json:** Setiap entri map wajib punya `diagram`, `sop`, `manual`. Steps di map harus sinkron dengan SOP Detail per Halaman.

---

## 5. Referensi SOP yang Sudah Konsisten

- `SOP_CRM_SALES_O2C_v3.1.html`
- `SOP_FIN_FINANCE_AR_AP_CASH_v3.1.html`
- `SOP_WQS_WAREHOUSE_STOCK_v3.1.html`
- `SOP_MPR_Marketing_Project_v1.0.html`
- `SOP_Master_Data_v3.1.html`

---

## 6. Aturan Penambahan SOP Baru

1. Wajib ada versi print-ready `.html`.
2. Tambahkan doc_code di header (HRL-HR-SOP-*, HRL-LEGAL-SOP-*).
3. Daftarkan di `docs/help_sop_map.json` untuk F1/help mapping.
4. Daftarkan di SOP Index (`SOP_Index_ERP_RMI_SOFULL_v3.1.html`).
5. Ikuti struktur standar di atas.
