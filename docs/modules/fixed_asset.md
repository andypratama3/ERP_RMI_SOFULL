# Fixed Asset

## Ringkasan alur

Modul **aset tetap**: pencatatan aset, **depresiasi**, audit, laporan pajak tahunan (sesuai konfigurasi).

## Halaman utama

| Path | Fungsi | Role / dept tipikal |
|------|--------|---------------------|
| `Fixed_Asset/index.php` | Pusat Fixed Asset | ACT, FIN |
| `Fixed_Asset/ops.php` | Operasi terkait aset | ACT |

## Monitoring

- Laporan & audit aset sesuai SOP ACT/FIN internal.

## Deep-dive

- Permission **FIXED_ASSET.*** di RBAC Center.
- Detail skema & flow mengikuti file modul `Fixed_Asset/` dan kebijakan akuntansi perusahaan.
