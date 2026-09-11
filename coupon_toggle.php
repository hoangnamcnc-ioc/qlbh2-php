<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('coupons.php');
checkCsrf();

$pdo = db();
$id = (int) ($_POST['id'] ?? 0);
$pdo->prepare('UPDATE coupons SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
logActivity('COUPON_TOGGLE', "id=$id");

redirect('coupons.php');
