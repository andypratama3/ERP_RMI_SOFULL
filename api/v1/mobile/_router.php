<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth_guard.php';
require_once __DIR__ . '/_rate_limit.php';
require_once __DIR__ . '/_idempotency.php';
require_once __DIR__ . '/_audit.php';

$endpoint = (string)($MOBILE_ENDPOINT ?? '');
if ($endpoint === '') {
    mobile_err('ERR_NOT_FOUND', 'Endpoint not found.', 404);
}

$pdo = mobile_pdo();
mobile_require_request_id();

function mobile_issue_auth_tokens(PDO $pdo, array $user, string $deviceId): array
{
    $claims = [
        'uid' => (int)$user['id'],
        'username' => (string)$user['username'],
        'role' => (string)($user['role'] ?? ''),
        'level' => (string)($user['level'] ?? ''),
        'department' => (string)($user['department'] ?? ''),
        'office_code' => (string)($user['office_code'] ?? ''),
    ];
    $access = mobile_jwt_issue($claims, 15 * 60);
    $refresh = mobile_refresh_issue(
        $pdo,
        (int)$user['id'],
        $deviceId,
        mobile_mask_ip((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
    );
    return [$access, $refresh];
}

function mobile_fetch_do(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM sales_do WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function mobile_do_can_transition(string $from, string $to): bool
{
    $map = [
        'crm_to_wqs' => ['wqs_processing'],
        'sent_wqs' => ['wqs_processing'],
        'wqs_processing' => ['ready_scm'],
        'ready_scm' => ['on_delivery'],
        'on_delivery' => ['delivered'],
        'delivered' => ['wait_payment'],
        'wait_payment' => ['paid'],
    ];
    return in_array($to, $map[$from] ?? [], true);
}

function mobile_transition_status_for_action(string $action): ?string
{
    $a = strtolower(trim($action));
    $m = [
        'wqs_start' => 'wqs_processing',
        'wqs_ready' => 'ready_scm',
        'scm_start' => 'on_delivery',
        'scm_delivered' => 'delivered',
        'act_send_fin' => 'wait_payment',
        'fin_paid' => 'paid',
    ];
    return $m[$a] ?? null;
}

function mobile_user_can_do_action(array $u, string $action, ?PDO $pdo = null): bool
{
    $admin = !empty(mobile_permissions($u)['admin']);
    if ($admin) return true;
    $a = strtolower($action);
    $permMap = [
        'wqs_' => ['SALES.EDIT'],
        'scm_' => ['SALES.EDIT'],
        'act_' => ['SALES.EDIT'],
        'fin_' => ['SALES.EDIT'],
    ];
    foreach ($permMap as $prefix => $perms) {
        if (str_starts_with($a, $prefix)) {
            if ($pdo instanceof PDO && function_exists('mobile_rbac_can_any') && mobile_rbac_ready($pdo)) {
                return mobile_rbac_can_any($pdo, $u, $perms);
            }
            return !empty(mobile_permissions($u)['sales']);
        }
    }
    if ($pdo instanceof PDO && function_exists('mobile_rbac_can_any') && mobile_rbac_ready($pdo)) {
        return mobile_rbac_can_any($pdo, $u, ['SALES.CREATE', 'SALES.EDIT', 'SALES.VIEW']);
    }
    return !empty(mobile_permissions($u)['sales']);
}

function mobile_chat_member_guard(PDO $pdo, int $userId, int $channelId): void
{
    $st = $pdo->prepare("SELECT id FROM chat_channel_members WHERE channel_id=? AND user_id=? LIMIT 1");
    $st->execute([$channelId, $userId]);
    if (!$st->fetch(PDO::FETCH_ASSOC)) {
        mobile_err('ERR_FORBIDDEN', 'Forbidden channel.', 403);
    }
}

function mobile_mutation_response_cached(array $cached): void
{
    if (isset($cached['ok']) && isset($cached['request_id'])) {
        mobile_send_json($cached, 200);
    }
}

try {
    if ($endpoint === 'auth/login') {
        mobile_require_method('POST');
        $in = mobile_get_input();
        $idem = mobile_idem_key_required();
        $cached = mobile_idem_begin($pdo, 0, $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $username = trim((string)($in['username'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $deviceId = substr(trim((string)($in['device_id'] ?? 'unknown-device')), 0, 120);
        if ($username === '' || $password === '') {
            mobile_err('ERR_VALIDATION', 'username/password wajib.', 422);
        }
        mobile_rate_limit_login($pdo, $username);
        $user = mobile_find_user_by_username($pdo, $username);
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            mobile_audit($pdo, 'MOBILE.AUTH.LOGIN_FAIL', ['user_id' => 0, 'username' => $username], 'USER', $username, ['device_id' => $deviceId]);
            mobile_err('ERR_UNAUTHENTICATED', 'Username atau password salah.', 401);
        }
        if (strtolower((string)($user['status'] ?? 'active')) !== 'active') {
            mobile_err('ERR_FORBIDDEN', 'User tidak aktif.', 403);
        }
        [$access, $refresh] = mobile_issue_auth_tokens($pdo, $user, $deviceId);
        $actor = ['user_id' => (int)$user['id'], 'username' => (string)$user['username']];
        mobile_audit($pdo, 'MOBILE.AUTH.LOGIN_SUCCESS', $actor, 'USER', (int)$user['id'], ['device_id' => $deviceId]);
        $resp = [
            'ok' => true,
            'message' => 'OK',
            'data' => [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'refresh_token' => $refresh,
            'refresh_expires_in' => 30 * 24 * 3600,
            'profile' => [
                'user_id' => (int)$user['id'],
                'username' => (string)$user['username'],
                'full_name' => (string)($user['full_name'] ?? ''),
                'role' => (string)($user['role'] ?? ''),
                'level' => (string)($user['level'] ?? ''),
                'department' => (string)($user['department'] ?? ''),
                'office_code' => (string)($user['office_code'] ?? ''),
            ],
            ],
            'request_id' => mobile_request_id(),
        ];
        mobile_idem_commit($pdo, 0, $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'auth/refresh') {
        mobile_require_method('POST');
        mobile_rate_limit_refresh($pdo);
        $in = mobile_get_input();
        $idem = mobile_idem_key_required();
        $cached = mobile_idem_begin($pdo, 0, $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $refresh = trim((string)($in['refresh_token'] ?? ''));
        $deviceId = substr(trim((string)($in['device_id'] ?? 'unknown-device')), 0, 120);
        if ($refresh === '') mobile_err('ERR_VALIDATION', 'refresh_token wajib.', 422);
        $rot = mobile_refresh_rotate(
            $pdo,
            $refresh,
            $deviceId,
            mobile_mask_ip((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
        );
        $st = $pdo->prepare("SELECT id, username, full_name, role, level, department, office_code FROM master_system_login WHERE id=? LIMIT 1");
        $st->execute([(int)$rot['user_id']]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) mobile_err('ERR_UNAUTHENTICATED', 'User not found.', 401);
        $access = mobile_jwt_issue([
            'uid' => (int)$user['id'],
            'username' => (string)$user['username'],
            'role' => (string)($user['role'] ?? ''),
            'level' => (string)($user['level'] ?? ''),
            'department' => (string)($user['department'] ?? ''),
            'office_code' => (string)($user['office_code'] ?? ''),
        ], 15 * 60);
        $actor = ['user_id' => (int)$user['id'], 'username' => (string)$user['username']];
        mobile_audit($pdo, 'MOBILE.AUTH.REFRESH', $actor, 'USER', (int)$user['id'], ['device_id' => $deviceId]);
        $resp = [
            'ok' => true,
            'message' => 'OK',
            'data' => [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'refresh_token' => (string)$rot['refresh_token'],
            'refresh_expires_in' => 30 * 24 * 3600,
            ],
            'request_id' => mobile_request_id(),
        ];
        mobile_idem_commit($pdo, 0, $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'auth/logout') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        $in = mobile_get_input();
        $idem = mobile_idem_key_required();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $refresh = trim((string)($in['refresh_token'] ?? ''));
        $deviceId = trim((string)($in['device_id'] ?? ''));
        if ($refresh !== '') {
            $hash = hash('sha256', $refresh);
            $pdo->prepare("UPDATE mobile_refresh_tokens SET revoked_at=NOW(), updated_at=NOW() WHERE token_hash=? AND user_id=?")->execute([$hash, (int)$auth['user_id']]);
        } elseif ($deviceId !== '') {
            $pdo->prepare("UPDATE mobile_refresh_tokens SET revoked_at=NOW(), updated_at=NOW() WHERE user_id=? AND device_id=? AND revoked_at IS NULL")->execute([(int)$auth['user_id'], $deviceId]);
        }
        mobile_audit($pdo, 'MOBILE.AUTH.LOGOUT', $auth, 'USER', (int)$auth['user_id'], ['device_id' => $deviceId]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['logged_out' => true], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'auth/me') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_ok(['profile' => $auth, 'permissions' => mobile_permissions($auth)]);
    }

    if ($endpoint === 'dashboard/get') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        $counts = [
            'sales_do' => (int)$pdo->query("SELECT COUNT(*) FROM sales_do")->fetchColumn(),
            'po' => (int)$pdo->query("SELECT COUNT(*) FROM purchases_po WHERE deleted_at IS NULL")->fetchColumn(),
            'ap_unpaid' => (int)$pdo->query("SELECT COUNT(*) FROM purchases_invoice_ap WHERE deleted_at IS NULL AND status IN ('UNPAID','PARTIAL','HOLD_3WM')")->fetchColumn(),
            'stock_adjustments' => (int)$pdo->query("SELECT COUNT(*) FROM wqs_stock_adjustments")->fetchColumn(),
        ];
        $tasks = [
            'wqs_ready' => (int)$pdo->query("SELECT COUNT(*) FROM sales_do WHERE status='wqs_processing'")->fetchColumn(),
            'scm_delivery' => (int)$pdo->query("SELECT COUNT(*) FROM sales_do WHERE status='ready_scm'")->fetchColumn(),
            'act_fin' => (int)$pdo->query("SELECT COUNT(*) FROM sales_do WHERE status='delivered'")->fetchColumn(),
            'fin_pay' => (int)$pdo->query("SELECT COUNT(*) FROM sales_do WHERE status='wait_payment'")->fetchColumn(),
        ];
        mobile_ok(['kpi' => $counts, 'tasks' => $tasks, 'actor' => $auth]);
    }

    if ($endpoint === 'tasks/my') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        $dept = strtoupper((string)($auth['department'] ?? ''));
        $role = strtoupper((string)($auth['role'] ?? ''));
        $level = strtoupper((string)($auth['level'] ?? ''));
        $isManager = $role === 'MANAGER' || $level === 'MANAGER';
        $isAdmin = !empty(mobile_permissions($auth)['admin']);
        $statusMap = [
            'WQS' => ['crm_to_wqs', 'sent_wqs', 'wqs_processing'],
            'SCM' => ['ready_scm', 'on_delivery'],
            'ACT' => ['delivered'],
            'FIN' => ['wait_payment'],
            'CRM' => ['crm_to_wqs'],
        ];
        if ($isAdmin || $isManager) {
            $targets = ['crm_to_wqs', 'sent_wqs', 'wqs_processing', 'ready_scm', 'on_delivery', 'delivered', 'wait_payment'];
        } else {
            $targets = $statusMap[$dept] ?? ['crm_to_wqs'];
        }
        $placeholders = implode(',', array_fill(0, count($targets), '?'));
        $st = $pdo->prepare("SELECT id, do_code, tracking_code, status, do_date, customers_code, grand_total FROM sales_do WHERE status IN ({$placeholders}) ORDER BY id DESC LIMIT 100");
        $st->execute($targets);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        mobile_ok(['items' => $rows]);
    }

    if ($endpoint === 'sales/do_list') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'sales');
        [$page, $limit, $offset] = mobile_pagination($_GET);
        $status = trim((string)($_GET['status'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));
        $where = ["1=1"];
        $params = [];
        if ($status !== '') { $where[] = "status=?"; $params[] = $status; }
        if ($q !== '') { $where[] = "(do_code LIKE ? OR tracking_code LIKE ? OR customers_code LIKE ?)"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
        $sql = "SELECT id, do_code, tracking_code, status, do_date, customers_code, office_code, grand_total, updated_at FROM sales_do WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($params, [$limit, $offset]));
        mobile_ok(['page' => $page, 'limit' => $limit, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($endpoint === 'sales/do_detail') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'sales');
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) mobile_err('ERR_VALIDATION', 'id wajib.', 422);
        $do = mobile_fetch_do($pdo, $id);
        if (!$do) mobile_err('ERR_NOT_FOUND', 'DO tidak ditemukan.', 404);
        $st = $pdo->prepare("SELECT * FROM sales_do_items WHERE do_id=? ORDER BY id ASC");
        $st->execute([$id]);
        mobile_ok(['header' => $do, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($endpoint === 'sales/do_action') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'sales');
        $idem = mobile_idem_key_required();
        $in = mobile_get_input();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $id = (int)($in['do_id'] ?? 0);
        $action = trim((string)($in['action_code'] ?? ''));
        $note = trim((string)($in['note'] ?? ''));
        if ($id <= 0 || $action === '') mobile_err('ERR_VALIDATION', 'do_id/action_code wajib.', 422);
        if (!mobile_user_can_do_action($auth, $action, $pdo)) mobile_err('ERR_FORBIDDEN', 'Action tidak diizinkan.', 403);
        $do = mobile_fetch_do($pdo, $id);
        if (!$do) mobile_err('ERR_NOT_FOUND', 'DO tidak ditemukan.', 404);
        $next = mobile_transition_status_for_action($action);
        if ($next === null) mobile_err('ERR_VALIDATION', 'action_code tidak valid.', 422);
        if (!mobile_do_can_transition((string)$do['status'], $next)) {
            mobile_err('ERR_CONFLICT_STATE_CHANGED', 'Status berubah atau transisi tidak valid.', 409);
        }
        $pdo->prepare("UPDATE sales_do SET status=?, note=CONCAT(COALESCE(note,''), ?), last_updated_by=?, last_updated_at=NOW() WHERE id=?")
            ->execute([$next, ($note !== '' ? "\n[MOBILE] " . $note : ''), 'MOBILE:' . $auth['username'], $id]);
        mobile_audit($pdo, 'MOBILE.SALES.DO.ACTION', $auth, 'SALES_DO', (string)$id, ['action_code' => $action, 'to_status' => $next]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['do_id' => $id, 'status' => $next], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'purchases/pr_list') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'purchases');
        [$page, $limit, $offset] = mobile_pagination($_GET);
        $st = $pdo->prepare("SELECT id, pr_code, pr_date, office_code, status, note, created_by, created_at FROM wqs_pr WHERE deleted_at IS NULL ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->execute([$limit, $offset]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        mobile_ok(['page' => $page, 'limit' => $limit, 'items' => $rows]);
    }

    if ($endpoint === 'purchases/pr_create') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'purchases');
        $idem = mobile_idem_key_required();
        $in = mobile_get_input();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $office = strtoupper(trim((string)($in['office_code'] ?? $auth['office_code'])));
        if ($office === '') mobile_err('ERR_VALIDATION', 'office_code wajib.', 422);
        $date = trim((string)($in['pr_date'] ?? date('Y-m-d')));
        $note = trim((string)($in['note'] ?? ''));
        if (!function_exists('doc_prefix_pr')) require_once __DIR__ . '/../../config/doc_numbering.php';
        $prefix = doc_prefix_pr($office, date('ymd', strtotime($date)));
        $st = $pdo->prepare("SELECT pr_code FROM wqs_pr WHERE pr_code LIKE ? ORDER BY pr_code DESC LIMIT 1");
        $st->execute([$prefix . '%']);
        $last = (string)($st->fetchColumn() ?: '');
        $seq = $last === '' ? 1 : ((int)substr($last, -3) + 1);
        $prCode = $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO wqs_pr (pr_code, pr_date, office_code, status, note, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$prCode, $date, $office, 'DRAFT', $note, $auth['username']]);
        $prId = (int)$pdo->lastInsertId();
        mobile_audit($pdo, 'MOBILE.PURCHASES.PR.CREATE', $auth, 'WQS_PR', (string)$prId, ['pr_code' => $prCode]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['pr_id' => $prId, 'pr_code' => $prCode, 'status' => 'DRAFT'], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'purchases/po_list') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'purchases');
        [$page, $limit, $offset] = mobile_pagination($_GET);
        $st = $pdo->prepare("SELECT id, po_code, po_date, pr_id, office_code, currency, status, total_amount, created_by, created_at FROM purchases_po WHERE deleted_at IS NULL ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->execute([$limit, $offset]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        mobile_ok(['page' => $page, 'limit' => $limit, 'items' => $rows]);
    }

    if ($endpoint === 'purchases/po_detail') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'purchases');
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) mobile_err('ERR_VALIDATION', 'id wajib.', 422);
        $st = $pdo->prepare("SELECT * FROM purchases_po WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$id]);
        $po = $st->fetch(PDO::FETCH_ASSOC);
        if (!$po) mobile_err('ERR_NOT_FOUND', 'PO tidak ditemukan.', 404);
        $st2 = $pdo->prepare("SELECT * FROM purchases_po_items WHERE po_id=? AND deleted_at IS NULL ORDER BY line_no ASC");
        $st2->execute([$id]);
        mobile_ok(['header' => $po, 'items' => $st2->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($endpoint === 'purchases/po_create') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'purchases');
        $idem = mobile_idem_key_required();
        $in = mobile_get_input();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $prId = (int)($in['pr_id'] ?? 0);
        $manufactureId = (int)($in['manufacture_id'] ?? 0);
        if ($prId <= 0 || $manufactureId <= 0) mobile_err('ERR_VALIDATION', 'pr_id dan manufacture_id wajib.', 422);
        $stPr = $pdo->prepare("SELECT * FROM wqs_pr WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $stPr->execute([$prId]);
        $pr = $stPr->fetch(PDO::FETCH_ASSOC);
        if (!$pr) mobile_err('ERR_NOT_FOUND', 'PR tidak ditemukan.', 404);
        $date = trim((string)($in['po_date'] ?? date('Y-m-d')));
        $office = strtoupper((string)($pr['office_code'] ?? 'OFF'));
        $prefix = "RMI-PO-{$office}-" . date('ymd', strtotime($date)) . '-';
        $st = $pdo->prepare("SELECT po_code FROM purchases_po WHERE po_code LIKE ? ORDER BY po_code DESC LIMIT 1");
        $st->execute([$prefix . '%']);
        $last = (string)($st->fetchColumn() ?: '');
        $seq = $last === '' ? 1 : ((int)substr($last, -3) + 1);
        $poCode = $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO purchases_po (po_code, po_date, pr_id, manufacture_id, office_code, currency, payment_term, note, status, total_amount, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$poCode, $date, $prId, $manufactureId, $office, 'IDR', '', trim((string)($in['note'] ?? '')), 'OPEN', 0, $auth['username']]);
        $poId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE wqs_pr SET status='PO_CREATED', updated_at=NOW() WHERE id=?")->execute([$prId]);
        mobile_audit($pdo, 'MOBILE.PURCHASES.PO.CREATE', $auth, 'PURCHASES_PO', (string)$poId, ['po_code' => $poCode, 'pr_id' => $prId]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['po_id' => $poId, 'po_code' => $poCode, 'status' => 'OPEN'], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'purchases/ap_invoice_list') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'purchases');
        [$page, $limit, $offset] = mobile_pagination($_GET);
        $st = $pdo->prepare("SELECT id, ap_code, invoice_type, invoice_number, invoice_date, due_date, po_id, total_amount, status, created_at FROM purchases_invoice_ap WHERE deleted_at IS NULL ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->execute([$limit, $offset]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        mobile_ok(['page' => $page, 'limit' => $limit, 'items' => $rows]);
    }

    if ($endpoint === 'purchases/ap_invoice_create') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'purchases');
        $idem = mobile_idem_key_required();
        $in = mobile_get_input();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $poId = (int)($in['po_id'] ?? 0);
        $invoiceNo = trim((string)($in['invoice_number'] ?? ''));
        $invoiceDate = trim((string)($in['invoice_date'] ?? date('Y-m-d')));
        $total = (float)($in['total_amount'] ?? 0);
        if ($poId <= 0 || $invoiceNo === '' || $total <= 0) {
            mobile_err('ERR_VALIDATION', 'po_id, invoice_number, total_amount wajib.', 422);
        }
        $dup = $pdo->prepare("SELECT id FROM purchases_invoice_ap WHERE deleted_at IS NULL AND po_id=? AND UPPER(invoice_number)=UPPER(?) LIMIT 1");
        $dup->execute([$poId, $invoiceNo]);
        if ($dup->fetch(PDO::FETCH_ASSOC)) mobile_err('ERR_DUPLICATE_INVOICE', 'Invoice duplikat.', 409);
        $stPo = $pdo->prepare("SELECT id, po_code, office_code, manufacture_id, currency, total_amount FROM purchases_po WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $stPo->execute([$poId]);
        $po = $stPo->fetch(PDO::FETCH_ASSOC);
        if (!$po) mobile_err('ERR_NOT_FOUND', 'PO tidak ditemukan.', 404);
        if ($total > ((float)$po['total_amount'] + 0.01) && (float)$po['total_amount'] > 0) {
            mobile_err('ERR_3WAY_MISMATCH', 'Nominal AP melebihi total PO (3-way guard).', 409);
        }
        $prefix = "RMI-AP-" . strtoupper((string)$po['office_code']) . '-' . date('ymd', strtotime($invoiceDate)) . '-';
        $st = $pdo->prepare("SELECT ap_code FROM purchases_invoice_ap WHERE ap_code LIKE ? ORDER BY ap_code DESC LIMIT 1");
        $st->execute([$prefix . '%']);
        $last = (string)($st->fetchColumn() ?: '');
        $seq = $last === '' ? 1 : ((int)substr($last, -3) + 1);
        $apCode = $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO purchases_invoice_ap (ap_code, invoice_type, invoice_number, invoice_date, due_date, manufacture_id, office_code, po_id, currency, subtotal, tax_percent, tax_amount, total_amount, status, note, doc_path, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$apCode, strtoupper((string)($in['invoice_type'] ?? 'OTHER')), $invoiceNo, $invoiceDate, $in['due_date'] ?? null, (int)$po['manufacture_id'], (string)$po['office_code'], $poId, (string)($po['currency'] ?? 'IDR'), $total, 0, 0, $total, 'UNPAID', trim((string)($in['note'] ?? '')), null, $auth['username']]);
        $apId = (int)$pdo->lastInsertId();
        mobile_audit($pdo, 'MOBILE.PURCHASES.AP_INVOICE.CREATE', $auth, 'PURCHASES_AP', (string)$apId, ['ap_code' => $apCode, 'po_id' => $poId]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['ap_invoice_id' => $apId, 'ap_code' => $apCode, 'status' => 'UNPAID'], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'purchases/ap_payment_list') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'purchases');
        [$page, $limit, $offset] = mobile_pagination($_GET);
        $st = $pdo->prepare("SELECT id, pay_code, ap_id, pay_date, amount, method, reference, created_at FROM purchases_payment_ap WHERE deleted_at IS NULL ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->execute([$limit, $offset]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        mobile_ok(['page' => $page, 'limit' => $limit, 'items' => $rows]);
    }

    if ($endpoint === 'purchases/ap_payment_create') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'purchases');
        $idem = mobile_idem_key_required();
        $in = mobile_get_input();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $apId = (int)($in['ap_id'] ?? 0);
        $amount = (float)($in['amount'] ?? 0);
        if ($apId <= 0 || $amount <= 0) mobile_err('ERR_VALIDATION', 'ap_id dan amount wajib.', 422);
        $st = $pdo->prepare("SELECT * FROM purchases_invoice_ap WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$apId]);
        $ap = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ap) mobile_err('ERR_NOT_FOUND', 'AP invoice tidak ditemukan.', 404);
        if (strtoupper((string)$ap['status']) === 'HOLD_3WM') mobile_err('ERR_3WAY_MISMATCH', 'Invoice masih HOLD_3WM.', 409);
        $paidSt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM purchases_payment_ap WHERE ap_id=? AND deleted_at IS NULL");
        $paidSt->execute([$apId]);
        $alreadyPaid = (float)$paidSt->fetchColumn();
        if (($alreadyPaid + $amount) > ((float)$ap['total_amount'] + 0.01)) mobile_err('ERR_VALIDATION', 'Amount melebihi outstanding.', 422);
        $office = strtoupper((string)($ap['office_code'] ?? 'OFF'));
        $payDate = trim((string)($in['pay_date'] ?? date('Y-m-d')));
        $prefix = "RMI-PAY-{$office}-" . date('ymd', strtotime($payDate)) . '-';
        $st2 = $pdo->prepare("SELECT pay_code FROM purchases_payment_ap WHERE pay_code LIKE ? ORDER BY pay_code DESC LIMIT 1");
        $st2->execute([$prefix . '%']);
        $last = (string)($st2->fetchColumn() ?: '');
        $seq = $last === '' ? 1 : ((int)substr($last, -3) + 1);
        $payCode = $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO purchases_payment_ap (pay_code, ap_id, pay_date, amount, method, bank_name, reference, note, doc_path, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$payCode, $apId, $payDate, $amount, strtoupper((string)($in['method'] ?? 'TRANSFER')), trim((string)($in['bank_name'] ?? '')), trim((string)($in['reference'] ?? '')), trim((string)($in['note'] ?? '')), null, $auth['username']]);
        $newPaid = $alreadyPaid + $amount;
        $newStatus = ($newPaid + 0.01 >= (float)$ap['total_amount']) ? 'PAID' : 'PARTIAL';
        $pdo->prepare("UPDATE purchases_invoice_ap SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $apId]);
        $payId = (int)$pdo->lastInsertId();
        mobile_audit($pdo, 'MOBILE.PURCHASES.AP_PAYMENT.CREATE', $auth, 'PURCHASES_PAY', (string)$payId, ['pay_code' => $payCode, 'ap_id' => $apId]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['ap_payment_id' => $payId, 'pay_code' => $payCode, 'ap_status' => $newStatus], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'stock/items') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'stock');
        [$page, $limit, $offset] = mobile_pagination($_GET);
        $q = trim((string)($_GET['q'] ?? ''));
        $where = "1=1";
        $params = [];
        if ($q !== '') {
            $where = "(p.sku LIKE ? OR p.products_name LIKE ?)";
            $params = ["%{$q}%", "%{$q}%"];
        }
        // Gunakan subquery agregat agar tidak duplikat jika wqs_stock multi-office
        $sql = "SELECT p.id, COALESCE(NULLIF(p.sku, ''), CONCAT('SKU-', p.id)) AS sku, p.products_name, p.unit, COALESCE(s.stock_qty,0) AS stock_qty, s.updated_at AS stock_updated_at
                FROM master_products p
                LEFT JOIN (SELECT product_id, SUM(stock_qty) AS stock_qty, MAX(updated_at) AS updated_at FROM wqs_stock GROUP BY product_id) s ON s.product_id=p.id
                WHERE {$where}
                ORDER BY p.id DESC
                LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($params, [$limit, $offset]));
        mobile_ok(['page' => $page, 'limit' => $limit, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($endpoint === 'stock/incoming_list') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'stock');
        [$page, $limit, $offset] = mobile_pagination($_GET);
        $st = $pdo->prepare("SELECT id, incoming_code, received_date, po_id, po_code, office_code, depo_name, ref_note, created_at FROM wqs_incoming ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->execute([$limit, $offset]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        mobile_ok(['page' => $page, 'limit' => $limit, 'items' => $rows]);
    }

    if ($endpoint === 'stock/incoming_receive') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'stock');
        $idem = mobile_idem_key_required();
        $in = mobile_get_input();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $poId = (int)($in['po_id'] ?? 0);
        if ($poId <= 0) mobile_err('ERR_VALIDATION', 'po_id wajib.', 422);
        $stPo = $pdo->prepare("SELECT id, po_code, office_code FROM purchases_po WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $stPo->execute([$poId]);
        $po = $stPo->fetch(PDO::FETCH_ASSOC);
        if (!$po) mobile_err('ERR_NOT_FOUND', 'PO tidak ditemukan.', 404);
        $date = trim((string)($in['received_date'] ?? date('Y-m-d')));
        $prefix = "RMI-IN-" . strtoupper((string)$po['office_code']) . '-' . date('ymd', strtotime($date)) . '-';
        $st = $pdo->prepare("SELECT incoming_code FROM wqs_incoming WHERE incoming_code LIKE ? ORDER BY incoming_code DESC LIMIT 1");
        $st->execute([$prefix . '%']);
        $last = (string)($st->fetchColumn() ?: '');
        $seq = $last === '' ? 1 : ((int)substr($last, -3) + 1);
        $code = $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO wqs_incoming (incoming_code, received_date, po_id, po_code, office_code, depo_name, ref_note, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$code, $date, $poId, (string)$po['po_code'], (string)$po['office_code'], trim((string)($in['depo_name'] ?? '')), trim((string)($in['ref_note'] ?? 'MOBILE RECEIVE'))]);
        $incomingId = (int)$pdo->lastInsertId();
        mobile_audit($pdo, 'MOBILE.STOCK.INCOMING.RECEIVE', $auth, 'WQS_INCOMING', (string)$incomingId, ['incoming_code' => $code]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['incoming_id' => $incomingId, 'incoming_code' => $code], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'stock/adjustment_create') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'stock');
        $idem = mobile_idem_key_required();
        $in = mobile_get_input();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $productId = (int)($in['product_id'] ?? 0);
        $delta = (int)($in['delta_qty'] ?? 0);
        $reason = trim((string)($in['reason'] ?? ''));
        if ($productId <= 0 || $delta === 0 || $reason === '') mobile_err('ERR_VALIDATION', 'product_id/delta_qty/reason wajib.', 422);
        $stP = $pdo->prepare("SELECT id, COALESCE(NULLIF(sku, ''), CONCAT('SKU-', id)) AS sku FROM master_products WHERE id=? LIMIT 1");
        $stP->execute([$productId]);
        $p = $stP->fetch(PDO::FETCH_ASSOC);
        if (!$p) mobile_err('ERR_NOT_FOUND', 'Product tidak ditemukan.', 404);
        $stS = $pdo->prepare("SELECT stock_qty FROM wqs_stock WHERE product_id=? LIMIT 1");
        $stS->execute([$productId]);
        $cur = (int)($stS->fetchColumn() ?: 0);
        $next = $cur + $delta;
        if ($next < 0) mobile_err('ERR_NEGATIVE_STOCK', 'Stock tidak boleh minus.', 409);
        $adjCode = 'ADJ-' . date('ymdHis') . '-' . $productId;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO wqs_stock_adjustments (adj_code, product_id, sku, delta_qty, reason, created_at, created_by, source_system, source_key) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                ->execute([$adjCode, $productId, (string)$p['sku'], $delta, $reason, $auth['username'], 'MOBILE', $idem]);
            $adjId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO wqs_stock (product_id, stock_qty, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE stock_qty=VALUES(stock_qty), updated_at=NOW()")
                ->execute([$productId, $next]);
            $pdo->prepare("INSERT INTO wqs_stock_adjustment_approvals (adjustment_id, requested_by, requested_at, status, note) VALUES (?, ?, NOW(), 'PENDING', ?)")
                ->execute([$adjId, $auth['username'], $reason]);
            $pdo->commit();
            mobile_audit($pdo, 'MOBILE.STOCK.ADJUSTMENT.CREATE', $auth, 'WQS_STOCK_ADJ', (string)$adjId, ['delta_qty' => $delta, 'stock_before' => $cur, 'stock_after' => $next]);
            $resp = ['ok' => true, 'message' => 'OK', 'data' => ['adjustment_id' => $adjId, 'status' => 'PENDING'], 'request_id' => mobile_request_id()];
            mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
            mobile_send_json($resp, 200);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    if ($endpoint === 'stock/adjustment_approve') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'stock');
        $idem = mobile_idem_key_required();
        $in = mobile_get_input();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $adjId = (int)($in['adjustment_id'] ?? 0);
        if ($adjId <= 0) mobile_err('ERR_VALIDATION', 'adjustment_id wajib.', 422);
        $st = $pdo->prepare("SELECT * FROM wqs_stock_adjustment_approvals WHERE adjustment_id=? LIMIT 1");
        $st->execute([$adjId]);
        $ap = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ap) mobile_err('ERR_NOT_FOUND', 'Approval request tidak ditemukan.', 404);
        if (strtoupper((string)$ap['requested_by']) === strtoupper((string)$auth['username'])) {
            mobile_err('ERR_SELF_APPROVE_FORBIDDEN', 'Self approve tidak diizinkan.', 403);
        }
        if (strtoupper((string)$ap['status']) === 'APPROVED') {
            $resp = ['ok' => true, 'message' => 'OK', 'data' => ['adjustment_id' => $adjId, 'status' => 'APPROVED'], 'request_id' => mobile_request_id()];
            mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
            mobile_send_json($resp, 200);
        }
        $pdo->prepare("UPDATE wqs_stock_adjustment_approvals SET status='APPROVED', approved_by=?, approved_at=NOW(), note=CONCAT(COALESCE(note,''), ?) WHERE adjustment_id=?")
            ->execute([$auth['username'], "\n[MOBILE APPROVE] " . trim((string)($in['note'] ?? '')), $adjId]);
        mobile_audit($pdo, 'MOBILE.STOCK.ADJUSTMENT.APPROVE', $auth, 'WQS_STOCK_ADJ', (string)$adjId, []);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['adjustment_id' => $adjId, 'status' => 'APPROVED'], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'stock/audit') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        mobile_require_perm($auth, 'stock');
        [$page, $limit, $offset] = mobile_pagination($_GET);
        $st = $pdo->prepare("SELECT a.id, a.adj_code, a.sku, a.delta_qty, a.reason, a.created_by, a.created_at, ap.status AS approval_status, ap.approved_by, ap.approved_at
                             FROM wqs_stock_adjustments a
                             LEFT JOIN wqs_stock_adjustment_approvals ap ON ap.adjustment_id=a.id
                             ORDER BY a.id DESC
                             LIMIT ? OFFSET ?");
        $st->execute([$limit, $offset]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        mobile_ok(['page' => $page, 'limit' => $limit, 'items' => $rows]);
    }

    if ($endpoint === 'chat/channels') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        $uid = (int)$auth['user_id'];
        $sql = "SELECT c.id, c.name, c.channel_type, c.is_private,
                       COALESCE(cm.last_read_message_id,0) AS last_read_message_id,
                       COALESCE(unr.unread_count,0) AS unread_count,
                       s.muted_until
                FROM chat_channel_members cm
                JOIN chat_channels c ON c.id=cm.channel_id
                LEFT JOIN chat_user_channel_settings s ON s.channel_id=c.id AND s.user_id=cm.user_id
                LEFT JOIN (
                    SELECT m.channel_id, cm2.user_id, COUNT(*) AS unread_count
                    FROM chat_messages m
                    JOIN chat_channel_members cm2 ON cm2.channel_id=m.channel_id
                    WHERE m.id > COALESCE(cm2.last_read_message_id,0) AND m.is_deleted=0
                    GROUP BY m.channel_id, cm2.user_id
                ) unr ON unr.channel_id=c.id AND unr.user_id=cm.user_id
                WHERE cm.user_id=?
                ORDER BY c.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute([$uid]);
        mobile_ok(['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($endpoint === 'chat/messages') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        $uid = (int)$auth['user_id'];
        $channelId = (int)($_GET['channel_id'] ?? 0);
        if ($channelId <= 0) mobile_err('ERR_VALIDATION', 'channel_id wajib.', 422);
        mobile_chat_member_guard($pdo, $uid, $channelId);
        $cursor = (int)($_GET['cursor'] ?? 0);
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        if ($cursor > 0) {
            $st = $pdo->prepare("SELECT * FROM chat_messages WHERE channel_id=? AND id < ? ORDER BY id DESC LIMIT ?");
            $st->execute([$channelId, $cursor, $limit]);
        } else {
            $st = $pdo->prepare("SELECT * FROM chat_messages WHERE channel_id=? ORDER BY id DESC LIMIT ?");
            $st->execute([$channelId, $limit]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $nextCursor = !empty($rows) ? (int)end($rows)['id'] : null;
        mobile_ok(['channel_id' => $channelId, 'cursor' => $nextCursor, 'items' => $rows]);
    }

    if ($endpoint === 'chat/send') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        mobile_rate_limit_chat_send($pdo, (string)$auth['username']);
        $idem = mobile_idem_key_required();
        $in = mobile_get_input();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $channelId = (int)($in['channel_id'] ?? 0);
        $text = trim((string)($in['message_text'] ?? ''));
        if ($channelId <= 0 || $text === '') mobile_err('ERR_VALIDATION', 'channel_id/message_text wajib.', 422);
        mobile_chat_member_guard($pdo, (int)$auth['user_id'], $channelId);
        $reply = (int)($in['reply_to_message_id'] ?? 0);
        $pdo->prepare("INSERT INTO chat_messages (channel_id, user_id, sender_username, reply_to_message_id, message_text, created_at, is_deleted, sender_user_id, idempotency_key, has_attachments, has_mentions) VALUES (?,?,?,?,?,NOW(),0,?,?,0,0)")
            ->execute([$channelId, (int)$auth['user_id'], (string)$auth['username'], $reply > 0 ? $reply : null, $text, (int)$auth['user_id'], $idem]);
        $msgId = (int)$pdo->lastInsertId();
        mobile_audit($pdo, 'MOBILE.CHAT.MESSAGE.SEND', $auth, 'CHAT_MESSAGE', (string)$msgId, ['channel_id' => $channelId]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['message_id' => $msgId, 'channel_id' => $channelId], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'chat/read') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        $in = mobile_get_input();
        $idem = mobile_idem_key_required();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $channelId = (int)($in['channel_id'] ?? 0);
        $messageId = (int)($in['message_id'] ?? 0);
        if ($channelId <= 0 || $messageId <= 0) mobile_err('ERR_VALIDATION', 'channel_id/message_id wajib.', 422);
        mobile_chat_member_guard($pdo, (int)$auth['user_id'], $channelId);
        $pdo->prepare("UPDATE chat_channel_members SET last_read_message_id=?, last_read_at=NOW() WHERE channel_id=? AND user_id=?")
            ->execute([$messageId, $channelId, (int)$auth['user_id']]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['updated' => true], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'chat/mark_all_read') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        $in = mobile_get_input();
        $idem = mobile_idem_key_required();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $channelId = (int)($in['channel_id'] ?? 0);
        if ($channelId <= 0) mobile_err('ERR_VALIDATION', 'channel_id wajib.', 422);
        mobile_chat_member_guard($pdo, (int)$auth['user_id'], $channelId);
        $st = $pdo->prepare("SELECT MAX(id) FROM chat_messages WHERE channel_id=?");
        $st->execute([$channelId]);
        $maxId = (int)($st->fetchColumn() ?: 0);
        $pdo->prepare("UPDATE chat_channel_members SET last_read_message_id=?, last_read_at=NOW() WHERE channel_id=? AND user_id=?")
            ->execute([$maxId, $channelId, (int)$auth['user_id']]);
        mobile_audit($pdo, 'MOBILE.CHAT.MARK_ALL_READ', $auth, 'CHAT_CHANNEL', (string)$channelId, []);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['channel_id' => $channelId, 'last_read_message_id' => $maxId], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'chat/mute') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        $in = mobile_get_input();
        $idem = mobile_idem_key_required();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $channelId = (int)($in['channel_id'] ?? 0);
        $duration = trim((string)($in['duration'] ?? '1h'));
        $map = ['1h' => 1, '8h' => 8, '24h' => 24];
        $hours = $map[$duration] ?? 1;
        if ($channelId <= 0) mobile_err('ERR_VALIDATION', 'channel_id wajib.', 422);
        mobile_chat_member_guard($pdo, (int)$auth['user_id'], $channelId);
        $pdo->prepare("INSERT INTO chat_user_channel_settings (user_id, channel_id, muted_until, notification_level, updated_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL {$hours} HOUR),'all',NOW()) ON DUPLICATE KEY UPDATE muted_until=VALUES(muted_until), updated_at=NOW()")
            ->execute([(int)$auth['user_id'], $channelId]);
        mobile_audit($pdo, 'MOBILE.CHAT.MUTE', $auth, 'CHAT_CHANNEL', (string)$channelId, ['duration' => $duration]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['channel_id' => $channelId, 'muted_until' => date('c', time() + $hours * 3600)], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'chat/unmute') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        $in = mobile_get_input();
        $idem = mobile_idem_key_required();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $channelId = (int)($in['channel_id'] ?? 0);
        if ($channelId <= 0) mobile_err('ERR_VALIDATION', 'channel_id wajib.', 422);
        mobile_chat_member_guard($pdo, (int)$auth['user_id'], $channelId);
        $pdo->prepare("UPDATE chat_user_channel_settings SET muted_until=NULL, updated_at=NOW() WHERE user_id=? AND channel_id=?")->execute([(int)$auth['user_id'], $channelId]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['channel_id' => $channelId, 'muted_until' => null], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'chat/presence_ping') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        $idem = mobile_idem_key_required();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, []);
        if ($cached) mobile_mutation_response_cached($cached);
        $pdo->prepare("INSERT INTO chat_presence (user_id, status, last_seen_at) VALUES (?, 'ONLINE', NOW()) ON DUPLICATE KEY UPDATE status='ONLINE', last_seen_at=NOW()")
            ->execute([(int)$auth['user_id']]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['status' => 'ONLINE', 'last_seen_at' => date('c')], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'chat/presence_get') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        $idsRaw = trim((string)($_GET['user_ids'] ?? ''));
        $ids = array_filter(array_map('intval', explode(',', $idsRaw)), static fn($x) => $x > 0);
        if (empty($ids)) mobile_err('ERR_VALIDATION', 'user_ids wajib.', 422);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT user_id, status, last_seen_at FROM chat_presence WHERE user_id IN ({$placeholders})");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        mobile_ok(['items' => $rows]);
    }

    if ($endpoint === 'chat/search') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') mobile_err('ERR_VALIDATION', 'q wajib.', 422);
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        $st = $pdo->prepare("SELECT m.id, m.channel_id, m.sender_username, m.message_text, m.created_at
                             FROM chat_messages m
                             JOIN chat_channel_members cm ON cm.channel_id=m.channel_id AND cm.user_id=?
                             WHERE m.is_deleted=0 AND m.message_text LIKE ?
                             ORDER BY m.id DESC
                             LIMIT ?");
        $st->execute([(int)$auth['user_id'], '%' . $q . '%', $limit]);
        mobile_ok(['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($endpoint === 'chat/attachment_upload') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        $idem = mobile_idem_key_required();
        $in = $_POST;
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, is_array($in) ? $in : []);
        if ($cached) mobile_mutation_response_cached($cached);
        $channelId = (int)($_POST['channel_id'] ?? 0);
        if ($channelId <= 0) mobile_err('ERR_VALIDATION', 'channel_id wajib.', 422);
        mobile_chat_member_guard($pdo, (int)$auth['user_id'], $channelId);
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) mobile_err('ERR_VALIDATION', 'file wajib.', 422);
        $f = $_FILES['file'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) mobile_err('ERR_VALIDATION', 'upload gagal.', 422);
        if (($f['size'] ?? 0) > 8 * 1024 * 1024) mobile_err('ERR_UPLOAD_TOO_LARGE', 'Ukuran file terlalu besar.', 413);
        $tmp = (string)$f['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $allow = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!in_array($mime, $allow, true)) mobile_err('ERR_UPLOAD_INVALID_MIME', 'MIME tidak diizinkan.', 422);
        $day = date('Y-m-d');
        $stQuota = $pdo->prepare("SELECT COALESCE(SUM(size_bytes),0) FROM chat_attachments ca JOIN chat_messages cm ON cm.id=ca.message_id WHERE cm.user_id=? AND DATE(ca.created_at)=?");
        $stQuota->execute([(int)$auth['user_id'], $day]);
        $used = (int)$stQuota->fetchColumn();
        $quota = 25 * 1024 * 1024;
        if (($used + (int)$f['size']) > $quota) mobile_err('ERR_QUOTA_EXCEEDED', 'Kuota harian lampiran terlampaui.', 429);
        $dir = realpath(__DIR__ . '/../../../uploads');
        if ($dir === false) $dir = __DIR__ . '/../../../uploads';
        $destDir = $dir . '/chat_mobile/' . date('Y/m');
        if (!is_dir($destDir)) @mkdir($destDir, 0777, true);
        $orig = (string)($f['name'] ?? 'file.bin');
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
        $stored = $safe . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
        $dest = $destDir . '/' . $stored;
        if (!move_uploaded_file($tmp, $dest)) mobile_err('ERR_INTERNAL', 'Gagal menyimpan file.', 500);
        $pdo->prepare("INSERT INTO chat_messages (channel_id, user_id, sender_username, message_text, created_at, is_deleted, sender_user_id, idempotency_key, has_attachments, has_mentions) VALUES (?,?,?,?,NOW(),0,?,?,1,0)")
            ->execute([$channelId, (int)$auth['user_id'], (string)$auth['username'], '[attachment]', (int)$auth['user_id'], 'att-' . bin2hex(random_bytes(8))]);
        $msgId = (int)$pdo->lastInsertId();
        $sha = hash_file('sha256', $dest) ?: '';
        $relPath = 'uploads/chat_mobile/' . date('Y/m') . '/' . $stored;
        $pdo->prepare("INSERT INTO chat_attachments (message_id, original_filename, stored_filename, mime_type, size_bytes, sha256, storage_path, created_at, channel_id) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$msgId, $orig, $stored, $mime, (int)$f['size'], $sha, $relPath, date('Y-m-d H:i:s'), $channelId]);
        $attId = (int)$pdo->lastInsertId();
        mobile_audit($pdo, 'MOBILE.CHAT.ATTACHMENT.UPLOAD', $auth, 'CHAT_ATTACHMENT', (string)$attId, ['channel_id' => $channelId]);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['attachment_id' => $attId, 'message_id' => $msgId, 'mime_type' => $mime, 'size_bytes' => (int)$f['size']], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'chat/attachment_preview' || $endpoint === 'chat/attachment_download') {
        $auth = mobile_require_auth();
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) mobile_err('ERR_VALIDATION', 'id wajib.', 422);
        $st = $pdo->prepare("SELECT a.*, m.channel_id FROM chat_attachments a JOIN chat_messages m ON m.id=a.message_id WHERE a.id=? LIMIT 1");
        $st->execute([$id]);
        $att = $st->fetch(PDO::FETCH_ASSOC);
        if (!$att) mobile_err('ERR_NOT_FOUND', 'Attachment tidak ditemukan.', 404);
        mobile_chat_member_guard($pdo, (int)$auth['user_id'], (int)$att['channel_id']);
        mobile_audit($pdo, 'MOBILE.CHAT.ATTACHMENT.DOWNLOAD', $auth, 'CHAT_ATTACHMENT', (string)$id, []);
        mobile_ok(['id' => $id, 'original_filename' => $att['original_filename'], 'mime_type' => $att['mime_type'], 'size_bytes' => (int)$att['size_bytes'], 'storage_path' => $att['storage_path']]);
    }

    if ($endpoint === 'master/products' || $endpoint === 'master/vendors' || $endpoint === 'master/customers') {
        mobile_require_method('GET');
        mobile_require_auth();
        mobile_err('ERR_FORBIDDEN', 'Master access via mobile is disabled by policy.', 403);
    }

    if ($endpoint === 'notifications/register_token') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        $in = mobile_get_input();
        $idem = mobile_idem_key_required();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $token = trim((string)($in['fcm_token'] ?? ''));
        $deviceId = substr(trim((string)($in['device_id'] ?? 'unknown-device')), 0, 120);
        if ($token === '') mobile_err('ERR_VALIDATION', 'fcm_token wajib.', 422);
        $pdo->prepare("INSERT INTO mobile_device_tokens (user_id, fcm_token, device_id, platform, created_at, last_seen_at) VALUES (?,?,?,'android',NOW(),NOW()) ON DUPLICATE KEY UPDATE fcm_token=VALUES(fcm_token), last_seen_at=NOW()")
            ->execute([(int)$auth['user_id'], $token, $deviceId]);
        mobile_audit($pdo, 'MOBILE.NOTIF.REGISTER_TOKEN', $auth, 'DEVICE', $deviceId, []);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['registered' => true, 'device_id' => $deviceId], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    if ($endpoint === 'notifications/list') {
        mobile_require_method('GET');
        $auth = mobile_require_auth();
        [$page, $limit, $offset] = mobile_pagination($_GET);
        $st = $pdo->prepare("SELECT id, notif_code, title, body, payload_json, is_read, created_at, read_at FROM mobile_notifications WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->execute([(int)$auth['user_id'], $limit, $offset]);
        mobile_ok(['page' => $page, 'limit' => $limit, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($endpoint === 'notifications/mark_read') {
        mobile_require_method('POST');
        $auth = mobile_require_auth();
        $in = mobile_get_input();
        $idem = mobile_idem_key_required();
        $cached = mobile_idem_begin($pdo, (int)$auth['user_id'], $endpoint, $idem, $in);
        if ($cached) mobile_mutation_response_cached($cached);
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) mobile_err('ERR_VALIDATION', 'id wajib.', 422);
        $pdo->prepare("UPDATE mobile_notifications SET is_read=1, read_at=NOW() WHERE id=? AND user_id=?")->execute([$id, (int)$auth['user_id']]);
        mobile_audit($pdo, 'MOBILE.NOTIF.MARK_READ', $auth, 'MOBILE_NOTIFICATION', (string)$id, []);
        $resp = ['ok' => true, 'message' => 'OK', 'data' => ['id' => $id, 'is_read' => true], 'request_id' => mobile_request_id()];
        mobile_idem_commit($pdo, (int)$auth['user_id'], $endpoint, $idem, $resp);
        mobile_send_json($resp, 200);
    }

    mobile_err('ERR_NOT_FOUND', 'Endpoint not found.', 404);
} catch (Throwable $e) {
    mobile_err('ERR_INTERNAL', 'Internal server error.', 500);
}
