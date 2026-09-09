<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$products = $pdo->query('SELECT sku, barcode, name, unit, cost_price, sell_price, is_active FROM products ORDER BY created_at DESC')->fetchAll();
logActivity('EXPORT_PRODUCTS', count($products) . ' sản phẩm');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="san_pham.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM để Excel đọc đúng UTF-8
fputcsv($out, ['sku', 'barcode', 'name', 'unit', 'cost_price', 'sell_price', 'is_active']);
foreach ($products as $p) {
    fputcsv($out, [$p['sku'], $p['barcode'], $p['name'], $p['unit'], $p['cost_price'], $p['sell_price'], $p['is_active']]);
}
fclose($out);
