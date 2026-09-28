<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

opsgov_require_admin();
$dir = opsgov_root() . '/docs/rfc';
@mkdir($dir, 0775, true);
$msg = ''; $type = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $owner = trim((string)($_POST['owner'] ?? ''));
    $reviewer = trim((string)($_POST['reviewer'] ?? ''));
    $approver = trim((string)($_POST['approver'] ?? ''));
    $risk = trim((string)($_POST['risk'] ?? ''));
    $rollback = trim((string)($_POST['rollback'] ?? ''));
    if ($title === '' || $owner === '') {
        $msg = 'Title dan owner wajib.';
        $type = 'danger';
    } else {
        $date = date('Ymd');
        $seq = str_pad((string)(count(glob($dir . '/RFC-' . $date . '-*.md') ?: []) + 1), 3, '0', STR_PAD_LEFT);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($title));
        $slug = trim((string)$slug, '-');
        $id = 'RFC-' . $date . '-' . $seq;
        $name = $id . '-' . $slug;
        $md = $dir . '/' . $name . '.md';
        $json = $dir . '/' . $name . '.json';
        $body = "---\nid: {$id}\nstatus: DRAFT\nowner: {$owner}\nreviewer: {$reviewer}\napprover: {$approver}\ncreated_at: " . date(DateTimeInterface::ATOM) . "\n---\n\n# {$title}\n\n## Risk\n{$risk}\n\n## Rollback Plan\n{$rollback}\n\n## Approval Notes\n- reviewer:\n- approver:\n";
        @file_put_contents($md, $body);
        opsgov_safe_write_json($json, ['state_version' => 1, 'id' => $id, 'title' => $title, 'status' => 'DRAFT', 'owner' => $owner, 'reviewer' => $reviewer, 'approver' => $approver, 'file' => basename($md)]);
        ts_append_run_history('rfc_created', 'OK', ['source' => 'tools/change/rfc_new.php', 'rfc_id' => $id]);
        $msg = 'RFC dibuat: ' . $id;
        $type = 'success';
    }
}

$baseProject = rmi_layout_base_project();
rmi_header('RFC New', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], ['label' => 'RFC List', 'url' => 'rfc_list.php'], 'RFC New']]);
?>
<div class="card p-3">
  <?php if ($msg !== ''): ?><div class="alert alert-<?= opsgov_h($type) ?>"><?= opsgov_h($msg) ?></div><?php endif; ?>
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <div class="col-md-8"><label class="form-label small">Title</label><input class="form-control form-control-sm" name="title" required></div>
    <div class="col-md-4"><label class="form-label small">Owner</label><input class="form-control form-control-sm" name="owner" required></div>
    <div class="col-md-6"><label class="form-label small">Reviewer</label><input class="form-control form-control-sm" name="reviewer"></div>
    <div class="col-md-6"><label class="form-label small">Approver</label><input class="form-control form-control-sm" name="approver"></div>
    <div class="col-12"><label class="form-label small">Risk</label><textarea class="form-control form-control-sm" name="risk" rows="3"></textarea></div>
    <div class="col-12"><label class="form-label small">Rollback Plan</label><textarea class="form-control form-control-sm" name="rollback" rows="3"></textarea></div>
    <div class="col-12"><button class="btn btn-sm btn-rmi">Create RFC</button></div>
  </form>
</div>
<?php rmi_footer(); ?>
