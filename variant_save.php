<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('products.php');
checkCsrf();

$pdo = db();
$tenantId = currentTenantId();
$productId = (int) ($_POST['product_id'] ?? 0);
$sku = post('sku');
$barcode = post('barcode') ?: null;
$name = post('name');
$costPrice = postFloat('cost_price');
$sellPrice = postFloat('sell_price');
$initialQty = postQty('initial_qty');

$product = $pdo->prepare('SELECT id FROM products WHERE id = ? AND tenant_id = ?');
$product->execute([$productId, $tenantId]);
if (!$product->fetch()) redirect('products.php');

if ($sku === '' || $name === '') {
    redirect('product_form.php?id=' . $productId . '&variant_error=' . urlencode('Vui lòng nhập SKU và tên biến thể'));
}

$check = $pdo->prepare('SELECT id FROM product_variants WHERE sku = ? AND tenant_id = ?');
$check->execute([$sku, $tenantId]);
if ($check->fetch()) {
    redirect('product_form.php?id=' . $productId . '&variant_error=' . urlencode('Mã SKU biến thể đã tồn tại'));
}

$pdo->prepare(
    'INSERT INTO product_variants (product_id, sku, barcode, name, cost_price, sell_price, tenant_id) VALUES (?,?,?,?,?,?,?)'
)->execute([$productId, $sku, $barcode, $name, $costPrice, $sellPrice, $tenantId]);
$variantId = (int) $pdo->lastInsertId();

$targetBranchId = $currentUser['branch_id'];
if (!$targetBranchId) {
    $branchStmt = $pdo->prepare('SELECT id FROM branches WHERE tenant_id = ? ORDER BY id LIMIT 1');
    $branchStmt->execute([$tenantId]);
    $branch = $branchStmt->fetch();
    $targetBranchId = $branch['id'] ?? null;
}
if ($targetBranchId) {
    $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
        ->execute([$targetBranchId, $productId, $variantId, $initialQty]);
}

redirect('product_form.php?id=' . $productId);
