<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$customersStmt = $pdo->prepare('SELECT code, name, phone, address, debt, loyalty_points FROM customers WHERE tenant_id = ? ORDER BY created_at DESC');
$customersStmt->execute([currentTenantId()]);
$customers = $customersStmt->fetchAll();
logActivity('EXPORT_CUSTOMERS', count($customers) . ' khách hàng');

$rows = [['code', 'name', 'phone', 'address', 'debt', 'loyalty_points']];
foreach ($customers as $c) {
    $rows[] = [$c['code'], $c['name'], $c['phone'], $c['address'], $c['debt'], $c['loyalty_points']];
}
downloadXlsx($rows, 'khach_hang.xlsx');
