<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('inventory.php');
}
checkCsrf();

$productId = postInt('product_id');
$variantId = postInt('variant_id') ?: null;
$branchId = postInt('branch_id');
$quantity = postInt('quantity');
$minStock = postInt('min_stock');
$redirectTo = post('redirect');
// Chỉ cho phép chuyển hướng nội bộ (đường dẫn tương đối trong site), chặn open-redirect.
if ($redirectTo === '' || !preg_match('/^[a-zA-Z0-9_.\\-]+\\.php(\\?[a-zA-Z0-9_=&%.\\-]*)?$/', $redirectTo)) {
    $redirectTo = 'inventory.php';
}

if ($productId && $branchId) {
    $pdo = db();

    if ($variantId) {
        $stmt = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND variant_id = ?');
        $stmt->execute([$branchId, $variantId]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
        $stmt->execute([$branchId, $productId]);
    }
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare('UPDATE inventory SET quantity = ?, min_stock = ? WHERE id = ?')
            ->execute([$quantity, $minStock, $existing['id']]);
    } else {
        $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity, min_stock) VALUES (?,?,?,?,?)')
            ->execute([$branchId, $productId, $variantId, $quantity, $minStock]);
    }
}

redirect($redirectTo);
