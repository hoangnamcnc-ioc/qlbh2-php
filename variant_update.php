<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('products.php');
checkCsrf();

$pdo = db();
$variantId = (int) ($_POST['variant_id'] ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);
$barcode = post('barcode') ?: null;

$pdo->prepare('UPDATE product_variants SET barcode = ? WHERE id = ? AND product_id = ?')
    ->execute([$barcode, $variantId, $productId]);

redirect('product_form.php?id=' . $productId);
