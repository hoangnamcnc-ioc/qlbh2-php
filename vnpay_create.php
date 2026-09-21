<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_vnpay.php';

$orderCode = $_POST['order'] ?? '';
$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM subscription_payments WHERE order_code = ? AND status = 'PENDING'");
$stmt->execute([$orderCode]);
$payment = $stmt->fetch();

if (!$payment || !vnpayConfigured()) {
    http_response_code(404);
    exit('Không tìm thấy đơn thanh toán hoặc thanh toán online chưa được cấu hình.');
}

$url = vnpayBuildPaymentUrl($orderCode, (int) $payment['amount'], 'QLBH-CLOUD_' . $orderCode);
redirect($url);
