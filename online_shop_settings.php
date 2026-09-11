<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $enabled = isset($_POST['enabled']) ? '1' : '0';
    $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['online_shop_enabled', $enabled]);
    logActivity('ONLINE_SHOP_SETTINGS_UPDATE');
    redirect('online_shop_settings.php?saved=1');
}

$enabled = getSetting('online_shop_enabled', '1') === '1';
$shopUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/shop.php';

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Đặt hàng Online</h1>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Đã lưu cấu hình</div><?php endif; ?>

<div class="card" style="max-width:560px;margin-bottom:24px;">
  <p style="margin:0 0 12px;font-size:14px;">
    Chia sẻ đường dẫn dưới đây cho khách hàng (qua Facebook/Zalo/tin nhắn) để khách tự chọn sản
    phẩm và đặt hàng — không cần đăng nhập. Đơn đặt sẽ vào mục
    <a href="orders.php?status=DRAFT">Đơn hàng → Đặt hàng (chờ xử lý)</a> để nhân viên xác nhận
    trước khi chuyển sang các bước Duyệt/Đóng gói/Xuất kho/Hoàn thành.
  </p>
  <div style="display:flex;gap:8px;">
    <input class="input" readonly value="<?= e($shopUrl) ?>" onclick="this.select()" style="font-family:monospace;font-size:13px;">
  </div>
</div>

<div class="card" style="max-width:560px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <label style="font-weight:400;"><input type="checkbox" name="enabled" <?= $enabled ? 'checked' : '' ?>> Mở nhận đặt hàng online</label>
    <p class="muted" style="font-size:12px;margin:6px 0 12px;">Tắt tạm thời khi hết hàng/nghỉ bán mà không cần gỡ đường dẫn đã chia sẻ.</p>
    <button type="submit" class="btn">Lưu</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
