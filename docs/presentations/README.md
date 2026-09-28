# Presentasi ERP RMI

## File

| File | Deskripsi |
|------|-----------|
| `ERP_RMI_Overview_Futuristic.html` | Presentasi overview ERP RMI — tema futuristic modern (browser) |
| **`ERP_RMI_Training_Detail.html`** | **Training detail untuk tim** — sub-halaman, langkah O2C, tips, hal perlu diperhatikan |
| `ERP_RMI_Overview_Slides.md` | Slide dalam Markdown — untuk konversi ke PPTX via pandoc |

**Sumber data:** `docs/ERP_MENU_WORKFLOW_REFERENCE.md`

**Untuk training tim:** Gunakan `ERP_RMI_Training_Detail.html` — lebih detail (20 slide).

---

## Cara Pakai

### Presentasi di Browser

1. Buka `ERP_RMI_Overview_Futuristic.html` di browser (Chrome, Edge, Firefox)
2. Tekan **F** untuk fullscreen
3. Navigasi: panah kiri/kanan atau klik
4. **F1** — bantuan reveal.js

### Export ke PDF

1. Buka file HTML di browser
2. Tekan **Esc** untuk overview mode (opsional)
3. **Ctrl+P** (atau Cmd+P di Mac) → Print
4. Pilih **Save as PDF** atau **Microsoft Print to PDF**
5. Pastikan opsi "Background graphics" aktif agar warna/gradient ikut tercetak

### Export ke PowerPoint (.pptx)

**Opsi A — Pandoc (disarankan):**

```bash
# Install pandoc: https://pandoc.org/installing.html
cd /Volumes/web/ERP_RMI_SOFULL
pandoc docs/presentations/ERP_RMI_Overview_Slides.md -o docs/presentations/ERP_RMI_Overview.pptx -t pptx
```

**Opsi B — Manual:**

1. Export HTML → PDF (langkah di atas)
2. Buka PowerPoint → Insert → Object → Create from file → pilih PDF
3. Atau: Insert → Slides from Outline (jika punya outline)

**Opsi C — Screenshot:**

1. Presentasi di browser, fullscreen
2. Screenshot tiap slide (Win+Shift+S / Cmd+Shift+4)
3. Insert gambar ke slide PowerPoint baru

---

## Tema

- **Futuristic Modern:** Dark background, cyan/purple accent, font Orbitron + Exo 2
- Grid background, glow effect, cyber-style badges
