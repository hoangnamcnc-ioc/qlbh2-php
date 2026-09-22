<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();
require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:4px;">Hướng dẫn sử dụng QLBH-CLOUD</h1>
<p class="muted" style="margin:0 0 20px;font-size:13.5px;">Tài liệu hướng dẫn chi tiết từng tính năng — bấm vào mục bên dưới để đi nhanh tới phần cần xem.</p>

<div class="card" style="margin-bottom:24px;">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px 20px;">
    <a href="#bat-dau">1. Bắt đầu nhanh</a>
    <a href="#nhap-du-lieu">2. Nhập dữ liệu có sẵn từ Excel</a>
    <a href="#pos">3. Bán hàng (POS)</a>
    <a href="#don-hang">4. Đơn hàng &amp; vận chuyển</a>
    <a href="#san-pham-kho">5. Sản phẩm &amp; kho</a>
    <a href="#nhap-hang">6. Nhập hàng &amp; nhà cung cấp</a>
    <a href="#khach-hang">7. Khách hàng</a>
    <a href="#khuyen-mai">8. Khuyến mại &amp; marketing</a>
    <a href="#bao-hanh">9. Bảo hành</a>
    <a href="#so-quy-bao-cao">10. Sổ quỹ &amp; báo cáo</a>
    <a href="#ke-toan">11. Kế toán &amp; thuế</a>
    <a href="#nhan-su">12. Nhân sự (chấm công, lương)</a>
    <a href="#cau-hinh">13. Cấu hình &amp; tài khoản</a>
    <a href="#faq">14. Câu hỏi thường gặp</a>
  </div>
</div>

<div class="card" id="bat-dau" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">1. Bắt đầu nhanh</h2>
  <ol style="margin:0;padding-left:20px;line-height:1.9;">
    <li>Vào <b>Cấu hình → Cấu hình</b> để đặt tên cửa hàng, logo, thông tin in hóa đơn trước khi bán hàng.</li>
    <li>Vào <b>Sản phẩm → Danh mục / Nhãn hiệu</b> tạo trước các nhóm hàng bạn đang bán.</li>
    <li>Vào <b>Sản phẩm → Danh sách sản phẩm</b> thêm sản phẩm (giá bán, giá vốn, mã vạch nếu có) — hoặc xem mục <a href="#nhap-du-lieu">2. Nhập dữ liệu có sẵn từ Excel</a> nếu bạn đã có sẵn danh sách hàng trăm sản phẩm trong file Excel, không cần gõ tay từng dòng.</li>
    <li>Vào <b>Sản phẩm → Nhập hàng</b> nhập số lượng tồn kho ban đầu (hoặc nhập nhanh từ file, xem mục 2 bên dưới).</li>
    <li>Vào <b>Bán hàng (POS)</b> và bắt đầu bán — đơn hàng, tồn kho, sổ quỹ sẽ tự động cập nhật theo nhau.</li>
  </ol>
</div>

<div class="card" id="nhap-du-lieu" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">2. Nhập dữ liệu có sẵn từ Excel</h2>
  <p>Nếu bạn đang quản lý sản phẩm/khách hàng bằng file Excel có sẵn, không cần gõ tay lại — tải thẳng file lên để phần mềm tự nhập vào.</p>
  <ul style="line-height:1.9;">
    <li><b>Nhập sản phẩm</b>: vào <b>Sản phẩm → Danh sách sản phẩm → Nhập file</b>. File cần có các cột theo đúng thứ tự: <code>sku, barcode, name, unit, cost_price, sell_price</code> (mã hàng, mã vạch, tên sản phẩm, đơn vị tính, giá vốn, giá bán). Sản phẩm trùng mã (SKU) sẽ được cập nhật, mã mới sẽ được tạo mới.</li>
    <li><b>Nhập tồn kho ban đầu</b>: sau khi đã có sản phẩm, vào <b>Sản phẩm → Quản lý kho → Nhập tồn kho từ file</b>, chọn chi nhánh rồi tải file có 2 cột: <code>sku, quantity</code> (mã hàng, số lượng tồn thực tế đang có). Số lượng nhập vào sẽ <b>thay thế</b> số tồn hiện có, không cộng dồn.</li>
    <li><b>Nhập khách hàng</b>: vào <b>Khách hàng → Danh sách khách hàng → Nhập file</b>. File cần các cột: <code>code, name, phone, address</code> (mã khách, tên, số điện thoại, địa chỉ) — cột mã khách có thể để trống, hệ thống tự sinh. Khách trùng số điện thoại sẽ được cập nhật, số mới sẽ được tạo khách hàng mới.</li>
    <li>Cả 3 màn hình chỉ nhận <b>file Excel (.xlsx)</b> — file tải thẳng xuống từ Excel/Google Sheets đều dùng được ngay. Dòng đầu tiên của file phải là dòng tiêu đề (tên cột), dữ liệu bắt đầu từ dòng thứ 2.</li>
    <li>Các nút <b>Xuất file</b> ở danh sách sản phẩm/khách hàng/sổ quỹ cũng xuất ra file Excel (.xlsx) — có thể dùng ngay làm file mẫu để chỉnh sửa rồi nhập lại.</li>
    <li>Nhập xong, màn hình sẽ báo rõ: đã thêm mới bao nhiêu, cập nhật bao nhiêu, bỏ qua bao nhiêu dòng không hợp lệ — kiểm tra lại số liệu này để chắc chắn không sót dòng nào.</li>
  </ul>
</div>

<div class="card" id="pos" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">3. Bán hàng (POS)</h2>
  <p>Màn hình bán hàng chính, dùng hàng ngày tại quầy.</p>
  <ul style="line-height:1.9;">
    <li><b>Thêm sản phẩm vào giỏ</b>: gõ tên/SKU vào ô tìm kiếm rồi chọn, hoặc <b>quét mã vạch</b> — máy quét USB/Bluetooth hoạt động như bàn phím, quét xong sản phẩm tự thêm vào giỏ nếu khớp đúng 1 kết quả.</li>
    <li><b>Nhiều đơn cùng lúc</b>: mở nhiều tab đơn hàng song song ở đầu màn hình khi có khách chờ xử lý riêng (giữ đơn 1 lại, bán tiếp đơn 2).</li>
    <li><b>Bán offline</b>: nếu mất mạng giữa lúc bán, đơn vẫn được lưu tạm trên máy và tự động đồng bộ lên hệ thống khi có mạng trở lại — có banner báo khi đang offline.</li>
    <li><b>Combo</b>: sản phẩm loại combo khi bán sẽ tự trừ kho của từng sản phẩm thành phần bên trong.</li>
    <li><b>Bán theo lô (FEFO)</b>: nếu sản phẩm có khai báo lô/hạn dùng, hệ thống tự trừ lô hết hạn sớm nhất trước.</li>
    <li><b>Chiết khấu</b>: có thể chiết khấu tay theo % hoặc số tiền (F6), áp mã giảm giá, và tự động cộng chiết khấu hạng khách hàng thân thiết — tổng chiết khấu cộng dồn được giới hạn tối đa 50% giá trị đơn để tránh nhập nhầm.</li>
    <li><b>Công nợ một phần</b>: chọn khách hàng (theo SĐT) thì có thể cho khách trả một phần, phần còn lại ghi nợ.</li>
    <li><b>Đổi điểm tích lũy</b>: khách hàng thân thiết tích điểm theo doanh số, đổi được quà trong phần Quà tặng.</li>
    <li><b>Màn hình phụ cho khách</b>: có thể mở 1 cửa sổ riêng hiển thị giỏ hàng/tổng tiền cho khách xem trong lúc thanh toán.</li>
  </ul>
  <div style="overflow-x:auto;">
    <table style="margin-top:8px;">
      <thead><tr><th>Phím tắt</th><th>Chức năng</th></tr></thead>
      <tbody>
        <tr><td><code>F1</code></td><td>Thanh toán</td></tr>
        <tr><td><code>F2</code></td><td>Nhập tiền khách đưa</td></tr>
        <tr><td><code>F3</code></td><td>Focus vào ô tìm sản phẩm</td></tr>
        <tr><td><code>F4</code></td><td>Nhập SĐT khách hàng</td></tr>
        <tr><td><code>F6</code></td><td>Chiết khấu đơn</td></tr>
        <tr><td><code>F7</code></td><td>Đổi hình thức thanh toán</td></tr>
        <tr><td><code>F8</code></td><td>Áp khuyến mại</td></tr>
        <tr><td><code>F9</code></td><td>Thêm dịch vụ</td></tr>
        <tr><td><code>Alt+1</code></td><td>In lại đơn gần nhất</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card" id="don-hang" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">4. Đơn hàng &amp; vận chuyển</h2>
  <ul style="line-height:1.9;">
    <li><b>Danh sách đơn hàng</b>: xem/lọc mọi đơn theo trạng thái (chờ duyệt, chờ đóng gói, đang giao, hoàn thành, đã hủy), theo chi nhánh, theo khách hàng.</li>
    <li><b>Xử lý đơn</b>: mở 1 đơn để duyệt, cập nhật trạng thái đóng gói/giao hàng, ghi nhận thanh toán bổ sung, hoặc hủy đơn (bắt buộc chọn lý do).</li>
    <li><b>Đơn trả hàng</b>: tạo phiếu trả hàng cho 1 đơn đã bán, hệ thống tự hoàn tồn kho và tính lại công nợ/hoàn tiền.</li>
    <li><b>Vận chuyển</b>: theo dõi trạng thái giao hàng nội bộ (chưa gọi API hãng vận chuyển thật — nhập tay tên đơn vị, mã vận đơn), đối soát COD khi hàng giao xong.</li>
  </ul>
</div>

<div class="card" id="san-pham-kho" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">5. Sản phẩm &amp; kho</h2>
  <ul style="line-height:1.9;">
    <li><b>Danh sách sản phẩm</b>: thêm/sửa sản phẩm, có thể tạo biến thể (màu/size...), phân loại thường/dịch vụ/combo, gắn danh mục và nhãn hiệu, đăng nhiều ảnh. Có sẵn hàng loạt sản phẩm trong file Excel? Xem mục <a href="#nhap-du-lieu">2. Nhập dữ liệu có sẵn từ Excel</a> để nhập nhanh thay vì gõ tay.</li>
    <li><b>Quản lý kho</b>: xem tồn kho theo từng chi nhánh, cảnh báo sản phẩm dưới định mức tồn tối thiểu, có nút <b>Nhập tồn kho từ file</b> để khai báo nhanh tồn kho ban đầu (xem mục 2).</li>
    <li><b>Kiểm hàng</b>: tạo phiếu kiểm kê, nhập số đếm thực tế (gõ tay hoặc <b>quét mã vạch</b> để tự thêm) — hệ thống tự tính chênh lệch và điều chỉnh tồn kho khi cân bằng phiếu.</li>
    <li><b>Chuyển hàng</b>: chuyển sản phẩm giữa các chi nhánh (chọn sản phẩm bằng gõ tay hoặc <b>quét mã vạch</b>), cần xác nhận đã nhận hàng ở chi nhánh đích mới cộng tồn.</li>
    <li><b>Điều chỉnh giá vốn</b>: sửa giá vốn khi giá nhập thay đổi — không ảnh hưởng đến lãi gộp của các đơn đã bán trước đó (giá vốn được chốt lại ngay lúc bán).</li>
  </ul>
</div>

<div class="card" id="nhap-hang" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">6. Nhập hàng &amp; nhà cung cấp</h2>
  <ul style="line-height:1.9;">
    <li><b>Đặt hàng nhập</b>: tạo đơn đặt hàng gửi nhà cung cấp trước khi hàng về kho (tùy chọn, không bắt buộc).</li>
    <li><b>Nhập hàng</b>: ghi nhận phiếu nhập kho thực tế — có thể tạo từ đơn đặt hàng có sẵn hoặc nhập trực tiếp (chọn sản phẩm bằng gõ tay hoặc <b>quét mã vạch</b> để tự thêm vào phiếu), tự cộng tồn kho và cập nhật công nợ phải trả nhà cung cấp.</li>
    <li><b>Nhà cung cấp</b>: quản lý danh sách NCC, theo dõi công nợ phải trả, ghi nhận thanh toán cho NCC.</li>
    <li><b>Trả hàng NCC</b>: tạo phiếu trả hàng khi hàng nhập bị lỗi/không đạt, tự trừ tồn kho và giảm công nợ.</li>
  </ul>
</div>

<div class="card" id="khach-hang" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">7. Khách hàng</h2>
  <ul style="line-height:1.9;">
    <li><b>Danh sách khách hàng</b>: xem lịch sử mua hàng, công nợ, điểm tích lũy của từng khách. Có sẵn danh sách khách trong file Excel? Xem mục <a href="#nhap-du-lieu">2. Nhập dữ liệu có sẵn từ Excel</a> để nhập nhanh cả danh sách.</li>
    <li><b>Nhóm khách hàng</b>: phân nhóm để chạy chiến dịch/khuyến mại riêng.</li>
    <li><b>Hạng khách hàng thân thiết</b>: đặt ngưỡng chi tiêu để tự động lên hạng, mỗi hạng có % chiết khấu riêng khi bán hàng.</li>
  </ul>
</div>

<div class="card" id="khuyen-mai" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">8. Khuyến mại &amp; marketing</h2>
  <ul style="line-height:1.9;">
    <li><b>Mã giảm giá (coupon)</b>: tạo mã, đặt % hoặc số tiền giảm, điều kiện đơn tối thiểu, hạn dùng, số lượt sử dụng tối đa — nhập mã tại màn hình POS lúc thanh toán.</li>
    <li><b>Quản lý khuyến mại</b>: chương trình giảm giá tự động áp dụng khi đơn đạt điều kiện, không cần khách nhập mã.</li>
    <li><b>Chiến dịch</b>: ghi nhận các đợt gửi SMS/Email marketing tới nhóm khách hàng (phần mềm chưa gửi tin thật, dùng để lên kế hoạch/theo dõi).</li>
  </ul>
</div>

<div class="card" id="bao-hanh" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">9. Bảo hành</h2>
  <ul style="line-height:1.9;">
    <li><b>Chính sách bảo hành</b>: khai báo thời hạn bảo hành theo từng sản phẩm/nhóm sản phẩm.</li>
    <li><b>Phiếu bảo hành</b>: tự động tạo khi bán sản phẩm có bật bảo hành; tra cứu theo mã phiếu/SĐT khách khi khách mang hàng tới bảo hành, ghi nhận yêu cầu xử lý.</li>
  </ul>
</div>

<div class="card" id="so-quy-bao-cao" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">10. Sổ quỹ &amp; báo cáo</h2>
  <ul style="line-height:1.9;">
    <li><b>Sổ quỹ</b>: mọi khoản thu/chi từ bán hàng, thu nợ, trả nợ NCC được tự động ghi vào đây; có thể ghi thêm khoản thu/chi tay (ví dụ tiền điện nước, thuê mặt bằng).</li>
    <li><b>Báo cáo</b>: doanh thu theo ngày/kênh/nhân viên, top sản phẩm bán chạy, top khách hàng, giá trị tồn kho, lãi gộp ước tính theo khoảng thời gian tùy chọn.</li>
  </ul>
</div>

<div class="card" id="ke-toan" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">11. Kế toán &amp; thuế</h2>
  <ul style="line-height:1.9;">
    <li><b>Ước tính thuế hộ kinh doanh</b>: tự tính thuế GTGT/TNCN ước tính theo doanh thu năm và ngưỡng miễn thuế hiện hành (chỉ áp dụng đúng cho ngành bán lẻ hàng hóa thông thường — nếu có thêm ngành dịch vụ/sản xuất, số liệu chỉ mang tính tham khảo).</li>
    <li><b>Sổ sách theo Thông tư 152/2025/TT-BTC</b>: tự sinh đúng mẫu sổ theo doanh thu và nhóm nộp thuế bạn chọn (S1a/S2a/S2b/S2c/S2d/S2e-HKD) — chọn đúng "Nhóm nộp thuế" ở đầu trang nếu doanh thu đã vượt ngưỡng miễn thuế.</li>
    <li>Đây là số liệu <b>ước tính tham khảo</b>, luôn đối chiếu lại với cơ quan thuế/kế toán viên trước khi kê khai chính thức.</li>
  </ul>
</div>

<div class="card" id="nhan-su" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">12. Nhân sự (chấm công, lương)</h2>
  <ul style="line-height:1.9;">
    <li><b>Thiết lập lương</b>: vào <b>Nhân viên và phân quyền</b> (Cấu hình) đặt Lương theo giờ và Tỷ lệ hoa hồng (% doanh số) cho từng nhân viên.</li>
    <li><b>Chấm công</b>: chọn nhân viên + ngày, nhập giờ vào/giờ ra — hỗ trợ ca làm qua đêm (giờ ra nhỏ hơn giờ vào). Mỗi nhân viên chỉ có 1 bản ghi/ngày, ghi lại lần sau sẽ cập nhật đè lên.</li>
    <li><b>Lịch làm việc</b>: xếp lịch ca làm cho từng nhân viên theo ngày (chỉ để theo dõi, không ảnh hưởng tính lương).</li>
    <li><b>Bảng lương</b>: chọn tháng, hệ thống tự tính cho từng nhân viên: <b>Thực nhận = Tổng giờ công trong tháng × Lương/giờ + (Doanh số bán trong tháng − doanh thu đã trả hàng) × % hoa hồng</b>. Không có lương cứng cố định — ngày nào không chấm công thì ngày đó tính 0 giờ.</li>
  </ul>
</div>

<div class="card" id="cau-hinh" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">13. Cấu hình &amp; tài khoản</h2>
  <ul style="line-height:1.9;">
    <li><b>Cấu hình</b>: tên cửa hàng, logo, màu thương hiệu, mẫu in hóa đơn, bật/tắt bán hàng online, cho phép tồn kho âm hay không.</li>
    <li><b>Chi nhánh &amp; nhân viên</b>: thêm chi nhánh mới, tạo tài khoản nhân viên và phân quyền ADMIN/MANAGER/CASHIER theo từng chi nhánh.</li>
    <li><b>Xuất dữ liệu của tôi</b>: tự tải về toàn bộ dữ liệu cửa hàng bạn dưới dạng file <code>.sql.gz</code> để lưu trữ riêng.</li>
    <li><b>Yêu cầu gia hạn</b>: gửi yêu cầu gia hạn/nâng cấp gói khi sắp/đã hết hạn dùng thử.</li>
    <li><b>Khóa màn hình</b>: tạm khóa máy khi rời quầy mà không cần đăng xuất hẳn.</li>
  </ul>
</div>

<div class="card" id="faq" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 10px;">14. Câu hỏi thường gặp</h2>
  <div style="display:grid;gap:14px;">
    <div>
      <b>Mất mạng giữa lúc bán hàng thì sao?</b>
      <p class="muted" style="margin:4px 0 0;">Đơn vẫn được lưu tạm trên máy đang bán, có banner báo "đang offline", tự động đồng bộ lên hệ thống khi có mạng trở lại — không cần làm gì thêm.</p>
    </div>
    <div>
      <b>Nhân viên (CASHIER) có thấy được số liệu của chi nhánh khác không?</b>
      <p class="muted" style="margin:4px 0 0;">Không — CASHIER chỉ thấy đúng dữ liệu của chi nhánh mình đang gán. ADMIN/MANAGER có thể chọn xem "Tất cả chi nhánh" hoặc từng chi nhánh riêng.</p>
    </div>
    <div>
      <b>Đổi giá vốn sản phẩm có ảnh hưởng đến báo cáo lãi của các đơn cũ không?</b>
      <p class="muted" style="margin:4px 0 0;">Không — giá vốn được chốt lại ngay tại thời điểm bán, đổi giá vốn sau này chỉ ảnh hưởng đơn hàng mới.</p>
    </div>
    <div>
      <b>Hết hạn dùng thử thì dữ liệu có bị mất không?</b>
      <p class="muted" style="margin:4px 0 0;">Không — dữ liệu được giữ nguyên, chỉ tạm khóa truy cập cho tới khi gia hạn/nâng cấp.</p>
    </div>
    <div>
      <b>Quên mật khẩu thì làm sao?</b>
      <p class="muted" style="margin:4px 0 0;">Liên hệ chủ tài khoản ADMIN của cửa hàng để được cấp lại mật khẩu qua mục Chi nhánh &amp; nhân viên, hoặc gửi Yêu cầu gia hạn để KT-SOFT hỗ trợ.</p>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
