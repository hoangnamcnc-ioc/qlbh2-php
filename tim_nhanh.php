<?php
/**
 * Tim nhanh (Ctrl+K) toan he thong: san pham, khach hang, don hang (theo ma), nha cung cap.
 * Moi vai tro deu dung duoc nhung chi thay dung pham vi cua minh: thu ngan chi tim don cua chi nhanh minh va khong tim nha cung cap;
 * vai tro "chat" khong co quyen khach hang thi khong tim khach. Lien ket phu thuoc quyen (quan ly mo thang form, thu ngan mo danh sach).
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$user = requireLogin();
header('Content-Type: application/json; charset=utf-8');

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2 || mb_strlen($q) > 60) {
    echo '{}';
    exit;
}
$pdo = db();
$tenantId = currentTenantId();
$manager = isManagerTier();
$like = '%' . $q . '%';
$out = ['products' => [], 'customers' => [], 'orders' => [], 'suppliers' => []];

$stmt = $pdo->prepare(
    "SELECT id, name, sku, sell_price FROM products
     WHERE tenant_id = ? AND is_active = 1 AND (name LIKE ? OR sku LIKE ? OR barcode LIKE ?) ORDER BY name LIMIT 6"
);
$stmt->execute([$tenantId, $like, $like, $like]);
foreach ($stmt->fetchAll() as $r) {
    $out['products'][] = ['label' => $r['name'], 'sub' => $r['sku'] . ' · ' . money($r['sell_price']),
        'url' => $manager ? 'product_form.php?id=' . (int) $r['id'] : 'products.php?q=' . urlencode($r['sku'])];
}

$strict = !$manager && in_array('strict', currentUserPermissionGroups(), true);
if (!$strict || userHasPermissionGroup('customers') || userHasPermissionGroup('pos')) {
    $stmt = $pdo->prepare(
        "SELECT id, name, phone, code FROM customers WHERE tenant_id = ? AND (name LIKE ? OR phone LIKE ? OR code LIKE ?) ORDER BY name LIMIT 6"
    );
    $stmt->execute([$tenantId, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $out['customers'][] = ['label' => $r['name'], 'sub' => trim(($r['phone'] ?: '') . ' · ' . $r['code'], ' ·'), 'url' => 'customer_view.php?id=' . (int) $r['id']];
    }
}

$sql = "SELECT o.id, o.code, o.status, o.total_amount FROM orders o JOIN branches b ON b.id = o.branch_id WHERE b.tenant_id = ? AND o.code LIKE ?";
$params = [$tenantId, $q . '%'];
if (!$manager) {
    $sql .= ' AND o.branch_id = ?';
    $params[] = effectiveBranchId($user);
}
$stmt = $pdo->prepare($sql . ' ORDER BY o.created_at DESC LIMIT 5');
$stmt->execute($params);
foreach ($stmt->fetchAll() as $r) {
    $out['orders'][] = ['label' => $r['code'], 'sub' => money($r['total_amount']) . ' · ' . $r['status'], 'url' => 'order_view.php?id=' . (int) $r['id']];
}

if ($manager) {
    $stmt = $pdo->prepare("SELECT id, name, phone FROM suppliers WHERE tenant_id = ? AND (name LIKE ? OR phone LIKE ?) ORDER BY name LIMIT 4");
    $stmt->execute([$tenantId, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $out['suppliers'][] = ['label' => $r['name'], 'sub' => (string) $r['phone'], 'url' => 'supplier_view.php?id=' . (int) $r['id']];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
