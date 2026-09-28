# Stock Transfer — Bukti Otentik

## Ringkasan

Modul Transfer Antar Kantor (`stock/wqs_stock_transfer.php`) dengan bukti otentik sama seperti WQS DO Tasks & WQS Incoming:

1. **Foto kartu stok** — sebelum & sesudah (pengirim dan penerima)
2. **Foto fisik produk** — detail produk saat keluar (pengirim) dan saat masuk (penerima)

## Flow

| Status | Aksi | Bukti Wajib |
|--------|------|-------------|
| DRAFT | Buat transfer, pilih items | — |
| SENT | Kirim (reduce stock pengirim) | Foto kartu stok sebelum & sesudah + foto fisik produk keluar |
| RECEIVED | Terima (add stock penerima) | Foto kartu stok sebelum & sesudah + foto fisik produk masuk |

## Tabel

- **wqs_stock_transfer** — header (transfer_code, from_office, to_office, status, wqs_stock_before, wqs_stock_after, foto_fisik_keluar, wqs_stock_before_penerima, wqs_stock_after_penerima, foto_fisik_masuk)
- **wqs_stock_transfer_items** — detail (product_id, sku, qty)
- **wqs_stock_transfer_attachments** — optional multiple foto fisik (FISIK_KELUAR, FISIK_MASUK)

## Cross-reference di Report Opname

- **Selisih minus** — Transfer keluar (from_office = kantor opname)
- **Selisih plus** — Transfer masuk (to_office = kantor opname)
- Status foto = ✓ jika kartu stok + foto fisik lengkap

## Migration

```bash
mysql -u root -p erp_rmi_sofull < sql/migrations/131_wqs_stock_transfer.sql
```

## Verifikasi

1. Buat transfer DRAFT (dari kantor A ke B)
2. Kirim — upload foto kartu stok + foto fisik keluar → stock A berkurang
3. Terima — upload foto kartu stok + foto fisik masuk → stock B bertambah
4. Cek Report Opname — transfer muncul di cross-reference untuk selisih terkait
