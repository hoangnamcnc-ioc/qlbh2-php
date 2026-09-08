<?php
require_once __DIR__ . '/inc_header.php';

$statusLabels = [
    'PENDING' => 'Chờ lấy hàng', 'PICKED_UP' => 'Đã lấy hàng', 'IN_TRANSIT' => 'Đang giao',
    'DELIVERED' => 'Đã giao', 'FAILED' => 'Giao thất bại', 'RETURNED' => 'Đã hoàn',
];

$pdo = db();
$shipments = $pdo->query(
    'SELECT s.*, o.code AS order_code, c.name AS customer_name
     FROM shipments s
     JOIN orders o ON o.id = s.order_id
     LEFT JOIN customers c ON c.id = o.customer_id
     ORDER BY s.created_at DESC LIMIT 100'
)->fetchAll();
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Vận chuyển</h1>
<p class="muted" style="margin-bottom:16px;">Theo dõi vận đơn nội bộ. Tạo vận đơn từ trang chi tiết đơn hàng.</p>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Đơn hàng</th><th>Khách hàng</th><th>Mã vận đơn</th><th>Đơn vị</th><th>Trạng thái</th><th class="text-right">Phí ship</th><th class="text-right">Thu hộ (COD)</th></tr></thead>
    <tbody>
      <?php if (!$shipments): ?>
        <tr><td colspan="7" class="text-center muted" style="padding:32px;">Chưa có vận đơn nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($shipments as $s): ?>
        <tr>
          <td><a href="order_view.php?id=<?= (int) $s['order_id'] ?>" style="font-family:monospace;"><?= e($s['order_code']) ?></a></td>
          <td><?= e($s['customer_name'] ?: 'Khách lẻ') ?></td>
          <td><?= e($s['tracking_code'] ?: '—') ?></td>
          <td><?= e($s['carrier_name'] ?: '—') ?></td>
          <td><span class="badge badge-gray"><?= e($statusLabels[$s['status']] ?? $s['status']) ?></span></td>
          <td class="text-right"><?= money($s['shipping_fee']) ?></td>
          <td class="text-right"><?= money($s['cod_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
