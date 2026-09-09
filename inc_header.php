<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$isManagerUp = hasRole('ADMIN', 'MANAGER');

$navGroups = [
    'Tổng quan' => ['index.php' => 'Tổng quan'],
    'Bán hàng' => ['pos.php' => 'Bán hàng (POS)'],
    'Đơn hàng' => [
        'orders.php' => 'Danh sách đơn hàng',
        'order_returns.php' => 'Đơn trả hàng',
    ],
    'Vận chuyển' => ['shipments.php' => 'Vận chuyển'],
    'Sản phẩm' => array_merge(
        [
            'products.php' => 'Danh sách sản phẩm',
            'inventory.php' => 'Quản lý kho',
        ],
        $isManagerUp ? [
            'stock_takes.php' => 'Kiểm hàng',
            'stock_transfers.php' => 'Chuyển hàng',
            'purchase_orders.php' => 'Đặt hàng nhập',
            'stock_receipts.php' => 'Nhập hàng',
            'suppliers.php' => 'Nhà cung cấp',
            'price_adjustments.php' => 'Điều chỉnh giá vốn',
            'categories.php' => 'Danh mục',
            'brands.php' => 'Nhãn hiệu',
        ] : []
    ),
    'Khách hàng' => array_merge(
        ['customers.php' => 'Danh sách khách hàng'],
        $isManagerUp ? ['groups.php' => 'Nhóm khách hàng'] : []
    ),
];
if ($isManagerUp) {
    $navGroups['Marketing'] = ['campaigns.php' => 'Chiến dịch'];
    $navGroups['Bảo hành'] = [
        'warranty_cards.php' => 'Phiếu bảo hành',
        'warranty_policies.php' => 'Chính sách bảo hành',
    ];
    $navGroups['Sổ quỹ'] = ['cashbook.php' => 'Sổ quỹ'];
    $navGroups['Báo cáo'] = ['reports.php' => 'Báo cáo'];
    $navGroups['Khuyến mại'] = [
        'promotions.php' => 'Quản lý khuyến mại',
        'coupons.php' => 'Mã giảm giá',
    ];
    $navGroups['Kế toán và Thuế'] = ['accounting.php' => 'Kế toán và Thuế'];
    $navGroups['Kênh bán hàng'] = ['channels.php' => 'Kênh bán hàng'];
}
if (hasRole('ADMIN')) {
    $navGroups['Cấu hình'] = ['branches.php' => 'Chi nhánh'];
}

$currentFile = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QLBH2 - Quản lý bán hàng</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f8fafc; color: #1e293b; }
  a { color: #2563eb; text-decoration: none; }
  a:hover { text-decoration: underline; }
  .layout { display: flex; min-height: 100vh; }
  .sidebar { width: 240px; flex-shrink: 0; background: #0f172a; color: #cbd5e1; min-height: 100vh; }
  .sidebar .brand { padding: 16px; font-size: 20px; font-weight: 700; color: #fff; border-bottom: 1px solid #1e293b; }
  .sidebar .group-label { padding: 12px 16px 4px; font-size: 11px; text-transform: uppercase; color: #64748b; }
  .sidebar .group-toggle { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; font-size: 13px; font-weight: 600; color: #e2e8f0; cursor: pointer; user-select: none; }
  .sidebar .group-toggle:hover { background: #1e293b; }
  .sidebar .group-toggle .chevron { font-size: 10px; color: #64748b; transition: transform .15s; }
  .sidebar .group-toggle.open .chevron { transform: rotate(90deg); }
  .sidebar .submenu { display: none; }
  .sidebar .submenu.open { display: block; }
  .sidebar a { display: block; padding: 8px 24px; font-size: 14px; color: #cbd5e1; }
  .sidebar a:hover { background: #1e293b; color: #fff; text-decoration: none; }
  .sidebar a.active { background: #2563eb; color: #fff; }
  .main { flex: 1; min-width: 0; }
  .topbar { height: 56px; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 12px; padding: 0 24px; }
  .content { padding: 24px; }
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th { text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; background: #f8fafc; padding: 10px 12px; }
  td { padding: 10px 12px; border-top: 1px solid #f1f5f9; }
  tr:hover td { background: #f8fafc; }
  .btn { display: inline-block; background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 8px 16px; font-size: 14px; cursor: pointer; }
  .btn:hover { background: #1d4ed8; text-decoration: none; }
  .btn-secondary { background: #f1f5f9; color: #334155; }
  .btn-secondary:hover { background: #e2e8f0; }
  .btn-danger { background: #dc2626; }
  .input, select { width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 14px; }
  label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px; color: #334155; }
  .field { margin-bottom: 16px; }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .alert { padding: 10px 14px; border-radius: 6px; font-size: 14px; margin-bottom: 16px; }
  .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
  .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
  .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; }
  .badge-green { background: #d1fae5; color: #047857; }
  .badge-red { background: #fee2e2; color: #b91c1c; }
  .badge-gray { background: #f1f5f9; color: #475569; }
  .text-right { text-align: right; }
  .text-center { text-align: center; }
  .muted { color: #94a3b8; }
</style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">QLBH2</div>
    <?php foreach ($navGroups as $label => $links): ?>
      <?php $hasActive = array_key_exists($currentFile, $links); ?>
      <?php if (count($links) > 1): ?>
        <div class="group-toggle<?= $hasActive ? ' open' : '' ?>" onclick="toggleGroup(this)">
          <span><?= e($label) ?></span>
          <span class="chevron">▸</span>
        </div>
        <div class="submenu<?= $hasActive ? ' open' : '' ?>">
          <?php foreach ($links as $file => $text): ?>
            <a href="<?= e($file) ?>" class="<?= $currentFile === $file ? 'active' : '' ?>"><?= e($text) ?></a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <?php foreach ($links as $file => $text): ?>
          <a href="<?= e($file) ?>" class="<?= $currentFile === $file ? 'active' : '' ?>" style="padding-top:10px;padding-bottom:10px;font-weight:600;color:#e2e8f0;"><?= e($text) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  </aside>
  <script>
    function toggleGroup(el) {
      el.classList.toggle('open');
      el.nextElementSibling.classList.toggle('open');
    }
  </script>
  <div class="main">
    <div class="topbar">
      <span><?= e($currentUser['name']) ?> · <span class="muted"><?= e($currentUser['role']) ?></span></span>
      <a href="change_password.php" style="color:#64748b;">Đổi mật khẩu</a>
      <a href="logout.php" style="color:#64748b;">Đăng xuất</a>
    </div>
    <div class="content">
