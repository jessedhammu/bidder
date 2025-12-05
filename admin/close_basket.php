<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';
require_once '/var/www/src/audit.php';
require_role('admin');
$pdo = DB::pdo();
$basket_id = isset($_GET['basket_id']) ? (int)$_GET['basket_id'] : 0;
$action = $_GET['action'] ?? 'close';
if (!$basket_id) { header('Location: dashboard.php'); exit; }
$stmt = $pdo->prepare('SELECT * FROM baskets WHERE id=?'); $stmt->execute([$basket_id]); $basket = $stmt->fetch();
if (!$basket) { header('Location: dashboard.php'); exit; }
if ($action === 'open') $status = 'open'; else $status = 'closed';
$stmt = $pdo->prepare('UPDATE baskets SET status = ? WHERE id = ?'); $stmt->execute([$status, $basket_id]);
audit_log($status === 'closed' ? 'CLOSE_BASKET' : 'OPEN_BASKET','basket',$basket_id,['previous'=>$basket['status']],['new'=>$status]);
header('Location: dashboard.php'); exit;
