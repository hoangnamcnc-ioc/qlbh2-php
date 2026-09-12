<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('orders.php');
checkCsrf();

$pdo = db();
$orderId = (int) ($_POST['order_id'] ?? 0);
$reason = post('reason') ?: null;

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if ($order && $order['status'] !== 'CANCELLED') {
    $pdo->beginTransaction();
    try {
        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);

        foreach ($items->fetchAll() as $item) {
            $typeStmt = $pdo->prepare('SELECT product_type FROM products WHERE id = ?');
            $typeStmt->execute([$item['product_id']]);
            $productType = $typeStmt->fetchColumn() ?: 'PRODUCT';

            if ($productType === 'SERVICE') {
                // Dịch vụ không quản lý tồn kho, không cần hoàn kho.
                continue;
            }

            if ($productType === 'COMBO') {
                $comboStmt = $pdo->prepare('SELECT component_product_id, quantity AS comp_qty FROM combo_items WHERE combo_product_id = ?');
                $comboStmt->execute([$item['product_id']]);
                foreach ($comboStmt->fetchAll() as $comp) {
                    $restoreQty = (float) $comp['comp_qty'] * (float) $item['quantity'];
                    $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                    $inv->execute([$order['branch_id'], $comp['component_product_id']]);
                    $invRow = $inv->fetch();
                    if ($invRow) {
                        $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')->execute([$restoreQty, $invRow['id']]);
                    } else {
                        $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,NULL,?)')
                            ->execute([$order['branch_id'], $comp['component_product_id'], $restoreQty]);
                    }
                }
                continue;
            }

            if ($item['variant_id']) {
                $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND variant_id = ?');
                $inv->execute([$order['branch_id'], $item['variant_id']]);
            } else {
                $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                $inv->execute([$order['branch_id'], $item['product_id']]);
            }
            $invRow = $inv->fetch();
            if ($invRow) {
                $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')
                    ->execute([$item['quantity'], $invRow['id']]);
            } else {
                $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
                    ->execute([$order['branch_id'], $item['product_id'], $item['variant_id'], $item['quantity']]);
            }
        }

        $pdo->prepare("UPDATE orders SET status = 'CANCELLED' WHERE id = ?")->execute([$orderId]);
        $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, note, changed_by_id) VALUES (?,?,"CANCELLED",?,?)')
            ->execute([$orderId, $order['status'], $reason, $currentUser['id']]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
    }
}

redirect('order_view.php?id=' . $orderId);
