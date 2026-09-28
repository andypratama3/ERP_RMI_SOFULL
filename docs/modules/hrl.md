# HRL — People, Absensi & Payroll

## Ringkasan alur

**HRL** mengelola **karyawan/struktur**, **absensi** enterprise, **payroll**, dan **dokumen** kebijakan; **HRL Process** untuk alur approval terstruktur.

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `hrl/index.php` | Entry HRL | HRL |
| `hrl/hrl_docs.php` | Dokumen & acknowledgement | HRL, Staff |
| `hrl_process/tower.php` | Tower proses HRL | HRL, terkait permission |
| `absensi/index.php` | Absensi (check-in, izin, approval) | HRL, Staff |
| `payroll/index.php` | Payroll periode | HRL, FIN |
| `master/master_employees.php` | Master karyawan | HRL |
| `master/master_departements.php` | Departemen & level | HRL |

## Monitoring

- Payroll & absensi: laporan internal per modul.
- Office pack **SOP Staff HRL** di Help Center (Office Pack).

## Deep-dive

- **Branch playbook** karyawan cabang: Help Center → kategori BRANCH.
- Onboarding user ERP: `docs/onboarding/NEW_USER.md`, `docs/ONBOARDING_USER.md`.
