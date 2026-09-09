<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('products.php');
checkCsrf();

$pdo = db();
$productId = (int) ($_POST['product_id'] ?? 0);

$stmt = $pdo->prepare('SELECT id FROM products WHERE id = ?');
$stmt->execute([$productId]);
if (!$stmt->fetch()) redirect('products.php');

if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $tmpPath = $_FILES['image']['tmp_name'];
    $mime = mime_content_type($tmpPath);

    if (isset($allowed[$mime]) && $_FILES['image']['size'] <= 3 * 1024 * 1024) {
        $ext = $allowed[$mime];
        $filename = 'p' . $productId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dir = __DIR__ . '/uploads/products';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (move_uploaded_file($tmpPath, $dir . '/' . $filename)) {
            $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM product_images WHERE product_id = ?');
            $maxOrder->execute([$productId]);
            $nextOrder = (int) $maxOrder->fetchColumn() + 1;

            $pdo->prepare('INSERT INTO product_images (product_id, filename, sort_order) VALUES (?,?,?)')
                ->execute([$productId, $filename, $nextOrder]);
        }
    }
}

redirect('product_form.php?id=' . $productId);
