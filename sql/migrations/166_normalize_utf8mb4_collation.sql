-- 166_normalize_utf8mb4_collation.sql
-- Dump berasal dari MariaDB (utf8mb4_unicode_ci) sedangkan tabel hasil migration
-- dan default MySQL 8 memakai utf8mb4_0900_ai_ci. Mixed collation antar tabel yang
-- di-JOIN menghasilkan:
--   SQLSTATE[HY000] 1267 Illegal mix of collations (utf8mb4_0900_ai_ci,IMPLICIT)
--                   and (utf8mb4_unicode_ci,IMPLICIT) for operation '='
-- Contoh: absensi/admin/pins.php JOIN absensi_employee_pin.employee_code
--         = master_employees.employee_code.
--
-- Script ini menyamakan SELURUH kolom utf8mb4 ke utf8mb4_0900_ai_ci (default MySQL 8)
-- supaya tidak ada lagi pasangan JOIN lintas collation.
--
-- CATATAN: jalankan dump/backup dulu. Tabel kecil, tetapi ini mengubah definisi kolom.
--         Jalankan ulang (idempotent) aman — hanya menyalin tabel yang masih != 0900.

SET @target := 'utf8mb4_0900_ai_ci';
SET @sql := (
  SELECT GROUP_CONCAT(stmt SEPARATOR ' ')
  FROM (
    SELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @target, ';') AS stmt
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND COLLATION_NAME LIKE 'utf8mb4%'
      AND COLLATION_NAME <> @target
    GROUP BY TABLE_NAME
  ) t
);

-- foreign_key_checks dinonaktifkan sebentar: tabel anak/induk bisa punya collation berbeda.
SET @old_fk := @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- Kalau tidak ada tabel yang perlu diubah, GROUP_CONCAT NULL -> jangan siapkan statement.
SET @sql2 := IFNULL(@sql, 'DO 0');
PREPARE st FROM @sql2;
EXECUTE st;
DEALLOCATE PREPARE st;

SET FOREIGN_KEY_CHECKS = @old_fk;
