<?php
declare(strict_types=1);

/**
 * Ensure auth_login_attempts table exists (migration 072).
 * Call before LoginThrottle if migration may not have run.
 */
function schema_ensure_auth_login_attempts(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `auth_login_attempts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip_address` VARCHAR(45) NOT NULL,
            `username` VARCHAR(120) NOT NULL,
            `failed_count` INT NOT NULL DEFAULT 0,
            `last_attempt_at` DATETIME NULL,
            `locked_until` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_auth_attempt_ip_user` (`ip_address`, `username`),
            KEY `idx_auth_attempt_locked` (`locked_until`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Ensure auth_mfa_bypass_tickets table and columns exist (migrations 080, 081).
 */
function schema_ensure_auth_mfa_bypass_tickets(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `auth_mfa_bypass_tickets` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `reason` TEXT NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_mfa_bypass_user` (`user_id`),
            KEY `idx_mfa_bypass_exp` (`expires_at`),
            KEY `idx_mfa_bypass_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // ignore
    }
    $cols = [];
    try {
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_mfa_bypass_tickets'");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $cols[$c] = true;
        }
    } catch (Throwable $e) {
        return;
    }
    $add = [
        'status'      => "VARCHAR(20) NOT NULL DEFAULT 'PENDING' AFTER `expires_at`",
        'requested_by'=> "INT NULL AFTER `created_by`",
        'approved_by' => "INT NULL AFTER `requested_by`",
        'approved_at' => "DATETIME NULL AFTER `approved_by`",
    ];
    foreach ($add as $col => $ddl) {
        if (empty($cols[$col])) {
            try {
                $pdo->exec("ALTER TABLE `auth_mfa_bypass_tickets` ADD COLUMN `{$col}` {$ddl}");
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
}

/**
 * Ensure MFA columns exist in master_system_login (idempotent).
 * Call before any MFA-related query if migration 072 may not have run.
 */
function schema_ensure_mfa_columns(PDO $pdo): void {
    $cols = [];
    try {
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'master_system_login'");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $cols[$c] = true;
        }
    } catch (Throwable $e) {
        return;
    }
    $add = [
        'mfa_enabled'          => "tinyint(1) NOT NULL DEFAULT 0 AFTER `status`",
        'mfa_secret'           => "varchar(128) NULL AFTER `mfa_enabled`",
        'mfa_confirmed_at'     => "datetime NULL AFTER `mfa_secret`",
        'mfa_backup_codes_hash'=> "longtext NULL AFTER `mfa_confirmed_at`",
    ];
    foreach ($add as $col => $ddl) {
        if (empty($cols[$col])) {
            try {
                $pdo->exec("ALTER TABLE `master_system_login` ADD COLUMN `{$col}` {$ddl}");
            } catch (Throwable $e) {
                // ignore duplicate / race
            }
        }
    }
}

/**
 * Ensure auth_webauthn_credentials table exists (migration 124).
 */
function schema_ensure_webauthn_credentials(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `auth_webauthn_credentials` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `credential_id` VARCHAR(512) NOT NULL,
            `public_key` TEXT NOT NULL,
            `sign_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `aaguid` VARCHAR(64) NULL,
            `friendly_name` VARCHAR(120) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_webauthn_cred_id` (`credential_id`(255)),
            KEY `idx_webauthn_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // ignore
    }
}
