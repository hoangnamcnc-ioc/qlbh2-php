<?php
/**
 * Kiem tra khach trung so dien thoai (chuan hoa: bo ky tu la, 0 dau, +84) - dung khi nhap/sua khach de canh bao ngay thay vi tao ho so trung.
 * ?phone=...&id=<id khach dang sua, de loai tru chinh no>
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_thong_minh.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$n = chuanHoaSdt((string) ($_GET['phone'] ?? ''));
$selfId = (int) ($_GET['id'] ?? 0);
if (strlen($n) < 8) {
    echo '{"dup":[]}';
    exit;
}
$pdo = db();
$tail = substr($n, -8);
$stmt = $pdo->prepare("SELECT id, name, phone FROM customers WHERE tenant_id = ? AND phone IS NOT NULL AND REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '.', ''), '-', ''), '+', '') LIKE ? LIMIT 20");
$stmt->execute([currentTenantId(), '%' . $tail]);
$dup = [];
foreach ($stmt->fetchAll() as $r) {
    if ((int) $r['id'] !== $selfId && chuanHoaSdt($r['phone']) === $n) {
        $dup[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'phone' => $r['phone']];
    }
}
echo json_encode(['dup' => array_slice($dup, 0, 3)], JSON_UNESCAPED_UNICODE);
