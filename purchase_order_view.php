<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$statusLabels = ['PENDING' => 'Chờ nhập', 'RECEIVED' => 'Đã nhập', 'CANCELLED' => 'Đã hủy'];

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT po.*, s.name AS supplier_name, b.name AS branch_name, u.name AS created_by_name, a.name AS assigned_staff_name
     FROM purchase_orders po
     LEFT JOIN suppliers s ON s.id = po.supplier_id
     JOIN branches b ON b.id = po.branch_id
     JOIN users u ON u.id = po.created_by_id
     LEFT JOIN users a ON a.id = po.assigned_staff_id
     WHERE po.id = ?'
);
$stmt->execute([$id]);
$po = $stmt->fetch();
if (!$po) redirect('purchase_orders.php');

$items = $pdo->prepare(
    'SELECT i.*, p.name AS product_name, v.name AS variant_name
     FROM purchase_order_items i
     JOIN products p ON p.id = i.product_id
     LEFT JOIN product_variants v ON v.id = i.variant_id
     WHERE i.po_id = ?'
);
$items->execute([$id]);
$items = $items->fetchAll();
$total = array_sum(array_map(fn($i) => $i['quantity'] * $i['cost_price'], $items));

require_once __DIR__ . '/inc_header.php';
?>

<a href="purchase_orders.php" class="muted" style="font-size:14px;">← Danh sách đặt hàng nhập</a>
<div style="display:flex;align-items:center;justify-content:space-between;margin:8px 0 24px;">
  <div>
    <h1 style="font-size:24px;font-weight:600;font-family:monospace;margin:0;"><?= e($po['code']) ?></h1>
    <p class="muted" style="margin:2px 0 0;"><?= date('d/m/Y H:i', strtotime($po['created_at'])) ?></p>
  </div>
  <div style="display:flex;align-items:center;gap:12px;">
    <?php if ($po['status'] === 'PENDING'): ?>
      <form method="post" action="purchase_order_receive.php" onsubmit="return confirm('Tạo phiếu nhập kho từ đặt hàng này?');">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
        <button type="submit" class="btn">Nhập kho từ đặt hàng này</button>
      </form>
    <?php endif; ?>
    <?php if ($po['status'] === 'RECEIVED'): ?><span class="badge badge-green">Đã nhập</span>
    <?php elseif ($po['status'] === 'CANCELLED'): ?><span class="badge badge-gray">Đã hủy</span>
    <?php else: ?><span class="badge badge-red">Chờ nhập</span><?php endif; ?>
  </div>
</div>

<div class="grid-2" style="margin-bottom:24px;">
  <div class="card">
    <p style="margin:2px 0;">Nhà cung cấp: <?= e($po['supplier_name'] ?: '—') ?></p>
    <p style="margin:2px 0;">Nhập tại: <?= e($po['branch_name']) ?></p>
  </div>
  <div class="card">
    <p style="margin:2px 0;">Người tạo: <?= e($po['created_by_name']) ?></p>
    <?php if ($po['assigned_staff_name']): ?><p style="margin:2px 0;">Nhân viên phụ trách: <?= e($po['assigned_staff_name']) ?></p><?php endif; ?>
    <?php if ($po['expected_delivery_date']): ?><p style="margin:2px 0;">Ngày hẹn giao: <?= date('d/m/Y', strtotime($po['expected_delivery_date'])) ?></p><?php endif; ?>
    <?php if ($po['reference_no']): ?><p style="margin:2px 0;">Tham chiếu: <?= e($po['reference_no']) ?></p><?php endif; ?>
    <?php if ($po['note']): ?><p style="margin:2px 0;">Ghi chú: <?= e($po['note']) ?></p><?php endif; ?>
  </div>
</div>

<div class="card" style="padding:0;overflow-x:auto;margin-bottom:16px;">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">SL đặt</th><th class="text-right">Giá dự kiến</th><th class="text-right">Thành tiền</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?><?php if ($it['variant_name']): ?> <span class="muted">(<?= e($it['variant_name']) ?>)</span><?php endif; ?></td>
          <td class="text-right"><?= (int) $it['quantity'] ?></td>
          <td class="text-right"><?= money($it['cost_price']) ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($it['quantity'] * $it['cost_price']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div style="max-width:400px;margin-left:auto;font-size:16px;font-weight:700;text-align:right;color:#2563eb;">
  Tổng tiền dự kiến: <?= money($total) ?>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
