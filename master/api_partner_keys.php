<?php
/**
 * master/api_partner_keys.php
 * API Partner Keys — kelola API key untuk partner eksternal.
 * Hak akses: ADMIN & SUPERADMIN only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SYSTEM.API_PARTNER_KEYS', 'SYSTEM.CONFIG_MANAGE']);
} else {
    $meRole = strtoupper((string)($_SESSION['role'] ?? ''));
    $meLevel = strtoupper((string)($_SESSION['level'] ?? ''));
    $isAdmin = in_array($meRole, ['SYS', 'ADMIN', 'SUPERADMIN'], true) || in_array($meLevel, ['SYS', 'ADMIN', 'SUPERADMIN'], true);
    if (!$isAdmin) {
        http_response_code(403);
        exit('Akses ditolak. Hanya ADMIN & SUPERADMIN.');
    }
}

require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/_audit_master.php';

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();

function apk_h($v) {
    return function_exists('rmi_h') ? rmi_h($v) : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Ensure table exists (include environment for prod/dev key separation)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_partner_keys (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        partner_name VARCHAR(120) NOT NULL,
        environment ENUM('production','development') NOT NULL DEFAULT 'production',
        api_key_hash VARCHAR(255) NOT NULL,
        scopes TEXT NULL,
        rate_limit_per_hour INT UNSIGNED NOT NULL DEFAULT 1000,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_by VARCHAR(80) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        note VARCHAR(255) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_partner_env (partner_name, environment),
        KEY idx_status (status),
        KEY idx_env (environment)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    // table may already exist (e.g. dari migration 113)
}

// Deteksi kolom environment (migration 114) — untuk backward compatibility
$hasEnvColumn = false;
try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'api_partner_keys' AND column_name = 'environment'");
    $st->execute();
    $hasEnvColumn = (bool)$st->fetch();
} catch (Throwable $e) {
    $hasEnvColumn = false;
}

$flash = ['type' => '', 'msg' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    }
    $action = trim((string)($_POST['action'] ?? ''));
    $partnerName = trim((string)($_POST['partner_name'] ?? ''));
    $environment = in_array(trim((string)($_POST['environment'] ?? '')), ['production', 'development'], true) ? trim((string)($_POST['environment'] ?? '')) : 'production';
    $scopes = trim((string)($_POST['scopes'] ?? ''));
    $rateLimit = max(100, min(100000, (int)($_POST['rate_limit_per_hour'] ?? 1000)));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($action === 'create' && $partnerName !== '') {
        $rawKey = 'rpk_' . bin2hex(random_bytes(24));
        $hash = password_hash($rawKey, PASSWORD_DEFAULT);
        $createdBy = (string)($_SESSION['username'] ?? '');
        try {
            if ($hasEnvColumn) {
                $st = $pdo->prepare("INSERT INTO api_partner_keys (partner_name, environment, api_key_hash, scopes, rate_limit_per_hour, created_by, note) VALUES (?,?,?,?,?,?,?)");
                $st->execute([$partnerName, $environment, $hash, $scopes, $rateLimit, $createdBy, $note]);
                $newId = (int)$pdo->lastInsertId();
                if ($newId > 0 && function_exists('master_audit')) {
                    master_audit(
                        $pdo,
                        'api_partner_keys',
                        'api_partner_keys',
                        'CREATE',
                        $newId,
                        $partnerName . '/' . $environment,
                        'API partner key created (raw key not logged)',
                        ['environment' => $environment, 'rate_limit_per_hour' => $rateLimit]
                    );
                }
                $envLabel = $environment === 'production' ? 'Production' : 'Development';
                $flash = ['type' => 'success', 'msg' => "Partner \"{$partnerName}\" ({$envLabel}) dibuat. API Key (simpan, tidak akan ditampilkan lagi): " . $rawKey];
            } else {
                $st = $pdo->prepare("INSERT INTO api_partner_keys (partner_name, api_key_hash, scopes, rate_limit_per_hour, created_by, note) VALUES (?,?,?,?,?,?)");
                $st->execute([$partnerName, $hash, $scopes, $rateLimit, $createdBy, $note]);
                $newId = (int)$pdo->lastInsertId();
                if ($newId > 0 && function_exists('master_audit')) {
                    master_audit(
                        $pdo,
                        'api_partner_keys',
                        'api_partner_keys',
                        'CREATE',
                        $newId,
                        $partnerName,
                        'API partner key created (raw key not logged)',
                        ['rate_limit_per_hour' => $rateLimit]
                    );
                }
                $flash = ['type' => 'success', 'msg' => "Partner \"{$partnerName}\" dibuat. API Key (simpan, tidak akan ditampilkan lagi): " . $rawKey . ". Jalankan migration 114 untuk fitur environment."];
            }
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                $flash = ['type' => 'danger', 'msg' => $hasEnvColumn ? "Partner \"{$partnerName}\" dengan environment \"{$environment}\" sudah ada." : "Partner \"{$partnerName}\" sudah ada."];
            } else {
                $flash = ['type' => 'danger', 'msg' => $e->getMessage()];
            }
        }
    } elseif ($action === 'toggle' && ($id = (int)($_POST['id'] ?? 0)) > 0) {
        try {
            $row = $pdo->prepare("SELECT partner_name, environment FROM api_partner_keys WHERE id=?");
            $row->execute([$id]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            $st = $pdo->prepare("UPDATE api_partner_keys SET status = IF(status='active','inactive','active') WHERE id=?");
            $st->execute([$id]);
            if ($st->rowCount() > 0 && $r && function_exists('master_audit')) {
                $code = $r['partner_name'] . ($hasEnvColumn ? ('/' . ($r['environment'] ?? 'prod')) : '');
                master_audit($pdo, 'api_partner_keys', 'api_partner_keys', 'TOGGLE', $id, $code, "API partner key status toggled: {$r['partner_name']}", []);
            }
            $flash = ['type' => 'success', 'msg' => 'Status diubah.'];
        } catch (Throwable $e) {
            $flash = ['type' => 'danger', 'msg' => $e->getMessage()];
        }
    } elseif ($action === 'delete' && ($id = (int)($_POST['id'] ?? 0)) > 0) {
        try {
            $row = $pdo->prepare("SELECT partner_name, environment FROM api_partner_keys WHERE id=?");
            $row->execute([$id]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("DELETE FROM api_partner_keys WHERE id=?")->execute([$id]);
            if ($r && function_exists('master_audit')) {
                $code = $r['partner_name'] . ($hasEnvColumn ? ('/' . ($r['environment'] ?? 'prod')) : '');
                master_audit($pdo, 'api_partner_keys', 'api_partner_keys', 'DELETE', $id, $code, "API partner key deleted: {$r['partner_name']}", []);
            }
            $flash = ['type' => 'success', 'msg' => 'Partner key dihapus.'];
        } catch (Throwable $e) {
            $flash = ['type' => 'danger', 'msg' => $e->getMessage()];
        }
    }
}

$rows = [];
try {
    if ($hasEnvColumn) {
        $st = $pdo->query("SELECT id, partner_name, environment, scopes, rate_limit_per_hour, status, created_by, created_at, last_used_at, note FROM api_partner_keys ORDER BY partner_name, environment");
    } else {
        $st = $pdo->query("SELECT id, partner_name, scopes, rate_limit_per_hour, status, created_by, created_at, last_used_at, note FROM api_partner_keys ORDER BY partner_name");
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$hasEnvColumn) {
        foreach ($rows as &$r) { $r['environment'] = 'production'; }
    }
} catch (Throwable $e) {
    $rows = [];
}

$baseProject = rmi_layout_base_project();
rmi_header('API Partner Keys', [
    'active' => 'master',
    'subtitle' => 'Kelola API key untuk partner eksternal. Hanya ADMIN & SUPERADMIN.',
    'breadcrumbs' => [
        ['label' => 'Master Data', 'url' => $baseProject . '/master/master_data.php'],
        ['label' => 'API Partner Keys', 'url' => ''],
    ],
]);
?>
<div class="container py-4">
  <?php if ($flash['msg']): ?>
    <div class="alert alert-<?= apk_h($flash['type'] ?: 'info') ?>"><?= apk_h($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">API Partner Keys</h4>
    <a href="<?= apk_h($baseProject) ?>/master/master_data.php" class="btn btn-outline-secondary btn-sm">← Master Data</a>
  </div>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header">Tambah Partner</div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= apk_h(function_exists('csrf_token') ? csrf_token() : '') ?>">
            <input type="hidden" name="action" value="create">
            <div class="mb-2">
              <label class="form-label small">Nama Partner</label>
              <input type="text" name="partner_name" class="form-control form-control-sm" required placeholder="Contoh: Partner A">
            </div>
            <?php if ($hasEnvColumn): ?>
            <div class="mb-2">
              <label class="form-label small">Environment</label>
              <select name="environment" class="form-select form-select-sm">
                <option value="production">Production</option>
                <option value="development">Development</option>
              </select>
              <small class="text-muted">Production: hanya valid di APP_ENV=production. Development: valid di local/staging.</small>
            </div>
            <?php else: ?>
            <div class="mb-2">
              <small class="text-warning">Kolom environment belum tersedia. Jalankan migration 114 untuk fitur Production/Development.</small>
            </div>
            <?php endif; ?>
            <div class="mb-2">
              <label class="form-label small">Scopes (comma-separated)</label>
              <input type="text" name="scopes" class="form-control form-control-sm" placeholder="order:create, sales.do.read, stock.items.read">
            </div>
            <div class="mb-2">
              <label class="form-label small">Rate Limit / jam</label>
              <input type="number" name="rate_limit_per_hour" class="form-control form-control-sm" value="1000" min="100" max="100000">
            </div>
            <div class="mb-2">
              <label class="form-label small">Catatan</label>
              <input type="text" name="note" class="form-control form-control-sm" placeholder="Opsional">
            </div>
            <button type="submit" class="btn btn-rmi btn-sm">Generate API Key</button>
          </form>
          <div class="mt-2 small text-muted">
            API Key akan ditampilkan sekali saat dibuat. Simpan di tempat aman. Header: <code>X-API-Key</code> atau <code>Authorization: Bearer &lt;key&gt;</code>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card">
        <div class="card-header">Daftar Partner</div>
        <div class="card-body p-0">
          <?php if (empty($rows)): ?>
            <div class="p-3 text-muted small">Belum ada partner key.</div>
          <?php else: ?>
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>Partner</th>
                  <th>Env</th>
                  <th>Scopes</th>
                  <th>Rate/jam</th>
                  <th>Status</th>
                  <th>Terakhir dipakai</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?= apk_h($r['partner_name']) ?></td>
                  <td><span class="badge bg-<?= ($r['environment'] ?? '') === 'production' ? 'primary' : 'info' ?>"><?= apk_h($r['environment'] ?? '-') ?></span></td>
                  <td class="small"><?= apk_h($r['scopes'] ?: '-') ?></td>
                  <td><?= apk_h($r['rate_limit_per_hour']) ?></td>
                  <td>
                    <span class="badge bg-<?= ($r['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= apk_h($r['status'] ?? '') ?></span>
                  </td>
                  <td class="small"><?= apk_h($r['last_used_at'] ?: '-') ?></td>
                  <td>
                    <form method="post" class="d-inline" onsubmit="return confirm('Ubah status?');">
                      <input type="hidden" name="csrf_token" value="<?= apk_h(function_exists('csrf_token') ? csrf_token() : '') ?>">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-secondary">Toggle</button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus partner key ini?');">
                      <input type="hidden" name="csrf_token" value="<?= apk_h(function_exists('csrf_token') ? csrf_token() : '') ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-3">
    <div class="alert alert-info small mb-0">
      <strong>Base URL Partner API:</strong> <code><?= apk_h($baseProject ?: '') ?>/api/v1/partner/</code><br>
      Endpoints: <code>health.php</code>, <code>order_create.php</code> (H2H). Scopes: <code>order:create</code> (untuk order_create), <code>sales.do.read</code>, dll. Kosong = allow all.
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
