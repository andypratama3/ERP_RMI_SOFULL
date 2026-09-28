<?php
declare(strict_types=1);

require_once __DIR__ . '/pipeline_lib.php';

if (!function_exists('ppl_level_from_stage')) {
    function ppl_level_from_stage(array $stage): string
    {
        if (!(bool)($stage['overall_ok'] ?? false)) return 'CRITICAL';
        if ((array)($stage['reasons'] ?? []) !== []) return 'ATTENTION';
        return 'HEALTHY';
    }
}

if (!function_exists('ppl_render_plan_md')) {
    function ppl_render_plan_md(array $planSummary): string
    {
        $lines = [];
        $lines[] = '# Pipeline Plan Summary';
        $lines[] = '';
        $lines[] = '- generated_at: ' . ppl_mask((string)($planSummary['generated_at'] ?? ''));
        $lines[] = '- plan_id: ' . ppl_mask((string)($planSummary['plan_id'] ?? ''));
        $lines[] = '- env: ' . ppl_mask((string)($planSummary['env'] ?? ''));
        $lines[] = '- mode: ' . ppl_mask((string)($planSummary['mode'] ?? ''));
        $lines[] = '- master_run_id: ' . ppl_mask((string)($planSummary['master_run_id'] ?? ''));
        $lines[] = '- overall_ok: ' . ((bool)($planSummary['overall_ok'] ?? false) ? 'true' : 'false');
        $lines[] = '- failed_stage: ' . ppl_mask((string)($planSummary['failed_stage'] ?? 'null'));
        $lines[] = '';
        $lines[] = '| Stage | Run ID | OK | Level | Reasons |';
        $lines[] = '|---|---|---|---|---|';
        foreach ((array)($planSummary['stages'] ?? []) as $row) {
            $lines[] = '| ' . ppl_mask((string)($row['stage'] ?? ''))
                . ' | ' . ppl_mask((string)($row['run_id'] ?? ''))
                . ' | ' . (((bool)($row['overall_ok'] ?? false)) ? 'YES' : 'NO')
                . ' | ' . ppl_mask((string)($row['level'] ?? 'UNKNOWN'))
                . ' | ' . ppl_mask(implode(',', (array)($row['reasons'] ?? []))) . ' |';
        }
        $failedStage = (string)($planSummary['failed_stage'] ?? '');
        if ($failedStage !== '') {
            $rerun = 'php tools/qa/run_pipeline_final.php --plan=stage' . $failedStage . '_staging_draft --run-id='
                . ppl_mask((string)($planSummary['master_run_id'] ?? '') . '_S' . $failedStage) . ' --write-last';
            $lines[] = '';
            $lines[] = '## First Failure & Rerun';
            $lines[] = '- first_failed_stage: ' . ppl_mask($failedStage);
            $lines[] = '- rerun: `' . $rerun . '`';
        }
        $lines[] = '';
        $lines[] = '## Architecture Readiness (Evidence Scan)';
        $panel = (array)($planSummary['architecture_panel'] ?? []);
        if ($panel !== []) {
            $lines[] = '- status: ' . ppl_mask((string)($panel['status'] ?? 'DATA_MISSING'));
            $lines[] = '- arch_audit_run_id: ' . ppl_mask((string)($panel['arch_audit_run_id'] ?? 'null'));
            foreach ((array)($panel['domains'] ?? []) as $d) {
                $lines[] = '  - ' . ppl_mask((string)($d['name'] ?? '')) . ': ' . ppl_mask((string)($d['status'] ?? '')) . ' (confidence: ' . (int)($d['confidence'] ?? 0) . ')';
            }
            foreach ((array)($panel['data_missing'] ?? []) as $dm) {
                $lines[] = '- data_missing: ' . ppl_mask((string)($dm['reason'] ?? ''));
            }
            foreach ((array)($panel['commands'] ?? []) as $c) {
                $lines[] = '- command: `' . ppl_mask((string)$c) . '`';
            }
        } else {
            $lines[] = '- DATA_MISSING';
        }
        $lines[] = '';
        $lines[] = '## Next Actions';
        foreach ((array)($planSummary['notes'] ?? []) as $n) {
            $lines[] = '- ' . ppl_mask((string)$n);
        }
        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('ppl_render_plan_html')) {
    function ppl_render_plan_html(array $planSummary): string
    {
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $badge = ((bool)($planSummary['overall_ok'] ?? false)) ? 'GO' : 'FAIL';
        $badgeBg = ((bool)($planSummary['overall_ok'] ?? false)) ? '#d1fae5' : '#fee2e2';
        $rows = '';
        foreach ((array)($planSummary['stages'] ?? []) as $s) {
            $rows .= '<tr><td>' . $esc((string)($s['stage'] ?? '')) . '</td>'
                . '<td>' . $esc((string)($s['run_id'] ?? '')) . '</td>'
                . '<td>' . (((bool)($s['overall_ok'] ?? false)) ? 'OK' : 'FAIL') . '</td>'
                . '<td>' . $esc((string)($s['level'] ?? 'UNKNOWN')) . '</td>'
                . '<td>' . $esc(implode(', ', (array)($s['reasons'] ?? []))) . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="5">No stage results</td></tr>';
        $failedStage = (string)($planSummary['failed_stage'] ?? '');
        $rerun = '';
        if ($failedStage !== '') {
            $rerun = 'php tools/qa/run_pipeline_final.php --plan=stage' . $failedStage . '_staging_draft --run-id='
                . (string)($planSummary['master_run_id'] ?? '') . '_S' . $failedStage . ' --write-last';
        }
        $archPanel = (array)($planSummary['architecture_panel'] ?? []);
        $archHtml = '';
        if ($archPanel !== []) {
            $archStatus = $esc((string)($archPanel['status'] ?? 'DATA_MISSING'));
            $archHtml = '<div class="box"><h2>Architecture Readiness (Evidence Scan)</h2><p><b>status:</b> ' . $archStatus . '</p>';
            $archDomains = (array)($archPanel['domains'] ?? []);
            if ($archDomains !== []) {
                $archHtml .= '<table><tr><th>Domain</th><th>Status</th><th>Confidence</th></tr>';
                foreach ($archDomains as $d) {
                    $archHtml .= '<tr><td>' . $esc((string)($d['name'] ?? '')) . '</td><td>' . $esc((string)($d['status'] ?? '')) . '</td><td>' . (int)($d['confidence'] ?? 0) . '</td></tr>';
                }
                $archHtml .= '</table>';
            }
            foreach ((array)($archPanel['commands'] ?? []) as $ac) {
                $archHtml .= '<p><b>command:</b> <code>' . $esc((string)$ac) . '</code></p>';
            }
            $archHtml .= '</div>';
        }
        return '<!doctype html><html><head><meta charset="utf-8"><title>Pipeline Plan Summary</title>'
            . '<style>body{font-family:Arial,sans-serif;font-size:11px;margin:12px;color:#111}table{width:100%;border-collapse:collapse;font-size:10px}th,td{border:1px solid #ddd;padding:4px}h1,h2{margin:4px 0}.badge{display:inline-block;padding:4px 10px;border-radius:10px;font-weight:700}.box{border:1px solid #ddd;padding:6px;border-radius:6px;margin:6px 0}@media print{@page{size:A4;margin:7mm}body{margin:0;font-size:10px}}</style>'
            . '</head><body>'
            . '<h1>Pipeline Plan Summary</h1>'
            . '<p><b>plan:</b> ' . $esc((string)($planSummary['plan_id'] ?? '')) . ' | <b>env:</b> ' . $esc((string)($planSummary['env'] ?? '')) . ' | <b>master_run_id:</b> ' . $esc((string)($planSummary['master_run_id'] ?? '')) . '</p>'
            . '<p><span class="badge" style="background:' . $badgeBg . ';">' . $badge . '</span></p>'
            . '<table><tr><th>Stage</th><th>Run ID</th><th>OK</th><th>Level</th><th>Reasons</th></tr>' . $rows . '</table>'
            . ($failedStage !== '' ? '<div class="box"><b>First failure stage:</b> ' . $esc($failedStage) . '<br><b>Rerun:</b> <code>' . $esc($rerun) . '</code></div>' : '')
            . $archHtml
            . '<div class="box"><b>Generated at:</b> ' . $esc(ppl_fmt_ts((string)($planSummary['generated_at'] ?? ''))) . '</div>'
            . '</body></html>';
    }
}

