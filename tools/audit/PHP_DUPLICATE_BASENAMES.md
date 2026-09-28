# File PHP dengan nama sama di folder berbeda (basename duplicate)

**Metode:** semua `*.php` di bawah root proyek, kecuali `.git/` dan `node_modules/`. Dikelompokkan menurut **nama file saja** (basename), bukan isi file (bukan `diff`).

**Scan referensi (contoh tanggal lokal):** jalankan ulang jika perlu:

```bash
cd /volume4/web/ERP_RMI_SOFULL   # atau path mount Mac Anda
find . -name '*.php' -type f ! -path '*/.git/*' ! -path '*/node_modules/*' \
  | sed 's|^\./||' | awk -F/ '{print $NF "\t" $0}' | LC_ALL=C sort -t "$(printf '\t')" -k1,1 -k2,2 > /tmp/rmi_php_by_base.tsv
```

## Ringkasan angka

| Cakupan | Jumlah **grup** basename yang punya ≥2 file | Jumlah **total file** dalam grup-grup itu |
|--------|---------------------------------------------|-------------------------------------------|
| **Semua** (termasuk `vendor/`, `exports/`, `_backup/`) | **671** | **1771** |
| Tanpa `vendor/` | 614 | 1618 |
| Tanpa `vendor/` + `exports/` | 165 | 470 |
| Tanpa `vendor/` + `exports/` + `_backup/` (“pohon aplikasi”) | **142** | **421** |

**Basename dengan salinan terbanyak di seluruh tree:** `index.php` (**42** path berbeda).

## Top 25 basename (pohon aplikasi saja: tanpa vendor, exports, _backup)

Diurutkan dari yang paling banyak salinan:

| Salinan | Basename |
|--------:|----------|
| 24 | panduan.php |
| 19 | index.php |
| 16 | panduan_index.php |
| 10 | bootstrap.php |
| 7 | _bootstrap.php |
| 6 | unmute.php, read.php, mute.php, mark_all_read.php, audit.php |
| 5 | logout.php, login.php, health.php, download.php, channels.php |
| 4 | search.php, schema.php, presence_ping.php, presence_get.php, presence.php, ping.php, messages.php, layout.php, attachment_upload.php, attachment_preview.php |

*(Angka di atas dari agregasi `uniq -c` per basename pada path yang tidak diawali `vendor/`, `exports/`, `_backup/`.)*

## Contoh detail: semua path `index.php` (42 file, full tree)

```
Fixed_Asset/index.php
_backup/purchases_m2_20260308_231256/purchases/index.php
_shared/index.php
absensi/index.php
chat/index.php
chat/views/index.php
customer_portal/index.php
dashboards/index.php
docs/user_guide/index.php
exports/deploy/2026-02/ERP_RMI_SOFULL_deploy_20260225/... (banyak mirror)
hrl_reg_alkes/index.php
index.php
manufacturer_portal/index.php
master/index.php
payroll/index.php
rbac/index.php
stock/index.php
tools/doctor/index.php
tools/index.php
views/chat/index.php
web/admin/rfc/index.php
```

## Catatan interpretasi

- **Nama sama ≠ isi sama.** `index.php` di tiap modul biasanya **entry berbeda**; itu normal di PHP.
- **`vendor/`**: library (PhpSpreadsheet, dll.) sengaja punya banyak class dengan nama file yang sama di subfolder berbeda.
- **`exports/`**: salinan deploy lama → basename bentrok dengan sumber di root modul.
- **`panduan.php` / `panduan_index.php` banyak:** pola panduan per modul + generator; bukan otomatis duplikasi bug.

## Laporan lengkap semua grup (671 basename)

File contoh hasil generate (semua grup + path): `/tmp/rmi_dup_report.txt` pada mesin yang menjalankan skrip (≈3000+ baris). Untuk membuat ulang dari `rmi_php_by_base.tsv`, gunakan skrip `flush` berkelompok seperti di sesi audit internal.
