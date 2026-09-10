<?php
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$today = date('Y-m-d 00:00:00');

$revenueToday = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount),0) AS total FROM orders WHERE created_at >= ? AND status != 'CANCELLED'"
);
$revenueToday->execute([$today]);
$revenueToday = (float) $revenueToday->fetch()['total'];

$newOrdersToday = $pdo->prepare('SELECT COUNT(*) AS c FROM orders WHERE created_at >= ?');
$newOrdersToday->execute([$today]);
$newOrdersToday = (int) $newOrdersToday->fetch()['c'];

$cancelledToday = $pdo->prepare("SELECT COUNT(*) AS c FROM orders WHERE created_at >= ? AND status = 'CANCELLED'");
$cancelledToday->execute([$today]);
$cancelledToday = (int) $cancelledToday->fetch()['c'];

$totalStock = (int) $pdo->query('SELECT COALESCE(SUM(quantity),0) AS s FROM inventory')->fetch()['s'];

// Doanh thu 7 ngày qua (theo ngày) để vẽ biểu đồ
$from7 = date('Y-m-d 00:00:00', strtotime('-6 days'));
$stmt = $pdo->prepare(
    "SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS total
     FROM orders WHERE created_at >= ? AND status != 'CANCELLED'
     GROUP BY DATE(created_at)"
);
$stmt->execute([$from7]);
$revenueByDay = [];
foreach ($stmt->fetchAll() as $r) {
    $revenueByDay[$r['d']] = (float) $r['total'];
}
$chartDays = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartDays[$d] = $revenueByDay[$d] ?? 0;
}
$maxRevenue = max([1, ...array_values($chartDays)]);

// Đơn hàng cần xử lý (theo từng bước trong pipeline, chưa hoàn thành/hủy)
$pendingStatusLabels = [
    'DRAFT' => 'Chờ duyệt', 'APPROVED' => 'Chờ đóng gói', 'PACKED' => 'Chờ lấy hàng', 'SHIPPED' => 'Đang giao hàng',
];
$stmt = $pdo->query(
    "SELECT status, COUNT(*) AS c FROM orders WHERE status IN ('DRAFT','APPROVED','PACKED','SHIPPED') GROUP BY status"
);
$pendingCounts = array_fill_keys(array_keys($pendingStatusLabels), 0);
foreach ($stmt->fetchAll() as $r) {
    $pendingCounts[$r['status']] = (int) $r['c'];
}

// Sản phẩm dưới định mức
$lowStock = $pdo->query(
    'SELECT i.quantity, i.min_stock, p.name AS product_name, v.name AS variant_name, b.name AS branch_name
     FROM inventory i
     JOIN products p ON p.id = i.product_id
     LEFT JOIN product_variants v ON v.id = i.variant_id
     JOIN branches b ON b.id = i.branch_id
     WHERE i.quantity <= i.min_stock
     ORDER BY i.quantity ASC LIMIT 10'
)->fetchAll();
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Tổng quan</h1>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Doanh thu hôm nay</div>
    <div style="font-size:24px;font-weight:700;color:#2563eb;"><?= money($revenueToday) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Đơn hàng mới</div>
    <div style="font-size:24px;font-weight:700;color:#059669;"><?= $newOrdersToday ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Đơn hủy</div>
    <div style="font-size:24px;font-weight:700;color:#dc2626;"><?= $cancelledToday ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Tổng tồn kho (SL)</div>
    <div style="font-size:24px;font-weight:700;"><?= $totalStock ?></div>
  </div>
</div>

<div class="card" style="margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Đơn hàng cần xử lý</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;">
    <?php foreach ($pendingStatusLabels as $status => $label): ?>
      <a href="orders.php?status=<?= e($status) ?>" style="display:block;padding:12px;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:inherit;">
        <div class="muted" style="font-size:12px;margin-bottom:4px;"><?= e($label) ?></div>
        <div style="font-size:20px;font-weight:700;<?= $pendingCounts[$status] > 0 ? 'color:#dc2626;' : '' ?>"><?= $pendingCounts[$status] ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:24px;">
  <div class="card">
    <h2 style="font-size:14px;font-weight:600;margin:0 0 16px;">Doanh thu 7 ngày qua</h2>
    <div style="display:flex;align-items:flex-end;gap:12px;height:140px;">
      <?php foreach ($chartDays as $d => $rev): $h = max(4, (int) round($rev / $maxRevenue * 120)); ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;">
          <div class="muted" style="font-size:11px;margin-bottom:4px;"><?= $rev > 0 ? number_format($rev / 1000, 0) . 'k' : '' ?></div>
          <div style="width:100%;max-width:36px;background:#2563eb;border-radius:4px 4px 0 0;height:<?= $h ?>px;" title="<?= money($rev) ?>"></div>
          <div class="muted" style="font-size:11px;margin-top:4px;"><?= date('d/m', strtotime($d)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Sản phẩm dưới định mức</h2>
    <?php if (!$lowStock): ?>
      <p class="muted" style="font-size:13px;margin:0;">Không có sản phẩm nào dưới định mức.</p>
    <?php else: ?>
      <?php foreach ($lowStock as $it): ?>
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-top:1px solid #f1f5f9;">
          <span><?= e($it['product_name']) ?><?= $it['variant_name'] ? ' (' . e($it['variant_name']) . ')' : '' ?><br><span class="muted" style="font-size:11px;"><?= e($it['branch_name']) ?></span></span>
          <span style="font-weight:600;color:#dc2626;"><?= (int) $it['quantity'] ?>/<?= (int) $it['min_stock'] ?></span>
        </div>
      <?php endforeach; ?>
      <a href="inventory.php" class="muted" style="font-size:12px;display:block;margin-top:8px;">Xem tất cả →</a>
    <?php endif; ?>
  </div>
</div>

<div class="card muted" style="font-size:14px;">
  Xem <a href="pos.php">Bán hàng</a>, <a href="products.php">Sản phẩm</a>,
  <a href="customers.php">Khách hàng</a>, <a href="reports.php">Báo cáo</a> chi tiết hơn.
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
