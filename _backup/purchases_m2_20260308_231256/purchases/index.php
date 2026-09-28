<?php
require_once __DIR__ . '/../master/auth.php';
require_login();
header('Location: purchases_dashboard.php');
exit;
