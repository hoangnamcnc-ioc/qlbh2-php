<?php
require_once __DIR__ . '/inc_header.php';

$statusLabels = [
    'PENDING' => 'Chờ lấy hàng', 'PICKED_UP' => 'Đã lấy hàng', 'IN_TRANSIT' => 'Đang giao',
    'DELIVERED' => 'Đã giao', 'FAILED' => 'Giao thất bại', 'RETURNED' => 'Đã hoàn',
];

$filter = $_GET['filter'] ?? 'all';
$pdo = db();

$sql = 'SELECT s.*, o.code AS order_code, c.name AS customer_name
        FROM shipments s
        JOIN orders o ON o.id = s.order_id
        LEFT JOIN customers c ON c.id = o.customer_id';
if ($filter === 'unreconciled') {
    $sql .= " WHERE s.cod_amount > 0 AND s.reconciled_at IS NULL";
} elseif ($filter === 'reconciled') {
    $sql .= ' WHERE s.reconciled_at IS NOT NULL';
}
$sql .= ' ORDER BY s.created_at DESC LIMIT 100';
$shipments = $pdo->query($sql)->fetchAll();

$totalUnreconciled = $pdo->query('SELECT COALESCE(SUM(cod_amount),0) AS s FROM shipments WHERE cod_amount > 0 AND reconciled_at IS NULL')->fetch()['s'];
$totalNetUnreconciled = $pdo->query(
    "SELECT COALESCE(SUM(CASE WHEN fee_payer = 'CUSTOMER' THEN cod_amount - shipping_fee ELSE cod_amount END),0) AS s
     FROM shipments WHERE cod_amount > 0 AND reconciled_at IS NULL"
)->fetch()['s'];
$feePayerLabels = ['CUSTOMER' => 'Khách trả', 'SHOP' => 'Shop trả'];
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:8px;">Vận chuyển</h1>
<p class="muted" style="margin-bottom:16px;">Theo dõi vận đơn nội bộ. Tạo vận đơn từ trang chi tiết đơn hàng.</p>

<div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
  <div class="card" style="max-width:320px;">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">COD chưa đối soát</div>
    <div style="font-size:20px;font-weight:700;color:#dc2626;"><?= money($totalUnreconciled) ?></div>
  </div>
  <div class="card" style="max-width:320px;">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Thực nhận chưa đối soát (đã trừ phí ship nếu khách trả)</div>
    <div style="font-size:20px;font-weight:700;color:#dc2626;"><?= money($totalNetUnreconciled) ?></div>
  </div>
</div>

<div style="display:flex;gap:4px;margin-bottom:12px;border-bottom:1px solid #e2e8f0;">
  <a href="?filter=all" style="padding:8px 12px;font-size:14px;<?= $filter === 'all' ? 'border-bottom:2px solid #2563eb;color:#2563eb;font-weight:600;' : 'color:#64748b;' ?>">Tất cả</a>
  <a href="?filter=unreconciled" style="padding:8px 12px;font-size:14px;<?= $filter === 'unreconciled' ? 'border-bottom:2px solid #2563eb;color:#2563eb;font-weight:600;' : 'color:#64748b;' ?>">Chưa đối soát</a>
  <a href="?filter=reconciled" style="padding:8px 12px;font-size:14px;<?= $filter === 'reconciled' ? 'border-bottom:2px solid #2563eb;color:#2563eb;font-weight:600;' : 'color:#64748b;' ?>">Đã đối soát</a>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Đơn hàng</th><th>Người nhận</th><th>Mã vận đơn</th><th>Đơn vị</th><th>Trạng thái</th><th class="text-right">Phí ship</th><th>Người trả phí</th><th class="text-right">Thu hộ (COD)</th><th class="text-right">Thực nhận</th><th>Đối soát</th></tr></thead>
    <tbody>
      <?php if (!$shipments): ?>
        <tr><td colspan="10" class="text-center muted" style="padding:32px;">Không có vận đơn nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($shipments as $s): ?>
        <?php $net = $s['fee_payer'] === 'CUSTOMER' ? (float) $s['cod_amount'] - (float) $s['shipping_fee'] : (float) $s['cod_amount']; ?>
        <tr>
          <td><a href="order_view.php?id=<?= (int) $s['order_id'] ?>" style="font-family:monospace;"><?= e($s['order_code']) ?></a></td>
          <td><?= e($s['recipient_name'] ?: ($s['customer_name'] ?: 'Khách lẻ')) ?><?php if ($s['recipient_phone']): ?><br><span class="muted" style="font-size:12px;"><?= e($s['recipient_phone']) ?></span><?php endif; ?></td>
          <td><?= e($s['tracking_code'] ?: '—') ?></td>
          <td><?= e($s['carrier_name'] ?: '—') ?></td>
          <td><span class="badge badge-gray"><?= e($statusLabels[$s['status']] ?? $s['status']) ?></span></td>
          <td class="text-right"><?= money($s['shipping_fee']) ?></td>
          <td class="muted"><?= e($feePayerLabels[$s['fee_payer']] ?? $s['fee_payer']) ?></td>
          <td class="text-right"><?= money($s['cod_amount']) ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($net) ?></td>
          <td>
            <?php if ($s['cod_amount'] <= 0): ?>
              <span class="muted">—</span>
            <?php elseif ($s['reconciled_at']): ?>
              <span class="badge badge-green">Đã đối soát</span>
            <?php else: ?>
              <form method="post" action="shipment_reconcile.php" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="shipment_id" value="<?= (int) $s['id'] ?>">
                <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;">Đối soát</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
