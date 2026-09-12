<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('orders.php');
checkCsrf();

$pdo = db();
$orderId = (int) ($_POST['order_id'] ?? 0);
$pipeline = ['DRAFT', 'APPROVED', 'PACKED', 'SHIPPED', 'COMPLETED'];

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if ($order) {
    $idx = array_search($order['status'], $pipeline, true);
    if ($idx !== false && $idx > 0) {
        $prevStatus = $pipeline[$idx - 1];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$prevStatus, $orderId]);
            $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, note, changed_by_id) VALUES (?,?,?,?,?)')
                ->execute([$orderId, $order['status'], $prevStatus, 'Lùi bước xử lý (điều chỉnh nhầm)', $currentUser['id']]);

            if ($order['status'] === 'COMPLETED') {
                // Hoàn tác các tác dụng phụ đã áp dụng lúc đơn được đánh dấu Hoàn thành:
                // xóa phiếu bảo hành đã tự động tạo cho đơn này, trừ lại điểm tích lũy đã cộng.
                $pdo->prepare(
                    'DELETE wc FROM warranty_cards wc JOIN order_items oi ON oi.id = wc.order_item_id WHERE oi.order_id = ?'
                )->execute([$orderId]);

                if ($order['customer_id']) {
                    $points = (int) floor((float) $order['total_amount'] / 10000);
                    if ($points > 0) {
                        $pdo->prepare('UPDATE customers SET loyalty_points = GREATEST(0, loyalty_points - ?) WHERE id = ?')
                            ->execute([$points, $order['customer_id']]);
                    }
                }
            }

            $pdo->commit();
            logActivity('ORDER_REVERT', "order_id=$orderId {$order['status']}->$prevStatus");
        } catch (Throwable $ex) {
            $pdo->rollBack();
        }
    }
}

redirect('order_view.php?id=' . $orderId);
