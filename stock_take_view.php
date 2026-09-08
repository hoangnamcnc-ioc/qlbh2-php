<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT t.*, b.name AS branch_name, u.name AS created_by_name
     FROM stock_takes t JOIN branches b ON b.id = t.branch_id JOIN users u ON u.id = t.created_by_id
     WHERE t.id = ?'
);
$stmt->execute([$id]);
$take = $stmt->fetch();
if (!$take) redirect('stock_takes.php');

$items = $pdo->prepare(
    'SELECT i.*, p.name AS product_name, v.name AS variant_name
     FROM stock_take_items i
     JOIN products p ON p.id = i.product_id
     LEFT JOIN product_variants v ON v.id = i.variant_id
     WHERE i.take_id = ?'
);
$items->execute([$id]);
$items = $items->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="stock_takes.php" class="muted" style="font-size:14px;">← Danh sách phiếu kiểm hàng</a>
<h1 style="font-size:24px;font-weight:600;font-family:monospace;margin:8px 0 4px;"><?= e($take['code']) ?></h1>
<p class="muted" style="margin:0 0 24px;"><?= date('d/m/Y H:i', strtotime($take['created_at'])) ?> · <?= e($take['branch_name']) ?> · <?= e($take['created_by_name']) ?></p>

<?php if ($take['note']): ?><p class="muted">Ghi chú: <?= e($take['note']) ?></p><?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">Tồn hệ thống</th><th class="text-right">SL thực tế</th><th class="text-right">Chênh lệch</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): $diff = (int) $it['counted_qty'] - (int) $it['system_qty']; ?>
        <tr>
          <td><?= e($it['product_name']) ?><?php if ($it['variant_name']): ?> <span class="muted">(<?= e($it['variant_name']) ?>)</span><?php endif; ?></td>
          <td class="text-right muted"><?= (int) $it['system_qty'] ?></td>
          <td class="text-right"><?= (int) $it['counted_qty'] ?></td>
          <td class="text-right" style="font-weight:600;<?= $diff == 0 ? '' : ($diff > 0 ? 'color:#059669;' : 'color:#dc2626;') ?>">
            <?= $diff > 0 ? '+' : '' ?><?= $diff ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
