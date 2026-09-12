<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('shop.php');

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    redirect('shop.php?err=' . urlencode('Phiên làm việc đã hết hạn, vui lòng tải lại trang và thử lại.'));
}

$pdo = db();
$enabled = getSetting('online_shop_enabled', '1') === '1';
$branch = $pdo->query("SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();

$customerName = post('customer_name');
$customerPhone = preg_replace('/\D/', '', post('customer_phone'));
$customerAddress = post('customer_address');
$note = post('note') ?: null;
$qtyInput = $_POST['qty'] ?? [];

if (!$enabled || !$branch) {
    redirect('shop.php?err=' . urlencode('Cửa hàng hiện chưa mở đặt hàng online.'));
}
if ($customerName === '' || $customerPhone === '' || $customerAddress === '') {
    redirect('shop.php?err=' . urlencode('Vui lòng nhập đầy đủ họ tên, số điện thoại và địa chỉ nhận hàng.'));
}
if (!is_array($qtyInput)) {
    redirect('shop.php?err=' . urlencode('Dữ liệu đặt hàng không hợp lệ.'));
}

$allowNegativeStock = getSetting('allow_negative_stock', '0') === '1';
$branchId = (int) $branch['id'];
$systemUserId = (int) $pdo->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$systemUserId) {
    redirect('shop.php?err=' . urlencode('Cửa hàng chưa sẵn sàng nhận đơn online.'));
}

try {
    $pdo->beginTransaction();

    $lineData = [];
    $subTotal = 0.0;
    foreach ($qtyInput as $productId => $qty) {
        $productId = (int) $productId;
        $qty = round((float) $qty, 3);
        if ($productId <= 0 || $qty <= 0) continue;

        $stmt = $pdo->prepare(
            "SELECT p.id, p.name, p.sell_price, i.quantity AS stock, i.id AS inventory_id
             FROM products p
             LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
             WHERE p.id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'
             FOR UPDATE"
        );
        $stmt->execute([$branchId, $productId]);
        $product = $stmt->fetch();
        if (!$product) continue;

        $stock = (float) ($product['stock'] ?? 0);
        if (!$allowNegativeStock && $qty > $stock) {
            throw new RuntimeException('Sản phẩm "' . $product['name'] . '" chỉ còn ' . fmtQty($stock) . ' — vui lòng giảm số lượng.');
        }

        $unitPrice = (float) $product['sell_price'];
        $lineTotal = $unitPrice * $qty;
        $subTotal += $lineTotal;
        $lineData[] = [$productId, $qty, $unitPrice, $lineTotal, $product['inventory_id']];
    }

    if (!$lineData) {
        throw new RuntimeException('Vui lòng chọn ít nhất 1 sản phẩm để đặt hàng.');
    }

    $customerId = null;
    $custStmt = $pdo->prepare('SELECT id FROM customers WHERE phone = ?');
    $custStmt->execute([$customerPhone]);
    $existingCustomer = $custStmt->fetch();
    if ($existingCustomer) {
        $customerId = (int) $existingCustomer['id'];
    } else {
        $custCode = genCode('CUZN');
        $pdo->prepare('INSERT INTO customers (code, name, phone) VALUES (?,?,?)')
            ->execute([$custCode, $customerName, $customerPhone]);
        $customerId = (int) $pdo->lastInsertId();
    }

    $channelStmt = $pdo->query("SELECT id FROM sales_channels WHERE type = 'WEBSITE' AND is_active = 1 ORDER BY id LIMIT 1");
    $channelId = $channelStmt->fetchColumn() ?: null;

    $code = 'DH' . strtoupper(base_convert((string) (microtime(true) * 1000), 10, 36));
    $pdo->prepare(
        "INSERT INTO orders (code, branch_id, customer_id, sold_by_id, source, status, payment_status, sub_total, total_amount, paid_amount, is_delivery, shipping_address, note, channel_id)
         VALUES (?, ?, ?, ?, 'ONLINE', 'DRAFT', 'UNPAID', ?, ?, 0, 1, ?, ?, ?)"
    )->execute([$code, $branchId, $customerId, $systemUserId, $subTotal, $subTotal, $customerAddress, $note, $channelId]);
    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, changed_by_id) VALUES (?, NULL, "DRAFT", ?)')
        ->execute([$orderId, $systemUserId]);

    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
    foreach ($lineData as [$productId, $qty, $unitPrice, $lineTotal, $inventoryId]) {
        $itemStmt->execute([$orderId, $productId, $qty, $unitPrice, $lineTotal]);
        if ($inventoryId) {
            $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $inventoryId]);
        }
    }

    $pdo->commit();
    logActivity('SHOP_ORDER_CREATE', "order_id=$orderId code=$code phone=$customerPhone");
    redirect('shop.php?ok=1&code=' . urlencode($code));
} catch (RuntimeException $ex) {
    $pdo->rollBack();
    redirect('shop.php?err=' . urlencode($ex->getMessage()));
} catch (Throwable $ex) {
    $pdo->rollBack();
    redirect('shop.php?err=' . urlencode('Không thể đặt hàng lúc này, vui lòng thử lại.'));
}
