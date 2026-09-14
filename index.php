<?php
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$today = date('Y-m-d 00:00:00');
$tenantId = currentTenantId();
$branchesStmt = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? ORDER BY name');
$branchesStmt->execute([$tenantId]);
$branches = $branchesStmt->fetchAll();

// CASHIER chỉ xem được số liệu của chi nhánh mình, giống các trang Đơn hàng/Vận chuyển/Tồn
// kho đã chặn theo chi nhánh — tránh lộ doanh thu/tồn kho toàn chuỗi cho nhân viên cấp thấp.
// ADMIN/MANAGER được chọn xem "Tất cả chi nhánh" hoặc lọc theo 1 chi nhánh cụ thể (giống Sapo).
if (hasRole('ADMIN', 'MANAGER')) {
    $branchId = (int) ($_GET['branch_id'] ?? 0);
    // Nếu chọn 1 chi nhánh cụ thể, xác nhận chi nhánh đó thực sự thuộc tenant hiện tại -
    // tránh truyền branch_id của tenant khác qua URL để xem lẫn số liệu.
    if ($branchId && !in_array($branchId, array_column($branches, 'id'), true)) {
        $branchId = 0;
    }
} else {
    $branchId = effectiveBranchId($currentUser);
}
// "Tất cả chi nhánh" (branchId=0) vẫn phải giới hạn trong đúng các chi nhánh của tenant hiện
// tại — không được lộ số liệu tổng hợp của tenant khác.
if ($branchId) {
    $branchWhere = ' AND o.branch_id = ?';
    $branchParam = [$branchId];
} else {
    $branchWhere = ' AND o.branch_id IN (SELECT id FROM branches WHERE tenant_id = ?)';
    $branchParam = [$tenantId];
}

$revenueToday = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount),0) AS total FROM orders o WHERE created_at >= ? AND status != 'CANCELLED'$branchWhere"
);
$revenueToday->execute([$today, ...$branchParam]);
$revenueToday = (float) $revenueToday->fetch()['total'];

$newOrdersToday = $pdo->prepare("SELECT COUNT(*) AS c FROM orders o WHERE created_at >= ?$branchWhere");
$newOrdersToday->execute([$today, ...$branchParam]);
$newOrdersToday = (int) $newOrdersToday->fetch()['c'];

$cancelledToday = $pdo->prepare("SELECT COUNT(*) AS c FROM orders o WHERE created_at >= ? AND status = 'CANCELLED'$branchWhere");
$cancelledToday->execute([$today, ...$branchParam]);
$cancelledToday = (int) $cancelledToday->fetch()['c'];

$returnedToday = $pdo->prepare(
    "SELECT COUNT(*) AS c FROM order_returns r JOIN orders o ON o.id = r.order_id WHERE r.created_at >= ?$branchWhere"
);
$returnedToday->execute([$today, ...$branchParam]);
$returnedToday = (int) $returnedToday->fetch()['c'];

if ($branchId) {
    $stockWhere = ' WHERE i.branch_id = ?';
    $stockParam = [$branchId];
} else {
    $stockWhere = ' WHERE i.branch_id IN (SELECT id FROM branches WHERE tenant_id = ?)';
    $stockParam = [$tenantId];
}
$stockStmt = $pdo->prepare("SELECT COALESCE(SUM(i.quantity),0) AS qty, COALESCE(SUM(i.quantity * p.cost_price),0) AS val FROM inventory i JOIN products p ON p.id = i.product_id$stockWhere");
$stockStmt->execute($stockParam);
$stockRow = $stockStmt->fetch();
$totalStock = fmtQty($stockRow['qty']);
$totalStockValue = (float) $stockRow['val'];

// Doanh thu 7 ngày qua (theo ngày) để vẽ biểu đồ
$from7 = date('Y-m-d 00:00:00', strtotime('-6 days'));
$stmt = $pdo->prepare(
    "SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS total
     FROM orders o WHERE created_at >= ? AND status != 'CANCELLED'$branchWhere
     GROUP BY DATE(created_at)"
);
$stmt->execute([$from7, ...$branchParam]);
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
$stmt = $pdo->prepare(
    "SELECT status, COUNT(*) AS c FROM orders o WHERE status IN ('DRAFT','APPROVED','PACKED','SHIPPED')$branchWhere GROUP BY status"
);
$stmt->execute($branchParam);
$pendingCounts = array_fill_keys(array_keys($pendingStatusLabels), 0);
foreach ($stmt->fetchAll() as $r) {
    $pendingCounts[$r['status']] = (int) $r['c'];
}

// Chờ thanh toán: đơn đã hoàn thành nhưng khách còn nợ tiền — Sapo tách riêng ô này khỏi
// pipeline giao hàng vì đây là việc "cần xử lý" ở khâu công nợ, không phải khâu kho/vận chuyển.
$awaitingPaymentStmt = $pdo->prepare(
    "SELECT COUNT(*) AS c FROM orders o WHERE payment_status != 'PAID' AND status NOT IN ('CANCELLED','DRAFT')$branchWhere"
);
$awaitingPaymentStmt->execute($branchParam);
$awaitingPayment = (int) $awaitingPaymentStmt->fetch()['c'];

// Top sản phẩm bán chạy 7 ngày qua (theo doanh thu)
$topProductsStmt = $pdo->prepare(
    "SELECT p.name, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     JOIN products p ON p.id = oi.product_id
     WHERE o.created_at >= ? AND o.status != 'CANCELLED'$branchWhere
     GROUP BY oi.product_id, p.name
     ORDER BY revenue DESC LIMIT 5"
);
$topProductsStmt->execute([$from7, ...$branchParam]);
$topProducts = $topProductsStmt->fetchAll();

// Sản phẩm dưới định mức
if ($branchId) {
    $lowStockWhere = ' AND i.branch_id = ?';
    $lowStockParam = [$branchId];
} else {
    $lowStockWhere = ' AND b.tenant_id = ?';
    $lowStockParam = [$tenantId];
}
$lowStockStmt = $pdo->prepare(
    "SELECT i.quantity, i.min_stock, p.name AS product_name, v.name AS variant_name, b.name AS branch_name
     FROM inventory i
     JOIN products p ON p.id = i.product_id
     LEFT JOIN product_variants v ON v.id = i.variant_id
     JOIN branches b ON b.id = i.branch_id
     WHERE i.quantity <= i.min_stock$lowStockWhere
     ORDER BY i.quantity ASC LIMIT 10"
);
$lowStockStmt->execute($lowStockParam);
$lowStock = $lowStockStmt->fetchAll();
?>

<?php if (isset($_GET['welcome'])): ?>
  <div class="alert alert-success" style="margin-bottom:16px;">
    🎉 Chào mừng bạn đến với QLBH2! Tài khoản dùng thử 14 ngày đã sẵn sàng — bắt đầu bằng cách thêm
    chi nhánh, sản phẩm và bán hàng thử ngay.
  </div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">Tổng quan</h1>
  <?php if (hasRole('ADMIN', 'MANAGER')): ?>
    <form method="get">
      <select name="branch_id" class="input" style="width:auto;" onchange="this.form.submit()">
        <option value="0">Tất cả chi nhánh</option>
        <?php foreach ($branches as $b): ?>
          <option value="<?= (int) $b['id'] ?>" <?= $branchId === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>
</div>

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
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Đơn trả hàng</div>
    <div style="font-size:24px;font-weight:700;color:#d97706;"><?= $returnedToday ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Đơn hủy</div>
    <div style="font-size:24px;font-weight:700;color:#dc2626;"><?= $cancelledToday ?></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Số tồn kho<?= $branchId ? '' : ' (tất cả CN)' ?></div>
    <div style="font-size:24px;font-weight:700;"><?= $totalStock ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Giá trị tồn kho</div>
    <div style="font-size:24px;font-weight:700;"><?= money($totalStockValue) ?></div>
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
    <a href="orders.php" style="display:block;padding:12px;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:inherit;">
      <div class="muted" style="font-size:12px;margin-bottom:4px;">Chờ thanh toán</div>
      <div style="font-size:20px;font-weight:700;<?= $awaitingPayment > 0 ? 'color:#dc2626;' : '' ?>"><?= $awaitingPayment ?></div>
    </a>
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
          <span style="font-weight:600;color:#dc2626;"><?= fmtQty($it['quantity']) ?>/<?= (int) $it['min_stock'] ?></span>
        </div>
      <?php endforeach; ?>
      <a href="inventory.php" class="muted" style="font-size:12px;display:block;margin-top:8px;">Xem tất cả →</a>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Top sản phẩm bán chạy (7 ngày qua)</h2>
  <?php if (!$topProducts): ?>
    <p class="muted" style="font-size:13px;margin:0;">Chưa có dữ liệu bán hàng.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Sản phẩm</th><th class="text-right">Số lượng bán</th><th class="text-right">Doanh thu</th></tr></thead>
      <tbody>
        <?php foreach ($topProducts as $tp): ?>
          <tr>
            <td><?= e($tp['name']) ?></td>
            <td class="text-right"><?= fmtQty($tp['qty']) ?></td>
            <td class="text-right"><?= money($tp['revenue']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card muted" style="font-size:14px;">
  Xem <a href="pos.php">Bán hàng</a>, <a href="products.php">Sản phẩm</a>,
  <a href="customers.php">Khách hàng</a>, <a href="reports.php">Báo cáo</a> chi tiết hơn.
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
