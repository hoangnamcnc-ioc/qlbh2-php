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
            b.name AS branch_name, u.name AS sold_by_name, sc.name AS channel_name, os.name AS source_name
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     JOIN branches b ON b.id = o.branch_id
     JOIN users u ON u.id = o.sold_by_id
     LEFT JOIN sales_channels sc ON sc.id = o.channel_id
     LEFT JOIN order_sources os ON os.id = o.source_id
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

$shipmentStmt = $pdo->prepare('SELECT * FROM shipments WHERE order_id = ?');
$shipmentStmt->execute([$id]);
$shipment = $shipmentStmt->fetch();
$shipmentStatusLabels = [
    'PENDING' => 'Chờ lấy hàng', 'PICKED_UP' => 'Đã lấy hàng', 'IN_TRANSIT' => 'Đang giao',
    'DELIVERED' => 'Đã giao', 'FAILED' => 'Giao thất bại', 'RETURNED' => 'Đã hoàn',
];

$promotionName = null;
if ($order['promotion_id']) {
    $pStmt = $pdo->prepare('SELECT name FROM promotions WHERE id = ?');
    $pStmt->execute([$order['promotion_id']]);
    $promotionName = $pStmt->fetchColumn();
}

$historyStmt = $pdo->prepare(
    'SELECT h.*, u.name AS changed_by_name FROM order_status_history h
     JOIN users u ON u.id = h.changed_by_id WHERE h.order_id = ? ORDER BY h.changed_at DESC'
);
$historyStmt->execute([$id]);
$history = $historyStmt->fetchAll();

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
      <a href="order_print.php?id=<?= (int) $order['id'] ?>" target="_blank" class="btn btn-secondary">In hóa đơn</a>
      <a href="order_return_form.php?q=<?= urlencode($order['code']) ?>" class="btn btn-secondary">Đổi trả hàng</a>
      <a href="shipment_form.php?order_id=<?= (int) $order['id'] ?>" class="btn btn-secondary">Vận chuyển</a>
    <?php endif; ?>
    <?php if (hasRole('ADMIN', 'MANAGER') && $order['status'] !== 'CANCELLED'): ?>
      <a href="order_edit.php?id=<?= (int) $order['id'] ?>" class="btn btn-secondary">Sửa đơn hàng</a>
    <?php endif; ?>
    <?php
      $pipelineOrder = ['DRAFT', 'APPROVED', 'PACKED', 'SHIPPED', 'COMPLETED'];
      $curIdx = array_search($order['status'], $pipelineOrder, true);
      $nextLabel = ($curIdx !== false && $curIdx < count($pipelineOrder) - 1) ? ($statusLabels[$pipelineOrder[$curIdx + 1]] ?? null) : null;
      $prevLabel = ($curIdx !== false && $curIdx > 0) ? ($statusLabels[$pipelineOrder[$curIdx - 1]] ?? null) : null;
    ?>
    <?php if (hasRole('ADMIN', 'MANAGER') && $prevLabel): ?>
      <form method="post" action="order_revert.php" onsubmit="return confirm('Lùi đơn hàng về bước &quot;<?= e($prevLabel) ?>&quot;? Dùng khi vừa chuyển bước nhầm.<?= $order['status'] === 'COMPLETED' ? ' Phiếu bảo hành và điểm tích lũy đã cộng cho đơn này sẽ bị hủy.' : '' ?>');">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
        <button type="submit" class="btn btn-secondary">← Lùi về: <?= e($prevLabel) ?></button>
      </form>
    <?php endif; ?>
    <?php if (hasRole('ADMIN', 'MANAGER') && $nextLabel): ?>
      <form method="post" action="order_advance.php" onsubmit="return confirm('Chuyển đơn hàng sang bước &quot;<?= e($nextLabel) ?>&quot;?');">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
        <button type="submit" class="btn">Chuyển sang: <?= e($nextLabel) ?></button>
      </form>
    <?php endif; ?>
    <?php if (hasRole('ADMIN', 'MANAGER') && $order['status'] !== 'CANCELLED'): ?>
      <?php $cancelReasons = $pdo->query("SELECT * FROM cancel_reasons WHERE is_active = 1 AND applies_to IN ('CANCEL','BOTH') ORDER BY id")->fetchAll(); ?>
      <form method="post" action="order_cancel.php" style="display:flex;gap:6px;align-items:center;" onsubmit="return confirm('Hủy đơn hàng này? Tồn kho sẽ được hoàn lại.');">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
        <?php if ($cancelReasons): ?>
          <select name="reason" class="input" style="max-width:180px;padding:6px 10px;" onchange="document.getElementById('cancel-reason-other').style.display = this.value === '__OTHER__' ? 'inline-block' : 'none';">
            <option value="">— Lý do hủy —</option>
            <?php foreach ($cancelReasons as $r): ?>
              <option value="<?= e($r['name']) ?>"><?= e($r['name']) ?></option>
            <?php endforeach; ?>
            <option value="__OTHER__">Khác (nhập bên cạnh)</option>
          </select>
          <input class="input" id="cancel-reason-other" name="reason_other" placeholder="Nhập lý do khác" style="max-width:160px;padding:6px 10px;display:none;">
        <?php else: ?>
          <input class="input" name="reason" placeholder="Lý do hủy (tùy chọn)" style="max-width:180px;padding:6px 10px;">
        <?php endif; ?>
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
    <?php if ($order['is_delivery']): ?>
      <p style="margin:8px 0 2px;"><span class="badge badge-green">Giao hàng</span></p>
      <?php if ($order['shipping_address']): ?><p class="muted" style="margin:2px 0;">Địa chỉ: <?= e($order['shipping_address']) ?></p><?php endif; ?>
    <?php endif; ?>
    <?php if ($order['note']): ?><p class="muted" style="margin:8px 0 0;">Ghi chú: <?= e($order['note']) ?></p><?php endif; ?>
  </div>
  <div class="card">
    <h2 style="font-size:14px;font-weight:600;margin:0 0 8px;">Thông tin đơn</h2>
    <p style="margin:2px 0;">Bán tại: <?= e($order['branch_name']) ?></p>
    <p style="margin:2px 0;">Bán bởi: <?= e($order['sold_by_name']) ?></p>
    <p style="margin:2px 0;">Nguồn: <?= e($order['source_name'] ?: '—') ?></p>
    <p style="margin:2px 0;">Kênh bán: <?= e($order['channel_name'] ?: 'Trực tiếp') ?><?php if ($order['external_order_code']): ?> <span class="muted">(mã: <?= e($order['external_order_code']) ?>)</span><?php endif; ?></p>
    <?php if ($order['coupon_code']): ?><p style="margin:2px 0;">Mã giảm giá: <span style="font-family:monospace;"><?= e($order['coupon_code']) ?></span></p><?php endif; ?>
    <?php if ($promotionName): ?><p style="margin:2px 0;">Khuyến mại tự động: <?= e($promotionName) ?></p><?php endif; ?>
    <?php if ($order['tags']): ?><p style="margin:2px 0;">Tags: <?php foreach (explode(',', $order['tags']) as $t): ?><span class="badge badge-gray" style="margin-right:4px;"><?= e(trim($t)) ?></span><?php endforeach; ?></p><?php endif; ?>
  </div>
  <?php if ($shipment): ?>
  <div class="card">
    <h2 style="font-size:14px;font-weight:600;margin:0 0 8px;">Vận chuyển</h2>
    <p style="margin:2px 0;">Đơn vị: <?= e($shipment['carrier_name'] ?: '—') ?></p>
    <p style="margin:2px 0;">Mã vận đơn: <?= e($shipment['tracking_code'] ?: '—') ?></p>
    <p style="margin:2px 0;">Trạng thái: <span class="badge badge-gray"><?= e($shipmentStatusLabels[$shipment['status']] ?? $shipment['status']) ?></span></p>
    <?php if ((float) $shipment['cod_amount'] > 0): ?>
      <p style="margin:2px 0;">Người trả phí ship: <?= $shipment['fee_payer'] === 'SHOP' ? 'Shop trả' : 'Khách trả' ?></p>
      <p style="margin:2px 0;">Thực nhận từ ĐVVC: <b><?= money($shipment['fee_payer'] === 'CUSTOMER' ? (float) $shipment['cod_amount'] - (float) $shipment['shipping_fee'] : (float) $shipment['cod_amount']) ?></b></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card" style="padding:0;overflow-x:auto;margin-bottom:24px;">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">Đơn giá</th><th class="text-center">SL</th><th class="text-right">Thành tiền</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?><?php if ($it['variant_name']): ?> <span class="muted">(<?= e($it['variant_name']) ?>)</span><?php endif; ?></td>
          <td class="text-right"><?= money($it['unit_price']) ?></td>
          <td class="text-center"><?= fmtQty($it['quantity']) ?></td>
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

<?php $orderRemaining = (float) $order['total_amount'] - (float) $order['paid_amount']; ?>
<?php if ($orderRemaining > 0.01): ?>
<div class="card" style="max-width:360px;margin-left:auto;margin-top:16px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thanh toán</h2>
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;color:#059669;"><span>Đã thanh toán</span><span><?= money($order['paid_amount']) ?></span></div>
  <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:8px;margin-bottom:12px;font-weight:600;color:#dc2626;"><span>Còn phải trả</span><span><?= money($orderRemaining) ?></span></div>
  <?php if ($order['customer_id']): ?>
    <form method="post" action="order_pay.php" style="display:flex;gap:8px;align-items:end;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
      <div class="field" style="flex:1;margin:0;">
        <label>Số tiền thu thêm</label>
        <input class="input" type="number" min="1" max="<?= (float) $orderRemaining ?>" name="amount" required>
      </div>
      <button type="submit" class="btn">Ghi nhận thu</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($history): ?>
<h2 style="font-size:16px;font-weight:600;margin:32px 0 12px;">Lịch sử thay đổi</h2>
<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Thời gian</th><th>Người thực hiện</th><th>Thay đổi</th><th>Ghi chú</th></tr></thead>
    <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($h['changed_at'])) ?></td>
          <td><?= e($h['changed_by_name']) ?></td>
          <td>
            <?php if ($h['from_status'] && $h['from_status'] !== $h['to_status']): ?>
              <?= e($statusLabels[$h['from_status']] ?? $h['from_status']) ?> → <?= e($statusLabels[$h['to_status']] ?? $h['to_status']) ?>
            <?php else: ?>
              <?= e($statusLabels[$h['to_status']] ?? $h['to_status']) ?>
            <?php endif; ?>
          </td>
          <td class="muted"><?= e($h['note'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
