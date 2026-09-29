<?php
/**
 * manufacturer_portal/layout.php
 * Layout wrapper untuk halaman portal pabrikan. 3 bahasa: EN, 中文, ID.
 */
declare(strict_types=1);
require_once __DIR__ . '/../_shared/rmi_icons.php';

$base = mportal_base();
$user = mportal_user();
$lang = mportal_lang();
$langParam = '?lang=';
?>
<?php
$assetsBase = $base . '/public/assets/vendor';
$curPage    = basename($_SERVER['SCRIPT_NAME'] ?? '');
$reqUri     = $_SERVER['REQUEST_URI'] ?? '/manufacturer_portal/';
$sep        = (strpos($reqUri, '?') !== false) ? '&' : '?';
function mp_nav_active(string $file): string {
    global $curPage;
    return $curPage === $file ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="<?= $lang === 'zh' ? 'zh-CN' : ($lang === 'id' ? 'id' : 'en') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= rmi_h($pageTitle ?? mportal_t('dashboard')) ?> — RMI Manufacturer Portal</title>
<link href="<?= $assetsBase ?>/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{--mp-navy:#0f2d5a;--mp-blue:#1d4ed8;--mp-accent:#06b6d4}
body{background:#f1f5f9;font-family:'Segoe UI',system-ui,sans-serif;color:#1e293b;min-height:100vh;display:flex;flex-direction:column}
main{flex:1}

.mp-navbar{background:linear-gradient(135deg,#0f2d5a 0%,#1a4480 50%,#1d4ed8 100%);box-shadow:0 2px 16px rgba(0,0,0,.25)}
.mp-navbar .navbar-brand{font-weight:800;color:#fff!important;display:flex;align-items:center;gap:10px;font-size:1rem}
.mp-navbar .navbar-brand .brand-sub{font-size:10px;color:rgba(255,255,255,.6);font-weight:400;display:block}
.mp-navbar .nav-link{color:rgba(255,255,255,.8)!important;font-size:13px;font-weight:500;padding:8px 12px;border-radius:8px;transition:all .2s}
.mp-navbar .nav-link:hover,.mp-navbar .nav-link.active{color:#fff!important;background:rgba(255,255,255,.12)}
.mp-navbar .nav-link.active{background:rgba(6,182,212,.2)}
.mp-navbar .navbar-toggler{border-color:rgba(255,255,255,.3)}
.mp-navbar .navbar-toggler-icon{filter:brightness(10)}

.lang-btn{padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;color:rgba(255,255,255,.5);text-decoration:none;transition:all .2s}
.lang-btn:hover,.lang-btn.active-lang{color:#fff;background:rgba(255,255,255,.15)}
.lang-divider{color:rgba(255,255,255,.2)}

.mp-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.mp-card-header{padding:14px 20px;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:14px;color:#1e293b;display:flex;align-items:center;justify-content:space-between}

.kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;transition:all .2s;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.kpi-card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.1)}
.kpi-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:10px}
.kpi-val{font-size:22px;font-weight:800;margin-bottom:2px}
.kpi-label{font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.4px}

.btn-mp{background:linear-gradient(135deg,#0f2d5a,#1d4ed8);color:#fff;border:none;border-radius:8px;font-weight:600}
.btn-mp:hover{background:linear-gradient(135deg,#0c2347,#1e40af);color:#fff}
.btn-mp-outline{border:2px solid #1d4ed8;color:#1d4ed8;border-radius:8px;font-weight:600;background:transparent}
.btn-mp-outline:hover{background:#1d4ed8;color:#fff}

.mp-table{border-collapse:collapse;width:100%}
.mp-table th{background:#f8fafc;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:10px 14px;border-bottom:2px solid #e2e8f0}
.mp-table td{padding:12px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:13px}
.mp-table tbody tr:hover{background:#f8fafc}

.alert{border:none;border-radius:10px;padding:12px 16px;font-size:13px}

.mp-footer{background:#0f2d5a;color:rgba(255,255,255,.5);padding:16px 0;text-align:center;font-size:12px;margin-top:auto}
.mp-footer strong{color:#06b6d4}

@media(max-width:576px){.kpi-card{margin-bottom:10px}}
</style>
</head>
<body>

<nav class="mp-navbar navbar navbar-expand-lg">
  <div class="container">
    <a class="navbar-brand" href="<?= rmi_h($base) ?>/manufacturer_portal/">
      <div style="width:32px;height:32px;background:rgba(255,255,255,.12);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px"><?= rmi_icon('office') ?></div>
      <div>
        <div>RMI Manufacturer Portal</div>
        <span class="brand-sub">Rizqullah Mediska Indonesia</span>
      </div>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mpNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mpNav">
      <ul class="navbar-nav me-auto gap-1">
        <li class="nav-item"><a class="nav-link <?= mp_nav_active('index.php') ?>" href="<?= rmi_h($base) ?>/manufacturer_portal/"><?= rmi_icon('home') ?> <?= rmi_h(mportal_t('dashboard')) ?></a></li>
        <li class="nav-item"><a class="nav-link <?= mp_nav_active('manufacture_docs.php') ?>" href="<?= rmi_h($base) ?>/manufacturer_portal/manufacture_docs.php"><?= rmi_icon('doc') ?> <?= rmi_h(mportal_t('partnership_proposal')) ?></a></li>
        <li class="nav-item"><a class="nav-link <?= mp_nav_active('rfq.php') ?>" href="<?= rmi_h($base) ?>/manufacturer_portal/rfq.php"><?= rmi_icon('clipboard') ?> <?= rmi_h(mportal_t('rfq')) ?></a></li>
        <li class="nav-item"><a class="nav-link <?= mp_nav_active('cases.php') ?>" href="<?= rmi_h($base) ?>/manufacturer_portal/cases.php"><?= rmi_icon('office') ?> <?= rmi_h(mportal_t('reg_alkes_cases')) ?></a></li>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <!-- Language switcher -->
        <div class="d-flex align-items-center gap-1">
          <a class="lang-btn <?= $lang==='en'?'active-lang':'' ?>" href="<?= rmi_h($reqUri.$sep.'lang=en') ?>">EN</a>
          <span class="lang-divider">|</span>
          <a class="lang-btn <?= $lang==='zh'?'active-lang':'' ?>" href="<?= rmi_h($reqUri.$sep.'lang=zh') ?>">中文</a>
          <span class="lang-divider">|</span>
          <a class="lang-btn <?= $lang==='id'?'active-lang':'' ?>" href="<?= rmi_h($reqUri.$sep.'lang=id') ?>">ID</a>
        </div>
        <div style="width:1px;height:20px;background:rgba(255,255,255,.2)"></div>
        <span style="font-size:12px;color:rgba(255,255,255,.7)"><?= rmi_icon('user') ?> <?= rmi_h($user['full_name'] ?: $user['username']) ?></span>
        <a class="btn btn-sm btn-outline-light" href="<?= rmi_h($base) ?>/manufacturer_portal/logout.php"><?= rmi_h(mportal_t('logout')) ?></a>
      </div>
    </div>
  </div>
</nav>

<main>
  <div class="container py-4">
    <?php if (!empty($flash)): ?>
      <div class="alert alert-<?= rmi_h($flash['type']??'info') ?> mb-4"><?= rmi_h($flash['msg']??'') ?></div>
    <?php endif; ?>
    <?= $content ?? '' ?>
  </div>
</main>

<footer class="mp-footer">
  &copy; <?= date('Y') ?> <strong>Rizqullah Mediska Indonesia</strong>
  &nbsp;·&nbsp; <?= rmi_h(mportal_t('pqp_contact')) ?>
</footer>

<script src="<?= $assetsBase ?>/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
