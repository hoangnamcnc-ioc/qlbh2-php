<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');
require_once __DIR__ . '/inc_header.php';

$statusLabels = ['PENDING' => 'Chờ nhập', 'RECEIVED' => 'Đã nhập', 'CANCELLED' => 'Đã hủy'];

$pdo = db();
$pos = $pdo->query(
    'SELECT po.*, s.name AS supplier_name, u.name AS created_by_name,
            (SELECT COALESCE(SUM(quantity*cost_price),0) FROM purchase_order_items WHERE po_id = po.id) AS total_amount
     FROM purchase_orders po
     LEFT JOIN suppliers s ON s.id = po.supplier_id
     JOIN users u ON u.id = po.created_by_id
     ORDER BY po.created_at DESC LIMIT 100'
)->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;">Đặt hàng nhập</h1>
  <a href="purchase_order_form.php" class="btn">+ Tạo đặt hàng nhập</a>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã đặt hàng</th><th>Nhà cung cấp</th><th>Người tạo</th><th>Trạng thái</th><th>Ngày</th><th class="text-right">Tổng tiền dự kiến</th></tr></thead>
    <tbody>
      <?php if (!$pos): ?>
        <tr><td colspan="6" class="text-center muted" style="padding:32px;">Chưa có đặt hàng nhập nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($pos as $po): ?>
        <tr>
          <td><a href="purchase_order_view.php?id=<?= (int) $po['id'] ?>" style="font-family:monospace;"><?= e($po['code']) ?></a></td>
          <td><?= e($po['supplier_name'] ?: '—') ?></td>
          <td><?= e($po['created_by_name']) ?></td>
          <td>
            <?php if ($po['status'] === 'RECEIVED'): ?><span class="badge badge-green">Đã nhập</span>
            <?php elseif ($po['status'] === 'CANCELLED'): ?><span class="badge badge-gray">Đã hủy</span>
            <?php else: ?><span class="badge badge-red">Chờ nhập</span><?php endif; ?>
          </td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($po['created_at'])) ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($po['total_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
