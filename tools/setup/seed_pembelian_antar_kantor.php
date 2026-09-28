<?php
declare(strict_types=1);
/**
 * Seed Customer Internal & Manufacture Kantor Internal untuk Pembelian Antar Kantor.
 * Membuat data di Master Customers dan Master Manufactures berdasarkan master_office.
 *
 * Jalankan via web (Tools → Setup) atau CLI: php tools/setup/seed_pembelian_antar_kantor.php
 */
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/db.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_login();
    $base = function_exists('auth_base_project') ? auth_base_project() : '';
    $role = strtoupper(trim((string)($_SESSION['level'] ?? $_SESSION['role'] ?? '')));
    if (!in_array($role, ['ADMIN', 'SUPERADMIN', 'SYS'], true)) {
        rmi_redirect($base . '/tools/index.php');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run']) && $_POST['run'] === '1') {
        if (function_exists('verify_csrf')) {
            verify_csrf((string)($_POST['csrf_token'] ?? ''));
        }
    }
}

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : null;
if (!$pdo) {
    $pdo = function_exists('db_pdo') ? db_pdo() : null;
}
if (!$pdo) {
    if ($isCli) {
        fwrite(STDERR, "DB connection failed.\n");
        exit(1);
    }
    die('DB connection failed.');
}

$run = $isCli || (isset($_POST['run']) && $_POST['run'] === '1');
$result = ['ok' => false, 'msg' => '', 'customers' => [], 'manufactures' => []];

// Kantor yang di-skip (HO = head office, biasanya bukan kantor operasional)
$skipOffices = ['HO', ''];

try {
    $offices = $pdo->query("
        SELECT office_code, office_name
        FROM master_office
        WHERE office_code IS NOT NULL AND TRIM(office_code) != ''
        ORDER BY office_code
    ")->fetchAll(PDO::FETCH_ASSOC);

    $offices = array_filter($offices, fn($o) => !in_array(trim((string)($o['office_code'] ?? '')), $skipOffices, true));

    if (empty($offices)) {
        $result['msg'] = 'Tidak ada kantor di master_office.';
    } elseif ($run) {
        foreach ($offices as $o) {
            $oc = strtoupper(trim((string)($o['office_code'] ?? '')));
            $oname = trim((string)($o['office_name'] ?? $oc));
            if ($oc === '') continue;

            // 1. Customer Internal (OFFICE-INT)
            $custCode = $oc . '-INT';
            $custName = 'Kantor ' . $oname . ' (Internal)';
            $st = $pdo->prepare("SELECT id FROM master_customers WHERE customers_code = ? LIMIT 1");
            $st->execute([$custCode]);
            if ($st->fetch() === false) {
                $ins = $pdo->prepare("
                    INSERT INTO master_customers (customers_code, name, customers_name, category, segment, city, office_code, status)
                    VALUES (?, ?, ?, 'Internal', 'Kantor', '', ?, 'active')
                ");
                $ins->execute([$custCode, $custName, $custName, $oc]);
                $result['customers'][] = ['code' => $custCode, 'action' => 'created'];
            } else {
                $result['customers'][] = ['code' => $custCode, 'action' => 'exists'];
            }

            // 2. Manufacture Kantor Internal (KANTOR-OFFICE)
            $mfgCode = 'KANTOR-' . $oc;
            $mfgName = 'Kantor ' . $oname;
            $st = $pdo->prepare("SELECT id FROM master_manufactures WHERE internal_office_code = ? LIMIT 1");
            $st->execute([$oc]);
            if ($st->fetch() === false) {
                $st2 = $pdo->prepare("SELECT id FROM master_manufactures WHERE manufacture_code = ? OR manufactures_code = ? LIMIT 1");
                $st2->execute([$mfgCode, $mfgCode]);
                if ($st2->fetch() === false) {
                    $ins = $pdo->prepare("
                        INSERT INTO master_manufactures (manufactures_code, manufactures_name, manufacture_code, manufacture_name, status, internal_office_code, created_at, updated_at)
                        VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())
                    ");
                    $ins->execute([$mfgCode, $mfgName, $mfgCode, $mfgName, $oc]);
                    $result['manufactures'][] = ['code' => $mfgCode, 'office' => $oc, 'action' => 'created'];
                } else {
                    $result['manufactures'][] = ['code' => $mfgCode, 'office' => $oc, 'action' => 'exists'];
                }
            } else {
                $result['manufactures'][] = ['code' => $mfgCode, 'office' => $oc, 'action' => 'exists'];
            }
        }
        $result['ok'] = true;
        $result['msg'] = 'Setup selesai. ' . count($offices) . ' kantor diproses.';
    } else {
        $result['msg'] = 'Preview: ' . count($offices) . ' kantor akan diproses. Klik Run untuk eksekusi.';
        foreach ($offices as $o) {
            $oc = strtoupper(trim((string)($o['office_code'] ?? '')));
            if ($oc === '') continue;
            $result['customers'][] = ['code' => $oc . '-INT', 'action' => 'will_create'];
            $result['manufactures'][] = ['code' => 'KANTOR-' . $oc, 'office' => $oc, 'action' => 'will_create'];
        }
    }
} catch (Throwable $e) {
    $result['msg'] = 'Error: ' . $e->getMessage();
}

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($result['ok'] ? 0 : 1);
}

// Web output
require_once __DIR__ . '/../../_shared/rmi_layout.php';
rmi_header('Setup Pembelian Antar Kantor', ['active' => 'tools']);
?>
<div class="container py-4">
    <div class="card">
        <div class="card-header">Seed Customer Internal & Manufacture Kantor</div>
        <div class="card-body">
            <?php if ($result['msg']): ?>
                <div class="alert alert-<?= $result['ok'] ? 'success' : 'info' ?>"><?= rmi_h($result['msg']) ?></div>
            <?php endif; ?>
            <?php if (!empty($result['customers'])): ?>
                <h6>Customer Internal</h6>
                <ul class="small">
                    <?php foreach ($result['customers'] as $c): ?>
                        <li><?= rmi_h($c['code']) ?> — <?= rmi_h($c['action']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($result['manufactures'])): ?>
                <h6>Manufacture Kantor Internal</h6>
                <ul class="small">
                    <?php foreach ($result['manufactures'] as $m): ?>
                        <li><?= rmi_h($m['code']) ?> (<?= rmi_h($m['office']) ?>) — <?= rmi_h($m['action']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" class="mt-3">
                <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>"><?php endif; ?>
                <input type="hidden" name="run" value="1">
                <button type="submit" class="btn btn-primary">Run Setup</button>
                <a href="<?= rmi_h(function_exists('auth_base_project') ? auth_base_project() : '') ?>/tools/index.php" class="btn btn-secondary">Kembali</a>
            </form>
        </div>
    </div>
</div>
<?php rmi_footer(); ?>
