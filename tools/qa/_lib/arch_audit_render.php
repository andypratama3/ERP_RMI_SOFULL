<?php
declare(strict_types=1);

require_once __DIR__ . '/arch_audit_mask.php';

function aa_render_md(array $report): string
{
    $root = function_exists('aa_root') ? aa_root() : '';
    $domains = $report['domains'] ?? [];
    $cov = $report['scan_coverage'] ?? [];
    $inv = $report['inventory'] ?? [];
    $runId = $report['run_id'] ?? 'N/A';
    $gen = $report['generated_at'] ?? 'N/A';
    $buf = "# Architecture Audit Report\n\n";
    $buf .= "**Run ID:** " . $runId . "  \n**Generated:** " . $gen . "\n\n";
    $buf .= "## Executive Summary\n\n";
    $buf .= "| Domain | Status | Confidence |\n|--------|--------|------------|\n";
    foreach ($domains as $name => $d) {
        $buf .= "| " . str_replace('_', ' ', ucfirst($name)) . " | " . ($d['status'] ?? 'N/A') . " | " . ($d['confidence'] ?? 0) . " |\n";
    }
    $buf .= "\n## Findings per Domain\n\n";
    foreach ($domains as $name => $d) {
        $buf .= "### " . str_replace('_', ' ', ucfirst($name)) . "\n\n";
        $buf .= "- **Status:** " . ($d['status'] ?? 'N/A') . "\n";
        $buf .= "- **Confidence:** " . ($d['confidence'] ?? 0) . "\n";
        if (!empty($d['risk_note'])) $buf .= "- **Risk:** " . $d['risk_note'] . "\n";
        if (!empty($d['evidence'])) {
            $buf .= "- **Evidence (sample):**\n";
            foreach (array_slice($d['evidence'], 0, 10) as $e) {
                $buf .= "  - " . ($e['file'] ?? '') . " [" . ($e['pattern'] ?? '') . "]\n";
            }
        }
        if (!empty($d['recommendations'])) {
            $buf .= "- **Recommendations:** " . implode('; ', $d['recommendations']) . "\n";
        }
        $buf .= "\n";
    }
    $buf .= "## Scan Coverage\n\n";
    $buf .= "- Files scanned: " . ($cov['files_scanned'] ?? 0) . "\n";
    $buf .= "- Total eligible: " . ($cov['total_eligible'] ?? 0) . "\n";
    $buf .= "- Truncated: " . (($cov['truncated'] ?? false) ? 'yes' : 'no') . "\n";
    $buf .= "- Excluded: " . implode(', ', $cov['excluded_dirs'] ?? []) . "\n\n";
    $buf .= "## Inventory\n\n";
    foreach ($inv as $k => $v) {
        $buf .= "- " . $k . ": " . (($v['exists'] ?? false) ? 'exists' : 'missing') . "\n";
    }
    $buf .= "\n## Fix Commands (non-destructive)\n\n";
    $buf .= "```bash\n# Run architecture audit\nphp tools/qa/arch_audit.php --run-id=auto --scope=repo --write-last\n```\n";
    return $buf;
}

function aa_render_one_pager(array $report): string
{
    $domains = $report['domains'] ?? [];
    $statusMap = ['PRESENT' => 'Ada', 'PARTIAL' => 'Sebagian', 'NOT_FOUND' => 'Tidak Ada', 'UNKNOWN' => 'Tidak Bisa Dipastikan'];
    $buf = "# Ringkasan Audit Arsitektur (Non-IT)\n\n";
    $buf .= "Laporan singkat status lima domain arsitektur.\n\n";
    $labels = [
        'broker' => 'Kurir Pesan / Antrian',
        'data_gov' => 'Governance Data & Kualitas',
        'iam' => 'Identitas & Akses (IAM)',
        'monitoring' => 'Monitoring & Alert',
        'backup_dr' => 'Backup & Disaster Recovery',
    ];
    foreach ($labels as $key => $label) {
        $d = $domains[$key] ?? [];
        $st = $d['status'] ?? 'UNKNOWN';
        $statusText = $statusMap[$st] ?? 'Tidak Bisa Dipastikan';
        $buf .= "**" . $label . ":** " . $statusText . ".\n";
        if ($key === 'broker') {
            $bp = $d['broker_present'] ?? false;
            $fq = $d['fallback_queue_present'] ?? false;
            $buf .= "  " . ($bp ? "Ada kurir pesan internal (broker)." : ($fq ? "Hanya antrian sederhana (DB/cron)." : "Belum terdeteksi broker atau antrian.")) . "\n";
        }
        if ($key === 'data_gov') {
            $cg = $d['change_gov_status'] ?? 'NOT_FOUND';
            $dq = $d['data_quality_status'] ?? 'NOT_FOUND';
            $buf .= "  Change governance: " . ($statusMap[$cg] ?? $cg) . ". Data quality: " . ($statusMap[$dq] ?? $dq) . ".\n";
        }
        if ($key === 'iam') {
            $buf .= "  Maturity: " . ($d['iam_maturity'] ?? 'L1') . ".\n";
        }
        if ($key === 'backup_dr') {
            $buf .= "  Backup: " . ($d['backup_present'] ?? 'no') . ". Restore aman: " . ($d['restore_safe'] ?? 'unknown') . ".\n";
        }
        $buf .= "\n";
    }
    return $buf;
}
