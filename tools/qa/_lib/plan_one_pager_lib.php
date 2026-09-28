<?php
declare(strict_types=1);

require_once __DIR__ . '/pipeline_plans_lib.php';
require_once __DIR__ . '/pipeline_steps.php';

if (!function_exists('pop_root')) {
    function pop_root(): string
    {
        return ppl_root();
    }
}

if (!function_exists('pop_mask')) {
    function pop_mask(string $v): string
    {
        return function_exists('ppl_mask') ? ppl_mask($v) : $v;
    }
}

/** Profile executor exists (returns non-ERR step list) */
if (!function_exists('pop_profile_implemented')) {
    function pop_profile_implemented(string $profile): bool
    {
        if ($profile === '') return true;
        if ($profile === 'full') return true;
        $steps = ppl_profile_steps($profile, 'pop-check', []);
        if ($steps === []) return false;
        $first = $steps[0] ?? [];
        $name = (string)($first['name'] ?? '');
        return !str_starts_with($name, 'ERR_PROFILE_NOT_IMPLEMENTED');
    }
}

/** ETA heuristics (range string) */
if (!function_exists('pop_eta_for_profile')) {
    function pop_eta_for_profile(string $profile, string $planId): string
    {
        $map = [
            'pr_gate' => '1–3 menit',
            'pr_gate_strict_http' => '2–5 menit',
            'pr_gate_security_lite' => '2–6 menit',
            'pr_gate_migration_hygiene' => '2–5 menit',
            'nightly_staging_full' => '6–15 menit',
            'release_candidate_staging' => '10–25 menit',
            'ui_regression_optional' => '5–20 menit',
            'mobile_contract_gate' => '2–8 menit',
            'ops_freshness_gate' => '< 1 menit',
            'full' => '3–15 menit',
        ];
        if ($planId === 'full_staging_00_16') return '20–90 menit';
        if ($planId === 'prod_stage16_final') return '15–45 menit';
        return $map[$profile] ?? '3–15 menit';
    }
}

/** When to use (non-IT) */
if (!function_exists('pop_when_for_plan')) {
    function pop_when_for_plan(array $plan): string
    {
        $id = (string)($plan['id'] ?? '');
        $profile = strtolower(trim((string)($plan['profile'] ?? '')));
        if (str_starts_with($id, 'stage') && str_ends_with($id, '_staging_draft')) return 'Cek stage spesifik (dev/QA)';
        if ($id === 'stage16_staging_strict') return 'Cek stage 16 ketat';
        if ($id === 'staging_gate_before_merge') return 'PR kecil';
        if ($id === 'staging_gate_before_merge_strict_http') return 'PR besar / banyak ubah route/API';
        if ($id === 'staging_gate_security_lite') return 'Perubahan security/dependency';
        if ($id === 'staging_gate_migration_hygiene') return 'Perubahan DB/migration';
        if ($id === 'nightly_staging_full_gate') return 'Nightly baseline';
        if ($id === 'staging_release_candidate') return 'Release candidate';
        if ($id === 'ui_regression_optional_playwright') return 'UI changes besar (optional)';
        if ($id === 'mobile_contract_gate') return 'Mobile API changes';
        if ($id === 'ops_freshness_gate') return 'Ops cek cepat freshness';
        if ($id === 'full_staging_00_16') return 'Full run 00→16 (stop-on-fail)';
        if ($id === 'prod_stage16_final') return 'Cutover final production';
        return 'Sesuai kebutuhan';
    }
}

/** Who uses (DEV/QA/OPS/RELEASE) */
if (!function_exists('pop_who_for_plan')) {
    function pop_who_for_plan(array $plan): array
    {
        $id = (string)($plan['id'] ?? '');
        $env = strtolower((string)($plan['env'] ?? ''));
        if ($id === 'prod_stage16_final') return ['RELEASE', 'QA'];
        if ($id === 'staging_release_candidate') return ['RELEASE', 'QA'];
        if ($id === 'ops_freshness_gate') return ['OPS'];
        if ($id === 'nightly_staging_full_gate') return ['OPS', 'QA'];
        if (str_starts_with($id, 'staging_gate_')) return ['DEV', 'QA'];
        if ($id === 'mobile_contract_gate' || $id === 'ui_regression_optional_playwright') return ['DEV', 'QA'];
        if ($id === 'full_staging_00_16') return ['QA', 'RELEASE'];
        return ['DEV', 'QA'];
    }
}

/** Outputs (ringkas) */
if (!function_exists('pop_outputs_for_plan')) {
    function pop_outputs_for_plan(array $plan): string
    {
        return 'PASS/FAIL + laporan (plan_<id>_<run>.json, .md, .html)';
    }
}

/** Notes (dependencies) */
if (!function_exists('pop_notes_for_plan')) {
    function pop_notes_for_plan(array $plan): string
    {
        $id = (string)($plan['id'] ?? '');
        $req = (bool)($plan['require_tools_base_url'] ?? false);
        if ($id === 'ops_freshness_gate') return 'Tidak butuh TOOLS_BASE_URL';
        if ($id === 'prod_stage16_final') return 'Butuh RFC + confirm (--i-understand --confirm=...)';
        if ($id === 'staging_release_candidate') return 'Butuh --rfc=RFC-XXXX, TOOLS_BASE_URL';
        if ($req) return 'Butuh TOOLS_BASE_URL';
        return '';
    }
}

/** Order: PR → Nightly → RC → Production Final → Ops */
if (!function_exists('pop_sort_order')) {
    function pop_sort_order(string $id): int
    {
        $order = [
            'stageXX_staging_draft (00–16)' => 0,
            'stage16_staging_strict' => 1,
            'staging_gate_before_merge' => 2,
            'staging_gate_before_merge_strict_http' => 3,
            'staging_gate_security_lite' => 4,
            'staging_gate_migration_hygiene' => 5,
            'nightly_staging_full_gate' => 6,
            'staging_release_candidate' => 7,
            'ui_regression_optional_playwright' => 8,
            'mobile_contract_gate' => 9,
            'ops_freshness_gate' => 10,
            'full_staging_00_16' => 11,
            'prod_stage16_final' => 12,
        ];
        return $order[$id] ?? 99;
    }
}

/** Build one-pager rows, grouped */
if (!function_exists('pop_build_rows')) {
    function pop_build_rows(array $plans): array
    {
        $stageDraft = [];
        $rows = [];
        foreach ($plans as $p) {
            $id = (string)($p['id'] ?? '');
            $profile = strtolower(trim((string)($p['profile'] ?? '')));
            $blocked = !pop_profile_implemented($profile);
            if (preg_match('/^stage(\d{2})_staging_draft$/', $id)) {
                $stageDraft[] = $id;
                continue;
            }
            $rows[] = [
                'id' => $id,
                'when' => pop_when_for_plan($p),
                'who' => pop_who_for_plan($p),
                'eta' => $blocked ? 'BLOCKED' : pop_eta_for_profile($profile, $id),
                'outputs' => pop_outputs_for_plan($p),
                'notes' => $blocked ? 'BLOCKED (not implemented)' : pop_notes_for_plan($p),
                'blocked' => $blocked,
            ];
        }
        if ($stageDraft !== []) {
            $rows[] = [
                'id' => 'stageXX_staging_draft (00–16)',
                'when' => 'Cek stage spesifik (dev/QA)',
                'who' => ['DEV', 'QA'],
                'eta' => '3–15 menit',
                'outputs' => 'PASS/FAIL + laporan',
                'notes' => 'Butuh TOOLS_BASE_URL',
                'blocked' => false,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => pop_sort_order($a['id']) <=> pop_sort_order($b['id']));
        return $rows;
    }
}
