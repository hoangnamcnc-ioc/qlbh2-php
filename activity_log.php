<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();
$actionLabels = [
    'LOGIN' => 'Đăng nhập', 'LOGOUT' => 'Đăng xuất',
    'USER_CREATE' => 'Tạo tài khoản', 'USER_TOGGLE' => 'Khóa/mở khóa tài khoản',
    'USER_ROLE_CHANGE' => 'Đổi vai trò/chi nhánh', 'USER_RESET_PASSWORD' => 'Đặt lại mật khẩu',
    'STORE_SETTINGS_UPDATE' => 'Sửa thông tin cửa hàng', 'SALES_SETTINGS_UPDATE' => 'Sửa cấu hình bán hàng',
    'INVENTORY_SETTINGS_UPDATE' => 'Sửa cấu hình kho', 'BRANCH_CREATE' => 'Tạo chi nhánh',
    'CHANNEL_CREATE' => 'Tạo kênh bán hàng', 'CHANNEL_TOGGLE' => 'Bật/tắt kênh bán hàng',
    'TAX_RATE_CREATE' => 'Tạo mức thuế', 'TAX_RATE_TOGGLE' => 'Bật/tắt mức thuế',
    'CANCEL_REASON_CREATE' => 'Tạo lý do hủy trả', 'CANCEL_REASON_TOGGLE' => 'Bật/tắt lý do hủy trả',
    'ORDER_SOURCE_CREATE' => 'Tạo nguồn bán hàng', 'ORDER_SOURCE_TOGGLE' => 'Bật/tắt nguồn bán hàng',
    'EXPORT_PRODUCTS' => 'Xuất file sản phẩm', 'IMPORT_PRODUCTS' => 'Nhập file sản phẩm',
    'EXPORT_CUSTOMERS' => 'Xuất file khách hàng', 'IMPORT_CUSTOMERS' => 'Nhập file khách hàng',
];

$actionFilter = $_GET['action'] ?? '';
$sql = 'SELECT * FROM activity_logs';
$params = [];
if ($actionFilter !== '' && array_key_exists($actionFilter, $actionLabels)) {
    $sql .= ' WHERE action = ?';
    $params[] = $actionFilter;
}
$sql .= ' ORDER BY created_at DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Nhật ký hoạt động</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Ghi lại các thao tác quan trọng: đăng nhập/đăng xuất, tạo/sửa tài khoản, thay đổi cấu hình hệ
  thống. Hiển thị tối đa 200 dòng gần nhất.
</p>

<form style="margin-bottom:16px;">
  <select name="action" class="input" style="max-width:260px;" onchange="this.form.submit()">
    <option value="">Tất cả hoạt động</option>
    <?php foreach ($actionLabels as $val => $lbl): ?>
      <option value="<?= e($val) ?>" <?= $actionFilter === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Thời gian</th><th>Người thực hiện</th><th>Hoạt động</th><th>Chi tiết</th></tr></thead>
    <tbody>
      <?php if (!$logs): ?><tr><td colspan="4" class="text-center muted" style="padding:24px;">Chưa có nhật ký nào.</td></tr><?php endif; ?>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td class="muted"><?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?></td>
          <td><?= e($l['user_name'] ?: 'Hệ thống') ?></td>
          <td><span class="badge badge-gray"><?= e($actionLabels[$l['action']] ?? $l['action']) ?></span></td>
          <td class="muted" style="font-size:13px;"><?= e($l['detail'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
