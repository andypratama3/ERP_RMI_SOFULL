<?php
// _shared/enterprise_guard.php
// Minimal Enterprise Guard (dept + role gate)
// - Designed to be drop-in & low impact.
// - Uses session fields from master/auth.php: department, role, level.
// - Privileged (SUPERADMIN/ADMIN/SYS) bypass.
//
// NOTE: You can disable enforcement by defining ERP_ROLE_GATE_ENABLED=false
// before including this file.

declare(strict_types=1);




// RMI_GUARD_DIRECT_ACCESS
if (basename(__FILE__) === basename($_SERVER["SCRIPT_FILENAME"] ?? "")) {
    $auth = __DIR__ . "/../master/auth.php";
    if (is_file($auth)) { require_once $auth; }
    if (function_exists("require_login")) { require_login(); }
    http_response_code(403);
    exit("Forbidden");
}

if (!defined('ERP_ROLE_GATE_ENABLED')) {
    define('ERP_ROLE_GATE_ENABLED', true);
}

if (!function_exists('eg_norm')) {
    function eg_norm(string $s): string {
        $s = trim($s);
        $s = strtoupper($s);
        return $s;
    }
}

if (!function_exists('eg_role')) {
    function eg_role(): string { return eg_norm((string)($_SESSION['role'] ?? '')); }
}
if (!function_exists('eg_level')) {
    function eg_level(): string { return eg_norm((string)($_SESSION['level'] ?? '')); }
}
if (!function_exists('eg_dept')) {
    function eg_dept(): string { return eg_norm((string)($_SESSION['department'] ?? '')); }
}

if (!function_exists('eg_is_privileged')) {
    function eg_is_privileged(): bool {
        $role  = eg_role();
        $level = eg_level();
        // SYS = privileged canonical. ADMIN/SUPERADMIN = backward-compat.
        return in_array($role, ['SYS','ADMIN','SUPERADMIN'], true)
            || in_array($level, ['SYS','ADMIN','SUPERADMIN'], true);
    }
}

if (!function_exists('eg_is_manager')) {
    function eg_is_manager(): bool {
        $role  = eg_role();
        $level = eg_level();
        return $role === 'MANAGER' || $level === 'MANAGER';
    }
}

if (!function_exists('eg_user_group')) {
    // Normalize dept to a smaller set used in guard decisions
    function eg_user_group(): string {
        $d = eg_dept();
        if ($d === 'PQP') return 'PQP';
        if (in_array($d, ['HRL','LEGAL'], true)) return 'HRL';
        if ($d === 'ITC') return 'ITC';
        return $d !== '' ? $d : 'UNKNOWN';
    }
}

if (!function_exists('eg_require_depts')) {
    function eg_require_depts(array $depts, string $msg = 'Akses ditolak'): void {
        if (!ERP_ROLE_GATE_ENABLED) return;
        if (eg_is_privileged()) return;

        $allowed = array_map(fn($x) => eg_norm((string)$x), $depts);
        $dept = eg_dept();
        if (!in_array($dept, $allowed, true)) {
            http_response_code(403);
            echo "<h3>" . htmlspecialchars($msg) . "</h3>";
            echo "<p>Dept kamu: <b>" . htmlspecialchars($dept) . "</b></p>";
            echo "<p>Dept yang boleh: " . htmlspecialchars(implode(', ', $allowed)) . "</p>";
            exit;
        }
    }
}

if (!function_exists('eg_can_access_reg_alkes')) {
    function eg_can_access_reg_alkes(): bool {
        if (!ERP_ROLE_GATE_ENABLED) return true;
        if (eg_is_privileged()) return true;
        return in_array(eg_user_group(), ['PQP','HRL','ITC'], true);
    }
}

if (!function_exists('eg_can_access_manufactures_docs')) {
    function eg_can_access_manufactures_docs(): bool {
        if (!ERP_ROLE_GATE_ENABLED) return true;
        if (eg_is_privileged()) return true;
        return in_array(eg_user_group(), ['PQP','HRL','ITC'], true);
    }
}

if (!function_exists('eg_case_stage_owner_group')) {
    // Ownership of the work being completed at each stage
    function eg_case_stage_owner_group(int $stageNo): string {
        // PQP handles: 1,2,3,5,6,11,12
        $pqpStages = [1,2,3,5,6,11,12];
        return in_array($stageNo, $pqpStages, true) ? 'PQP' : 'HRL';
    }
}

if (!function_exists('eg_can_case_next_stage')) {
    // For next_stage actions: who is allowed to click "Next" from current stage
    function eg_can_case_next_stage(int $currentStage): bool {
        if (!ERP_ROLE_GATE_ENABLED) return true;
        if (eg_is_privileged()) return true;

        $owner = eg_case_stage_owner_group($currentStage);
        $g = eg_user_group();

        if ($owner === 'PQP') return $g === 'PQP';
        // HRL stages can be handled by HRL or ITC (support)
        return in_array($g, ['HRL','ITC'], true);
    }
}

if (!function_exists('eg_can_case_set_stage')) {
    // For update_case that sets stage_no explicitly
    function eg_can_case_set_stage(int $targetStage): bool {
        if (!ERP_ROLE_GATE_ENABLED) return true;
        if (eg_is_privileged()) return true;

        $owner = eg_case_stage_owner_group($targetStage);
        $g = eg_user_group();

        if ($owner === 'PQP') return $g === 'PQP';
        return in_array($g, ['HRL','ITC'], true);
    }
}

if (!function_exists('eg_can_case_close')) {
    function eg_can_case_close(): bool {
        if (!ERP_ROLE_GATE_ENABLED) return true;
        if (eg_is_privileged()) return true;

        // Close/Reopen is HRL domain
        return in_array(eg_user_group(), ['HRL','ITC'], true);
    }
}

if (!function_exists('eg_can_upload_case_doc')) {
    function eg_can_upload_case_doc(string $docType): bool {
        if (!ERP_ROLE_GATE_ENABLED) return true;
        if (eg_is_privileged()) return true;

        $g = eg_user_group();
        $dt = eg_norm($docType);

        if ($g === 'PQP') {
            // Legal document NIE should not be uploaded by PQP
            if ($dt === 'NIE') return false;
            return true;
        }
        if (in_array($g, ['HRL','ITC'], true)) return true;
        return false;
    }
}

if (!function_exists('eg_can_import_sku')) {
    function eg_can_import_sku(): bool {
        if (!ERP_ROLE_GATE_ENABLED) return true;
        if (eg_is_privileged()) return true;
        // Import SKU to master_products is HRL/Legal operation
        return in_array(eg_user_group(), ['HRL','ITC'], true);
    }
}

if (!function_exists('eg_can_upload_manufacture_bucket')) {
    function eg_can_upload_manufacture_bucket(string $bucket): bool {
        if (!ERP_ROLE_GATE_ENABLED) return true;
        if (eg_is_privileged()) return true;

        $g = eg_user_group();
        $b = eg_norm($bucket);

        if ($b === 'HRL') {
            return in_array($g, ['HRL','ITC'], true);
        }
        // INTERNAL
        return in_array($g, ['PQP','HRL','ITC'], true);
    }
}

if (!function_exists('eg_can_delete_manufacture_doc')) {
    function eg_can_delete_manufacture_doc(): bool {
        if (!ERP_ROLE_GATE_ENABLED) return true;
        if (eg_is_privileged()) return true;

        // Delete is restricted (manager recommended)
        return in_array(eg_user_group(), ['HRL','ITC'], true) && eg_is_manager();
    }
}
