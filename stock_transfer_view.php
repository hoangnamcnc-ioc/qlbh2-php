<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT t.*, fb.name AS from_branch_name, tb.name AS to_branch_name, u.name AS created_by_name
     FROM stock_transfers t
     JOIN branches fb ON fb.id = t.from_branch_id
     JOIN branches tb ON tb.id = t.to_branch_id
     JOIN users u ON u.id = t.created_by_id
     WHERE t.id = ?'
);
$stmt->execute([$id]);
$transfer = $stmt->fetch();
if (!$transfer) redirect('stock_transfers.php');

$items = $pdo->prepare(
    'SELECT i.*, p.name AS product_name, v.name AS variant_name
     FROM stock_transfer_items i
     JOIN products p ON p.id = i.product_id
     LEFT JOIN product_variants v ON v.id = i.variant_id
     WHERE i.transfer_id = ?'
);
$items->execute([$id]);
$items = $items->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="stock_transfers.php" class="muted" style="font-size:14px;">← Danh sách phiếu chuyển hàng</a>
<h1 style="font-size:24px;font-weight:600;font-family:monospace;margin:8px 0 4px;"><?= e($transfer['code']) ?></h1>
<p class="muted" style="margin:0 0 24px;">
  <?= date('d/m/Y H:i', strtotime($transfer['created_at'])) ?> ·
  <?= e($transfer['from_branch_name']) ?> → <?= e($transfer['to_branch_name']) ?> ·
  <?= e($transfer['created_by_name']) ?>
</p>

<?php if ($transfer['note']): ?><p class="muted">Ghi chú: <?= e($transfer['note']) ?></p><?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">Số lượng</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?><?php if ($it['variant_name']): ?> <span class="muted">(<?= e($it['variant_name']) ?>)</span><?php endif; ?></td>
          <td class="text-right" style="font-weight:600;"><?= (int) $it['quantity'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
