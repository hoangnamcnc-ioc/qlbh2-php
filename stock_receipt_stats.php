<?php
/** Thong ke cac lan nhap truoc cua 1 san pham (de canh bao so luong / don gia lech khi lap phieu nhap). JSON. */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');
header('Content-Type: application/json; charset=utf-8');

$pid = (int) ($_GET['product_id'] ?? 0);
$pdo = db();
$st = $pdo->prepare(
    'SELECT i.quantity, i.cost_price FROM stock_receipt_items i
     JOIN stock_receipts r ON r.id = i.receipt_id JOIN branches b ON b.id = r.branch_id
     WHERE i.product_id = ? AND b.tenant_id = ? ORDER BY r.created_at DESC, r.id DESC LIMIT 10'
);
$st->execute([$pid, currentTenantId()]);
$rows = $st->fetchAll();
if (!$rows) {
    echo json_encode(['n' => 0]);
    exit;
}
$qs = array_map(static fn($r) => (float) $r['quantity'], $rows);
$cs = array_map(static fn($r) => (float) $r['cost_price'], $rows);
echo json_encode([
    'n' => count($rows), 'avg_qty' => round(array_sum($qs) / count($qs), 3), 'max_qty' => max($qs),
    'avg_cost' => round(array_sum($cs) / count($cs), 2), 'last_cost' => $cs[0],
]);
