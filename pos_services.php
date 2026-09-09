<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$services = $pdo->query(
    "SELECT id, sku, name, sell_price FROM products WHERE is_active = 1 AND product_type = 'SERVICE' ORDER BY name"
)->fetchAll();

echo json_encode($services, JSON_UNESCAPED_UNICODE);
