<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_shared/bootstrap.php';
require_once __DIR__ . '/../../../_shared/rbac.php';
require_once __DIR__ . '/../../../tools/rfc/_lib/rfc_lib.php';

require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.VIEW', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$rfc = strtoupper(trim((string)($_GET['rfc'] ?? $_POST['rfc'] ?? '')));
$msg = '';
$errs = [];
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    verify_csrf();
    $role = strtoupper(trim((string)($_POST['role'] ?? '')));
    $note = trim((string)($_POST['note'] ?? ''));
    $actor = (string)($_SESSION['username'] ?? 'SYSTEM');
    $file = rfc_find_file_by_id($rfc);
    if ($file === '') $errs[] = 'rfc_not_found';
    else {
        $p = rfc_parse_file($file);
        if (!$p['ok']) $errs[] = 'rfc_parse_failed';
        else {
            $fm = (array)$p['frontmatter'];
            $approvals = (array)($fm['APPROVALS'] ?? []);
            foreach ($approvals as $a) {
                if (strtoupper((string)($a['ROLE'] ?? '')) === $role) $errs[] = 'approval_role_already_exists';
            }
            if (strtolower((string)($fm['CREATED_BY'] ?? '')) === strtolower($actor)) $errs[] = 'maker_checker_violation';
            if ($errs === []) {
                $approvals[] = ['ROLE' => $role, 'USERNAME' => $actor, 'APPROVED_AT' => date(DateTimeInterface::ATOM), 'NOTE' => $note === '' ? 'approved' : $note];
                $fm['APPROVALS'] = $approvals;
                $required = (array)($fm['REQUIRES_APPROVALS'] ?? []);
                $approvedRoles = array_map(static fn(array $a): string => strtoupper((string)($a['ROLE'] ?? '')), $approvals);
                $all = true;
                foreach ($required as $r) if (!in_array(strtoupper((string)$r), $approvedRoles, true)) $all = false;
                $fm['STATUS'] = $all ? 'APPROVED' : 'IN_REVIEW';
                $verr = rfc_validate_frontmatter($fm, true);
                if ($verr !== []) $errs = array_merge($errs, $verr);
                else {
                    if (!rfc_atomic_write($file, rfc_render_file($fm, (string)$p['body']))) $errs[] = 'write_failed';
                    else {
                        rfc_append_audit([
                            'ts' => date(DateTimeInterface::ATOM),
                            'actor_username' => $actor,
                            'action' => 'RFC_APPROVED',
                            'rfc_id' => $rfc,
                            'role' => $role,
                            'request_id' => 'rfc-web-' . date('YmdHis'),
                            'meta_masked' => rfc_mask($note),
                        ]);
                        rfc_write_core_audit('RFC_APPROVED', ['rfc_id' => $rfc, 'role' => $role, 'actor_username' => $actor, 'source' => 'web']);
                        $msg = 'Approved.';
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Approve RFC</title></head>
<body>
<p><a href="view.php?rfc=<?= urlencode($rfc) ?>">Back</a></p>
<h1>Approve RFC</h1>
<?php if ($msg !== ''): ?><p><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($errs !== []): ?><ul><?php foreach ($errs as $e): ?><li><?= htmlspecialchars(rfc_mask((string)$e), ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul><?php endif; ?>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="rfc" value="<?= htmlspecialchars($rfc, ENT_QUOTES, 'UTF-8') ?>">
  <label>Role <input type="text" name="role" required></label><br><br>
  <label>Note <input type="text" name="note"></label><br><br>
  <button type="submit">Approve</button>
</form>
</body>
</html>

