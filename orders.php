<?php
require_once __DIR__ . '/inc_header.php';

$statusLabels = [
    'DRAFT' => 'Đặt hàng', 'APPROVED' => 'Duyệt', 'PACKED' => 'Đóng gói',
    'SHIPPED' => 'Xuất kho', 'COMPLETED' => 'Hoàn thành', 'CANCELLED' => 'Đã hủy',
];

$pdo = db();
$orders = $pdo->query(
    'SELECT o.*, c.name AS customer_name FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     ORDER BY o.created_at DESC LIMIT 100'
)->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;">Danh sách đơn hàng</h1>
  <a href="pos.php" class="btn">+ Tạo đơn hàng</a>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr><th>Mã đơn hàng</th><th>Ngày tạo</th><th>Khách hàng</th><th>Trạng thái</th><th class="text-right">Tổng tiền</th></tr>
    </thead>
    <tbody>
      <?php if (!$orders): ?>
        <tr><td colspan="5" class="text-center muted" style="padding:32px;">Chưa có đơn hàng nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><a href="order_view.php?id=<?= (int) $o['id'] ?>" style="font-family:monospace;"><?= e($o['code']) ?></a></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
          <td><?= e($o['customer_name'] ?: 'Khách lẻ') ?></td>
          <td><span class="badge badge-gray"><?= e($statusLabels[$o['status']] ?? $o['status']) ?></span></td>
          <td class="text-right" style="font-weight:600;"><?= money($o['total_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
