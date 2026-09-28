<?php
// KPI Snapshot & Lock — maker/checker, two-step approval, transactional lock.
require_once __DIR__ . '/_kpi_bootstrap.php';
require_once __DIR__ . '/_kpi_metrics.php';
require_once __DIR__ . '/_kpi_policy.php';
$pdo = kpi_require_pdo();

require_once __DIR__ . '/../_shared/rbac.php';
if (!function_exists('rbac_require')) {
    http_response_code(500);
    exit('RBAC unavailable');
}
rbac_require($pdo, 'KPI.VIEW');

kpi_ensure_compat_schema($pdo);

function kpi_snapshot_username(): string
{
    foreach (['username', 'user_name', 'name'] as $key) {
        $value = trim((string)($_SESSION[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return 'system';
}

function kpi_snapshot_tables_ready(PDO $pdo): bool
{
    return kpi_table_exists($pdo, 'kpi_snapshot')
        && kpi_table_exists($pdo, 'kpi_snapshot_items')
        && kpi_table_exists($pdo, 'kpi_snapshot_approvals');
}

function kpi_snapshot_scope_tables(string $scope): array
{
    if ($scope === 'OFFICE') return ['office' => 'kpi_office'];
    if ($scope === 'EMPLOYEE') return ['employee' => 'kpi_employee'];
    return ['office' => 'kpi_office', 'employee' => 'kpi_employee'];
}

function kpi_snapshot_validate_scope(string $scope): string
{
    return in_array($scope, ['BOTH', 'OFFICE', 'EMPLOYEE'], true) ? $scope : 'BOTH';
}

function kpi_snapshot_has_existing(PDO $pdo, string $month, string $scope): bool
{
    $st = $pdo->prepare("SELECT 1 FROM kpi_snapshot WHERE snapshot_month=:m AND scope=:s LIMIT 1");
    $st->execute([':m' => $month, ':s' => $scope]);
    return (bool)$st->fetchColumn();
}

function kpi_snapshot_collect_final(PDO $pdo, string $month, string $scope): array
{
    $payload = [];
    foreach (kpi_snapshot_scope_tables($scope) as $payloadKey => $table) {
        if (!kpi_table_exists($pdo, $table)) {
            throw new RuntimeException("Tabel {$table} belum tersedia.");
        }
        $monthCol = kpi_month_col($pdo, $table);
        $statusCol = kpi_status_col($pdo, $table);
        $deletedCol = kpi_deleted_col($pdo, $table);
        $where = "{$monthCol}=:m AND {$statusCol}='FINAL'";
        if ($deletedCol) $where .= " AND {$deletedCol} IS NULL";

        $st = $pdo->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC FOR UPDATE");
        $st->execute([':m' => $month]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            throw new RuntimeException("Tidak ada data {$payloadKey} berstatus FINAL untuk periode {$month}.");
        }
        $payload[$payloadKey] = $rows;
    }
    return $payload;
}

function kpi_snapshot_lock_final(PDO $pdo, string $month, string $scope): void
{
    foreach (kpi_snapshot_scope_tables($scope) as $table) {
        $monthCol = kpi_month_col($pdo, $table);
        $statusCol = kpi_status_col($pdo, $table);
        $deletedCol = kpi_deleted_col($pdo, $table);
        $where = "{$monthCol}=:m AND {$statusCol}='FINAL'";
        if ($deletedCol) $where .= " AND {$deletedCol} IS NULL";
        $st = $pdo->prepare("UPDATE {$table} SET {$statusCol}='LOCKED' WHERE {$where}");
        $st->execute([':m' => $month]);
    }
}

function kpi_snapshot_create_from_approval(PDO $pdo, array $approval): int
{
    $month = (string)$approval['snapshot_month'];
    $scope = kpi_snapshot_validate_scope((string)$approval['scope']);
    $maker = (string)$approval['maker_username'];
    $checker = (string)$approval['checker_username'];
    $reason = (string)$approval['approval_reason'];
    $approvalId = (int)$approval['id'];

    if (kpi_snapshot_has_existing($pdo, $month, $scope)) {
        throw new RuntimeException('Snapshot periode dan scope ini sudah ada.');
    }

    $payload = kpi_snapshot_collect_final($pdo, $month, $scope);
    $payload['_approval'] = [
        'approval_id' => $approvalId,
        'maker' => $maker,
        'checker' => $checker,
        'reason' => $reason,
        'approved_at' => date('c'),
    ];
    $payload['_meta'] = [
        'month' => $month,
        'scope' => $scope,
        'generated_at' => date('c'),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Payload snapshot gagal dibuat.');
    }
    $hash = hash('sha256', $json);
    $rowCount = 0;
    foreach (['office', 'employee'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) $rowCount += count($payload[$key]);
    }

    $cols = kpi_table_columns($pdo, 'kpi_snapshot');
    $hasHash = in_array('payload_hash', $cols, true);
    $hasRows = in_array('row_count', $cols, true);
    $hasApproval = in_array('approval_id', $cols, true);

    $fields = ['snapshot_month', 'scope', 'actor', 'created_at'];
    $values = [':m', ':s', ':a', 'NOW()'];
    $params = [':m' => $month, ':s' => $scope, ':a' => $maker];
    if ($hasHash) { $fields[] = 'payload_hash'; $values[] = ':h'; $params[':h'] = $hash; }
    if ($hasRows) { $fields[] = 'row_count'; $values[] = ':rc'; $params[':rc'] = $rowCount; }
    if ($hasApproval) { $fields[] = 'approval_id'; $values[] = ':aid'; $params[':aid'] = $approvalId; }

    $sql = "INSERT INTO kpi_snapshot(" . implode(',', $fields) . ") VALUES(" . implode(',', $values) . ")";
    $pdo->prepare($sql)->execute($params);
    $snapshotId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO kpi_snapshot_items(snapshot_id,payload_json,created_at) VALUES(:id,:json,NOW())")
        ->execute([':id' => $snapshotId, ':json' => $json]);

    kpi_snapshot_lock_final($pdo, $month, $scope);
    return $snapshotId;
}

if (!kpi_snapshot_tables_ready($pdo)) {
    kpi_header('KPI Snapshot & Lock');
    kpi_nav('snapshot');
    echo "<div class='card'><span class='badge danger'>MISSING</span> Struktur Snapshot belum lengkap. Jalankan <code>kpi_enterprise_tables.sql</code>.</div>";
    kpi_footer();
    exit;
}

$currentUser = kpi_snapshot_username();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'submit_approval') {
        kpi_can_manage_or_die('kpi_snapshot.php');
        $month = trim((string)($_POST['month'] ?? ''));
        $scope = kpi_snapshot_validate_scope(trim((string)($_POST['scope'] ?? 'BOTH')));
        $checker = trim((string)($_POST['checker_username'] ?? ''));
        $reason = trim((string)($_POST['approval_reason'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) die('Month wajib format YYYY-MM.');
        if ($checker === '' || !kpi_user_exists_active($pdo, $checker)) die('Checker tidak ditemukan atau nonaktif.');
        if (strcasecmp($checker, $currentUser) === 0) die('Checker harus berbeda dari maker.');
        if (mb_strlen($reason) < 10) die('Alasan approval minimal 10 karakter.');
        if (kpi_snapshot_has_existing($pdo, $month, $scope)) die('Snapshot periode dan scope ini sudah ada.');

        $pending = $pdo->prepare("SELECT 1 FROM kpi_snapshot_approvals WHERE snapshot_month=:m AND scope=:s AND approval_status='PENDING' LIMIT 1");
        $pending->execute([':m' => $month, ':s' => $scope]);
        if ($pending->fetchColumn()) die('Masih ada approval PENDING untuk periode dan scope ini.');

        $st = $pdo->prepare("INSERT INTO kpi_snapshot_approvals
            (snapshot_id,snapshot_month,scope,maker_username,checker_username,approval_reason,approval_status,created_at,updated_at)
            VALUES(NULL,:m,:s,:maker,:checker,:reason,'PENDING',NOW(),NOW())");
        $st->execute([
            ':m' => $month,
            ':s' => $scope,
            ':maker' => $currentUser,
            ':checker' => $checker,
            ':reason' => $reason,
        ]);
        kpi_audit($pdo, 'kpi_snapshot', 'SUBMIT_APPROVAL', $month, "scope={$scope};checker={$checker}");
        rmi_redirect('kpi_snapshot.php?submitted=1');
    }

    if ($action === 'approve' || $action === 'reject') {
        $approvalId = (int)($_POST['approval_id'] ?? 0);
        $rejectReason = trim((string)($_POST['rejection_reason'] ?? ''));
        if ($approvalId <= 0) die('Approval ID tidak valid.');

        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM kpi_snapshot_approvals WHERE id=:id FOR UPDATE");
            $st->execute([':id' => $approvalId]);
            $approval = $st->fetch(PDO::FETCH_ASSOC);
            if (!$approval) throw new RuntimeException('Approval tidak ditemukan.');
            if ((string)$approval['approval_status'] !== 'PENDING') throw new RuntimeException('Approval sudah diproses.');
            if (strcasecmp((string)$approval['checker_username'], $currentUser) !== 0) {
                throw new RuntimeException('Hanya checker yang ditunjuk yang dapat memproses approval ini.');
            }
            if (!kpi_is_manager_plus()) {
                throw new RuntimeException('Checker harus level MANAGER atau SYS.');
            }
            if (strcasecmp((string)$approval['maker_username'], $currentUser) === 0) {
                throw new RuntimeException('Maker tidak boleh menyetujui pengajuannya sendiri.');
            }

            if ($action === 'reject') {
                if (mb_strlen($rejectReason) < 5) throw new RuntimeException('Alasan penolakan minimal 5 karakter.');
                $pdo->prepare("UPDATE kpi_snapshot_approvals SET approval_status='REJECTED', rejection_reason=:r, rejected_at=NOW(), updated_at=NOW() WHERE id=:id")
                    ->execute([':r' => $rejectReason, ':id' => $approvalId]);
                kpi_audit($pdo, 'kpi_snapshot', 'REJECT', (string)$approval['snapshot_month'], "approval_id={$approvalId};reason={$rejectReason}");
                $pdo->commit();
                rmi_redirect('kpi_snapshot.php?rejected=1');
            }

            $snapshotId = kpi_snapshot_create_from_approval($pdo, $approval);
            $pdo->prepare("UPDATE kpi_snapshot_approvals
                SET snapshot_id=:sid, approval_status='APPROVED', approved_at=NOW(), updated_at=NOW()
                WHERE id=:id")
                ->execute([':sid' => $snapshotId, ':id' => $approvalId]);
            kpi_audit($pdo, 'kpi_snapshot', 'APPROVE_AND_LOCK', (string)$approval['snapshot_month'], "approval_id={$approvalId};snapshot_id={$snapshotId};scope={$approval['scope']}");
            $pdo->commit();
            rmi_redirect('kpi_snapshot.php?view=' . $snapshotId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            die('Gagal memproses approval: ' . h($e->getMessage()));
        }
    }
}

kpi_header('KPI Snapshot & Lock');
kpi_nav('snapshot');

if (isset($_GET['view'])) {
    $id = (int)$_GET['view'];
    $st = $pdo->prepare("SELECT s.*,i.payload_json,a.maker_username,a.checker_username,a.approval_reason,a.approval_status,a.approved_at
        FROM kpi_snapshot s
        JOIN kpi_snapshot_items i ON i.snapshot_id=s.id
        LEFT JOIN kpi_snapshot_approvals a ON a.snapshot_id=s.id
        WHERE s.id=:id LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "<div class='card'>Snapshot tidak ditemukan.</div>";
        kpi_footer();
        exit;
    }
    echo "<div class='card'><a href='kpi_snapshot.php'>← Kembali</a><h3>Snapshot #" . h($row['id']) . "</h3>";
    echo "<div class='muted'>Periode: " . h($row['snapshot_month']) . " • Scope: " . h($row['scope']) . " • Maker: " . h($row['maker_username']) . " • Checker: " . h($row['checker_username']) . " • Approved: " . h($row['approved_at']) . "</div>";
    echo "<div class='muted'>Alasan: " . h($row['approval_reason']) . "</div></div>";
    $payload = json_decode((string)$row['payload_json'], true);
    echo "<div class='card'><h3>Payload Read-only</h3><pre style='white-space:pre-wrap;overflow:auto'>" . h(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "</pre></div>";
    kpi_footer();
    exit;
}

$defaultMonth = date('Y-m');
$list = $pdo->query("SELECT s.*,a.checker_username,a.approved_at FROM kpi_snapshot s LEFT JOIN kpi_snapshot_approvals a ON a.snapshot_id=s.id ORDER BY s.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$approvalMonth = trim((string)($_GET['approval_month'] ?? ''));
$approvalChecker = trim((string)($_GET['approval_checker'] ?? ''));
$approvalStatus = strtoupper(trim((string)($_GET['approval_status'] ?? '')));
$approvalWhere = [];
$approvalParams = [];
if ($approvalMonth !== '') {
    $approvalWhere[] = 'snapshot_month = :approval_month';
    $approvalParams[':approval_month'] = $approvalMonth;
}
if ($approvalChecker !== '') {
    $approvalWhere[] = 'LOWER(TRIM(checker_username)) LIKE :approval_checker';
    $approvalParams[':approval_checker'] = '%' . strtolower($approvalChecker) . '%';
}
if (in_array($approvalStatus, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
    $approvalWhere[] = 'approval_status = :approval_status';
    $approvalParams[':approval_status'] = $approvalStatus;
}
$approvalSql = 'SELECT * FROM kpi_snapshot_approvals'
    . ($approvalWhere ? ' WHERE ' . implode(' AND ', $approvalWhere) : '')
    . ' ORDER BY id DESC LIMIT 100';
$approvalStmt = $pdo->prepare($approvalSql);
$approvalStmt->execute($approvalParams);
$approvals = $approvalStmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['submitted'])) echo "<div class='card' style='border-left:4px solid #22c55e'><b>Pengajuan snapshot berhasil dibuat.</b> Menunggu checker.</div>";
if (isset($_GET['rejected'])) echo "<div class='card' style='border-left:4px solid #f59e0b'><b>Pengajuan snapshot ditolak.</b></div>";

if (kpi_can_manage()) {
    echo "<div class='card'><h3>Ajukan Snapshot & Lock</h3><form method='post'><div class='row'>";
    if (function_exists('csrf_field')) echo csrf_field();
    echo "<input type='hidden' name='action' value='submit_approval'>";
    echo "<div class='col'><label>Month</label><input type='month' name='month' required value='" . h($defaultMonth) . "'></div>";
    echo "<div class='col'><label>Scope</label><select name='scope'><option value='BOTH'>BOTH</option><option value='OFFICE'>OFFICE</option><option value='EMPLOYEE'>EMPLOYEE</option></select></div>";
    echo "<div class='col'><label>Checker Username</label><input name='checker_username' required></div>";
    echo "<div class='col'><label>Approval Reason</label><input name='approval_reason' required minlength='10' maxlength='500'></div>";
    echo "<div class='col'><label>&nbsp;</label><button type='submit'>Ajukan Snapshot</button></div></div>";
    echo "<div class='muted'>Tahap ini hanya membuat status PENDING. Data baru dikunci setelah checker yang ditunjuk menyetujui.</div></form></div>";
} else {
    echo kpi_sys_only_data_notice_html('KPI Snapshot');
}

echo "<div class='card'><h3>Alur Snapshot & Lock</h3><div class='muted'>DRAFT → FINAL → Maker mengajukan → PENDING → Checker Approve → Snapshot dibuat → data menjadi LOCKED. Jika ditolak, status menjadi REJECTED dan data tetap FINAL.</div></div>";

echo "<div class='card'><h3>Filter Approval</h3><form method='get' class='row'>";
echo "<div class='col'><label>Month</label><input type='month' name='approval_month' value='" . h($approvalMonth) . "'></div>";
echo "<div class='col'><label>Checker</label><input name='approval_checker' value='" . h($approvalChecker) . "' placeholder='username checker'></div>";
echo "<div class='col'><label>Status</label><select name='approval_status'><option value=''>-- semua --</option>";
foreach (['PENDING','APPROVED','REJECTED'] as $statusOption) {
    echo "<option value='" . h($statusOption) . "'" . ($approvalStatus === $statusOption ? ' selected' : '') . ">" . h($statusOption) . "</option>";
}
echo "</select></div><div class='col' style='align-self:flex-end'><button type='submit'>Apply</button> <a class='btn secondary' href='kpi_snapshot.php'>Reset</a></div></form></div>";

echo "<div class='card'><h3>Approval Snapshot</h3><table><thead><tr><th>ID</th><th>Month</th><th>Scope</th><th>Maker</th><th>Checker</th><th>Status</th><th>Created</th><th>Aksi</th></tr></thead><tbody>";
foreach ($approvals as $a) {
    $canProcess = (string)$a['approval_status'] === 'PENDING'
        && strcasecmp((string)$a['checker_username'], $currentUser) === 0
        && kpi_is_manager_plus();
    echo "<tr><td>" . h($a['id']) . "</td><td>" . h($a['snapshot_month']) . "</td><td>" . h($a['scope']) . "</td><td>" . h($a['maker_username']) . "</td><td>" . h($a['checker_username']) . "</td><td>" . h($a['approval_status']) . "</td><td>" . h($a['created_at']) . "</td><td>";
    if ($canProcess) {
        echo "<form method='post' style='display:inline-block;margin-right:6px'>";
        if (function_exists('csrf_field')) echo csrf_field();
        echo "<input type='hidden' name='action' value='approve'><input type='hidden' name='approval_id' value='" . h($a['id']) . "'><button type='submit'>Approve & Lock</button></form>";
        echo "<form method='post' style='display:inline-flex;gap:5px'>";
        if (function_exists('csrf_field')) echo csrf_field();
        echo "<input type='hidden' name='action' value='reject'><input type='hidden' name='approval_id' value='" . h($a['id']) . "'><input name='rejection_reason' required minlength='5' placeholder='alasan tolak'><button class='btn danger' type='submit'>Reject</button></form>";
    } elseif ((int)($a['snapshot_id'] ?? 0) > 0) {
        echo "<a href='?view=" . h($a['snapshot_id']) . "'>View Snapshot</a>";
    } else {
        echo "-";
    }
    echo "</td></tr>";
}
if (!$approvals) echo "<tr><td colspan='8' class='muted'>Belum ada approval.</td></tr>";
echo "</tbody></table></div>";

echo "<div class='card'><h3>Recent Snapshots</h3><table><thead><tr><th>ID</th><th>Month</th><th>Scope</th><th>Maker</th><th>Checker</th><th>Time</th><th></th></tr></thead><tbody>";
foreach ($list as $r) {
    echo "<tr><td>" . h($r['id']) . "</td><td>" . h($r['snapshot_month']) . "</td><td>" . h($r['scope']) . "</td><td>" . h($r['actor']) . "</td><td>" . h($r['checker_username']) . "</td><td>" . h($r['created_at']) . "</td><td><a href='?view=" . h($r['id']) . "'>View</a></td></tr>";
}
if (!$list) echo "<tr><td colspan='7' class='muted'>Belum ada snapshot.</td></tr>";
echo "</tbody></table></div>";

kpi_footer();
