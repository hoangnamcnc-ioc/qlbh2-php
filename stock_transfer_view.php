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

<?php
$statusLabels = ['IN_TRANSIT' => ['Đang vận chuyển', 'badge-gray'], 'COMPLETED' => ['Đã nhận hàng', 'badge-green'], 'CANCELLED' => ['Đã hủy', 'badge-red']];
[$statusText, $statusClass] = $statusLabels[$transfer['status']] ?? ['—', 'badge-gray'];
?>

<a href="stock_transfers.php" class="muted" style="font-size:14px;">← Danh sách phiếu chuyển hàng</a>
<div style="display:flex;align-items:center;justify-content:space-between;">
  <h1 style="font-size:24px;font-weight:600;font-family:monospace;margin:8px 0 4px;"><?= e($transfer['code']) ?></h1>
  <?php if ($transfer['status'] === 'IN_TRANSIT'): ?>
    <div style="display:flex;gap:8px;">
      <form method="post" action="stock_transfer_receive.php" onsubmit="return confirm('Xác nhận đã nhận đủ hàng tại chi nhánh nhận?');">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="transfer_id" value="<?= (int) $transfer['id'] ?>">
        <input type="hidden" name="action" value="receive">
        <button type="submit" class="btn">Xác nhận đã nhận hàng</button>
      </form>
      <form method="post" action="stock_transfer_receive.php" onsubmit="return confirm('Hủy phiếu chuyển hàng này? Tồn kho sẽ hoàn về chi nhánh chuyển.');">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="transfer_id" value="<?= (int) $transfer['id'] ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-danger">Hủy chuyển hàng</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<p class="muted" style="margin:0 0 8px;">
  <?= date('d/m/Y H:i', strtotime($transfer['created_at'])) ?> ·
  <?= e($transfer['from_branch_name']) ?> → <?= e($transfer['to_branch_name']) ?> ·
  <?= e($transfer['created_by_name']) ?>
</p>
<p style="margin:0 0 24px;"><span class="badge <?= $statusClass ?>"><?= e($statusText) ?></span>
  <?php if ($transfer['status'] === 'COMPLETED' && $transfer['received_at']): ?>
    <span class="muted" style="font-size:13px;"> — nhận lúc <?= date('d/m/Y H:i', strtotime($transfer['received_at'])) ?></span>
  <?php endif; ?>
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
