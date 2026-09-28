ERP_RMI_SOFULL — HRL_REG_ALKES Patch (v1)

Tujuan:
- Menambahkan helper safe_filename() & CSRF helper pada hrl_reg_alkes/reg_alkes.php
- Memastikan semua action POST (upload_output, upload_accessory, preview, import) divalidasi CSRF token
- Menghilangkan flag "Upload (no safe_filename)" pada enterprise_audit untuk modul hrl_reg_alkes

File yang diubah:
- hrl_reg_alkes/reg_alkes.php

Cara apply:
1) Backup folder: hrl_reg_alkes/
2) Extract ZIP ini ke root project ERP_RMI_SOFULL (selevel dengan folder master/, payroll/, hrl_reg_alkes/, dst).
3) Replace file jika diminta.

Catatan:
- Token CSRF dibuat di session: $_SESSION['csrf_token'].
- Jika ada form custom lain yang POST ke reg_alkes.php dan belum menyertakan csrf_token, tambahkan hidden input csrf_token.
