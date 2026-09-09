<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();
$fields = ['bank_code', 'bank_account', 'bank_account_name'];

$bankOptions = [
    'VCB' => 'Vietcombank', 'TCB' => 'Techcombank', 'MB' => 'MB Bank', 'ACB' => 'ACB',
    'VPB' => 'VPBank', 'BIDV' => 'BIDV', 'ICB' => 'VietinBank', 'STB' => 'Sacombank',
    'TPB' => 'TPBank', 'VIB' => 'VIB', 'AGRIBANK' => 'Agribank', 'MSB' => 'MSB',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    foreach ($fields as $key) {
        $value = post($key);
        $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $value]);
    }
    logActivity('PAYMENT_SETTINGS_UPDATE');
    redirect('payment_settings.php?saved=1');
}

$values = [];
foreach ($fields as $key) {
    $values[$key] = getSetting($key, '');
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Thanh toán</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Khai báo tài khoản ngân hàng để hiển thị mã QR chuyển khoản (chuẩn VietQR) ngay trong POS khi
  khách chọn thanh toán bằng QR — <b>không kết nối cổng thanh toán thật</b> (VNPay/MoMo...), hệ
  thống chỉ tạo ảnh mã QR chuyển khoản đúng số tiền để khách quét bằng app ngân hàng, nhân viên vẫn
  cần tự xác nhận đã nhận tiền trước khi hoàn tất đơn.
</p>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Đã lưu cấu hình thanh toán</div><?php endif; ?>

<div class="card" style="max-width:520px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field">
      <label>Ngân hàng</label>
      <select class="input" name="bank_code">
        <option value="">— Chọn ngân hàng —</option>
        <?php foreach ($bankOptions as $code => $label): ?>
          <option value="<?= e($code) ?>" <?= $values['bank_code'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Số tài khoản</label><input class="input" name="bank_account" value="<?= e($values['bank_account']) ?>"></div>
    <div class="field"><label>Tên chủ tài khoản</label><input class="input" name="bank_account_name" value="<?= e($values['bank_account_name']) ?>" placeholder="Không dấu, vd: NGUYEN VAN A"></div>
    <button type="submit" class="btn">Lưu cấu hình</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
