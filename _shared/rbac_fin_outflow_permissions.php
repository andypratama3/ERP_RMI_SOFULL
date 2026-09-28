<?php
declare(strict_types=1);

/**
 * Permission codes that imply FIN cash-out / pengeluaran (server must enforce MgrFIN_BGR + SYS).
 *
 * Source: docs/governance/RBAC_ALL_MODULES_V1.md (Purchases AP payment, Finance payment, Payroll post, etc.)
 * + config/rbac_permissions.php (kanonik).
 *
 * @return list<string>
 */
function rmi_rbac_fin_outflow_permission_codes(): array
{
    return [
        'PURCHASES.AP_PAYMENT_APPROVE_POST',
        'PURCHASES.AP_PAYMENT_APPROVE',
        'PURCHASES.GL_REVERSAL_APPROVE',
        'FIN.PAYMENT_APPROVE',
        'PAYROLL.APPROVE',
        'PAYROLL.EXPORT',
        // Aliases / legacy mirrors (still enforced at handler where used)
        'AP_PAYMENT_APPROVE',
    ];
}
