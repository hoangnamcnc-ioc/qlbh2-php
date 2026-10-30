<?php
/**
 * Them nhanh danh muc nho (danh muc san pham, nhan hieu, nhom khach hang) NGAY TRONG form dang nhap
 * lieu - tra ve JSON de form chon luon muc vua tao, khong phai roi form sang trang quan ly.
 * Neu da co muc cung ten (khong phan biet hoa thuong) thi dung lai, khong tao trung.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');
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
$type = (string) ($_POST['type'] ?? '');
$name = mb_substr(post('name'), 0, 255);
if ($name === '') {
    traLoi(['error' => 'Vui lòng nhập tên'], 400);
}

$tables = ['category' => 'categories', 'brand' => 'brands', 'customer_group' => 'customer_groups'];
if (!isset($tables[$type])) {
    traLoi(['error' => 'Loại danh mục không hợp lệ'], 400);
}
$table = $tables[$type];

$dup = $pdo->prepare("SELECT id, name FROM $table WHERE tenant_id = ? AND LOWER(name) = LOWER(?)");
$dup->execute([$tenantId, $name]);
if ($row = $dup->fetch()) {
    traLoi(['id' => (int) $row['id'], 'name' => $row['name'], 'existing' => true]);
}

if ($type === 'category') {
    $pdo->prepare('INSERT INTO categories (name, parent_id, tenant_id) VALUES (?,NULL,?)')->execute([$name, $tenantId]);
} elseif ($type === 'brand') {
    $pdo->prepare('INSERT INTO brands (name, tenant_id) VALUES (?,?)')->execute([$name, $tenantId]);
} else {
    $code = 'NHM' . substr((string) (int) round(microtime(true) * 1000), -8);
    $pdo->prepare('INSERT INTO customer_groups (name, code, tenant_id) VALUES (?,?,?)')->execute([$name, $code, $tenantId]);
}
traLoi(['id' => (int) $pdo->lastInsertId(), 'name' => $name]);
