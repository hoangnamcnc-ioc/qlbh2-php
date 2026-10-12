<?php
/**
 * Doi soat COD: danh dau van don da nhan tien thu ho tu hang van chuyen VA ghi so day du:
 * phieu THU so quy (tien khach tra qua hang), phieu CHI phi van chuyen hang khau tru (neu khach tra
 * phi), cap nhat "da thanh toan" cua don va tru cong no khach. Truoc day chi dat moc thoi gian nen
 * so quy khong co tien COD, don van hien "chua thanh toan" va khach van bi tinh no.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('shipments.php');
checkCsrf();

$pdo = db();
$shipmentId = (int) ($_POST['shipment_id'] ?? 0);
$method = in_array($_POST['method'] ?? '', ['CASH', 'BANK_TRANSFER'], true) ? $_POST['method'] : 'BANK_TRANSFER';

$pdo->beginTransaction();
try {
    // Khoa dong van don + dung tenant; chi xu ly khi chua doi soat (bam 2 lan khong ghi so 2 lan).
    $stmt = $pdo->prepare(
        'SELECT s.*, o.id AS oid, o.code AS order_code, o.branch_id, o.customer_id, o.status AS order_status,
                o.total_amount, o.paid_amount
         FROM shipments s JOIN orders o ON o.id = s.order_id JOIN branches b ON b.id = o.branch_id
         WHERE s.id = ? AND s.reconciled_at IS NULL AND b.tenant_id = ? FOR UPDATE'
    );
    $stmt->execute([$shipmentId, currentTenantId()]);
    $ship = $stmt->fetch();

    if ($ship) {
        $cod = (float) $ship['cod_amount'];
        $orderId = (int) $ship['oid'];
        $orderActive = in_array($ship['order_status'], ['APPROVED', 'PACKED', 'SHIPPED', 'COMPLETED'], true);

        if ($cod > 0 && $orderActive) {
            // Chi ghi nhan phan con thieu cua don (khong thu qua so con no thuc te cua don).
            $remaining = max(0.0, (float) $ship['total_amount'] - (float) $ship['paid_amount']);
            $collected = max(0.0, min($cod, $remaining));

            if ($collected > 0) {
                $newPaid = (float) $ship['paid_amount'] + $collected;
                $newStatus = $newPaid >= (float) $ship['total_amount'] ? 'PAID' : 'PARTIAL';
                $pdo->prepare('UPDATE orders SET paid_amount = ?, payment_status = ? WHERE id = ?')->execute([$newPaid, $newStatus, $orderId]);
                $pdo->prepare('INSERT INTO payments (order_id, method, amount) VALUES (?,?,?)')->execute([$orderId, $method, $collected]);
                // Cong no khach chi giam theo so no thuc te cua don (don dat truoc chua ghi no thi khong tru).
                $debtReduce = $ship['customer_id'] ? min($collected, congNoConLaiCuaDon($pdo, $orderId)) : 0.0;
                if ($debtReduce > 0) {
                    $pdo->prepare('UPDATE customers SET debt = GREATEST(0, debt - ?) WHERE id = ?')->execute([$debtReduce, $ship['customer_id']]);
                    $pdo->prepare('INSERT INTO customer_debt_entries (customer_id, order_id, amount, note, created_by_id) VALUES (?,?,?,?,?)')
                        ->execute([$ship['customer_id'], $orderId, -$debtReduce, 'Thu hộ COD đơn hàng ' . $ship['order_code'], $currentUser['id']]);
                }
                recordCashbookEntry((int) $ship['branch_id'], 'RECEIPT', $collected, 'Thu hộ COD đơn hàng ' . $ship['order_code'], $method, $currentUser['id'], $orderId);
                // Hang giu lai phi van chuyen khi khach tra phi (khau tru vao tien COD chuyen ve).
                $fee = (float) $ship['shipping_fee'];
                if ($ship['fee_payer'] === 'CUSTOMER' && $fee > 0) {
                    $fee = min($fee, $collected);
                    recordCashbookEntry((int) $ship['branch_id'], 'PAYMENT', $fee, 'Phí vận chuyển hãng khấu trừ từ COD đơn ' . $ship['order_code'], $method, $currentUser['id'], $orderId);
                }
            }
        }
        $pdo->prepare('UPDATE shipments SET reconciled_at = NOW() WHERE id = ?')->execute([$shipmentId]);
        logActivity('SHIPMENT_RECONCILE', "shipment_id=$shipmentId order=" . $ship['order_code']);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
}

redirect('shipments.php');
