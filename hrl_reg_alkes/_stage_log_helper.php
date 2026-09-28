<?php
/**
 * hrl_reg_alkes/_stage_log_helper.php
 * Helper untuk log stage transition (time-in-stage).
 * Hanya logging — tidak mengubah flow user.
 */
declare(strict_types=1);

if (!function_exists('reg_alkes_stage_log_enter')) {
    /**
     * Log case masuk ke stage baru.
     * Otomatis menutup (exited_at) log stage sebelumnya jika ada.
     */
    function reg_alkes_stage_log_enter(PDO $pdo, int $case_id, int $new_stage): void {
        try {
            $chk = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_case_stage_log'");
            $chk->execute();
            if (!$chk->fetch()) return;

            // Tutup log stage sebelumnya (yang masih exited_at NULL)
            $pdo->prepare("UPDATE hrl_reg_alkes_case_stage_log SET exited_at=NOW() WHERE case_id=? AND exited_at IS NULL")
                ->execute([$case_id]);

            // Insert log stage baru
            $pdo->prepare("INSERT INTO hrl_reg_alkes_case_stage_log (case_id, stage_no, entered_at) VALUES (?, ?, NOW())")
                ->execute([$case_id, $new_stage]);
        } catch (Throwable $e) {
            // fail-soft: jangan ganggu flow utama
        }
    }
}

if (!function_exists('reg_alkes_stage_log_close_current')) {
    /**
     * Tutup log stage saat ini (saat case di-close).
     */
    function reg_alkes_stage_log_close_current(PDO $pdo, int $case_id): void {
        try {
            $chk = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_case_stage_log'");
            $chk->execute();
            if (!$chk->fetch()) return;

            $pdo->prepare("UPDATE hrl_reg_alkes_case_stage_log SET exited_at=NOW() WHERE case_id=? AND exited_at IS NULL")
                ->execute([$case_id]);
        } catch (Throwable $e) {
            // fail-soft
        }
    }
}
