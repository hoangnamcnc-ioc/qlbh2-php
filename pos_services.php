<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$servicesStmt = $pdo->prepare(
    "SELECT id, sku, name, sell_price FROM products WHERE is_active = 1 AND product_type = 'SERVICE' AND tenant_id = ? ORDER BY name"
);
$servicesStmt->execute([currentTenantId()]);
$services = $servicesStmt->fetchAll();

echo json_encode($services, JSON_UNESCAPED_UNICODE);
