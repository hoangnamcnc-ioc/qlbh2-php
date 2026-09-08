<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$code = strtoupper(trim($_GET['code'] ?? ''));
$subTotal = (float) ($_GET['sub_total'] ?? 0);

if ($code === '') {
    echo json_encode(['error' => 'Vui lòng nhập mã giảm giá']);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM coupons WHERE code = ?');
$stmt->execute([$code]);
$coupon = $stmt->fetch();

if (!$coupon || !$coupon['is_active']) {
    echo json_encode(['error' => 'Mã giảm giá không tồn tại hoặc đã bị tắt']);
    exit;
}
if ($coupon['start_date'] && strtotime($coupon['start_date']) > time()) {
    echo json_encode(['error' => 'Mã giảm giá chưa đến ngày sử dụng']);
    exit;
}
if ($coupon['end_date'] && strtotime($coupon['end_date'] . ' 23:59:59') < time()) {
    echo json_encode(['error' => 'Mã giảm giá đã hết hạn']);
    exit;
}
if ($coupon['max_uses'] && (int) $coupon['used_count'] >= (int) $coupon['max_uses']) {
    echo json_encode(['error' => 'Mã giảm giá đã hết lượt sử dụng']);
    exit;
}
if ($subTotal < (float) $coupon['min_order_amount']) {
    echo json_encode(['error' => 'Đơn hàng chưa đạt giá trị tối thiểu ' . number_format((float) $coupon['min_order_amount'], 0, ',', '.')]);
    exit;
}

$discount = $coupon['discount_type'] === 'PERCENT'
    ? $subTotal * (float) $coupon['discount_value'] / 100
    : (float) $coupon['discount_value'];
$discount = min($discount, $subTotal);

echo json_encode(['ok' => true, 'discount' => $discount, 'code' => $coupon['code']]);
