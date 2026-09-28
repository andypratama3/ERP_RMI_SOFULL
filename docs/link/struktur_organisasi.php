<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_login();
require_once __DIR__ . '/../../_shared/org_structure_config.php';

/** @var array<string,mixed> $org */
$org = org_structure_load();
$doc = is_array($org['doc'] ?? null) ? $org['doc'] : [];
$director = is_array($org['director'] ?? null) ? $org['director'] : [];
$vision = is_array($org['vision'] ?? null) ? $org['vision'] : [];
$legend = is_array($org['legend'] ?? null) ? $org['legend'] : [];
$divisions = is_array($org['divisions'] ?? null) ? $org['divisions'] : [];
$branchBlock = is_array($org['branch_block'] ?? null) ? $org['branch_block'] : [];
$branches = is_array($branchBlock['branches'] ?? null) ? $branchBlock['branches'] : [];
$notes = is_array($org['notes'] ?? null) ? $org['notes'] : [];
$signatures = is_array($org['signatures'] ?? null) ? $org['signatures'] : [];
$footerBase = isset($org['footer']) ? (string)$org['footer'] : 'Rizqullah Mediska Indonesia • ORG-RMI-001';

$baseProject = defined('BASE_PROJECT') ? rtrim((string)BASE_PROJECT, '/') : '';
$canEditOrg = function_exists('auth_is_sys') && auth_is_sys();

/**
 * @param array<string,mixed> $row
 */
function _os_doc_str(array $row, string $key, string $default = ''): string {
    $v = $row[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

function _os_hex_color(mixed $c): string {
    $s = trim((string)$c);
    return preg_match('/^#[0-9A-Fa-f]{3,8}$/', $s) ? $s : '#64748b';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Struktur Organisasi — Rizqullah Mediska Indonesia</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
*{box-sizing:border-box;margin:0;padding:0}

body{background:#f0f4f8;font-family:'Inter',system-ui,sans-serif;color:#1e293b;min-height:100vh}

@media print {
  body{background:#fff}
  .no-print{display:none!important}
  .page{box-shadow:none;border-radius:0}
  .org-node{break-inside:avoid}
  @page{margin:1.5cm;size:A4 landscape}
}

.page{max-width:1200px;margin:0 auto;padding:24px 16px}

/* Header */
.doc-header{background:linear-gradient(135deg,#0f2d5a 0%,#1a56a0 60%,#2563eb 100%);color:#fff;border-radius:20px;padding:36px 40px;margin-bottom:28px;position:relative;overflow:hidden}
.doc-header::before{content:"";position:absolute;top:-80px;right:-80px;width:280px;height:280px;border-radius:50%;background:rgba(255,255,255,.06)}
.doc-header::after{content:"";position:absolute;bottom:-60px;left:60px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.04)}
.doc-company{font-size:11px;font-weight:700;letter-spacing:2px;color:rgba(255,255,255,.6);text-transform:uppercase;margin-bottom:6px}
.doc-title{font-size:28px;font-weight:900;margin-bottom:6px;letter-spacing:-.5px}
.doc-sub{font-size:13px;color:rgba(255,255,255,.75);margin-bottom:20px}
.doc-meta{display:flex;gap:20px;flex-wrap:wrap;font-size:12px;color:rgba(255,255,255,.6)}
.doc-meta strong{color:#fff}
.doc-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:600;margin-top:14px}

/* Actions */
.actions{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.btn-blue{background:#2563eb;color:#fff}
.btn-blue:hover{background:#1d4ed8}
.btn-white{background:#fff;color:#374151;border:1px solid #d1d5db}
.btn-white:hover{background:#f9fafb}

/* Vision banner */
.vision-banner{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px 24px;margin-bottom:28px;display:flex;gap:20px;align-items:center;flex-wrap:wrap}
.vision-item{flex:1;min-width:200px;text-align:center;padding:12px}
.vision-icon{font-size:28px;margin-bottom:6px}
.vision-label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.vision-text{font-size:14px;font-weight:700;color:#1e293b}
.vision-sep{width:1px;background:#e2e8f0;align-self:stretch}

/* ORG CHART */
.org-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px;margin-bottom:28px;overflow-x:auto}
.org-chart{display:flex;flex-direction:column;align-items:center;gap:0;min-width:900px}

/* Director */
.dir-box{background:linear-gradient(135deg,#0f2d5a,#2563eb);color:#fff;border-radius:16px;padding:18px 40px;text-align:center;box-shadow:0 8px 32px rgba(37,99,235,.3);position:relative;z-index:2}
.dir-title{font-size:11px;letter-spacing:1px;color:rgba(255,255,255,.7);text-transform:uppercase;margin-bottom:4px}
.dir-name{font-size:18px;font-weight:800}
.dir-sub{font-size:12px;color:rgba(255,255,255,.7);margin-top:2px}

/* Connector lines */
.v-line{width:2px;height:32px;background:#cbd5e1;margin:0 auto}
.h-connector{display:flex;align-items:flex-start;position:relative;width:100%;justify-content:center}
.h-connector::before{content:"";position:absolute;top:0;left:50%;transform:translateX(-50%);width:85%;height:2px;background:#cbd5e1}
.col-connector{display:flex;flex-direction:column;align-items:center;flex:1}
.col-connector .v-line{height:24px}

/* Division boxes */
.divs-row{display:flex;gap:12px;width:100%;justify-content:center;align-items:flex-start}

.div-box{flex:1;min-width:140px;max-width:180px;border-radius:14px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);transition:transform .2s,box-shadow .2s}
.div-box:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.15)}
.div-header{padding:14px 12px 10px;text-align:center;color:#fff}
.div-header .div-icon{font-size:24px;margin-bottom:4px}
.div-header .div-title{font-size:12px;font-weight:700;line-height:1.3}
.div-header .div-code{font-size:10px;opacity:.8;margin-top:2px}
.div-body{background:#fff;padding:10px 12px}
.div-unit{display:flex;align-items:center;gap:6px;padding:4px 0;border-bottom:1px solid #f1f5f9;font-size:11px;color:#475569}
.div-unit:last-child{border-bottom:none}
.div-unit .unit-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}

/* Colors per division */
.div-fin .div-header{background:linear-gradient(135deg,#059669,#10b981)}
.div-fin .unit-dot{background:#10b981}
.div-scm .div-header{background:linear-gradient(135deg,#d97706,#f59e0b)}
.div-scm .unit-dot{background:#f59e0b}
.div-sales .div-header{background:linear-gradient(135deg,#2563eb,#3b82f6)}
.div-sales .unit-dot{background:#3b82f6}
.div-hrl .div-header{background:linear-gradient(135deg,#dc2626,#ef4444)}
.div-hrl .unit-dot{background:#ef4444}
.div-itc .div-header{background:linear-gradient(135deg,#7c3aed,#8b5cf6)}
.div-itc .unit-dot{background:#8b5cf6}
.div-branch .div-header{background:linear-gradient(135deg,#0e7490,#06b6d4)}
.div-branch .unit-dot{background:#06b6d4}
.div-mfg .div-header{background:linear-gradient(135deg,#374151,#6b7280);opacity:.85}
.div-mfg .unit-dot{background:#6b7280}
.div-mfg{opacity:.85}
.future-label{font-size:9px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;padding:1px 6px;display:inline-block;margin-top:4px}

/* Branch grid */
.branch-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;padding:8px 10px;background:#fff}
.branch-item{background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:5px 8px;text-align:center}
.branch-code{font-size:12px;font-weight:800;color:#0369a1}
.branch-city{font-size:10px;color:#64748b}
.branch-hq{background:#dbeafe;border-color:#93c5fd}
.branch-hq .branch-code{color:#1d4ed8}

/* Legend */
.legend{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;align-items:center}
.legend-item{display:flex;align-items:center;gap:6px;font-size:12px;color:#64748b}
.legend-dot{width:12px;height:12px;border-radius:3px}

/* Notes section */
.notes-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-bottom:28px}
.note-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px}
.note-title{font-size:13px;font-weight:700;color:#1e293b;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.note-item{display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;font-size:12px;color:#475569}
.note-item:last-child{margin-bottom:0}
.note-icon{flex-shrink:0;margin-top:1px}

/* Signature */
.sig-section{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-top:24px}
.sig-title{font-size:14px;font-weight:700;color:#1e293b;margin-bottom:20px;text-align:center}
.sig-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.sig-box{text-align:center;padding:12px}
.sig-line{border-top:1px solid #94a3b8;margin:48px 8px 8px;font-size:11px;color:#64748b}
.sig-label{font-size:12px;font-weight:700;color:#374151}
.sig-dept{font-size:11px;color:#64748b;margin-top:2px}

.doc-footer{text-align:center;font-size:11px;color:#94a3b8;margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0}
</style>
</head>
<body>
<div class="page">

  <!-- Actions -->
  <div class="actions no-print">
    <button class="btn btn-blue" type="button" onclick="window.print()">🖨️ Cetak / Save PDF</button>
    <a href="<?= rmi_h($baseProject) ?>/" class="btn btn-white">← Kembali ke ERP</a>
    <?php if ($canEditOrg): ?>
    <a href="<?= rmi_h($baseProject) ?>/master/org_structure_edit.php" class="btn btn-white">✏️ Edit konfigurasi (SYS)</a>
    <?php endif; ?>
  </div>

  <!-- Header -->
  <div class="doc-header">
    <div class="doc-company"><?= rmi_h(_os_doc_str($doc, 'company_line', 'Rizqullah Mediska Indonesia — Dokumen Resmi')) ?></div>
    <div class="doc-title"><?= rmi_h(_os_doc_str($doc, 'title', '🏢 Struktur Organisasi')) ?></div>
    <div class="doc-sub"><?= rmi_h(_os_doc_str($doc, 'sub', 'Susunan Divisi & Jabatan Perusahaan')) ?></div>
    <div class="doc-meta">
      <span>📄 Nomor: <strong><?= rmi_h(_os_doc_str($doc, 'number', 'ORG-RMI-001')) ?></strong></span>
      <span>📅 Tanggal: <strong><?= rmi_h(date('d F Y')) ?></strong></span>
      <span>🔄 Revisi: <strong><?= rmi_h(_os_doc_str($doc, 'revision', '01')) ?></strong></span>
    </div>
    <?php
      $__badge = _os_doc_str($doc, 'badge', '');
    ?>
    <?php if ($__badge !== ''): ?>
    <div class="doc-badge"><?= rmi_h($__badge) ?></div>
    <?php endif; ?>
  </div>

  <!-- Vision -->
  <?php if ($vision !== []): ?>
  <div class="vision-banner">
    <?php
    $vi = 0;
    foreach ($vision as $item):
        if (!is_array($item)) {
            continue;
        }
        $vi++;
        if ($vi > 1) {
            echo '<div class="vision-sep"></div>';
        }
    ?>
    <div class="vision-item">
      <div class="vision-icon"><?= rmi_h(_os_doc_str($item, 'icon', '•')) ?></div>
      <div class="vision-label"><?= rmi_h(_os_doc_str($item, 'label', '')) ?></div>
      <div class="vision-text"><?= rmi_h(_os_doc_str($item, 'text', '')) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Legend -->
  <?php if ($legend !== []): ?>
  <div class="legend no-print">
    <span style="font-size:12px;font-weight:700;color:#374151;margin-right:4px">Keterangan:</span>
    <?php foreach ($legend as $leg): ?>
      <?php if (!is_array($leg)) { continue; } ?>
      <?php
        $dim = !empty($leg['dim']);
        $dotStyle = 'background:' . _os_hex_color($leg['color'] ?? '#64748b');
        if ($dim) {
            $dotStyle .= ';opacity:.7';
        }
      ?>
    <div class="legend-item"><div class="legend-dot" style="<?= rmi_h($dotStyle) ?>"></div> <?= rmi_h((string)($leg['label'] ?? '')) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ORG CHART -->
  <div class="org-wrap">
    <div class="org-chart">

      <!-- Direktur Utama -->
      <div class="dir-box">
        <div class="dir-title"><?= rmi_h(_os_doc_str($director, 'title', 'Pimpinan Perusahaan')) ?></div>
        <div class="dir-name"><?= rmi_h(_os_doc_str($director, 'name', 'Direktur Utama')) ?></div>
        <div class="dir-sub"><?= rmi_h(_os_doc_str($director, 'sub', 'Rizqullah Mediska Indonesia')) ?></div>
      </div>

      <div class="v-line" style="height:36px"></div>

      <!-- H connector -->
      <div style="width:92%;height:2px;background:#cbd5e1"></div>

      <!-- Row 1: Divisions -->
      <div class="divs-row">
        <?php foreach ($divisions as $div): ?>
          <?php if (!is_array($div)) { continue; } ?>
          <?php
            $rawClass = preg_replace('/[^a-z0-9_-]/i', '', (string)($div['box_class'] ?? 'div-fin'));
            $allowedBox = ['div-fin', 'div-scm', 'div-sales', 'div-hrl', 'div-itc', 'div-branch', 'div-mfg'];
            $boxClass = in_array($rawClass, $allowedBox, true) ? $rawClass : 'div-fin';
            $future = !empty($div['future']);
            $vlineExtra = $future ? ' style="background:#d1d5db"' : '';
            $units = $div['units'] ?? [];
            if (!is_array($units)) {
                $units = [];
            }
          ?>
        <div class="col-connector">
          <div class="v-line"<?= $vlineExtra ?>></div>
          <div class="div-box <?= rmi_h($boxClass) ?>">
            <div class="div-header">
              <div class="div-icon"><?= rmi_h(_os_doc_str($div, 'icon', '')) ?></div>
              <div class="div-title"><?= rmi_h(_os_doc_str($div, 'title', '')) ?></div>
              <div class="div-code"><?= rmi_h(_os_doc_str($div, 'code', '')) ?></div>
              <?php if ($future): ?>
              <div><span class="future-label"><?= rmi_h(_os_doc_str($div, 'future_caption', '🚀 Rencana ke depan')) ?></span></div>
              <?php endif; ?>
            </div>
            <div class="div-body">
              <?php foreach ($units as $u): ?>
                <?php if (!is_string($u)) { continue; } ?>
              <div class="div-unit"><div class="unit-dot"></div><?= rmi_h($u) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div><!-- /divs-row -->

      <!-- Branch section -->
      <?php if ($branches !== []): ?>
      <div style="width:92%;height:2px;background:#cbd5e1;margin-top:28px"></div>
      <div class="v-line"></div>

      <div style="width:100%;max-width:700px">
        <div class="div-box div-branch" style="max-width:100%">
          <div class="div-header" style="display:flex;align-items:center;gap:12px;text-align:left;padding:14px 16px">
            <span style="font-size:28px">🏢</span>
            <div>
              <div class="div-title" style="font-size:13px"><?= rmi_h(_os_doc_str($branchBlock, 'title', 'Jaringan Cabang')) ?></div>
              <div class="div-code"><?= rmi_h(_os_doc_str($branchBlock, 'subtitle', '')) ?></div>
            </div>
          </div>
          <div class="branch-grid">
            <?php foreach ($branches as $b): ?>
              <?php if (!is_array($b)) { continue; } ?>
              <?php
                $hq = !empty($b['hq']);
                $hqClass = $hq ? ' branch-hq' : '';
              ?>
            <div class="branch-item<?= $hqClass ?>">
              <div class="branch-code"><?= rmi_h((string)($b['code'] ?? '')) ?></div>
              <div class="branch-city"><?= rmi_h((string)($b['city'] ?? '')) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /org-chart -->
  </div><!-- /org-wrap -->

  <!-- Notes -->
  <?php if ($notes !== []): ?>
  <div class="notes-grid">
    <?php foreach ($notes as $card): ?>
      <?php if (!is_array($card)) { continue; } ?>
    <div class="note-card">
      <div class="note-title"><?= rmi_h(_os_doc_str($card, 'title', '')) ?></div>
      <?php
        $items = $card['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
      ?>
      <?php foreach ($items as $it): ?>
        <?php if (!is_array($it)) { continue; } ?>
      <div class="note-item"><span class="note-icon"><?= rmi_h(_os_doc_str($it, 'icon', '•')) ?></span><?= rmi_h(_os_doc_str($it, 'text', '')) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Signature -->
  <?php if ($signatures !== []): ?>
  <div class="sig-section">
    <div class="sig-title">Pengesahan Dokumen Struktur Organisasi</div>
    <div class="sig-grid">
      <?php foreach ($signatures as $sig): ?>
        <?php if (!is_array($sig)) { continue; } ?>
      <div class="sig-box">
        <div class="sig-line"><?= rmi_h(_os_doc_str($sig, 'line', '')) ?></div>
        <div class="sig-label"><?= rmi_h(_os_doc_str($sig, 'label', '')) ?></div>
        <div class="sig-dept"><?= rmi_h(_os_doc_str($sig, 'dept', '')) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="doc-footer">
    <?= rmi_h($footerBase) ?> • <?= rmi_h(date('d F Y')) ?> • Dokumen Resmi Perusahaan
  </div>

</div><!-- /page -->
</body>
</html>
