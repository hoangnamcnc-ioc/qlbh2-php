<?php
/**
 * Tao nhanh san pham moi NGAY TRONG phieu nhap hang (khi hang chua co trong danh muc) - tra ve JSON
 * de phieu nhap them luon dong hang do, khong phai roi phieu dang lap do sang trang Them san pham.
 * Chi nhan cac truong toi thieu; muon khai bao day du (danh muc, thuong hieu, bao hanh...) thi vao
 * trang san pham sau.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');
header('Content-Type: application/json; charset=utf-8');

function traLoi(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    traLoi(['error' => 'Chỉ chấp nhận POST'], 405);
}
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    traLoi(['error' => 'Phiên làm việc không hợp lệ, vui lòng tải lại trang.'], 403);
}

$pdo = db();
$tenantId = currentTenantId();

$name = post('name');
$sku = post('sku');
$barcode = post('barcode') ?: null;
$unit = post('unit') ?: null;
$costPrice = max(0, postFloat('cost_price'));
$sellPrice = max(0, postFloat('sell_price'));

if ($name === '') {
    traLoi(['error' => 'Vui lòng nhập tên hàng'], 400);
}
if ($sku === '') {
    $sku = genCode('SP');
}

$check = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND tenant_id = ?');
$check->execute([$sku, $tenantId]);
if (!$check->fetch()) {
    $check = $pdo->prepare('SELECT id FROM product_variants WHERE sku = ? AND tenant_id = ?');
    $check->execute([$sku, $tenantId]);
}
if ($check->fetch()) {
    traLoi(['error' => 'Mã SKU "' . $sku . '" đã có trong danh mục, hãy tìm và chọn hàng đó thay vì tạo mới.'], 409);
}
if ($barcode !== null) {
    $check = $pdo->prepare('SELECT name FROM products WHERE barcode = ? AND tenant_id = ?');
    $check->execute([$barcode, $tenantId]);
    $dup = $check->fetch();
    if ($dup) {
        traLoi(['error' => 'Mã vạch này đã thuộc về hàng "' . $dup['name'] . '", hãy tìm và chọn hàng đó.'], 409);
    }
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "INSERT INTO products (sku, barcode, name, unit, pack_size, cost_price, sell_price, product_type, tenant_id)
         VALUES (?,?,?,?,1,?,?,'PRODUCT',?)"
    )->execute([$sku, $barcode, $name, $unit, $costPrice, $sellPrice, $tenantId]);
    $id = (int) $pdo->lastInsertId();

    $branchId = effectiveBranchId($currentUser);
    if ($branchId) {
        $pdo->prepare('INSERT INTO inventory (branch_id, product_id, quantity, min_stock) VALUES (?,?,0,0)')
            ->execute([$branchId, $id]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    traLoi(['error' => 'Không tạo được hàng mới, vui lòng thử lại.'], 500);
}

traLoi([
    'id' => $id, 'variant_id' => null, 'sku' => $sku, 'barcode' => $barcode, 'name' => $name,
    'cost_price' => $costPrice, 'pack_unit' => null, 'pack_size' => 1,
]);
