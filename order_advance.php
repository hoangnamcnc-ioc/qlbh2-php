<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('orders.php');
checkCsrf();

$pdo = db();
$orderId = (int) ($_POST['order_id'] ?? 0);
$pipeline = ['DRAFT', 'APPROVED', 'PACKED', 'SHIPPED', 'COMPLETED'];

$order = layDonHangCuaToi($orderId);

if ($order) {
    $idx = array_search($order['status'], $pipeline, true);
    if ($idx !== false && $idx < count($pipeline) - 1) {
        $nextStatus = $pipeline[$idx + 1];
        $pdo->beginTransaction();
        try {
            // Chi chuyen khi don van o dung trang thai vua doc: bam 2 lan/2 nguoi cung luc khong con
            // cung "hoan thanh" don 2 lan (cong diem va tao the bao hanh 2 lan).
            $move = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ? AND status = ?');
            $move->execute([$nextStatus, $orderId, $order['status']]);
            if ($move->rowCount() === 0) {
                $pdo->rollBack();
                redirect('order_view.php?id=' . $orderId);
            }
            $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, changed_by_id) VALUES (?,?,?,?)')
                ->execute([$orderId, $order['status'], $nextStatus, $currentUser['id']]);

            if ($nextStatus === 'COMPLETED') {
                createWarrantyCardsForOrder($orderId, $order['customer_id'], $currentUser['id']);
                if ($order['customer_id']) {
                    $points = (int) floor((float) $order['total_amount'] / 10000);
                    $pdo->prepare('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?')
                        ->execute([$points, $order['customer_id']]);
                }
            }

            $pdo->commit();
            logActivity('ORDER_ADVANCE', "order_id=$orderId {$order['status']}->$nextStatus");
        } catch (Throwable $ex) {
            $pdo->rollBack();
        }
    }
}

redirect('order_view.php?id=' . $orderId);
