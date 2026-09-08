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
$pdo = db();
$like = '%' . $q . '%';

// Sản phẩm không có biến thể (bán trực tiếp theo product_id)
$stmt = $pdo->prepare(
    'SELECT p.id, NULL AS variant_id, p.sku, p.name, p.sell_price,
            COALESCE(i.quantity, 0) AS qty
     FROM products p
     LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
     WHERE p.is_active = 1 AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
       AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1)
     LIMIT 15'
);
$stmt->execute([$branchId, $like, $like, $like]);
$products = $stmt->fetchAll();

// Biến thể sản phẩm (bán theo variant_id)
$stmt = $pdo->prepare(
    "SELECT p.id, v.id AS variant_id, v.sku, CONCAT(p.name, ' - ', v.name) AS name, v.sell_price,
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

echo json_encode(array_merge($products, $variants), JSON_UNESCAPED_UNICODE);
