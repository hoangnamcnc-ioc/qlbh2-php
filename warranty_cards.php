<?php
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$created = $_GET['created'] ?? null;
$cards = $pdo->query(
    'SELECT w.*, p.name AS product_name, c.name AS customer_name, pol.name AS policy_name
     FROM warranty_cards w
     JOIN products p ON p.id = w.product_id
     LEFT JOIN customers c ON c.id = w.customer_id
     LEFT JOIN warranty_policies pol ON pol.id = w.policy_id
     ORDER BY w.created_at DESC LIMIT 100'
)->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;">Phiếu bảo hành</h1>
  <a href="warranty_card_form.php" class="btn">+ Tạo phiếu bảo hành</a>
</div>

<?php if ($created): ?><div class="alert alert-success">Đã tạo phiếu bảo hành <?= e($created) ?> thành công.</div><?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã phiếu</th><th>Sản phẩm</th><th>Khách hàng</th><th>Chính sách</th><th>Từ ngày</th><th>Đến ngày</th></tr></thead>
    <tbody>
      <?php if (!$cards): ?>
        <tr><td colspan="6" class="text-center muted" style="padding:32px;">Chưa có phiếu bảo hành nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($cards as $c): $expired = strtotime($c['end_date']) < time(); ?>
        <tr>
          <td><a href="warranty_card_view.php?id=<?= (int) $c['id'] ?>" style="font-family:monospace;"><?= e($c['code']) ?></a></td>
          <td><?= e($c['product_name']) ?></td>
          <td><?= e($c['customer_name'] ?: 'Khách lẻ') ?></td>
          <td><?= e($c['policy_name'] ?: 'Mặc định') ?></td>
          <td class="muted"><?= date('d/m/Y', strtotime($c['start_date'])) ?></td>
          <td>
            <?= date('d/m/Y', strtotime($c['end_date'])) ?>
            <?php if ($expired): ?><span class="badge badge-red">Hết hạn</span><?php else: ?><span class="badge badge-green">Còn hạn</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
