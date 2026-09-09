<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();
$fields = ['require_customer_phone', 'auto_print_receipt', 'round_total'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    foreach ($fields as $key) {
        $value = isset($_POST[$key]) ? '1' : '0';
        $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $value]);
    }
    logActivity('SALES_SETTINGS_UPDATE');
    redirect('sales_settings.php?saved=1');
}

$values = [];
foreach ($fields as $key) {
    $values[$key] = getSetting($key, '0');
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Cấu hình bán hàng</h1>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Đã lưu cấu hình bán hàng</div><?php endif; ?>

<div class="card" style="max-width:560px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field">
      <label><input type="checkbox" name="require_customer_phone" <?= $values['require_customer_phone'] === '1' ? 'checked' : '' ?>> Bắt buộc nhập SĐT khách hàng trước khi thanh toán trong POS</label>
    </div>
    <div class="field">
      <label><input type="checkbox" name="auto_print_receipt" <?= $values['auto_print_receipt'] === '1' ? 'checked' : '' ?>> Tự động mở hóa đơn in ngay sau khi thanh toán thành công</label>
    </div>
    <div class="field">
      <label><input type="checkbox" name="round_total" <?= $values['round_total'] === '1' ? 'checked' : '' ?>> Làm tròn tổng tiền đơn hàng đến hàng nghìn đồng</label>
    </div>
    <button type="submit" class="btn">Lưu cấu hình</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
