<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT r.*, s.name AS supplier_name, b.name AS branch_name, u.name AS created_by_name
     FROM stock_receipts r
     LEFT JOIN suppliers s ON s.id = r.supplier_id
     JOIN branches b ON b.id = r.branch_id
     JOIN users u ON u.id = r.created_by_id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$receipt = $stmt->fetch();
if (!$receipt) redirect('stock_receipts.php');

$items = $pdo->prepare(
    'SELECT ri.*, p.name AS product_name, v.name AS variant_name
     FROM stock_receipt_items ri
     JOIN products p ON p.id = ri.product_id
     LEFT JOIN product_variants v ON v.id = ri.variant_id
     WHERE ri.receipt_id = ?'
);
$items->execute([$id]);
$items = $items->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="stock_receipts.php" class="muted" style="font-size:14px;">← Danh sách phiếu nhập</a>
<div style="display:flex;align-items:center;justify-content:space-between;">
  <h1 style="font-size:24px;font-weight:600;font-family:monospace;margin:8px 0 4px;"><?= e($receipt['code']) ?></h1>
  <?php if ($receipt['supplier_id'] && hasRole('ADMIN', 'MANAGER')): ?>
    <a href="supplier_return_form.php?q=<?= urlencode($receipt['code']) ?>" class="btn btn-secondary">Trả hàng NCC</a>
  <?php endif; ?>
</div>
<p class="muted" style="margin:0 0 24px;"><?= date('d/m/Y H:i', strtotime($receipt['created_at'])) ?></p>

<div class="grid-2" style="margin-bottom:24px;">
  <div class="card">
    <p style="margin:2px 0;">Nhà cung cấp: <?= e($receipt['supplier_name'] ?: '—') ?></p>
    <?php if ($receipt['supplier_id']): ?>
      <a href="supplier_view.php?id=<?= (int) $receipt['supplier_id'] ?>" class="muted" style="font-size:13px;">Xem nhà cung cấp →</a>
    <?php endif; ?>
  </div>
  <div class="card">
    <p style="margin:2px 0;">Nhập tại: <?= e($receipt['branch_name']) ?></p>
    <p style="margin:2px 0;">Người tạo: <?= e($receipt['created_by_name']) ?></p>
    <?php if ($receipt['invoice_date']): ?><p style="margin:2px 0;">Ngày hoá đơn: <?= date('d/m/Y', strtotime($receipt['invoice_date'])) ?></p><?php endif; ?>
    <?php if ($receipt['reference_no']): ?><p style="margin:2px 0;">Tham chiếu: <?= e($receipt['reference_no']) ?></p><?php endif; ?>
    <?php if ($receipt['note']): ?><p style="margin:2px 0;">Ghi chú: <?= e($receipt['note']) ?></p><?php endif; ?>
  </div>
</div>

<?php $remaining = (float) $receipt['total_amount'] - (float) $receipt['paid_amount']; ?>
<?php if ($receipt['supplier_id']): ?>
<div class="card" style="max-width:480px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thanh toán NCC</h2>
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;"><span>Tiền cần trả NCC</span><span><?= money($receipt['total_amount']) ?></span></div>
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;color:#059669;"><span>Đã trả</span><span><?= money($receipt['paid_amount']) ?></span></div>
  <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:8px;margin-bottom:12px;font-weight:600;<?= $remaining > 0 ? 'color:#dc2626;' : '' ?>"><span>Còn phải trả</span><span><?= money($remaining) ?></span></div>
  <?php if ($remaining > 0): ?>
    <form method="post" action="stock_receipt_pay.php" style="display:flex;gap:8px;align-items:end;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="receipt_id" value="<?= (int) $receipt['id'] ?>">
      <div class="field" style="flex:1;margin:0;">
        <label>Số tiền trả thêm</label>
        <input class="input" type="number" min="1" max="<?= (float) $remaining ?>" name="amount" required>
      </div>
      <button type="submit" class="btn">Ghi nhận trả</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto;margin-bottom:16px;">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">SL</th><th class="text-right">Giá vốn</th><th class="text-right">Thành tiền</th></tr></thead>
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

<div style="max-width:400px;margin-left:auto;">
  <?php $subTotal = array_sum(array_map(fn($i) => $i['quantity'] * $i['cost_price'], $items)); ?>
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;"><span>Tạm tính</span><span><?= money($subTotal) ?></span></div>
  <?php if ($receipt['discount_amount'] > 0): ?><div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;color:#dc2626;"><span>Chiết khấu</span><span>-<?= money($receipt['discount_amount']) ?></span></div><?php endif; ?>
  <?php if ($receipt['extra_cost'] > 0): ?><div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;"><span>Chi phí nhập hàng</span><span>+<?= money($receipt['extra_cost']) ?></span></div><?php endif; ?>
  <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:8px;font-size:16px;font-weight:700;color:#2563eb;"><span>Tổng tiền</span><span><?= money($receipt['total_amount']) ?></span></div>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
