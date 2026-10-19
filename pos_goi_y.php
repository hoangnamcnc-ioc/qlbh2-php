<?php
/**
 * Goi y nhanh cho man hinh ban hang (POS), moi vai tro dung duoc:
 *   - mac dinh: hang hay ban theo GIO hien tai (28 ngay, +-1 gio) va nhac viec ca (lo sap het han, hang duoi dinh muc)
 *   - ?ids=1,2,3 : thong ke so luong moi dong/san pham (180 ngay) de POS canh bao khi nhap so luong bat thuong
 * Khong tra ve gia von/lai - chi ten, ma, gia ban, so luong.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$user = requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$tenantId = currentTenantId();
$branchId = effectiveBranchId($user);

if (isset($_GET['ids'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_GET['ids'])))));
    $ids = array_slice($ids, 0, 50);
    if (!$ids) {
        echo '{}';
        exit;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT oi.product_id, AVG(oi.quantity) AS tb, MAX(oi.quantity) AS mx, COUNT(*) AS n
         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN branches b ON b.id = o.branch_id
         WHERE b.tenant_id = ? AND oi.product_id IN ($in) AND o.status NOT IN ('CANCELLED','DRAFT')
           AND o.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
         GROUP BY oi.product_id"
    );
    $stmt->execute(array_merge([$tenantId], $ids));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(string) (int) $r['product_id']] = ['tb' => round((float) $r['tb'], 3), 'mx' => (float) $r['mx'], 'n' => (int) $r['n']];
    }
    echo json_encode($out ?: new stdClass());
    exit;
}

$h = (int) date('G');
$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.sku, p.sell_price, SUM(oi.quantity) AS sl
     FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
     WHERE o.branch_id = ? AND p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'
       AND o.status NOT IN ('CANCELLED','DRAFT') AND o.created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)
       AND HOUR(o.created_at) BETWEEN ? AND ?
       AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1)
     GROUP BY p.id, p.name, p.sku, p.sell_price ORDER BY sl DESC LIMIT 8"
);
$stmt->execute([$branchId, $tenantId, max(0, $h - 1), min(23, $h + 1)]);
$hayBan = [];
foreach ($stmt->fetchAll() as $r) {
    $hayBan[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'sku' => $r['sku'], 'price' => (float) $r['sell_price']];
}

$nhac = [];
$stmt = $pdo->prepare(
    "SELECT p.name FROM product_batches b JOIN products p ON p.id = b.product_id
     WHERE b.branch_id = ? AND p.tenant_id = ? AND b.quantity > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
     GROUP BY p.id, p.name ORDER BY MIN(b.expiry_date) LIMIT 4"
);
$stmt->execute([$branchId, $tenantId]);
$het = $stmt->fetchAll(PDO::FETCH_COLUMN);
if ($het) {
    $nhac[] = 'Có hàng sắp/đã hết hạn (3 ngày): ' . implode(', ', $het) . ' — xếp ra phía trước kệ, ưu tiên bán trước hoặc báo quản lý.';
}
$stmt = $pdo->prepare('SELECT COUNT(*) FROM inventory i JOIN products p ON p.id = i.product_id WHERE i.branch_id = ? AND p.tenant_id = ? AND i.min_stock > 0 AND i.quantity <= i.min_stock');
$stmt->execute([$branchId, $tenantId]);
$low = (int) $stmt->fetchColumn();
if ($low > 0) {
    $nhac[] = $low . ' mặt hàng dưới định mức tồn tối thiểu — báo quản lý để nhập thêm.';
}

echo json_encode(['hay_ban' => $hayBan, 'gio' => $h, 'nhac_viec' => $nhac], JSON_UNESCAPED_UNICODE);
