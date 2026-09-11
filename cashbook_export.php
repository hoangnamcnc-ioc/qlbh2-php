<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$paymentLabels = ['CASH' => 'Tiền mặt', 'BANK_TRANSFER' => 'Chuyển khoản', 'CARD' => 'Quẹt thẻ', 'QR_CODE' => 'Quét mã QR'];
$typeLabels = ['RECEIPT' => 'Phiếu thu', 'PAYMENT' => 'Phiếu chi'];

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';
$typeFilter = $_GET['type'] ?? '';
$branchFilter = (int) ($_GET['branch_id'] ?? 0);
$paymentFilter = $_GET['payment_method'] ?? '';
$staffFilter = (int) ($_GET['staff_id'] ?? 0);

$where = ['ce.created_at BETWEEN ? AND ?'];
$params = [$fromDt, $toDt];
if ($typeFilter === 'RECEIPT' || $typeFilter === 'PAYMENT') {
    $where[] = 'ce.type = ?';
    $params[] = $typeFilter;
}
if ($branchFilter) {
    $where[] = 'ce.branch_id = ?';
    $params[] = $branchFilter;
}
if (array_key_exists($paymentFilter, $paymentLabels)) {
    $where[] = 'ce.payment_method = ?';
    $params[] = $paymentFilter;
}
if ($staffFilter) {
    $where[] = 'ce.created_by_id = ?';
    $params[] = $staffFilter;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT ce.*, b.name AS branch_name, u.name AS created_by_name, o.code AS order_code, r.code AS receipt_code
     FROM cashbook_entries ce JOIN branches b ON b.id = ce.branch_id JOIN users u ON u.id = ce.created_by_id
     LEFT JOIN orders o ON o.id = ce.order_id
     LEFT JOIN stock_receipts r ON r.id = ce.receipt_id
     WHERE $whereSql ORDER BY ce.created_at DESC"
);
$stmt->execute($params);
$entries = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="so_quy.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['ma_phieu', 'loai', 'nguon', 'chung_tu_lien_quan', 'ly_do', 'hinh_thuc_tt', 'chi_nhanh', 'nguoi_tao', 'ngay', 'so_tien']);
foreach ($entries as $en) {
    fputcsv($out, [
        $en['code'], $typeLabels[$en['type']] ?? $en['type'],
        $en['auto_generated'] ? 'Tự động' : 'Thủ công', $en['order_code'] ?: ($en['receipt_code'] ?: ''),
        $en['reason'], $paymentLabels[$en['payment_method']] ?? $en['payment_method'], $en['branch_name'], $en['created_by_name'],
        $en['created_at'], $en['amount'],
    ]);
}
fclose($out);
