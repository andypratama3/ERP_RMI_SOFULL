<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../../master/auth.php';
    require_once __DIR__ . '/../tools_access_helpers.php';
    tools_require_access('ops/create_ops_gov_patch_zip.php');
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$root = realpath(__DIR__ . '/../..');
if (!$root) {
    fwrite(STDERR, "Root not found\n");
    exit(1);
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive not available\n");
    exit(1);
}

$files = [
    'tools/ops_gov_common.php',
    'tools/ops/module_governance_tracker.php',
    'tools/dr/backup_db.php',
    'tools/dr/restore_db.php',
    'tools/dr/dr_log.php',
    'tools/perf/perf_baseline.php',
    'tools/perf/perf_budget.php',
    'tools/perf/cleanup_runner.php',
    'tools/compliance/evidence_export.php',
    'tools/compliance/evidence_index.php',
    'tools/change/rfc_new.php',
    'tools/change/rfc_list.php',
    'tools/change/rfc_view.php',
    'tools/release/release_gate.php',
    'tools/release/release_notes.php',
    'tools/ci/postmortem_new.php',
    'tools/ci/postmortem_list.php',
    'tools/ci/kpi_improvement.php',
    'tools/index.php',
    'tools/tools_state_lib.php',
    'tools/tools_access_matrix.php',
    'docs/help_center.php',
    'docs/help_sop_map.json',
    'docs/governance/BATCH_1_2_3_EXECUTION_PLAYBOOK.md',
    'docs/governance/BATCH_2_3_MODULE_TASK_BACKLOG.md',
    'docs/ops/dr/DR_Runbook.md',
    'docs/ops/dr/DR_Tabletop_Checklist.md',
    'docs/ops/dr/DR_RealRestore_Checklist.md',
    'docs/ops/dr/DR_Log_Template.csv',
    'docs/ops/perf/Performance_Budget.md',
    'docs/ops/perf/Baseline_Measurement.md',
    'docs/ops/perf/Thresholds.md',
    'docs/ops/compliance/Compliance_Evidence_Pack.md',
    'docs/ops/compliance/Evidence_Sources.md',
    'docs/ops/compliance/Evidence_Export_SOP.md',
    'docs/ops/compliance/Signoff_Template.md',
    'docs/ops/change/RFC_Template.md',
    'docs/ops/change/RFC_Policy.md',
    'docs/ops/change/Rollback_Playbook.md',
    'docs/ops/release/Release_Train.md',
    'docs/ops/release/Release_Checklist.md',
    'docs/ops/release/Readiness_Signoff_Template.md',
    'docs/ops/kt/Playbook_Admin.md',
    'docs/ops/kt/Playbook_QA.md',
    'docs/ops/kt/Playbook_DevOps.md',
    'docs/ops/kt/Playbook_Manager.md',
    'docs/ops/kt/Onboarding_Quickstart.md',
    'docs/ops/ci/Postmortem_Template.md',
    'docs/ops/ci/Incident_Severity_Guide.md',
    'docs/ops/ci/Improvement_Backlog.md',
];

$zipPath = $root . '/ERP_RMI_SOFULL_OPS_GOV_Pack_v1.zip';
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot open zip output\n");
    exit(1);
}

$changelog = "# CHANGELOG OPS GOV Pack v1\n\n- Add DR drill toolkit (backup/restore/log + docs).\n- Add performance budget + cleanup tools.\n- Add compliance evidence export pack (manifest/checksum/zip).\n- Add RFC light flow (new/list/view).\n- Add release gate + release notes generator (local-first).\n- Add postmortem/improvement tools.\n- Integrate Help Center and F1 SOP mapping for OPS & Governance.\n";
$install = "# INSTALL OPS GOV Pack v1\n\n1. Extract patch ZIP into project root, keep directory structure.\n2. Ensure PHP extension `zip` enabled.\n3. Set ENV: DB_HOST, DB_USER, DB_PASS, DB_NAME, BACKUP_PATH.\n4. Ensure folder write permissions: `storage/logs`, `tools/logs`, `exports/evidence`, `docs/rfc`.\n5. Login as ADMIN/SUPERADMIN.\n6. Open `/tools/index.php` and verify new OPS & Governance entries.\n7. Run tools in order: Perf Baseline -> DR Backup -> DR Restore Drill -> Evidence Export -> Release Gate.\n8. Validate Help Center and F1 mappings.\n9. Optional only: integrate `.github/workflows/ci.yml` jika memakai GitHub Actions.\n";
$zip->addFromString('CHANGELOG.md', $changelog);
$zip->addFromString('INSTALL.md', $install);

foreach ($files as $rel) {
    $abs = $root . '/' . $rel;
    if (!is_file($abs)) continue;
    $zip->addFile($abs, $rel);
}

$zip->close();
echo $zipPath . PHP_EOL;
