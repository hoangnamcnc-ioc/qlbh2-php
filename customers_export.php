<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$customers = $pdo->query('SELECT code, name, phone, address, debt, loyalty_points FROM customers ORDER BY created_at DESC')->fetchAll();
logActivity('EXPORT_CUSTOMERS', count($customers) . ' khách hàng');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="khach_hang.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['code', 'name', 'phone', 'address', 'debt', 'loyalty_points']);
foreach ($customers as $c) {
    fputcsv($out, [$c['code'], $c['name'], $c['phone'], $c['address'], $c['debt'], $c['loyalty_points']]);
}
fclose($out);
