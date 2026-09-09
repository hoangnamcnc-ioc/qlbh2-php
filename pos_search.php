<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$user = requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo '[]';
    exit;
}

$branchId = (int) ($user['branch_id'] ?? 0);
$priceListId = (int) ($_GET['price_list_id'] ?? 0) ?: null;
$pdo = db();
$like = '%' . $q . '%';

// Sản phẩm không có biến thể (bán trực tiếp theo product_id)
$stmt = $pdo->prepare(
    'SELECT p.id, NULL AS variant_id, p.sku, p.name, p.sell_price, p.product_type,
            COALESCE(i.quantity, 0) AS qty
     FROM products p
     LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
     WHERE p.is_active = 1 AND p.product_type != \'COMBO\' AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
       AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1)
     LIMIT 15'
);
$stmt->execute([$branchId, $like, $like, $like]);
$products = $stmt->fetchAll();

// Combo (bán như 1 dòng, không kiểm tồn kho riêng)
$stmt = $pdo->prepare(
    "SELECT p.id, NULL AS variant_id, p.sku, p.name, p.sell_price, p.product_type, 999 AS qty
     FROM products p
     WHERE p.is_active = 1 AND p.product_type = 'COMBO' AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
     LIMIT 15"
);
$stmt->execute([$like, $like, $like]);
$combos = $stmt->fetchAll();

// Biến thể sản phẩm (bán theo variant_id)
$stmt = $pdo->prepare(
    "SELECT p.id, v.id AS variant_id, v.sku, CONCAT(p.name, ' - ', v.name) AS name, v.sell_price, 'PRODUCT' AS product_type,
            COALESCE(i.quantity, 0) AS qty
     FROM product_variants v
     JOIN products p ON p.id = v.product_id
     LEFT JOIN inventory i ON i.variant_id = v.id AND i.branch_id = ?
     WHERE v.is_active = 1 AND p.is_active = 1
       AND (p.name LIKE ? OR v.name LIKE ? OR v.sku LIKE ?)
     LIMIT 15"
);
$stmt->execute([$branchId, $like, $like, $like]);
$variants = $stmt->fetchAll();

$all = array_merge($products, $combos, $variants);

if ($priceListId) {
    $stmt = $pdo->prepare('SELECT product_id, variant_id, price FROM product_prices WHERE price_list_id = ?');
    $stmt->execute([$priceListId]);
    $overrides = [];
    foreach ($stmt->fetchAll() as $row) {
        $overrides[$row['product_id'] . ':' . ($row['variant_id'] ?? '')] = $row['price'];
    }
    foreach ($all as &$item) {
        $key = $item['id'] . ':' . ($item['variant_id'] ?? '');
        if (isset($overrides[$key])) {
            $item['sell_price'] = $overrides[$key];
            $item['price_list_applied'] = true;
        }
    }
    unset($item);
}

foreach ($all as &$item) {
    unset($item['product_type']);
}
unset($item);

echo json_encode($all, JSON_UNESCAPED_UNICODE);
