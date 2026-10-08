<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();
$checkboxFields = ['require_customer_phone', 'auto_print_receipt', 'round_total', 'suggest_cash_amounts'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    foreach ($checkboxFields as $key) {
        setSetting($key, isset($_POST[$key]) ? '1' : '0');
    }
    $discountUnit = ($_POST['default_discount_unit'] ?? '') === 'PERCENT' ? 'PERCENT' : 'AMOUNT';
    setSetting('default_discount_unit', $discountUnit);
    setSetting('cashier_return_limit', (string) max(0, (int) round((float) ($_POST['cashier_return_limit'] ?? 0))));
    logActivity('SALES_SETTINGS_UPDATE');
    redirect('sales_settings.php?saved=1');
}

$values = [];
foreach ($checkboxFields as $key) {
    $values[$key] = getSetting($key, '0');
}
$values['default_discount_unit'] = getSetting('default_discount_unit', 'AMOUNT');
$values['cashier_return_limit'] = getSetting('cashier_return_limit', '500000');

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
    <div class="field">
      <label><input type="checkbox" name="suggest_cash_amounts" <?= $values['suggest_cash_amounts'] === '1' ? 'checked' : '' ?>> Gợi ý nhanh các mức tiền khách đưa (tròn chục/trăm nghìn) trong POS</label>
    </div>
    <div class="field">
      <label>Đơn vị chiết khấu mặc định trong POS</label>
      <select class="input" name="default_discount_unit" style="max-width:160px;">
        <option value="AMOUNT" <?= $values['default_discount_unit'] === 'AMOUNT' ? 'selected' : '' ?>>VNĐ</option>
        <option value="PERCENT" <?= $values['default_discount_unit'] === 'PERCENT' ? 'selected' : '' ?>>%</option>
      </select>
    </div>
    <div class="field">
      <label>Hạn mức trả hàng của thu ngân (VNĐ mỗi phiếu)</label>
      <input class="input" type="number" min="0" step="1000" name="cashier_return_limit" value="<?= e($values['cashier_return_limit']) ?>" style="max-width:200px;">
      <div class="muted" style="font-size:12px;margin-top:4px;">Phiếu trả hàng có số tiền hoàn lớn hơn mức này phải do Quản lý/Quản trị tạo. Nhập 0 để không giới hạn.</div>
    </div>
    <button type="submit" class="btn">Lưu cấu hình</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
