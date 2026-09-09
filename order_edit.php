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

$channels = $pdo->query('SELECT * FROM sales_channels WHERE is_active = 1 ORDER BY name')->fetchAll();
$sources = $pdo->query('SELECT * FROM order_sources WHERE is_active = 1 ORDER BY name')->fetchAll();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $note = post('note') ?: null;
    $discount = postFloat('discount');
    $tags = post('tags') ?: null;
    $channelId = (int) ($_POST['channel_id'] ?? 0) ?: null;
    $sourceId = (int) ($_POST['source_id'] ?? 0) ?: null;
    $externalCode = post('external_order_code') ?: null;

    if ($discount > (float) $order['sub_total']) {
        $error = 'Chiết khấu không được lớn hơn tổng tiền hàng';
    } else {
        $newTotal = (float) $order['sub_total'] - $discount + (float) $order['shipping_fee'];
        $pdo->prepare('UPDATE orders SET note = ?, discount = ?, total_amount = ?, tags = ?, channel_id = ?, source_id = ?, external_order_code = ? WHERE id = ?')
            ->execute([$note, $discount, $newTotal, $tags, $channelId, $sourceId, $externalCode, $id]);

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
    <div class="grid-2">
      <div class="field">
        <label>Kênh bán hàng (<a href="channels.php" class="muted">quản lý</a>)</label>
        <select class="input" name="channel_id">
          <option value="">— Không chọn (bán trực tiếp) —</option>
          <?php foreach ($channels as $ch): ?>
            <option value="<?= (int) $ch['id'] ?>" <?= (int) ($order['channel_id'] ?? 0) === (int) $ch['id'] ? 'selected' : '' ?>><?= e($ch['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Mã đơn trên kênh (nếu có)</label>
        <input class="input" name="external_order_code" value="<?= e($order['external_order_code'] ?? '') ?>" placeholder="vd: mã đơn Shopee">
      </div>
    </div>
    <div class="field">
      <label>Nguồn bán hàng (<a href="order_sources.php" class="muted">quản lý</a>)</label>
      <select class="input" name="source_id">
        <option value="">— Không chọn —</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (int) ($order['source_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn">Lưu thay đổi</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
