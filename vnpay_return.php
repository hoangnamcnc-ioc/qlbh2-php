<?php
// VNPay redirect trinh duyet ve day sau khi khach thanh toan xong. TUYET DOI khong tin bat ky
// gia tri nao trong $_GET (vnp_ResponseCode...) truoc khi xac thuc chu ky - neu khong ai cung co
// the tu go URL nay voi response code 00 de gia mao "da thanh toan".
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_mail.php';
require_once __DIR__ . '/inc_vnpay.php';

$params = $_GET;
$validSignature = vnpayConfigured() && vnpayVerifySignature($params);
$orderCode = $params['vnp_TxnRef'] ?? '';
$responseCode = $params['vnp_ResponseCode'] ?? '';

$pdo = db();
$payment = null;
$message = '';
$success = false;

if ($orderCode !== '') {
    $stmt = $pdo->prepare('SELECT p.*, t.name AS tenant_name, t.owner_email FROM subscription_payments p JOIN tenants t ON t.id = p.tenant_id WHERE p.order_code = ?');
    $stmt->execute([$orderCode]);
    $payment = $stmt->fetch();
}

if (!$validSignature) {
    $message = 'Chữ ký không hợp lệ - giao dịch không được chấp nhận.';
} elseif (!$payment) {
    $message = 'Không tìm thấy đơn thanh toán.';
} elseif ($payment['status'] === 'SUCCESS') {
    // Da xu ly roi (vd nguoi dung bam Back/F5 tai trang return) - khong cong don lai thoi han.
    $success = true;
    $message = 'Đơn thanh toán này đã được xử lý trước đó.';
} elseif ($responseCode !== '00') {
    $pdo->prepare("UPDATE subscription_payments SET status = 'FAILED' WHERE id = ?")->execute([$payment['id']]);
    $message = 'Giao dịch không thành công hoặc đã bị hủy (mã lỗi VNPay: ' . e($responseCode) . ').';
} else {
    $txnNo = $params['vnp_TransactionNo'] ?? null;
    $pdo->prepare("UPDATE subscription_payments SET status = 'SUCCESS', vnp_transaction_no = ?, paid_at = NOW() WHERE id = ?")
        ->execute([$txnNo, $payment['id']]);

    $pdo->prepare(
        "UPDATE tenants SET plan = 'PAID',
            paid_until = DATE_ADD(GREATEST(COALESCE(paid_until, NOW()), NOW()), INTERVAL ? MONTH),
            trial_ends_at = NULL
         WHERE id = ?"
    )->execute([(int) $payment['months'], (int) $payment['tenant_id']]);

    logActivity('PAYMENT_SUCCESS', 'order=' . $orderCode . ' amount=' . $payment['amount']);

    $subject = 'QLBH-CLOUD - Thanh toan thanh cong';
    $body = "Cam on ban! Da nhan thanh toan {$payment['amount']}d cho cua hang {$payment['tenant_name']}.\n"
        . "Goi dich vu da duoc kich hoat them {$payment['months']} thang.\n";
    if (!empty($payment['owner_email'])) {
        sendMail($payment['owner_email'], $subject, $body);
    }
    sendMail('hoangnamcnc@gmail.com', $subject, $body);

    $success = true;
    $message = 'Thanh toán thành công! Gói dịch vụ của bạn đã được kích hoạt.';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Kết quả thanh toán - QLBH-CLOUD</title>
<style>
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 16px; }
  .box { width: 100%; max-width: 420px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; text-align: center; }
  h1 { margin: 0 0 12px; font-size: 20px; }
  .icon { font-size: 44px; margin-bottom: 8px; }
  p { color: #475569; font-size: 14.5px; line-height: 1.6; }
  a.btn { display: inline-block; margin-top: 16px; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 14px; }
</style>
</head>
<body>
  <div class="box">
    <div class="icon"><?= $success ? '✅' : '❌' ?></div>
    <h1><?= $success ? 'Thành công' : 'Không thành công' ?></h1>
    <p><?= e($message) ?></p>
    <a href="login.php" class="btn">Vào QLBH-CLOUD</a>
  </div>
</body>
</html>
