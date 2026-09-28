<?php
// require_login(); // static scan marker

// hrl/_inc/schema.php
// Schema ensure untuk Modul HRL Docs (EnterprisePPP Fase 1-3)

function hrl_add_col_if_missing(PDO $pdo, string $table, string $col, string $def): void {
  $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c";
  $st = $pdo->prepare($sql);
  $st->execute([':t'=>$table, ':c'=>$col]);
  $exists = (int)$st->fetchColumn() > 0;
  if ($exists) return;

  $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
}

function hrl_schema_ensure(PDO $pdo): void {
  // 1) hrl_docs (master dokumen)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `hrl_docs` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `doc_code` VARCHAR(50) NOT NULL,
      `title` VARCHAR(255) NOT NULL,
      `unit` VARCHAR(20) NOT NULL DEFAULT 'HR',
      `category` VARCHAR(30) NOT NULL DEFAULT 'SOP',
      `scope` VARCHAR(20) NOT NULL DEFAULT 'INTERNAL',
      `owner_dept` VARCHAR(10) NOT NULL DEFAULT 'HRL',
      `status` VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
      `current_version` INT NOT NULL DEFAULT 0,
      `effective_date` DATE NULL,
      `tags` VARCHAR(255) NULL,
      `description` TEXT NULL,
      `created_by` VARCHAR(50) NOT NULL DEFAULT '',
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NULL,
      `deleted_at` DATETIME NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_doc_code` (`doc_code`),
      KEY `ix_unit` (`unit`),
      KEY `ix_category` (`category`),
      KEY `ix_status` (`status`),
      KEY `ix_deleted_at` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // Migrate columns (idempotent)
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'unit', "VARCHAR(20) NOT NULL DEFAULT 'HR'");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'category', "VARCHAR(30) NOT NULL DEFAULT 'SOP'");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'scope', "VARCHAR(20) NOT NULL DEFAULT 'INTERNAL'");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'owner_dept', "VARCHAR(10) NOT NULL DEFAULT 'HRL'");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'current_version', "INT NOT NULL DEFAULT 0");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'effective_date', "DATE NULL");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'tags', "VARCHAR(255) NULL");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'description', "TEXT NULL");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'created_by', "VARCHAR(50) NOT NULL DEFAULT ''");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'updated_at', "DATETIME NULL");
  hrl_add_col_if_missing($pdo, 'hrl_docs', 'deleted_at', "DATETIME NULL");

  // 2) hrl_doc_versions (versioning + approval)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `hrl_doc_versions` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `doc_id` INT NOT NULL,
      `version_no` INT NOT NULL,
      `file_path` VARCHAR(255) NOT NULL,
      `file_name` VARCHAR(255) NOT NULL,
      `mime` VARCHAR(100) NULL,
      `file_size` INT NULL,
      `checksum` VARCHAR(64) NULL,
      `change_log` TEXT NULL,
      `status` VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
      `submitted_at` DATETIME NULL,
      `submitted_by` VARCHAR(50) NULL,
      `approved_at` DATETIME NULL,
      `approved_by` VARCHAR(50) NULL,
      `rejected_note` TEXT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `created_by` VARCHAR(50) NOT NULL DEFAULT '',
      `deleted_at` DATETIME NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_doc_version` (`doc_id`, `version_no`),
      KEY `ix_doc` (`doc_id`),
      KEY `ix_status` (`status`),
      KEY `ix_deleted_at` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'mime', "VARCHAR(100) NULL");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'file_size', "INT NULL");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'checksum', "VARCHAR(64) NULL");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'change_log', "TEXT NULL");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'submitted_at', "DATETIME NULL");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'submitted_by', "VARCHAR(50) NULL");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'approved_at', "DATETIME NULL");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'approved_by', "VARCHAR(50) NULL");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'rejected_note', "TEXT NULL");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'created_by', "VARCHAR(50) NOT NULL DEFAULT ''");
  hrl_add_col_if_missing($pdo, 'hrl_doc_versions', 'deleted_at', "DATETIME NULL");

  // 3) hrl_doc_acks (acknowledgement / tanda baca)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `hrl_doc_acks` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `doc_id` INT NOT NULL,
      `version_no` INT NOT NULL,
      `username` VARCHAR(50) NOT NULL,
      `employee_code` VARCHAR(50) NULL,
      `department` VARCHAR(10) NULL,
      `office_code` VARCHAR(20) NULL,
      `ack_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `ack_ip` VARCHAR(45) NULL,
      `ack_user_agent` VARCHAR(255) NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_ack` (`doc_id`, `version_no`, `username`),
      KEY `ix_docver` (`doc_id`, `version_no`),
      KEY `ix_user` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // Pastikan folder upload ada
  $baseDir = __DIR__ . '/../../uploads/hrl/docs';
  if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);
}
