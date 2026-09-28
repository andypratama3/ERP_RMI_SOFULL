<?php
/**
 * customer_portal/layout.php
 * Layout Portal Customer — Rizqullah Mediska Indonesia
 */
declare(strict_types=1);

$base = portal_base();
$user = portal_user();
$cart     = $_SESSION['portal_cart'] ?? [];
$cartCnt  = is_array($cart) ? array_sum(array_column($cart, 'qty')) : 0;
$curPage  = basename($_SERVER['SCRIPT_NAME'] ?? '');

function cp_nav_active(string $file): string {
    global $curPage;
    return $curPage === $file ? 'active' : '';
}

$assetsBase = $base . '/public/assets/vendor';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= rmi_h($pageTitle ?? 'Portal') ?> — RMI Customer Portal</title>
<link href="<?= $assetsBase ?>/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<style>
:root {
  --rmi-green: #16a34a;
  --rmi-green-light: #22c55e;
  --rmi-blue: #1d4ed8;
  --rmi-blue-light: #3b82f6;
  --rmi-gray: #f8fafc;
}

/* Base */
body { background:#f1f5f9; font-family:'Segoe UI',system-ui,sans-serif; color:#1e293b; min-height:100vh; display:flex; flex-direction:column }
main { flex:1 }

/* Navbar */
.cp-navbar { background:linear-gradient(135deg,#14532d 0%,#166534 40%,#1d4ed8 100%); box-shadow:0 2px 12px rgba(0,0,0,.2) }
.cp-navbar .navbar-brand { font-weight:800; font-size:1.1rem; color:#fff!important; display:flex; align-items:center; gap:10px }
.cp-navbar .nav-link { color:rgba(255,255,255,.82)!important; font-weight:500; font-size:14px; padding:8px 14px; border-radius:8px; transition:all .2s }
.cp-navbar .nav-link:hover, .cp-navbar .nav-link.active { color:#fff!important; background:rgba(255,255,255,.15) }
.cp-navbar .nav-link.active { background:rgba(255,255,255,.2); font-weight:600 }
.cp-navbar .navbar-toggler { border-color:rgba(255,255,255,.3) }
.cp-navbar .navbar-toggler-icon { filter:brightness(10) }
.cp-user { color:rgba(255,255,255,.75); font-size:13px }
.cp-user strong { color:#fff }

/* Cart badge */
.cart-badge { background:#fbbf24; color:#1e293b; font-size:10px; font-weight:800; border-radius:10px; padding:1px 6px; margin-left:4px }

/* Logo wrapper */
.brand-icon { width:34px; height:34px; background:rgba(255,255,255,.15); border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:18px }

/* Page header */
.cp-page-header { background:linear-gradient(135deg,#14532d,#1d4ed8); color:#fff; padding:28px 0 20px; margin-bottom:28px }
.cp-page-header h1 { font-size:1.5rem; font-weight:800; margin:0 }
.cp-page-header p { color:rgba(255,255,255,.75); margin:4px 0 0; font-size:14px }

/* Cards */
.kpi-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; height:100%; transition:all .25s; box-shadow:0 1px 4px rgba(0,0,0,.06) }
.kpi-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.1) }
.kpi-card .kpi-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; margin-bottom:12px }
.kpi-card .kpi-val { font-size:24px; font-weight:800; margin-bottom:2px }
.kpi-card .kpi-label { font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.4px }

.cp-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06) }
.cp-card-header { padding:16px 20px; border-bottom:1px solid #f1f5f9; font-weight:700; font-size:15px; display:flex; align-items:center; justify-content:space-between }

/* Status badges */
.status-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px }
.status-paid     { background:#dcfce7; color:#166534 }
.status-open     { background:#fef9c3; color:#854d0e }
.status-partial  { background:#dbeafe; color:#1e40af }
.status-cancel   { background:#fee2e2; color:#991b1b }
.status-default  { background:#f1f5f9; color:#475569 }

/* Table */
.cp-table { border-collapse:collapse; width:100% }
.cp-table th { background:#f8fafc; color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:10px 14px; border-bottom:2px solid #e2e8f0 }
.cp-table td { padding:12px 14px; border-bottom:1px solid #f1f5f9; vertical-align:middle; font-size:14px }
.cp-table tbody tr:hover { background:#f8fafc }
.cp-table tbody tr:last-child td { border-bottom:none }

/* Product card */
.product-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; height:100%; transition:all .2s; box-shadow:0 1px 3px rgba(0,0,0,.05) }
.product-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.1); border-color:#22c55e }
.product-sku { font-size:11px; color:#94a3b8; font-family:monospace; margin-bottom:4px }
.product-name { font-weight:700; font-size:14px; color:#1e293b; margin-bottom:8px; line-height:1.4 }
.product-price { font-size:16px; font-weight:800; color:#16a34a; margin-bottom:12px }

/* Buttons */
.btn-rmi { background:linear-gradient(135deg,#16a34a,#1d4ed8); color:#fff; border:none; border-radius:8px; font-weight:600 }
.btn-rmi:hover { background:linear-gradient(135deg,#15803d,#1e40af); color:#fff }
.btn-rmi-outline { border:2px solid #16a34a; color:#16a34a; border-radius:8px; font-weight:600; background:transparent }
.btn-rmi-outline:hover { background:#16a34a; color:#fff }

/* Search bar */
.cp-search { background:#fff; border:2px solid #e2e8f0; border-radius:12px; padding:10px 16px; font-size:14px; transition:border-color .2s; width:100% }
.cp-search:focus { outline:none; border-color:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,.1) }

/* Alert */
.cp-alert { border-radius:10px; border:none; padding:12px 16px; font-size:14px }

/* Footer */
.cp-footer { background:#1e293b; color:rgba(255,255,255,.6); padding:20px 0; text-align:center; font-size:13px; margin-top:auto }
.cp-footer strong { color:#22c55e }

/* Welcome banner */
.welcome-banner { background:linear-gradient(135deg,#14532d 0%,#166534 50%,#1d4ed8 100%); color:#fff; border-radius:16px; padding:24px 28px; margin-bottom:24px; position:relative; overflow:hidden }
.welcome-banner::before { content:""; position:absolute; top:-30px; right:-30px; width:120px; height:120px; border-radius:50%; background:rgba(255,255,255,.07) }
.welcome-banner::after { content:""; position:absolute; bottom:-20px; right:40px; width:80px; height:80px; border-radius:50%; background:rgba(255,255,255,.05) }
.welcome-banner h2 { font-size:1.4rem; font-weight:800; margin:0 0 4px }
.welcome-banner p { color:rgba(255,255,255,.75); margin:0; font-size:14px }

@media (max-width:576px) {
  .cp-page-header { padding:20px 0 14px }
  .kpi-card { margin-bottom:12px }
  .welcome-banner { padding:18px 20px }
}
</style>
</head>
<body>

<!-- Navbar -->
<nav class="cp-navbar navbar navbar-expand-lg">
    <div class="container">
    <a class="navbar-brand" href="<?= rmi_h($base) ?>/customer_portal/">
      <div class="brand-icon">🏥</div>
      <div>
        <div style="line-height:1.1">RMI Portal</div>
        <div style="font-size:10px;opacity:.7;font-weight:400">Rizqullah Mediska Indonesia</div>
      </div>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#cpNav">
            <span class="navbar-toggler-icon"></span>
        </button>

    <div class="collapse navbar-collapse" id="cpNav">
      <ul class="navbar-nav me-auto gap-1">
        <li class="nav-item">
          <a class="nav-link <?= cp_nav_active('index.php') ?>" href="<?= rmi_h($base) ?>/customer_portal/">
            🏠 Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= cp_nav_active('catalog.php') ?>" href="<?= rmi_h($base) ?>/customer_portal/catalog.php">
            📦 Katalog
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= cp_nav_active('cart.php') ?>" href="<?= rmi_h($base) ?>/customer_portal/cart.php">
            🛒 Keranjang
            <?php if ($cartCnt > 0): ?>
              <span class="cart-badge"><?= (int)$cartCnt ?></span>
            <?php endif; ?>
          </a>
        </li>
                <li class="nav-item">
          <a class="nav-link <?= cp_nav_active('orders.php') ?>" href="<?= rmi_h($base) ?>/customer_portal/orders.php">
            📋 Riwayat Order
                    </a>
                </li>
            </ul>

      <div class="d-flex align-items-center gap-3">
        <div class="cp-user d-none d-lg-block">
          👤 <strong><?= rmi_h($user['full_name'] ?: $user['username']) ?></strong>
        </div>
        <a class="btn btn-sm btn-outline-light" href="<?= rmi_h($base) ?>/customer_portal/logout.php">
          Logout
        </a>
      </div>
        </div>
    </div>
</nav>

<!-- Main -->
<main>
  <div class="container py-4">
    <?php if (!empty($flash)): ?>
      <div class="alert cp-alert alert-<?= rmi_h($flash['type'] ?? 'info') ?> mb-4">
        <?= $flash['msg'] ?? '' ?>
      </div>
    <?php endif; ?>
    <?= $content ?? '' ?>
  </div>
</main>

<!-- Footer -->
<footer class="cp-footer">
  &copy; <?= date('Y') ?> <strong>Rizqullah Mediska Indonesia</strong> &nbsp;·&nbsp;
  We Are Healthy Together &nbsp;·&nbsp;
  <a href="mailto:info@rizqullahmediska.com" style="color:rgba(255,255,255,.5);text-decoration:none">Hubungi Kami</a>
</footer>

<script src="<?= $assetsBase ?>/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
