<?php
if (function_exists('opcache_invalidate')) {
    static $done = false;
    if (!$done) {
        $done = true;
        $base = __DIR__;
        foreach ([
            $base . '/_inc/bootstrap.php', $base . '/_inc/schema.php', $base . '/_layout_top.php',
            $base . '/mpr_plans.php', $base . '/mpr_pipeline.php', $base . '/mpr_visits.php',
            $base . '/mpr_dashboard.php', $base . '/mpr_plan_view.php', $base . '/mpr_budget_fin.php',
            dirname($base) . '/master/auth.php', dirname($base) . '/_shared/helpers.php',
            dirname($base) . '/_shared/rmi_page_registry_guard.php', dirname($base) . '/config/page_registry.php',
        ] as $f) { if (is_file($f)) opcache_invalidate($f, true); }
    }
}
