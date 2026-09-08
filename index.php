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

<div class="card muted" style="font-size:14px;">
  Khung dashboard MVP. Xem <a href="pos.php">Bán hàng</a>, <a href="products.php">Sản phẩm</a>,
  <a href="customers.php">Khách hàng</a> để bắt đầu.
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
