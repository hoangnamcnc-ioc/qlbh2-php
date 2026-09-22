<?php
/**
 * Xuat danh sach don hang ra Excel.
 *
 * Dung LAI DUNG bo loc cua orders.php (trang thai, khoang ngay, nhan vien, kenh, nguon, va gioi
 * han theo chi nhanh voi CASHIER) de file tai ve khop chinh xac nhung gi nguoi dung dang nhin
 * thay - khac di thi ho se tuong file bi sai.
 *
 * Khac 1 diem co chu dich: trang danh sach gioi han LIMIT 100 cho de xem, con file xuat lay
 * toan bo ket qua khop bo loc (co chan tran de khong lam sap bo nho).
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
requireLogin();

$statusLabels = [
    'DRAFT' => 'Đặt hàng', 'APPROVED' => 'Duyệt', 'PACKED' => 'Đóng gói',
    'SHIPPED' => 'Xuất kho', 'COMPLETED' => 'Hoàn thành', 'CANCELLED' => 'Đã hủy',
];
$paymentStatusLabels = ['PAID' => 'Đã thanh toán', 'PARTIAL' => 'Trả một phần', 'UNPAID' => 'Chưa thanh toán'];

$status = $_GET['status'] ?? '';
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';
$staffId = (int) ($_GET['staff_id'] ?? 0);
$channelId = (int) ($_GET['channel_id'] ?? 0);
$sourceId = (int) ($_GET['source_id'] ?? 0);

$pdo = db();
$currentUser = currentUser();
$tenantId = currentTenantId();

$sql = 'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone, u.name AS staff_name,
               b.name AS branch_name, sc.name AS channel_name, os.name AS source_name
        FROM orders o
        JOIN branches b ON b.id = o.branch_id
        LEFT JOIN customers c ON c.id = o.customer_id
        JOIN users u ON u.id = o.sold_by_id
        LEFT JOIN sales_channels sc ON sc.id = o.channel_id
        LEFT JOIN order_sources os ON os.id = o.source_id';
$where = ['b.tenant_id = ?'];
$params = [$tenantId];

if ($status !== '' && array_key_exists($status, $statusLabels)) {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if ($fromDate !== '') {
    $where[] = 'o.created_at >= ?';
    $params[] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $where[] = 'o.created_at <= ?';
    $params[] = $toDate . ' 23:59:59';
}
if ($staffId) {
    $where[] = 'o.sold_by_id = ?';
    $params[] = $staffId;
}
if ($channelId) {
    $where[] = 'o.channel_id = ?';
    $params[] = $channelId;
}
if ($sourceId) {
    $where[] = 'o.source_id = ?';
    $params[] = $sourceId;
}
// Thu ngan (CASHIER) chi xuat duoc don hang cua chi nhanh minh - giong het rang buoc o orders.php,
// neu khong thi ho se lay duoc toan bo don cua chuoi qua duong tai file.
if (!hasRole('ADMIN', 'MANAGER')) {
    $where[] = 'o.branch_id = ?';
    $params[] = effectiveBranchId($currentUser);
}
$sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY o.created_at DESC LIMIT 20000';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$rows = [[
    'ma_don_hang', 'ngay_tao', 'khach_hang', 'dien_thoai', 'chi_nhanh', 'nhan_vien_ban',
    'kenh_ban', 'nguon', 'trang_thai', 'thanh_toan',
    'tien_hang', 'chiet_khau', 'phi_giao_hang', 'tong_tien', 'da_thu', 'con_lai', 'ghi_chu',
]];
foreach ($orders as $o) {
    $conLai = (float) $o['total_amount'] - (float) $o['paid_amount'];
    $rows[] = [
        $o['code'],
        $o['created_at'],
        $o['customer_name'] ?: 'Khách lẻ',
        $o['customer_phone'] ?: '',
        $o['branch_name'],
        $o['staff_name'],
        $o['channel_name'] ?: '',
        $o['source_name'] ?: ($o['source'] ?: ''),
        $statusLabels[$o['status']] ?? $o['status'],
        $paymentStatusLabels[$o['payment_status']] ?? $o['payment_status'],
        $o['sub_total'],
        $o['discount'],
        $o['shipping_fee'],
        $o['total_amount'],
        $o['paid_amount'],
        $conLai,
        $o['note'] ?: '',
    ];
}

logActivity('EXPORT_ORDERS', 'so_dong=' . count($orders));
downloadXlsx($rows, 'don_hang.xlsx');
