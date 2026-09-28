-- 149_vendor_dup_index_office.sql
-- Index duplikat vendor: dukung multi-cabang (BARAKA EXPRESS BGR/SLO/SMG = bukan duplikat).
-- UNIQUE lama (vendors_name_normalized saja) di-drop.
-- UNIQUE baru: (vendors_name_normalized, office_code) — sama nama + sama cabang = duplikat.

-- Drop index lama jika ada (bisa gagal jika ada multi-branch)
DROP INDEX IF EXISTS uq_vendors_name_norm ON master_vendors;

-- Tambah composite UNIQUE: cegah duplikat nama di cabang sama
-- office_code NULL = multiple allowed (vendor tanpa cabang)
ALTER TABLE master_vendors
  ADD UNIQUE INDEX uq_vendors_name_norm_office (vendors_name_normalized, office_code);
