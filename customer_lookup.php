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

$tenantId = currentTenantId();
$stmt = $pdo->prepare(
    'SELECT c.id, c.name, c.group_id, c.loyalty_points, c.discount_percent, c.debt, g.price_list_id, g.name AS group_name
     FROM customers c LEFT JOIN customer_groups g ON g.id = c.group_id
     WHERE c.phone = ? AND c.tenant_id = ?'
);
$stmt->execute([$phone, $tenantId]);
$customer = $stmt->fetch();

if (!$customer) {
    echo json_encode(['found' => false]);
    exit;
}

$spendStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE customer_id = ? AND status != 'CANCELLED'");
$spendStmt->execute([$customer['id']]);
$totalSpend = (float) $spendStmt->fetchColumn();

$tierStmt = $pdo->prepare(
    'SELECT discount_percent FROM customer_tiers WHERE is_active = 1 AND min_spend <= ? AND tenant_id = ? ORDER BY min_spend DESC LIMIT 1'
);
$tierStmt->execute([$totalSpend, $tenantId]);
$tierDiscountPercent = (float) ($tierStmt->fetchColumn() ?: 0);

$loyaltyDiscountPercent = max((float) $customer['discount_percent'], $tierDiscountPercent);

// Khach quen: so don, lan mua cuoi, mon hay mua (180 ngay), mon lan truoc (de them nhanh vao don)
$okStatus = "o.status NOT IN ('CANCELLED','DRAFT')";
$stmt = $pdo->prepare("SELECT COUNT(*), MAX(o.created_at) FROM orders o WHERE o.customer_id = ? AND $okStatus");
$stmt->execute([$customer['id']]);
[$soDon, $lanCuoi] = $stmt->fetch(PDO::FETCH_NUM);
$stmt = $pdo->prepare(
    "SELECT p.name FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
     WHERE o.customer_id = ? AND $okStatus AND o.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
     GROUP BY p.id, p.name ORDER BY SUM(oi.quantity) DESC LIMIT 3"
);
$stmt->execute([$customer['id']]);
$hayMua = $stmt->fetchAll(PDO::FETCH_COLUMN);
$lanTruoc = [];
$stmt = $pdo->prepare("SELECT o.id FROM orders o WHERE o.customer_id = ? AND $okStatus ORDER BY o.created_at DESC LIMIT 1");
$stmt->execute([$customer['id']]);
$lastOrderId = (int) $stmt->fetchColumn();
if ($lastOrderId && !$customer['price_list_id']) {
    // Chi them nhanh san pham thuong (khong bien the) va chi khi khach khong dung bang gia rieng - de gia khong sai
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.sku, p.sell_price, oi.quantity FROM order_items oi JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ? AND oi.variant_id IS NULL AND p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT' LIMIT 8"
    );
    $stmt->execute([$lastOrderId, $tenantId]);
    foreach ($stmt->fetchAll() as $r) {
        $lanTruoc[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'sku' => $r['sku'], 'price' => (float) $r['sell_price'], 'qty' => (float) $r['quantity']];
    }
}

echo json_encode([
    'found' => true,
    'no' => (float) $customer['debt'],
    'so_don' => (int) $soDon,
    'lan_cuoi' => $lanCuoi ? date('d/m/Y', strtotime($lanCuoi)) : null,
    'hay_mua' => $hayMua,
    'lan_truoc' => $lanTruoc,
    'id' => (int) $customer['id'],
    'name' => $customer['name'],
    'group_name' => $customer['group_name'],
    'price_list_id' => $customer['price_list_id'] ? (int) $customer['price_list_id'] : null,
    'loyalty_points' => (int) $customer['loyalty_points'],
    'loyalty_discount_percent' => $loyaltyDiscountPercent,
], JSON_UNESCAPED_UNICODE);
