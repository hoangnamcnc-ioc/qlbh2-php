<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$user = requireLogin();
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!hash_equals($_SESSION['csrf'] ?? '', $input['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Phiên làm việc không hợp lệ, vui lòng tải lại trang.']);
    exit;
}

$customerId = (int) ($input['customer_id'] ?? 0);
$giftId = (int) ($input['gift_id'] ?? 0);

if (!$customerId || !$giftId) {
    echo json_encode(['error' => 'Thiếu thông tin khách hàng hoặc quà tặng']);
    exit;
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $custStmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? FOR UPDATE');
    $custStmt->execute([$customerId]);
    $customer = $custStmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Không tìm thấy khách hàng');
    }

    $giftStmt = $pdo->prepare('SELECT * FROM gifts WHERE id = ? AND is_active = 1 FOR UPDATE');
    $giftStmt->execute([$giftId]);
    $gift = $giftStmt->fetch();
    if (!$gift) {
        throw new RuntimeException('Quà tặng không tồn tại hoặc đã ngừng áp dụng');
    }

    if ((int) $customer['loyalty_points'] < (int) $gift['points_required']) {
        throw new RuntimeException('Khách hàng không đủ điểm để đổi quà này');
    }

    if ($gift['stock_qty'] !== null && (int) $gift['stock_qty'] <= 0) {
        throw new RuntimeException('Quà tặng đã hết hàng');
    }

    $pdo->prepare('UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id = ?')
        ->execute([$gift['points_required'], $customerId]);

    if ($gift['stock_qty'] !== null) {
        $pdo->prepare('UPDATE gifts SET stock_qty = stock_qty - 1 WHERE id = ?')->execute([$giftId]);
    }

    $branchId = (int) ($user['branch_id'] ?? 0) ?: null;
    $pdo->prepare('INSERT INTO gift_redemptions (gift_id, customer_id, branch_id, points_used, redeemed_by_id) VALUES (?,?,?,?,?)')
        ->execute([$giftId, $customerId, $branchId, $gift['points_required'], $user['id']]);

    $pdo->commit();
    logActivity('GIFT_REDEEM', "customer_id=$customerId gift={$gift['name']} points={$gift['points_required']}");

    $remaining = (int) $customer['loyalty_points'] - (int) $gift['points_required'];
    echo json_encode(['success' => true, 'remaining_points' => $remaining, 'gift_name' => $gift['name']], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $ex) {
    $pdo->rollBack();
    echo json_encode(['error' => $ex->getMessage()]);
} catch (Throwable $ex) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Không thể đổi quà, vui lòng thử lại']);
}
