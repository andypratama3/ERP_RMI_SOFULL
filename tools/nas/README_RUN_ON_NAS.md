# Run on NAS — Golden Rule

## Beda mount Mac vs NAS

| Path | Lokasi | Dipakai untuk |
|------|--------|---------------|
| Share SMB di Finder (lihat `docs/SETUP_TIM_EDIT_TERLIHAT.md`) | Mac | **Edit** file saja |
| `/volume4/web/ERP_RMI_SOFULL` | NAS (Synology) | **Eksekusi** tools |

## Golden Rule

> **Edit boleh dari Mac. Eksekusi tools wajib lewat NAS.**

Tools (cutover, smoke, doctor, dll.) **tidak boleh** dijalankan dari Mac mount. Harus di NAS.

---

## Command Siap Pakai

### 1) SSH manual ke NAS

```bash
ssh RizqullahMediska@RMI-2025
cd /volume4/web/ERP_RMI_SOFULL
./tools/nas/erp.sh php tools/qa/run_cutover_checks.php --write-last --strict
```

### 2) Remote runner dari Mac

```bash
NAS_SSH_TARGET='RizqullahMediska@RMI-2025' \
NAS_APP_ROOT='/volume4/web/ERP_RMI_SOFULL' \
./tools/nas/remote_run.sh php tools/qa/run_cutover_checks.php --write-last --strict
```

Atau pakai hostname:

```bash
NAS_SSH_TARGET='RizqullahMediska@RMI-2025.local' ./tools/nas/remote_run.sh php tools/qa/run_cutover_checks.php --write-last --strict
```

**Catatan:** `NAS_SSH_TARGET` wajib. Setup SSH key agar `BatchMode=yes` berjalan tanpa password.

---

## Target URL (smoke)

- **LAN:** http://10.10.60.20/ERP_RMI_SOFULL/
- **Public:** https://erp.rizqullahmediska.com/ERP_RMI_SOFULL

---

## Jika gagal dari Mac

Pesan error:

```
RUN_ON_NAS_REQUIRED: please SSH to NAS and run from /volume4/web/ERP_RMI_SOFULL
```

**Solusi:** Pakai SSH manual atau `remote_run.sh` (lihat di atas).
