<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$logs = $pdo->query(
    "SELECT * FROM activity_logs WHERE action LIKE 'EXPORT_%' OR action LIKE 'IMPORT_%' ORDER BY created_at DESC LIMIT 200"
)->fetchAll();

$actionLabels = [
    'EXPORT_PRODUCTS' => 'Xuất sản phẩm', 'IMPORT_PRODUCTS' => 'Nhập sản phẩm',
    'EXPORT_CUSTOMERS' => 'Xuất khách hàng', 'IMPORT_CUSTOMERS' => 'Nhập khách hàng',
];

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Xuất / nhập file</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Theo dõi lịch sử xuất/nhập file CSV của cửa hàng (sản phẩm, khách hàng). Thao tác xuất/nhập thực
  hiện tại <a href="products.php">Danh sách sản phẩm</a> và <a href="customers.php">Danh sách khách
  hàng</a>.
</p>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Thời gian</th><th>Người thực hiện</th><th>Thao tác</th><th>Chi tiết</th></tr></thead>
    <tbody>
      <?php if (!$logs): ?><tr><td colspan="4" class="text-center muted" style="padding:24px;">Chưa có lịch sử xuất/nhập file nào.</td></tr><?php endif; ?>
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
