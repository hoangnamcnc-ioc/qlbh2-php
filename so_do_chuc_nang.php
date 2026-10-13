<?php
/**
 * So do chuc nang: luong lam viec cua cua hang theo 4 buoc (Thiet lap -> Cung ung & kho -> Ban hang
 * & van chuyen -> Hau kiem & bao cao). Bam vao o de mo chuc nang. CHI hien nhung chuc nang tai khoan
 * hien tai duoc phep dung (lay tu chinh menu ben trai, nen luon khop phan quyen).
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
require_once __DIR__ . '/inc_header.php';

// Cac file duoc phep = moi lien ket dang co trong menu cua nguoi dung (inc_header.php dung chung quy tac phan quyen).
$allowed = [];
foreach ($navGroups as $links) {
    foreach (array_keys($links) as $file) {
        $allowed[$file] = true;
    }
}
// Mot so chuc nang khong nam o menu rieng nhung thuoc cung nhom quyen voi trang menu.
$alsoAllowed = [
    'online_shop_settings.php' => 'settings.php',
    'shipping_settings.php' => 'settings.php',
    'cancel_reasons.php' => 'settings.php',
    'tenant_export.php' => 'settings.php',
    'activity_log.php' => 'settings.php',
    'backup.php' => 'settings.php',
];
foreach ($alsoAllowed as $file => $via) {
    if (isset($allowed[$via])) {
        $allowed[$file] = true;
    }
}

/** [icon, tieu de, mo ta, file] */
$stages = [
    ['1. Thiết lập', 'Khai báo một lần, chỉnh khi cần', [
        ['🏬', 'Chi nhánh', 'Thêm chi nhánh, kho bán', 'branches.php'],
        ['👥', 'Nhân viên & phân quyền', 'Tài khoản, vai trò, lương/giờ', 'users.php'],
        ['🛡️', 'Vai trò tùy chỉnh', 'Thủ kho, thu quỹ... theo khu vực', 'custom_roles.php'],
        ['📦', 'Sản phẩm', 'Hàng hóa, biến thể, combo, giá', 'products.php'],
        ['🗂️', 'Danh mục & nhãn hiệu', 'Phân loại sản phẩm', 'categories.php'],
        ['💲', 'Bảng giá', 'Giá theo nhóm khách', 'price_lists.php'],
        ['🙋', 'Khách hàng', 'Hồ sơ, công nợ, điểm', 'customers.php'],
        ['🏅', 'Nhóm & hạng khách', 'Nhóm, hạng thành viên, ưu đãi', 'groups.php'],
        ['⚙️', 'Cấu hình', 'Cửa hàng, thanh toán, thuế, in', 'settings.php'],
        ['🧪', 'Dữ liệu mẫu', 'Xem thử 1 tuần bán hàng, xóa để bắt đầu', 'du_lieu_mau.php'],
    ]],
    ['2. Cung ứng & kho', 'Nhập hàng, kiểm kê, luân chuyển', [
        ['🚚', 'Nhà cung cấp', 'Danh sách và công nợ NCC', 'suppliers.php'],
        ['📝', 'Đặt hàng nhập', 'Lập đơn đặt, nhận hàng về kho', 'purchase_orders.php'],
        ['📥', 'Nhập hàng', 'Phiếu nhập, trả tiền NCC', 'stock_receipts.php'],
        ['↩️', 'Trả hàng NCC', 'Trả hàng, giảm công nợ', 'supplier_returns.php'],
        ['🏭', 'Quản lý kho', 'Tồn kho, lô hạn dùng, nhập tồn', 'inventory.php'],
        ['📋', 'Kiểm hàng', 'Đếm thực tế, cân bằng kho', 'stock_takes.php'],
        ['🔁', 'Chuyển hàng', 'Giữa các chi nhánh', 'stock_transfers.php'],
        ['💡', 'Gợi ý nhập hàng', 'Hàng sắp hết, nên nhập', 'reorder_suggestions.php'],
        ['🏷️', 'In tem giá / mã vạch', 'Tem dán hàng', 'product_labels.php'],
    ]],
    ['3. Bán hàng & vận chuyển', 'Hằng ngày tại quầy và giao hàng', [
        ['🛒', 'Bán hàng (POS)', 'Bán tại quầy, quét mã, in hóa đơn', 'pos.php'],
        ['🕒', 'Mở ca / đóng ca', 'Kiểm tiền đầu ca, cuối ca', 'ca_ban_hang.php'],
        ['🚛', 'Tạo đơn giao hàng', 'Đơn đặt trước, giao tận nơi', 'order_form.php'],
        ['📃', 'Danh sách đơn hàng', 'Duyệt, đóng gói, thu tiền, sửa đơn', 'orders.php'],
        ['📦', 'Vận chuyển & COD', 'Vận đơn, đối soát thu hộ', 'shipments.php'],
        ['↩️', 'Đơn trả hàng', 'Khách trả hàng, hoàn tiền', 'order_returns.php'],
        ['🛡️', 'Bảo hành', 'Thẻ bảo hành, yêu cầu bảo hành', 'warranty_cards.php'],
        ['🎟️', 'Khuyến mại & mã giảm giá', 'Chương trình, coupon, quà tặng', 'promotions.php'],
        ['🌐', 'Cửa hàng online', 'Link đặt hàng riêng cho khách', 'online_shop_settings.php'],
    ]],
    ['4. Hậu kiểm & báo cáo', 'Đối soát, xem kết quả', [
        ['💵', 'Sổ quỹ', 'Thu chi tiền mặt, chuyển khoản', 'cashbook.php'],
        ['📊', 'Báo cáo', 'Doanh thu, lãi gộp, tồn kho', 'reports.php'],
        ['🧮', 'Kế toán & Thuế', 'Sổ sách, thuế, kết chuyển', 'accounting.php'],
        ['📒', 'Công nợ phải thu/trả', 'Khách nợ, nợ nhà cung cấp, xuất Excel', 'cong_no.php'],
        ['🔎', 'Đối chiếu số liệu', 'Tự tìm chỗ sổ sách không khớp', 'doi_chieu.php'],
        ['⏱️', 'Chấm công & lương của tôi', 'Vào/ra ca, lương tạm tính', 'cham_cong_cua_toi.php'],
        ['🗓️', 'Chấm công, lịch & bảng lương', 'Quản lý nhân sự, trả lương', 'attendance.php'],
        ['🕵️', 'Nhật ký hoạt động', 'Ai đã làm gì, lúc nào', 'activity_log.php'],
        ['💾', 'Xuất dữ liệu', 'Tải toàn bộ dữ liệu của cửa hàng', 'tenant_export.php'],
    ]],
];
?>
<style>
  .sdcn-intro { color:#64748b; font-size:14px; margin:0 0 18px; max-width:880px; line-height:1.55; }
  .sdcn-flow { display:flex; align-items:stretch; gap:6px; }
  .sdcn-stage { flex:1 1 0; min-width:0; background:#f0f7ff; border:2px solid #cfe3fb; border-radius:18px; padding:14px 14px 16px; }
  .sdcn-stage h2 { font-size:16px; margin:0; color:#0f2a4d; }
  .sdcn-stage .sub { font-size:12.5px; color:#3b82c4; margin:2px 0 12px; }
  .sdcn-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; }
  .sdcn-tile { display:flex; flex-direction:column; align-items:center; text-align:center; gap:4px; background:#fff; border:1px solid #e2e8f0;
    border-radius:14px; padding:12px 8px 10px; color:#0f172a; text-decoration:none; box-shadow:0 1px 3px rgba(15,42,77,.08);
    transition:transform .12s ease, box-shadow .12s ease, border-color .12s ease; }
  .sdcn-tile:hover { transform:translateY(-2px); box-shadow:0 6px 14px rgba(37,99,235,.18); border-color:var(--brand); text-decoration:none; }
  .sdcn-tile .ic { font-size:30px; line-height:1.1; }
  .sdcn-tile .t { font-weight:700; font-size:13.5px; line-height:1.25; }
  .sdcn-tile .d { font-size:11.5px; color:#64748b; line-height:1.3; }
  .sdcn-arrow { flex:0 0 28px; display:flex; align-items:center; justify-content:center; font-size:26px; color:#0ea5e9; font-weight:800; }
  .sdcn-empty { font-size:12.5px; color:#94a3b8; padding:10px 4px; text-align:center; }
  @media (max-width: 1100px) {
    .sdcn-flow { flex-direction:column; }
    .sdcn-arrow { flex-basis:auto; transform:rotate(90deg); padding:2px 0; }
  }
  @media (max-width: 520px) { .sdcn-grid { grid-template-columns:1fr 1fr; } }
</style>

<h1 style="font-size:24px;font-weight:600;margin:0 0 6px;">Sơ đồ chức năng</h1>
<p class="sdcn-intro">
  Luồng làm việc của cửa hàng theo chiều mũi tên: thiết lập → cung ứng & kho → bán hàng & vận chuyển → hậu kiểm & báo cáo.
  Bấm vào ô để mở chức năng; chỉ hiện những chức năng bạn được phép dùng.
</p>

<div class="sdcn-flow">
  <?php foreach ($stages as $i => [$title, $sub, $tiles]): ?>
    <?php if ($i > 0): ?><div class="sdcn-arrow" aria-hidden="true">➜</div><?php endif; ?>
    <section class="sdcn-stage">
      <h2><?= e($title) ?></h2>
      <div class="sub"><?= e($sub) ?></div>
      <?php $visible = array_filter($tiles, static fn($t) => isset($allowed[$t[3]])); ?>
      <?php if (!$visible): ?>
        <div class="sdcn-empty">Bạn chưa có chức năng nào ở bước này.</div>
      <?php else: ?>
        <div class="sdcn-grid">
          <?php foreach ($visible as [$icon, $name, $desc, $file]): ?>
            <a class="sdcn-tile" href="<?= e($file) ?>">
              <span class="ic"><?= $icon ?></span>
              <span class="t"><?= e($name) ?></span>
              <span class="d"><?= e($desc) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
