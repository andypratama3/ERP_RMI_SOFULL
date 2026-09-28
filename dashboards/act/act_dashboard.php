<?php
/**
 * dashboards/act/act_dashboard.php
 * Accounting & Tax Dashboard — ACT focus
 */
require_once __DIR__ . '/../_dashboard_bootstrap.php';
require_once __DIR__ . '/../../_shared/rbac_ui.php';

require_login();
$bp  = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '');
$pdo = $GLOBALS['pdo'] ?? null;

/*
 * Dashboard scope policy:
 * - SYS/ADMIN/SUPERADMIN : global
 * - Manager ACT          : global (sama seperti SYS untuk monitoring dashboard ACT)
 * - Staff ACT            : tidak diberi bypass RBAC/action; halaman operasional tetap mengikuti gate masing-masing.
 *
 * KPI ACT di file ini memang berasal dari sumber pusat dan tidak boleh dipersempit office
 * untuk Manager ACT, karena manager mengontrol seluruh proses ACT lintas office.
 */
$__actUser = function_exists('auth_user') ? (array)auth_user() : [];
$__actUsername = strtoupper(trim((string)($__actUser['username'] ?? ($_SESSION['username'] ?? ''))));
$__actDept = strtoupper(trim((string)(
    (function_exists('auth_dept') ? auth_dept() : '')
    ?: ($__actUser['department'] ?? ($_SESSION['department'] ?? ''))
)));
$__actRole = strtoupper(trim((string)($__actUser['role'] ?? ($_SESSION['role'] ?? ''))));
$__actLevel = strtoupper(trim((string)($__actUser['level'] ?? ($_SESSION['level'] ?? ''))));

$__actIsSys = in_array($__actDept, ['SYS','SYSTEM','IT'], true)
    || in_array($__actRole, ['SYS','ADMIN','SUPERADMIN','OWNER'], true)
    || in_array($__actLevel, ['SYS','ADMIN','SUPERADMIN','OWNER'], true);

$__actIsManager = ($__actDept === 'ACT' && (
        in_array($__actRole, ['MANAGER','MGR'], true)
        || in_array($__actLevel, ['MANAGER','MGR'], true)
    ))
    || (bool)preg_match('/^MGRACT(?:_|$)/i', $__actUsername);

$__actGlobalDashboard = $__actIsSys || $__actIsManager;

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
function act_u($path) {
    $bp = $GLOBALS['BASE_PROJECT'] ?? '';
    return rtrim($bp, '/') . '/' . ltrim((string)$path, '/');
}
function act_money(float $v): string {
    if ($v >= 1e9) return 'Rp ' . number_format($v / 1e9, 1, ',', '.') . 'M';
    if ($v >= 1e6) return 'Rp ' . number_format($v / 1e6, 1, ',', '.') . 'jt';
    return 'Rp ' . number_format($v, 0, ',', '.');
}
function act_duration(?float $sec): string {
    if ($sec === null || $sec < 0) return '—';
    $s = (int)round($sec);
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    if ($d > 0) return $d . ' hari ' . $h . ' jam';
    if ($h > 0) return $h . ' jam ' . $m . ' menit';
    if ($m > 0) return $m . ' menit';
    return $s . ' detik';
}

function act_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function act_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function act_first_existing_column(PDO $pdo, string $table, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (act_column_exists($pdo, $table, $c)) return $c;
    }
    return null;
}

// ── Period filter ─────────────────────────────────────────────────────────
$period  = trim((string)($_GET['m'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
$mStart  = $period . '-01';
$mEnd    = date('Y-m-t', strtotime($mStart));
$today   = date('Y-m-d');

// ── KPI Queries ───────────────────────────────────────────────────────────
$kpi = [
    'assets_active'     => 0,
    'task_do_act'       => 0,      // current DOs waiting ACT action (existing backlog; dipertahankan)
    'task_faktur_pajak' => 0,      // dokumen Faktur Pajak belum ada
    'task_tukar_faktur' => 0,      // bukti Tukar Faktur belum ada
    'act_done_period'   => 0,      // transitioned from ACT to FIN during selected period
    'tax_inv_issued'    => 0,      // ISSUED in selected period
    'tax_inv_draft'     => 0,      // current DRAFT/NEW/PENDING backlog
    'gl_reversal_pend'  => 0,
    'avg_act_duration_sec' => null, // SCM delivered -> ACT handoff to FIN
    'act_duration_measured' => 0,   // DO with complete ACT start/finish timestamps
];

$sourceState = [
    'tax_invoice' => 'ok',
    'gl_reversal' => 'ok',
    'fixed_asset' => 'ok',
];

// Recent DO tasks
$recentDOTasks = [];

if ($pdo) {
    try {
        // Fixed Assets — current snapshot, not period based
        try {
            if (!act_table_exists($pdo, 'fa_assets')) {
                $sourceState['fixed_asset'] = 'na';
            } else {
                $kpi['assets_active'] = (int)$pdo->query("SELECT COUNT(*) FROM fa_assets WHERE deleted_at IS NULL AND status='ACTIVE'")->fetchColumn();
            }
        } catch (Throwable $e) { $sourceState['fixed_asset'] = 'error'; }

        // Task DO ACT — ONLY current ACT work.
        // Flow: SCM delivered/scm_done -> ACT review -> wait_payment (handoff to FIN).
        // Therefore wait_payment/act_done/paid are NOT active ACT tasks.
        $actStatuses = ['delivered', 'scm_done', 'act_review'];
        $inPh = implode(',', array_fill(0, count($actStatuses), '?'));
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE LOWER(COALESCE(status,'')) IN ({$inPh})");
            $st->execute($actStatuses);
            $kpi['task_do_act'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {}

        /*
         * Dashboard-only split Task DO ACT menjadi dua jenis dokumen.
         * act_do_tasks.php TIDAK diubah; file tersebut hanya menjadi referensi source-of-truth:
         * - File Faktur Pajak = bukti task Faktur Pajak selesai.
         * - File Tukar Faktur = bukti task Tukar Faktur selesai.
         * - WAIT PAYMENT sendiri tidak dianggap bukti dokumen selesai.
         *
         * Status yang dibaca mengikuti daftar operasional ACT: delivered + wait_payment.
         * KPI mengikuti periode dashboard berdasarkan sales_do.do_date.
         * Fallback dokumen lama tetap dihitung agar data historis tidak tampak hilang.
         */
        try {
            $taxPresentParts = [];
            if (act_column_exists($pdo, 'sales_do', 'act_tax_invoice_file')) {
                $taxPresentParts[] = "NULLIF(TRIM(sd.act_tax_invoice_file),'') IS NOT NULL";
            }
            if (act_table_exists($pdo, 'sales_do_act_tax_docs')
                && act_column_exists($pdo, 'sales_do_act_tax_docs', 'do_id')
                && act_column_exists($pdo, 'sales_do_act_tax_docs', 'file_path')) {
                $taxPresentParts[] = "EXISTS (SELECT 1 FROM sales_do_act_tax_docs td WHERE td.do_id=sd.id AND NULLIF(TRIM(td.file_path),'') IS NOT NULL)";
            }
            if (act_table_exists($pdo, 'ar_invoices')
                && act_column_exists($pdo, 'ar_invoices', 'do_code')
                && act_column_exists($pdo, 'ar_invoices', 'source_tax_file')) {
                $taxPresentParts[] = "EXISTS (SELECT 1 FROM ar_invoices ai WHERE ai.do_code=sd.do_code AND NULLIF(TRIM(ai.source_tax_file),'') IS NOT NULL)";
            }
            $taxPresentSql = $taxPresentParts ? '(' . implode(' OR ', $taxPresentParts) . ')' : '0=1';

            $st = $pdo->query("
                SELECT COUNT(*)
                FROM sales_do sd
                WHERE LOWER(COALESCE(sd.status,'')) IN ('delivered','wait_payment')
                  AND sd.do_date >= " . $pdo->quote($mStart) . "
                  AND sd.do_date < DATE_ADD(" . $pdo->quote($mEnd) . ", INTERVAL 1 DAY)
                  AND NOT ({$taxPresentSql})
            ");
            $kpi['task_faktur_pajak'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $kpi['task_faktur_pajak'] = 0;
        }

        try {
            $exchangePresentParts = [];
            if (act_column_exists($pdo, 'sales_do', 'act_exchange_doc_file')) {
                $exchangePresentParts[] = "NULLIF(TRIM(sd.act_exchange_doc_file),'') IS NOT NULL";
            }
            if (act_table_exists($pdo, 'sales_do_act_exchange_docs')
                && act_column_exists($pdo, 'sales_do_act_exchange_docs', 'do_id')
                && act_column_exists($pdo, 'sales_do_act_exchange_docs', 'file_path')) {
                $exchangePresentParts[] = "EXISTS (SELECT 1 FROM sales_do_act_exchange_docs ed WHERE ed.do_id=sd.id AND NULLIF(TRIM(ed.file_path),'') IS NOT NULL)";
            }
            // Recovery legacy — HARUS selaras dengan act_do_tasks.php.
            // Bukti lama dianggap ada hanya bila audit ACT_SAVE_EXCHANGE_AFTER_FIN
            // memang membawa file/path bukti; event audit saja tidak cukup.
            if (act_table_exists($pdo, 'system_audit_logs')
                && act_column_exists($pdo, 'system_audit_logs', 'record_id')
                && act_column_exists($pdo, 'system_audit_logs', 'action')) {

                $legacyFileChecks = [];
                foreach (['file_path','file','path','attachment','attachment_path','new_value','details','meta','metadata'] as $legacyCol) {
                    if (act_column_exists($pdo, 'system_audit_logs', $legacyCol)) {
                        $legacyFileChecks[] = "NULLIF(TRIM(CAST(al.`{$legacyCol}` AS CHAR)),'') IS NOT NULL";
                    }
                }

                if ($legacyFileChecks) {
                    $exchangePresentParts[] =
                        "EXISTS (SELECT 1 FROM system_audit_logs al
                                 WHERE al.record_id=sd.id
                                   AND al.action='ACT_SAVE_EXCHANGE_AFTER_FIN'
                                   AND (" . implode(' OR ', $legacyFileChecks) . "))";
                }
            }
            $exchangePresentSql = $exchangePresentParts ? '(' . implode(' OR ', $exchangePresentParts) . ')' : '0=1';

            $st = $pdo->query("
                SELECT COUNT(*)
                FROM sales_do sd
                WHERE LOWER(COALESCE(sd.status,'')) IN ('delivered','wait_payment')
                  AND sd.do_date >= " . $pdo->quote($mStart) . "
                  AND sd.do_date < DATE_ADD(" . $pdo->quote($mEnd) . ", INTERVAL 1 DAY)
                  AND NOT ({$exchangePresentSql})
            ");
            $kpi['task_tukar_faktur'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $kpi['task_tukar_faktur'] = 0;
        }

        // Recent DO tasks for ACT
        try {
            $st = $pdo->prepare("
                SELECT id, do_code, customers_code, status, office_code,
                       COALESCE(grand_total,total_amount,0) AS amount, do_date
                FROM sales_do
                WHERE LOWER(COALESCE(status,'')) IN ({$inPh})
                ORDER BY id DESC LIMIT 8
            ");
            $st->execute($actStatuses);
            $recentDOTasks = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        // ACT completed in selected period = handoff from ACT to FIN.
        // Use immutable audit transition to wait_payment so history remains measurable
        // even after FIN later changes the DO to paid.
        try {
            if (act_table_exists($pdo, 'sales_do_audit') && act_column_exists($pdo, 'sales_do_audit', 'status_to') && act_column_exists($pdo, 'sales_do_audit', 'created_at')) {
                $st = $pdo->prepare("
                    SELECT COUNT(DISTINCT do_id)
                    FROM sales_do_audit
                    WHERE LOWER(COALESCE(status_to,'')) = 'wait_payment'
                      AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
                ");
                $st->execute([$mStart, $mEnd]);
                $kpi['act_done_period'] = (int)$st->fetchColumn();
            }
        } catch (Throwable $e) {}

        // AVG Durasi ACT — read-only KPI, selaras alur operasional:
        // start  = DO masuk ACT setelah SCM selesai (delivered/scm_done/act_review)
        // finish = ACT handoff ke FIN (status_to = wait_payment).
        // Periode mengikuti waktu handoff ke FIN, sama dengan ACT Selesai Periode.
        // Audit trail diprioritaskan agar durasi tidak terus berjalan setelah ACT selesai.
        try {
            if (act_table_exists($pdo, 'sales_do_audit')
                && act_column_exists($pdo, 'sales_do_audit', 'do_id')
                && act_column_exists($pdo, 'sales_do_audit', 'status_to')
                && act_column_exists($pdo, 'sales_do_audit', 'created_at')) {

                $fallbackStart = act_column_exists($pdo, 'sales_do', 'scm_delivered_at')
                    ? 'sd.scm_delivered_at'
                    : 'NULL';

                $sqlActDuration = "
                    SELECT COUNT(*) AS measured_count, AVG(TIMESTAMPDIFF(SECOND, start_at, finish_at)) AS avg_sec
                    FROM (
                        SELECT f.do_id,
                               f.finish_at,
                               COALESCE(
                                   (
                                       SELECT MAX(a.created_at)
                                       FROM sales_do_audit a
                                       WHERE a.do_id = f.do_id
                                         AND LOWER(COALESCE(a.status_to,'')) IN ('delivered','scm_done','act_review')
                                         AND a.created_at <= f.finish_at
                                   ),
                                   {$fallbackStart}
                               ) AS start_at
                        FROM (
                            SELECT do_id, MIN(created_at) AS finish_at
                            FROM sales_do_audit
                            WHERE LOWER(COALESCE(status_to,'')) = 'wait_payment'
                              AND created_at >= ?
                              AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
                            GROUP BY do_id
                        ) f
                        LEFT JOIN sales_do sd ON sd.id = f.do_id
                    ) x
                    WHERE start_at IS NOT NULL
                      AND finish_at IS NOT NULL
                      AND finish_at >= start_at
                ";
                $st = $pdo->prepare($sqlActDuration);
                $st->execute([$mStart, $mEnd]);
                $dur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $kpi['act_duration_measured'] = (int)($dur['measured_count'] ?? 0);
                $kpi['avg_act_duration_sec'] = $dur['avg_sec'] !== null ? (float)$dur['avg_sec'] : null;
            }
        } catch (Throwable $e) {
            // KPI tambahan tidak boleh memutus dashboard utama.
            $kpi['act_duration_measured'] = 0;
            $kpi['avg_act_duration_sec'] = null;
        }

        // Tax Invoice:
        // - ISSUED follows selected dashboard period
        // - DRAFT/NEW/PENDING remains a current backlog
        try {
            if (!act_table_exists($pdo, 'tax_invoices')) {
                $sourceState['tax_invoice'] = 'na';
            } else {
                $dateCol = act_first_existing_column($pdo, 'tax_invoices', ['issued_at','invoice_date','tax_invoice_date','date','created_at','updated_at']);
                if ($dateCol) {
                    $sql = "SELECT COUNT(*) FROM tax_invoices WHERE UPPER(COALESCE(status,''))='ISSUED' AND `{$dateCol}` >= ? AND `{$dateCol}` < DATE_ADD(?, INTERVAL 1 DAY)";
                    $stTaxIssued = $pdo->prepare($sql);
                    $stTaxIssued->execute([$mStart, $mEnd]);
                    $kpi['tax_inv_issued'] = (int)$stTaxIssued->fetchColumn();
                } else {
                    // No usable date column: keep number safe at 0 and mark source unavailable
                    // rather than showing an all-time number under a monthly filter.
                    $sourceState['tax_invoice'] = 'no_date';
                }
                $kpi['tax_inv_draft'] = (int)$pdo->query("SELECT COUNT(*) FROM tax_invoices WHERE UPPER(COALESCE(status,'')) IN ('DRAFT','NEW','PENDING')")->fetchColumn();
            }
        } catch (Throwable $e) { $sourceState['tax_invoice'] = 'error'; }

        // GL Reversal Pending — current approval backlog.
        // Preserve 0 only when the source is actually readable.
        try {
            if (!act_table_exists($pdo, 'gl_reversal_requests')) {
                $sourceState['gl_reversal'] = 'na';
            } else {
                $kpi['gl_reversal_pend'] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM gl_reversal_requests WHERE UPPER(COALESCE(status,''))='PENDING'"
                )->fetchColumn();
            }
        } catch (Throwable $e) { $sourceState['gl_reversal'] = 'error'; }

    } catch (Throwable $e) {}
}

// ── Layout ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../_shared/rmi_layout.php';

$base = function_exists('rmi_assets_base') ? rmi_assets_base() : '';
$chartJs = function_exists('rmi_assets_foot') ? rmi_assets_foot(['chartjs'=>true,'jquery'=>false,'bootstrap'=>false,'datatables'=>false]) : '';

rmi_header('ACT Dashboard', [
    'active'     => 'dashboard',
    'subtitle'   => 'Accounting & Tax — Task DO, Fixed Asset, Tax Invoice',
    'extra_head' => '<style>
body{background:#0b1220;color:#e8ecf4}
.act-wrap{max-width:1160px;margin:0 auto}
.act-hdr{background:linear-gradient(135deg,rgba(120,53,15,.7),rgba(217,119,6,.5));border:1px solid rgba(245,158,11,.3);border-radius:16px;padding:18px 22px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.act-hdr h2{margin:0;font-size:19px;font-weight:800;color:#fff}
.act-hdr p{margin:3px 0 0;font-size:12px;color:rgba(255,255,255,.65)}
/* KPI */
.act-kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:10px;margin-bottom:14px}
.act-k{padding:13px;border-radius:12px;background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.09);border-top:3px solid var(--kc,#64748b);transition:.2s}
.act-k:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.3)}
.act-k-icon{font-size:18px;margin-bottom:5px}
.act-k-val{font-size:22px;font-weight:800;color:#fff;line-height:1;font-variant-numeric:tabular-nums}
.act-k-val.sm{font-size:14px}
.act-k-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-top:3px}
.act-k-sub{font-size:10px;color:#374151;margin-top:2px}
/* Section heading */
.act-sh{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#475569;margin:14px 0 8px;display:flex;align-items:center;gap:8px}
.act-sh::after{content:"";flex:1;height:1px;background:rgba(255,255,255,.08)}
/* Mini table */
.act-tbl{width:100%;border-collapse:collapse;font-size:12px}
.act-tbl th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#475569;padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;white-space:nowrap}
.act-tbl td{padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle}
.act-tbl tr:hover td{background:rgba(255,255,255,.02)}
.act-tbl tr:last-child td{border-bottom:none}
/* Quick links */
.act-links{display:flex;flex-wrap:wrap;gap:6px}
.act-link{padding:7px 13px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;border:1px solid rgba(255,255,255,.12);color:#e2e8f0;background:rgba(255,255,255,.05);transition:.15s;display:inline-flex;align-items:center;gap:5px}
.act-link:hover{background:rgba(255,255,255,.12);color:#fff}
.act-link.primary{background:linear-gradient(135deg,#d97706,#b45309);border-color:transparent;color:#fff}
/* Filter bar */
.act-filter{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;font-size:12px}
.act-period-btn{padding:4px 12px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:#94a3b8;font-size:11px;font-weight:600;text-decoration:none;transition:.12s}
.act-period-btn:hover,.act-period-btn.act{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.4);color:#fbbf24}
</style>',
    'actions' => [
        ['label' => '📚 Panduan', 'url' => act_u('/dashboards/act/panduan.php'), 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>
<div class="act-wrap">

  <!-- Manager Controlling Staff removed from ACT dashboard -->

  <!-- Header -->
  <div class="act-hdr">
    <div>
      <h2>📝 ACT Dashboard</h2>
      <p>Accounting & Tax — Task DO, Fixed Asset, Tax Invoice, Bank Rekon<?php if ($__actGlobalDashboard): ?> · Scope: <b>ALL</b><?php endif; ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="act-link" href="<?= h(act_u('/dashboards/index.php')) ?>">🏠 Home</a>
      <a class="act-link" href="<?= h(act_u('/sales/sales_control_tower.php')) ?>">🗼 Control Tower</a>
    </div>
  </div>

  <!-- Period filter -->
  <div class="act-filter">
    <span style="color:#64748b;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.04em">Periode:</span>
    <?php
    $periods = [];
    for ($i = 2; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-{$i} month"));
        $periods[$m] = date('M Y', strtotime("-{$i} month"));
    }
    foreach ($periods as $val => $lbl):
    ?>
      <a class="act-period-btn <?= $period===$val?'act':'' ?>"
         href="?m=<?= urlencode($val) ?>"><?= h($lbl) ?></a>
    <?php endforeach; ?>
    <span style="color:#374151;font-size:11px"><?= h($mStart) ?> → <?= h($mEnd) ?></span>
  </div>
<!-- KPI Tiles -->
  <div class="act-kpi">
    <a class="act-k" href="<?= h(act_u('/sales/act_do_tasks.php')) ?>" style="--kc:<?= $kpi['task_faktur_pajak']>0?'#3b82f6':'#64748b' ?>">
      <div class="act-k-icon">🧾</div>
      <div class="act-k-val" style="color:<?= $kpi['task_faktur_pajak']>0?'#60a5fa':'#fff' ?>"><?= number_format($kpi['task_faktur_pajak']) ?></div>
      <div class="act-k-lbl">Task Faktur Pajak</div>
      <div class="act-k-sub"><?= $kpi['task_faktur_pajak']>0?'⚡ Belum upload Faktur Pajak':'✓ Clear' ?></div>
    </a>
    <a class="act-k" href="<?= h(act_u('/sales/act_do_tasks.php')) ?>" style="--kc:<?= $kpi['task_tukar_faktur']>0?'#f59e0b':'#64748b' ?>">
      <div class="act-k-icon">📄</div>
      <div class="act-k-val" style="color:<?= $kpi['task_tukar_faktur']>0?'#fbbf24':'#fff' ?>"><?= number_format($kpi['task_tukar_faktur']) ?></div>
      <div class="act-k-lbl">Task Tukar Faktur</div>
      <div class="act-k-sub"><?= $kpi['task_tukar_faktur']>0?'⚡ Belum upload Bukti Tukar Faktur':'✓ Clear' ?></div>
    </a>
    <div class="act-k" style="--kc:#f59e0b">
      <div class="act-k-icon">⏱️</div>
      <div class="act-k-val sm" style="color:#fbbf24"><?= h(act_duration($kpi['avg_act_duration_sec'])) ?></div>
      <div class="act-k-lbl">Avg Durasi ACT</div>
      <div class="act-k-sub">SCM delivered → handoff FIN · <?= number_format($kpi['act_duration_measured']) ?> DO terukur</div>
    </div>
    <div class="act-k" style="--kc:#06b6d4">
      <div class="act-k-icon">✅</div>
      <div class="act-k-val" style="color:#67e8f9"><?= number_format($kpi['act_done_period']) ?></div>
      <div class="act-k-lbl">ACT Selesai Periode</div>
      <div class="act-k-sub">Handoff ke FIN (wait_payment)</div>
    </div>
    <a class="act-k" href="<?= h(act_u('/sales/tax_invoices.php')) ?>" style="--kc:#22c55e">
      <div class="act-k-icon">🧾</div>
      <div class="act-k-val"><?= number_format($kpi['tax_inv_issued']) ?></div>
      <div class="act-k-lbl">Tax Invoice Issued Periode</div>
      <div class="act-k-sub"><?= $sourceState['tax_invoice']==='ok' ? ('Draft current: '.number_format($kpi['tax_inv_draft'])) : 'Source tanggal belum tersedia' ?></div>
    </a>
    <a class="act-k" href="<?= h(act_u('/Fixed_Asset/index.php')) ?>" style="--kc:#8b5cf6">
      <div class="act-k-icon">🏗️</div>
      <div class="act-k-val"><?= number_format($kpi['assets_active']) ?></div>
      <div class="act-k-lbl">Fixed Asset Aktif</div>
      <div class="act-k-sub"><?= $sourceState['fixed_asset']==='ok' ? 'Current snapshot · Klik → FA Dashboard' : 'Source tidak tersedia' ?></div>
    </a>
</div>

  <!-- Recent DO Tasks -->
  <div class="row g-3 mb-3">

    <!-- Finance/AP widgets intentionally removed: owned by FIN dashboard -->

    <!-- Recent DO Tasks ACT -->
    <div class="col-12">
      <div class="rmi-card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="act-sh" style="margin:0">📋 Task DO ACT Aktif</div>
          <a href="<?= h(act_u('/sales/act_do_tasks.php')) ?>" style="font-size:11px;color:#f59e0b;text-decoration:none">Buka semua →</a>
        </div>
        <?php if (empty($recentDOTasks)): ?>
          <div style="color:#4b5563;font-size:13px;padding:16px 0;text-align:center">✅ Tidak ada task DO untuk ACT saat ini.</div>
        <?php else: ?>
          <div class="table-responsive">
          <table class="act-tbl">
            <thead><tr><th>DO Code</th><th>Customer</th><th>Status</th><th>Office</th><th style="text-align:right">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($recentDOTasks as $r):
              $statusColor = ['delivered'=>'#22c55e','scm_done'=>'#22c55e','act_review'=>'#f59e0b'][$r['status']??''] ?? '#94a3b8';
            ?>
              <tr>
                <td><a href="<?= h(act_u('/sales/sales_do_view.php?id='.(int)$r['id'])) ?>" style="color:#60a5fa;font-weight:600;font-size:12px"><?= h($r['do_code']??'—') ?></a></td>
                <td style="color:#94a3b8;font-size:11px"><?= h(mb_strimwidth((string)($r['customers_code']??''),0,14,'…')) ?></td>
                <td><span style="font-size:10px;font-weight:700;color:<?= $statusColor ?>;background:rgba(255,255,255,.06);padding:2px 7px;border-radius:6px"><?= h($r['status']??'') ?></span></td>
                <td style="font-size:11px"><span style="background:rgba(245,158,11,.15);color:#fbbf24;padding:1px 6px;border-radius:5px;font-weight:700"><?= h(strtoupper($r['office_code']??'')) ?></span></td>
                <td style="text-align:right;font-size:11px;font-weight:600;color:#67e8f9"><?= act_money((float)($r['amount']??0)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Quick Links -->
  <div class="rmi-card p-3">
    <div class="act-sh" style="margin-top:0">⚡ Quick Links</div>
    <div class="act-links">
      <a class="act-link primary" href="<?= h(act_u('/sales/act_do_tasks.php')) ?>">⚡ Task DO ACT</a>
      <a class="act-link primary" href="<?= h(act_u('/sales/tax_invoices.php')) ?>">🧾 Tax Invoice</a>
      <a class="act-link" href="<?= h(act_u('/purchases/bank_recon.php')) ?>">🏦 Bank Rekonsiliasi</a>
      <a class="act-link" href="<?= h(act_u('/purchases/gl_reversal_approvals.php')) ?>">🔄 GL Reversal</a>
      <a class="act-link" href="<?= h(act_u('/Fixed_Asset/index.php')) ?>">🏗️ Fixed Asset</a>
      <a class="act-link" href="<?= h(act_u('/fixed_asset/depreciation.php')) ?>">📉 Depresiasi</a>
      <a class="act-link" href="<?= h(act_u('/Fixed_Asset/tax_annual.php')) ?>">📊 Pajak Tahunan</a>
      <a class="act-link" href="<?= h(act_u('/master/company_bank_accounts.php')) ?>">🏧 Rekening Perusahaan</a>
      <a class="act-link" href="<?= h(act_u('/dashboards/finance/dashboard_detail.php')) ?>">💰 Finance Detail</a>
      <a class="act-link" href="<?= h(act_u('/master/master_tax.php')) ?>">🧾 Master Tax</a>
      <a class="act-link" href="<?= h(act_u('/kpi/kpi_center.php')) ?>">📈 KPI Center</a>
      <a class="act-link" href="<?= h(act_u('/absensi/index.php')) ?>">📅 Absensi</a>
    </div>
  </div>

  <?php
  $auditModules = ['ACT', 'GL', 'TAX_INVOICE', 'FIXED_ASSET', 'BANK_RECON'];
  $auditLimit   = 10;
  require __DIR__ . '/../_audit_log_widget.php';
  ?>

</div>
<?php rmi_footer(); ?>
