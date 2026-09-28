# Panduan GO-Live Taskboard — Untuk Manager Departemen

Dokumen ini menjelaskan cara **Manager Departemen** mengisi dan memvalidasi checklist GO-Live Taskboard.

---

## 1. Kolom yang Diisi Manager Dept

| Kolom | Diisi oleh | Keterangan |
|-------|------------|------------|
| **pic_manager** | Manager Dept | Nama PIC yang menjalankan task (bisa Staff/Manager) |
| **status** | Manager Dept | NOT_STARTED → IN_PROGRESS → READY / DONE |
| **validated_by** | Manager Dept | Nama Manager Dept yang memvalidasi |
| **validated_at** | Manager Dept | Tanggal validasi (YYYY-MM-DD) |
| **notes** | Manager Dept | Catatan / blocker / halangan |

---

## 2. Mapping Task per Departemen

| owner_dept | Departemen | Manager mengisi | Task ID |
|------------|------------|-----------------|---------|
| **CRM** | Sales / CRM | MgrCRM_* | GL-004, GL-006, GL-019 |
| **PQP** | Purchasing | MgrPQP_* | GL-003, GL-005, GL-020 |
| **WQS** | Warehouse | MgrWQS_* | GL-007, GL-021 |
| **SCM** | SCM / Logistik | MgrSCM_* | GL-022 |
| **FIN** | Finance | MgrFIN_* | GL-023 |
| **HRL** | HRL | MgrHRL_* | GL-024 |
| **ACT** | ACT / Accounting | MgrACT_* | GL-025 |
| **MPR** | MPR / Planning | MgrMPR_* | GL-026 |
| **ITC** | IT / ITC | MgrITC_* | GL-008 s/d GL-018, GL-028 |
| **BRANCH** | Depo / BRANCH | Manager Depo | GL-027 |
| **CROSS** | Cross-cutting | Release / Business Owner | GL-001, GL-002, GL-017, GL-029, GL-030 |

---

## 3. Status yang Dipakai

| Status | Arti |
|--------|------|
| **NOT_STARTED** | Belum mulai |
| **IN_PROGRESS** | Sedang dikerjakan |
| **READY** | Selesai dan siap |
| **DONE** | Selesai divalidasi |
| **BLOCKED** | Terhambat (catat di notes) |

---

## 4. Langkah Isi Checklist

1. **Buka** `docs/governance/GO_LIVE_TASKBOARD.csv` (Excel / Google Sheets).
2. **Filter** kolom `owner_dept` = kode departemen Anda (CRM, PQP, WQS, dll.).
3. **Isi** untuk setiap task yang menjadi tanggung jawab Anda:
   - `pic_manager` = nama PIC
   - `status` = update status
   - `validated_by` = nama Anda (setelah validasi)
   - `validated_at` = tanggal validasi
   - `notes` = catatan jika ada
4. **Simpan** dan kirim ke Release Manager / koordinator GO-Live.

---

## 5. Contoh Pengisian

| task_id | owner_dept | pic_manager | status | validated_by | validated_at | notes |
|---------|------------|-------------|--------|--------------|--------------|------|
| GL-004 | CRM | StaffCRM_BGR | READY | MgrCRM_BGR | 2026-03-01 | Customer prioritas 50 sudah divalidasi |
| GL-019 | CRM | MgrCRM_BGR | DONE | MgrCRM_BGR | 2026-03-02 | UAT Sales flow PASS |

---

## 6. Referensi

- `docs/governance/GO_LIVE_TASKBOARD.csv` — File checklist
- `docs/governance/GO_LIVE_DATA_CHECKLIST.csv` — Checklist data detail
- `docs/NOTES_RMI_TERMINOLOGY.md` — Vendor vs Manufacturer, SCM, dll.
- `dashboards/AUDIT_AKSES_DEPARTEMEN.md` — Mapping akses per dept

---

*Terakhir diperbarui: 2026-02-28*
