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

$stmt = $pdo->prepare(
    'SELECT p.id, p.sku, p.name, p.sell_price, COALESCE(i.quantity, 0) AS qty
     FROM products p
     LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ?
     WHERE p.is_active = 1 AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
     LIMIT 20'
);
$stmt->execute([$branchId, $like, $like, $like]);
echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
