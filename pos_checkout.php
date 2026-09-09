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
            $code = 'CUZN' . substr((string) (int) round(microtime(true) * 1000), -8);
            $pdo->prepare('INSERT INTO customers (code, name, phone) VALUES (?, ?, ?)')
                ->execute([$code, $customerPhone, $customerPhone]);
            $customerId = (int) $pdo->lastInsertId();
        }
    }

    $subTotal = 0.0;
    $lineData = [];

    foreach ($items as $line) {
        $productId = (int) ($line['product_id'] ?? 0);
        $variantId = (int) ($line['variant_id'] ?? 0) ?: null;
        $quantity = (int) ($line['quantity'] ?? 0);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) {
            throw new RuntimeException('Dữ liệu sản phẩm không hợp lệ');
        }

        if ($variantId) {
            $stmt = $pdo->prepare('SELECT * FROM inventory WHERE variant_id = ? AND branch_id = ? FOR UPDATE');
            $stmt->execute([$variantId, $branchId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM inventory WHERE product_id = ? AND branch_id = ? AND variant_id IS NULL FOR UPDATE'
            );
            $stmt->execute([$productId, $branchId]);
        }
        $inv = $stmt->fetch();

        if (!$inv || (int) $inv['quantity'] < $quantity) {
            if ($variantId) {
                $prod = $pdo->prepare(
                    "SELECT CONCAT(p.name, ' - ', v.name) AS name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?"
                );
                $prod->execute([$variantId]);
            } else {
                $prod = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                $prod->execute([$productId]);
            }
            $pname = $prod->fetchColumn() ?: ('#' . $productId);
            throw new RuntimeException("Sản phẩm \"$pname\" không đủ tồn kho");
        }

        $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')
            ->execute([$quantity, $inv['id']]);

        $lineTotal = $unitPrice * $quantity;
        $subTotal += $lineTotal;
        $lineData[] = [$productId, $variantId, $quantity, $unitPrice, $lineTotal];
    }

    // Xác thực mã giảm giá lại phía server (không tin số liệu client gửi lên)
    $discount = 0.0;
    $couponCode = null;
    $couponId = null;
    $rawCouponCode = strtoupper(trim((string) ($input['coupon_code'] ?? '')));
    if ($rawCouponCode !== '') {
        $cStmt = $pdo->prepare('SELECT * FROM coupons WHERE code = ? FOR UPDATE');
        $cStmt->execute([$rawCouponCode]);
        $coupon = $cStmt->fetch();

        if (
            $coupon && $coupon['is_active']
            && (!$coupon['start_date'] || strtotime($coupon['start_date']) <= time())
            && (!$coupon['end_date'] || strtotime($coupon['end_date'] . ' 23:59:59') >= time())
            && (!$coupon['max_uses'] || (int) $coupon['used_count'] < (int) $coupon['max_uses'])
            && $subTotal >= (float) $coupon['min_order_amount']
        ) {
            $discount = $coupon['discount_type'] === 'PERCENT'
                ? $subTotal * (float) $coupon['discount_value'] / 100
                : (float) $coupon['discount_value'];
            $discount = min($discount, $subTotal);
            $couponCode = $coupon['code'];
            $couponId = $coupon['id'];
        }
    }

    // Áp dụng tự động chương trình khuyến mại phù hợp nhất (không cần nhập mã),
    // cộng dồn với giảm giá từ coupon nếu có.
    $promotionId = null;
    $pStmt = $pdo->prepare(
        "SELECT * FROM promotions WHERE is_active = 1 AND min_order_amount <= ?
         AND (start_date IS NULL OR start_date <= CURDATE())
         AND (end_date IS NULL OR end_date >= CURDATE())
         ORDER BY discount_percent DESC LIMIT 1"
    );
    $pStmt->execute([$subTotal]);
    $promotion = $pStmt->fetch();
    if ($promotion) {
        $promoDiscount = $subTotal * (float) $promotion['discount_percent'] / 100;
        $discount = min($subTotal, $discount + $promoDiscount);
        $promotionId = $promotion['id'];
    }

    $totalAmount = $subTotal - $discount;

    $code = 'DH' . strtoupper(base_convert((string) (microtime(true) * 1000), 10, 36));

    $pdo->prepare(
        'INSERT INTO orders (code, branch_id, customer_id, sold_by_id, source, status, payment_status, sub_total, discount, coupon_code, promotion_id, total_amount, paid_amount)
         VALUES (?, ?, ?, ?, "POS", "COMPLETED", "PAID", ?, ?, ?, ?, ?, ?)'
    )->execute([$code, $branchId, $customerId, $user['id'], $subTotal, $discount, $couponCode, $promotionId, $totalAmount, $totalAmount]);
    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, changed_by_id) VALUES (?, NULL, "COMPLETED", ?)')
        ->execute([$orderId, $user['id']]);

    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, variant_id, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)'
    );
    foreach ($lineData as [$productId, $variantId, $quantity, $unitPrice, $lineTotal]) {
        $itemStmt->execute([$orderId, $productId, $variantId, $quantity, $unitPrice, $lineTotal]);
    }

    if ($couponId) {
        $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')->execute([$couponId]);
    }

    $pdo->prepare('INSERT INTO payments (order_id, method, amount) VALUES (?,?,?)')
        ->execute([$orderId, $paymentMethod, $totalAmount]);

    if ($customerId) {
        $points = (int) floor($totalAmount / 10000);
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
