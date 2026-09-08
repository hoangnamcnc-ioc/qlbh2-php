<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo '[]'; exit; }

$like = '%' . $q . '%';
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT id, NULL AS variant_id, sku, name, cost_price FROM products
     WHERE (name LIKE ? OR sku LIKE ?)
       AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = products.id AND v.is_active = 1)
     LIMIT 15'
);
$stmt->execute([$like, $like]);
$products = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT p.id, v.id AS variant_id, v.sku, CONCAT(p.name, ' - ', v.name) AS name, v.cost_price
     FROM product_variants v JOIN products p ON p.id = v.product_id
     WHERE v.is_active = 1 AND (p.name LIKE ? OR v.name LIKE ? OR v.sku LIKE ?)
     LIMIT 15"
);
$stmt->execute([$like, $like, $like]);
$variants = $stmt->fetchAll();

echo json_encode(array_merge($products, $variants), JSON_UNESCAPED_UNICODE);
