# Playwright QA — ERP RMI SOFULL

Browser automation nyata (bukan curl) untuk QA ERP ini.

## Prinsip
- **Tidak ada credential di repo.** Semua lewat environment variable.
- Spec yang butuh login akan `skip` bila env tidak di-set — bukan `fail`.
  Ini supaya CI tanpa secret tetap hijau dan tidak memalsukan PASS.
- Tidak ada overlay putih dan tidak ada perubahan target sentuh.
- `tools/qa/audit_theme_contrast.py` **tidak** dipakai sebagai gate.

## Setup
```bash
npm install
npx playwright install chromium firefox webkit
```

## Menjalankan
```bash
export PW_BASE_URL="http://127.0.0.1:8765"   # default kalau kosong
# hanya untuk spec yang butuh login:
export PW_ADMIN_USER="..." PW_ADMIN_PASS="..."

cd tools/qa/playwright
npx playwright test                              # semua project
npx playwright test --project=chromium           # 1 engine
npx playwright test tests/smoke.spec.js          # lintas 3 engine
npx playwright test --project=responsive         # 3 viewport
npx playwright show-report                       # buka HTML report
```

## Project
| Project | Engine / viewport | Fungsi |
|---|---|---|
| `chromium` | Desktop Chrome | Cross-browser |
| `firefox` | Desktop Firefox | Cross-browser |
| `webkit` | Desktop Safari | Cross-browser |
| `responsive` | Chrome @ 390 / 820 / 1440 | Overflow + kontrol form |

## Spec
- `tests/smoke.spec.js` — guardrail: halaman publik tidak 5xx, tidak ada PHP
  fatal/parse error yang bocor ke browser.
- `tests/responsive.spec.js` — overflow horizontal, elemen keluar viewport,
  kontrol form zero-size.
- `tests/_helpers.js` — login ENV-based (`hasCreds`, `login`).

## Output
- Trace/screenshot: `tools/qa/playwright/test-results/`
- HTML report: `tools/qa/playwright/playwright-report/`
- Keduanya sudah masuk `.gitignore`.

## Status
Lihat `tools/qa/ERP_MASTER_TASK_TRACKER.md` section `PLAYWRIGHT COVERAGE`.
Jangan menandai PASS di tracker tanpa menyertakan output test.
