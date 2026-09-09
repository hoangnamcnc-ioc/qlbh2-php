<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('products.php');
}
checkCsrf();

$pdo = db();
$comboItemId = (int) ($_POST['combo_item_id'] ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);

if ($comboItemId) {
    $pdo->prepare('DELETE FROM combo_items WHERE id = ? AND combo_product_id = ?')->execute([$comboItemId, $productId]);
}

redirect('product_form.php?id=' . $productId);
