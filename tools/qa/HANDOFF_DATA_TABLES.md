# HANDOFF — DataTables / legacy table / theme (2026-09-29)

Dokumen ini untuk agent yang melanjutkan. **Baca bagian "JANGAN" dulu sebelum
ngubah apa pun.** Dua dari tiga temuan di bawah sudah pernah menyesatkan saya
sendiri di sesi yang sama.

Repo: `/Users/andypratama3/Development/ERP_RMI_SOFULL`
Branch: `main` — saat ditulis, HEAD = `4f8bf98`, sudah sinkron dengan `origin/main`
(0 ahead / 0 behind), working tree bersih.

---

## 1. Urutan muat stylesheet (kunci seluruh kaskade CSS)

`_shared/rmi_layout.php`:

| Baris | Stylesheet |
|---|---|
| 911 | `bootstrap.min.css` |
| 912 | `_shared/rmi.css` |
| 942 | `$opts['extra_head']` → **`dataTables.bootstrap5.min.css`** |
| 945 | `_shared/rmi_light_compat.css` |

Konsekuensi yang sering terlewat: **DataTables CSS dimuat SETELAH `rmi.css`**.
Rule di `rmi.css` yang specificity-nya sama akan kalah. Bootstrap dimuat lebih awal, jadi `rmi.css` masih bisa mengalahkannya dengan specificity lebih tinggi.

`_shared/rmi_light_compat.css` **wajib** tetap setelah `extra_head` (sudah ada
komentar alasannya di baris 943-944). File itu juga dibatasi: hanya boleh
mengubah `color`, `background`, dan `border`.

Markup DataTables 1.13 bootstrap5 yang dipakai repo ini:

```html
<ul class="pagination">
  <li class="paginate_button page-item"><a class="page-link" href="#">1</a></li>
```

`.paginate_button` = `<li>`. Kotak yang **benar-benar terlihat** = `<a class="page-link">`.
Rule harus menyasar keduanya, bukan hanya `<li>`.

---

## 2. JANGAN

### 2.1 JANGAN percaya `tools/qa/audit_theme_contrast.py` sebagai gate

Ini yang paling rawan. File itu **bukan scaffold** (393 baris, jalan normal).
Tapi hasilnya **tidak valid** karena salah memasangkan warna background.

Bukti konkret — di `sales/sales_do_view.php`, elemen-elemen ini berada DI DALAM
`.page { background:#ffffff }`:

| Selector | fg | Nyata di atas `#ffffff` | Klaim tool |
|---|---|---|---|
| `.erp-actor-name` | `#111827` | **17.74:1** | 1.09:1 (di atas `#1a202d`) |
| `.tracking` | `#6b7280` | **4.83:1** | 3.37:1 (di atas `#1a202d`) |
| `.signature-meta` | `#4b5563` | **7.56:1** | 2.15:1 (di atas `#1a202d`) |

Tool memilih `#1a202d` (panel gelap dari bagian lain file) sebagai background,
padahal elemennya di atas kertas putih. Selisihnya sampai 16x.

Konsekuensi: angka **35.703 kegagalan di 988 halaman** itu bukan 35.703 bug.
Memasangnya sebagai blocking gate di `ci_lint` sekarang akan membanjiri repo
dengan false positive dan membuat gate tidak bisa dipakai. **Belum dipasang,
dan sebaiknya tidak dipasang** sebelum tool bisa menyelesaikan cascade (butuh
analisis DOM, atau minimal melacak background ancestor yang benar).

Tool itu juga punya footgun: argumen non-glob diam-diam menghasilkan
`0 kegagalan` (lulus palsu). Contoh:

```bash
python3 tools/qa/audit_theme_contrast.py --help
# === 0 halaman diperiksa, 0 kegagalan <<<   <- exit 0, bukan error
```

Selalu jalankan tanpa argumen, dan selalu sanity-check jumlah halamannya
(harus ~988, bukan 0).

### 2.2 JANGAN ubah shared rule dark body jadi hitam

`_shared/rmi.css` (~baris 70):

```css
html[data-theme="dark"] body.rmi-body { ... color: var(--rmi-text) }
```

`--rmi-text` dark = `#e5e7eb`. Itu **benar** untuk body gelap. Yang salah
adalah halaman yang menaruh kertas putih di atasnya dan tidak mengunci warna
teksnya. Perbaikannya di level halaman, bukan dengan rusakin shared rule.

### 2.3 JANGAN pakai overlay putih untuk zebra/hover

`rgba(255,255,255,α)` tidak bekerja di light mode. Diukur (jarak baris even vs
baris biasa, target 1.06–1.15):

| | lama (putih) | baru (netral) |
|---|---|---|
| dark | 1.0417 | 1.1082 |
| light | **1.0014** | 1.0937 |
| contrast | 1.0310 | 1.0784 |

Gunakan `rgba(127,127,127,α)` untuk dark/contrast, `rgba(15,23,42,α)` untuk light.

### 2.4 JANGAN geser target sentuh

Blok `9h` di `rmi.css` menulis ulang target ≥44px **paling akhir** dengan sengaja.
Kalau ada yang menambah rule pagination setelahnya, target sentuh ikut hilang.

### 2.5 JANGAN bungkus tabel print dengan `table-responsive`

`sales/sales_do_view.php` adalah halaman print (`@media print` di dalam
CSS halaman). Butuh uji khusus. Selain itu, dari 37 `*_view.php` yang diinventarisasi,
**hanya** `sales_do_view.php` yang punya print button/CSS dan dipanggil dengan
`mode=print`. Jangan menambah print CSS spekulatif ke view lain.

### 2.6 JANGAN menebak owner untuk PENDING-05

23 actor unlinked + 2 department mismatch. Butuh keputusan data owner.

---

## 3. TIGA TEMA (semua token dipakai di blok 9a–9g)

| Tema | Selector | `--rmi-bg` | `--rmi-accent` |
|---|---|---|---|
| dark (default) | `body.rmi-body` | `#0b1220` | `#3b82f6` |
| light | `html[data-theme="light"]` / `html[data-rmi-theme="light"]` | `#f5f7fb` | `#2563eb` |
| contrast | `body.theme-contrast` | `#000000` | `#00c8ff` |

HATI-HATI `theme-contrast`: accent-nya `#00c8ff` (cyan terang), white di atasnya
**1.96:1 — gagal total**. Karena itu blok 9a memaksa `#1d4ed8` untuk halaman
aktif di dark dan contrast, bukan memakai `var(--rmi-accent)`.

Kontras yang sudah diverifikasi:

- halaman aktif dark `#1d4ed8` = 6.70:1 (AA)
- halaman aktif light `#2563eb` = 5.17:1 (AA)
- teks non-aktif dark = 15.12:1, light = 16.65:1 (AA)

---

## 4. YANG SUDAH SELESAI

| Commit | Isi |
|---|---|
| `2c325e2` | tabrakan dark/light shared component |
| `70168ce` | lock Detail/Print kartu DO sampai status DELIVERED (UI saja) |
| `9a36675` | tracker full reproducible + regenerasi inventory |
| `f4e3ec4` | work item manual digabung ke `ERP_FULL_TRACKER.md` |
| `dccc00e` | teks di kertas `.page` saat mode gelap (`sales_do_view.php`) |
| `4f8bf98` | pagination DataTables + zebra legacy table (blok 9a–9h) |

Blok 9a–9h di `_shared/rmi.css` (9a pagination & `<a class="page-link">`,
9b baris kontrol, 9c state empty/processing/error, 9d sort indicator,
9e zebra/hover legacy table, 9f sticky thead, 9g caption/border terakhir,
9h target sentuh).

---

## 5. YANG MASIH BELUM SELESAI

### Terverifikasi masih ada (dicek ulang 2026-09-29)

1. **XSS — `<?=$vs?>`** di `hrl/hrl_doc_view.php:449`, nilai dari DB, belum di-escape.
   Prioritas tinggi. Satu-satunya temuan unescaped yang tersisa dari audit view
   sebelumnya (19 kandidat, 18 sudah aman).
2. **`table-responsive` hilang** di `hrl_process/employee_mutation_view.php`
   (1 tabel) dan `hrl_process/request_view.php` (1 tabel) — keduanya 0 kemunculan.
3. **52 halaman punya `<table>` polos tanpa `table-responsive`.**
   Dihitung per-file (`'table-responsive' not in file`). Catatan: angka 54 yang
   pernah muncul sebelumnya berasal dari pengurangan jumlah file
   (226 - 172), itu aritmetika tingkat file dan tidak per-file — pakai 52.
4. **Audit seluruh `*_view.php`** (37 file, 10 di antaranya stub auto-generate).
5. **Wave index/filter** dari backlog `tools/qa/ERP_FULL_TRACKER.md`.
6. **RETEST → PASS** untuk item berstatus `FIXED` di tracker.
7. **Tinjau kandidat** 115/131 aksi mutating tanpa audit trail dan 183/349
   auth gate tanpa permission check. Ini kandidat statis, butuh konfirmasi owner.
8. **Keputusan owner**: server-side lock `sales_do_view.php` + lock Detail/Print
   di ACT/FIN. Saat ini UI-only, akses langsung masih terbuka.
9. **`check_escaped_svg.sh` timeout** — masih jalan >300s di 675 halaman.
   Ini yang bikin `ci_lint.sh` sering timeout. **Bukan regresi.**
   `ci_lint` sendiri LULUS; pakai timeout ≥600s.
10. **`audit_theme_contrast.py`** — bukan scaffold (lihat 2.1), tapi belum layak
    jadi gate. Butuh perbaikan cascade dulu.

### Belum bisa diverifikasi

**Tidak ada verifikasi visual sama sekali.** 65 halaman DataTables + halaman
legacy butuh authenticated session; request tanpa login balas `302` ke login.
Semua angka kontrase di dokumen ini dihitung statis, bukan dari computed style.
Klaim "terlihat benar" belum terbukti — yang terbukti hanya "tidak lebih buruk,
dan secara matematis lebih terbaca".

Jangan pakai Chrome milik pengguna untuk ini.

---

## 6. VERIFIKASI SEBELUM COMMIT

```bash
php -l <file.php>                        # untuk tiap PHP yang diubah
bash tools/qa/ci_lint.sh                 # timeout >= 600s (lihat item 9)
```

Untuk CSS, cek struktur (minified vendor di repo memang punya paren tidak
seimbang `-17` — itu pre-existing, bukan bug yang kamu buat):

```bash
python3 -c "s=open('_shared/rmi.css').read(); print(s.count('{'), s.count('}'))"
```

Setelah ubah CSS besar, jalankan `audit_theme_contrast.py` **tanpa argumen**
hanya sebagai deteksi awal, dan **ingat semua temuannya harus diverifikasi manual**
terhadap cascade sebenarnya sebelum dipakai.

---

## 7. CATATAN COMMIT

`dccc00e` punya bug di commit message: ada karakter Mandarin yang slipped di
salah satu baris (`body页面`). File-nya sendiri bersih (0 baris CJK, sudah
di-scan). Belum di-rewrite karena tidak diminta. Kalau mau bersih, jangan `--amend` tanpa izin — buat commit baru yang mengoreksi, atau minta dulu.
