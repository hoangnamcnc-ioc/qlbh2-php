<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo '[]'; exit; }

$like = '%' . $q . '%';
$stmt = db()->prepare(
    'SELECT id, sku, name, cost_price FROM products
     WHERE name LIKE ? OR sku LIKE ? OR barcode LIKE ? LIMIT 20'
);
$stmt->execute([$like, $like, $like]);
echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
