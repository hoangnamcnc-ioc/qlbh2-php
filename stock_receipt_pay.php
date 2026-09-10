<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('stock_receipts.php');
checkCsrf();

$pdo = db();
$receiptId = (int) ($_POST['receipt_id'] ?? 0);
$amount = postFloat('amount');

$stmt = $pdo->prepare('SELECT * FROM stock_receipts WHERE id = ?');
$stmt->execute([$receiptId]);
$receipt = $stmt->fetch();

if ($receipt && $receipt['supplier_id'] && $amount > 0) {
    $remaining = (float) $receipt['total_amount'] - (float) $receipt['paid_amount'];
    $amount = min($amount, $remaining);

    if ($amount > 0) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE stock_receipts SET paid_amount = paid_amount + ? WHERE id = ?')->execute([$amount, $receiptId]);
            $pdo->prepare('UPDATE suppliers SET debt = GREATEST(0, debt - ?) WHERE id = ?')->execute([$amount, $receipt['supplier_id']]);
            $pdo->commit();
            logActivity('STOCK_RECEIPT_PAY', "receipt_id=$receiptId amount=$amount");
        } catch (Throwable $ex) {
            $pdo->rollBack();
        }
    }
}

redirect('stock_receipt_view.php?id=' . $receiptId);
