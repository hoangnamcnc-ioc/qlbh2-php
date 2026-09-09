<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();
$fields = ['store_name', 'store_phone', 'store_email', 'store_address', 'print_footer_note'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $pdo->beginTransaction();
    foreach ($fields as $key) {
        $value = post($key);
        $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $value]);
    }
    $pdo->commit();
    logActivity('STORE_SETTINGS_UPDATE');
    redirect('store_settings.php?saved=1');
}

$values = [];
foreach ($fields as $key) {
    $values[$key] = getSetting($key, $key === 'print_footer_note' ? 'Cảm ơn quý khách!' : '');
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Thông tin cửa hàng</h1>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Đã lưu thông tin cửa hàng</div><?php endif; ?>

<div class="card" style="max-width:560px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field"><label>Tên cửa hàng / công ty</label><input class="input" name="store_name" value="<?= e($values['store_name']) ?>" placeholder="vd: Cửa hàng ABC"></div>
    <div class="grid-2">
      <div class="field"><label>Số điện thoại</label><input class="input" name="store_phone" value="<?= e($values['store_phone']) ?>"></div>
      <div class="field"><label>Email liên hệ</label><input class="input" type="email" name="store_email" value="<?= e($values['store_email']) ?>"></div>
    </div>
    <div class="field"><label>Địa chỉ</label><input class="input" name="store_address" value="<?= e($values['store_address']) ?>"></div>
    <div class="field">
      <label>Lời cảm ơn cuối hóa đơn in</label>
      <input class="input" name="print_footer_note" value="<?= e($values['print_footer_note']) ?>">
    </div>
    <button type="submit" class="btn">Lưu thông tin</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
