<?php
/**
 * Tao nhanh nha cung cap NGAY TRONG phieu nhap hang - tra ve JSON de phieu nhap chon luon NCC do,
 * khong phai roi phieu dang lap. Muon khai bao day du (nhom, dia chi...) thi vao trang Nha cung cap.
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
$name = mb_substr(post('name'), 0, 255);
$phone = mb_substr(post('phone'), 0, 50) ?: null;
$address = mb_substr(post('address'), 0, 255);
$taxCode = chuanHoaMaSoThue(post('tax_code'));

if ($name === '') {
    traLoi(['error' => 'Vui lòng nhập tên nhà cung cấp'], 400);
}
if ($taxCode === null) {
    traLoi(['error' => 'Mã số thuế bắt buộc nhập, gồm 10 số (hoặc 13 số: 10 số + 3 số chi nhánh)'], 400);
}
if ($address === '') {
    traLoi(['error' => 'Vui lòng nhập địa chỉ nhà cung cấp'], 400);
}
$dup = $pdo->prepare('SELECT id, name FROM suppliers WHERE tenant_id = ? AND LOWER(name) = LOWER(?)');
$dup->execute([$tenantId, $name]);
if ($row = $dup->fetch()) {
    // Da co NCC cung ten: dung lai thay vi tao trung
    traLoi(['id' => (int) $row['id'], 'name' => $row['name'], 'existing' => true]);
}
$pdo->prepare('INSERT INTO suppliers (name, phone, address, tenant_id, tax_code) VALUES (?,?,?,?,?)')
    ->execute([$name, $phone, $address, $tenantId, $taxCode]);
traLoi(['id' => (int) $pdo->lastInsertId(), 'name' => $name]);
