<?php
/**
 * POS hoi: da nhan duoc bao nhieu tien chuyen khoan voi ma thanh toan TTxxxxxx nay chua? (poll moi vai giay khi hien QR).
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ref = strtoupper(trim((string) ($_GET['ref'] ?? '')));
if (!preg_match('/^TT[A-HJ-NP-Z2-9]{6}$/', $ref)) {
    echo json_encode(['received' => 0, 'error' => 'invalid_ref']);
    exit;
}
$st = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM bank_transactions WHERE tenant_id = ? AND pos_ref = ? AND status = 'POS_REF'");
$st->execute([currentTenantId(), $ref]);
echo json_encode(['received' => (float) $st->fetchColumn()]);
