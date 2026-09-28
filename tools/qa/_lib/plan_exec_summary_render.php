<?php
declare(strict_types=1);

require_once __DIR__ . '/pipeline_lib.php';

if (!function_exists('pes_render_md')) {
    function pes_render_md(array $obj): string
    {
        $lines = [];
        $lines[] = '# Plan Executive Summary';
        $lines[] = '';
        $goNoGo = (string)($obj['decision']['go_no_go'] ?? 'UNKNOWN');
        $level = (string)($obj['decision']['level'] ?? 'UNKNOWN');
        $lines[] = '## Decision';
        $lines[] = '- **GO/NO-GO:** ' . $goNoGo;
        $lines[] = '- **Level:** ' . $level;
        $lines[] = '- **First failure stage:** ' . (string)($obj['decision']['first_failure_stage'] ?? 'null');
        foreach ((array)($obj['decision']['reasons'] ?? []) as $r) {
            $lines[] = '- ' . ppl_mask((string)$r);
        }
        $lines[] = '';
        $lines[] = '## Summary';
        $sum = (array)($obj['summary'] ?? []);
        $lines[] = '- stages_total: ' . (int)($sum['stages_total'] ?? 0);
        $lines[] = '- stages_pass: ' . (int)($sum['stages_pass'] ?? 0);
        $lines[] = '- stages_fail: ' . (int)($sum['stages_fail'] ?? 0);
        $lines[] = '- stop_on_fail: ' . ((bool)($sum['stop_on_fail'] ?? true) ? 'true' : 'false');
        $lines[] = '';
        $lines[] = '## Architecture Readiness';
        $arch = (array)($obj['architecture'] ?? []);
        $lines[] = '- status: ' . ppl_mask((string)($arch['status'] ?? 'DATA_MISSING'));
        $lines[] = '- arch_audit_run_id: ' . ppl_mask((string)($arch['arch_audit_run_id'] ?? 'null'));
        foreach ((array)($arch['domains'] ?? []) as $d) {
            $lines[] = '  - ' . ppl_mask((string)($d['name'] ?? '')) . ': ' . ppl_mask((string)($d['status'] ?? '')) . ' (confidence: ' . (int)($d['confidence'] ?? 0) . ')';
        }
        foreach ((array)($arch['commands'] ?? []) as $c) {
            $lines[] = '- command: `' . ppl_mask((string)$c) . '`';
        }
        $lines[] = '';
        $lines[] = '## Evidence Links';
        foreach ((array)($obj['evidence_links'] ?? []) as $e) {
            $lines[] = '- ' . ppl_mask((string)($e['label'] ?? '')) . ': ' . ppl_mask((string)($e['path'] ?? ''));
        }
        $lines[] = '';
        $lines[] = '## Next Actions';
        foreach ((array)($obj['next_actions'] ?? []) as $a) {
            $lines[] = '- `' . ppl_mask((string)$a) . '`';
        }
        $lines[] = '';
        $lines[] = '---';
        $lines[] = 'generated_at: ' . ppl_mask((string)($obj['generated_at'] ?? ''));
        $lines[] = 'plan_id: ' . ppl_mask((string)($obj['plan_id'] ?? ''));
        $lines[] = 'master_run_id: ' . ppl_mask((string)($obj['master_run_id'] ?? ''));
        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('pes_render_html')) {
    function pes_render_html(array $obj): string
    {
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $goNoGo = (string)($obj['decision']['go_no_go'] ?? 'UNKNOWN');
        $level = (string)($obj['decision']['level'] ?? 'UNKNOWN');
        $badge = $goNoGo === 'GO' ? 'GO' : ($goNoGo === 'NO-GO' ? 'NO-GO' : 'UNKNOWN');
        $badgeBg = $goNoGo === 'GO' ? '#d1fae5' : ($goNoGo === 'NO-GO' ? '#fee2e2' : '#fef3c7');

        $stageRows = '';
        $failedStage = (string)($obj['decision']['first_failure_stage'] ?? '');
        $sum = (array)($obj['summary'] ?? []);
        $checklist = (array)($sum['stages_checklist'] ?? []);
        foreach ($checklist as $c) {
            $stageRows .= '<tr><td>' . $esc((string)($c['stage'] ?? '')) . '</td><td>' . ((bool)($c['ok'] ?? false) ? 'PASS' : 'FAIL') . '</td></tr>';
        }
        if ($stageRows === '') $stageRows = '<tr><td colspan="2">No stages</td></tr>';

        $archRows = '';
        foreach ((array)($obj['architecture']['domains'] ?? []) as $d) {
            $archRows .= '<tr><td>' . $esc((string)($d['name'] ?? '')) . '</td><td>' . $esc((string)($d['status'] ?? '')) . '</td><td>' . (int)($d['confidence'] ?? 0) . '</td></tr>';
        }
        if ($archRows === '') $archRows = '<tr><td colspan="3">DATA_MISSING</td></tr>';

        $nextActions = '';
        foreach ((array)($obj['next_actions'] ?? []) as $a) {
            $nextActions .= '<li><code>' . $esc((string)$a) . '</code></li>';
        }

        $reasons = '';
        foreach ((array)($obj['decision']['reasons'] ?? []) as $r) {
            $reasons .= '<li>' . $esc((string)$r) . '</li>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>Plan Executive Summary</title>'
            . '<style>body{font-family:Arial,sans-serif;font-size:11px;margin:12px;color:#111}table{width:100%;border-collapse:collapse;font-size:10px}th,td{border:1px solid #ddd;padding:4px}h1,h2{margin:4px 0}.badge{display:inline-block;padding:8px 16px;border-radius:8px;font-weight:700;font-size:14px}.box{border:1px solid #ddd;padding:8px;border-radius:6px;margin:8px 0}@media print{@page{size:A4;margin:7mm}body{margin:0;font-size:10px}}</style>'
            . '</head><body>'
            . '<h1>Plan Executive Summary</h1>'
            . '<p><b>plan:</b> ' . $esc((string)($obj['plan_id'] ?? '')) . ' | <b>env:</b> ' . $esc((string)($obj['env'] ?? '')) . ' | <b>master_run_id:</b> ' . $esc((string)($obj['master_run_id'] ?? '')) . '</p>'
            . '<p><span class="badge" style="background:' . $badgeBg . ';">' . $badge . '</span> <b>Level:</b> ' . $esc($level) . '</p>'
            . '<div class="box"><h2>Summary</h2><p>Stages: ' . (int)($sum['stages_pass'] ?? 0) . ' pass / ' . (int)($sum['stages_fail'] ?? 0) . ' fail</p>'
            . '<table><tr><th>Stage</th><th>Status</th></tr>' . $stageRows . '</table></div>'
            . ($failedStage !== '' ? '<div class="box"><b>First failure:</b> ' . $esc($failedStage) . '</div>' : '')
            . ($reasons !== '' ? '<div class="box"><b>Reasons:</b><ul>' . $reasons . '</ul></div>' : '')
            . '<div class="box"><h2>Architecture Readiness</h2><p><b>status:</b> ' . $esc((string)($obj['architecture']['status'] ?? 'DATA_MISSING')) . '</p>'
            . '<table><tr><th>Domain</th><th>Status</th><th>Confidence</th></tr>' . $archRows . '</table>'
            . '</div>'
            . '<div class="box"><h2>Next Actions</h2><ul>' . $nextActions . '</ul></div>'
            . '<p><small>Generated: ' . $esc(ppl_fmt_ts((string)($obj['generated_at'] ?? ''))) . ' | master_run_id: ' . $esc((string)($obj['master_run_id'] ?? '')) . '</small></p>'
            . '</body></html>';
    }
}
