-- 100_mobile_auth.sql
-- Mobile auth + idempotency + device token + audit + stock adjustment approvals (additive, backward-compatible)

CREATE TABLE IF NOT EXISTS mobile_refresh_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  device_id VARCHAR(120) NULL,
  ip_masked VARCHAR(80) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mobile_refresh_token_hash (token_hash),
  KEY idx_mobile_refresh_user_id (user_id),
  KEY idx_mobile_refresh_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mobile_idempotency (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  endpoint_key VARCHAR(180) NOT NULL,
  idem_key VARCHAR(120) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  response_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mobile_idem (user_id, endpoint_key, idem_key),
  KEY idx_mobile_idem_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mobile_device_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  fcm_token TEXT NOT NULL,
  device_id VARCHAR(120) NOT NULL,
  platform VARCHAR(20) NOT NULL DEFAULT 'android',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mobile_device_user (user_id, device_id),
  KEY idx_mobile_device_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mobile_audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_name VARCHAR(140) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_username VARCHAR(120) NOT NULL,
  request_id VARCHAR(80) NULL,
  endpoint VARCHAR(200) NULL,
  method VARCHAR(10) NULL,
  target_type VARCHAR(80) NULL,
  target_id VARCHAR(120) NULL,
  payload_json LONGTEXT NULL,
  ip_masked VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mobile_audit_event_name (event_name),
  KEY idx_mobile_audit_actor (actor_username),
  KEY idx_mobile_audit_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mobile_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  notif_code VARCHAR(80) NOT NULL,
  title VARCHAR(180) NOT NULL,
  body TEXT NULL,
  payload_json LONGTEXT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  KEY idx_mobile_notif_user_created (user_id, created_at),
  KEY idx_mobile_notif_user_read (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wqs_stock_adjustment_approvals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  adjustment_id BIGINT UNSIGNED NOT NULL,
  requested_by VARCHAR(120) NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by VARCHAR(120) NULL,
  approved_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  note TEXT NULL,
  UNIQUE KEY uq_wqs_adj_approval_adjustment_id (adjustment_id),
  KEY idx_wqs_adj_approval_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO api_rate_limit_policies (scope_key, window_seconds, max_hits, is_active, created_at, updated_at)
SELECT 'MOBILE_AUTH_LOGIN_IP', 600, 10, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM api_rate_limit_policies WHERE scope_key='MOBILE_AUTH_LOGIN_IP');

INSERT INTO api_rate_limit_policies (scope_key, window_seconds, max_hits, is_active, created_at, updated_at)
SELECT 'MOBILE_AUTH_LOGIN_USER', 600, 10, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM api_rate_limit_policies WHERE scope_key='MOBILE_AUTH_LOGIN_USER');

INSERT INTO api_rate_limit_policies (scope_key, window_seconds, max_hits, is_active, created_at, updated_at)
SELECT 'MOBILE_AUTH_REFRESH', 600, 30, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM api_rate_limit_policies WHERE scope_key='MOBILE_AUTH_REFRESH');

INSERT INTO api_rate_limit_policies (scope_key, window_seconds, max_hits, is_active, created_at, updated_at)
SELECT 'MOBILE_CHAT_SEND', 60, 60, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM api_rate_limit_policies WHERE scope_key='MOBILE_CHAT_SEND');
