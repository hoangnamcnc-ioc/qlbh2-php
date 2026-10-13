<?php
require_once __DIR__ . '/inc_legal.php';
legalPageStart('Chính sách bảo mật');
?>
    <h1>Chính sách bảo mật và dữ liệu cá nhân</h1>
    <p class="muted">Áp dụng cho <?= htmlspecialchars(LEGAL_PRODUCT) ?> · <?= htmlspecialchars(LEGAL_COMPANY) ?> · Cập nhật <?= LEGAL_UPDATED ?> · Phiên bản <?= LEGAL_TERMS_VERSION ?></p>

    <p>Chính sách này giải thích chúng tôi thu thập, sử dụng và bảo vệ thông tin như thế nào theo quy định về bảo vệ dữ liệu cá nhân của Việt Nam (Nghị định 13/2023/NĐ-CP).</p>

    <h2>1. Vai trò của các bên</h2>
    <ul>
      <li><b>Cửa hàng (Người dùng)</b> là bên quyết định thu thập và sử dụng thông tin khách hàng, nhân viên của mình trong phần mềm (họ tên, số điện thoại, địa chỉ, lịch sử mua hàng, công nợ, giờ công...). Cửa hàng chịu trách nhiệm có căn cứ hợp pháp và thông báo cho khách hàng/nhân viên của mình.</li>
      <li><b><?= htmlspecialchars(LEGAL_COMPANY) ?></b> cung cấp nền tảng, lưu trữ và xử lý dữ liệu theo chỉ dẫn của Cửa hàng chỉ để vận hành dịch vụ, và là bên kiểm soát đối với thông tin tài khoản của chính Người dùng.</li>
    </ul>

    <h2>2. Thông tin chúng tôi thu thập</h2>
    <ul>
      <li><b>Thông tin tài khoản:</b> tên cửa hàng, họ tên người quản trị, email, số điện thoại (nếu có), mật khẩu (lưu dưới dạng băm, không lưu mật khẩu gốc).</li>
      <li><b>Dữ liệu kinh doanh do Cửa hàng nhập:</b> sản phẩm, giá, tồn kho, khách hàng, đơn hàng, sổ quỹ, nhân viên, chấm công.</li>
      <li><b>Khách đặt hàng qua mã QR:</b> số điện thoại (bắt buộc), tên, địa chỉ nhận hàng (nếu giao tận nơi), ghi chú và vị trí bàn/quầy — gửi trực tiếp cho cửa hàng mà khách đặt.</li>
      <li><b>Vị trí GPS (chỉ khi cửa hàng bật chấm công GPS):</b> khoảng cách tới cửa hàng tại thời điểm chấm công được ghi vào ghi chú chấm công; chúng tôi không theo dõi vị trí liên tục.</li>
      <li><b>Dữ liệu kỹ thuật:</b> địa chỉ IP, thời gian truy cập, nhật ký hoạt động (ai làm gì), cookie phiên đăng nhập — để bảo mật, chống gian lận và hỗ trợ kỹ thuật.</li>
    </ul>

    <h2>3. Mục đích sử dụng</h2>
    <p>Cung cấp, duy trì và bảo mật dịch vụ; sao lưu; hỗ trợ khách hàng; gửi thông báo vận hành (ví dụ hết hạn dùng thử, báo cáo, đơn đặt hàng mới) tới email của chủ cửa hàng; phát hiện và ngăn chặn lạm dụng. Chúng tôi <b>không bán</b> dữ liệu và không dùng dữ liệu khách hàng của cửa hàng cho quảng cáo của chúng tôi.</p>

    <h2>4. Chia sẻ với bên thứ ba</h2>
    <p>Chỉ trong phạm vi cần thiết để vận hành: nhà cung cấp hosting/máy chủ, dịch vụ gửi email (SMTP), dịch vụ tạo ảnh QR chuyển khoản VietQR (nhận số tài khoản, số tiền, nội dung), dịch vụ thông báo biến động số dư mà Cửa hàng tự kết nối (SePay/Casso...), và khi pháp luật yêu cầu. Dữ liệu của mỗi cửa hàng được tách biệt, cửa hàng này không xem được dữ liệu của cửa hàng khác.</p>

    <h2>5. Lưu trữ, thời hạn và bảo mật</h2>
    <ul>
      <li>Dữ liệu được lưu trên máy chủ tại Việt Nam trong suốt thời gian tài khoản hoạt động và thêm một thời gian hợp lý sau khi hết hạn để Người dùng có thể gia hạn hoặc tải dữ liệu.</li>
      <li>Chúng tôi áp dụng các biện pháp kỹ thuật hợp lý: kết nối mã hóa (HTTPS), băm mật khẩu, phân quyền theo vai trò, tách dữ liệu giữa các cửa hàng, giới hạn đăng nhập sai, sao lưu định kỳ. Không hệ thống nào an toàn tuyệt đối; Người dùng cần giữ kín mật khẩu và phân quyền nhân viên cẩn thận.</li>
    </ul>

    <h2>6. Quyền của bạn</h2>
    <p>Bạn có quyền truy cập, chỉnh sửa, tải về (<i>Cấu hình → Xuất dữ liệu của tôi</i>), yêu cầu xóa dữ liệu hoặc rút lại sự đồng ý. Cửa hàng có thể xóa toàn bộ dữ liệu mẫu/dữ liệu kinh doanh tại <i>Cấu hình → Dữ liệu mẫu</i>. Khách hàng của một cửa hàng muốn truy cập/xóa thông tin cá nhân nên liên hệ trực tiếp cửa hàng đó; chúng tôi sẽ hỗ trợ cửa hàng thực hiện khi được yêu cầu.</p>

    <h2>7. Cookie</h2>
    <p>Chúng tôi dùng cookie phiên cần thiết để duy trì đăng nhập và chống giả mạo yêu cầu (CSRF). Không dùng cookie quảng cáo.</p>

    <h2>8. Trẻ em</h2>
    <p>Dịch vụ dành cho hoạt động kinh doanh, không hướng tới người dưới 16 tuổi.</p>

    <h2>9. Thay đổi và liên hệ</h2>
    <p>Chính sách có thể được cập nhật; phiên bản mới đăng tại trang này. Mọi yêu cầu về dữ liệu cá nhân xin liên hệ: <?= htmlspecialchars(LEGAL_COMPANY) ?> · ĐT/Zalo <b><?= htmlspecialchars(LEGAL_PHONE) ?></b> · Email <b><?= htmlspecialchars(LEGAL_EMAIL) ?></b>.</p>
    <p class="muted">Xem thêm <a href="dieu_khoan.php">Điều khoản sử dụng</a>.</p>
<?php legalPageEnd(); ?>
