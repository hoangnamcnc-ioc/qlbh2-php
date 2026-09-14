<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('products.php');
checkCsrf();

$pdo = db();
$imageId = (int) ($_POST['image_id'] ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT pi.* FROM product_images pi JOIN products p ON p.id = pi.product_id
     WHERE pi.id = ? AND pi.product_id = ? AND p.tenant_id = ?'
);
$stmt->execute([$imageId, $productId, currentTenantId()]);
$image = $stmt->fetch();

if ($image) {
    $path = __DIR__ . '/uploads/products/' . $image['filename'];
    if (is_file($path)) {
        unlink($path);
    }
    $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
}

redirect('product_form.php?id=' . $productId);
