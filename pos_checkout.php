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

$branchId = (int) ($user['branch_id'] ?? 0);
if (!$branchId) {
    echo json_encode(['error' => 'Tài khoản chưa được gán chi nhánh, không thể tạo đơn']);
    exit;
}

$items = $input['items'] ?? [];
if (!is_array($items) || !$items) {
    echo json_encode(['error' => 'Đơn hàng chưa có sản phẩm']);
    exit;
}

$paymentMethod = in_array($input['payment_method'] ?? '', ['CASH', 'BANK_TRANSFER', 'CARD', 'QR_CODE'], true)
    ? $input['payment_method']
    : 'CASH';
$customerPhone = trim((string) ($input['customer_phone'] ?? ''));

$pdo = db();

try {
    $pdo->beginTransaction();

    $customerId = null;
    if ($customerPhone !== '') {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE phone = ?');
        $stmt->execute([$customerPhone]);
        $existingCustomer = $stmt->fetch();
        if ($existingCustomer) {
            $customerId = (int) $existingCustomer['id'];
        } else {
            $code = 'CUZN' . substr((string) (microtime(true) * 1000), -8);
            $pdo->prepare('INSERT INTO customers (code, name, phone) VALUES (?, ?, ?)')
                ->execute([$code, $customerPhone, $customerPhone]);
            $customerId = (int) $pdo->lastInsertId();
        }
    }

    $subTotal = 0.0;
    $lineData = [];

    foreach ($items as $line) {
        $productId = (int) ($line['product_id'] ?? 0);
        $quantity = (int) ($line['quantity'] ?? 0);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) {
            throw new RuntimeException('Dữ liệu sản phẩm không hợp lệ');
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM inventory WHERE product_id = ? AND branch_id = ? FOR UPDATE'
        );
        $stmt->execute([$productId, $branchId]);
        $inv = $stmt->fetch();

        if (!$inv || (int) $inv['quantity'] < $quantity) {
            $prod = $pdo->prepare('SELECT name FROM products WHERE id = ?');
            $prod->execute([$productId]);
            $pname = $prod->fetchColumn() ?: ('#' . $productId);
            throw new RuntimeException("Sản phẩm \"$pname\" không đủ tồn kho");
        }

        $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')
            ->execute([$quantity, $inv['id']]);

        $lineTotal = $unitPrice * $quantity;
        $subTotal += $lineTotal;
        $lineData[] = [$productId, $quantity, $unitPrice, $lineTotal];
    }

    $code = 'DH' . strtoupper(base_convert((string) (microtime(true) * 1000), 10, 36));

    $pdo->prepare(
        'INSERT INTO orders (code, branch_id, customer_id, sold_by_id, source, status, payment_status, sub_total, total_amount, paid_amount)
         VALUES (?, ?, ?, ?, "POS", "COMPLETED", "PAID", ?, ?, ?)'
    )->execute([$code, $branchId, $customerId, $user['id'], $subTotal, $subTotal, $subTotal]);
    $orderId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total) VALUES (?,?,?,?,?)'
    );
    foreach ($lineData as [$productId, $quantity, $unitPrice, $lineTotal]) {
        $itemStmt->execute([$orderId, $productId, $quantity, $unitPrice, $lineTotal]);
    }

    $pdo->prepare('INSERT INTO payments (order_id, method, amount) VALUES (?,?,?)')
        ->execute([$orderId, $paymentMethod, $subTotal]);

    if ($customerId) {
        $points = (int) floor($subTotal / 10000);
        $pdo->prepare('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?')
            ->execute([$points, $customerId]);
    }

    $pdo->commit();
    echo json_encode(['order_id' => $orderId, 'code' => $code]);
} catch (RuntimeException $ex) {
    $pdo->rollBack();
    echo json_encode(['error' => $ex->getMessage()]);
} catch (Throwable $ex) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Không thể tạo đơn hàng, vui lòng thử lại']);
}
