# Reset ERP untuk Go-Live — Panduan Lengkap

**Tool:** `tools/ops/reset_for_golive.php`  
**Wajib:** SYS only · Jalankan dari NAS · Backup otomatis sebelum reset

---

## 3 Mode Reset

| Mode | Apa yang Dihapus | Apa yang Dipertahankan |
|------|-----------------|------------------------|
| `--mode=full` | SEMUA (schema + data + config) | — (fresh install) |
| `--mode=transactions` | Semua transaksi bisnis | Master data, user, RBAC, config |
| `--mode=testdata` | Transaksi + data demo/uji | Master data real, user, RBAC |

---

## Tabel yang Dihapus per Mode

### Mode `transactions` & `testdata` — menghapus:

**Purchases (PR→PO→GR→AP):**
`purchases_po`, `purchases_po_items`, `purchases_invoice_ap`, `purchases_payment_ap`, `wqs_pr`, `wqs_pr_items` dll.

**Sales (SO→DO):**
`sales_do`, `sales_do_items`, `sales_do_audit`, `tax_invoices` dll.

**Stock / WQS:**
`wqs_stock`, `wqs_incoming`, `wqs_picking`, `wqs_allocations`, `wqs_stock_opname`, `wqs_stock_transfer`, `wqs_stock_adjustments` dll.

**Finance / GL:**
`gl_journal_headers`, `gl_journal_lines`, `bank_statements`, `bank_reconciliations` dll.

**Payroll:** `payroll_runs`, `payroll_run_items`, `payroll_loans`

**Absensi:** `absensi_logs`, `absensi_requests`, `absensi_audit`

**MPR:** `mpr_plans`, `mpr_visits`, `mpr_progress`, `mpr_budget_requests`

**Audit/Log:** `system_audit_logs`, `erp_audit_log`, `auth_login_attempts`

### Mode `transactions` & `testdata` — **TETAP ada:**

- `master_customers`, `master_products`, `master_vendors`, `master_employees`
- `master_system_login` (user login)
- `rbac_*` (semua RBAC rules)
- `system_config`, `absensi_settings`, `gl_accounts`, `gl_mappings`
- `payroll_salary_matrix`, `payroll_employee_settings`
- `chat_channels` (tapi pesan dihapus)
- `fa_assets` (aset tetap)

---

## Cara Penggunaan

### Persiapan

```bash
ssh ke NAS
cd /volume4/web/ERP_RMI_SOFULL
```

### Dry-Run Dulu (Sangat Disarankan!)

```bash
# Lihat apa yang akan terjadi tanpa eksekusi:
./tools/nas/erp.sh php tools/ops/reset_for_golive.php \
  --mode=transactions \
  --new-admin-password=RahasiaKuat123! \
  --i-understand-this-is-irreversible \
  --dry-run
```

### Eksekusi Nyata

```bash
# Mode A: Hapus transaksi saja (PALING AMAN untuk go-live)
./tools/nas/erp.sh php tools/ops/reset_for_golive.php \
  --mode=transactions \
  --new-admin-password=RahasiaKuat123! \
  --i-understand-this-is-irreversible

# Mode B: Hapus data uji + transaksi
./tools/nas/erp.sh php tools/ops/reset_for_golive.php \
  --mode=testdata \
  --new-admin-password=RahasiaKuat123! \
  --i-understand-this-is-irreversible

# Mode C: Full reset (PALING BERSIH, tapi hapus SEMUA termasuk master!)
./tools/nas/erp.sh php tools/ops/reset_for_golive.php \
  --mode=full \
  --new-admin-password=RahasiaKuat123! \
  --i-understand-this-is-irreversible
```

---

## Checklist Sebelum Reset

- [ ] Semua user sudah selesai UAT / uji coba
- [ ] Backup terakhir sudah diverifikasi (`tools/backup_verify.php`)
- [ ] Password baru sudah disiapkan (min 10 karakter)
- [ ] Tim sudah diberi tahu: "ERP akan direset untuk go-live"
- [ ] Dry-run sudah dijalankan dan hasilnya sudah direview

---

## Checklist Setelah Reset

- [ ] Login ke ERP dengan akun `admin` + password baru
- [ ] Buka RBAC Center → verifikasi permission semua dept
- [ ] Buka Master → tambah user real per dept & office
- [ ] Set up absensi settings (lokasi kantor, jam kerja)
- [ ] Input master data: customer, produk, vendor (jika mode=full)
- [ ] Jalankan smoke test: `./tools/nas/erp.sh php tools/qa/smoke_http.php --strict`

---

## Artifact Reset

Setelah reset, file ini otomatis dibuat:

```
storage/logs/reset_for_golive_last.json
```

---

## Kapan Pakai Mode Apa?

| Situasi | Mode yang Tepat |
|---------|----------------|
| UAT/uji selesai, master data sudah benar, mau mulai dari nol transaksi | `transactions` |
| Mau hapus data demo/palsu, pertahankan customer/produk/vendor real | `testdata` |
| Install ulang total dari awal | `full` |
| Rollback ke kondisi backup | Gunakan `tools/restore_now.sh` |
