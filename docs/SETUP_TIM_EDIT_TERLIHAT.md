# Setup Tim — Agar Edit Terlihat di Web

**Masalah:** Tim edit file di ERP tapi perubahan tidak muncul di browser.

**Penyebab utama:** Edit di folder **lokal** Mac, sedangkan web server baca dari **NAS**.

---

## Solusi: Edit di Lokasi yang Benar

### Opsi A: Buka Project dari Share NAS (Disarankan)

1. **Connect ke NAS**
   - Finder → **Go** → **Connect to Server** (`Cmd+K`)
   - Masukkan: `smb://RMI-2025/web`
   - Login jika diminta

2. **Buka project dari share**
   - Setelah terhubung, folder **web** muncul di sidebar Finder
   - Buka folder **web** → **ERP_RMI_SOFULL**
   - Drag folder **ERP_RMI_SOFULL** ke Cursor/VS Code, atau
   - Cursor → **File** → **Open Folder** → pilih `ERP_RMI_SOFULL` di dalam share **web**

3. **Verifikasi path**
   - Di Cursor, lihat path di status bar atau tab file
   - Harus: `/Volumes/web/ERP_RMI_SOFULL/...`
   - Bukan: `/Users/.../ERP_RMI_SOFULL` atau `~/Projects/...`

4. **Save = langsung ke NAS**
   - Edit file → `Cmd+S` → perubahan langsung tersimpan ke NAS
   - Refresh browser (atau `Cmd+Shift+R` untuk hard refresh)

---

### Opsi B: Edit Lokal + Deploy ke NAS

Jika tim prefer edit di folder lokal (mis. `~/Projects/ERP_RMI_SOFULL`):

1. Setelah selesai edit, deploy ke NAS:
   ```bash
   rsync -avz -e ssh ~/Projects/ERP_RMI_SOFULL/master/ \
     user@RMI-2025.local:/volume4/web/ERP_RMI_SOFULL/master/
   ```

2. Atau pakai script deploy (lihat `docs/LANGKAH_DEPLOY_KE_NAS.md`)

---

## Cek Path Sekarang

Jalankan dari Terminal (di folder project):

```bash
cd /path/ke/ERP_RMI_SOFULL
php tools/cek_workspace_path.php
```

Script akan memberitahu apakah path saat ini benar atau tidak.

---

## Troubleshooting

| Gejala | Kemungkinan | Solusi |
|--------|-------------|--------|
| Edit tidak muncul | Buka folder lokal | Buka dari share NAS (Opsi A) |
| Edit tidak muncul | Share belum connect | Connect to Server → smb://RMI-2025/web |
| Edit tidak muncul | Browser cache | Hard refresh: Cmd+Shift+R |
| Edit tidak muncul | PHP opcache | Restart web server di NAS |

---

## Ringkasan

| Path project | Edit terlihat? |
|--------------|----------------|
| `/Volumes/web/ERP_RMI_SOFULL` (share NAS) | ✅ Ya, langsung |
| `/volume4/web/ERP_RMI_SOFULL` (di NAS) | ✅ Ya |
| `~/Projects/ERP_RMI_SOFULL` (lokal) | ❌ Tidak, harus deploy |
