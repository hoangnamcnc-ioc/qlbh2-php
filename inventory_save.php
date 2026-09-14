<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('inventory.php');
}
checkCsrf();

$productId = postInt('product_id');
$variantId = postInt('variant_id') ?: null;
$branchId = postInt('branch_id');
$quantity = postQty('quantity');
$minStock = postInt('min_stock');
$maxStockRaw = post('max_stock');
$maxStock = $maxStockRaw === '' ? null : max(0, (int) $maxStockRaw);
$storageLocation = post('storage_location') ?: null;
$redirectTo = post('redirect');
// Chỉ cho phép chuyển hướng nội bộ (đường dẫn tương đối trong site), chặn open-redirect.
if ($redirectTo === '' || !preg_match('/^[a-zA-Z0-9_.\\-]+\\.php(\\?[a-zA-Z0-9_=&%.\\-]*)?$/', $redirectTo)) {
    $redirectTo = 'inventory.php';
}

if ($productId && $branchId) {
    $pdo = db();
    $tenantId = currentTenantId();

    // Xac nhan chi nhanh + san pham (va bien the neu co) thuc su thuoc tenant hien tai truoc
    // khi ghi ton kho - tranh ghi nham/co y ghi de ton kho cua tenant khac qua request thu cong.
    $ownBranch = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ?');
    $ownBranch->execute([$branchId, $tenantId]);
    $ownProduct = $pdo->prepare('SELECT id FROM products WHERE id = ? AND tenant_id = ?');
    $ownProduct->execute([$productId, $tenantId]);
    $ownVariantOk = true;
    if ($variantId) {
        $ownVariant = $pdo->prepare('SELECT id FROM product_variants WHERE id = ? AND tenant_id = ?');
        $ownVariant->execute([$variantId, $tenantId]);
        $ownVariantOk = (bool) $ownVariant->fetch();
    }
    if (!$ownBranch->fetch() || !$ownProduct->fetch() || !$ownVariantOk) {
        redirect($redirectTo);
    }

    if ($variantId) {
        $stmt = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND variant_id = ?');
        $stmt->execute([$branchId, $variantId]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
        $stmt->execute([$branchId, $productId]);
    }
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare('UPDATE inventory SET quantity = ?, min_stock = ?, max_stock = ?, storage_location = ? WHERE id = ?')
            ->execute([$quantity, $minStock, $maxStock, $storageLocation, $existing['id']]);
    } else {
        $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity, min_stock, max_stock, storage_location) VALUES (?,?,?,?,?,?,?)')
            ->execute([$branchId, $productId, $variantId, $quantity, $minStock, $maxStock, $storageLocation]);
    }
}

redirect($redirectTo);
