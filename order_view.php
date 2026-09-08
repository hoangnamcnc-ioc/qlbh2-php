<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

$statusLabels = [
    'DRAFT' => 'Đặt hàng', 'APPROVED' => 'Duyệt', 'PACKED' => 'Đóng gói',
    'SHIPPED' => 'Xuất kho', 'COMPLETED' => 'Hoàn thành', 'CANCELLED' => 'Đã hủy',
];
$methodLabels = [
    'CASH' => 'Tiền mặt', 'BANK_TRANSFER' => 'Chuyển khoản', 'CARD' => 'Quẹt thẻ', 'QR_CODE' => 'Quét mã QR',
];

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone, c.loyalty_points,
            b.name AS branch_name, u.name AS sold_by_name
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     JOIN branches b ON b.id = o.branch_id
     JOIN users u ON u.id = o.sold_by_id
     WHERE o.id = ?'
);
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) redirect('orders.php');

$items = $pdo->prepare(
    'SELECT oi.*, p.name AS product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?'
);
$items->execute([$id]);
$items = $items->fetchAll();

$payments = $pdo->prepare('SELECT * FROM payments WHERE order_id = ?');
$payments->execute([$id]);
$payments = $payments->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="orders.php" class="muted" style="font-size:14px;">← Danh sách đơn hàng</a>

<div style="display:flex;align-items:center;justify-content:space-between;margin:8px 0 24px;">
  <div>
    <h1 style="font-size:24px;font-weight:600;font-family:monospace;margin:0;"><?= e($order['code']) ?></h1>
    <p class="muted" style="margin:2px 0 0;"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p>
  </div>
  <div style="display:flex;align-items:center;gap:12px;">
    <a href="order_return_form.php?q=<?= urlencode($order['code']) ?>" class="btn btn-secondary">Đổi trả hàng</a>
    <span class="badge badge-green"><?= e($statusLabels[$order['status']] ?? $order['status']) ?></span>
  </div>
</div>

<div class="grid-2" style="margin-bottom:24px;">
  <div class="card">
    <h2 style="font-size:14px;font-weight:600;margin:0 0 8px;">Thông tin khách hàng</h2>
    <p style="margin:2px 0;"><?= e($order['customer_name'] ?: 'Khách lẻ') ?></p>
    <?php if ($order['customer_phone']): ?><p class="muted" style="margin:2px 0;"><?= e($order['customer_phone']) ?></p><?php endif; ?>
    <?php if ($order['customer_name']): ?><p class="muted" style="margin:2px 0;">Điểm tích lũy: <?= (int) $order['loyalty_points'] ?></p><?php endif; ?>
  </div>
  <div class="card">
    <h2 style="font-size:14px;font-weight:600;margin:0 0 8px;">Thông tin đơn</h2>
    <p style="margin:2px 0;">Bán tại: <?= e($order['branch_name']) ?></p>
    <p style="margin:2px 0;">Bán bởi: <?= e($order['sold_by_name']) ?></p>
    <p style="margin:2px 0;">Nguồn: <?= e($order['source']) ?></p>
  </div>
</div>

<div class="card" style="padding:0;overflow-x:auto;margin-bottom:24px;">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">Đơn giá</th><th class="text-center">SL</th><th class="text-right">Thành tiền</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?></td>
          <td class="text-right"><?= money($it['unit_price']) ?></td>
          <td class="text-center"><?= (int) $it['quantity'] ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($it['line_total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="max-width:360px;margin-left:auto;">
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;"><span>Tổng tiền</span><span><?= money($order['sub_total']) ?></span></div>
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;"><span>Chiết khấu</span><span><?= money($order['discount']) ?></span></div>
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px;"><span>Phí giao hàng</span><span><?= money($order['shipping_fee']) ?></span></div>
  <div style="display:flex;justify-content:space-between;font-weight:700;color:#2563eb;border-top:1px solid #e2e8f0;padding-top:8px;">
    <span>Khách phải trả</span><span><?= money($order['total_amount']) ?></span>
  </div>
  <?php foreach ($payments as $p): ?>
    <p class="muted" style="font-size:12px;margin:8px 0 0;">
      Đã thanh toán <?= money($p['amount']) ?> qua <?= e($methodLabels[$p['method']] ?? $p['method']) ?>
      lúc <?= date('d/m/Y H:i', strtotime($p['paid_at'])) ?>
    </p>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
