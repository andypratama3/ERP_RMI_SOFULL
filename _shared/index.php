<?php
require_once __DIR__ . '/../master/auth.php';
require_login();
header('Location: reg_alkes_control_tower.php');
exit;
