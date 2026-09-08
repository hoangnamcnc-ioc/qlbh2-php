<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('products.php');
checkCsrf();

$pdo = db();
$productId = (int) ($_POST['product_id'] ?? 0);
$sku = post('sku');
$name = post('name');
$costPrice = postFloat('cost_price');
$sellPrice = postFloat('sell_price');
$initialQty = postInt('initial_qty');

$product = $pdo->prepare('SELECT id FROM products WHERE id = ?');
$product->execute([$productId]);
if (!$product->fetch()) redirect('products.php');

if ($sku === '' || $name === '') {
    redirect('product_form.php?id=' . $productId . '&variant_error=' . urlencode('Vui lòng nhập SKU và tên biến thể'));
}

$check = $pdo->prepare('SELECT id FROM product_variants WHERE sku = ?');
$check->execute([$sku]);
if ($check->fetch()) {
    redirect('product_form.php?id=' . $productId . '&variant_error=' . urlencode('Mã SKU biến thể đã tồn tại'));
}

$pdo->prepare(
    'INSERT INTO product_variants (product_id, sku, name, cost_price, sell_price) VALUES (?,?,?,?,?)'
)->execute([$productId, $sku, $name, $costPrice, $sellPrice]);
$variantId = (int) $pdo->lastInsertId();

$branch = $pdo->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetch();
if ($branch) {
    $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
        ->execute([$branch['id'], $productId, $variantId, $initialQty]);
}

redirect('product_form.php?id=' . $productId);
