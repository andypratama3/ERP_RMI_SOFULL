-- 146_system_audit_logs_index.sql
-- Add index for common query pattern: WHERE module=? ORDER BY created_at DESC
-- Run: mysql -u user -p db < sql/migrations/146_system_audit_logs_index.sql

ALTER TABLE system_audit_logs ADD KEY idx_module_created (module, created_at);
