<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('stock_transfers.php');
checkCsrf();

$pdo = db();
$transferId = (int) ($_POST['transfer_id'] ?? 0);
$action = $_POST['action'] ?? 'receive';

$stmt = $pdo->prepare('SELECT * FROM stock_transfers WHERE id = ?');
$stmt->execute([$transferId]);
$transfer = $stmt->fetch();

if ($transfer && $transfer['status'] === 'IN_TRANSIT') {
    $pdo->beginTransaction();
    try {
        $items = $pdo->prepare('SELECT * FROM stock_transfer_items WHERE transfer_id = ?');
        $items->execute([$transferId]);
        $items = $items->fetchAll();

        if ($action === 'cancel') {
            // Hoàn lại hàng về chi nhánh chuyển vì chưa tới nơi nhận
            foreach ($items as $it) {
                if ($it['variant_id']) {
                    $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND variant_id = ?');
                    $inv->execute([$transfer['from_branch_id'], $it['variant_id']]);
                } else {
                    $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                    $inv->execute([$transfer['from_branch_id'], $it['product_id']]);
                }
                $invRow = $inv->fetch();
                if ($invRow) {
                    $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')->execute([$it['quantity'], $invRow['id']]);
                } else {
                    $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
                        ->execute([$transfer['from_branch_id'], $it['product_id'], $it['variant_id'], $it['quantity']]);
                }
            }
            $pdo->prepare("UPDATE stock_transfers SET status = 'CANCELLED' WHERE id = ?")->execute([$transferId]);
            logActivity('STOCK_TRANSFER_CANCEL', 'id=' . $transferId);
        } else {
            // Cộng hàng vào chi nhánh nhận vì đã xác nhận nhận được
            foreach ($items as $it) {
                if ($it['variant_id']) {
                    $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND variant_id = ?');
                    $inv->execute([$transfer['to_branch_id'], $it['variant_id']]);
                } else {
                    $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                    $inv->execute([$transfer['to_branch_id'], $it['product_id']]);
                }
                $invRow = $inv->fetch();
                if ($invRow) {
                    $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')->execute([$it['quantity'], $invRow['id']]);
                } else {
                    $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
                        ->execute([$transfer['to_branch_id'], $it['product_id'], $it['variant_id'], $it['quantity']]);
                }
            }
            $pdo->prepare("UPDATE stock_transfers SET status = 'COMPLETED', received_by_id = ?, received_at = NOW() WHERE id = ?")
                ->execute([$currentUser['id'], $transferId]);
            logActivity('STOCK_TRANSFER_RECEIVE', 'id=' . $transferId);
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
    }
}

redirect('stock_transfer_view.php?id=' . $transferId);
