# Checklist: Update Docs — Apa Saja yang Perlu Diubah

Panduan untuk AI/developer saat ada penambahan atau perubahan dokumen di `docs/`.

---

## 1. Dokumen Baru (HTML) — Lokasi

| Jenis | Folder | Contoh |
|-------|--------|--------|
| PP (Peraturan Perusahaan) | `docs/officepack/pp/` | PP_Disiplin_Karyawan_v1.0.html |
| SOP | `docs/officepack/dept_training/` | SOP_Tindakan_Disipliner_v1.0.html |
| Form | `docs/officepack/forms/` | Form_Disciplinary_Action_v1.0.html |
| Policy | `docs/officepack/policies/` | Policy_Retention_Arsip_v1.0.html |
| Checklist | `docs/officepack/checklists/` | Checklist_Onboarding_Vendor_Baru_v1.0.html |
| Governance/Ops | `docs/governance/`, `docs/ops/` | OPS_RUNBOOK_FINAL.md |

---

## 2. File yang Perlu Di-Update Saat Ada Dokumen Baru

| File | Yang diubah |
|------|-------------|
| `docs/help_center.php` | Tambah entry `$docs[]` dengan `category`, `doc_code`, `title`, `files` |
| `docs/officepack/INDEX_v3.1.html` | Tambah `<li><a href='...'>Judul</a></li>` |
| `docs/officepack/register/HRL_Docs_Register_RMI_Recommendation_v1.0.csv` | Tambah baris: doc_code, title, unit, category, scope, status, tags, description |

---

## 3. File yang TIDAK Perlu Di-Update

| File | Alasan |
|------|--------|
| `docs/_token_helper.php` | Helper generik, tidak punya daftar dokumen |
| `docs/docs_view.php` | Proxy generik, melayani berdasarkan path |
| `docs/officepack_view.php` | Proxy generik. **Auto-rewrite**: link relatif di HTML diubah ke `officepack_view.php?f=...` saat disajikan, sehingga link antar dokumen tetap berfungsi di Help Center. |

---

## 4. File Binary (PDF/DOCX) — Regenerate Manual

| File | Cara update |
|------|-------------|
| `docs/officepack/INDEX_v3.1.pdf` | Regenerate dari INDEX_v3.1.html (Print to PDF / pandoc / wkhtmltopdf) |
| `docs/officepack/INDEX_v3.1.docx` | Regenerate dari INDEX_v3.1.html (Word / pandoc) |

Lihat: `docs/officepack/REGENERATE_INDEX_PDF_DOCX.md`

---

## 5. Deploy ke NAS — File yang Perlu Di-Copy

Lihat: `docs/DEPLOY_DOCS_CHECKLIST.md`

Ringkas: `_token_helper.php`, `docs_view.php`, `officepack_view.php`, `help_center.php`, `.htaccess`, folder officepack (file baru).

---

## 6. F1 Help & Panduan Cepat

| File | Fungsi |
|------|--------|
| `docs/help_sop_map.json` | Mapping F1 per halaman: path → title, purpose, sop, manual, diagram, steps, tips |
| `docs/officepack/dept_training/Panduan_Cepat_ERP_v1.0.html` | Panduan singkat untuk user baru |

**Urutan tampilan F1:** Diagram alur → Langkah kerja → Tips → Dokumen lengkap (SOP/Manual).

Saat tambah halaman baru: tambah entry di `help_sop_map.json` agar F1 menampilkan bantuan kontekstual. Modul utama (Sales, Purchases, Stock, HRL, Master, Dashboards) sudah punya diagram + steps + tips.

---

## 7. Contoh Urutan Update

1. Buat file HTML baru (mis. `pp/PP_Disiplin_Karyawan_v1.0.html`)
2. Update `help_center.php` — tambah blok `if (is_file(...)) { $docs[] = [...]; }`
3. Update `INDEX_v3.1.html` — tambah link
4. Update `HRL_Docs_Register_RMI_Recommendation_v1.0.csv` — tambah baris
5. (Opsional) Regenerate INDEX_v3.1.pdf & .docx
6. Deploy ke NAS sesuai checklist

---

*Generated untuk referensi AI/developer. Update doc ini jika ada perubahan proses.*
