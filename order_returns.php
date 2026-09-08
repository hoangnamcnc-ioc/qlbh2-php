<?php
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$created = $_GET['created'] ?? null;

$returns = $pdo->query(
    'SELECT r.*, o.code AS order_code, c.name AS customer_name
     FROM order_returns r
     JOIN orders o ON o.id = r.order_id
     LEFT JOIN customers c ON c.id = r.customer_id
     ORDER BY r.created_at DESC LIMIT 100'
)->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;">Danh sách đơn trả hàng</h1>
  <a href="order_return_form.php" class="btn">+ Tạo đơn trả hàng</a>
</div>

<?php if ($created): ?>
  <div class="alert alert-success">Đã tạo đơn trả hàng <?= e($created) ?> thành công.</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr><th>Mã đơn trả</th><th>Đơn gốc</th><th>Khách hàng</th><th>Lý do</th><th>Ngày</th><th class="text-right">Hoàn tiền</th></tr>
    </thead>
    <tbody>
      <?php if (!$returns): ?>
        <tr><td colspan="6" class="text-center muted" style="padding:32px;">Chưa có đơn trả hàng nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($returns as $r): ?>
        <tr>
          <td style="font-family:monospace;"><?= e($r['code']) ?></td>
          <td><a href="order_view.php?id=<?= (int) $r['order_id'] ?>" style="font-family:monospace;"><?= e($r['order_code']) ?></a></td>
          <td><?= e($r['customer_name'] ?: 'Khách lẻ') ?></td>
          <td><?= e($r['reason'] ?: '—') ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
          <td class="text-right" style="font-weight:600;color:#dc2626;"><?= money($r['refund_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
