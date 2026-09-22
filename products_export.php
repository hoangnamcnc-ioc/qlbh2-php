<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$productsStmt = $pdo->prepare('SELECT sku, barcode, name, unit, cost_price, sell_price, is_active FROM products WHERE tenant_id = ? ORDER BY created_at DESC');
$productsStmt->execute([currentTenantId()]);
$products = $productsStmt->fetchAll();
logActivity('EXPORT_PRODUCTS', count($products) . ' sản phẩm');

$rows = [['sku', 'barcode', 'name', 'unit', 'cost_price', 'sell_price', 'is_active']];
foreach ($products as $p) {
    $rows[] = [$p['sku'], $p['barcode'], $p['name'], $p['unit'], $p['cost_price'], $p['sell_price'], $p['is_active']];
}
downloadXlsx($rows, 'san_pham.xlsx');
