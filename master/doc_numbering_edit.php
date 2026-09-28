<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();

if (!function_exists('auth_is_sys') || !auth_is_sys()) {
    http_response_code(403);
    exit('403 Forbidden — halaman ini hanya untuk SYS.');
}

require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';

$JSON_FILE = __DIR__ . '/../config/doc_numbering.json';
$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : (function_exists('db_pdo') ? db_pdo() : null);

// Label & deskripsi setiap kode dokumen
$DOC_META = [
    'do'      => ['label' => 'DO — Delivery Order (Sales)',    'desc' => 'Nomor Surat Jalan / DO dari CRM ke customer.'],
    'po'      => ['label' => 'PO — Purchase Order',            'desc' => 'Nomor PO pembelian ke manufaktur / supplier.'],
    'pr'      => ['label' => 'PR — Purchase Request',          'desc' => 'Nomor permintaan pengadaan dari WQS.'],
    'ap'      => ['label' => 'AP Invoice — Invoice Pembelian', 'desc' => 'Nomor invoice AP dari supplier.'],
    'pay'     => ['label' => 'AP Payment — Pembayaran AP',     'desc' => 'Nomor bukti pembayaran AP.'],
    'fap'     => ['label' => 'Forwarder AP Invoice',           'desc' => 'Nomor invoice forwarder / jasa logistik.'],
    'fpay'    => ['label' => 'Forwarder Payment',              'desc' => 'Nomor pembayaran forwarder.'],
    'pib_pay' => ['label' => 'PIB/CEISA Payment',              'desc' => 'Nomor pembayaran bea cukai / PIB.'],
    'rfq'     => ['label' => 'RFQ — Request For Quotation',    'desc' => 'Nomor permintaan penawaran ke supplier. Placeholder: {YEAR}.'],
];

$DEFAULTS = [
    'do' => 'RMI-{OFFICE}-{DATE}-', 'po' => 'RMI-PO-{OFFICE}-{DATE}-',
    'pr' => 'RMI-PR-{OFFICE}-{DATE}-', 'ap' => 'RMI-AP-{OFFICE}-{DATE}-',
    'pay' => 'RMI-PAY-{OFFICE}-{DATE}-', 'fap' => 'RMI-FAP-{OFFICE}-{DATE}-',
    'fpay' => 'RMI-FPAY-{OFFICE}-{DATE}-', 'pib_pay' => 'RMI-PIB-PAY-{OFFICE}-{DATE}-',
    'rfq' => 'RFQ-{YEAR}-',
];

$flash = ''; $flashOk = true;
$current = $DEFAULTS;
if (file_exists($JSON_FILE)) {
    $loaded = json_decode((string)file_get_contents($JSON_FILE), true);
    if (is_array($loaded)) $current = array_merge($current, $loaded);
}

// Preview: replace placeholder dengan contoh nyata
function dn_preview(string $tpl): string {
    $sample = str_replace('{CATEGORY}', 'BMHP',   $tpl);
    $sample = str_replace('{OFFICE}',   'BGR',     $sample);
    $sample = str_replace('{DATE}',     '260318',  $sample);
    $sample = str_replace('{YEAR}',     date('Y'), $sample);
    return $sample . str_pad('1', 3, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) verify_csrf();

    if (isset($_POST['reset_defaults'])) {
        $newCfg = $DEFAULTS;
        $newCfg['_note'] = 'Edit via web: master/doc_numbering_edit.php (SYS only). Placeholder: {OFFICE} = kode cabang, {DATE} = YYMMDD, {YEAR} = YYYY.';
        $json = json_encode($newCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $JSON_FILE . '.tmp';
        if (file_put_contents($tmp, $json) !== false && rename($tmp, $JSON_FILE)) {
            $flash = '✓ Penomoran direset ke default.'; $flashOk = true;
            $current = $DEFAULTS;
            if ($pdo) master_audit($pdo, 'doc_numbering', 'config', 'RESET', null, 'doc_numbering.json', 'Reset ke default oleh ' . ($_SESSION['username'] ?? '-'), []);
        } else {
            $flash = 'Gagal menyimpan file. Cek permission folder config/.'; $flashOk = false;
        }
    } else {
        $newCfg = $current;
        $errors = [];
        foreach (array_keys($DOC_META) as $key) {
            $val = trim((string)($_POST['fmt'][$key] ?? ''));
            if ($val === '') { $errors[] = "Format {$key} tidak boleh kosong."; continue; }
            // Validasi: hanya karakter aman untuk prefix nomor dokumen
            if (!preg_match('/^[A-Za-z0-9\-_\/\.{}]+$/', $val)) {
                $errors[] = "Format {$key} mengandung karakter tidak valid (gunakan huruf, angka, -, _, {OFFICE}, {DATE}, {YEAR}).";
                continue;
            }
            // Pastikan tidak ada script injection
            if (preg_match('/<\?|<script|eval\(|base64/i', $val)) {
                $errors[] = "Format {$key} mengandung konten tidak diizinkan.";
                continue;
            }
            $newCfg[$key] = $val;
        }

        if ($errors) {
            $flash = implode(' | ', $errors); $flashOk = false;
        } else {
            $newCfg['_note'] = 'Edit via web: master/doc_numbering_edit.php (SYS only). Placeholder: {OFFICE} = kode cabang, {DATE} = YYMMDD, {YEAR} = YYYY.';
            $json = json_encode($newCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $tmp  = $JSON_FILE . '.tmp';
            if (file_put_contents($tmp, $json) !== false && rename($tmp, $JSON_FILE)) {
                $flash = '✓ Format penomoran berhasil disimpan.'; $flashOk = true;
                $current = $newCfg;
                if ($pdo) master_audit($pdo, 'doc_numbering', 'config', 'SAVE', null, 'doc_numbering.json', 'Format penomoran diubah oleh ' . ($_SESSION['username'] ?? '-'), $newCfg);
            } else {
                $flash = 'Gagal menyimpan file. Cek permission folder config/.'; $flashOk = false;
            }
        }
    }
}

$baseProject = rmi_layout_base_project();
rmi_header('Format Penomoran Dokumen', [
    'active'      => 'master',
    'breadcrumbs' => [
        ['label' => 'Master Data', 'url' => $baseProject . '/master/master_data.php'],
        'Format Penomoran Dokumen',
    ],
    'actions' => [
        ['label' => '← Master Data', 'url' => $baseProject . '/master/master_data.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
    'extra_head' => '<style>
    .dn-card{background:rgba(17,24,39,.82);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:20px;margin-bottom:14px}
    .dn-label{font-size:12px;font-weight:700;color:#e2e8f0;margin-bottom:2px}
    .dn-desc{font-size:11px;color:#6b7280;margin-bottom:8px}
    .dn-row{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
    .dn-field{width:100%;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.12);color:#e2e8f0;border-radius:10px;padding:9px 12px;font-size:13px;font-family:ui-monospace,monospace}
    .dn-field:focus{outline:none;border-color:rgba(96,165,250,.5)}
    .dn-preview{font-size:11px;color:#6b7280;margin-top:5px;font-family:ui-monospace,monospace}
    .dn-preview b{color:#fbbf24}
    .dn-flash{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px}
    .dn-flash.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
    .dn-flash.bad{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
    .dn-hint{background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:12px;color:#93c5fd;line-height:1.6}
    .dn-hint code{background:rgba(255,255,255,.08);padding:1px 6px;border-radius:4px;font-size:11px}
    </style>',
]);
?>

<div class="mb-3">
  <h4 class="mb-1">🔢 Format Penomoran Dokumen</h4>
  <div style="font-size:12px;color:#6b7280">Atur prefix nomor untuk setiap jenis dokumen ERP. Berlaku untuk dokumen <b>baru</b> — dokumen lama di database tidak berubah.</div>
</div>

<?php if ($flash): ?>
<div class="dn-flash <?= $flashOk ? 'ok' : 'bad' ?>"><?= htmlspecialchars($flash, ENT_QUOTES) ?></div>
<?php endif; ?>

<div class="dn-hint">
  <b>Placeholder yang bisa dipakai:</b><br>
  <code>{OFFICE}</code> — kode cabang otomatis (BGR, BKS, TGR, dll.) &nbsp;|&nbsp;
  <code>{DATE}</code> — tanggal format YYMMDD (contoh: 260318) &nbsp;|&nbsp;
  <code>{YEAR}</code> — tahun 4 digit (contoh: 2026, khusus RFQ)<br>
  <br>
  <b>Contoh:</b> format <code>BMHP-{OFFICE}-{DATE}-</code> menghasilkan <b>BMHP-BGR-260318-001</b>
</div>

<form method="post">
  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

  <?php foreach ($DOC_META as $key => $meta): ?>
  <div class="dn-card">
    <div class="dn-label"><?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></div>
    <div class="dn-desc"><?= htmlspecialchars($meta['desc'], ENT_QUOTES) ?></div>
    <div class="dn-row">
      <input
        class="dn-field"
        type="text"
        name="fmt[<?= htmlspecialchars($key, ENT_QUOTES) ?>]"
        value="<?= htmlspecialchars((string)($current[$key] ?? $DEFAULTS[$key]), ENT_QUOTES) ?>"
        placeholder="<?= htmlspecialchars($DEFAULTS[$key], ENT_QUOTES) ?>"
        id="fmt_<?= htmlspecialchars($key, ENT_QUOTES) ?>"
        oninput="updatePreview('<?= htmlspecialchars($key, ENT_QUOTES) ?>')"
        spellcheck="false"
        autocomplete="off"
      >
    </div>
    <div class="dn-preview" id="prev_<?= htmlspecialchars($key, ENT_QUOTES) ?>">
      Contoh: <b><?= htmlspecialchars(dn_preview((string)($current[$key] ?? $DEFAULTS[$key])), ENT_QUOTES) ?></b>
    </div>
  </div>
  <?php endforeach; ?>

  <div style="display:flex;gap:10px;margin-top:4px">
    <button type="submit" class="btn btn-primary">💾 Simpan Format</button>
    <button type="submit" name="reset_defaults" value="1" class="btn btn-outline-secondary"
      onclick="return confirm('Reset semua format ke default (RMI-...)?')">↺ Reset Default</button>
  </div>
</form>

<script>
function updatePreview(key) {
  var input = document.getElementById('fmt_' + key);
  var prev  = document.getElementById('prev_' + key);
  if (!input || !prev) return;
  var tpl = input.value;
  var sample = tpl
    .replace(/\{CATEGORY\}/g, 'BMHP')
    .replace(/\{OFFICE\}/g,   'BGR')
    .replace(/\{DATE\}/g,     '260318')
    .replace(/\{YEAR\}/g,     '<?= date('Y') ?>');
  prev.innerHTML = 'Contoh: <b>' + sample + '001</b>';
}
</script>

<?php rmi_footer(); ?>
