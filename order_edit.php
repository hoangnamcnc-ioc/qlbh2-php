<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) redirect('orders.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $note = post('note') ?: null;
    $discount = postFloat('discount');
    $tags = post('tags') ?: null;

    if ($discount > (float) $order['sub_total']) {
        $error = 'Chiết khấu không được lớn hơn tổng tiền hàng';
    } else {
        $newTotal = (float) $order['sub_total'] - $discount + (float) $order['shipping_fee'];
        $pdo->prepare('UPDATE orders SET note = ?, discount = ?, total_amount = ?, tags = ? WHERE id = ?')
            ->execute([$note, $discount, $newTotal, $tags, $id]);

        $currentUser = currentUser();
        $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, note, changed_by_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $order['status'], $order['status'], 'Sửa đơn hàng (ghi chú/chiết khấu)', $currentUser['id']]);

        redirect('order_view.php?id=' . $id);
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="order_view.php?id=<?= (int) $id ?>" class="muted" style="font-size:14px;">← Đơn hàng <?= e($order['code']) ?></a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Sửa đơn hàng</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<div class="alert alert-warning" style="max-width:480px;">
  Chỉ sửa được ghi chú và chiết khấu. Số tiền đã thanh toán ghi nhận lúc bán <b>không tự động đối
  soát lại</b> — nếu tăng chiết khấu sau khi khách đã thanh toán, bạn cần tự hoàn tiền chênh lệch
  thủ công (vd tạo phiếu chi trong Sổ quỹ).
</div>

<div class="card" style="max-width:480px;margin-top:16px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field"><label>Ghi chú</label><input class="input" name="note" value="<?= e($order['note'] ?? '') ?>"></div>
    <div class="field">
      <label>Chiết khấu (tổng tiền hàng: <?= money($order['sub_total']) ?>)</label>
      <input class="input" type="number" min="0" max="<?= (float) $order['sub_total'] ?>" name="discount" value="<?= e((string) $order['discount']) ?>">
    </div>
    <div class="field"><label>Tags (cách nhau bằng dấu phẩy)</label><input class="input" name="tags" value="<?= e($order['tags'] ?? '') ?>"></div>
    <button type="submit" class="btn">Lưu thay đổi</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
