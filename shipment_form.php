<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$orderId = (int) ($_GET['order_id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?'
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) redirect('orders.php');

$existing = $pdo->prepare('SELECT * FROM shipments WHERE order_id = ?');
$existing->execute([$orderId]);
$shipment = $existing->fetch();

$error = null;
$statusLabels = [
    'PENDING' => 'Chờ lấy hàng', 'PICKED_UP' => 'Đã lấy hàng', 'IN_TRANSIT' => 'Đang giao',
    'DELIVERED' => 'Đã giao', 'FAILED' => 'Giao thất bại', 'RETURNED' => 'Đã hoàn',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $trackingCode = post('tracking_code') ?: null;
    $carrierName = post('carrier_name') ?: null;
    $status = $_POST['status'] ?? 'PENDING';
    $shippingFee = postFloat('shipping_fee');
    $codAmount = postFloat('cod_amount');
    $note = post('note') ?: null;
    $recipientName = post('recipient_name') ?: null;
    $recipientPhone = post('recipient_phone') ?: null;
    $feePayer = ($_POST['fee_payer'] ?? '') === 'SHOP' ? 'SHOP' : 'CUSTOMER';

    if (!array_key_exists($status, $statusLabels)) $status = 'PENDING';

    if ($shipment) {
        $pdo->prepare('UPDATE shipments SET tracking_code=?, carrier_name=?, status=?, shipping_fee=?, cod_amount=?, note=?, recipient_name=?, recipient_phone=?, fee_payer=? WHERE id=?')
            ->execute([$trackingCode, $carrierName, $status, $shippingFee, $codAmount, $note, $recipientName, $recipientPhone, $feePayer, $shipment['id']]);
    } else {
        $pdo->prepare('INSERT INTO shipments (order_id, tracking_code, carrier_name, status, shipping_fee, cod_amount, note, recipient_name, recipient_phone, fee_payer, created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$orderId, $trackingCode, $carrierName, $status, $shippingFee, $codAmount, $note, $recipientName, $recipientPhone, $feePayer, $currentUser['id']]);
    }
    redirect('order_view.php?id=' . $orderId);
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="order_view.php?id=<?= (int) $orderId ?>" class="muted" style="font-size:14px;">← Đơn hàng <?= e($order['code']) ?></a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;"><?= $shipment ? 'Cập nhật' : 'Tạo' ?> vận đơn</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:480px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="grid-2">
      <div class="field"><label>Người nhận</label><input class="input" name="recipient_name" value="<?= e($shipment['recipient_name'] ?? $order['customer_name'] ?? '') ?>"></div>
      <div class="field"><label>SĐT người nhận</label><input class="input" name="recipient_phone" value="<?= e($shipment['recipient_phone'] ?? $order['customer_phone'] ?? '') ?>"></div>
    </div>
    <div class="field"><label>Đơn vị vận chuyển</label><input class="input" name="carrier_name" value="<?= e($shipment['carrier_name'] ?? '') ?>" placeholder="vd: GHN, GHTK, Tự giao..."></div>
    <div class="field"><label>Mã vận đơn</label><input class="input" name="tracking_code" value="<?= e($shipment['tracking_code'] ?? '') ?>"></div>
    <div class="field">
      <label>Trạng thái</label>
      <select class="input" name="status">
        <?php foreach ($statusLabels as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= ($shipment['status'] ?? 'PENDING') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="grid-2">
      <div class="field"><label>Phí giao hàng</label><input class="input" type="number" min="0" name="shipping_fee" value="<?= e((string) ($shipment['shipping_fee'] ?? '0')) ?>"></div>
      <div class="field"><label>Thu hộ (COD)</label><input class="input" type="number" min="0" name="cod_amount" value="<?= e((string) ($shipment['cod_amount'] ?? '0')) ?>"></div>
    </div>
    <div class="field">
      <label>Người trả phí giao hàng</label>
      <select class="input" name="fee_payer">
        <option value="CUSTOMER" <?= ($shipment['fee_payer'] ?? 'CUSTOMER') === 'CUSTOMER' ? 'selected' : '' ?>>Khách trả (trừ vào tiền COD thu hộ)</option>
        <option value="SHOP" <?= ($shipment['fee_payer'] ?? 'CUSTOMER') === 'SHOP' ? 'selected' : '' ?>>Shop trả (không trừ vào COD)</option>
      </select>
    </div>
    <div class="field"><label>Ghi chú</label><input class="input" name="note" value="<?= e($shipment['note'] ?? '') ?>"></div>
    <button type="submit" class="btn">Lưu vận đơn</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
