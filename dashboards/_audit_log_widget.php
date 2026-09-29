<?php
if (!function_exists('rmi_icon')) { require_once __DIR__ . '/../_shared/rmi_icons.php'; }
/**
 * _audit_log_widget.php — Widget Audit Log untuk landing page masing-masing dept.
 *
 * Cara pakai di setiap landing page dashboard:
 *
 *   $auditModules = ['SALES', 'DO', 'CRM'];   // modul yang relevan
 *   $auditLimit   = 10;                         // jumlah baris (default 10)
 *   require __DIR__ . '/../_audit_log_widget.php';
 *
 * Widget ini fail-soft: tidak crash jika tabel belum ada.
 * Desain mengikuti style dashboard yang ada (card, badge, timeline).
 */

if (!defined('_AUDIT_WIDGET_INCLUDED')) define('_AUDIT_WIDGET_INCLUDED', true);

$_aw_pdo     = $GLOBALS['pdo'] ?? null;
$_aw_modules = $auditModules ?? [];
$_aw_limit   = max(5, min(50, (int)($auditLimit ?? 10)));
$_aw_base    = function_exists('rmi_layout_base_project') ? rmi_layout_base_project()
             : ($GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? (string)BASE_PROJECT : ''));

if (!function_exists('_aw_h')) {
    function _aw_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$_aw_logs = [];
if ($_aw_pdo instanceof PDO) {
    // system_audit_logs → cek apakah table ada
    try {
        $chk = $_aw_pdo->query("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema=DATABASE() AND table_name='system_audit_logs'");
        $hasSys = (int)$chk->fetchColumn() > 0;
    } catch (Throwable $e) { $hasSys = false; }

    try {
        $chk2 = $_aw_pdo->query("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema=DATABASE() AND table_name='erp_audit_log'");
        $hasErp = (int)$chk2->fetchColumn() > 0;
    } catch (Throwable $e) { $hasErp = false; }

    if ($hasSys && !empty($_aw_modules)) {
        try {
            $in = implode(',', array_fill(0, count($_aw_modules), '?'));
            $st = $_aw_pdo->prepare("
                SELECT module, action, record_code, username, description, created_at
                FROM system_audit_logs
                WHERE module IN ({$in})
                ORDER BY created_at DESC, id DESC
                LIMIT ?
            ");
            $p = array_values($_aw_modules);
            $p[] = $_aw_limit;
            $st->execute($p);
            $_aw_logs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }

    // Jika system_audit_logs kosong, coba erp_audit_log
    if (empty($_aw_logs) && $hasErp && !empty($_aw_modules)) {
        try {
            $in = implode(',', array_fill(0, count($_aw_modules), '?'));
            $st = $_aw_pdo->prepare("
                SELECT module, action, entity_key AS record_code, username, '' AS description, created_at
                FROM erp_audit_log
                WHERE module IN ({$in})
                ORDER BY created_at DESC, id DESC
                LIMIT ?
            ");
            $p = array_values($_aw_modules);
            $p[] = $_aw_limit;
            $st->execute($p);
            $_aw_logs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }
}

// Warna badge per action prefix
function _aw_action_color(string $action): string {
    $a = strtoupper($action);
    if (str_contains($a,'DELETE') || str_contains($a,'CANCEL')) return '#ef4444';
    if (str_contains($a,'CREATE') || str_contains($a,'ADD'))    return '#22c55e';
    if (str_contains($a,'APPROVE') || str_contains($a,'POST'))  return '#3b82f6';
    if (str_contains($a,'UPDATE') || str_contains($a,'EDIT'))   return '#f59e0b';
    if (str_contains($a,'SUBMIT'))                              return '#8b5cf6';
    if (str_contains($a,'LOGIN') || str_contains($a,'LOGOUT'))  return '#64748b';
    return '#6b7280';
}
function _aw_action_icon(string $action): string {
    $a = strtoupper($action);
    if (str_contains($a,'DELETE') || str_contains($a,'CANCEL')) return rmi_icon('x');
    if (str_contains($a,'CREATE') || str_contains($a,'ADD'))    return rmi_icon('check');
    if (str_contains($a,'APPROVE') || str_contains($a,'POST'))  return rmi_icon('outbox');
    if (str_contains($a,'UPDATE') || str_contains($a,'EDIT'))   return rmi_icon('memo');
    if (str_contains($a,'SUBMIT'))                              return rmi_icon('doc');
    if (str_contains($a,'LOGIN'))                               return rmi_icon('gear');
    return rmi_icon('clipboard');
}
function _aw_time_ago(string $ts): string {
    $diff = time() - strtotime($ts);
    if ($diff < 60)    return $diff . 'd lalu';
    if ($diff < 3600)  return intdiv($diff,60) . 'mnt lalu';
    if ($diff < 86400) return intdiv($diff,3600) . 'j lalu';
    return intdiv($diff,86400) . 'hr lalu';
}

$_aw_module_label = implode(', ', (array)($_aw_modules));
?>

<div class="card" style="margin-top:14px">
  <div class="card-body" style="padding:14px 18px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div>
        <span style="font-size:13px;font-weight:700;color:#e5e7eb"><?=rmi_icon('clipboard')?> Audit Log Terbaru</span>
        <span style="font-size:11px;color:#64748b;margin-left:8px"><?= _aw_h($_aw_module_label) ?></span>
      </div>
      <a href="<?= _aw_h($_aw_base) ?>/master/audit_logs.php"
         style="font-size:11px;color:#3b82f6;text-decoration:none">
        Lihat semua →
      </a>
    </div>

    <?php if (empty($_aw_logs)): ?>
      <div style="text-align:center;padding:20px 0;color:var(--rmi-muted);font-size:13px">
        Belum ada aktivitas tercatat.
      </div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($_aw_logs as $_aw_log):
          $col  = _aw_action_color((string)($_aw_log['action'] ?? ''));
          $icon = _aw_action_icon((string)($_aw_log['action'] ?? ''));
          $ago  = _aw_time_ago((string)($_aw_log['created_at'] ?? date('Y-m-d H:i:s')));
          $mod  = (string)($_aw_log['module'] ?? '');
          $act  = (string)($_aw_log['action'] ?? '');
          $code = (string)($_aw_log['record_code'] ?? '');
          $user = (string)($_aw_log['username'] ?? '—');
          $desc = (string)($_aw_log['description'] ?? '');
        ?>
          <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 10px;border-radius:8px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06)">
            <span style="font-size:16px;flex-shrink:0;margin-top:1px"><?= $icon ?></span>
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                <span style="font-size:11px;font-weight:700;color:<?= $col ?>;background:<?= $col ?>22;padding:1px 7px;border-radius:4px;white-space:nowrap">
                  <?= _aw_h($act) ?>
                </span>
                <?php if ($mod): ?>
                  <span style="font-size:10px;color:#64748b"><?= _aw_h($mod) ?></span>
                <?php endif; ?>
                <?php if ($code): ?>
                  <span style="font-size:11px;color:#94a3b8;font-family:monospace"><?= _aw_h(mb_strimwidth($code,0,30,'…')) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($desc): ?>
                <div style="font-size:11px;color:#94a3b8;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= _aw_h(mb_strimwidth($desc,0,80,'…')) ?>
                </div>
              <?php endif; ?>
            </div>
            <div style="text-align:right;flex-shrink:0">
              <div style="font-size:11px;color:#94a3b8"><?= _aw_h($user) ?></div>
              <div style="font-size:10px;color:var(--rmi-muted)"><?= _aw_h($ago) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
// Cleanup variables
unset($_aw_pdo,$_aw_modules,$_aw_limit,$_aw_base,$_aw_logs,$_aw_log,$_aw_module_label,$col,$icon,$ago,$mod,$act,$code,$user,$desc);
?>
