<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT o.* FROM orders o JOIN branches b ON b.id = o.branch_id WHERE o.id = ? AND b.tenant_id = ?'
);
$stmt->execute([$id, $tenantId]);
$order = $stmt->fetch();
if (!$order) redirect('orders.php');

$channelsStmt = $pdo->prepare('SELECT * FROM sales_channels WHERE is_active = 1 AND tenant_id = ? ORDER BY name');
$channelsStmt->execute([$tenantId]);
$channels = $channelsStmt->fetchAll();
$sourcesStmt = $pdo->prepare('SELECT * FROM order_sources WHERE is_active = 1 AND tenant_id = ? ORDER BY name');
$sourcesStmt->execute([$tenantId]);
$sources = $sourcesStmt->fetchAll();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $note = post('note') ?: null;
    $discount = postFloat('discount');
    $tags = post('tags') ?: null;
    $channelId = (int) ($_POST['channel_id'] ?? 0) ?: null;
    $sourceId = (int) ($_POST['source_id'] ?? 0) ?: null;
    $externalCode = post('external_order_code') ?: null;

    if ($order['status'] === 'CANCELLED') {
        $error = 'Đơn đã hủy không thể sửa.';
    } elseif ($discount < 0 || $discount > (float) $order['sub_total']) {
        $error = 'Chiết khấu không được âm hoặc lớn hơn tổng tiền hàng';
    } else {
        $pdo->beginTransaction();
        try {
            // Khoa dong don va doc lai trong giao dich: sua chiet khau thay doi tong tien nen phai dong bo
            // lai trang thai thanh toan + cong no khach, neu khong so sach lech nhau.
            $lock = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $order = $lock->fetch();
            $oldTotal = (float) $order['total_amount'];
            $newTotal = (float) $order['sub_total'] - $discount + (float) $order['shipping_fee'];
            $paid = (float) $order['paid_amount'];
            if ($newTotal < $paid) {
                throw new RuntimeException('Chiết khấu mới làm tổng đơn (' . money($newTotal) . ') thấp hơn số tiền đã thu (' . money($paid) . '). Hãy tạo phiếu trả hàng/hoàn tiền thay vì tăng chiết khấu.');
            }
            $payStatus = $paid >= $newTotal && $newTotal > 0 ? 'PAID' : ($paid > 0 ? 'PARTIAL' : 'UNPAID');
            if ($order['status'] === 'DRAFT') {
                $payStatus = 'UNPAID';
            }
            $pdo->prepare('UPDATE orders SET note = ?, discount = ?, total_amount = ?, payment_status = ?, tags = ?, channel_id = ?, source_id = ?, external_order_code = ? WHERE id = ?')
                ->execute([$note, $discount, $newTotal, $payStatus, $tags, $channelId, $sourceId, $externalCode, $id]);

            // Don da ghi no vao cong no khach: no cua don tang/giam dung bang phan tong tien thay doi
            // (khong giam qua so no con lai cua don).
            if ($order['customer_id'] && $order['status'] !== 'DRAFT') {
                $orderDebt = congNoConLaiCuaDon($pdo, $id);
                $hasDebtTracking = (int) $pdo->query('SELECT COUNT(*) FROM customer_debt_entries WHERE order_id = ' . (int) $id)->fetchColumn() > 0;
                $delta = $newTotal - $oldTotal;
                if ($hasDebtTracking && $delta != 0) {
                    $delta = $delta < 0 ? -min($orderDebt, -$delta) : $delta;
                    if ($delta != 0) {
                        $pdo->prepare('UPDATE customers SET debt = GREATEST(0, debt + ?) WHERE id = ?')->execute([$delta, $order['customer_id']]);
                        $pdo->prepare('INSERT INTO customer_debt_entries (customer_id, order_id, amount, note, created_by_id) VALUES (?,?,?,?,?)')
                            ->execute([$order['customer_id'], $id, $delta, 'Điều chỉnh chiết khấu đơn ' . $order['code'], currentUser()['id']]);
                    }
                }
            }

            $currentUser = currentUser();
            $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, note, changed_by_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $order['status'], $order['status'], 'Sửa đơn hàng (ghi chú/chiết khấu)', $currentUser['id']]);
            $pdo->commit();
            redirect('order_view.php?id=' . $id);
        } catch (RuntimeException $ex) {
            $pdo->rollBack();
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'Không lưu được thay đổi, vui lòng thử lại.';
        }
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
