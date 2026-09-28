# RBAC Change Rules — Maker-Checker, Audit, FIN Special

---

## 1. Maker-Checker

- Perubahan RBAC **wajib** diaudit
- SYS yang membuat perubahan RBAC
- Tidak ada auto-apply tanpa review

## 2. Audit Mandatory

- Setiap perubahan permission/user → `master_audit()` atau `erp_audit()`
- Event: `RBAC_MATRIX_SAVE`, `RBAC_USER_PERM_SAVE`, `USER_CREATE`, `USER_UPDATE`
- Meta: `request_id`, `actor_username`, `dept`, `role`, `perm_code`

## 3. FIN Special Rule (WAJIB)

- **AP_PAYMENT_APPROVE, AP_PAYMENT_POST, GL_REVERSAL_APPROVE:** HANYA MgrFIN_BGR + SYS
- **MgrFIN_BGR** = dept=FIN, level=MANAGER, office_code=BGR
- **MgrFIN cabang** (BDG, BKS, TGR, dll.): view only, ACTION approve/pay → 403
- Helper: `is_mgr_fin_bgr($user)`, `auth_require_fin_central_approver()`

## 4. Tools & RBAC Management

- **/tools/*, /rbac/*:** SYS ONLY
- ITC tidak punya akses khusus (default = dept biasa)
- Permission: `TOOLS.VIEW`, `SYSTEM.RBAC_MANAGE`, `SYSTEM.USER_MANAGE` → SYS only

## 5. Role Canonical

- **SYS, MANAGER, STAFF** — tidak boleh buat role baru tanpa konfirmasi
- ADMIN/SUPERADMIN = alias SYS
