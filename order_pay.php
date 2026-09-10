<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('orders.php');
checkCsrf();

$pdo = db();
$orderId = (int) ($_POST['order_id'] ?? 0);
$amount = postFloat('amount');

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if ($order && $order['customer_id'] && $amount > 0) {
    $remaining = (float) $order['total_amount'] - (float) $order['paid_amount'];
    $amount = min($amount, $remaining);

    if ($amount > 0) {
        $pdo->beginTransaction();
        try {
            $newPaid = (float) $order['paid_amount'] + $amount;
            $newStatus = $newPaid >= (float) $order['total_amount'] ? 'PAID' : 'PARTIAL';
            $pdo->prepare('UPDATE orders SET paid_amount = ?, payment_status = ? WHERE id = ?')
                ->execute([$newPaid, $newStatus, $orderId]);
            $pdo->prepare('UPDATE customers SET debt = GREATEST(0, debt - ?) WHERE id = ?')->execute([$amount, $order['customer_id']]);
            $pdo->prepare('INSERT INTO payments (order_id, method, amount) VALUES (?,?,?)')
                ->execute([$orderId, 'CASH', $amount]);
            $pdo->commit();
            logActivity('ORDER_PAY', "order_id=$orderId amount=$amount");
        } catch (Throwable $ex) {
            $pdo->rollBack();
        }
    }
}

redirect('order_view.php?id=' . $orderId);
