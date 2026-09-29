-- 167_sales_do_backfill_fin_time.sql
-- Backfill KOLOM WAKTU saja dari bukti audit yang sudah ada.
-- Tidak mengarang pelaku, tidak menyentuh POD/TTD/foto/video.
--
-- Masalah: sales_do.fin_updated_at tidak pernah ditulis oleh fin_do_tasks.php
-- (aksi save/paid hanya mengisi fin_updated_by + last_updated_at), sehingga
-- DO berstatus paid/fin_done tampil "FIN Menunggu" di print DO bila tidak ada
-- baris audit fin_done. Bukti verifikasi FIN yang sah untuk DO paid adalah
-- transisi wait_payment -> paid oleh dept FIN di sales_do_audit.
--
-- Idempoten: UPDATE hanya menyentuh baris yang targetnya masih NULL, dan
-- sumbernya adalah MIN(created_at) audit yang sama setiap dijalankan ulang.
-- Aman dijalankan berulang via run_full_suite.php / CI.

SET FOREIGN_KEY_CHECKS=0;

UPDATE sales_do d
JOIN (
    SELECT do_id, MIN(created_at) AS fin_at
    FROM sales_do_audit
    WHERE LOWER(TRIM(COALESCE(status_to, ''))) = 'paid'
      AND UPPER(TRIM(COALESCE(actor_dept, ''))) = 'FIN'
    GROUP BY do_id
) a ON a.do_id = d.id
SET d.fin_updated_at = a.fin_at
WHERE d.fin_updated_at IS NULL;

SET FOREIGN_KEY_CHECKS=1;
