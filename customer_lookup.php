<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$phone = trim($_GET['phone'] ?? '');
if ($phone === '') {
    echo json_encode(['found' => false]);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT c.id, c.name, c.group_id, c.loyalty_points, c.discount_percent, g.price_list_id, g.name AS group_name
     FROM customers c LEFT JOIN customer_groups g ON g.id = c.group_id
     WHERE c.phone = ?'
);
$stmt->execute([$phone]);
$customer = $stmt->fetch();

if (!$customer) {
    echo json_encode(['found' => false]);
    exit;
}

$spendStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE customer_id = ? AND status != 'CANCELLED'");
$spendStmt->execute([$customer['id']]);
$totalSpend = (float) $spendStmt->fetchColumn();

$tierStmt = $pdo->prepare(
    'SELECT discount_percent FROM customer_tiers WHERE is_active = 1 AND min_spend <= ? ORDER BY min_spend DESC LIMIT 1'
);
$tierStmt->execute([$totalSpend]);
$tierDiscountPercent = (float) ($tierStmt->fetchColumn() ?: 0);

$loyaltyDiscountPercent = max((float) $customer['discount_percent'], $tierDiscountPercent);

echo json_encode([
    'found' => true,
    'id' => (int) $customer['id'],
    'name' => $customer['name'],
    'group_name' => $customer['group_name'],
    'price_list_id' => $customer['price_list_id'] ? (int) $customer['price_list_id'] : null,
    'loyalty_points' => (int) $customer['loyalty_points'],
    'loyalty_discount_percent' => $loyaltyDiscountPercent,
], JSON_UNESCAPED_UNICODE);
