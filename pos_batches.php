<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$user = requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$branchId = effectiveBranchId($user);
$productIds = array_filter(array_map('intval', explode(',', $_GET['product_ids'] ?? '')));

if (!$productIds) {
    echo '[]';
    exit;
}

$placeholders = implode(',', array_fill(0, count($productIds), '?'));
$stmt = $pdo->prepare(
    "SELECT b.product_id, p.name AS product_name, b.lot_number, b.expiry_date, b.quantity
     FROM product_batches b JOIN products p ON p.id = b.product_id
     WHERE b.branch_id = ? AND b.product_id IN ($placeholders) AND b.quantity > 0
     ORDER BY b.product_id, (b.expiry_date IS NULL), b.expiry_date"
);
$stmt->execute(array_merge([$branchId], $productIds));
echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
