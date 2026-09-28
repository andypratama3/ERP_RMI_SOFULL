<?php
/**
 * dashboards/_panduan_helpers.php
 * URL + CSS bersama untuk halaman panduan in-app (folder dashboards, file panduan.php).
 */
declare(strict_types=1);

if (!function_exists('ds_panduan_u')) {
    function ds_panduan_u(string $path): string
    {
        $bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string) BASE_PROJECT, '/') : '');

        return rtrim($bp, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('ds_panduan_styles')) {
    function ds_panduan_styles(): string
    {
        return <<<'CSS'
<style>
.pnd-hero{background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(14,165,233,.08));border:1px solid rgba(99,102,241,.28);border-radius:16px;padding:28px 32px;margin-bottom:24px}
.pnd-section{background:var(--rmi-card,#1a2235);border:1px solid var(--rmi-border,rgba(255,255,255,.1));border-radius:14px;padding:22px 26px;margin-bottom:18px}
.pnd-section.accent-blue{border-color:rgba(59,130,246,.35)}
.pnd-section.accent-purple{border-color:rgba(139,92,246,.35)}
.pnd-section.accent-amber{border-color:rgba(245,158,11,.35)}
.pnd-section.accent-green{border-color:rgba(34,197,94,.35)}
.pnd-section.accent-teal{border-color:rgba(20,184,166,.35)}
.pnd-section.accent-red{border-color:rgba(239,68,68,.35)}
.pnd-step{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px}
.pnd-num{min-width:34px;height:34px;border-radius:50%;color:#fff;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;background:linear-gradient(135deg,#6366f1,#8b5cf6)}
.pnd-title{font-weight:700;margin-bottom:5px;font-size:15px;color:#e2e8f0}
.pnd-desc{color:var(--rmi-muted,#9ca3af);font-size:13px;line-height:1.75}
.pnd-desc b{color:#e2e8f0}
.pnd-tip{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#fbbf24;margin-top:12px}
.pnd-info{background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.25);border-radius:10px;padding:12px 16px;font-size:13px;color:#93c5fd;margin-top:12px}
.pnd-quick{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-top:14px}
.pnd-quick a{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#e2e8f0;text-decoration:none;font-size:13px;font-weight:600;transition:.12s}
.pnd-quick a:hover{background:rgba(99,102,241,.12);border-color:rgba(99,102,241,.35);color:#a5b4fc}
ul.pnd-list{margin:0;padding-left:18px;color:#94a3b8;font-size:13px;line-height:1.85}
ul.pnd-list li{margin-bottom:4px}
</style>
CSS;
    }
}
