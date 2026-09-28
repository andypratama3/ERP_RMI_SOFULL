FIXED ASSET LITE (Ringkas tapi detail)
=================================

Tujuan:
- Versi lebih ringkas (file sedikit), tapi proses enterprise tetap ada:
  Acquisition, Depresiasi fiskal ID, Mutasi, Maintenance, Disposal, Audit

Struktur:
- index.php           : dashboard
- assets.php          : Asset Register (list + add/edit)  [Fase 1]
- depreciation.php    : Run & report depresiasi bulanan    [Fase 1]
- ops.php             : Acquisition / Mutasi / Maintenance / Disposal (tabs) [Fase 2-3]
- audit.php           : Audit (buat, entry, report, close) [Audit]
- _sql/fixed_asset_install.sql : buat tabel
- _inc/*              : bootstrap, layout, helpers

Install:
1) Copy folder Fixed_Asset_Lite ke ERP_RMI_SOFULL/
2) Import SQL installer: Fixed_Asset_Lite/_sql/fixed_asset_install.sql
3) Buka: http://localhost:8888/Fixed_Asset_Lite/index.php

Catatan depresiasi fiskal:
- Kelompok 1-4: SL atau DDB (saldo menurun)
- Bangunan: SL saja (DDB otomatis tidak dipakai)
- Perhitungan dibuat bulanan. Rerun periode disediakan (force re-run).
