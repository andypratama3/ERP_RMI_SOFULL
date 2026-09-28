<?php
declare(strict_types=1);

/**
 * SYS-only: edit dokumen Struktur Organisasi (storage/config/org_structure.json).
 * CSRF, validasi schema, backup rotasi, audit system_audit_logs.
 */
require_once __DIR__ . '/auth.php';
require_login();

if (!function_exists('auth_is_sys') || !auth_is_sys()) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403</title></head><body>';
    echo '<h3>Akses ditolak</h3><p>Hanya akun <strong>SYS</strong> yang dapat mengubah dokumen struktur organisasi.</p>';
    echo '</body></html>';
    exit;
}

require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/org_structure_config.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

$pdo = db_pdo();
master_audit_ensure_table($pdo);

$base = rmi_layout_base_project();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'reset_default') {
        $def = org_structure_default_data();
        $res = org_structure_save($def, $pdo);
        if ($res['ok']) {
            $message = 'Dikonfigurasi ulang ke default bawaan sistem.';
            $messageType = 'success';
        } else {
            $message = $res['error'] ?? 'Gagal reset.';
            $messageType = 'danger';
        }
    } elseif ($action === 'save') {
        $raw = (string)($_POST['json_payload'] ?? '');
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            $message = 'JSON tidak valid: ' . json_last_error_msg();
            $messageType = 'danger';
        } else {
            $res = org_structure_save($j, $pdo);
            if ($res['ok']) {
                $message = 'Struktur organisasi berhasil disimpan. Backup otomatis dibuat di storage/backups/org_structure/.';
                $messageType = 'success';
            } else {
                $message = $res['error'] ?? 'Gagal simpan.';
                $messageType = 'danger';
            }
        }
    }
}

$current = org_structure_load();
$textarea = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($textarea === false) {
    $textarea = '{}';
}

rmi_header('Struktur Organisasi — Editor', [
    'active' => 'master',
    'subtitle' => 'SYS only · JSON tervalidasi · audit & backup',
    'breadcrumbs' => [
        ['label' => 'Master Data', 'url' => $base . '/master/index.php'],
        ['label' => 'Struktur Organisasi', 'url' => $base . '/docs/link/struktur_organisasi.php'],
        'Editor',
    ],
    'actions' => [
        ['label' => 'Lihat dokumen', 'url' => $base . '/docs/link/struktur_organisasi.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'Master Data', 'url' => $base . '/master/index.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
]);
?>

<?php if ($message !== ''): ?>
  <div class="alert alert-<?= rmi_h($messageType) ?>"><?= rmi_h($message) ?></div>
<?php endif; ?>

<div class="rmi-card p-3 mb-3">
  <div class="small text-muted mb-2">
    File aktif: <code>storage/config/org_structure.json</code> · Default referensi: <code>_shared/org_structure.default.json</code>
    · Perubahan tercatat di <strong>Audit Logs</strong> (module <code>docs</code>, action <code>UPDATE</code>).
  </div>
  <ul class="small">
    <li>Pastikan JSON valid UTF-8; simpan akan gagal jika schema tidak sesuai (divisi, cabang, panjang teks).</li>
    <li>Array <code>vision</code>, <code>divisions</code>, <code>notes</code>, <code>legend</code>, <code>signatures</code> mengganti seluruh isi dari default bila Anda kirimkan di JSON.</li>
    <li>Backup otomatis (30 file terakhir) di <code>storage/backups/org_structure/</code>.</li>
  </ul>
</div>

<div class="rmi-card p-3">
  <form method="post" class="mb-0">
    <input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <label class="form-label fw-semibold">Payload JSON (version 1)</label>
    <textarea name="json_payload" class="form-control font-monospace small" rows="28" required><?= rmi_h($textarea) ?></textarea>
    <div class="d-flex flex-wrap gap-2 mt-3">
      <button type="submit" class="btn btn-primary">Simpan &amp; audit</button>
    </div>
  </form>
  <hr>
  <form method="post" onsubmit="return confirm('Reset ke default bawaan sistem? Konfigurasi saat ini akan disimpan backup lalu ditimpa.');">
    <input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>">
    <input type="hidden" name="action" value="reset_default">
    <button type="submit" class="btn btn-outline-danger btn-sm">Reset ke default bawaan</button>
  </form>
</div>
