<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('stock_takes.php');
checkCsrf();

$pdo = db();
$takeId = (int) ($_POST['take_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM stock_takes WHERE id = ?');
$stmt->execute([$takeId]);
$take = $stmt->fetch();

if ($take && $take['status'] === 'DRAFT') {
    $pdo->beginTransaction();
    try {
        $items = $pdo->prepare('SELECT * FROM stock_take_items WHERE take_id = ?');
        $items->execute([$takeId]);

        foreach ($items->fetchAll() as $it) {
            if ($it['variant_id']) {
                $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND variant_id = ?');
                $inv->execute([$take['branch_id'], $it['variant_id']]);
            } else {
                $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                $inv->execute([$take['branch_id'], $it['product_id']]);
            }
            $invRow = $inv->fetch();
            if ($invRow) {
                $pdo->prepare('UPDATE inventory SET quantity = ? WHERE id = ?')->execute([$it['counted_qty'], $invRow['id']]);
            } else {
                $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
                    ->execute([$take['branch_id'], $it['product_id'], $it['variant_id'], $it['counted_qty']]);
            }
        }

        $pdo->prepare("UPDATE stock_takes SET status = 'BALANCED', balanced_by_id = ?, balanced_at = NOW() WHERE id = ?")
            ->execute([$currentUser['id'], $takeId]);

        $pdo->commit();
        logActivity('STOCK_TAKE_BALANCE', 'id=' . $takeId);
    } catch (Throwable $ex) {
        $pdo->rollBack();
    }
}

redirect('stock_take_view.php?id=' . $takeId);
