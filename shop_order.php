<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

$shopQ = preg_match('/^[a-zA-Z0-9-]{3,60}$/', (string) ($_POST['s'] ?? '')) ? 's=' . urlencode((string) $_POST['s']) . '&' : '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('shop.php');

if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    redirect('shop.php?' . $shopQ . 'err=' . urlencode('Phiên làm việc đã hết hạn, vui lòng tải lại trang và thử lại.'));
}

$pdo = db();
// Cua hang nhan don: theo slug gui kem tu shop.php (rong = cua hang #1).
$shopSlug = (string) ($_POST['s'] ?? '');
$shopTenantId = shopTenantFromSlug($shopSlug);
if ($shopTenantId === 0) {
    http_response_code(404);
    exit('Không tìm thấy cửa hàng.');
}

$enabled = getSettingForTenant($shopTenantId, 'online_shop_enabled', '1') === '1';
$branch = $pdo->prepare("SELECT id FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY id LIMIT 1");
$branch->execute([$shopTenantId]);
$branch = $branch->fetch();

$customerName = post('customer_name');
$customerPhone = preg_replace('/\D/', '', post('customer_phone'));
$customerAddress = post('customer_address');
$note = post('note') ?: null;
$qtyInput = $_POST['qty'] ?? [];

// Khach quet QR tai quay/ban: nhan tai cua hang (khong can dia chi), vi tri (vd "Ban 3") ghi vao ghi chu don.
$tableLabel = mb_substr(trim(preg_replace('/[^\p{L}\p{N} .\-]/u', '', (string) ($_POST['t'] ?? ''))), 0, 40);
$isPickup = ($_POST['receive'] ?? 'delivery') === 'pickup';
if ($isPickup) {
    $customerAddress = $tableLabel !== '' ? 'Phục vụ tại chỗ: ' . $tableLabel : 'Nhận tại cửa hàng';
}
if ($customerName === '') {
    $customerName = $tableLabel !== '' ? 'Khách ' . $tableLabel : 'Khách đặt online ' . substr($customerPhone, -4);
}
if ($tableLabel !== '') {
    $note = '[' . $tableLabel . '] ' . ($note ?? '');
}
if ($shopQ !== '' && $tableLabel !== '') {
    $shopQ .= 't=' . urlencode($tableLabel) . '&';
} elseif ($tableLabel !== '') {
    $shopQ = 't=' . urlencode($tableLabel) . '&';
}

if (!$enabled || !$branch) {
    redirect('shop.php?' . $shopQ . 'err=' . urlencode('Cửa hàng hiện chưa mở đặt hàng online.'));
}
if ($customerPhone === '' || $customerAddress === '') {
    redirect('shop.php?' . $shopQ . 'err=' . urlencode('Vui lòng nhập số điện thoại' . ($isPickup ? '.' : ' và địa chỉ nhận hàng.')));
}
if (!is_array($qtyInput)) {
    redirect('shop.php?' . $shopQ . 'err=' . urlencode('Dữ liệu đặt hàng không hợp lệ.'));
}

$allowNegativeStock = getSettingForTenant($shopTenantId, 'allow_negative_stock', '0') === '1';
$branchId = (int) $branch['id'];
$systemUserStmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id = ? AND is_active = 1 ORDER BY id LIMIT 1");
$systemUserStmt->execute([$shopTenantId]);
$systemUserId = (int) $systemUserStmt->fetchColumn();
if (!$systemUserId) {
    redirect('shop.php?' . $shopQ . 'err=' . urlencode('Cửa hàng chưa sẵn sàng nhận đơn online.'));
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
            "SELECT p.id, p.name, p.sell_price, p.cost_price, i.quantity AS stock, i.id AS inventory_id
             FROM products p
             LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
             WHERE p.id = ? AND p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'
             FOR UPDATE"
        );
        $stmt->execute([$branchId, $productId, $shopTenantId]);
        $product = $stmt->fetch();
        if (!$product) continue;

        $stock = (float) ($product['stock'] ?? 0);
        if (!$allowNegativeStock && $qty > $stock) {
            throw new RuntimeException('Sản phẩm "' . $product['name'] . '" chỉ còn ' . fmtQty($stock) . ' — vui lòng giảm số lượng.');
        }

        $unitPrice = (float) $product['sell_price'];
        $lineTotal = $unitPrice * $qty;
        $subTotal += $lineTotal;
        $lineData[] = [$productId, $qty, $unitPrice, $lineTotal, $product['inventory_id'], (float) $product['cost_price']];
    }

    if (!$lineData) {
        throw new RuntimeException('Vui lòng chọn ít nhất 1 sản phẩm để đặt hàng.');
    }

    $customerId = null;
    $custStmt = $pdo->prepare('SELECT id FROM customers WHERE phone = ? AND tenant_id = ?');
    $custStmt->execute([$customerPhone, $shopTenantId]);
    $existingCustomer = $custStmt->fetch();
    if ($existingCustomer) {
        $customerId = (int) $existingCustomer['id'];
    } else {
        $custCode = genCode('CUZN');
        $pdo->prepare('INSERT INTO customers (code, name, phone, tenant_id) VALUES (?,?,?,?)')
            ->execute([$custCode, $customerName, $customerPhone, $shopTenantId]);
        $customerId = (int) $pdo->lastInsertId();
    }

    $channelStmt = $pdo->prepare("SELECT id FROM sales_channels WHERE tenant_id = ? AND type = 'WEBSITE' AND is_active = 1 ORDER BY id LIMIT 1");
    $channelStmt->execute([$shopTenantId]);
    $channelId = $channelStmt->fetchColumn() ?: null;

    $code = 'DH' . strtoupper(base_convert((string) (int) (microtime(true) * 1000000), 10, 36));
    $pdo->prepare(
        "INSERT INTO orders (code, branch_id, customer_id, sold_by_id, source, status, payment_status, sub_total, total_amount, paid_amount, is_delivery, shipping_address, note, channel_id)
         VALUES (?, ?, ?, ?, 'ONLINE', 'DRAFT', 'UNPAID', ?, ?, 0, 1, ?, ?, ?)"
    )->execute([$code, $branchId, $customerId, $systemUserId, $subTotal, $subTotal, $customerAddress, $note, $channelId]);
    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, changed_by_id) VALUES (?, NULL, "DRAFT", ?)')
        ->execute([$orderId, $systemUserId]);

    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total, cost_price) VALUES (?,?,?,?,?,?)');
    foreach ($lineData as [$productId, $qty, $unitPrice, $lineTotal, $inventoryId, $costPrice]) {
        $itemStmt->execute([$orderId, $productId, $qty, $unitPrice, $lineTotal, $costPrice]);
        if ($inventoryId) {
            $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $inventoryId]);
        }
    }

    $pdo->commit();
    logActivity('SHOP_ORDER_CREATE', "order_id=$orderId code=$code phone=$customerPhone");

    // Bao ngay cho chu cua hang qua email de don online khong bi bo quen (truoc day phai tu vao
    // xem danh sach don). Loi gui mail khong duoc lam hong don da dat thanh cong.
    try {
        $own = $pdo->prepare('SELECT name, owner_email FROM tenants WHERE id = ?');
        $own->execute([$shopTenantId]);
        $ownRow = $own->fetch();
        if ($ownRow && !empty($ownRow['owner_email'])) {
            $oneLine = static fn(string $t): string => mb_substr(trim(preg_replace('/\s+/', ' ', $t)), 0, 200);
            $body = "Có đơn đặt hàng online mới cho cửa hàng {$ownRow['name']}:

"
                . 'Mã đơn: ' . $code . "
"
                . 'Khách: ' . $oneLine($customerName) . ' - ' . $customerPhone . "
"
                . 'Địa chỉ: ' . $oneLine($customerAddress) . "
"
                . 'Tổng tiền: ' . number_format($subTotal, 0, ',', '.') . "đ
"
                . ($note ? 'Ghi chú: ' . $oneLine((string) $note) . "
" : '')
                . "
Đăng nhập QLBH-CLOUD, vào Đơn hàng → Đặt hàng (chờ xử lý) để xác nhận với khách:
"
                . 'https://app.kt-soft.vn/orders.php?status=DRAFT' . "
";
            sendMail($ownRow['owner_email'], '[QLBH-CLOUD] Đơn online mới ' . $code, $body);
        }
    } catch (Throwable $mailEx) {
        error_log('shop_order: khong gui duoc email bao don moi: ' . $mailEx->getMessage());
    }
    redirect('shop.php?' . $shopQ . 'ok=1&code=' . urlencode($code));
} catch (RuntimeException $ex) {
    $pdo->rollBack();
    redirect('shop.php?' . $shopQ . 'err=' . urlencode($ex->getMessage()));
} catch (Throwable $ex) {
    $pdo->rollBack();
    redirect('shop.php?' . $shopQ . 'err=' . urlencode('Không thể đặt hàng lúc này, vui lòng thử lại.'));
}
