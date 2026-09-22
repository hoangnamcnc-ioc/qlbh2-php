<?php
/**
 * Sao chep don hang (§4.3 dac ta) - khach dat lai y het don cu la viec rat hay gap.
 *
 * KHONG tao don moi ngay tai day. Thay vao do nap san noi dung don cu vao form tao don giao hang
 * roi cho nguoi dung xem lai truoc khi bam tao. Hai ly do:
 *   - Tao thang se phai viet lai toan bo phan kiem ton kho / combo / bien the / san gia - tuc la
 *     nhan ban pos_checkout.php mot lan nua, va ban sao do chac chan se thieu sot.
 *   - Hang trong don cu co the da het, hoac gia da doi. Bat nguoi dung nhin lai 1 lan la dung.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}
checkCsrf();

$orderId = (int) ($_POST['order_id'] ?? 0);
$order = layDonHangCuaToi($orderId);
if (!$order) {
    redirect('orders.php');
}

$pdo = db();
$itemsStmt = $pdo->prepare(
    "SELECT oi.product_id, oi.variant_id, oi.quantity, oi.unit_price,
            CASE WHEN v.id IS NULL THEN p.name ELSE CONCAT(p.name, ' - ', v.name) END AS name
     FROM order_items oi
     JOIN products p ON p.id = oi.product_id
     LEFT JOIN product_variants v ON v.id = oi.variant_id
     WHERE oi.order_id = ?"
);
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

if (!$items) {
    redirect('order_view.php?id=' . $orderId);
}

$customer = ['phone' => '', 'name' => ''];
if ($order['customer_id']) {
    $cStmt = $pdo->prepare('SELECT name, phone FROM customers WHERE id = ? AND tenant_id = ?');
    $cStmt->execute([$order['customer_id'], currentTenantId()]);
    $customer = $cStmt->fetch() ?: $customer;
}

$_SESSION['order_copy'] = [
    'from_code' => $order['code'],
    'customer_phone' => $customer['phone'] ?? '',
    'customer_name' => $customer['name'] ?? '',
    'address' => $order['shipping_address'] ?? '',
    'shipping_fee' => (float) $order['shipping_fee'],
    'note' => $order['note'] ?? '',
    'lines' => array_map(static fn(array $it): array => [
        'id' => (int) $it['product_id'],
        'variantId' => $it['variant_id'] !== null ? (int) $it['variant_id'] : null,
        'name' => $it['name'],
        'qty' => (float) $it['quantity'],
        'price' => (float) $it['unit_price'],
    ], $items),
];

logActivity('ORDER_COPY', "tu don {$order['code']}");
redirect('order_form.php');
