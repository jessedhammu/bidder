<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/audit.php';
$user = current_user();
logout();
if ($user) audit_log('LOGOUT','auth',null,null,['username'=>$user['username']]);
header('Location: ' . BASE_URL);
exit;
