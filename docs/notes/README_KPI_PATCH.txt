KPI ENTERPRISE PATCH (Phase 1–3)

Install:
1) Copy folder /kpi into project root.
2) Run SQL: kpi/kpi_enterprise_tables.sql
3) Open: /kpi/kpi_center.php

Notes:
- Uses project config.php ($pdo). Includes a PDO fallback if DB constants exist.
- RBAC: Manager+ can create/update & bulk status; Admin+ can soft delete.
- Dept scoping for KPI Employee reads dept_code/department from session if available.