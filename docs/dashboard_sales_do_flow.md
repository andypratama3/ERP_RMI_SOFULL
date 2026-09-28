# Flow Dashboard Rekap — Sales DO sebagai Contoh

## Pola Umum (Reference)

```
[Sumber Data] → [Rekap] → [Dashboard]
     ↓              ↓           ↓
  Query DB    Validasi &    Agregat
             Detail list   display
```

**Sales DO** dipakai sebagai **contoh/reference** untuk rekap lain (AP, GL, Target).

---

## Sales DO (Contoh Lengkap)

### Alur Data

```
sales_do (tabel sumber)
    ↓
sales_do_rekap (jembatan validasi)
    ↓ tampilkan DO yang sama dengan logic dashboard
dashboard_detail (tampilan agregat)
```

### Fitur Sales Rekap

1. **Filter Periode**: Office, Segment, Bulan, Tahun, As of Date, Tipe (Penjualan/Piutang Baru/Piutang Lama)
2. **Semua periode**: Checkbox untuk tampil konsisten dengan List DO
3. **Revenue only**: Parameter `revenue_only=1` dari drill dashboard — angka match dengan Pencapaian
4. **Tabel**:
   - Detail DO (di atas) — kolom sama dengan List DO
   - Rekap per Office
   - Rekap per Segment
   - Rekap per Tanggal
5. **Link ke sumber**: DO Code, Grand Total → `sales_do_view.php`

### Status Filter

- **Revenue only** (drill dari dashboard): `delivered`, `wait_payment`, `paid`, `fin_done`
- **Setelah CRM Submit** (default): `crm_to_wqs`, `sent_wqs`, `wqs_done`, `delivered`, dll.

### File

| File | Fungsi |
|------|--------|
| `app/Dashboard/DashboardDetailService.php` | salesAggregates, getSalesDoRowsForRekap |
| `dashboards/finance/sales_do_rekap.php` | Rekap Sales DO |
| `dashboards/finance/dashboard_detail.php` | Link drill ke rekap |
| `sales/sales_do_view.php` | Detail DO |
| `sales/sales_do.php` | List DO (sumber) |

---

## Rekap Lain (AP, GL, Target)

Mengikuti pola Sales:

- Filter Periode di atas
- Detail table dengan link ke sumber
- Kembali ke Dashboard

| Rekap | Sumber | Link ke |
|-------|--------|---------|
| ap_rekap | purchases_invoice_ap | purchases_invoice_ap_edit.php |
| gl_rekap | gl_journal_headers/lines | fin_gl_auto.php |
| target_rekap | kpi_targets | - |
