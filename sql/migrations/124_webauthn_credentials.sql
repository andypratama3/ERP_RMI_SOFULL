-- 124_webauthn_credentials.sql
-- WebAuthn / Passkeys (Face ID, Windows Hello, security keys) credentials storage

CREATE TABLE IF NOT EXISTS `auth_webauthn_credentials` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
