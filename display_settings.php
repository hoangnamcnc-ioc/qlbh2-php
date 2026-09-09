<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();

$quickActions = [
    'qa_add_service' => 'Thêm dịch vụ (F9)',
    'qa_promotions' => 'Khuyến mại (F8)',
    'qa_gift' => 'Đổi quà',
    'qa_clear_cart' => 'Xóa toàn bộ sản phẩm',
    'qa_customers' => 'Thông tin khách hàng',
    'qa_returns' => 'Đổi trả hàng',
    'qa_orders' => 'Xem danh sách đơn hàng',
    'qa_reports' => 'Xem báo cáo',
    'qa_sales_settings' => 'Thiết lập chung',
    'qa_cashbook' => 'Tạo phiếu thu/chi',
    'qa_print_last' => 'In đơn gần nhất',
    'qa_customer_display' => 'Kết nối màn hình phụ',
    'qa_qr_payment' => 'Hiện mã QR thanh toán',
    'qa_batches' => 'Chọn lô tự động (Alt+5)',
    'qa_offline' => 'Bán hàng Offline',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $brandColor = post('brand_color') ?: '#2563eb';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor)) $brandColor = '#2563eb';
    $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['brand_color', $brandColor]);

    $sortOrder = in_array($_POST['product_sort_order'] ?? '', ['name_asc', 'name_desc', 'newest'], true)
        ? $_POST['product_sort_order'] : 'name_asc';
    $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['product_sort_order', $sortOrder]);

    $splitLines = isset($_POST['print_split_lines']) ? '1' : '0';
    $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['print_split_lines', $splitLines]);

    $showSTT = isset($_POST['show_column_stt']) ? '1' : '0';
    $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['show_column_stt', $showSTT]);
    $showSku = isset($_POST['show_column_sku']) ? '1' : '0';
    $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['show_column_sku', $showSku]);

    foreach ($quickActions as $key => $label) {
        $value = isset($_POST[$key]) ? '1' : '0';
        $pdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $value]);
    }

    logActivity('DISPLAY_SETTINGS_UPDATE');
    redirect('display_settings.php?saved=1');
}

$brandColor = getSetting('brand_color', '#2563eb');
$sortOrder = getSetting('product_sort_order', 'name_asc');
$splitLines = getSetting('print_split_lines', '0');
$showSTT = getSetting('show_column_stt', '1');
$showSku = getSetting('show_column_sku', '0');
$qaValues = [];
foreach ($quickActions as $key => $label) {
    $qaValues[$key] = getSetting($key, '1');
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Tùy chỉnh giao diện bán hàng</h1>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Đã lưu cấu hình. Tải lại trang Bán hàng (POS) để thấy thay đổi.</div><?php endif; ?>

<form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

  <div class="card" style="max-width:640px;margin-bottom:20px;">
    <h2 style="font-size:15px;font-weight:600;margin:0 0 12px;">Thay đổi màu sắc</h2>
    <div class="field" style="display:flex;align-items:center;gap:12px;">
      <label style="margin:0;">Màu chủ đạo (nút, tab đang chọn...)</label>
      <input type="color" name="brand_color" value="<?= e($brandColor) ?>" style="width:60px;height:36px;padding:2px;border:1px solid #cbd5e1;border-radius:6px;">
    </div>
  </div>

  <div class="card" style="max-width:640px;margin-bottom:20px;">
    <h2 style="font-size:15px;font-weight:600;margin:0 0 12px;">Sắp xếp & hiển thị sản phẩm</h2>
    <div class="field">
      <label>Sắp xếp thứ tự sản phẩm (tab "Danh sách sản phẩm" trong POS)</label>
      <select class="input" name="product_sort_order" style="max-width:240px;">
        <option value="name_asc" <?= $sortOrder === 'name_asc' ? 'selected' : '' ?>>Tên A → Z</option>
        <option value="name_desc" <?= $sortOrder === 'name_desc' ? 'selected' : '' ?>>Tên Z → A</option>
        <option value="newest" <?= $sortOrder === 'newest' ? 'selected' : '' ?>>Mới thêm trước</option>
      </select>
    </div>
    <div class="field">
      <label>Điều chỉnh cột hiển thị trong giỏ hàng POS</label>
      <label style="font-weight:400;"><input type="checkbox" name="show_column_stt" <?= $showSTT === '1' ? 'checked' : '' ?>> Hiện cột STT</label><br>
      <label style="font-weight:400;"><input type="checkbox" name="show_column_sku" <?= $showSku === '1' ? 'checked' : '' ?>> Hiện cột Mã hàng (SKU)</label>
    </div>
  </div>

  <div class="card" style="max-width:640px;margin-bottom:20px;">
    <h2 style="font-size:15px;font-weight:600;margin:0 0 12px;">Mẫu in hóa đơn</h2>
    <div class="field">
      <label style="font-weight:400;"><input type="checkbox" name="print_split_lines" <?= $splitLines === '1' ? 'checked' : '' ?>> Tách dòng: in mỗi đơn vị sản phẩm thành 1 dòng riêng thay vì gộp theo số lượng</label>
    </div>
  </div>

  <div class="card" style="max-width:640px;margin-bottom:20px;">
    <h2 style="font-size:15px;font-weight:600;margin:0 0 12px;">Tùy chỉnh nút chức năng hiển thị trong POS</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
      <?php foreach ($quickActions as $key => $label): ?>
        <label style="font-weight:400;font-size:13px;"><input type="checkbox" name="<?= e($key) ?>" <?= $qaValues[$key] === '1' ? 'checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach; ?>
    </div>
  </div>

  <button type="submit" class="btn">Lưu cấu hình</button>
</form>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
