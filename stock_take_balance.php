<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('stock_takes.php');
checkCsrf();

$pdo = db();
$takeId = (int) ($_POST['take_id'] ?? 0);

$take = layPhieuKiemHangCuaToi($takeId);

// MANAGER chỉ được cân bằng phiếu kiểm hàng của chi nhánh mình.
if ($take && !hasRole('ADMIN') && (int) $take['branch_id'] !== effectiveBranchId($currentUser)) {
    $take = null;
}

if ($take && $take['status'] === 'DRAFT') {
    $pdo->beginTransaction();
    try {
        $items = $pdo->prepare('SELECT * FROM stock_take_items WHERE take_id = ?');
        $items->execute([$takeId]);

        foreach ($items->fetchAll() as $it) {
            if ($it['variant_id']) {
                $inv = $pdo->prepare('SELECT id, quantity FROM inventory WHERE branch_id = ? AND variant_id = ? FOR UPDATE');
                $inv->execute([$take['branch_id'], $it['variant_id']]);
            } else {
                $inv = $pdo->prepare('SELECT id, quantity FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL FOR UPDATE');
                $inv->execute([$take['branch_id'], $it['product_id']]);
            }
            $invRow = $inv->fetch();

            // Áp dụng CHÊNH LỆCH (counted_qty - system_qty lúc lập phiếu) vào tồn kho HIỆN TẠI,
            // thay vì ghi đè thẳng thành counted_qty. Giữa lúc tạo phiếu nháp và lúc bấm "Cân
            // bằng kho" có thể đã phát sinh bán hàng/nhập hàng khác làm tồn kho thay đổi — ghi
            // đè thẳng sẽ xóa mất các thay đổi đó; cộng dồn chênh lệch mới không làm mất chúng.
            $delta = round((float) $it['counted_qty'] - (float) $it['system_qty'], 3);

            if ($invRow) {
                $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')->execute([$delta, $invRow['id']]);
            } else {
                $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
                    ->execute([$take['branch_id'], $it['product_id'], $it['variant_id'], max(0, $delta)]);
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
