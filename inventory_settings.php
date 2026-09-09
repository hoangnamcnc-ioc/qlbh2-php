<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $value = isset($_POST['allow_negative_stock']) ? '1' : '0';
    $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['allow_negative_stock', $value]);
    logActivity('INVENTORY_SETTINGS_UPDATE', 'allow_negative_stock=' . $value);
    redirect('inventory_settings.php?saved=1');
}

$allowNegativeStock = getSetting('allow_negative_stock', '0');

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Quản lý kho & Sản phẩm</h1>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Đã lưu cấu hình</div><?php endif; ?>

<div class="card" style="max-width:560px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field">
      <label><input type="checkbox" name="allow_negative_stock" <?= $allowNegativeStock === '1' ? 'checked' : '' ?>> Cho phép bán khi tồn kho không đủ (bán âm kho)</label>
      <p class="muted" style="font-size:12px;margin:4px 0 0;">Khi bật, POS vẫn cho thanh toán dù sản phẩm/thành phần combo không đủ tồn kho — tồn kho có thể xuống âm. Dùng cho cửa hàng chưa cập nhật tồn kho kịp thời.</p>
    </div>
    <button type="submit" class="btn">Lưu cấu hình</button>
  </form>
</div>

<p class="muted" style="font-size:13px;margin-top:16px;max-width:560px;">
  Các thiết lập khác về sản phẩm: xem <a href="categories.php">Danh mục</a>, <a href="brands.php">Nhãn hiệu</a>.
</p>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
