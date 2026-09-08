<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

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
    'SELECT ri.*, p.name AS product_name FROM stock_receipt_items ri JOIN products p ON p.id = ri.product_id WHERE ri.receipt_id = ?'
);
$items->execute([$id]);
$items = $items->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="stock_receipts.php" class="muted" style="font-size:14px;">← Danh sách phiếu nhập</a>
<h1 style="font-size:24px;font-weight:600;font-family:monospace;margin:8px 0 4px;"><?= e($receipt['code']) ?></h1>
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
    <?php if ($receipt['note']): ?><p style="margin:2px 0;">Ghi chú: <?= e($receipt['note']) ?></p><?php endif; ?>
  </div>
</div>

<div class="card" style="padding:0;overflow-x:auto;margin-bottom:16px;">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">SL</th><th class="text-right">Giá vốn</th><th class="text-right">Thành tiền</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?></td>
          <td class="text-right"><?= (int) $it['quantity'] ?></td>
          <td class="text-right"><?= money($it['cost_price']) ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($it['quantity'] * $it['cost_price']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div style="max-width:400px;margin-left:auto;font-size:16px;font-weight:700;text-align:right;color:#2563eb;">
  Tổng tiền: <?= money($receipt['total_amount']) ?>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
