-- 159: Pisahkan grouping RBAC Center — HRL Process vs HRL inti
-- Permission code tidak berubah (HRL.PROCESS_*); hanya kolom module untuk urutan/blok UI.
-- Setelah migration: jalankan Sync Permissions di RBAC Center (upsert) agar selaras dengan config.

UPDATE rbac_permissions
SET module = 'HRL_PROCESS'
WHERE perm_code IN (
  'HRL.PROCESS_VIEW',
  'HRL.PROCESS_CREATE',
  'HRL.PROCESS_EDIT',
  'HRL.PROCESS_DELETE'
);
