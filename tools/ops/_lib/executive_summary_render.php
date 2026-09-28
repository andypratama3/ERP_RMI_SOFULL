<?php
declare(strict_types=1);

require_once __DIR__ . '/executive_summary_lib.php';

if (!function_exists('exsr_status_badge_class')) {
    function exsr_status_badge_class(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'GO', 'HEALTHY', 'OK', 'PASS', 'PRESENT' => 'ok',
            'CRITICAL', 'FAIL', 'NO-GO' => 'critical',
            'ATTENTION', 'WARN', 'PARTIAL' => 'attention',
            'DATA_MISSING', 'NOT_FOUND', 'UNKNOWN' => 'missing',
            default => 'missing',
        };
    }
}

if (!function_exists('exsr_render_md')) {
    function exsr_render_md(array $payload): string
    {
        $decision = (array)($payload['decision'] ?? []);
        $panels = (array)($payload['panels'] ?? []);
        $ops = (array)($panels['ops_score'] ?? []);
        $fix = (array)($panels['fix_backlog'] ?? []);
        $rv = (array)($panels['release_verify'] ?? []);
        $alerts = (array)($panels['alerts'] ?? []);
        $cc = (array)($panels['change_control'] ?? []);
        $arch = (array)($panels['architecture'] ?? []);
        $fresh = (array)($panels['freshness'] ?? []);
        $lines = [];
        $lines[] = '# Executive Ops Summary Ultimate';
        $lines[] = '';
        $lines[] = '- generated_at: ' . exs_format_ts((string)($payload['generated_at'] ?? ''));
        $lines[] = '- env: ' . exs_mask((string)($payload['env'] ?? ''));
        $lines[] = '- run_id: ' . exs_mask((string)($payload['run_id'] ?? ''));
        $lines[] = '- decision: **' . exs_mask((string)($decision['go_no_go'] ?? 'UNKNOWN')) . '** / **' . exs_mask((string)($decision['level'] ?? 'ATTENTION')) . '**';
        $lines[] = '- top reasons: ' . exs_mask(implode(', ', array_slice((array)($decision['reasons'] ?? []), 0, 3)));
        $lines[] = '';
        $lines[] = '## Panel 1 - Ops Score';
        $lines[] = '- status: ' . exs_mask((string)($ops['status'] ?? 'DATA_MISSING')) . ', score=' . (string)($ops['current_score'] ?? 'null');
        $lines[] = '- delta 7d/30d: ' . (string)($ops['delta_7d'] ?? 'null') . ' / ' . (string)($ops['delta_30d'] ?? 'null');
        $lines[] = '';
        $lines[] = '## Panel 2 - Fix Backlog';
        $lines[] = '- p0/p1/p2: ' . (string)($fix['p0'] ?? 'null') . '/' . (string)($fix['p1'] ?? 'null') . '/' . (string)($fix['p2'] ?? 'null');
        $lines[] = '- p0_delta_7d: ' . (string)($fix['p0_delta_7d'] ?? 'null');
        $lines[] = '- flags: ' . exs_mask(implode(', ', (array)($fix['flags'] ?? [])));
        $lines[] = '';
        $lines[] = '## Panel 3 - Release Verify';
        $lines[] = '- status: ' . exs_mask((string)($rv['status'] ?? 'DATA_MISSING'));
        $lines[] = '- bundle_fail/warn: ' . (string)($rv['bundle_fail'] ?? 'null') . '/' . (string)($rv['bundle_warn'] ?? 'null');
        $lines[] = '- last_run_at: ' . exs_format_ts((string)($rv['last_run_at'] ?? ''));
        if ((string)($rv['reason'] ?? '') !== '') {
            $lines[] = '- reason: ' . exs_mask((string)$rv['reason']);
        }
        $lines[] = '';
        $lines[] = '## Panel 4 - Alerts';
        $lines[] = '- level: ' . exs_mask((string)($alerts['level'] ?? 'DATA_MISSING'));
        $lines[] = '- owner: ' . exs_mask((string)($alerts['owner_primary'] ?? 'N/A'));
        $lines[] = '- workflow: ' . exs_mask((string)($alerts['workflow_status'] ?? 'N/A'));
        $lines[] = '- sla_due_at: ' . exs_format_ts((string)($alerts['sla_due_at'] ?? ''));
        $lines[] = '- sla_breached: ' . ((bool)($alerts['sla_breached'] ?? false) ? 'true' : 'false');
        $lines[] = '';
        $lines[] = '## Panel 5 - Change Control Health';
        $lines[] = '- level: ' . exs_mask((string)($cc['level'] ?? 'DATA_MISSING'));
        $lines[] = '- lint fail/warn: ' . (string)($cc['lint_fail'] ?? 'null') . '/' . (string)($cc['lint_warn'] ?? 'null');
        $lines[] = '- approved_but_fail: ' . (string)($cc['approved_but_fail'] ?? 'null');
        $lines[] = '- prod_missing_schedule: ' . (string)($cc['prod_missing_schedule'] ?? 'null');
        $lines[] = '- primary RFC: ' . exs_mask((string)($cc['primary_rfc_id'] ?? 'null')) . ' (' . exs_mask((string)($cc['primary_status'] ?? 'null')) . ')';
        $lines[] = '';
        $lines[] = '## Panel 6 - Architecture Readiness';
        $lines[] = '- status: ' . exs_mask((string)($arch['status'] ?? 'DATA_MISSING'));
        $lines[] = '- arch_audit_run_id: ' . exs_mask((string)($arch['arch_audit_run_id'] ?? 'null'));
        $lines[] = '- arch_audit_age_hours: ' . (string)($arch['arch_audit_age_hours'] ?? 'null');
        foreach ((array)($arch['domains'] ?? []) as $dom) {
            $lines[] = '  - ' . exs_mask((string)($dom['name'] ?? '')) . ': ' . exs_mask((string)($dom['status'] ?? '')) . ' (conf=' . (string)($dom['confidence'] ?? 0) . ')';
        }
        foreach (array_slice((array)($arch['recommendations'] ?? []), 0, 3) as $rec) {
            $lines[] = '- rec: ' . exs_mask((string)$rec);
        }
        if (!empty($arch['data_missing'])) {
            foreach ((array)$arch['data_missing'] as $dm) {
                $lines[] = '- data_missing: ' . exs_mask((string)($dm['reason'] ?? ''));
            }
        }
        $lines[] = '';
        $lines[] = '## Panel 7 - Freshness';
        foreach ((array)($fresh['per_source_age_s'] ?? []) as $k => $v) {
            $lines[] = '- ' . exs_mask((string)$k) . ': ' . (string)($v ?? 'null');
        }
        $lines[] = '- stale: ' . ((bool)($fresh['stale'] ?? false) ? 'true' : 'false');
        $lines[] = '';
        $lines[] = '## Panel 8 - Next Actions';
        foreach ((array)($payload['next_actions'] ?? []) as $cmd) {
            $lines[] = '- `' . exs_mask((string)$cmd) . '`';
        }
        if ((array)($payload['next_actions'] ?? []) === []) $lines[] = '- none';
        if ((array)($payload['data_missing'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = '## Data Missing';
            foreach ((array)$payload['data_missing'] as $dm) {
                $lines[] = '- ' . exs_mask((string)($dm['source'] ?? '')) . ' (' . exs_mask((string)($dm['reason'] ?? 'MISSING')) . ')';
            }
        }
        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('exsr_render_html')) {
    function exsr_render_html(array $payload): string
    {
        $decision = (array)($payload['decision'] ?? []);
        $panels = (array)($payload['panels'] ?? []);
        $ops = (array)($panels['ops_score'] ?? []);
        $fix = (array)($panels['fix_backlog'] ?? []);
        $rv = (array)($panels['release_verify'] ?? []);
        $alerts = (array)($panels['alerts'] ?? []);
        $cc = (array)($panels['change_control'] ?? []);
        $arch = (array)($panels['architecture'] ?? []);
        $fresh = (array)($panels['freshness'] ?? []);
        $reasons = array_slice((array)($decision['reasons'] ?? []), 0, 3);
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $reasonHtml = '';
        foreach ($reasons as $r) {
            $reasonHtml .= '<li>' . $esc(exs_mask((string)$r)) . '</li>';
        }
        if ($reasonHtml === '') $reasonHtml = '<li>none</li>';
        $freshRows = '';
        foreach ((array)($fresh['per_source_age_s'] ?? []) as $k => $v) {
            $freshRows .= '<tr><td>' . $esc((string)$k) . '</td><td>' . $esc((string)($v ?? 'null')) . '</td></tr>';
        }
        $actionHtml = '';
        foreach ((array)($payload['next_actions'] ?? []) as $cmd) {
            $actionHtml .= '<li><code>' . $esc(exs_mask((string)$cmd)) . '</code></li>';
        }
        if ($actionHtml === '') $actionHtml = '<li>none</li>';
        $dmHtml = '';
        foreach ((array)($payload['data_missing'] ?? []) as $dm) {
            $dmHtml .= '<li>' . $esc((string)($dm['source'] ?? '')) . ' (' . $esc((string)($dm['reason'] ?? 'MISSING')) . ')</li>';
        }
        if ($dmHtml === '') $dmHtml = '<li>none</li>';

        return '<!doctype html><html><head><meta charset="utf-8"><title>Executive Ops Summary</title>'
            . '<style>body{font-family:Arial,sans-serif;color:#111;margin:10px;font-size:11px}h1,h2,h3{margin:4px 0}table{border-collapse:collapse;width:100%;font-size:10px}th,td{border:1px solid #ddd;padding:4px;vertical-align:top}.badge{display:inline-block;padding:3px 7px;border-radius:10px;font-weight:700;font-size:10px}.ok{background:#d1fae5}.attention{background:#fef3c7}.critical{background:#fee2e2}.missing{background:#e5e7eb}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.box{border:1px solid #ddd;padding:6px;border-radius:5px;break-inside:avoid}.muted{color:#444}.mono{font-family:ui-monospace,Menlo,Consolas,monospace}.footer{margin-top:6px;font-size:9px;color:#555}@media print{@page{size:A4;margin:6mm}body{margin:0;font-size:10px}.grid{gap:4px}.box{padding:4px}}</style>'
            . '</head><body>'
            . '<h1>Executive Ops Summary Ultimate</h1>'
            . '<p><b>env:</b> ' . $esc(exs_mask((string)($payload['env'] ?? ''))) . ' | <b>run_id:</b> ' . $esc(exs_mask((string)($payload['run_id'] ?? ''))) . ' | <b>generated_at:</b> ' . $esc(exs_format_ts((string)($payload['generated_at'] ?? ''))) . '</p>'
            . '<div class="box"><h2 style="margin:0 0 4px;">Executive Decision</h2>'
            . '<span class="badge ' . exsr_status_badge_class((string)($decision['go_no_go'] ?? 'UNKNOWN')) . '">' . $esc((string)($decision['go_no_go'] ?? 'UNKNOWN')) . '</span> '
            . '<span class="badge ' . exsr_status_badge_class((string)($decision['level'] ?? 'ATTENTION')) . '">' . $esc((string)($decision['level'] ?? 'ATTENTION')) . '</span>'
            . '<div class="muted" style="margin-top:4px;">Top reasons:</div><ul style="margin:4px 0 0 16px;">' . $reasonHtml . '</ul></div>'
            . '<div class="grid">'
            . '<div class="box"><h3>Panel 1: Ops Score</h3>'
            . '<div>Status: <span class="badge ' . exsr_status_badge_class((string)($ops['status'] ?? 'DATA_MISSING')) . '">' . $esc((string)($ops['status'] ?? 'DATA_MISSING')) . '</span></div>'
            . '<div>Score: ' . $esc((string)($ops['current_score'] ?? 'null')) . '</div>'
            . '<div>Δ7d / Δ30d: ' . $esc((string)($ops['delta_7d'] ?? 'null')) . ' / ' . $esc((string)($ops['delta_30d'] ?? 'null')) . '</div>'
            . '<div class="mono" style="font-size:9px;margin-top:4px;">7d path: ' . $esc((string)($ops['sparkline_7d_svg'] ?? '')) . '</div>'
            . '</div>'
            . '<div class="box"><h3>Panel 2: Fix Backlog</h3>'
            . '<div>P0/P1/P2: ' . $esc((string)($fix['p0'] ?? 'null')) . '/' . $esc((string)($fix['p1'] ?? 'null')) . '/' . $esc((string)($fix['p2'] ?? 'null')) . '</div>'
            . '<div>Δ7d P0: ' . $esc((string)($fix['p0_delta_7d'] ?? 'null')) . '</div>'
            . '<div>Flags: ' . $esc(implode(',', (array)($fix['flags'] ?? []))) . '</div>'
            . '</div>'
            . '<div class="box"><h3>Panel 3: Release Verify</h3>'
            . '<div>Status: <span class="badge ' . exsr_status_badge_class((string)($rv['status'] ?? 'DATA_MISSING')) . '">' . $esc((string)($rv['status'] ?? 'DATA_MISSING')) . '</span></div>'
            . '<div>bundle_fail/warn: ' . $esc((string)($rv['bundle_fail'] ?? 'null')) . '/' . $esc((string)($rv['bundle_warn'] ?? 'null')) . '</div>'
            . '<div>last: ' . $esc(exs_format_ts((string)($rv['last_run_at'] ?? ''))) . '</div>'
            . (((string)($rv['reason'] ?? '') !== '') ? ('<div>reason: ' . $esc((string)$rv['reason']) . '</div>') : '')
            . '<div><a href="/tools/release/release_artifacts.php">release artifacts</a></div>'
            . '</div>'
            . '<div class="box"><h3>Panel 4: Alerts + SLA</h3>'
            . '<div>Level: <span class="badge ' . exsr_status_badge_class((string)($alerts['level'] ?? 'DATA_MISSING')) . '">' . $esc((string)($alerts['level'] ?? 'DATA_MISSING')) . '</span></div>'
            . '<div>Owner: ' . $esc((string)($alerts['owner_primary'] ?? 'N/A')) . '</div>'
            . '<div>Workflow: ' . $esc((string)($alerts['workflow_status'] ?? 'N/A')) . '</div>'
            . '<div>SLA due: ' . $esc(exs_format_ts((string)($alerts['sla_due_at'] ?? ''))) . '</div>'
            . '<div>SLA breach: ' . ($alerts['sla_breached'] === true ? '<span class="badge critical">BREACH</span>' : '<span class="badge ok">OK</span>') . '</div>'
            . '<div><a href="/tools/ops/alerts.php">alerts workflow</a></div>'
            . '</div>'
            . '<div class="box"><h3>Panel 5: Change Control Health</h3>'
            . '<div>Level: <span class="badge ' . exsr_status_badge_class((string)($cc['level'] ?? 'DATA_MISSING')) . '">' . $esc((string)($cc['level'] ?? 'DATA_MISSING')) . '</span></div>'
            . '<div>Lint F/W: ' . $esc((string)($cc['lint_fail'] ?? 'null')) . '/' . $esc((string)($cc['lint_warn'] ?? 'null')) . '</div>'
            . '<div>Approved-but-fail: ' . $esc((string)($cc['approved_but_fail'] ?? 'null')) . '</div>'
            . '<div>Prod missing schedule: ' . $esc((string)($cc['prod_missing_schedule'] ?? 'null')) . '</div>'
            . '<div>Primary RFC: ' . $esc((string)($cc['primary_rfc_id'] ?? 'null')) . ' (' . $esc((string)($cc['primary_status'] ?? 'null')) . ')</div>'
            . '<div><a href="/tools/rfc/rfc_dashboard.php">rfc dashboard</a></div>'
            . '</div>'
            . '<div class="box"><h3>Panel 6: Architecture Readiness (Evidence Scan)</h3>'
            . '<div>Status: <span class="badge ' . exsr_status_badge_class((string)($arch['status'] ?? 'DATA_MISSING')) . '">' . $esc((string)($arch['status'] ?? 'DATA_MISSING')) . '</span></div>'
            . (function () use ($arch, $esc) {
                $rows = '';
                foreach ((array)($arch['domains'] ?? []) as $dom) {
                    $rows .= '<tr><td>' . $esc((string)($dom['name'] ?? '')) . '</td><td><span class="badge ' . exsr_status_badge_class((string)($dom['status'] ?? '')) . '">' . $esc((string)($dom['status'] ?? '')) . '</span></td><td class="muted" style="font-size:9px;">' . $esc((string)($dom['confidence'] ?? 0)) . '</td></tr>';
                }
                if ($rows === '' && !empty($arch['data_missing'])) {
                    $rows = '<tr><td colspan="3" class="muted">DATA_MISSING: ' . $esc((string)(($arch['data_missing'][0]['reason'] ?? 'MISSING'))) . '</td></tr>';
                }
                return $rows !== '' ? ('<table><tr><th>Domain</th><th>Status</th><th>Conf</th></tr>' . $rows . '</table>') : '<div class="muted">No data</div>';
            })()
            . '<div class="muted" style="margin-top:4px;font-size:9px;">Freshness: ' . ($arch['arch_audit_age_hours'] !== null ? $esc((string)$arch['arch_audit_age_hours']) . 'h ago' : 'N/A') . '</div>'
            . (count((array)($arch['recommendations'] ?? [])) > 0 ? '<div style="margin-top:4px;">Rec: ' . $esc(implode('; ', array_slice((array)$arch['recommendations'], 0, 3))) . '</div>' : '')
            . (!empty($arch['data_missing']) ? '<div style="margin-top:4px;"><code>' . $esc((string)($arch['commands'][0] ?? '')) . '</code></div>' : '')
            . '<div style="margin-top:4px;"><a href="arch_audit_last_one_pager.md">one pager</a></div>'
            . '</div>'
            . '<div class="box"><h3>Panel 7: Freshness</h3>'
            . '<table><tr><th>Source</th><th>Age(s)</th></tr>' . $freshRows . '</table>'
            . '<div>stale: ' . ((bool)($fresh['stale'] ?? false) ? '<span class="badge attention">STALE</span>' : '<span class="badge ok">FRESH</span>') . '</div>'
            . '</div>'
            . '</div>'
            . '<div class="box"><h3>Panel 8: Next Actions</h3><ul style="margin:4px 0 0 16px;">' . $actionHtml . '</ul></div>'
            . '<div class="box"><h3>Data Missing</h3><ul style="margin:4px 0 0 16px;">' . $dmHtml . '</ul></div>'
            . '<div class="footer">Generated by tools/ops/generate_executive_summary.php | Policy fingerprint: '
            . $esc((string)($payload['meta']['policy_fingerprint'] ?? '')) . '</div>'
            . '</body></html>';
    }
}

