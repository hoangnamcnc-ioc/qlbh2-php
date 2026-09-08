<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('products.php');
checkCsrf();

$pdo = db();
$variantId = (int) ($_POST['variant_id'] ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);

$stmt = $pdo->prepare('SELECT id FROM order_items WHERE variant_id = ? LIMIT 1');
$stmt->execute([$variantId]);
if (!$stmt->fetch()) {
    // Chỉ cho xóa nếu biến thể chưa từng được bán (tránh mất dữ liệu lịch sử đơn hàng)
    $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$variantId]);
} else {
    $pdo->prepare('UPDATE product_variants SET is_active = 0 WHERE id = ?')->execute([$variantId]);
}

redirect('product_form.php?id=' . $productId);
