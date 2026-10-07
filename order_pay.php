<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('orders.php');
checkCsrf();

$pdo = db();
$orderId = (int) ($_POST['order_id'] ?? 0);
$amount = postFloat('amount');

$order = layDonHangCuaToi($orderId);

// Thu ngan (CASHIER) khong duoc ghi nhan thu no cho don cua chi nhanh khac.
if ($order && !hasRole('ADMIN', 'MANAGER') && (int) $order['branch_id'] !== effectiveBranchId($currentUser)) {
    $order = null;
}

if ($order && $order['customer_id'] && $amount > 0 && in_array($order['status'], ['APPROVED', 'PACKED', 'SHIPPED', 'COMPLETED'], true)) {
    $pdo->beginTransaction();
    try {
        // Khóa dòng đơn rồi đọc lại số liệu BÊN TRONG transaction - bấm 2 lần liên tiếp không còn
        // cùng thấy "còn nợ 400.000" rồi cùng thu 400.000.
        $lock = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND status IN (?,?,?,?) FOR UPDATE');
        $lock->execute([$orderId, 'APPROVED', 'PACKED', 'SHIPPED', 'COMPLETED']);
        $order = $lock->fetch();
        // Số còn thu được giới hạn bởi công nợ thực tế của đơn (trả hàng đã trừ nợ thì không thu lại).
        $remaining = $order ? min((float) $order['total_amount'] - (float) $order['paid_amount'], congNoConLaiCuaDon($pdo, $orderId)) : 0.0;
        $amount = min($amount, $remaining);

        if ($order && $amount > 0) {
            $newPaid = (float) $order['paid_amount'] + $amount;
            $newStatus = $newPaid >= (float) $order['total_amount'] ? 'PAID' : 'PARTIAL';
            $pdo->prepare('UPDATE orders SET paid_amount = ?, payment_status = ? WHERE id = ?')
                ->execute([$newPaid, $newStatus, $orderId]);
            $pdo->prepare('UPDATE customers SET debt = GREATEST(0, debt - ?) WHERE id = ?')->execute([$amount, $order['customer_id']]);
            $pdo->prepare('INSERT INTO payments (order_id, method, amount) VALUES (?,?,?)')
                ->execute([$orderId, 'CASH', $amount]);
            $pdo->prepare('INSERT INTO customer_debt_entries (customer_id, order_id, amount, note, created_by_id) VALUES (?,?,?,?,?)')
                ->execute([$order['customer_id'], $orderId, -$amount, 'Thu nợ đơn hàng', $currentUser['id']]);
            recordCashbookEntry((int) $order['branch_id'], 'RECEIPT', $amount, 'Thu nợ đơn hàng ' . $order['code'], 'CASH', $currentUser['id'], $orderId);
            logActivity('ORDER_PAY', "order_id=$orderId amount=$amount");
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
    }
}

redirect('order_view.php?id=' . $orderId);
