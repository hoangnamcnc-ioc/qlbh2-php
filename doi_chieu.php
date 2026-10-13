<?php
/**
 * Doi chieu so lieu: tu dong tim cho SO SACH KHONG KHOP nhau (cong no khach, tien thu tung don,
 * ton kho am, don huy chua hoan het tien, ca ban hang lech tien) de Quan ly xu ly som thay vi
 * den cuoi thang moi biet. Chi doc du lieu, khong sua gi.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();

// 1) Cong no khach: customers.debt phai bang tong cac dong customer_debt_entries cua khach do.
$stmt = $pdo->prepare(
    'SELECT c.id, c.name, c.phone, c.debt,
            COALESCE((SELECT SUM(e.amount) FROM customer_debt_entries e WHERE e.customer_id = c.id), 0) AS so_chi_tiet
     FROM customers c WHERE c.tenant_id = ?
     HAVING ABS(c.debt - so_chi_tiet) > 0.5
     ORDER BY ABS(c.debt - so_chi_tiet) DESC LIMIT 100'
);
$stmt->execute([$tenantId]);
$debtMismatch = $stmt->fetchAll();

// 2) Tien da thu cua don: orders.paid_amount phai bang thu - chi (so quy) gan voi don do.
$stmt = $pdo->prepare(
    "SELECT o.id, o.code, o.status, o.paid_amount,
            COALESCE((SELECT SUM(ce.amount) FROM cashbook_entries ce WHERE ce.order_id = o.id AND ce.type = 'RECEIPT'), 0) AS so_quy
     FROM orders o JOIN branches b ON b.id = o.branch_id
     WHERE b.tenant_id = ? AND o.status NOT IN ('CANCELLED','DRAFT')
     HAVING ABS(o.paid_amount - so_quy) > 0.5
     ORDER BY o.id DESC LIMIT 100"
);
$stmt->execute([$tenantId]);
$paidMismatch = $stmt->fetchAll();

// 3) Don da huy nhung so quy van con tien (thu chua duoc hoan).
$stmt = $pdo->prepare(
    "SELECT o.id, o.code,
            COALESCE((SELECT SUM(CASE WHEN ce.type = 'RECEIPT' THEN ce.amount ELSE -ce.amount END)
                      FROM cashbook_entries ce WHERE ce.order_id = o.id), 0) AS so_quy
     FROM orders o JOIN branches b ON b.id = o.branch_id
     WHERE b.tenant_id = ? AND o.status = 'CANCELLED'
     HAVING ABS(so_quy) > 0.5
     ORDER BY o.id DESC LIMIT 100"
);
$stmt->execute([$tenantId]);
$cancelMismatch = $stmt->fetchAll();

// 4) Ton kho am.
$stmt = $pdo->prepare(
    'SELECT p.name, p.sku, b.name AS branch_name, i.quantity
     FROM inventory i JOIN branches b ON b.id = i.branch_id JOIN products p ON p.id = i.product_id
     WHERE b.tenant_id = ? AND i.quantity < 0 ORDER BY i.quantity LIMIT 100'
);
$stmt->execute([$tenantId]);
$negativeStock = $stmt->fetchAll();

// 5) Ca ban hang da dong nhung lech tien trong 30 ngay gan day.
$stmt = $pdo->prepare(
    "SELECT s.id, s.opened_at, s.closed_at, s.expected_cash, s.counted_cash, s.difference, b.name AS branch_name
     FROM pos_shifts s JOIN branches b ON b.id = s.branch_id
     WHERE b.tenant_id = ? AND s.status = 'CLOSED' AND ABS(s.difference) > 0.5 AND s.closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     ORDER BY s.closed_at DESC LIMIT 100"
);
$stmt->execute([$tenantId]);
$shiftDiffs = $stmt->fetchAll();

$totalIssues = count($debtMismatch) + count($paidMismatch) + count($cancelMismatch) + count($negativeStock) + count($shiftDiffs);

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:4px;">Đối chiếu số liệu</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:760px;">
  Tự động tìm những chỗ sổ sách không khớp nhau. Trang chỉ xem, không sửa dữ liệu. Đơn bán từ trước
  khi hệ thống ghi nhận sổ quỹ theo từng đơn có thể hiện lệch do lịch sử — hãy xử lý các dòng mới phát sinh trước.
</p>

<?php if ($totalIssues === 0): ?>
  <div class="alert alert-success">Không phát hiện chỗ nào lệch. Công nợ, tiền thu từng đơn, tồn kho và các ca bán hàng đều khớp.</div>
<?php else: ?>
  <div class="alert alert-error">Phát hiện <b><?= (int) $totalIssues ?></b> mục cần kiểm tra (xem các bảng bên dưới).</div>
<?php endif; ?>

<?php
$section = static function (string $title, string $hint, int $count): void {
    echo '<h2 style="font-size:16px;margin:24px 0 4px;">' . e($title) . ' <span class="muted" style="font-weight:400;">(' . $count . ')</span></h2>';
    echo '<p class="muted" style="margin:0 0 8px;font-size:12.5px;">' . e($hint) . '</p>';
};
?>

<?php $section('Công nợ khách không khớp chi tiết', 'Nợ ghi trên hồ sơ khách khác tổng các dòng lịch sử công nợ.', count($debtMismatch)); ?>
<?php if ($debtMismatch): ?>
<div class="card" style="padding:0;overflow-x:auto;"><table>
  <thead><tr><th>Khách</th><th>SĐT</th><th class="text-right">Nợ trên hồ sơ</th><th class="text-right">Tổng chi tiết</th><th class="text-right">Lệch</th></tr></thead>
  <tbody><?php foreach ($debtMismatch as $r): ?>
    <tr>
      <td><a href="customer_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
      <td><?= e((string) $r['phone']) ?></td>
      <td class="text-right"><?= money($r['debt']) ?></td>
      <td class="text-right"><?= money($r['so_chi_tiet']) ?></td>
      <td class="text-right" style="color:#dc2626;"><?= money((float) $r['debt'] - (float) $r['so_chi_tiet']) ?></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php $section('Tiền thu của đơn không khớp sổ quỹ', 'Số "đã thanh toán" trên đơn khác tổng các phiếu THU trong sổ quỹ gắn với đơn đó (tiền hoàn do trả hàng không tính).', count($paidMismatch)); ?>
<?php if ($paidMismatch): ?>
<div class="card" style="padding:0;overflow-x:auto;"><table>
  <thead><tr><th>Đơn</th><th>Trạng thái</th><th class="text-right">Đã thanh toán trên đơn</th><th class="text-right">Tổng phiếu thu</th></tr></thead>
  <tbody><?php foreach ($paidMismatch as $r): ?>
    <tr>
      <td><a href="order_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['code']) ?></a></td>
      <td><?= e($r['status']) ?></td>
      <td class="text-right"><?= money($r['paid_amount']) ?></td>
      <td class="text-right"><?= money($r['so_quy']) ?></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php $section('Đơn đã hủy nhưng sổ quỹ còn tiền', 'Đơn hủy phải có phiếu chi hoàn đủ số đã thu.', count($cancelMismatch)); ?>
<?php if ($cancelMismatch): ?>
<div class="card" style="padding:0;overflow-x:auto;"><table>
  <thead><tr><th>Đơn</th><th class="text-right">Còn trong sổ quỹ</th></tr></thead>
  <tbody><?php foreach ($cancelMismatch as $r): ?>
    <tr>
      <td><a href="order_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['code']) ?></a></td>
      <td class="text-right" style="color:#dc2626;"><?= money($r['so_quy']) ?></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php $section('Tồn kho âm', 'Bán nhiều hơn số đã nhập — thường do chưa nhập hàng kịp hoặc bật "cho phép bán khi hết hàng".', count($negativeStock)); ?>
<?php if ($negativeStock): ?>
<div class="card" style="padding:0;overflow-x:auto;"><table>
  <thead><tr><th>Sản phẩm</th><th>Mã</th><th>Chi nhánh</th><th class="text-right">Tồn</th></tr></thead>
  <tbody><?php foreach ($negativeStock as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td><td><?= e($r['sku']) ?></td><td><?= e($r['branch_name']) ?></td>
      <td class="text-right" style="color:#dc2626;"><?= e(fmtQty($r['quantity'])) ?></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php $section('Ca bán hàng lệch tiền (30 ngày gần đây)', 'Tiền đếm thực tế khi đóng ca khác tiền mặt dự kiến.', count($shiftDiffs)); ?>
<?php if ($shiftDiffs): ?>
<div class="card" style="padding:0;overflow-x:auto;"><table>
  <thead><tr><th>Chi nhánh</th><th>Đóng ca lúc</th><th class="text-right">Dự kiến</th><th class="text-right">Đếm thực tế</th><th class="text-right">Lệch</th></tr></thead>
  <tbody><?php foreach ($shiftDiffs as $r): ?>
    <tr>
      <td><?= e($r['branch_name']) ?></td>
      <td><?= e(date('d/m/Y H:i', strtotime($r['closed_at']))) ?></td>
      <td class="text-right"><?= money($r['expected_cash']) ?></td>
      <td class="text-right"><?= money($r['counted_cash']) ?></td>
      <td class="text-right" style="color:#dc2626;"><?= money($r['difference']) ?></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<p class="muted" style="font-size:12px;margin:14px 0 0;max-width:820px;">⚖️ Số liệu trên trang này được tính từ dữ liệu bạn nhập theo cách tính mặc định của phần mềm, chỉ mang tính <b>tham khảo</b> và không thay thế tư vấn của kế toán/cơ quan thuế. Xem <a href="dieu_khoan.php" target="_blank" rel="noopener">Điều khoản sử dụng</a>.</p>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
