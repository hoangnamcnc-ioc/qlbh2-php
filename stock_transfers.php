<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$transfers = $pdo->query(
    'SELECT t.*, fb.name AS from_branch_name, tb.name AS to_branch_name, u.name AS created_by_name
     FROM stock_transfers t
     JOIN branches fb ON fb.id = t.from_branch_id
     JOIN branches tb ON tb.id = t.to_branch_id
     JOIN users u ON u.id = t.created_by_id
     ORDER BY t.created_at DESC LIMIT 100'
)->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;">Danh sách phiếu chuyển hàng</h1>
  <a href="stock_transfer_form.php" class="btn">+ Tạo phiếu chuyển hàng</a>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã phiếu</th><th>Từ</th><th>Đến</th><th>Người tạo</th><th>Ngày</th></tr></thead>
    <tbody>
      <?php if (!$transfers): ?>
        <tr><td colspan="5" class="text-center muted" style="padding:32px;">Chưa có phiếu chuyển hàng nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($transfers as $t): ?>
        <tr>
          <td><a href="stock_transfer_view.php?id=<?= (int) $t['id'] ?>" style="font-family:monospace;"><?= e($t['code']) ?></a></td>
          <td><?= e($t['from_branch_name']) ?></td>
          <td><?= e($t['to_branch_name']) ?></td>
          <td><?= e($t['created_by_name']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
