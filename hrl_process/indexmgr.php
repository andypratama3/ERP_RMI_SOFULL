<?php
// /hrl_process/index.php
require_once __DIR__ . '/_inc/bootstrap.php';
require_login();

header('Location: tower.php');
exit;
