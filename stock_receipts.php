<?php
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$receipts = $pdo->query(
    'SELECT r.*, s.name AS supplier_name, u.name AS created_by_name
     FROM stock_receipts r
     LEFT JOIN suppliers s ON s.id = r.supplier_id
     JOIN users u ON u.id = r.created_by_id
     ORDER BY r.created_at DESC LIMIT 100'
)->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;">Danh sách phiếu nhập hàng</h1>
  <a href="stock_receipt_form.php" class="btn">+ Tạo phiếu nhập</a>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã phiếu</th><th>Nhà cung cấp</th><th>Người tạo</th><th>Ngày</th><th class="text-right">Tổng tiền</th></tr></thead>
    <tbody>
      <?php if (!$receipts): ?>
        <tr><td colspan="5" class="text-center muted" style="padding:32px;">Chưa có phiếu nhập nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($receipts as $r): ?>
        <tr>
          <td><a href="stock_receipt_view.php?id=<?= (int) $r['id'] ?>" style="font-family:monospace;"><?= e($r['code']) ?></a></td>
          <td><?= e($r['supplier_name'] ?: '—') ?></td>
          <td><?= e($r['created_by_name']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($r['total_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
