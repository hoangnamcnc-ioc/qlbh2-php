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
    'SELECT oi.*, p.name AS product_name, v.name AS variant_name
     FROM order_items oi
     JOIN products p ON p.id = oi.product_id
     LEFT JOIN product_variants v ON v.id = oi.variant_id
     WHERE oi.order_id = ?'
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
    <?php if ($order['status'] !== 'CANCELLED'): ?>
      <a href="order_return_form.php?q=<?= urlencode($order['code']) ?>" class="btn btn-secondary">Đổi trả hàng</a>
    <?php endif; ?>
    <?php if (hasRole('ADMIN', 'MANAGER') && $order['status'] !== 'CANCELLED'): ?>
      <form method="post" action="order_cancel.php" onsubmit="return confirm('Hủy đơn hàng này? Tồn kho sẽ được hoàn lại.');">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
        <button type="submit" class="btn btn-danger">Hủy đơn hàng</button>
      </form>
    <?php endif; ?>
    <?php if ($order['status'] === 'CANCELLED'): ?>
      <span class="badge badge-red">Đã hủy</span>
    <?php else: ?>
      <span class="badge badge-green"><?= e($statusLabels[$order['status']] ?? $order['status']) ?></span>
    <?php endif; ?>
  </div>
</div>

<?php if ($order['status'] !== 'CANCELLED'):
  $pipeline = ['DRAFT' => 'Đặt hàng', 'APPROVED' => 'Duyệt', 'PACKED' => 'Đóng gói', 'SHIPPED' => 'Xuất kho', 'COMPLETED' => 'Hoàn thành'];
  $currentIdx = array_search($order['status'], array_keys($pipeline), true);
  $currentIdx = $currentIdx === false ? 0 : $currentIdx;
?>
<div class="card" style="margin-bottom:24px;">
  <div style="display:flex;align-items:center;">
    <?php foreach (array_values($pipeline) as $i => $label): ?>
      <div style="flex:1;display:flex;align-items:center;">
        <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;">
          <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;
            <?= $i <= $currentIdx ? 'background:#2563eb;color:#fff;' : 'background:#e2e8f0;color:#94a3b8;' ?>">
            <?= $i + 1 ?>
          </div>
          <div style="font-size:12px;margin-top:4px;white-space:nowrap;<?= $i <= $currentIdx ? 'color:#1e293b;font-weight:600;' : 'color:#94a3b8;' ?>"><?= e($label) ?></div>
        </div>
        <?php if ($i < count($pipeline) - 1): ?>
          <div style="flex:1;height:2px;<?= $i < $currentIdx ? 'background:#2563eb;' : 'background:#e2e8f0;' ?>margin:0 4px 18px;"></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

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
          <td><?= e($it['product_name']) ?><?php if ($it['variant_name']): ?> <span class="muted">(<?= e($it['variant_name']) ?>)</span><?php endif; ?></td>
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
