<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$branchId = (int) ($_GET['branch_id'] ?? 0);
if ($q === '' || !$branchId) { echo '[]'; exit; }

$like = '%' . $q . '%';
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT p.id, NULL AS variant_id, p.sku, p.name, COALESCE(i.quantity,0) AS qty
     FROM products p
     LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
     WHERE (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
       AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1)
     LIMIT 15'
);
$stmt->execute([$branchId, $like, $like, $like]);
$products = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT p.id, v.id AS variant_id, v.sku, CONCAT(p.name, ' - ', v.name) AS name, COALESCE(i.quantity,0) AS qty
     FROM product_variants v
     JOIN products p ON p.id = v.product_id
     LEFT JOIN inventory i ON i.variant_id = v.id AND i.branch_id = ?
     WHERE v.is_active = 1 AND (p.name LIKE ? OR v.name LIKE ? OR v.sku LIKE ?)
     LIMIT 15"
);
$stmt->execute([$branchId, $like, $like, $like]);
$variants = $stmt->fetchAll();

echo json_encode(array_merge($products, $variants), JSON_UNESCAPED_UNICODE);
