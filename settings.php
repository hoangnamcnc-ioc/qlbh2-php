<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');
require_once __DIR__ . '/inc_header.php';

$sections = [
    'Thiết lập cửa hàng' => [
        ['store_settings.php', '🏪', 'Thông tin cửa hàng', 'Quản lý thông tin liên hệ, địa chỉ và mẫu in hóa đơn'],
        ['payment_settings.php', '💳', 'Thanh toán', 'Khai báo tài khoản ngân hàng để hiện mã QR chuyển khoản'],
        ['display_settings.php', '🎨', 'Tùy chỉnh giao diện', 'Màu sắc, sắp xếp sản phẩm, nút chức năng hiển thị trong POS'],
        ['batches.php', '📦', 'Lô hàng & Hạn sử dụng', 'Theo dõi lô hàng theo hạn sử dụng, tự gợi ý lô cần bán trước'],
        ['branches.php', '🏬', 'Quản lý chi nhánh', 'Thêm mới & quản lý thông tin chi nhánh'],
        ['users.php', '👤', 'Nhân viên và phân quyền', 'Quản lý & phân quyền tài khoản nhân viên'],
        ['tax_rates.php', '📐', 'Thuế', 'Quản lý các mức thuế suất đầu ra, đầu vào'],
        ['price_lists.php', '💵', 'Chính sách giá', 'Tạo và quản lý các bảng giá riêng theo nhóm khách hàng'],
        ['inventory_settings.php', '📦', 'Quản lý kho & Sản phẩm', 'Thiết lập quản lý thông tin về sản phẩm'],
        ['categories.php', '🗂️', 'Danh mục sản phẩm', 'Quản lý danh mục cha/con'],
        ['brands.php', '🏷️', 'Nhãn hiệu', 'Quản lý nhãn hiệu sản phẩm'],
        ['price_adjustments.php', '💰', 'Điều chỉnh giá vốn', 'Lịch sử thay đổi giá vốn sản phẩm'],
        ['accounting.php', '🧾', 'Kế toán và Thuế', 'Hướng dẫn hóa đơn điện tử / khai thuế'],
    ],
    'Thiết lập bán hàng' => [
        ['sales_settings.php', '⚙️', 'Cấu hình bán hàng', 'Thiết lập các cấu hình áp dụng khi bán hàng'],
        ['order_sources.php', '📞', 'Nguồn bán hàng', 'Thêm và quản lý nguồn tạo ra đơn hàng'],
        ['channels.php', '🔗', 'Kênh bán hàng', 'Quản lý các kênh bạn dùng để bán hàng (Shopee, Facebook, Website...)'],
        ['cancel_reasons.php', '↩️', 'Lý do hủy trả', 'Thêm và điều chỉnh lý do khi hủy/trả đơn hàng'],
        ['gifts.php', '🎁', 'Quà đổi điểm', 'Khai báo quà tặng khách hàng đổi bằng điểm tích lũy'],
        ['promotions.php', '🎉', 'Khuyến mại tự động', 'Chương trình giảm giá tự áp dụng theo giá trị đơn'],
        ['coupons.php', '🎟️', 'Mã giảm giá', 'Tạo và quản lý mã giảm giá'],
        ['warranty_policies.php', '🛡️', 'Chính sách bảo hành', 'Thiết lập thời hạn & điều kiện bảo hành'],
        ['campaigns.php', '📣', 'Chiến dịch Marketing', 'Lưu chiến dịch SMS/Email nội bộ'],
    ],
    'Tài chính & Báo cáo' => [
        ['cashbook.php', '📒', 'Sổ quỹ', 'Phiếu thu/chi & tổng hợp thu chi'],
        ['reports.php', '📊', 'Báo cáo', 'Doanh thu, lãi gộp, tồn kho, top sản phẩm/khách hàng'],
    ],
    'Nhật ký' => [
        ['file_logs.php', '📁', 'Xuất/ nhập file', 'Theo dõi và quản lý xuất nhập file của cửa hàng'],
        ['activity_log.php', '🕒', 'Nhật ký hoạt động', 'Quản lý thao tác, nhật ký hoạt động của cửa hàng'],
    ],
];
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Cấu hình</h1>

<?php foreach ($sections as $sectionTitle => $items): ?>
  <h2 style="font-size:15px;font-weight:600;margin:0 0 12px;color:#334155;"><?= e($sectionTitle) ?></h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:28px;">
    <?php foreach ($items as [$href, $icon, $title, $desc]): ?>
      <a href="<?= e($href) ?>" class="card" style="display:flex;gap:12px;text-decoration:none;color:inherit;">
        <div style="font-size:22px;line-height:1;"><?= $icon ?></div>
        <div>
          <div style="font-weight:600;font-size:14px;margin-bottom:2px;color:#1e293b;"><?= e($title) ?></div>
          <div class="muted" style="font-size:12px;"><?= e($desc) ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
