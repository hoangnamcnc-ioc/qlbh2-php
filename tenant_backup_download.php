<?php
/**
 * Tai file sao luu rieng cua 1 tenant qua token ngau nhien co han dung (khong yeu cau dang nhap -
 * chu cua hang bam link ngay trong email tren dien thoai, khong phai dang nhap lai). An toan vi:
 *   - token 32 byte ngau nhien (khong doan duoc), so sanh bang hash_equals (chong timing attack).
 *   - het han sau EXPIRE_DAYS ngay, va CHI DUNG DUOC 1 LAN (xoa dong token ngay sau khi phuc vu).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc_functions.php';

const EXPIRE_DAYS = 7;

$token = $_GET['token'] ?? '';
if ($token === '') {
    http_response_code(404);
    exit('Không tìm thấy file.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM tenant_backup_downloads WHERE token = ?');
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row || !hash_equals($row['token'], $token) || strtotime($row['expires_at']) < time()) {
    http_response_code(404);
    exit('Liên kết đã hết hạn hoặc không tồn tại. Vào app tạo lại ở mục "Xuất dữ liệu của tôi".');
}

$path = tenantBackupDir() . '/' . $row['filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Không tìm thấy file (có thể đã bị dọn tự động).');
}

// Dung 1 lan: xoa dong token ngay truoc khi tra file, tranh nguoi khac vo tinh cam duoc link
// (forward email...) tai lai duoc nhieu lan trong 7 ngay.
$pdo->prepare('DELETE FROM tenant_backup_downloads WHERE id = ?')->execute([$row['id']]);

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $row['filename'] . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
@unlink($path);
