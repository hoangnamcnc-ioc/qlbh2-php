<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$orderId = (int) ($_GET['order_id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone FROM orders o
     JOIN branches b ON b.id = o.branch_id
     LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ? AND b.tenant_id = ?'
);
$stmt->execute([$orderId, currentTenantId()]);
$order = $stmt->fetch();
if (!$order) redirect('orders.php');
// Thu ngan (CASHIER) khong duoc tao/sua van don cho chi nhanh khac.
if (!hasRole('ADMIN', 'MANAGER') && (int) $order['branch_id'] !== effectiveBranchId($currentUser)) {
    http_response_code(403);
    require_once __DIR__ . '/inc_header.php';
    echo '<div class="alert alert-error">Bạn không có quyền tạo vận đơn cho đơn hàng của chi nhánh khác.</div>';
    require_once __DIR__ . '/inc_footer.php';
    exit;
}

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

    $pdo->beginTransaction();
    $cancelMsg = '';
    try {
        if ($shipment) {
            $pdo->prepare('UPDATE shipments SET tracking_code=?, carrier_name=?, status=?, shipping_fee=?, cod_amount=?, note=?, recipient_name=?, recipient_phone=?, fee_payer=? WHERE id=?')
                ->execute([$trackingCode, $carrierName, $status, $shippingFee, $codAmount, $note, $recipientName, $recipientPhone, $feePayer, $shipment['id']]);
        } else {
            $pdo->prepare('INSERT INTO shipments (order_id, tracking_code, carrier_name, status, shipping_fee, cod_amount, note, recipient_name, recipient_phone, fee_payer, created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$orderId, $trackingCode, $carrierName, $status, $shippingFee, $codAmount, $note, $recipientName, $recipientPhone, $feePayer, $currentUser['id']]);
        }

        // Hang hoan ve shop: huy don (hoan kho/lo hang, hoan tien/cong no/diem) de so sach khop voi thuc te.
        // Chi Quan ly/Quan tri duoc tu dong huy; thu ngan chi doi trang thai van don.
        $wasReturned = $shipment && $shipment['status'] === 'RETURNED';
        if ($status === 'RETURNED' && !$wasReturned && $order['status'] !== 'CANCELLED') {
            $hasReturn = $pdo->prepare('SELECT COUNT(*) FROM order_returns WHERE order_id = ?');
            $hasReturn->execute([$orderId]);
            if (!isManagerTier()) {
                $cancelMsg = 'Đã cập nhật vận đơn. Hàng hoàn về nên cần Quản lý hủy đơn để hoàn kho và tiền.';
            } elseif ((int) $hasReturn->fetchColumn() > 0) {
                $cancelMsg = 'Đã cập nhật vận đơn. Đơn này đã có phiếu trả hàng nên không tự hủy được.';
            } else {
                $fresh = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
                $fresh->execute([$orderId]);
                huyDonHangTrongGiaoDich($pdo, $fresh->fetch(), (int) $currentUser['id'], 'Hàng hoàn về từ vận chuyển');
                $cancelMsg = 'Hàng đã hoàn về: hệ thống đã hủy đơn, hoàn kho và hoàn tiền/công nợ.';
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        $error = 'Không lưu được vận đơn, vui lòng thử lại.';
    }
    if ($error === null) {
        redirect('order_view.php?id=' . $orderId . ($cancelMsg !== '' ? '&err=' . urlencode($cancelMsg) : ''));
    }
}


require_once __DIR__ . '/inc_header.php';
?>

<a href="order_view.php?id=<?= (int) $orderId ?>" class="muted" style="font-size:14px;">← Đơn hàng <?= e($order['code']) ?></a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 16px;"><?= $shipment ? 'Cập nhật' : 'Tạo' ?> vận đơn</h1>

<?php hopDieuKienTrienKhai(
    'Vận đơn ở đây do bạn <b>nhập tay</b>: sau khi gửi hàng cho hãng vận chuyển, chép mã vận đơn
     họ cấp vào ô bên dưới rồi cập nhật trạng thái khi hàng đi đến đâu.',
    [
        'Muốn hệ thống <b>tự đẩy đơn sang hãng</b> và <b>tự cập nhật trạng thái</b>, cần hợp đồng
         + API key của hãng (GHN, GHTK, ViettelPost...) và lập trình phần kết nối.',
    ]
); ?>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:480px;">
  <form method="post" onsubmit="var st=this.status.value; if (st==='RETURNED' && '<?= e($shipment['status'] ?? '') ?>'!=='RETURNED') { return confirm('Chuyển sang Đã hoàn sẽ HỦY ĐƠN, hoàn kho và hoàn tiền/công nợ (nếu bạn là Quản lý). Tiếp tục?'); } return true;">
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
