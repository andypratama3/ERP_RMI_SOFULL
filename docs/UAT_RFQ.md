# UAT RFQ — End-to-End Testing

Panduan User Acceptance Testing untuk modul RFQ (Request for Quotation).

## Prasyarat

1. **Env (.env)** — untuk notifikasi email:
   ```
   ENABLE_EMAIL_ALERTS=1
   # PQP_RFQ_EMAIL opsional (prioritas: system_config RFQ.PQP_EMAIL)
   # PQP_RFQ_EMAIL=pqp@rizqullahmediska.com,rizqullahmediskapqp@rizqullahmediskaindonesia.com
   ```

2. **Migration 151, 152, 153** sudah dijalankan:
   ```bash
   cd /volume4/web/ERP_RMI_SOFULL
   php tools/run_migration_151.php
   php tools/run_migration_152.php
   php tools/run_migration_153.php
   ```

3. **Folder upload**: Pastikan `master/uploads/manufactures/` writable:
   ```bash
   chmod -R 755 master/uploads/manufactures
   ```

4. **Manufacturer Portal Users**: Minimal 2 manufacturer dengan user portal & email berbeda.

---

## Skenario 1: PQP Buat RFQ

| Step | Aksi | Hasil yang diharapkan |
|------|------|------------------------|
| 1 | Login sebagai PQP/Admin | Berhasil masuk |
| 2 | Buka Purchases → RFQ | Halaman daftar RFQ |
| 3 | Isi form: Judul, Deskripsi, Qty, Deadline, Status=Open | Form valid |
| 4 | Klik "Buat RFQ" | RFQ dibuat, flash success |
| 5 | Cek inbox manufacturer | Email notifikasi terkirim (jika ENABLE_EMAIL_ALERTS=1) |
| 6 | Cek Audit Log di halaman RFQ | Ada log CREATE |

---

## Skenario 2: Manufacturer Submit Quotation (berbagai currency)

| Step | Aksi | Hasil yang diharapkan |
|------|------|------------------------|
| 1 | Login Manufacturer Portal (Manufacturer A) | Berhasil masuk |
| 2 | Buka menu RFQ | Daftar RFQ open tampil |
| 3 | Filter: Pending | Hanya RFQ belum submit |
| 4 | Submit quotation: Price 100, Currency **CNY** | Berhasil submit |
| 5 | Logout, login Manufacturer B | - |
| 6 | Submit quotation: Price 1500000, Currency **IDR** | Berhasil submit |
| 7 | Logout, login Manufacturer C | - |
| 8 | Submit quotation: Price 95, Currency **USD** | Berhasil submit |
| 9 | Cek inbox PQP | Email notifikasi ke pqp@... & rizqullahmediskapqp@... |

---

## Skenario 3: Attachment Quotation

| Step | Aksi | Hasil yang diharapkan |
|------|------|------------------------|
| 1 | Manufacturer submit quotation + upload file PDF/Excel | Berhasil, file tersimpan |
| 2 | PQP buka halaman comparison RFQ | Tabel quotation tampil |
| 3 | Klik "Download" di kolom File | File terunduh |

---

## Skenario 4: Currency & Export

| Step | Aksi | Hasil yang diharapkan |
|------|------|------------------------|
| 1 | PQP buka RFQ yang punya quotation CNY, IDR, USD | Kolom ≈ USD tampil |
| 2 | Urutan harga | Terurut dari terendah (USD equivalent) |
| 3 | Klik Export CSV | File CSV terunduh |
| 4 | Klik Export Excel | File XLS terunduh |
| 5 | Buka file export | Kolom ≈ USD & Attachment ada |

---

## Skenario 5: Chat/Komentar

| Step | Aksi | Hasil yang diharapkan |
|------|------|------------------------|
| 1 | PQP tulis komentar di RFQ | Komentar tersimpan |
| 2 | Manufacturer buka RFQ, baca komentar | Komentar tampil |
| 3 | Manufacturer balas komentar | Komentar tersimpan |
| 4 | PQP refresh halaman | Semua komentar tampil |

---

## Skenario 6: Filter & Sort (Manufacturer Portal)

| Step | Aksi | Hasil yang diharapkan |
|------|------|------------------------|
| 1 | Filter: All | Semua RFQ open |
| 2 | Filter: Has (sudah submit) | Hanya RFQ yang sudah submit |
| 3 | Filter: Pending | Hanya RFQ belum submit |
| 4 | Cek urutan | Deadline terdekat di atas |

---

## Konfigurasi Currency Rate

Jika rate default tidak sesuai, update via **master_system_config.php**:

| config_group   | config_key | Contoh value | Keterangan        |
|----------------|------------|--------------|-------------------|
| RFQ_CURRENCY   | rate_CNY   | 0.14         | 1 CNY = 0.14 USD  |
| RFQ_CURRENCY   | rate_IDR   | 0.000063     | 1 IDR = 0.000063 USD |
| RFQ_CURRENCY   | rate_EUR   | 1.08         | 1 EUR = 1.08 USD  |

---

## Konfigurasi PQP Email

Email PQP untuk notifikasi quotation (comma-separated):

- **system_config**: group `RFQ`, key `PQP_EMAIL`  
  Contoh: `pqp@rizqullahmediska.com,rizqullahmediskapqp@rizqullahmediskaindonesia.com`
- **Alternatif**: Set `PQP_RFQ_EMAIL` di `.env`

---

## Checklist Verifikasi

- [ ] Migration 151, 152, 153 berhasil
- [ ] PQP bisa buat RFQ (draft & open)
- [ ] Email ke manufacturer saat RFQ open
- [ ] Manufacturer bisa submit quotation (USD, CNY, IDR, EUR)
- [ ] Manufacturer bisa upload file (PDF/Excel)
- [ ] Email ke PQP saat quotation submit
- [ ] Kolom ≈ USD tampil & urutan benar
- [ ] Export CSV/Excel berhasil
- [ ] Download attachment berhasil
- [ ] Komentar PQP & manufacturer berfungsi
- [ ] Filter (All/Has/Pending) berfungsi
- [ ] Audit log tercatat
