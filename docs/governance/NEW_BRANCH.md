# New Branch — Tambah Cabang, Office Code, Depo

**Lokasi lengkap:** [ONBOARDING.md](../onboarding/ONBOARDING.md) § 2. Office / Depo Baru

---

## Ringkasan

1. **Tambah office di Master:** `master/master_office.php` (atau modul master data)
2. **Code 3 huruf:** BGR, BDG, BKS, TGR, SLO, SMG, JGY, KAL, SYS
3. **User mapping:** Assign user ke office baru via Master System Login
4. **Stock/WQS:** Cek `wqs_stock_default_office()` di `stock/_stock_office_helper.php` — default fallback BGR

## Office Code Valid

| Code | Kota |
|------|------|
| BGR | Bogor (depo utama) |
| BDG | Bandung |
| BKS | Bekasi |
| TGR | Tangerang |
| SLO | Solo |
| SMG | Semarang |
| JGY | Yogyakarta |
| KAL | Kalimantan |
| SYS | System |

**Default office** saat kosong: **BGR** (bukan HO).
