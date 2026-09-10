<?php
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$returns = $pdo->query(
    "SELECT sr.*, s.name AS supplier_name, u.name AS created_by_name
     FROM supplier_returns sr
     JOIN suppliers s ON s.id = sr.supplier_id
     JOIN users u ON u.id = sr.created_by_id
     ORDER BY sr.created_at DESC LIMIT 100"
)->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
  <h1 style="font-size:24px;font-weight:600;">Trả hàng nhà cung cấp</h1>
  <a href="supplier_return_form.php" class="btn">+ Tạo trả hàng NCC</a>
</div>

<?php if (isset($_GET['created'])): ?>
  <div class="alert alert-success">Đã tạo trả hàng NCC <?= e($_GET['created']) ?> thành công.</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã trả hàng</th><th>Nhà cung cấp</th><th>Phiếu nhập gốc</th><th>Lý do</th><th>Người tạo</th><th>Ngày</th><th class="text-right">Giá trị hoàn</th></tr></thead>
    <tbody>
      <?php if (!$returns): ?>
        <tr><td colspan="7" class="text-center muted" style="padding:32px;">Chưa có trả hàng nhà cung cấp nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($returns as $r): ?>
        <tr>
          <td style="font-family:monospace;"><?= e($r['code']) ?></td>
          <td><?= e($r['supplier_name']) ?></td>
          <td>
            <?php if ($r['receipt_id']): ?>
              <a href="stock_receipt_view.php?id=<?= (int) $r['receipt_id'] ?>" style="font-family:monospace;">#<?= (int) $r['receipt_id'] ?></a>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td class="muted"><?= e($r['reason'] ?: '—') ?></td>
          <td class="muted"><?= e($r['created_by_name']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
          <td class="text-right" style="font-weight:600;color:#dc2626;"><?= money($r['refund_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
