<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('products.php');
checkCsrf();

$pdo = db();
$productId = (int) ($_POST['product_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) redirect('products.php');

$newSku = $product['sku'] . '-COPY-' . substr((string) (int) round(microtime(true) * 1000), -5);

$pdo->prepare(
    'INSERT INTO products (sku, barcode, name, description, unit, cost_price, sell_price, category_id, brand_id, tags, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,?,0)'
)->execute([
    $newSku, null, $product['name'] . ' (Sao chép)', $product['description'], $product['unit'],
    $product['cost_price'], $product['sell_price'], $product['category_id'], $product['brand_id'], $product['tags'],
]);
$newId = (int) $pdo->lastInsertId();

redirect('product_form.php?id=' . $newId . '&saved=1');
