<?php
/**
 * absensi/admin/broadcast.php
 * Generator pesan japri WhatsApp per karyawan untuk HRL
 */
require_once __DIR__ . "/../_inc/bootstrap.php";
if (function_exists('require_login')) { require_login(); }
rbac_require('ABSENSI.ADMIN_USERS');
if (!absensi_is_hr_admin($pdo, $ABS_USER)) { http_response_code(403); die('Akses ditolak'); }

require_once __DIR__ . "/../_layout_top.php";

// Filter
$filter_dept   = strtoupper(trim((string)($_GET['dept']   ?? '')));
$filter_office = strtoupper(trim((string)($_GET['office'] ?? '')));
$filter_status = trim((string)($_GET['status'] ?? 'active'));

// Load users
$users = [];
try {
    $where = ["m.deleted_at IS NULL", "m.username NOT IN ('admin','superadmin')"];
    $params = [];
    if ($filter_dept !== '')   { $where[] = "UPPER(TRIM(m.department))=?";   $params[] = $filter_dept; }
    if ($filter_office !== '') { $where[] = "UPPER(TRIM(m.office_code))=?";  $params[] = $filter_office; }
    if ($filter_status !== '') { $where[] = "LOWER(COALESCE(m.status,'active'))=?"; $params[] = strtolower($filter_status); }

    $sql = "SELECT m.id, m.username, m.full_name, m.department, m.office_code, m.status,
                   COALESCE(p.office_code,'') AS abs_office_override,
                   COALESCE(e.employee_name,'') AS employee_name,
                   COALESCE(e.phone,'') AS phone
            FROM master_system_login m
            LEFT JOIN absensi_user_profile p ON p.user_id = m.id
            LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code
            WHERE " . implode(" AND ", $where) . "
            ORDER BY m.department ASC, m.office_code ASC, m.username ASC
            LIMIT 500";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $users = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $users = []; }

// Dropdown options
$depts = $offices = [];
try {
    $depts   = $pdo->query("SELECT DISTINCT UPPER(TRIM(department)) AS v FROM master_system_login WHERE department IS NOT NULL AND department!='' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
    $offices = $pdo->query("SELECT DISTINCT UPPER(TRIM(office_code)) AS v FROM master_system_login WHERE office_code IS NOT NULL AND office_code!='' AND office_code!='DEFAULT' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$base_url = defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '/ERP_RMI_SOFULL';
$abs_url  = rtrim((string)(getenv('APP_PUBLIC_URL') ?: 'https://erp.rizqullahmediska.com/ERP_RMI_SOFULL'), '/');

function generateMessage(array $u, string $abs_url): string {
    $name     = !empty($u['employee_name']) ? $u['employee_name'] : $u['username'];
    $username = $u['username'];
    $dept     = $u['department'] ?? '';
    $office   = !empty($u['abs_office_override']) ? $u['abs_office_override'] : ($u['office_code'] ?? '');
    $link     = $abs_url . '/absensi/';

    return "Halo *{$name}*! " . rmi_icon('user') . "\n\n"
         . "Kami dari Tim HRL ingin menginformasikan bahwa sistem *Absensi Digital ERP RMI* kini telah aktif dan wajib digunakan mulai hari ini.\n\n"
         . "" . rmi_icon('clipboard') . " *Data Akun ERP Anda:*\n"
         . "• Username: *{$username}*\n"
         . "• Departemen: {$dept}\n"
         . "• Kantor Absensi: *{$office}*\n\n"
         . "" . rmi_icon('doc') . " *Cara Check-in:*\n"
         . "1. Buka link: {$link}\n"
         . "2. Login dengan username & password ERP\n"
         . "3. Tap tombol " . rmi_icon('doc') . " *Ambil Foto / Check-in*\n"
         . "4. Izinkan akses kamera & lokasi GPS\n"
         . "5. Foto selfie → Submit " . rmi_icon('check') . "\n\n"
         . "" . rmi_icon('calendar') . " *Lakukan setiap hari:*\n"
         . "• Check-in saat tiba di kantor\n"
         . "• Check-out saat akan pulang\n\n"
         . "" . rmi_icon('question') . " Ada kendala? Hubungi Tim HRL atau balas pesan ini.\n\n"
         . "_Rizqullah Mediska Indonesia — Tim HRL_";
}
?>

<style>
.bc-filter{background:var(--rmi-card);border:1px solid var(--rmi-border);border-radius:12px;padding:16px 20px;margin-bottom:16px}
.bc-filter form{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.bc-filter label{font-size:12px;color:var(--rmi-muted);display:block;margin-bottom:3px}
.bc-filter select,.bc-filter input{padding:6px 10px;font-size:13px;border-radius:8px;border:1px solid var(--rmi-border);background:rgba(30,41,59,.8);color:var(--rmi-text)}

.bc-stats{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.bc-stat{background:rgba(6,182,212,.1);border:1px solid rgba(6,182,212,.2);border-radius:10px;padding:8px 16px;font-size:13px;color:#67e8f9}
.bc-stat strong{font-size:18px;display:block}

.bc-actions{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}

.user-card{background:var(--rmi-card);border:1px solid var(--rmi-border);border-radius:12px;padding:16px;margin-bottom:12px;transition:border-color .2s}
.user-card:hover{border-color:rgba(6,182,212,.3)}
.user-card-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
.user-info{flex:1}
.user-name{font-size:15px;font-weight:700;margin-bottom:2px}
.user-meta{font-size:12px;color:var(--rmi-muted)}
.user-badges{display:flex;gap:6px;margin-top:4px;flex-wrap:wrap}
.badge{padding:2px 8px;border-radius:8px;font-size:11px;font-weight:600}
.badge-dept{background:rgba(59,130,246,.2);color:#60a5fa}
.badge-office{background:rgba(16,185,129,.2);color:#34d399}
.badge-override{background:rgba(251,191,36,.2);color:#fbbf24}

.msg-box{background:rgba(15,23,42,.8);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:14px;font-size:13px;line-height:1.7;white-space:pre-wrap;color:var(--rmi-text,#cbd5e1);font-family:system-ui,sans-serif;max-height:200px;overflow-y:auto;margin-bottom:10px}
.copy-btn{padding:7px 16px;background:#25d366;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.copy-btn:hover{background:#1ebe57}
.copy-btn.copied{background:#16a34a}
.wa-btn{padding:7px 14px;background:rgba(37,211,102,.15);color:#25d366;border:1px solid rgba(37,211,102,.3);border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.wa-btn:hover{background:rgba(37,211,102,.25)}
.phone-badge{background:rgba(34,197,94,.1);color:#4ade80;padding:2px 8px;border-radius:6px;font-size:11px}
.no-phone{color:var(--rmi-muted);font-size:11px}

.section-dept{margin:20px 0 10px;font-size:13px;font-weight:700;color:#06b6d4;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid rgba(6,182,212,.2);padding-bottom:6px}
</style>

<div style="max-width:860px;margin:0 auto">

  <!-- Header -->
  <div style="margin-bottom:16px">
    <div class="h1"><?=rmi_icon('outbox')?> Broadcast Japri — Absensi</div>
    <div class="muted small">Generator pesan WhatsApp personal per karyawan. Klik "Copy Pesan" lalu kirim via WA.</div>
  </div>

  <!-- Filter -->
  <div class="bc-filter">
    <form method="get">
      <div>
        <label>Departemen</label>
        <select name="dept">
          <option value="">Semua Dept</option>
          <?php foreach ($depts as $d): ?>
            <option value="<?= htmlspecialchars($d) ?>" <?= $filter_dept===$d?'selected':'' ?>><?= htmlspecialchars($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Office</label>
        <select name="office">
          <option value="">Semua Office</option>
          <?php foreach ($offices as $o): ?>
            <option value="<?= htmlspecialchars($o) ?>" <?= $filter_office===$o?'selected':'' ?>><?= htmlspecialchars($o) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Status</label>
        <select name="status">
          <option value="active" <?= $filter_status==='active'?'selected':'' ?>>Active</option>
          <option value="" <?= $filter_status===''?'selected':'' ?>>Semua</option>
        </select>
      </div>
      <div style="display:flex;gap:6px;align-items:flex-end">
        <button type="submit" class="btn ok" style="padding:7px 14px;font-size:13px">Filter</button>
        <a href="broadcast.php" class="btn" style="padding:7px 14px;font-size:13px">Reset</a>
      </div>
    </form>
  </div>

  <!-- Stats -->
  <div class="bc-stats">
    <div class="bc-stat">
      <strong><?= count($users) ?></strong>
      Karyawan ditampilkan
    </div>
    <div class="bc-stat">
      <strong><?= count(array_filter($users, fn($u) => !empty($u['phone']))) ?></strong>
      Punya nomor HP
    </div>
    <div class="bc-stat">
      <strong><?= count(array_filter($users, fn($u) => !empty($u['abs_office_override']))) ?></strong>
      Override absensi aktif
    </div>
  </div>

  <!-- Copy All -->
  <div class="bc-actions">
    <button class="copy-btn" onclick="copyAll()" id="btnCopyAll">
      <?=rmi_icon('clipboard')?> Copy Semua Pesan (<?= count($users) ?>)
    </button>
    <span class="muted small" style="align-self:center">atau copy per orang di bawah</span>
  </div>

  <!-- User Cards -->
  <?php
  $prev_dept = '';
  foreach ($users as $i => $u):
    if ($u['department'] !== $prev_dept):
      $prev_dept = $u['department'];
  ?>
    <div class="section-dept"><?=rmi_icon('box')?> <?= htmlspecialchars($u['department']) ?></div>
  <?php endif;
    $msg     = generateMessage($u, $abs_url);
    $msgId   = 'msg_' . $i;
    $name    = !empty($u['employee_name']) ? $u['employee_name'] : $u['username'];
    $office  = !empty($u['abs_office_override']) ? $u['abs_office_override'] : ($u['office_code'] ?? '-');
    $isOverride = !empty($u['abs_office_override']);
    $phone   = preg_replace('/[^0-9]/', '', $u['phone'] ?? '');
    if ($phone && str_starts_with($phone, '0')) $phone = '62' . substr($phone, 1);
  ?>
  <div class="user-card">
    <div class="user-card-header">
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($name) ?></div>
        <div class="user-meta">@<?= htmlspecialchars($u['username']) ?></div>
        <div class="user-badges">
          <span class="badge badge-dept"><?= htmlspecialchars($u['department']) ?></span>
          <span class="badge badge-office"><?=rmi_icon('target')?> <?= htmlspecialchars($office) ?></span>
          <?php if ($isOverride): ?>
            <span class="badge badge-override"><?=rmi_icon('zap')?> Override Absensi</span>
          <?php endif; ?>
          <?php if (!empty($u['phone'])): ?>
            <span class="phone-badge"><?=rmi_icon('doc')?> <?= htmlspecialchars($u['phone']) ?></span>
          <?php else: ?>
            <span class="no-phone">Belum ada nomor HP</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Message preview -->
    <div class="msg-box" id="<?= $msgId ?>"><?= htmlspecialchars($msg) ?></div>

    <!-- Actions -->
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="copy-btn" id="btn_<?= $i ?>" onclick="copyMsg('<?= $msgId ?>','btn_<?= $i ?>')">
        <?=rmi_icon('clipboard')?> Copy Pesan
      </button>
      <?php if ($phone): ?>
        <a class="wa-btn" href="https://wa.me/<?= $phone ?>?text=<?= urlencode($msg) ?>" target="_blank">
          <?=rmi_icon('memo')?> Buka di WA
        </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (!$users): ?>
    <div class="muted" style="text-align:center;padding:32px">Tidak ada karyawan yang cocok dengan filter.</div>
  <?php endif; ?>

</div>

<script>
function copyMsg(msgId, btnId) {
  const el = document.getElementById(msgId);
  const btn = document.getElementById(btnId);
  if (!el || !btn) return;
  navigator.clipboard.writeText(el.textContent).then(() => {
    btn.textContent = '<?=rmi_icon('check')?> Tersalin!';
    btn.classList.add('copied');
    setTimeout(() => {
      btn.innerHTML = '<?=rmi_icon('clipboard')?> Copy Pesan';
      btn.classList.remove('copied');
    }, 2500);
  }).catch(() => {
    // Fallback
    const ta = document.createElement('textarea');
    ta.value = el.textContent;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    btn.textContent = '<?=rmi_icon('check')?> Tersalin!';
    setTimeout(() => btn.innerHTML = '<?=rmi_icon('clipboard')?> Copy Pesan', 2500);
  });
}

function copyAll() {
  const boxes = document.querySelectorAll('.msg-box');
  const all = Array.from(boxes).map(b => b.textContent).join('\n\n' + '—'.repeat(40) + '\n\n');
  const btn = document.getElementById('btnCopyAll');
  navigator.clipboard.writeText(all).then(() => {
    btn.textContent = '<?=rmi_icon('check')?> Semua Tersalin!';
    setTimeout(() => btn.innerHTML = '<?=rmi_icon('clipboard')?> Copy Semua Pesan (<?= count($users) ?>)', 2500);
  });
}
</script>

<?php require_once __DIR__ . "/../_layout_bottom.php"; ?>
