<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$promotionsStmt = $pdo->prepare(
    "SELECT name, min_order_amount, discount_percent FROM promotions
     WHERE is_active = 1 AND tenant_id = ?
       AND (start_date IS NULL OR start_date <= CURDATE())
       AND (end_date IS NULL OR end_date >= CURDATE())
     ORDER BY discount_percent DESC"
);
$promotionsStmt->execute([currentTenantId()]);
$promotions = $promotionsStmt->fetchAll();

echo json_encode($promotions, JSON_UNESCAPED_UNICODE);
