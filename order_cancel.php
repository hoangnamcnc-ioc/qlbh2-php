<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('orders.php');
checkCsrf();

$pdo = db();
$orderId = (int) ($_POST['order_id'] ?? 0);
$reason = post('reason');
if ($reason === '__OTHER__') {
    $reason = post('reason_other');
}
$reason = $reason ?: null;

$order = layDonHangCuaToi($orderId);

if ($order && $order['status'] !== 'CANCELLED') {
    // Đơn đã có phiếu trả hàng: hủy nữa sẽ hoàn kho/tiền lần thứ hai cho cùng số hàng đó.
    $returnCheck = $pdo->prepare('SELECT COUNT(*) FROM order_returns WHERE order_id = ?');
    $returnCheck->execute([$orderId]);
    if ((int) $returnCheck->fetchColumn() > 0) {
        redirect('order_view.php?id=' . $orderId . '&err=' . urlencode('Đơn này đã có phiếu trả hàng nên không thể hủy nữa (hàng và tiền đã được hoàn theo phiếu trả).'));
    }
    $pdo->beginTransaction();
    try {
        huyDonHangTrongGiaoDich($pdo, $order, (int) $currentUser['id'], $reason);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        redirect('order_view.php?id=' . $orderId . '&err=' . urlencode('Hủy đơn thất bại, vui lòng thử lại. Nếu vẫn lỗi, liên hệ hỗ trợ.'));
    }
} elseif (!$order) {
    redirect('orders.php?err=' . urlencode('Không tìm thấy đơn hàng này.'));
} elseif ($order['status'] === 'CANCELLED') {
    redirect('order_view.php?id=' . $orderId . '&err=' . urlencode('Đơn hàng này đã được hủy từ trước.'));
}

redirect('order_view.php?id=' . $orderId);
