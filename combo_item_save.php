<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('products.php');
}
checkCsrf();

$pdo = db();
$productId = (int) ($_POST['product_id'] ?? 0);
$componentId = (int) ($_POST['component_product_id'] ?? 0);
$quantity = max(1, (int) ($_POST['quantity'] ?? 1));

if ($productId && $componentId && $productId !== $componentId) {
    $check = $pdo->prepare('SELECT id, quantity FROM combo_items WHERE combo_product_id = ? AND component_product_id = ?');
    $check->execute([$productId, $componentId]);
    $existing = $check->fetch();
    if ($existing) {
        $pdo->prepare('UPDATE combo_items SET quantity = ? WHERE id = ?')->execute([$quantity, $existing['id']]);
    } else {
        $pdo->prepare('INSERT INTO combo_items (combo_product_id, component_product_id, quantity) VALUES (?,?,?)')
            ->execute([$productId, $componentId, $quantity]);
    }
}

redirect('product_form.php?id=' . $productId);
