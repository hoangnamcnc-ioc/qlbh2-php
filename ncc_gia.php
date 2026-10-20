<?php
/**
 * Gia nhap gan nhat theo tung nha cung cap cho 1 mat hang (de phieu nhap hang canh bao "NCC khac re hon"). Chi quan ly.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_thong_minh.php';
requireRole('ADMIN', 'MANAGER');
header('Content-Type: application/json; charset=utf-8');

$productId = (int) ($_GET['product_id'] ?? 0);
if (!$productId || !laySanPhamCuaToi($productId)) {
    echo '[]';
    exit;
}
echo json_encode(giaNhapTheoNcc(db(), currentTenantId(), $productId), JSON_UNESCAPED_UNICODE);
