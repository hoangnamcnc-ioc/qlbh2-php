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

    if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $tmpPath = $_FILES['logo']['tmp_name'];
        $mime = mime_content_type($tmpPath);
        if (isset($allowed[$mime]) && $_FILES['logo']['size'] <= 3 * 1024 * 1024) {
            $ext = $allowed[$mime];
            $filename = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dir = __DIR__ . '/uploads/store';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (move_uploaded_file($tmpPath, $dir . '/' . $filename)) {
                $oldLogo = getSetting('store_logo', '');
                $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                    ->execute(['store_logo', $filename]);
                if ($oldLogo && is_file($dir . '/' . $oldLogo)) {
                    unlink($dir . '/' . $oldLogo);
                }
            }
        }
    }

    $pdo->commit();
    logActivity('STORE_SETTINGS_UPDATE');
    redirect('store_settings.php?saved=1');
}

$values = [];
foreach ($fields as $key) {
    $values[$key] = getSetting($key, $key === 'print_footer_note' ? 'Cảm ơn quý khách!' : '');
}
$storeLogo = getSetting('store_logo', '');

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Thông tin cửa hàng</h1>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Đã lưu thông tin cửa hàng</div><?php endif; ?>

<div class="card" style="max-width:560px;">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field">
      <label>Logo cửa hàng</label>
      <div style="display:flex;align-items:center;gap:16px;">
        <?php if ($storeLogo): ?>
          <img src="uploads/store/<?= e($storeLogo) ?>" alt="Logo" style="width:64px;height:64px;object-fit:contain;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">
        <?php else: ?>
          <div class="muted" style="font-size:13px;">Chưa có logo</div>
        <?php endif; ?>
        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
      </div>
      <p class="muted" style="font-size:12px;margin:6px 0 0;">Hiển thị ở menu quản trị, trang đăng nhập, hóa đơn in và trang đặt hàng online. Ảnh vuông, tối đa 3MB.</p>
    </div>
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
