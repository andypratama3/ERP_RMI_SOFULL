-- 083_seed_mfa_policy_baseline.sql
-- Seed baseline MFA policy list for Manager/Staff rollout (idempotent)

-- Recommended staged baseline:
-- 1) MANAGER/*          => require MFA
-- 2) STAFF/(FIN,ACT,SYS)=> require MFA
-- 3) STAFF/*            => optional (not required) as fallback

INSERT INTO auth_mfa_policies (role_code, dept_code, require_mfa, is_active, created_at, updated_at)
SELECT 'MANAGER', '*', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM auth_mfa_policies WHERE role_code='MANAGER' AND dept_code='*'
);

INSERT INTO auth_mfa_policies (role_code, dept_code, require_mfa, is_active, created_at, updated_at)
SELECT 'STAFF', 'FIN', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM auth_mfa_policies WHERE role_code='STAFF' AND dept_code='FIN'
);

INSERT INTO auth_mfa_policies (role_code, dept_code, require_mfa, is_active, created_at, updated_at)
SELECT 'STAFF', 'ACT', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM auth_mfa_policies WHERE role_code='STAFF' AND dept_code='ACT'
);

INSERT INTO auth_mfa_policies (role_code, dept_code, require_mfa, is_active, created_at, updated_at)
SELECT 'STAFF', 'SYS', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM auth_mfa_policies WHERE role_code='STAFF' AND dept_code='SYS'
);

INSERT INTO auth_mfa_policies (role_code, dept_code, require_mfa, is_active, created_at, updated_at)
SELECT 'STAFF', '*', 0, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM auth_mfa_policies WHERE role_code='STAFF' AND dept_code='*'
);
