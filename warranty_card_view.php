<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT w.*, p.name AS product_name, c.name AS customer_name, c.phone AS customer_phone,
            pol.name AS policy_name, oi.order_id, o.code AS order_code
     FROM warranty_cards w
     JOIN products p ON p.id = w.product_id
     LEFT JOIN customers c ON c.id = w.customer_id
     LEFT JOIN warranty_policies pol ON pol.id = w.policy_id
     JOIN order_items oi ON oi.id = w.order_item_id
     JOIN orders o ON o.id = oi.order_id
     WHERE w.id = ?'
);
$stmt->execute([$id]);
$card = $stmt->fetch();
if (!$card) redirect('warranty_cards.php');

$claims = $pdo->prepare('SELECT * FROM warranty_claims WHERE warranty_card_id = ? ORDER BY created_at DESC');
$claims->execute([$id]);
$claims = $claims->fetchAll();

$claimStatusLabels = ['PENDING' => 'Chờ xử lý', 'PROCESSING' => 'Đang xử lý', 'DONE' => 'Hoàn thành', 'REJECTED' => 'Từ chối'];

require_once __DIR__ . '/inc_header.php';
?>

<a href="warranty_cards.php" class="muted" style="font-size:14px;">← Danh sách phiếu bảo hành</a>
<h1 style="font-size:24px;font-weight:600;font-family:monospace;margin:8px 0 4px;"><?= e($card['code']) ?></h1>
<p class="muted" style="margin:0 0 24px;">Từ <?= date('d/m/Y', strtotime($card['start_date'])) ?> đến <?= date('d/m/Y', strtotime($card['end_date'])) ?></p>

<div class="grid-2" style="margin-bottom:24px;">
  <div class="card">
    <h2 style="font-size:14px;font-weight:600;margin:0 0 8px;">Thông tin sản phẩm</h2>
    <p style="margin:2px 0;"><?= e($card['product_name']) ?></p>
    <p class="muted" style="margin:2px 0;">Chính sách: <?= e($card['policy_name'] ?: 'Mặc định') ?></p>
    <a href="order_view.php?id=<?= (int) $card['order_id'] ?>" class="muted" style="font-size:13px;">Xem đơn gốc <?= e($card['order_code']) ?> →</a>
  </div>
  <div class="card">
    <h2 style="font-size:14px;font-weight:600;margin:0 0 8px;">Khách hàng</h2>
    <p style="margin:2px 0;"><?= e($card['customer_name'] ?: 'Khách lẻ') ?></p>
    <?php if ($card['customer_phone']): ?><p class="muted" style="margin:2px 0;"><?= e($card['customer_phone']) ?></p><?php endif; ?>
  </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
  <h2 style="font-size:18px;font-weight:600;margin:0;">Yêu cầu bảo hành</h2>
  <a href="warranty_claim_form.php?card_id=<?= (int) $card['id'] ?>" class="btn btn-secondary">+ Tạo yêu cầu</a>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã yêu cầu</th><th>Mô tả lỗi</th><th>Trạng thái</th><th>Ngày tạo</th></tr></thead>
    <tbody>
      <?php if (!$claims): ?>
        <tr><td colspan="4" class="text-center muted" style="padding:24px;">Chưa có yêu cầu bảo hành nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($claims as $c): ?>
        <tr>
          <td><a href="warranty_claim_view.php?id=<?= (int) $c['id'] ?>" style="font-family:monospace;"><?= e($c['code']) ?></a></td>
          <td><?= e($c['issue_description']) ?></td>
          <td><span class="badge badge-gray"><?= e($claimStatusLabels[$c['status']] ?? $c['status']) ?></span></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
