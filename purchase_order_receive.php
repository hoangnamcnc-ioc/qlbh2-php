<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('purchase_orders.php');
checkCsrf();

$pdo = db();
$poId = (int) ($_POST['po_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
$stmt->execute([$poId]);
$po = $stmt->fetch();
if (!$po || $po['status'] !== 'PENDING') redirect('purchase_order_view.php?id=' . $poId);

$items = $pdo->prepare('SELECT * FROM purchase_order_items WHERE po_id = ?');
$items->execute([$poId]);
$items = $items->fetchAll();

if ($items) {
    $pdo->beginTransaction();
    try {
        $total = array_sum(array_map(fn($i) => $i['quantity'] * $i['cost_price'], $items));
        $code = 'PN' . substr((string) (int) round(microtime(true) * 1000), -8);

        $pdo->prepare(
            'INSERT INTO stock_receipts (code, branch_id, supplier_id, created_by_id, total_amount, note, purchase_order_id) VALUES (?,?,?,?,?,?,?)'
        )->execute([$code, $po['branch_id'], $po['supplier_id'], $currentUser['id'], $total, 'Nhập từ đặt hàng ' . $po['code'], $poId]);
        $receiptId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO stock_receipt_items (receipt_id, product_id, variant_id, quantity, cost_price) VALUES (?,?,?,?,?)');
        foreach ($items as $it) {
            $itemStmt->execute([$receiptId, $it['product_id'], $it['variant_id'], $it['quantity'], $it['cost_price']]);

            if ($it['variant_id']) {
                $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND variant_id = ?');
                $inv->execute([$po['branch_id'], $it['variant_id']]);
            } else {
                $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                $inv->execute([$po['branch_id'], $it['product_id']]);
            }
            $invRow = $inv->fetch();
            if ($invRow) {
                $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')->execute([$it['quantity'], $invRow['id']]);
            } else {
                $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
                    ->execute([$po['branch_id'], $it['product_id'], $it['variant_id'], $it['quantity']]);
            }

            if ($it['variant_id']) {
                $pdo->prepare('UPDATE product_variants SET cost_price = ? WHERE id = ?')->execute([$it['cost_price'], $it['variant_id']]);
            } else {
                $pdo->prepare('UPDATE products SET cost_price = ? WHERE id = ?')->execute([$it['cost_price'], $it['product_id']]);
            }
        }

        if ($po['supplier_id']) {
            $pdo->prepare('UPDATE suppliers SET debt = debt + ? WHERE id = ?')->execute([$total, $po['supplier_id']]);
        }

        $pdo->prepare("UPDATE purchase_orders SET status = 'RECEIVED' WHERE id = ?")->execute([$poId]);

        $pdo->commit();
        redirect('stock_receipt_view.php?id=' . $receiptId);
    } catch (Throwable $ex) {
        $pdo->rollBack();
        redirect('purchase_order_view.php?id=' . $poId);
    }
}

redirect('purchase_order_view.php?id=' . $poId);
