<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$isManagerUp = hasRole('ADMIN', 'MANAGER');

$navGroups = [
    'Tổng quan' => ['index.php' => 'Tổng quan', 'huong_dan.php' => 'Hướng dẫn sử dụng'],
    'Bán hàng' => array_merge(
        [
            'pos.php' => 'Bán hàng (POS)',
            'order_form.php' => 'Tạo đơn giao hàng',
            'orders.php' => 'Danh sách đơn hàng',
            'order_returns.php' => 'Đơn trả hàng',
            'shipments.php' => 'Vận chuyển',
        ],
        $isManagerUp ? ['channels.php' => 'Kênh bán hàng'] : []
    ),
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
            'supplier_returns.php' => 'Trả hàng NCC',
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
    $navGroups['Marketing & Khuyến mại'] = [
        'campaigns.php' => 'Chiến dịch',
        'promotions.php' => 'Quản lý khuyến mại',
        'coupons.php' => 'Mã giảm giá',
    ];
    $navGroups['Bảo hành'] = [
        'warranty_cards.php' => 'Phiếu bảo hành',
        'warranty_claims.php' => 'Yêu cầu bảo hành',
        'warranty_policies.php' => 'Chính sách bảo hành',
    ];
    $navGroups['Tài chính & Báo cáo'] = [
        'cashbook.php' => 'Sổ quỹ',
        'reports.php' => 'Báo cáo',
        'accounting.php' => 'Kế toán và Thuế',
    ];
    $navGroups['Nhân sự'] = [
        'users.php' => 'Nhân viên & phân quyền',
        'attendance.php' => 'Chấm công',
        'work_schedules.php' => 'Lịch làm việc',
        'payroll.php' => 'Bảng lương',
    ];
}
if (hasRole('ADMIN')) {
    $navGroups['Cấu hình'] = [
        'settings.php' => 'Cấu hình',
        'shipping_settings.php' => 'Cấu hình giao hàng',
        'marketing_settings.php' => 'Cấu hình kênh marketing',
        'tenant_export.php' => 'Xuất dữ liệu của tôi',
        'gia_han.php' => 'Yêu cầu gia hạn',
    ];
    // Chi ADMIN cua tenant #1 (chu so huu KT-SOFT) moi thay muc quan tri he thong va sao luu toan
    // bo DB - day la cong cu van hanh nen tang, khong phai nghiep vu cua 1 cua hang thong thuong.
    if ((int) ($currentUser['tenant_id'] ?? 0) === 1) {
        $navGroups['Cấu hình']['backup.php'] = 'Sao lưu dữ liệu (toàn hệ thống)';
        $navGroups['Cấu hình']['super_admin_tenants.php'] = 'Quản trị hệ thống (KT-SOFT)';
    }
}

$groupIcons = [
    'Tổng quan' => '📊',
    'Bán hàng' => '🛒',
    'Sản phẩm' => '📦',
    'Khách hàng' => '👤',
    'Marketing & Khuyến mại' => '📣',
    'Bảo hành' => '🛡️',
    'Tài chính & Báo cáo' => '💰',
    'Nhân sự' => '🧑‍💼',
    'Cấu hình' => '⚙️',
];

$currentFile = basename($_SERVER['SCRIPT_NAME']);
$storeLogo = getSetting('store_logo', '');
$brandColor = getSetting('brand_color', '#2563eb');
$brandColorDark = darkenColor($brandColor, 15);

// Canh bao con lai bao nhieu ngay dung thu - chi hien voi ADMIN/MANAGER (nguoi co the quyet
// dinh nang cap), tranh lam phien CASHIER voi thong tin ho khong xu ly duoc. Khong tinh lai o
// checkTrialExpiry() vi ham do chi chan truy cap, khong luu lai so ngay con lai cho UI dung.
$trialDaysLeft = null;
if ($isManagerUp) {
    $tenantRow = db()->prepare('SELECT plan, trial_ends_at FROM tenants WHERE id = ?');
    $tenantRow->execute([$currentUser['tenant_id']]);
    $tenantRow = $tenantRow->fetch();
    if ($tenantRow && $tenantRow['plan'] === 'TRIAL' && $tenantRow['trial_ends_at']) {
        $trialDaysLeft = (int) ceil((strtotime($tenantRow['trial_ends_at']) - time()) / 86400);
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QLBH-CLOUD - Quản lý bán hàng</title>
<?php if ($storeLogo): ?><link rel="icon" href="uploads/store/<?= e($storeLogo) ?>"><?php endif; ?>
<style>
  :root { --brand: <?= e($brandColor) ?>; --brand-dark: <?= e($brandColorDark) ?>; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f8fafc; color: #1e293b; }
  a { color: var(--brand); text-decoration: none; }
  a:hover { text-decoration: underline; }
  .layout { display: flex; min-height: 100vh; }
  .sidebar { width: 250px; flex-shrink: 0; background: #0f172a; color: #cbd5e1; min-height: 100vh; padding: 8px 10px 24px; }
  .sidebar .brand { padding: 12px 10px 16px; font-size: 20px; font-weight: 700; color: #fff; }
  .sidebar .nav-item { display: block; border-radius: 8px; padding: 9px 12px; font-size: 14px; font-weight: 500; color: #cbd5e1; margin-bottom: 2px; }
  .sidebar .nav-item:hover { background: #1e293b; color: #fff; text-decoration: none; }
  .sidebar .nav-item.active { background: var(--brand); color: #fff; }
  .sidebar .group-toggle { display: flex; align-items: center; justify-content: space-between; border-radius: 8px; padding: 9px 12px; font-size: 14px; font-weight: 500; color: #cbd5e1; cursor: pointer; user-select: none; margin-bottom: 2px; }
  .sidebar .group-toggle:hover { background: #1e293b; color: #fff; }
  .sidebar .group-toggle .chevron { font-size: 26px; line-height: 1; color: #94a3b8; transition: transform .15s; }
  .sidebar .group-toggle:hover .chevron { color: #fff; }
  .sidebar .group-toggle.has-active .chevron { color: #cbd5e1; }
  .sidebar .group-toggle.open .chevron { transform: rotate(90deg); }
  .sidebar .nav-icon { display: inline-block; width: 22px; font-size: 16px; text-align: center; margin-right: 4px; }
  .sidebar .group-toggle.has-active { color: #fff; }
  .sidebar .submenu { display: none; margin: 0 0 4px 12px; padding-left: 10px; border-left: 1px solid #1e293b; }
  .sidebar .submenu.open { display: block; }
  .sidebar .submenu a { display: block; border-radius: 6px; padding: 7px 10px; font-size: 13px; color: #94a3b8; margin-bottom: 1px; }
  .sidebar .submenu a:hover { background: #1e293b; color: #fff; text-decoration: none; }
  .sidebar .submenu a.active { background: var(--brand); color: #fff; }
  .main { flex: 1; min-width: 0; }
  .topbar { height: 56px; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 12px; padding: 0 24px; }
  .trial-banner { background: #eff6ff; border-bottom: 1px solid #bfdbfe; color: #1e40af; font-size: 13px; padding: 8px 24px; display: flex; align-items: center; gap: 10px; }
  .trial-banner a { color: #1e40af; font-weight: 600; text-decoration: underline; margin-left: auto; }
  .trial-banner-urgent { background: #fef2f2; border-bottom-color: #fecaca; color: #b91c1c; }
  .trial-banner-urgent a { color: #b91c1c; }
  .content { padding: 24px; }
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; }
  a.card:hover { border-color: #93c5fd; background: #f8fafc; text-decoration: none; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th { text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; background: #f8fafc; padding: 10px 12px; }
  td { padding: 10px 12px; border-top: 1px solid #f1f5f9; }
  tr:hover td { background: #f8fafc; }
  .btn { display: inline-block; background: var(--brand); color: #fff; border: none; border-radius: 6px; padding: 8px 16px; font-size: 14px; cursor: pointer; }
  .btn:hover { background: var(--brand-dark); text-decoration: none; }
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

  /* Responsive - man hinh dien thoai */
  .hamburger { display: none; background: none; border: none; font-size: 22px; cursor: pointer; color: #334155; padding: 4px 8px; margin-right: auto; }
  .sidebar-overlay { display: none; }
  @media (max-width: 860px) {
    .layout { display: block; }
    .hamburger { display: inline-block; }
    .sidebar {
      position: fixed; top: 0; left: 0; height: 100vh; z-index: 200; width: 250px;
      transform: translateX(-100%); transition: transform .2s ease; overflow-y: auto;
    }
    .sidebar.open { transform: translateX(0); }
    .sidebar-overlay.show { display: block; position: fixed; inset: 0; background: rgba(15,23,42,.5); z-index: 150; }
    .main { width: 100%; }
    .topbar { flex-wrap: wrap; height: auto; min-height: 56px; padding: 10px 14px; gap: 8px; }
    .content { padding: 14px; }
    .grid-2 { grid-template-columns: 1fr; }
    table { display: block; overflow-x: auto; white-space: nowrap; }
    .trial-banner { flex-wrap: wrap; padding: 8px 14px; }
  }
</style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand" style="display:flex;align-items:center;gap:8px;">
      <?php if ($storeLogo): ?><img src="uploads/store/<?= e($storeLogo) ?>" alt="" style="width:28px;height:28px;object-fit:contain;border-radius:6px;background:#fff;"><?php endif; ?>
      <span>QLBH-CLOUD</span>
    </div>
    <?php foreach ($navGroups as $label => $links): ?>
      <?php $hasActive = array_key_exists($currentFile, $links); ?>
      <?php $groupIcon = $groupIcons[$label] ?? '•'; ?>
      <?php if (count($links) > 1): ?>
        <div class="group-toggle<?= $hasActive ? ' open has-active' : '' ?>" onclick="toggleGroup(this)">
          <span><span class="nav-icon"><?= $groupIcon ?></span><?= e($label) ?></span>
          <span class="chevron">▸</span>
        </div>
        <div class="submenu<?= $hasActive ? ' open' : '' ?>">
          <?php foreach ($links as $file => $text): ?>
            <a href="<?= e($file) ?>" class="<?= $currentFile === $file ? 'active' : '' ?>"><?= e($text) ?></a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <?php foreach ($links as $file => $text): ?>
          <a href="<?= e($file) ?>" class="nav-item <?= $currentFile === $file ? 'active' : '' ?>"><span class="nav-icon"><?= $groupIcon ?></span><?= e($text) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  </aside>
  <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
  <script>
    function toggleGroup(el) {
      el.classList.toggle('open');
      el.nextElementSibling.classList.toggle('open');
    }
    function toggleSidebar() {
      document.querySelector('.sidebar').classList.toggle('open');
      document.querySelector('.sidebar-overlay').classList.toggle('show');
    }
  </script>
  <div class="main">
    <div class="topbar">
      <button type="button" class="hamburger" onclick="toggleSidebar()" aria-label="Mở menu">☰</button>
      <span><?= e($currentUser['name']) ?> · <span class="muted"><?= e($currentUser['role']) ?></span></span>
      <a href="lock.php" style="color:#64748b;">🔒 Khóa màn hình</a>
      <a href="change_password.php" style="color:#64748b;">Đổi mật khẩu</a>
      <a href="logout.php" style="color:#64748b;">Đăng xuất</a>
    </div>
    <?php if ($trialDaysLeft !== null): ?>
      <div class="trial-banner <?= $trialDaysLeft <= 3 ? 'trial-banner-urgent' : '' ?>">
        <?php if ($trialDaysLeft <= 0): ?>
          ⏳ Bản dùng thử đã hết hạn hôm nay.
        <?php elseif ($trialDaysLeft === 1): ?>
          ⏳ Bản dùng thử QLBH-CLOUD còn <b>1 ngày</b> — sắp hết hạn.
        <?php else: ?>
          ⏳ Bản dùng thử QLBH-CLOUD còn <b><?= $trialDaysLeft ?> ngày</b>.
        <?php endif; ?>
        <a href="gia_han.php">Yêu cầu gia hạn →</a>
      </div>
    <?php endif; ?>
    <div class="content">
