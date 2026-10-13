<?php
require_once __DIR__ . '/inc_legal.php';
legalPageStart('Điều khoản sử dụng');
?>
    <h1>Điều khoản sử dụng</h1>
    <p class="muted">Dịch vụ <?= htmlspecialchars(LEGAL_PRODUCT) ?> do <?= htmlspecialchars(LEGAL_COMPANY) ?> cung cấp · Cập nhật <?= LEGAL_UPDATED ?> · Phiên bản <?= LEGAL_TERMS_VERSION ?></p>

    <p>Khi tạo tài khoản hoặc sử dụng <?= htmlspecialchars(LEGAL_PRODUCT) ?>, bạn (“Người dùng”, “Cửa hàng”) đồng ý với các điều khoản dưới đây.
       Nếu không đồng ý, vui lòng không sử dụng dịch vụ.</p>

    <h2>1. Dịch vụ</h2>
    <p><?= htmlspecialchars(LEGAL_PRODUCT) ?> là phần mềm quản lý bán hàng chạy trên nền tảng web (bán hàng tại quầy, kho, khách hàng, sổ quỹ, báo cáo, đặt hàng online qua mã QR...).
       Dịch vụ được cung cấp theo gói dùng thử và/hoặc gói trả phí; nội dung, giá và thời hạn của từng gói theo thông báo của <?= htmlspecialchars(LEGAL_COMPANY) ?> tại thời điểm đăng ký hoặc gia hạn.</p>

    <h2>2. Tài khoản và bảo mật</h2>
    <ul>
      <li>Người dùng cung cấp thông tin chính xác, tự bảo quản mật khẩu và chịu trách nhiệm về mọi hoạt động từ tài khoản của mình và của nhân viên do mình tạo.</li>
      <li>Tài khoản Quản trị chịu trách nhiệm phân quyền cho nhân viên phù hợp với công việc của họ.</li>
      <li>Phát hiện truy cập trái phép cần báo ngay cho chúng tôi qua thông tin liên hệ ở cuối trang.</li>
    </ul>

    <h2>3. Dữ liệu của Người dùng</h2>
    <ul>
      <li>Dữ liệu bạn nhập vào (sản phẩm, khách hàng, đơn hàng, sổ quỹ...) thuộc quyền sở hữu của bạn. Bạn cho phép chúng tôi lưu trữ, xử lý và sao lưu dữ liệu đó chỉ nhằm cung cấp dịch vụ.</li>
      <li>Bạn có thể tải toàn bộ dữ liệu của mình bất cứ lúc nào (Cấu hình → Xuất dữ liệu của tôi), kể cả khi gói dùng thử đã hết hạn.</li>
      <li>Bạn tự chịu trách nhiệm về tính hợp pháp của dữ liệu và về việc có đủ căn cứ để thu thập, sử dụng thông tin khách hàng, nhân viên của mình (xem <a href="chinh_sach_bao_mat.php">Chính sách bảo mật</a>).</li>
    </ul>

    <h2>4. Sử dụng được phép</h2>
    <p>Không sử dụng dịch vụ để vi phạm pháp luật, xâm phạm quyền của người khác, gửi nội dung độc hại, tấn công hoặc dò quét hệ thống, truy cập dữ liệu của cửa hàng khác,
       hoặc làm quá tải hạ tầng. Chúng tôi có quyền tạm ngưng hoặc chấm dứt tài khoản vi phạm sau khi thông báo (hoặc ngay lập tức nếu có nguy cơ gây hại).</p>

    <h2>5. Thanh toán, dùng thử và gia hạn</h2>
    <ul>
      <li>Gói dùng thử có thời hạn ghi trên màn hình; hết hạn, quyền truy cập các chức năng sẽ tạm khóa nhưng dữ liệu vẫn được giữ và bạn vẫn tải được dữ liệu của mình cho tới khi gia hạn hoặc xóa tài khoản.</li>
      <li>Phí gói trả phí, chính sách gia hạn và hoàn phí (nếu có) thực hiện theo thỏa thuận/hóa đơn giữa hai bên.</li>
    </ul>

    <h2>6. Các tính năng có yếu tố tham khảo — Miễn trừ trách nhiệm</h2>
    <div class="note">Các nội dung sau chỉ mang tính <b>hỗ trợ, tham khảo</b>, không thay thế tư vấn của kế toán, luật sư, cơ quan thuế hoặc ngân hàng.</div>
    <ul>
      <li><b>Thuế, kế toán, báo cáo:</b> số liệu ước tính thuế hộ kinh doanh, sổ doanh thu, báo cáo lãi gộp và các file Excel xuất ra được tính từ dữ liệu bạn nhập theo cách tính mặc định của phần mềm; quy định pháp luật có thể thay đổi hoặc khác theo ngành nghề, nên Người dùng cần đối chiếu với kế toán/cơ quan thuế trước khi kê khai. Phần mềm <b>chưa phải</b> là hóa đơn điện tử và không thay thế nghĩa vụ lập hóa đơn theo quy định.</li>
      <li><b>Thanh toán chuyển khoản/QR:</b> mã QR tạo theo chuẩn VietQR để khách chuyển khoản đúng số tiền. Việc đối soát tự động dựa trên thông báo biến động số dư của dịch vụ bên thứ ba (vd SePay, Casso) nên có thể chậm, thiếu hoặc sai; Cửa hàng chịu trách nhiệm kiểm tra sao kê ngân hàng và xác nhận tiền đã nhận trước khi giao hàng/xác nhận đơn.</li>
      <li><b>Dữ liệu mẫu:</b> là dữ liệu giả để tham khảo giao diện; không phản ánh số liệu thật.</li>
      <li><b>Chấm công, định vị, giới hạn theo mạng:</b> các biện pháp này chỉ mang tính hỗ trợ quản lý và có thể bị sai số hoặc bị giả lập; không phải chứng cứ tuyệt đối về giờ làm hay vị trí.</li>
    </ul>

    <h2>7. Tình trạng dịch vụ và giới hạn trách nhiệm</h2>
    <ul>
      <li>Dịch vụ được cung cấp “nguyên trạng”. Chúng tôi nỗ lực duy trì hoạt động ổn định và sao lưu định kỳ nhưng <b>không cam kết</b> dịch vụ không gián đoạn, không lỗi hay luôn khôi phục được mọi dữ liệu. Bạn nên tự xuất dữ liệu định kỳ.</li>
      <li>Trong phạm vi pháp luật cho phép, chúng tôi không chịu trách nhiệm đối với thiệt hại gián tiếp, mất lợi nhuận, mất cơ hội kinh doanh hoặc thiệt hại do bên thứ ba (nhà mạng, ngân hàng, dịch vụ email, dịch vụ thông báo biến động số dư...) gây ra.</li>
      <li>Tổng mức trách nhiệm của chúng tôi (nếu có) không vượt quá khoản phí Người dùng đã thanh toán cho dịch vụ trong 12 tháng gần nhất; với gói dùng thử miễn phí là không có khoản bồi thường nào, trừ trường hợp pháp luật quy định khác.</li>
    </ul>

    <h2>8. Sở hữu trí tuệ</h2>
    <p>Phần mềm, giao diện, logo, tài liệu và nhãn hiệu <?= htmlspecialchars(LEGAL_PRODUCT) ?> / <?= htmlspecialchars(LEGAL_COMPANY) ?> thuộc quyền của <?= htmlspecialchars(LEGAL_COMPANY) ?>.
       Người dùng được cấp quyền sử dụng không độc quyền, không chuyển nhượng trong thời gian có hiệu lực của tài khoản; không sao chép, bán lại, dịch ngược hoặc gỡ bỏ thông tin bản quyền.
       Phần mềm có sử dụng một số thư viện mã nguồn mở theo giấy phép riêng của từng thư viện.</p>

    <h2>9. Thay đổi điều khoản, chấm dứt</h2>
    <p>Chúng tôi có thể cập nhật điều khoản; phiên bản mới được đăng tại trang này và có hiệu lực từ ngày cập nhật. Việc tiếp tục sử dụng sau đó được xem là chấp nhận.
       Bạn có thể ngừng sử dụng và yêu cầu xóa tài khoản/dữ liệu bất cứ lúc nào.</p>

    <h2>10. Luật áp dụng và liên hệ</h2>
    <p>Điều khoản này chịu sự điều chỉnh của pháp luật Việt Nam; tranh chấp được ưu tiên giải quyết bằng thương lượng, nếu không được thì giải quyết tại Tòa án có thẩm quyền tại Việt Nam.</p>
    <p>Liên hệ: <?= htmlspecialchars(LEGAL_COMPANY) ?> · ĐT/Zalo <b><?= htmlspecialchars(LEGAL_PHONE) ?></b> · Email <b><?= htmlspecialchars(LEGAL_EMAIL) ?></b> · <a href="<?= htmlspecialchars(LEGAL_WEBSITE) ?>"><?= htmlspecialchars(LEGAL_WEBSITE) ?></a></p>
<?php legalPageEnd(); ?>
