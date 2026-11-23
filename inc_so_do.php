<?php
/** Du lieu dung chung cho So do chuc nang va trang so do nghiep vu cua tung nhom menu (nhom.php). */
function soDoGiaiDoan(): array
{
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
            ['🏷️', 'Nhãn hiệu', 'Thương hiệu của sản phẩm', 'brands.php'],
            ['🛡️', 'Chính sách bảo hành', 'Thời hạn bảo hành theo hàng', 'warranty_policies.php'],
            ['🚚', 'Cấu hình giao hàng', 'Phí giao hàng gợi ý, đối tác', 'shipping_settings.php'],
            ['📣', 'Cấu hình kênh marketing', 'Kênh gửi tin cho khách', 'marketing_settings.php'],
            ['📥', 'Nhập từ QLBH-SOFT', 'Chuyển dữ liệu từ bản máy tính', 'import_from_soft.php'],
            ['⏳', 'Yêu cầu gia hạn', 'Gia hạn gói dùng thử', 'gia_han.php'],
            ['⚙️', 'Cấu hình', 'Cửa hàng, thanh toán, thuế, in', 'settings.php'],
            ['🧪', 'Dữ liệu mẫu', 'Xem thử 1 tuần bán hàng, xóa để bắt đầu', 'du_lieu_mau.php'],
        ]],
        ['2. Cung ứng & kho', 'Nhập hàng, kiểm kê, luân chuyển', [
            ['🚚', 'Nhà cung cấp', 'Danh sách và công nợ NCC', 'suppliers.php'],
            ['📝', 'Đặt hàng nhập', 'Lập đơn đặt, nhận hàng về kho', 'purchase_orders.php'],
            ['📥', 'Nhập hàng', 'Phiếu nhập, trả tiền NCC', 'stock_receipts.php'],
            ['↩️', 'Trả hàng NCC', 'Trả hàng, giảm công nợ', 'supplier_returns.php'],
            ['🏭', 'Quản lý kho', 'Tồn kho, lô hạn dùng, nhập tồn', 'inventory.php'],
            ['📋', 'Kiểm hàng', 'Đếm thực tế (kiểm theo nhóm hàng), cân bằng kho', 'stock_takes.php'],
            ['📆', 'Lô hàng & hạn dùng', 'Cảnh báo hàng sắp hết hạn, nhập lô', 'batches.php'],
            ['🗑️', 'Xuất hủy hàng', 'Hết hạn, hư hỏng, mất — có lý do', 'xuat_huy.php'],
            ['🏭', 'Sản xuất, giá thành', 'Định mức nguyên liệu, phiếu sản xuất, giá vốn thành phẩm', 'san_xuat.php'],
            ['🐌', 'Hàng tồn lâu / bán chậm', 'Vốn đang chôn vào hàng ế', 'ton_cham.php'],
            ['🧹', 'Kiểm tra dữ liệu', 'Giá bán < giá vốn, trùng mã, tồn âm, khách trùng SĐT', 'kiem_tra_du_lieu.php'],
            ['🔁', 'Chuyển hàng', 'Giữa các chi nhánh', 'stock_transfers.php'],
            ['💡', 'Gợi ý nhập hàng', 'Hàng sắp hết, nên nhập', 'reorder_suggestions.php'],
            ['🧾', 'Điều chỉnh giá vốn', 'Sửa giá vốn có lịch sử', 'price_adjustments.php'],
            ['🏷️', 'In tem giá / mã vạch', 'Tem dán hàng', 'product_labels.php'],
        ]],
        ['3. Bán hàng & vận chuyển', 'Hằng ngày tại quầy và giao hàng', [
            ['🛒', 'Bán hàng (POS)', 'Bán tại quầy, quét mã, in hóa đơn', 'pos.php'],
            ['🕒', 'Mở ca / đóng ca', 'Kiểm tiền đầu ca, cuối ca', 'ca_ban_hang.php'],
            ['🚛', 'Tạo đơn giao hàng', 'Đơn đặt trước, giao tận nơi', 'order_form.php'],
            ['📃', 'Danh sách đơn hàng', 'Duyệt, đóng gói, thu tiền, sửa đơn', 'orders.php'],
            ['📝', 'Báo giá', 'Lập, in báo giá và chuyển thành đơn hàng', 'quotes.php'],
            ['📦', 'Vận chuyển & COD', 'Vận đơn, đối soát thu hộ', 'shipments.php'],
            ['↩️', 'Đơn trả hàng', 'Khách trả hàng, hoàn tiền', 'order_returns.php'],
            ['⏰', 'Đơn online chờ thanh toán', 'Nhắc khách chưa trả sau 1 giờ', 'don_cho_thanh_toan.php'],
            ['🛡️', 'Bảo hành', 'Thẻ bảo hành, yêu cầu bảo hành', 'warranty_cards.php'],
            ['🔧', 'Yêu cầu bảo hành', 'Tiếp nhận và xử lý bảo hành', 'warranty_claims.php'],
            ['🛍️', 'Kênh bán hàng', 'Shopee, Facebook, tại quầy...', 'channels.php'],
            ['🎫', 'Mã giảm giá', 'Coupon cho khách', 'coupons.php'],
            ['📢', 'Chiến dịch marketing', 'Gửi ưu đãi cho nhóm khách', 'campaigns.php'],
            ['🔔', 'Nhắc nợ khách hàng', 'Khách còn nợ lâu ngày', 'customer_debt_reminders.php'],
            ['🎟️', 'Khuyến mại & mã giảm giá', 'Chương trình, coupon, quà tặng', 'promotions.php'],
            ['🌐', 'Cửa hàng online', 'Link đặt hàng riêng cho khách', 'online_shop_settings.php'],
        ]],
        ['4. Hậu kiểm & báo cáo', 'Đối soát, xem kết quả', [
            ['💵', 'Sổ quỹ', 'Thu chi tiền mặt, chuyển khoản', 'cashbook.php'],
            ['📊', 'Báo cáo', 'Doanh thu, lãi gộp, tồn kho', 'reports.php'],
            ['🧮', 'Kế toán & Thuế', 'Sổ sách, thuế, kết chuyển', 'accounting.php'],
            ['🧾', 'Chi phí kinh doanh', 'Phân loại khoản chi được trừ thuế, cảnh báo, khóa sổ', 'chi_phi.php'],
            ['📑', 'Sổ hóa đơn', 'Hóa đơn bán ra, mua vào, đơn chưa có hóa đơn', 'hoa_don.php'],
            ['🏗️', 'Tài sản và phân bổ', 'Khấu hao, chi phí trả trước, doanh thu nhận trước', 'phan_bo.php'],
            ['🔢', 'Kiểm kê quỹ', 'Đếm tiền mặt theo mệnh giá, so với sổ quỹ', 'kiem_ke_quy.php'],
            ['🧾', 'Tờ khai thuế (01/CNKD, 02/QTT...)', 'Tờ khai quý, quyết toán TNCN, giảm 30% NQ 43, bảng kê thu mua, thông báo doanh thu', 'to_khai_thue.php'],
            ['📋', 'Sổ thuế khác (S3a)', 'Thuế xuất nhập khẩu, tiêu thụ đặc biệt, bảo vệ môi trường...', 'thue_khac.php'],
            ['🏦', 'Đối soát chuyển khoản', 'Tự ghi thu khi khách chuyển khoản', 'bank_reconcile.php'],
            ['📒', 'Công nợ phải thu/trả', 'Khách nợ, nợ nhà cung cấp, xuất Excel', 'cong_no.php'],
            ['🔎', 'Đối chiếu số liệu', 'Tự tìm chỗ sổ sách không khớp', 'doi_chieu.php'],
            ['🚨', 'Cảnh báo bất thường', 'Huỷ đơn nhiều, chênh lệch ca, hao hụt, nợ quá hạn', 'canh_bao.php'],
            ['💬', 'Trợ lý hỏi đáp', 'Hỏi bằng tiếng Việt: hôm nay bán bao nhiêu...', 'tro_ly.php'],
            ['📈', 'Phân tích kinh doanh', 'Dòng tiền 14 ngày, ABC, khách sắp bỏ đi, combo', 'phan_tich.php'],
            ['⏱️', 'Chấm công & lương của tôi', 'Vào/ra ca, lương tạm tính', 'cham_cong_cua_toi.php'],
            ['🗓️', 'Lịch làm việc', 'Phân ca cho nhân viên', 'work_schedules.php'],
            ['💰', 'Bảng lương', 'Tính lương theo giờ công và hoa hồng', 'payroll.php'],
            ['👤', 'Hồ sơ nhân sự', 'Hợp đồng, bảo hiểm, người phụ thuộc, ngày phép', 'nhan_su.php'],
            ['🏖️', 'Nghỉ phép', 'Gửi đơn nghỉ, duyệt đơn, ngày phép còn lại', 'nghi_phep.php'],
            ['🗓️', 'Chấm công, lịch & bảng lương', 'Quản lý nhân sự, trả lương', 'attendance.php'],
            ['🕵️', 'Nhật ký hoạt động', 'Ai đã làm gì, lúc nào', 'activity_log.php'],
            ['💾', 'Xuất dữ liệu', 'Tải toàn bộ dữ liệu của cửa hàng', 'tenant_export.php'],
        ]],
    ];
    return $stages;
}

function soDoCss(): void
{
    ?>
<style>
  .sdcn-intro { color:#64748b; font-size:14px; margin:0 0 18px; max-width:880px; line-height:1.55; }
  .sdcn-flow { display:flex; align-items:stretch; gap:6px; flex-wrap:wrap; }
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
    <?php
}
