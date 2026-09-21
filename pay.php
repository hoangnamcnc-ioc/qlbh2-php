<?php
// Trang thanh toan cong khai - KHONG yeu cau dang nhap vi link nay duoc gui qua SDT/Zalo/email
// cho chu cua hang, ho co the chua dang nhap luc bam vao. Bao ve bang order_code ngau nhien kho
// doan (khong phai ID tang dan), giong pattern reset_password.php.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_vnpay.php';

$orderCode = $_GET['order'] ?? '';
$pdo = db();
$payment = null;
if ($orderCode !== '') {
    $stmt = $pdo->prepare('SELECT p.*, t.name AS tenant_name FROM subscription_payments p JOIN tenants t ON t.id = p.tenant_id WHERE p.order_code = ?');
    $stmt->execute([$orderCode]);
    $payment = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Thanh toán - QLBH-CLOUD</title>
<style>
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 16px; }
  .box { width: 100%; max-width: 420px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; }
  h1 { margin: 0 0 4px; font-size: 19px; }
  p.sub { margin: 0 0 20px; color: #64748b; font-size: 14px; }
  .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 14.5px; }
  .row b { color: #0f172a; }
  .amount { font-size: 28px; font-weight: 800; color: #2563eb; text-align: center; margin: 20px 0; }
  button { width: 100%; background: #2563eb; color: #fff; border: none; border-radius: 8px; padding: 12px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 8px; }
  .alert { padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
  .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
  .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
</style>
</head>
<body>
  <div class="box">
    <?php if (!$payment): ?>
      <h1>Không tìm thấy đơn thanh toán</h1>
      <p class="sub">Link không đúng hoặc đã hết hạn. Vui lòng liên hệ KT-SOFT để được cấp lại link, ĐT/Zalo <b>0945289666</b>.</p>
    <?php elseif ($payment['status'] === 'SUCCESS'): ?>
      <h1>✅ Đã thanh toán</h1>
      <div class="alert alert-success">Đơn thanh toán này đã hoàn tất vào lúc <?= e(date('d/m/Y H:i', strtotime($payment['paid_at']))) ?>. Gói dịch vụ của bạn đã được kích hoạt.</div>
    <?php elseif (!vnpayConfigured()): ?>
      <h1>Thanh toán online chưa sẵn sàng</h1>
      <p class="sub">Vui lòng liên hệ trực tiếp KT-SOFT để hoàn tất thanh toán, ĐT/Zalo <b>0945289666</b>.</p>
    <?php else: ?>
      <h1>Xác nhận thanh toán</h1>
      <p class="sub">Gói dịch vụ QLBH-CLOUD cho <b><?= e($payment['tenant_name']) ?></b></p>
      <div class="row"><span>Cửa hàng</span><b><?= e($payment['tenant_name']) ?></b></div>
      <div class="row"><span>Thời hạn</span><b><?= (int) $payment['months'] ?> tháng</b></div>
      <div class="row"><span>Mã đơn</span><b style="font-family:monospace;"><?= e($payment['order_code']) ?></b></div>
      <div class="amount"><?= number_format((float) $payment['amount'], 0, ',', '.') ?>đ</div>
      <form method="post" action="vnpay_create.php">
        <input type="hidden" name="order" value="<?= e($payment['order_code']) ?>">
        <button type="submit">Thanh toán qua VNPay</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
