# ASSUMPTIONS_LOG — E2E Trail Agent

**Tanggal:** 2026-03-10  
**Agent:** ERP_RMI_SOFULL E2E TRAIL AGENT (NAS-ONLY)

---

## 1. NAS Path Tidak Diakses

**Aturan:** Semua kerja wajib di `/volume4/web/ERP_RMI_SOFULL` (NAS-only).

**Kondisi:** Environment saat ini = Mac mount (`/Volumes/web/ERP_RMI_SOFULL`). Path `/volume4/web/ERP_RMI_SOFULL` **tidak dapat diakses** dari shell.

**Asumsi:** Trail dijalankan dari workspace path `/Volumes/web/ERP_RMI_SOFULL`. Jika konten sama (mount NAS), hasil valid. Tools yang punya guard `RUN_ON_NAS_REQUIRED` mungkin FAIL — dicatat di report.

**Rekomendasi:** Untuk hasil 100% sesuai aturan, jalankan trail via SSH ke NAS:
```bash
ssh user@nas-ip
cd /volume4/web/ERP_RMI_SOFULL
php tools/e2e_trail_runner.php
```

**Hasil trail dari Mac:** HTTP code 0 (unreachable), tools exit 127 (php not in exec PATH), data_integrity kosong (DB mungkin di NAS). Evidence pack tetap dihasilkan.

---

## 2. Base URL untuk HTTP Check

**Konteks:** LAN_BASE_URL=http://10.10.60.20/ERP_RMI_SOFULL, PUBLIC_BASE_URL=https://erp.rizqullahmediska.com/ERP_RMI_SOFULL

**Asumsi:** HTTP checks memakai PUBLIC_BASE_URL (akses dari internet) atau LAN_BASE_URL jika dalam jaringan. Jika keduanya unreachable, HTTP checks = SKIP.

---

## 3. Admin Session untuk HTTP Check

**Asumsi:** Tidak ada kredensial admin di env. Guest checks (302 → login) tetap valid. Admin checks (200) memerlukan session — jika tidak ada, admin checks = SKIP atau mock.

---

## 4. Dokumen Referensi

**Sumber:** `docs/ERP_MENU_WORKFLOW_REFERENCE.md` — **DITEMUKAN** ✅

---

## 5. ALLOW_WRITE_DEMO

**Default:** 0 (read-only). Tidak create transaksi baru.
