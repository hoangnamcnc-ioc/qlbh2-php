# QLBH2 (PHP) - Phần mềm Quản lý Bán hàng

Bản viết lại bằng **PHP thuần + MySQL** để chạy được trên hosting chia sẻ (không cần Node.js),
thay thế bản Next.js trước đó (vẫn giữ tại repo `qlbh2-soft` làm tham khảo).

Xem [docs đặc tả chức năng gốc](../QLBH2-SOFT/docs/feature-spec.md) (khảo sát từ Sapo POS) để biết
đầy đủ phạm vi nghiệp vụ tham chiếu.

## Yêu cầu

- PHP 8.0+ với extension `pdo_mysql`
- MySQL 5.7+ / MariaDB
- Không cần Node.js, không cần build bước nào — chạy trực tiếp

## Cài đặt (local hoặc hosting)

1. Import `schema.sql` vào database MySQL (qua phpMyAdmin hoặc `mysql -u ... < schema.sql`).
2. Sao chép `config.sample.php` thành `config.php`, điền thông tin DB thật.
   - **Quirk hosting iNet đã biết**: `DB_HOST` phải là `127.0.0.1`, không dùng `localhost`.
3. Mở `seed.php` trên trình duyệt **1 lần** để tạo tài khoản admin
   (`admin@qlbh2.local` / `Admin@123`) — sau đó **xóa file `seed.php`** khỏi server.
4. Truy cập `index.php` (hoặc domain gốc nếu đã trỏ đúng) → đăng nhập → đổi mật khẩu ngay.

## Cấu trúc file (cố tình để phẳng, không dùng thư mục con)

> Một số hosting chia sẻ (LiteSpeed) từng chặn truy cập thư mục con dạng `admin/` — để tránh lặp
> lại sự cố đó, toàn bộ trang ở đây nằm phẳng tại thư mục gốc thay vì tổ chức theo thư mục.

- `config.sample.php` — mẫu cấu hình DB (copy thành `config.php`, không commit `config.php`).
- `schema.sql` — toàn bộ cấu trúc bảng MySQL.
- `inc_auth.php`, `inc_functions.php`, `inc_header.php`, `inc_footer.php` — các phần dùng chung.
- `login.php`, `logout.php`, `seed.php` — xác thực.
- `index.php` — Dashboard.
- `products.php`, `product_form.php`, `inventory.php`, `inventory_save.php` — Sản phẩm + Tồn kho.
- `customers.php`, `customer_form.php`, `customer_view.php`, `groups.php` — Khách hàng + Công nợ.
- `pos.php`, `pos_search.php`, `pos_checkout.php` — Bán hàng POS (AJAX).
- `orders.php`, `order_view.php` — Đơn hàng.

## Bảo mật đã áp dụng

- Mật khẩu băm bằng `password_hash()` (bcrypt).
- Session cookie `httponly` + `SameSite=Lax`.
- CSRF token trên mọi form POST (`csrfToken()` / `checkCsrf()`).
- Câu lệnh SQL luôn dùng prepared statement (PDO, không nối chuỗi trực tiếp).
- Giao dịch bán hàng (`pos_checkout.php`) dùng `SELECT ... FOR UPDATE` trong transaction để
  tránh bán quá tồn kho khi nhiều người thao tác cùng lúc.

## Trạng thái hiện tại

Đã có: Đăng nhập + Đổi mật khẩu, Dashboard cơ bản, Sản phẩm + Tồn kho, Bán hàng POS + Đơn hàng,
Khách hàng + Công nợ + Nhóm khách hàng, Sổ quỹ (phiếu thu/chi + tổng hợp), **Đổi trả hàng**
(tìm đơn gốc, chọn SL trả từng dòng, tự hoàn tồn kho + tự tạo phiếu chi hoàn tiền trong Sổ quỹ)
— đã deploy và test thành công trên app.kt-soft.vn.

**Nhà cung cấp + Nhập hàng**: tạo/xem NCC, công nợ phải trả tự tăng khi nhập hàng, ghi nhận trả
nợ, tạo phiếu nhập (chọn sản phẩm động, tồn kho + giá vốn tự cập nhật) — đã test đầy đủ.

**Báo cáo**: doanh thu/lãi gộp/tồn kho theo khoảng ngày, top sản phẩm bán chạy, top khách hàng,
doanh thu theo ngày, tổng thu/chi sổ quỹ trong kỳ — đã test số liệu khớp chính xác.

**Biến thể sản phẩm** (màu/size): mỗi sản phẩm có thể có nhiều biến thể, mỗi biến thể có SKU/giá
vốn/giá bán/tồn kho riêng theo từng chi nhánh — khi có biến thể, sản phẩm gốc không còn bán trực
tiếp (phải chọn biến thể). Đã tích hợp xuyên suốt: tìm kiếm & bán trong POS, nhập hàng, đổi trả
hàng (hoàn đúng kho biến thể), báo cáo (giá vốn/tồn kho tính theo biến thể) — test đầy đủ.

**Phân quyền theo role**: `requireRole()`/`hasRole()` chặn UI + backend theo role (ADMIN/MANAGER
đầy đủ quyền, CASHIER chỉ Bán hàng/Đơn hàng/Khách hàng/xem Sản phẩm-Kho, không có Nhập hàng/Sổ
quỹ/Báo cáo/Cấu hình) — sidebar tự ẩn mục không có quyền, truy cập trực tiếp URL bị chặn (403).

**Quy trình đơn hàng kiểu Sapo**: pipeline stepper (Đặt hàng→Duyệt→Đóng gói→Xuất kho→Hoàn thành)
trên trang chi tiết đơn, nút **Hủy đơn hàng** (ADMIN/MANAGER) tự hoàn tồn kho.

**Kiểm hàng + Chuyển hàng giữa chi nhánh** (ADMIN/MANAGER): kiểm hàng ghi nhận tồn hệ thống vs
thực tế và cập nhật tồn kho theo số đếm thực tế; chuyển hàng validate đủ tồn kho, trừ/cộng đúng 2
chi nhánh trong 1 transaction. Thêm trang quản lý **Chi nhánh** (ADMIN) — cần thiết vì trước đó chỉ
có 1 chi nhánh mặc định.

Đã test đầy đủ trên app.kt-soft.vn, phát hiện và sửa 1 bug: tồn kho ban đầu khi tạo sản phẩm/biến
thể mới bị gán nhầm vào chi nhánh đầu tiên theo alphabet thay vì chi nhánh của người tạo.

**14/14 nhóm chức năng đã có** (khớp cấu trúc menu Sapo gốc):
- **Khuyến mại**: mã giảm giá (số tiền/%, đơn tối thiểu, giới hạn lượt dùng), áp dụng trong POS
  (validate lại phía server, không tin số liệu client).
- **Bảo hành**: chính sách bảo hành, phiếu bảo hành (tạo từ đơn hàng), yêu cầu bảo hành + xử lý.
- **Vận chuyển**: vận đơn nội bộ (mã vận đơn, đơn vị, trạng thái, phí ship, COD) gắn với đơn hàng.
- **Marketing**: lưu chiến dịch SMS/Email nội bộ (**chưa gửi thật** — cần cấu hình gateway riêng).
- **Kế toán và Thuế**: trang hướng dẫn (**chưa tích hợp hóa đơn điện tử thật** — cần nhà cung cấp
  được Tổng cục Thuế công nhận).
- **Đặt hàng nhập**: tạo trước khi nhập kho, nút "Nhập kho từ đặt hàng này" tự tạo phiếu nhập.
- **Điều chỉnh giá vốn**: log lịch sử thay đổi giá vốn độc lập với nhập hàng.
- **Xuất/nhập file**: CSV (Excel mở được) cho Sản phẩm (2 chiều) và Khách hàng (xuất).
- **Sửa đơn hàng**: sửa ghi chú + chiết khấu sau khi tạo (có cảnh báo không tự đối soát thanh toán).

Đã test toàn bộ trên app.kt-soft.vn bằng cURL + browser thật: coupon giảm đúng giá trong đơn, phiếu
bảo hành tính đúng hạn 12 tháng, đặt hàng nhập → nhập kho tự động cộng đúng tồn kho + giá vốn, điều
chỉnh giá vốn ghi log đúng, CSV export/import đọc/ghi đúng dữ liệu.

Giới hạn còn lại: Kênh bán hàng (tích hợp Shopee/Facebook/sàn TMĐT) chưa có kể cả khung dữ liệu —
đây là phần phụ thuộc nhiều vào API riêng từng sàn, cần yêu cầu cụ thể mới xây được đúng hướng.

**Chi tiết bổ sung bên trong các module chính**:
- Danh mục sản phẩm (`categories.php`, hỗ trợ danh mục cha/con) + Nhãn hiệu (`brands.php`), gán vào
  sản phẩm, lọc danh sách sản phẩm theo danh mục.
- Sao chép sản phẩm nhanh (`product_copy.php`) — tạo bản sao ở trạng thái ngừng bán để chỉnh sửa
  trước khi kích hoạt.
- Đơn hàng: bộ lọc nâng cao (trạng thái, nhân viên, khoảng ngày), xem nhanh sản phẩm trong đơn ngay
  tại danh sách (không cần vào trang chi tiết) qua `order_quick.php`.
- Khách hàng: tab "Đang giao dịch" (khách có ít nhất 1 đơn hợp lệ), nhập file CSV
  (`customers_import.php`, khớp theo SĐT).
- In hóa đơn (`order_print.php`) — khổ giấy nhiệt 380px, tự mở hộp thoại in, có ở cả trang chi tiết
  đơn và ngay sau khi thanh toán trong POS.
- Dashboard: biểu đồ cột doanh thu 7 ngày qua (thuần CSS, không phụ thuộc thư viện ngoài), widget
  "Sản phẩm dưới định mức" hiển thị trực tiếp thay vì chỉ đếm số lượng.

Đã test trên app.kt-soft.vn: lọc sản phẩm theo danh mục đúng, sao chép sản phẩm giữ nguyên
danh mục/nhãn hiệu/giá, xem nhanh đơn hàng hiện đúng dòng sản phẩm, nội dung hóa đơn in khớp dữ
liệu đơn (xác minh qua cURL vì trình duyệt test tự kích hoạt hộp thoại in chặn thao tác tự động).

**Vòng chi tiết thứ 2**:
- Tags cho sản phẩm và đơn hàng (nhập tự do, cách nhau bằng dấu phẩy).
- Nhiều ảnh sản phẩm (`product_images`, upload/xóa trong `product_form.php`, giới hạn 3MB,
  chỉ nhận jpg/png/webp, thư mục `uploads/products/` có `.htaccess` chặn thực thi mã).
- Lịch sử thay đổi đơn hàng (`order_status_history`) — ghi log khi tạo, hủy, sửa đơn; hiển thị
  trong trang chi tiết đơn.
- Đối soát COD: trang Vận chuyển có bộ đếm "COD chưa đối soát", nút Đối soát từng vận đơn, tab
  lọc Tất cả/Chưa đối soát/Đã đối soát.
- Phím tắt bán hàng trong POS (F1 Thanh toán, F2 Tiền khách đưa, F3 Tìm sản phẩm, F4 SĐT khách,
  F6 Mã giảm giá, F7 Đổi hình thức thanh toán) + trường "Tiền khách đưa"/"Tiền thối lại".
- Quản lý khuyến mại dạng chương trình tự động (`promotions.php`) — khác mã coupon: tự áp dụng
  trong POS khi đơn đạt giá trị tối thiểu, không cần khách nhập mã; cộng dồn với coupon nếu có.

Đã test: upload ảnh thành công (xác minh file truy cập được qua HTTP 200), khuyến mại tự động
áp dụng đúng % giảm và cộng dồn đúng vào `discount`, audit log ghi đúng 2 dòng khi tạo rồi hủy đơn,
đối soát COD chuyển đúng trạng thái và bộ đếm về 0.

**Lưu ý kỹ thuật khi migrate DB có dữ liệu cũ**: nếu nâng cấp từ bản trước khi có biến thể, bảng
`inventory` có unique key cũ `(branch_id, product_id)` được ràng buộc bởi FK — cần thêm index phụ
trên `product_id` trước khi xóa key cũ để đổi sang `(branch_id, product_id, variant_id)` (xem lịch
sử migration trong quá trình phát triển nếu cần làm lại thao tác này).

**Vòng hoàn thiện thứ 3**:
- **Phân loại sản phẩm** (`products.product_type`: Hàng hóa/Dịch vụ/Combo) — Dịch vụ không hiện
  mục tồn kho/biến thể (không trừ kho khi bán); Combo hiện mục "Thành phần Combo"
  (`combo_item_save.php`/`combo_item_delete.php`) để gộp nhiều sản phẩm khác vào bán chung 1 dòng.
- **Nhiều bảng giá theo chính sách** (`price_lists.php` quản lý danh sách bảng giá, gán bảng giá
  cho từng Nhóm khách hàng trong `groups.php`), nhập giá riêng cho từng sản phẩm theo từng bảng giá
  ngay trong `product_form.php` (để trống = dùng giá bán mặc định).
- **Quét mã vạch trong POS**: khi gõ/quét mã rồi bấm Enter ở ô tìm kiếm, nếu khớp đúng 1 sản phẩm
  (hoặc khớp đúng SKU trong nhiều kết quả) thì tự động thêm vào giỏ hàng ngay, không cần bấm chuột.

Đã test trên app.kt-soft.vn: tạo sản phẩm Dịch vụ (ẩn đúng mục tồn kho), tạo sản phẩm Combo + thêm
thành phần (hiện đúng số lượng), tạo bảng giá + gán vào nhóm khách hàng + đặt giá riêng cho sản phẩm
(lưu và hiển thị lại đúng), tra cứu theo SKU trả về đúng 1 kết quả xác nhận logic tự thêm vào giỏ
khi quét mã hoạt động đúng. Toàn bộ dữ liệu test đã được xóa sạch khỏi server sau khi kiểm tra.

Giới hạn: giá theo bảng giá hiện chỉ áp cho sản phẩm gốc (chưa hỗ trợ giá riêng theo biến thể).

**Vòng hoàn thiện thứ 4**:
- **POS tự động áp bảng giá theo khách hàng**: nhập SĐT trong POS (`customer_lookup.php`) → nếu
  khách thuộc nhóm có gán bảng giá riêng, mọi sản phẩm tìm/quét sau đó tự lấy đúng giá trong bảng
  giá đó (`pos_search.php` nhận thêm tham số `price_list_id`), hiển thị rõ tên khách + nhóm +
  thông báo "Áp dụng bảng giá riêng" ngay dưới ô nhập SĐT.
- **Bán Combo trong POS**: sản phẩm loại Combo giờ tìm được trong POS như 1 dòng hàng bình thường;
  khi thanh toán (`pos_checkout.php`), hệ thống tự trừ tồn kho của từng sản phẩm thành phần theo
  đúng số lượng cấu hình trong combo (kiểm tra đủ tồn kho từng thành phần trước khi trừ, dùng
  transaction + `FOR UPDATE` như luồng bán hàng thường).
- **Bán Dịch vụ trong POS**: sản phẩm loại Dịch vụ khi thanh toán không kiểm tra/trừ tồn kho.

Đã test trên app.kt-soft.vn: tạo khách hàng gán nhóm có bảng giá riêng, xác nhận `pos_search.php`
trả đúng giá ưu đãi khi có `price_list_id` và giá mặc định khi không có; thanh toán 1 đơn có dòng
Combo số lượng 3 (combo gồm 2 sản phẩm A) → xác nhận tồn kho sản phẩm A giảm đúng 6 đơn vị, đơn hàng
ghi đúng dòng và tổng tiền. Toàn bộ dữ liệu test (khách hàng, sản phẩm, đơn hàng, bảng giá) đã được
xóa sạch khỏi server sau khi kiểm tra.

**Vòng hoàn thiện thứ 5 (hoàn tất các hạng mục còn thiếu)**:
- **Đổi trả hàng cho Combo/Dịch vụ** (`order_return_form.php`): Dịch vụ trả hàng không hoàn tồn
  kho (vì không quản lý tồn kho); Combo trả hàng tự hoàn tồn kho đúng cho từng sản phẩm thành phần
  theo số lượng cấu hình trong combo (không còn tạo nhầm dòng tồn kho cho chính sản phẩm combo).
- **Kênh bán hàng** (`channels.php`, khung dữ liệu nội bộ — chưa gọi API Shopee/Facebook thật):
  khai báo kênh (Shopee/Lazada/TikTok Shop/Facebook/Website/Khác), gán vào đơn hàng kèm mã đơn bên
  kênh (`order_edit.php`), lọc + hiển thị cột kênh trong danh sách đơn (`orders.php`), xem chi tiết
  trong `order_view.php`, và báo cáo doanh thu theo từng kênh trong `reports.php`.
- **Sidebar gọn hơn**: các nhóm menu có từ 2 mục con trở lên (Đơn hàng, Sản phẩm, Khách hàng, Bảo
  hành, Khuyến mại...) giờ thu gọn mặc định, chỉ hiện danh sách mục con khi bấm vào tên nhóm; nhóm
  chứa trang đang xem tự động mở sẵn. Nhóm chỉ có 1 mục (Tổng quan, Bán hàng, Vận chuyển...) vẫn
  hiển thị trực tiếp như cũ, không cần bấm thêm.

Đã test trên app.kt-soft.vn: trả 1 đơn vị Combo (gồm 3 sản phẩm thành phần/combo) → tồn kho thành
phần tăng đúng +3, không phát sinh dòng tồn kho thừa cho sản phẩm combo; tạo kênh bán hàng, gán vào
đơn hàng kèm mã đơn ngoài, xác nhận hiển thị đúng ở danh sách/chi tiết đơn và báo cáo doanh thu theo
kênh; bấm mở/đóng từng nhóm menu hoạt động đúng, nhóm đang chứa trang hiện tại tự mở sẵn. Toàn bộ
dữ liệu test đã xóa sạch khỏi server sau khi kiểm tra.

Đến đây phần mềm đã hoàn thiện đầy đủ các hạng mục nghiệp vụ đối chiếu với Sapo POS trong phạm vi
đã khảo sát, chỉ còn phụ thuộc các tích hợp API thật bên thứ 3 (thanh toán, hóa đơn điện tử, SMS/
Email, vận chuyển, sàn TMĐT) mà dự án chủ động chưa gọi thật theo quyết định ban đầu.

**Vòng hoàn thiện thứ 6**:
- **Sidebar gọn hơn nữa**: gộp các nhóm chỉ có 1 mục vào nhóm liên quan gần nhất — "Bán hàng" giờ
  gồm POS + Đơn hàng + Đơn trả hàng + Vận chuyển + Kênh bán hàng; "Marketing & Khuyến mại" gồm
  Chiến dịch + Khuyến mại tự động + Mã giảm giá; "Tài chính & Báo cáo" gồm Sổ quỹ + Báo cáo + Kế
  toán và Thuế. Sidebar còn 7 nhóm chính thay vì 13 nhóm rời rạc như trước.
- **Trang Cấu hình dạng hub** (`settings.php`, ADMIN), lấy cảm hứng từ trang Cấu hình của Sapo:
  các thẻ (card) gom theo 3 nhóm "Thiết lập cửa hàng"/"Thiết lập bán hàng"/"Tài chính & Báo cáo",
  mỗi thẻ dẫn thẳng tới trang quản lý tương ứng đã có sẵn.
- **Nhân viên và phân quyền** (`users.php`, ADMIN) — hạng mục còn thiếu so với Sapo: trước đây chỉ
  tạo được 1 tài khoản admin duy nhất qua `seed.php`, giờ ADMIN có thể tạo thêm tài khoản nhân viên
  (họ tên/email/mật khẩu/vai trò/chi nhánh), đổi vai trò và chi nhánh ngay trong danh sách, khóa/mở
  khóa tài khoản, đặt lại mật khẩu cho nhân viên khi họ quên. Tự bảo vệ: không thể tự khóa hoặc tự
  hạ quyền chính tài khoản đang đăng nhập.

Đã test trên app.kt-soft.vn: tạo tài khoản nhân viên mới với vai trò Thu ngân, xác nhận đăng nhập
được ngay bằng tài khoản đó; trang Cấu hình hiển thị đúng các thẻ liên kết tới toàn bộ trang quản lý
hiện có. Đã xóa tài khoản test sau khi kiểm tra.

**Vòng rà soát đối chiếu chi tiết trang Cấu hình của Sapo** (theo ảnh chụp thực tế người dùng gửi),
bổ sung các mục còn thiếu:
- **Thông tin cửa hàng** (`store_settings.php`): tên/điện thoại/email/địa chỉ cửa hàng, lời cảm ơn
  cuối hóa đơn in (áp dụng ngay vào `order_print.php`), và tùy chọn **cho phép bán âm kho** — khi
  bật, `pos_checkout.php` bỏ qua kiểm tra đủ tồn kho (cả sản phẩm thường lẫn thành phần combo) và
  cho phép số lượng tồn kho xuống âm.
- **Thuế** (`tax_rates.php`): khai báo các mức thuế suất đầu ra/đầu vào — danh mục tham chiếu nội
  bộ, chưa tự động tính vào giá bán.
- **Lý do hủy trả** (`cancel_reasons.php`): danh sách lý do dùng chung cho cả hủy đơn
  (`order_view.php`/`order_cancel.php`) và trả hàng (`order_return_form.php`), chọn nhanh bằng
  dropdown thay vì gõ tay, có lựa chọn "Khác" để nhập tự do khi cần.
- **Nguồn bán hàng** (`order_sources.php`): mô tả cách khách tiếp cận để đặt hàng (gọi điện, nhắn
  Zalo/Facebook...), gán vào đơn trong `order_edit.php` — khác với "Kênh bán hàng" (nền tảng bán).
- **Sửa lỗi hủy đơn hàng cho Combo/Dịch vụ**: trước đây `order_cancel.php` hoàn tồn kho sai cách
  giống lỗi đã sửa ở đổi trả hàng (Dịch vụ bị hoàn nhầm tồn kho, Combo hoàn nhầm vào chính nó thay
  vì thành phần) — nay xử lý đúng như `order_return_form.php`.

Đã test trên app.kt-soft.vn: lưu thông tin cửa hàng và xác nhận lời cảm ơn tùy chỉnh hiển thị đúng
trên hóa đơn in; tạo mức thuế, lý do hủy trả, nguồn bán hàng và xác nhận hiển thị đúng trong danh
sách cũng như trong dropdown chọn khi hủy đơn/sửa đơn; hủy 1 đơn kèm lý do và xác nhận lý do được
ghi đúng vào lịch sử đơn hàng. Toàn bộ dữ liệu test đã xóa sạch khỏi server.

**Vòng rà soát lần 2** (đối chiếu tiếp phần còn lại của ảnh chụp trang Cấu hình Sapo), bổ sung:
- **Chính sách giá**: thêm thẻ liên kết tới `price_lists.php` (đã có sẵn từ vòng trước nhưng bị
  thiếu trong hub Cấu hình).
- **Cấu hình bán hàng** (`sales_settings.php`): 3 tùy chọn ảnh hưởng trực tiếp luồng POS — bắt buộc
  nhập SĐT khách trước khi thanh toán, tự động mở hóa đơn in ngay sau khi thanh toán, làm tròn tổng
  tiền đơn hàng đến hàng nghìn đồng (áp dụng ngay trong `pos.php`/`pos_checkout.php`).
- **Quản lý kho & Sản phẩm** (`inventory_settings.php`): tách riêng tùy chọn "cho phép bán âm kho"
  khỏi trang Thông tin cửa hàng cho đúng cấu trúc Sapo; áp dụng cho cả sản phẩm thường lẫn thành
  phần combo trong `pos_checkout.php`.
- **Nhật ký hoạt động** (`activity_log.php`, ADMIN): ghi lại đăng nhập/đăng xuất và các thao tác
  cấu hình quan trọng (tạo/khóa/đổi quyền tài khoản, sửa thông tin cửa hàng, tạo chi nhánh/kênh bán
  hàng/thuế/lý do hủy trả/nguồn bán hàng) qua hàm dùng chung `logActivity()`, lọc theo loại hoạt
  động, hiển thị 200 dòng gần nhất.
- **Xuất/nhập file** (`file_logs.php`): dùng lại bảng nhật ký hoạt động, lọc riêng các thao tác
  xuất/nhập CSV sản phẩm và khách hàng, ghi rõ tên file + số dòng thêm mới/cập nhật/bỏ qua.

Đã test trên app.kt-soft.vn: bật "làm tròn tổng tiền" → thanh toán đơn 12.345đ tự động làm tròn
thành 12.000đ, xác nhận đúng trong dữ liệu đơn hàng; các trang cấu hình mới lưu và hiển thị đúng
trạng thái đã lưu; trang Nhật ký hoạt động ghi nhận đúng sự kiện đăng nhập. Đã tắt lại "làm tròn
tổng tiền" và xóa sạch dữ liệu test (dùng tên file tạm khác nhau mỗi lần do LiteSpeed cache phản hồi
cũ của cùng 1 tên file — cần lưu ý thao tác kiểm tra dữ liệu thực tế bằng truy vấn mới thay vì tin
vào output của file đã chạy trước đó nếu tái sử dụng cùng tên file).

Giới hạn còn lại so với Sapo (chủ động không xây dựng theo phạm vi ban đầu — chỉ khung dữ liệu nội
bộ, không gọi API thật): Thanh toán (kết nối cổng VNPay/VietQR/MoMo thật); Mẫu in chưa cho tùy
chỉnh layout kéo-thả, chỉ tùy chỉnh nội dung; Cân điện tử không tích hợp phần cứng; Hóa đơn điện tử
chưa kết nối nhà cung cấp thật; Xử lý đơn hàng (tùy chỉnh quy trình pipeline linh hoạt) chưa có vì
hiện tại đơn từ POS luôn tạo thẳng ở trạng thái Hoàn thành, chưa có luồng tạo đơn nháp riêng.

**Vòng bổ sung trang Bán hàng (POS)** (theo ảnh chụp màn hình POS thực tế của Sapo):
- **Chiết khấu đơn (F6)**: nhập chiết khấu trực tiếp theo VNĐ hoặc %, tính trước và cộng dồn với mã
  giảm giá (coupon) + khuyến mại tự động, luôn giới hạn không vượt quá tổng tiền hàng — khác với
  trước đây chỉ có mã giảm giá, không có ô chiết khấu nhanh.
- **Giao hàng**: checkbox bật/tắt, hiện thêm ô địa chỉ giao hàng + phí giao hàng; phí giao hàng
  cộng thẳng vào tổng tiền khách phải trả (không bị trừ chiết khấu), lưu vào đơn hàng và hiển thị
  trong `order_view.php`/hóa đơn in.
- **Ghi chú đơn hàng**: nhập ngay trong POS, lưu thẳng vào đơn thay vì phải vào sửa đơn sau đó.
- **Liên kết nhanh**: thêm 3 link Danh sách đơn hàng / Đổi trả hàng / Xem báo cáo ngay dưới nút
  Thanh toán, giống các nút thao tác nhanh trong POS của Sapo.

Đã test trên app.kt-soft.vn: thanh toán đơn 2 sản phẩm 100.000đ (tổng 200.000đ), chiết khấu 10% =
20.000đ, bật giao hàng với phí 20.000đ → tổng tiền cuối đúng 200.000đ (200.000 - 20.000 + 20.000),
địa chỉ giao hàng và ghi chú hiển thị đúng trong chi tiết đơn. Đã xóa sạch dữ liệu test.

**Vòng bổ sung tiếp theo — giữ nhiều đơn cùng lúc + thao tác nhanh** (theo ảnh Sapo, khắc phục đúng
giới hạn vừa nêu ở trên):
- **Giữ nhiều đơn hàng cùng lúc (tab)**: thanh tab "Đơn 1", "Đơn 2"... + nút "+" thêm đơn mới ở
  `pos.php`, mỗi tab giữ trạng thái độc lập (giỏ hàng, SĐT khách, chiết khấu, mã giảm giá, giao
  hàng, ghi chú, tiền khách đưa...) — chuyển qua lại giữa các tab không mất dữ liệu đơn đang dở, có
  thể đóng tab (xác nhận nếu còn sản phẩm chưa thanh toán). Thanh toán xong tự đóng tab đó.
  **Lưu ý kỹ thuật**: đây là trạng thái tạm trong bộ nhớ trình duyệt (JavaScript), mất khi tải lại
  trang — khác với đơn nháp lưu server-side thật của Sapo; phù hợp cho việc phục vụ xen kẽ nhiều
  khách tại quầy trong 1 phiên làm việc.
- **Thanh dịch vụ nhanh** (giống "Thao tác nhanh" của Sapo): Thêm dịch vụ (F9) mở danh sách sản
  phẩm loại Dịch vụ để thêm nhanh vào đơn (`pos_services.php`), Xóa toàn bộ sản phẩm (xóa nhanh giỏ
  hàng có xác nhận), cùng các link Thông tin khách hàng/Đổi trả hàng/Danh sách đơn hàng/Báo cáo.

Đã test trên app.kt-soft.vn: tạo "Đơn 2", thêm sản phẩm vào Đơn 2, chuyển về "Đơn 1" xác nhận vẫn
trống (0 sản phẩm) trong khi "Đơn 2 (1)" giữ đúng sản phẩm đã thêm — xác nhận cô lập trạng thái giữa
các tab hoạt động đúng. Đã xóa sạch dữ liệu test.

**Vòng bổ sung "Thao tác nhanh"** (khớp đúng các nút còn thiếu trong ảnh Sapo):
- **Đổi giá bán hàng**: ô đơn giá trong bảng giỏ hàng giờ có thể sửa trực tiếp ngay tại dòng sản
  phẩm (trước đây chỉ hiển thị, không sửa được) — nhân viên bán hàng có thể đổi giá bán ngay khi
  chốt đơn, không cần vào trang sản phẩm.
- **Khuyến mại (F8)**: xem nhanh danh sách chương trình khuyến mại tự động đang áp dụng
  (`pos_promotions.php`) — vì khuyến mại đã tự động cộng vào đơn nên đây chỉ là bảng tra cứu, không
  phải nút bật/tắt thủ công.
- **Danh sách sản phẩm**: tab chuyển giữa "Giỏ hàng" và duyệt toàn bộ danh mục sản phẩm đang bán
  (tối đa 60 sản phẩm/dịch vụ/combo, `pos_search.php?browse=1`) để chọn thêm vào đơn mà không cần
  gõ tìm kiếm — hữu ích khi nhân viên chưa nhớ tên/mã sản phẩm.
- **Thiết lập chung**: liên kết thẳng tới `sales_settings.php` ngay trong thanh thao tác nhanh.

Đã test trên app.kt-soft.vn: chuyển tab "Danh sách sản phẩm" hiển thị đúng sản phẩm đang bán, bấm
vào 1 sản phẩm thêm đúng vào giỏ hàng của tab đơn đang chọn; sửa trực tiếp đơn giá trong giỏ hàng từ
30.000 xuống 25.000 và xác nhận tổng tiền cập nhật đúng ngay lập tức; bấm Khuyến mại (F8) hiển thị
đúng thông báo "chưa có chương trình nào" khi không có khuyến mại active. Đã xóa sạch dữ liệu test.

**Vòng bổ sung "Đổi quà"** (khắc phục nốt giới hạn còn lại):
- **Danh mục quà đổi điểm** (`gifts.php`, ADMIN/MANAGER): khai báo quà tặng — tên, số điểm cần đổi,
  số lượng tồn kho (để trống = không giới hạn), bật/tắt. Thêm thẻ liên kết trong `settings.php`.
- **Đổi quà ngay trong POS** (nút "Đổi quà" ở `pos.php`): sau khi nhập đúng SĐT khách hàng đã có
  trong hệ thống, bấm "Đổi quà" hiện danh sách quà cùng số điểm khách đang có — quà nào đủ điểm mới
  bấm đổi được, quà chưa đủ điểm hiển thị mờ và khóa nút. Xác nhận qua `redeem_gift.php`: trừ đúng
  điểm khách hàng, trừ tồn kho quà (nếu có giới hạn), ghi lại lịch sử đổi quà
  (`gift_redemptions`) — toàn bộ trong 1 transaction, có khóa dòng (`FOR UPDATE`) để tránh đổi trùng
  khi 2 nhân viên thao tác cùng lúc.

Đã test trên app.kt-soft.vn: tạo quà "cần 100 điểm, tồn kho 5"; khách hàng test có 150 điểm đổi quà
thành công → còn đúng 50 điểm, tồn kho quà giảm còn 4, có bản ghi lịch sử đổi quà đúng dữ liệu; thử
đổi lần 2 khi chỉ còn 50 điểm (không đủ 100) → bị từ chối đúng với thông báo rõ ràng. Đã xóa sạch
dữ liệu test.

**Vòng bổ sung "Xem thêm thao tác"** (theo ảnh danh sách đầy đủ + panel "Thiết lập chung" của Sapo):
- **Tạo phiếu thu/chi**: thêm liên kết nhanh tới Sổ quỹ ngay trong thanh thao tác POS.
- **In đơn gần nhất (Alt+1)**: sau khi thanh toán, nút này (và phím tắt Alt+1) mở lại hóa đơn in
  của đơn vừa tạo mà không cần vào Danh sách đơn hàng tìm lại.
- **Thêm tags**: ô nhập tag cho đơn hàng ngay trong POS (lưu vào `orders.tags`, hiển thị trong
  `order_view.php` như tag của đơn tạo qua đường khác).
- **Gợi ý tiền thanh toán** (cấu hình bật/tắt trong `sales_settings.php`): khi bật, ô "Tiền khách
  đưa" hiện thêm các nút gợi ý nhanh (làm tròn nghìn, chục nghìn, trăm nghìn... của tổng tiền) để
  bấm chọn thay vì gõ tay.
- **Đơn vị chiết khấu mặc định** (cấu hình trong `sales_settings.php`): chọn VNĐ hoặc % làm mặc
  định cho ô "Chiết khấu đơn (F6)" mỗi khi mở tab đơn mới trong POS.

Đã test trên app.kt-soft.vn: bật "Gợi ý tiền thanh toán" + đặt mặc định "%" → tạo đơn 37.000đ, xác
nhận đúng 4 nút gợi ý (37.000/50.000/100.000/200.000) và ô chiết khấu mặc định hiện "%"; bấm gợi ý
50.000đ tính đúng tiền thối 13.000đ; nhập tag "test tag" và thanh toán, xác nhận tag lưu đúng vào
đơn hàng trong CSDL. Đã xóa sạch dữ liệu test và đặt lại cấu hình về mặc định ban đầu.

**Đính chính**: sau khi rà soát lại, một số mục trước đây bị xếp nhầm vào nhóm "cần phần cứng/tích
hợp thật" thực ra làm được hoàn toàn bằng phần mềm — đã bổ sung ngay trong vòng này:
- **Đổi chi nhánh ngay trong phiên POS** (`pos_switch_branch.php`): ADMIN/MANAGER có thể tạm chuyển
  sang bán hàng tại chi nhánh khác ngay trong `pos.php` (chọn ở góc trên) mà không cần đổi tài
  khoản — tồn kho, tìm kiếm sản phẩm, và đơn hàng tạo ra đều áp dụng đúng theo chi nhánh đang chọn
  (qua hàm dùng chung `effectiveBranchId()`), lựa chọn được nhớ theo phiên đăng nhập.
- **Kết nối màn hình phụ** (`pos_customer_display.php`): mở một cửa sổ trình duyệt riêng (dùng làm
  màn hình phụ quay ra phía khách) hiển thị trực tiếp giỏ hàng + tổng tiền đang cập nhật theo thời
  gian thực bằng `BroadcastChannel` — không cần phần cứng đặc biệt, chỉ cần 1 màn hình/máy tính thứ
  2 mở cùng trình duyệt.
- **Màn hình hiển thị mã QR thanh toán** (`payment_settings.php` khai báo tài khoản ngân hàng): nút
  "Hiện mã QR thanh toán" trong POS tạo ảnh mã VietQR đúng chuẩn qua dịch vụ ảnh công khai của
  VietQR.io (không cần đăng ký cổng thanh toán) — khách quét bằng app ngân hàng bất kỳ để chuyển
  khoản đúng số tiền, nhân viên vẫn tự xác nhận đã nhận tiền trước khi hoàn tất đơn thủ công.

Đã test trên app.kt-soft.vn: tạo chi nhánh phụ, chuyển "Đang bán tại" sang chi nhánh đó → xác nhận
tìm kiếm sản phẩm trả về đúng tồn kho riêng của chi nhánh phụ (khác chi nhánh chính), chuyển lại thì
tồn kho đổi đúng theo; cấu hình tài khoản ngân hàng test và bấm "Hiện mã QR thanh toán" cho đơn
88.000đ → ảnh mã QR VietQR hiển thị đúng, số tiền ghi bên dưới khớp chính xác với tổng đơn. Đã xóa
sạch chi nhánh/sản phẩm/cấu hình test sau khi kiểm tra.

Giới hạn thật sự còn lại (đúng nghĩa cần phần cứng vật lý hoặc hệ thống ngoài chuyên biệt, không thể
làm bằng phần mềm thuần): **Kết nối cân điện tử** (cần driver giao tiếp thiết bị cân qua cổng
USB/Bluetooth), **Đơn thuốc điện tử** (hệ thống quản lý nhà thuốc riêng, cần công nhận của cơ quan y
tế), **Bán hàng Offline thật** (cần kiến trúc offline-first với Service Worker + hàng đợi đồng bộ,
khối lượng công việc lớn hơn hẳn — có thể làm nếu bạn cần, nhưng nên tách thành một vòng phát triển
riêng). Tùy chỉnh màu sắc/nút chức năng hiển thị, Chọn lô tự động (theo dõi hạn sử dụng), Tách dòng
khi in, Sắp xếp thứ tự sản phẩm, Điều chỉnh cột hiển thị — đều làm được bằng phần mềm nhưng thuộc
nhóm tùy biến giao diện chi tiết, chưa làm trong vòng này để ưu tiên các tính năng nghiệp vụ cốt lõi
trước; báo lại nếu bạn muốn làm tiếp phần nào. Đơn giữ trên tab vẫn là trạng thái tạm trên trình
duyệt, không phải đơn nháp lưu server.

**Vòng hoàn thiện các tùy chỉnh giao diện còn lại** — đã làm nốt toàn bộ 5 mục còn lại nêu trên:
- **Tùy chỉnh màu sắc** (`display_settings.php`): chọn màu chủ đạo bằng color-picker, áp dụng ngay
  qua biến CSS `--brand`/`--brand-dark` (dùng `darkenColor()` tự tính màu hover đậm hơn) cho toàn bộ
  nút bấm, tab đang chọn, link trong menu.
- **Tùy chỉnh nút chức năng hiển thị**: 14 nút thao tác nhanh trong POS (Thêm dịch vụ, Khuyến mại,
  Đổi quà, QR thanh toán...) đều có thể ẩn/hiện riêng lẻ; các đoạn JS gắn sự kiện dùng hàm `on()`
  tự bỏ qua nếu nút đã bị ẩn, tránh lỗi.
- **Sắp xếp thứ tự sản phẩm**: 3 kiểu (Tên A→Z, Z→A, Mới thêm trước) áp dụng cho tab "Danh sách sản
  phẩm" trong POS.
- **Điều chỉnh cột hiển thị**: bật/tắt cột STT và Mã hàng (SKU) trong bảng giỏ hàng POS.
- **Tách dòng khi in**: tùy chọn in mỗi đơn vị sản phẩm thành 1 dòng riêng thay vì gộp theo số
  lượng trên hóa đơn in.
- **Chọn lô tự động (Alt+5)** (`batches.php` quản lý lô hàng theo hạn sử dụng, thuật toán FEFO —
  First Expired First Out): khai báo lô hàng (số lô, hạn sử dụng, số lượng) cho từng sản
  phẩm/chi nhánh; khi bán hàng, `pos_checkout.php` tự động trừ vào lô hết hạn sớm nhất trước — đây
  là sổ phụ theo dõi hạn sử dụng, không thay thế tồn kho chính nên sản phẩm chưa khai báo lô vẫn
  bán bình thường như cũ. Nút "Chọn lô tự động" trong POS cho xem trước lô nào sẽ được dùng.

Đã test trên app.kt-soft.vn: đổi màu chủ đạo sang đỏ → áp dụng ngay trên toàn bộ nút/sidebar; bật
cột STT + Mã hàng → hiện đúng trong bảng giỏ hàng; tạo 2 lô cho 1 sản phẩm (1 lô gần hạn hơn), bán
7 đơn vị → xác nhận trừ đúng theo FEFO (lô gần hạn hết trước, dư 2 đơn vị mới trừ sang lô xa hạn
hơn). Đã xóa sạch dữ liệu test và đặt lại toàn bộ cấu hình về mặc định ban đầu.

Đến đây toàn bộ danh sách "Xem thêm thao tác" và panel "Thiết lập chung" của Sapo trong các ảnh đã
được rà soát và triển khai đầy đủ những gì có thể làm bằng phần mềm. Giới hạn thật sự còn lại chỉ
còn Kết nối cân điện tử, Đơn thuốc điện tử, và Bán hàng Offline thật (kiến trúc offline-first) —
đúng nghĩa cần phần cứng hoặc khối lượng công việc lớn hơn hẳn, nên tách thành vòng riêng nếu cần.

## Vòng bổ sung: Bán hàng Offline

**Bán hàng Offline** (`pos.php`, hoạt động hoàn toàn phía trình duyệt bằng `localStorage`, không
cần Service Worker): khi mất mạng thật (sự kiện `online`/`offline` của trình duyệt) hoặc bật tay
qua nút "Bán hàng Offline" trong thanh thao tác nhanh, đơn hàng khi bấm Thanh toán sẽ được lưu tạm
vào hàng đợi trên máy (theo từng chi nhánh) thay vì gọi thẳng `pos_checkout.php` — vẫn hiển thị
thông báo thành công và đóng tab đơn như bán hàng bình thường để không làm gián đoạn luồng bán hàng
tại quầy. Ngay khi trình duyệt phát hiện có mạng trở lại (hoặc bấm "Đồng bộ ngay" trên banner cảnh
báo), hệ thống tự động gửi lần lượt từng đơn đang chờ lên `pos_checkout.php` theo đúng thứ tự đã
tạo; đơn nào lỗi (vd hết hàng lúc đồng bộ) vẫn giữ lại trong hàng đợi kèm thông báo lỗi lần trước,
không tự xóa để tránh mất đơn. Cũng tự bắt các trường hợp mất mạng đột ngột ngay lúc thanh toán
(request thất bại) và chuyển sang lưu offline thay vì báo lỗi mất luôn đơn.

Đã test trên app.kt-soft.vn: bật chế độ Offline thủ công → thanh toán 1 đơn 45.000đ → xác nhận đơn
được lưu vào hàng đợi trình duyệt và **chưa** xuất hiện trong Danh sách đơn hàng trên server; tắt
chế độ Offline → hệ thống tự động đồng bộ → xác nhận hàng đợi rỗng và đơn hàng đã xuất hiện đúng
trên server (45.000đ, trạng thái Hoàn thành). Đã xóa sạch dữ liệu test.

Giới hạn: hàng đợi offline lưu theo trình duyệt/thiết bị (không đồng bộ giữa các máy), nếu xóa dữ
liệu trình duyệt trong lúc đang có đơn chờ đồng bộ thì đơn đó sẽ mất — nên đồng bộ sớm khi có mạng
thay vì để dồn nhiều đơn qua nhiều ngày. Không dùng Service Worker nên chỉ hoạt động khi trang POS
đang mở sẵn trong trình duyệt (đúng với cách dùng thực tế: máy bán hàng luôn mở sẵn màn hình POS).

Đến đây cả 3 hạng mục còn lại của trang Bán hàng Sapo (Kết nối cân điện tử, Đơn thuốc điện tử, Bán
hàng Offline) chỉ còn 2 mục thật sự cần phần cứng/hệ thống quy định ngành riêng — không thể làm
bằng phần mềm thuần trong phạm vi ứng dụng bán lẻ tổng quát này.

## Vòng rà soát module Khách hàng (đối chiếu trang chi tiết khách hàng thực tế của Sapo)

Bổ sung các trường/tính năng còn thiếu so với trang "Chi tiết khách hàng" của Sapo:
- **Hồ sơ đầy đủ**: Ngày sinh, Giới tính, Email, Nhân viên phụ trách, Tags, Mã số thuế, Website,
  Mô tả — thêm vào `customer_form.php`/`customer_view.php`.
- **Thông tin gợi ý khi bán hàng**: Chiết khấu riêng cho từng khách hàng (%) và Hình thức thanh
  toán mặc định — hiển thị trong hồ sơ khách hàng (chưa tự động điền vào POS ở vòng này).
- **Nhiều địa chỉ giao hàng** (`customer_addresses`): thêm/xóa nhiều địa chỉ cho 1 khách hàng, đánh
  dấu địa chỉ mặc định, ngay trong `customer_view.php`.
- **Ghi chú khách hàng** (`customer_notes`): nhật ký ghi chú có thời gian + người tạo, thêm/xóa
  ngay trong trang chi tiết khách hàng — khác với trường Mô tả (chỉ 1 đoạn văn bản tĩnh).
- **Hạng thẻ khách hàng** (`customer_tiers.php`): khai báo các mốc chi tiêu (vd Bạc/Vàng/Kim
  cương) kèm % chiết khấu tham chiếu; trang chi tiết khách hàng tự tính hạng hiện tại theo tổng chi
  tiêu tích lũy và hiển thị "cần chi thêm bao nhiêu để lên hạng tiếp theo".

Đã test trên app.kt-soft.vn: tạo khách hàng đầy đủ hồ sơ (ngày sinh, giới tính, mã số thuế...) →
hiển thị đúng toàn bộ trong trang chi tiết; thêm địa chỉ + ghi chú → lưu và hiện đúng; tạo 3 hạng
thẻ (Bạc/Vàng/Kim cương) → khách chưa có đơn hàng nào tự động xếp đúng hạng thấp nhất và hiện đúng
số tiền cần chi thêm để lên hạng kế tiếp. Đã xóa sạch dữ liệu test.

Giới hạn: chiết khấu riêng khách hàng và hạng thẻ hiện là dữ liệu tham chiếu hiển thị, **chưa tự
động áp dụng vào tính giá khi bán hàng trong POS** — nếu cần tự động trừ chiết khấu theo khách hàng
hoặc hạng thẻ ngay khi thanh toán, cần làm thêm một vòng tích hợp riêng vào `pos_checkout.php`.

## Vòng rà soát module Sản phẩm & Kho, Báo cáo (đối chiếu trang thực tế của Sapo)

**Sản phẩm & Kho** — bổ sung các trường còn thiếu so với trang "Chi tiết sản phẩm" của Sapo:
- **Khối lượng (gram)**, **Thuế suất** (liên kết tới `tax_rates.php` đã có sẵn từ vòng trước — trước
  đó chỉ là danh mục tham chiếu độc lập, giờ gán trực tiếp vào từng sản phẩm), **Áp dụng bảo hành**
  (cờ đánh dấu sản phẩm có hỗ trợ bảo hành) — thêm vào `product_form.php`.
- **Tồn kho chi tiết hơn theo chi nhánh**: thêm **Tồn tối đa** và **Vị trí lưu kho** (vd "A1-K2")
  bên cạnh Tồn tối thiểu đã có, khớp đúng các cột "Tồn tối đa"/"Điểm lưu kho" trong tab Tồn kho của
  Sapo — sửa trong `inventory_save.php` + form tồn kho theo chi nhánh của `product_form.php`.

**Báo cáo** — bổ sung 3 báo cáo còn thiếu so với "Báo cáo bán hàng" của Sapo:
- **Doanh thu theo phương thức thanh toán** (Tiền mặt/Chuyển khoản/Quẹt thẻ/QR).
- **Doanh thu theo nhân viên bán hàng**.
- **Trả hàng trong kỳ**: tổng số đơn trả + tổng tiền hoàn, và top sản phẩm bị trả nhiều nhất.

Đã test trên app.kt-soft.vn: tạo sản phẩm với khối lượng/thuế suất/cờ bảo hành → lưu và hiển thị lại
đúng; cập nhật tồn tối đa + vị trí kho cho 1 chi nhánh → lưu đúng; thanh toán 1 đơn bằng Chuyển
khoản rồi trả lại 1 phần → xác nhận cả 3 báo cáo mới (theo phương thức thanh toán, theo nhân viên,
trả hàng trong kỳ) đều hiện đúng số liệu khớp với giao dịch vừa tạo. Đã xóa sạch dữ liệu test.

Giới hạn: chưa làm "Có thể bán" riêng biệt với "Tồn kho" (Sapo tách 2 số này để trừ đi hàng đang giữ
chỗ cho đơn nháp/đang xử lý — QLBH2 hiện tại không có khái niệm đơn nháp giữ chỗ tồn kho trước khi
hoàn thành nên 2 số này luôn bằng nhau, không cần tách); chưa có "Lịch sử kho" dạng sổ cái hợp nhất
theo từng sản phẩm (các thay đổi tồn kho hiện nằm rải rác trong lịch sử nhập hàng/kiểm hàng/chuyển
hàng/đổi trả — có thể gộp thành 1 trang riêng nếu cần).

## Vòng rà soát module Vận chuyển / Bảo hành / Marketing (đối chiếu menu thực tế của Sapo)

Đối chiếu đầy đủ menu con của 3 module này trên Sapo:
- **Vận chuyển**: Sapo có Quản lý vận đơn, Đối soát COD và phí, Kết nối đối tác (GHN/GHTK — cần
  API thật, ngoài phạm vi), Cấu hình giao hàng. QLBH2 đã có Quản lý vận đơn + Đối soát COD
  (`shipments.php`) khớp đúng — bổ sung thêm trường còn thiếu: **Người nhận** và **SĐT người
  nhận** riêng biệt với khách hàng đặt đơn (vd người khác nhận hộ), tự điền mặc định theo thông tin
  khách hàng nhưng sửa được, hiển thị trong danh sách vận đơn.
- **Bảo hành**: Sapo có Phiếu bảo hành, Yêu cầu bảo hành, Chính sách bảo hành — QLBH2 đã có đủ cả 3
  (`warranty_cards.php`, `warranty_claim_form.php`/`warranty_claim_view.php`,
  `warranty_policies.php`), không phát hiện thiếu gì thêm.
- **Marketing**: Sapo có Danh sách chiến dịch, Quản lý khuyến mại, Quản lý mã giảm giá — QLBH2 đã
  có đủ (`campaigns.php`, `promotions.php`, `coupons.php`), không phát hiện thiếu gì thêm.

Đã test trên app.kt-soft.vn: tạo vận đơn với người nhận khác thông tin khách hàng gốc → lưu và hiển
thị đúng cả tên + SĐT người nhận trong danh sách vận đơn. Đã xóa sạch dữ liệu test.

---

**Tổng kết đợt rà soát toàn diện 5 module** (Cấu hình, Bán hàng/POS, Khách hàng, Sản phẩm & Kho,
Báo cáo, Vận chuyển/Bảo hành/Marketing) đối chiếu trực tiếp với giao diện Sapo thật: đã bổ sung đầy
đủ các trường/tính năng khả thi bằng phần mềm. Các giới hạn còn lại đều đã ghi rõ lý do trong từng
mục — chủ yếu là tích hợp API/phần cứng thật của bên thứ 3 (thanh toán, hóa đơn điện tử, vận chuyển,
sàn TMĐT, cân điện tử) nằm ngoài quyết định phạm vi ban đầu của dự án.

## Vòng rà soát module Sổ quỹ (đối chiếu trang thực tế của Sapo)

Bổ sung các tính năng còn thiếu so với trang "Sổ quỹ" của Sapo:
- **Lọc theo khoảng ngày** (mặc định 30 ngày gần nhất), **loại phiếu** (Thu/Chi), **chi nhánh**,
  **hình thức thanh toán** — trước đây chỉ hiển thị 100 phiếu gần nhất không lọc được.
- **Số dư đầu kỳ** và **Tồn cuối kỳ**: tính đúng số dư lũy kế trước ngày bắt đầu lọc + cộng/trừ thu
  chi trong kỳ đang xem, khớp đúng cách Sapo hiển thị (không chỉ là tổng thu/chi trong khoảng lọc).
- **Hình thức thanh toán** cho từng phiếu thu/chi (Tiền mặt/Chuyển khoản/Quẹt thẻ/QR).
- **Xuất file CSV** (`cashbook_export.php`) theo đúng bộ lọc đang áp dụng.

Đã test trên app.kt-soft.vn: tạo phiếu thu 100.000đ bằng Chuyển khoản → Tồn cuối kỳ cập nhật đúng
từ 0 lên 100.000đ; lọc theo loại "Phiếu chi" → phiếu thu vừa tạo biến mất đúng khỏi danh sách; xuất
file CSV → nội dung khớp đúng dữ liệu phiếu vừa tạo. Đã xóa sạch dữ liệu test.

## Vòng rà soát module Nhập hàng (đối chiếu trang thực tế của Sapo)

Phát hiện và bổ sung 1 tính năng còn thiếu hoàn toàn: **Quản lý trả hàng NCC** (Sapo có nút riêng
"Quản lý trả hàng NCC" ngay trên trang Danh sách đơn nhập hàng) — trước đây QLBH2 chưa có bất kỳ
khung dữ liệu nào để trả lại hàng đã nhập cho nhà cung cấp khi phát hiện hàng lỗi/hết hạn.

- **`supplier_return_form.php`**: nhập mã phiếu nhập gốc, chọn số lượng trả cho từng dòng sản phẩm
  (giới hạn không vượt quá số đã nhập trừ đi số đã trả trước đó, giống hệt cơ chế `order_return_form.php`
  cho khách hàng), nhập lý do.
- **`supplier_returns.php`**: danh sách các lần trả hàng NCC, liên kết ngược tới phiếu nhập gốc.
- Khi tạo trả hàng: tự động **trừ tồn kho** (hàng đã trả vật lý cho NCC) và **giảm công nợ phải trả**
  cho đúng nhà cung cấp theo giá trị hàng trả (dùng giá nhập gốc của từng dòng).
- Thêm nút "Trả hàng NCC" ngay trên trang chi tiết phiếu nhập (`stock_receipt_view.php`) để thao
  tác nhanh không cần gõ lại mã phiếu.

Đã test trên app.kt-soft.vn: tạo NCC + phiếu nhập 10 sản phẩm (giá nhập 10.000đ, công nợ NCC
100.000đ) → trả lại 3 sản phẩm lỗi → xác nhận tồn kho giảm đúng còn 7, công nợ NCC giảm đúng còn
70.000đ, và số lượng còn có thể trả hiển thị đúng 7 khi mở lại phiếu (chặn trả vượt quá số đã nhập).
Đã xóa sạch dữ liệu test.

Do phiên đăng nhập Sapo hết hạn giữa chừng, chưa kịp đối chiếu sâu thêm "Đặt hàng nhập" và "Kiểm
hàng/Chuyển hàng" trong vòng này — có thể tiếp tục nếu bạn đăng nhập lại và muốn rà soát tiếp.

## Vòng bổ sung: Chuyển hàng "đang vận chuyển" + Kiểm hàng "nháp/cân bằng" (đúng quy trình thực tế Sapo)

Nhờ đăng nhập lại được Sapo, phát hiện 2 gap quan trọng trong quy trình kho: cả **Chuyển hàng** và
**Kiểm hàng** của QLBH2 trước đây áp dụng thay đổi tồn kho **ngay lập tức** khi tạo phiếu, trong khi
Sapo tách thành 2 bước rõ ràng (tạo phiếu nháp → xác nhận riêng để áp dụng), khớp với thực tế vận
hành (hàng cần thời gian di chuyển giữa chi nhánh, kiểm hàng cần review trước khi cập nhật hệ thống).

**Chuyển hàng — thêm trạng thái "Đang vận chuyển"**:
- Tạo phiếu chuyển: trừ tồn kho chi nhánh gửi ngay (hàng đã rời kho), nhưng **chưa cộng** vào chi
  nhánh nhận — trạng thái `IN_TRANSIT`.
- **Xác nhận đã nhận hàng** (`stock_transfer_receive.php`): cộng đúng số lượng vào tồn kho chi
  nhánh nhận, chuyển trạng thái `COMPLETED`, ghi lại người nhận + thời điểm.
- **Hủy chuyển hàng** (khi hàng chưa tới nơi): hoàn lại tồn kho về chi nhánh gửi, trạng thái
  `CANCELLED`.

**Kiểm hàng — thêm trạng thái "Nháp / Đã cân bằng"** (khớp đúng 2 mốc thời gian "Ngày tạo" và
"Ngày cân bằng" của Sapo):
- Tạo phiếu kiểm: chỉ lưu số đếm thực tế, **chưa** cập nhật tồn kho hệ thống — trạng thái `DRAFT`.
- **Cân bằng kho** (`stock_take_balance.php`): áp dụng số đã đếm vào tồn kho hệ thống, chuyển
  trạng thái `BALANCED`, ghi lại người cân bằng + thời điểm.

Đã test trên app.kt-soft.vn: tạo phiếu chuyển 8 sản phẩm giữa 2 chi nhánh → xác nhận tồn kho chi
nhánh gửi giảm ngay nhưng chi nhánh nhận **chưa** có hàng (đúng "đang vận chuyển") → bấm xác nhận
nhận hàng → tồn kho chi nhánh nhận tăng đúng 8, trạng thái chuyển "Đã nhận hàng". Tạo phiếu kiểm
hàng đếm 17 (hệ thống ghi 20) → xác nhận tồn kho **chưa đổi**, trạng thái "Chưa cân bằng" → bấm
"Cân bằng kho" → tồn kho cập nhật đúng thành 17, trạng thái "Đã cân bằng". Đã xóa sạch dữ liệu test.

**Lưu ý kỹ thuật phát hiện trong quá trình test**: một lần upload FTP báo thành công nhưng nội dung
file trên server vẫn là bản cũ (xác minh lại bằng cách tải file qua FTP so với bản local) — nghi do
race condition khi upload nhiều file liên tiếp quá nhanh trong vòng lặp. Đã khắc phục bằng cách
upload lại từng file kèm xác minh nội dung qua FTP trước khi test — nên áp dụng cách xác minh này
cho các lần deploy nhiều file sau này thay vì chỉ tin vào exit code thành công của curl.

## Vòng bổ sung: Widget "Đơn hàng cần xử lý" trên Dashboard

Đối chiếu trang Danh sách đơn hàng của Sapo, phát hiện thiếu widget tổng hợp nhanh số đơn hàng theo
từng bước cần xử lý (Sapo hiển thị ngay đầu trang: Chờ duyệt/Chờ thanh toán/Chờ đóng gói/Chờ lấy
hàng/Đang giao hàng/Chờ giao lại). Đã bổ sung vào Dashboard (`index.php`) 4 ô đếm số đơn theo đúng
4 bước pipeline QLBH2 đang có (Chờ duyệt/Chờ đóng gói/Chờ lấy hàng/Đang giao hàng), số > 0 tô đỏ để
dễ nhận biết, bấm vào từng ô dẫn thẳng tới danh sách đơn đã lọc đúng trạng thái đó.

Đã test trên app.kt-soft.vn: tạo 1 đơn trạng thái "Chờ duyệt" → widget hiện đúng số 1 tô đỏ, xác
nhận link lọc đúng trạng thái. Đã xóa sạch dữ liệu test.

---

**Tổng kết đợt rà soát toàn diện lần cuối**: đã đối chiếu trực tiếp với Sapo thật xuyên suốt các
module Cấu hình, Bán hàng/POS, Khách hàng, Sản phẩm & Kho, Báo cáo, Vận chuyển/Bảo hành/Marketing,
Sổ quỹ, Nhập hàng (kể cả phát hiện tính năng thiếu hoàn toàn "Trả hàng NCC"), và quy trình vận hành
kho (Chuyển hàng/Kiểm hàng cần tách bước xác nhận thay vì áp dụng ngay). Phần mềm QLBH2 hiện đã bám
sát Sapo về mặt nghiệp vụ trong phạm vi phần mềm quản lý bán hàng tổng quát (không phải phần mềm
chuyên ngành dược như cửa hàng mẫu đang dùng để khảo sát). Các giới hạn còn lại đều là tích hợp
API/phần cứng thật bên thứ 3 nằm ngoài quyết định phạm vi ban đầu của dự án, đã ghi rõ lý do trong
từng mục tương ứng phía trên.

## Vòng rà soát tiếp module Đặt hàng nhập / Nhập kho (đối chiếu trang chi tiết đơn nhập thực tế của Sapo)

Đối chiếu trang "Chi tiết đơn nhập" (PON...) thật trên Sapo, phát hiện QLBH2 còn thiếu các trường:
Ngày hẹn giao, Ngày hoá đơn, Tham chiếu, Nhân viên phụ trách (ở cả đặt hàng nhập và phiếu nhập kho),
Chiết khấu/Chi phí nhập hàng (ảnh hưởng tổng tiền phiếu nhập), và theo dõi thanh toán NCC theo từng
phiếu nhập riêng biệt (khác với công nợ tổng `suppliers.debt` đã có).

Đã bổ sung:
- `purchase_orders`: thêm `assigned_staff_id`, `expected_delivery_date`, `reference_no`.
- `stock_receipts`: thêm `invoice_date`, `reference_no`, `discount_amount`, `extra_cost`, `paid_amount`.
- `purchase_order_form.php`/`purchase_order_view.php`: chọn/hiển thị nhân viên phụ trách, ngày hẹn
  giao, số tham chiếu.
- `stock_receipt_form.php`: nhập ngày hoá đơn, tham chiếu, chiết khấu, chi phí nhập hàng, số tiền
  trả NCC ngay khi tạo phiếu — tổng tiền tính lại theo công thức `Tạm tính - Chiết khấu + Chi phí`,
  chỉ phần chưa trả (`total - paid_amount`) mới cộng vào công nợ NCC.
- `stock_receipt_view.php`: thêm card "Thanh toán NCC" (Tiền cần trả/Đã trả/Còn phải trả) và form
  ghi nhận trả thêm khi còn nợ.
- `stock_receipt_pay.php` (mới): xử lý trả thêm cho một phiếu nhập cụ thể, tăng `paid_amount` của
  phiếu và giảm tương ứng `suppliers.debt` (chặn không cho âm bằng `GREATEST(0, debt - ?)`).

Đã test trên app.kt-soft.vn: tạo phiếu nhập giá trị tạm tính 100.000, chiết khấu 10.000, chi phí
nhập hàng 20.000 → tổng tiền hiển thị đúng 110.000; trả trước 50.000 khi tạo phiếu → công nợ NCC
tăng đúng 60.000 (phần chưa trả); ghi nhận trả thêm 60.000 qua `stock_receipt_pay.php` → "Còn phải
trả" về 0 và công nợ NCC về 0. Tạo đặt hàng nhập với nhân viên phụ trách/ngày hẹn giao/tham chiếu →
hiển thị đúng trên trang chi tiết. Đã xóa sạch toàn bộ dữ liệu test (nhà cung cấp, sản phẩm, đặt
hàng nhập, phiếu nhập test) và các file tạm trên server.

## Vòng rà soát module Bán hàng (đối chiếu trang chi tiết đơn hàng thực tế của Sapo)

Đối chiếu trang "Chi tiết đơn hàng" thật trên Sapo (SON...), phát hiện gap lớn nhất: các cột
`orders.payment_status`/`paid_amount` và `customers.debt` đã có sẵn trong schema từ trước nhưng
**hoàn toàn không được dùng ở luồng bán hàng** — `pos_checkout.php` luôn tạo đơn với
`payment_status = PAID` và `paid_amount = total_amount` bất kể khách trả bao nhiêu, nên không thể
"bán nợ" (cho khách trả trước một phần, phần còn lại ghi vào công nợ khách hàng) như Sapo thật vẫn
làm — trong khi form thu công nợ ở `customer_view.php` đã có sẵn nhưng chưa từng có gì tạo ra nợ để
thu cả.

Đã bổ sung:
- `pos.php`: thêm checkbox "Cho khách nợ một phần" + ô "Khách trả trước" (chỉ áp dụng khi đã có
  khách hàng — nhập SĐT); trạng thái bật/tắt và giá trị được lưu riêng theo từng tab đơn hàng POS
  giống các trường khác.
- `pos_checkout.php`: nhận thêm `paid_amount` — nếu bỏ trống hoặc ≥ tổng tiền thì coi như thanh
  toán đủ (hành vi cũ không đổi); nếu nhỏ hơn thì bắt buộc phải có khách hàng, tính
  `payment_status` (PAID/PARTIAL/UNPAID) theo đúng số đã trả, chỉ ghi `payments` với số tiền thực
  trả, và cộng phần còn thiếu (`total - paid`) vào `customers.debt`.
- `order_view.php`: thêm card "Thanh toán" (Đã thanh toán/Còn phải trả) và form ghi nhận thu thêm
  khi đơn còn nợ, giống hệt mẫu đã làm cho phiếu nhập kho.
- `order_pay.php` (mới): xử lý thu nợ cho một đơn hàng cụ thể — tăng `paid_amount`/cập nhật
  `payment_status` của đơn, giảm tương ứng `customers.debt`, và ghi thêm 1 dòng `payments`.
- `orders.php`: thêm cột "Thanh toán" (Đã thanh toán/Trả một phần/Chưa thanh toán) vào danh sách
  đơn hàng, giống cột "Trạng thái Thanh toán" trên danh sách đơn hàng thật của Sapo.

Đã test trên app.kt-soft.vn: tạo đơn hàng 50.000 cho khách mới (SĐT test), trả trước 20.000 → đơn
hiển thị đúng "Còn phải trả 30.000", công nợ khách hàng tăng đúng 30.000; ghi nhận thu nốt 30.000
qua `order_pay.php` → "Còn phải trả" về 0 và công nợ khách hàng về 0; danh sách đơn hàng hiển thị
đúng badge trạng thái thanh toán. Đã xóa sạch toàn bộ dữ liệu test (đơn hàng, khách hàng, sản
phẩm test) và các file tạm trên server.

**Giới hạn còn lại của module Bán hàng so với Sapo thật** (không nằm trong phạm vi bổ sung lần
này, ghi nhận để tham khảo sau): thẻ thông tin khách hàng trên trang chi tiết đơn chưa hiển thị
số liệu tổng hợp nhanh (tổng chi tiêu/số đơn trả hàng/số đơn giao thất bại) như Sapo — các số liệu
này đã có đủ trên trang `customer_view.php` riêng, chỉ chưa rút gọn hiển thị lại trên trang đơn
hàng; trường "Hẹn giao hàng" ở cấp đơn hàng (khác với `expected_delivery_date` của đặt hàng nhập)
và "Đường dẫn"/"Chính sách giá" là các trường phụ ít dùng trong nghiệp vụ thực tế của cửa hàng mẫu
khảo sát, có thể bổ sung sau nếu cần.

## Vòng rà soát module Khách hàng (đối chiếu trang chi tiết khách hàng thực tế của Sapo)

Đối chiếu trang "Chi tiết khách hàng" thật trên Sapo (có các tab Lịch sử mua hàng/Công nợ/Liên
hệ/Địa chỉ/Ghi chú/Nhóm khách hàng), phát hiện QLBH2 đã có đủ các trường thông tin cá nhân, thông
tin mua hàng (tổng chi tiêu/số đơn/hạng thẻ/điểm tích lũy), địa chỉ, ghi chú — chỉ thiếu tab
**"Công nợ"**: Sapo lưu lại lịch sử từng lần phát sinh/thu nợ (kèm ngày giờ, người thực hiện, đơn
hàng liên quan), trong khi QLBH2 trước đó chỉ có số dư nợ hiện tại và một form thu nợ chung chung,
không lưu lại đã thu bao nhiêu lần, khi nào, ai thu, thu cho khoản nào.

Đã bổ sung:
- Bảng `customer_debt_entries` (mới): ghi lại mọi thay đổi công nợ khách hàng — số dương là phát
  sinh nợ, số âm là đã thu — kèm `order_id` (nếu có), ghi chú, người thực hiện, thời gian.
- `pos_checkout.php`: khi bán nợ một phần, ghi thêm 1 dòng vào `customer_debt_entries` (+ phần
  chưa trả).
- `order_pay.php`: khi thu nợ cho một đơn hàng cụ thể, ghi thêm 1 dòng (- số tiền đã thu).
- `customer_view.php`: form "Ghi nhận thu công nợ" chung (không gắn với đơn nào) cũng ghi lại vào
  ledger; thêm bảng "Lịch sử công nợ" hiển thị tối đa 50 dòng gần nhất (thời gian, nội dung, đơn
  hàng liên quan nếu có, người thực hiện, số tiền — số dương tô đỏ là phát sinh nợ, số âm tô xanh
  là đã thu).

Đã test trên app.kt-soft.vn: tạo đơn 80.000 cho khách mới, trả trước 30.000 → công nợ khách tăng
đúng 50.000, lịch sử công nợ hiện dòng "Bán hàng chưa thanh toán đủ" +50.000; thu nốt 50.000 qua
`order_pay.php` → công nợ về 0, lịch sử công nợ hiện thêm dòng "Thu nợ đơn hàng" -50.000. Đã xóa
sạch toàn bộ dữ liệu test (đơn hàng, khách hàng, sản phẩm test, các dòng ledger liên quan) và các
file tạm trên server.

**Giới hạn còn lại**: tab "Liên hệ" của Sapo (quản lý nhiều người liên hệ phụ cho một khách hàng,
ví dụ khách hàng doanh nghiệp có nhiều đầu mối) không có trong QLBH2 — bỏ qua vì cửa hàng mẫu khảo
sát là bán lẻ dược phẩm, không có nhu cầu multi-contact theo B2B.

## Vòng rà soát module Sản phẩm (đối chiếu trang chi tiết sản phẩm thực tế của Sapo)

Đối chiếu trang "Chi tiết sản phẩm" thật trên Sapo (có bảng "Chi tiết phiên bản" liệt kê SKU, mã
barcode, đơn vị, các mức giá, tồn kho theo chi nhánh cho từng biến thể), nhận thấy `product_form.php`
của QLBH2 đã bám khá sát: SKU, barcode (ở cấp sản phẩm), tên, đơn vị, danh mục, nhãn hiệu, giá
vốn/giá bán, khối lượng, thuế suất, bảo hành, tags, giá riêng theo bảng giá, tồn kho/định mức/vị trí
kho theo từng chi nhánh, biến thể (màu/size) với tồn kho riêng, combo. Gap cụ thể phát hiện được:
**biến thể sản phẩm (`product_variants`) không có cột `barcode` riêng** — chỉ sản phẩm gốc có
barcode, nên khi một sản phẩm bán theo nhiều biến thể (mỗi biến thể có mã vạch in riêng trên bao
bì thực tế, ví dụ "Bánh gấu Mr Mee - Vị socola" có barcode khác "Gói lớn"), máy quét mã vạch tại
POS sẽ không tìm ra đúng biến thể — đây là lỗi chức năng ảnh hưởng trực tiếp đến việc bán hàng bằng
máy quét, không chỉ là thiếu trường hiển thị.

Đã bổ sung:
- `product_variants`: thêm cột `barcode`.
- `variant_save.php`: nhận và lưu barcode khi tạo biến thể mới.
- `variant_update.php` (mới): cho phép sửa barcode của biến thể đã có (trước đây biến thể chỉ tạo
  được, không sửa được gì sau khi tạo).
- `product_form.php`: thêm ô nhập barcode khi tạo biến thể mới, và form sửa barcode nhanh ngay
  trên mỗi biến thể đã có.
- `pos_search.php`: thêm `v.barcode` vào điều kiện tìm kiếm biến thể, để máy quét/ô tìm kiếm tại
  POS tìm đúng biến thể theo mã vạch riêng của nó.

Đã test trên app.kt-soft.vn: tạo biến thể mới kèm barcode `8938501234567` → tìm bằng
`pos_search.php?q=8938501234567` ra đúng biến thể; sửa barcode biến thể đã có qua
`variant_update.php` → tìm bằng barcode mới cũng ra đúng kết quả. Đã xóa sạch dữ liệu test.

**Giới hạn còn lại**: trang danh sách sản phẩm (`products.php`) chưa hiển thị cột ảnh thu nhỏ như
Sapo (tính năng tải ảnh đã có sẵn ở `product_form.php`, chỉ chưa hiển thị lại ở danh sách) —
thuần túy thẩm mỹ, không ảnh hưởng nghiệp vụ nên chưa ưu tiên làm ngay; "Lịch sử kho" (nhật ký thay
đổi tồn kho theo sản phẩm) của Sapo cũng chưa có bản ghi tổng hợp riêng trong QLBH2, dữ liệu tương
đương nằm rải rác ở các phiếu nhập/chuyển/kiểm/trả hàng đã có, có thể gộp lại thành 1 trang xem
sau nếu cần.

## Vòng rà soát module Báo cáo (đối chiếu sidebar báo cáo thực tế của Sapo)

Sapo có 5 nhóm báo cáo riêng biệt ở sidebar: Báo cáo bán hàng, Báo cáo nhập hàng, Báo cáo kho, Báo
cáo tài chính, Báo cáo khách hàng. `reports.php` của QLBH2 (1 trang tổng hợp duy nhất) đã che phủ
khá tốt "Báo cáo bán hàng" (doanh thu, lãi gộp, top sản phẩm/khách hàng, theo kênh/ngày/thanh
toán/nhân viên, trả hàng) và một phần "Báo cáo tài chính" (sổ quỹ) — nhưng **hoàn toàn chưa có gì
cho "Báo cáo nhập hàng"** (dù module Đặt hàng/Nhập kho đã xây khá đầy đủ ở các vòng trước) và phần
"Báo cáo kho" chỉ có 1 con số tổng giá trị tồn, không có bảng chi tiết theo từng sản phẩm.

Đã bổ sung vào `reports.php` (theo đúng khoảng thời gian lọc sẵn có):
- "Nhập hàng trong kỳ": số phiếu nhập, tổng tiền nhập, tổng công nợ NCC phát sinh trong kỳ
  (`total_amount - paid_amount` cộng dồn các phiếu nhập trong kỳ).
- "Nhập hàng theo nhà cung cấp": top 10 NCC theo tổng tiền nhập.
- "Nhập hàng theo sản phẩm": top 10 sản phẩm nhập nhiều nhất theo số lượng.
- "Tồn kho theo sản phẩm": bảng top 15 sản phẩm có giá trị tồn kho cao nhất (SKU, tên, số lượng,
  giá trị tồn) — trước đây chỉ có 1 số tổng, không biết sản phẩm nào đang chiếm giá trị tồn kho
  lớn nhất.

Đã test trên app.kt-soft.vn: tạo 1 phiếu nhập test (nhà cung cấp mới, sản phẩm mới) trị giá
150.000, đã trả 50.000 → báo cáo hiển thị đúng "Số phiếu nhập: 1", "Tổng tiền nhập: 150.000", "Còn
nợ NCC: 100.000", đúng cả 2 bảng theo nhà cung cấp và theo sản phẩm. Đã xóa sạch dữ liệu test.

**Giới hạn còn lại**: các báo cáo còn lại của Sapo (ví dụ báo cáo giao hàng theo tình trạng, báo
cáo tùy chỉnh do người dùng tự tạo) là các tính năng phụ, phức tạp hơn nhiều so với nhu cầu thực
tế của một phần mềm quản lý bán hàng tổng quát — chưa làm, có thể bổ sung sau nếu người dùng có
nhu cầu cụ thể.

## Vòng rà soát tiếp module Vận chuyển (đối chiếu trang chi tiết phiếu giao hàng thực tế của Sapo)

Vòng trước đã rà soát ở mức menu; vòng này đối chiếu sâu hơn ở trang "Chi tiết phiếu giao hàng"
thật của Sapo (mã vận đơn, người nhận, địa chỉ, ngày đóng gói/xuất kho/giao hàng, đối tác vận
chuyển, tiền thu hộ COD, phí trả ĐTVC, đối soát, **người trả phí**). QLBH2 đã có hầu hết các
trường quan trọng (người nhận, SĐT, đơn vị, mã vận đơn, trạng thái, phí ship, COD, đối soát) —
gap cụ thể: **thiếu trường "Người trả phí giao hàng" (khách trả / shop trả)**, khiến số tiền thực
tế cửa hàng nhận về từ đơn vị vận chuyển sau khi đối soát bị tính sai — nếu khách trả phí ship thì
đơn vị vận chuyển giữ lại phần phí đó từ tiền COD trước khi trả về cho shop (thực nhận = COD - phí
ship), còn nếu shop trả phí thì thực nhận đúng bằng COD; trước đây QLBH2 chỉ hiển thị 2 số riêng
lẻ (phí ship, COD) mà không có công thức nối chúng lại nên số liệu đối soát dễ sai.

Đã bổ sung:
- `shipments`: thêm cột `fee_payer` (CUSTOMER/SHOP).
- `shipment_form.php`: chọn người trả phí khi tạo/sửa vận đơn.
- `shipments.php`: thêm cột "Người trả phí" và "Thực nhận" (tính theo công thức trên) vào danh
  sách; thêm thẻ tổng "Thực nhận chưa đối soát" bên cạnh thẻ "COD chưa đối soát" đã có.
- `order_view.php`: card "Vận chuyển" hiển thị thêm người trả phí và số tiền thực nhận từ ĐVVC.

Đã test trên app.kt-soft.vn: tạo vận đơn COD 100.000, phí ship 20.000, khách trả phí → "Thực nhận"
hiển thị đúng 80.000 ở cả danh sách vận chuyển và trang chi tiết đơn hàng. Đã xóa sạch dữ liệu test.

**Giới hạn còn lại**: mốc thời gian riêng cho từng bước (ngày đóng gói/ngày xuất kho/ngày giao
hàng) mà Sapo lưu chi tiết — QLBH2 chỉ lưu 1 trạng thái hiện tại + thời điểm cập nhật gần nhất
(`updated_at`), không có lịch sử timestamp theo từng mốc; địa chỉ nhận hàng tách riêng
phường/quận/tỉnh của Sapo — QLBH2 dùng 1 trường địa chỉ gộp ở cấp đơn hàng. Cả hai đều là tính
năng phụ, có thể bổ sung sau nếu cần đối soát chi tiết hơn với đối tác vận chuyển thật.

## Vòng rà soát tiếp module Bảo hành (đối chiếu quy trình thực tế của Sapo)

Vòng trước chỉ soát ở mức menu (Phiếu bảo hành/Yêu cầu bảo hành/Chính sách bảo hành đã có đủ cả 3
trang). Vòng này đối chiếu kỹ hơn *cách phiếu bảo hành được tạo ra* trên Sapo: trang danh sách
phiếu bảo hành của Sapo ghi rõ "Bạn cần tạo sản phẩm có bảo hành và xuất kho bán sản phẩm, **hệ
thống sẽ tự động tạo phiếu bảo hành tương ứng**" — tức phiếu bảo hành sinh ra tự động ngay khi bán
hàng, không cần thao tác thủ công riêng. QLBH2 trước đó tuy có đủ dữ liệu cần thiết (`has_warranty`
trên sản phẩm, `warranty_cards` liên kết `order_item_id`/`policy_id`) nhưng **chỉ tạo được phiếu
bảo hành thủ công** qua `warranty_card_form.php` (nhân viên phải nhớ tra mã đơn hàng rồi tạo tay
sau khi bán) — dễ bị bỏ sót trong thực tế vận hành.

Đã bổ sung:
- `products`: thêm `warranty_policy_id` (chính sách bảo hành mặc định của sản phẩm, tùy chọn —
  không chọn thì dùng mặc định 12 tháng như hành vi cũ).
- `product_form.php`: thêm ô chọn chính sách bảo hành mặc định ngay cạnh checkbox "Áp dụng bảo
  hành".
- `pos_checkout.php`: sau khi tạo đơn hàng, với mỗi dòng sản phẩm có `has_warranty = 1`, **tự động
  tạo 1 phiếu bảo hành** gắn với dòng đơn hàng đó (ngày bắt đầu = ngày bán, ngày kết thúc = ngày
  bán + số tháng theo chính sách mặc định của sản phẩm), giống đúng hành vi Sapo thật. Form tạo
  thủ công (`warranty_card_form.php`) vẫn giữ nguyên để xử lý các trường hợp ngoại lệ (đơn hàng cũ
  trước khi có tính năng này, sản phẩm quên bật `has_warranty` lúc bán...).

Đã test trên app.kt-soft.vn: tạo sản phẩm test có `has_warranty=1` gắn chính sách 6 tháng, bán qua
POS → phiếu bảo hành tự động xuất hiện đúng trong danh sách, đúng khách hàng, đúng chính sách,
ngày kết thúc lệch chính xác +6 tháng so với ngày bán. Đã xóa sạch dữ liệu test.

## Vòng rà soát tiếp module Sổ quỹ (phát hiện gap lớn: sổ quỹ tách rời hoàn toàn khỏi dòng tiền bán hàng)

Vòng trước chỉ so khớp giao diện lọc/tổng hợp của trang Sổ quỹ (đã khớp gần như 1:1 với Sapo). Vòng
này xem kỹ trang **"Phiếu thu"** riêng của Sapo (menu con của Sổ quỹ) thì phát hiện điều quan
trọng: mọi khoản khách thanh toán khi mua hàng đều **tự động sinh ra 1 "Phiếu thu"** trong sổ quỹ
(loại phiếu "Tự động", có "Chứng từ gốc" trỏ về đúng mã đơn hàng) — tức Sổ quỹ trên Sapo chính là
sổ tiền mặt/ngân hàng THẬT của toàn bộ cửa hàng, không phải một sổ ghi chép tay riêng biệt.

Kiểm tra lại thì `cashbook_entries` của QLBH2 hoàn toàn tách rời khỏi `payments`/`orders`/
`stock_receipts` — bán được 10 đơn hàng trị giá 10 triệu thì trang Sổ quỹ vẫn hiện "Tổng thu = 0"
vì chưa từng có ai tạo phiếu thu thủ công tương ứng. Đây là gap nghiêm trọng nhất phát hiện được ở
module này vì nó khiến Sổ quỹ (vốn là công cụ để chủ shop biết chính xác số tiền mặt/tiền trong
tài khoản đang có) trở nên vô dụng trong thực tế nếu không thao tác thủ công song song.

Đã bổ sung:
- Hàm dùng chung `recordCashbookEntry()` (`inc_functions.php`) để tự động ghi 1 phiếu thu/chi.
- `cashbook_entries`: thêm `order_id`, `receipt_id` (liên kết chứng từ gốc) và `auto_generated`.
- **Tự động ghi Phiếu thu** khi: bán hàng có thu tiền ngay tại `pos_checkout.php` (dù thu đủ hay
  thu một phần), thu nợ đơn hàng qua `order_pay.php`, thu nợ khách hàng trực tiếp qua
  `customer_view.php`.
- **Tự động ghi Phiếu chi** khi: trả tiền NCC ngay lúc tạo phiếu nhập (`stock_receipt_form.php`),
  trả thêm nợ NCC qua `stock_receipt_pay.php`.
- `cashbook.php`: thêm cột "Nguồn" hiển thị badge "Tự động"/"Thủ công" kèm link về đúng đơn
  hàng/phiếu nhập gốc; thêm bộ lọc theo "Người tạo" (giống Sapo).
- `cashbook_export.php`: file CSV xuất ra cũng có thêm cột nguồn/chứng từ liên quan, áp dụng đúng
  bộ lọc người tạo.

Đã test trên app.kt-soft.vn: bán 1 đơn 60.000 qua chuyển khoản tại POS → Sổ quỹ tự động xuất hiện
đúng 1 phiếu thu 60.000, đúng hình thức "Chuyển khoản", có link về đúng mã đơn hàng, "Tổng thu"
trong kỳ tăng đúng từ 0 lên 60.000 — không cần bất kỳ thao tác thủ công nào. Đã xóa sạch dữ liệu
test (đơn hàng, phiếu sổ quỹ tự động, sản phẩm test).

**Lưu ý quan trọng**: các phiếu thu/chi thủ công tạo trước đây trong QLBH2 (nếu người dùng đã dùng
để ghi nhận doanh thu bán hàng theo cách thủ công) sẽ bị trùng với phiếu tự động mới từ vòng này —
cần rà soát và xóa các phiếu thủ công trùng lặp đó nếu có, để tránh tính đúp doanh thu trong Sổ quỹ
kể từ ngày cập nhật.

## Vòng rà soát module Cấu hình (đối chiếu trang "Cấu hình" thực tế của Sapo)

Trang `settings.php` của QLBH2 (23 mục con, chia 4 nhóm) đã che phủ gần hết danh sách cấu hình thật
của Sapo: Thông tin cửa hàng, Chi nhánh, Nhân viên & phân quyền, Thuế, Chính sách giá, Thanh toán,
Quản lý kho & Sản phẩm, Cấu hình bán hàng, Nguồn/Kênh bán hàng, Lý do hủy trả, Quà đổi điểm, Hạng
thẻ, Khuyến mại, Mã giảm giá, Chính sách bảo hành, Marketing, Sổ quỹ, Báo cáo, Xuất/nhập file,
Nhật ký hoạt động. Các mục còn lại của Sapo (Gói dịch vụ Sapo, Hóa đơn điện tử, Cân điện tử) là
tính năng gắn với hạ tầng/dịch vụ bên thứ 3 của riêng Sapo, đúng như các vòng trước đã xác định là
ngoài phạm vi. Tài khoản "test" dùng để khảo sát bị giới hạn quyền ở hầu hết trang cấu hình con
(vd "Cấu hình bán hàng", "Xử lý đơn hàng" báo "Bạn không có quyền truy cập") nên không đối chiếu
sâu được các trang đó — chỉ xác nhận được sự tồn tại của chúng qua menu.

Gap cụ thể vẫn xác minh được: mục "Mẫu in" của Sapo cho tùy chỉnh mẫu in theo từng khổ giấy, trong
khi `order_print.php` của QLBH2 **hardcode khổ giấy 80mm** — nếu cửa hàng dùng máy in nhiệt mini
58mm (rất phổ biến với các máy in giá rẻ ở tiệm tạp hóa/dược nhỏ) thì hóa đơn in ra sẽ bị tràn lề
hoặc cắt mất nội dung bên phải.

Đã bổ sung:
- `display_settings.php` (card "Mẫu in hóa đơn" đã có sẵn): thêm lựa chọn khổ giấy in nhiệt
  80mm/58mm (`print_paper_width`).
- `order_print.php`: đọc cấu hình này để chỉnh `max-width` của hóa đơn HTML và thêm khai báo CSS
  `@page { size: 58mm/80mm auto; }` để trình duyệt in đúng khổ giấy khi gọi lệnh in.

Đã test trên app.kt-soft.vn: chuyển cấu hình sang 58mm → trang in hóa đơn co đúng còn 260px và khai
báo `@page` đúng 58mm; chuyển lại 80mm → về đúng 380px/80mm như cũ. Đã xóa dữ liệu test.

## Vòng rà soát module Đơn hàng (phát hiện gap lớn: quy trình xử lý đơn hàng hoàn toàn không dùng được)

`orders.status` có đủ 5 bước ENUM (`DRAFT/APPROVED/PACKED/SHIPPED/COMPLETED/CANCELLED`), `order_view.php`
vẽ đúng thanh tiến trình 5 bước, `orders.php` lọc được theo từng trạng thái, dashboard có widget đếm
"Đơn hàng cần xử lý" theo từng bước (làm ở vòng trước) — nhưng rà lại toàn bộ code thì phát hiện
**`pos_checkout.php` là nơi DUY NHẤT tạo đơn hàng, và luôn tạo thẳng ở trạng thái `COMPLETED`**, đồng
thời **không có bất kỳ file nào để chuyển trạng thái đơn hàng sang bước tiếp theo**. Kết quả: toàn bộ
quy trình Chờ duyệt → Duyệt → Đóng gói → Xuất kho → Hoàn thành chỉ tồn tại trên giao diện nhưng
không bao giờ có đơn hàng nào thực sự đi qua nó — widget dashboard luôn hiện số 0, bộ lọc trạng thái
trên `orders.php` không bao giờ lọc ra kết quả nào ngoài "Hoàn thành"/"Đã hủy". Đây là gap nghiêm
trọng vì nó khiến toàn bộ tính năng "quy trình xử lý đơn hàng" — vốn cần thiết cho đơn đặt trước/đơn
gọi điện/đơn online cần duyệt và đóng gói trước khi giao — không thể dùng được trong thực tế.

Đã bổ sung:
- `inc_functions.php`: tách hàm dùng chung `createWarrantyCardsForOrder()` (trước đây nằm thẳng
  trong `pos_checkout.php`, giờ tái dùng được ở cả bước hoàn thành thủ công).
- `pos.php`: thêm nút "Đặt hàng — xử lý sau" bên cạnh nút "Thanh toán" — tạo đơn nhưng không thu
  tiền, không xuất kho ngay tinh thần bán hàng thật (vẫn trừ tồn kho ngay lúc đặt để tránh bán trùng
  hàng, giống cách "Hoàn thành" đang trừ kho — chỉ khác ở việc chưa thu tiền/chưa tạo phiếu bảo
  hành/chưa cộng điểm, các việc này chỉ làm khi đơn thực sự hoàn thành).
- `pos_checkout.php`: nhận thêm cờ `draft` — nếu bật thì tạo đơn ở trạng thái `DRAFT`, `payment_status
  = UNPAID`, bỏ qua tạo phiếu bảo hành/cộng điểm/ghi sổ quỹ (dời đến lúc đơn được chuyển sang Hoàn
  thành).
- `order_advance.php` (mới): nút "Chuyển sang: <bước tiếp theo>" cho ADMIN/MANAGER, dịch đơn hàng
  sang đúng bước kế tiếp trong pipeline, ghi `order_status_history`; khi bước tới đích là `COMPLETED`
  mới chạy phần tạo phiếu bảo hành + cộng điểm tích lũy đã bị hoãn lại từ lúc đặt.
- `order_view.php`: hiển thị nút chuyển bước này ngay cạnh các nút Sửa/Hủy đơn hiện có.

Đã test trên app.kt-soft.vn: tạo đơn qua nút "Đặt hàng — xử lý sau" → đơn ở đúng trạng thái "Đặt
hàng" (DRAFT), có nút "Chuyển sang: Duyệt"; bấm liên tiếp qua đủ 4 bước → đơn về đúng "Hoàn thành",
đúng lúc đó phiếu bảo hành mới xuất hiện (sản phẩm có bật bảo hành) — xác nhận việc hoãn tạo phiếu
bảo hành đến khi hoàn thành hoạt động đúng. Đã xóa sạch dữ liệu test (đơn hàng, phiếu bảo hành,
khách hàng, sản phẩm test).

## Vòng rà soát tiếp module Marketing

Tài khoản "test" trên Sapo bị giới hạn quyền ở hầu hết trang Marketing con ("Danh sách chiến dịch"
báo "Bạn không có quyền truy cập") nên không đối chiếu trực tiếp được giao diện thật ở vòng này —
chuyển sang rà soát kỹ nội bộ `promotions.php`/`coupons.php`/`campaigns.php` của QLBH2 thay vì đoán
mò theo Sapo.

Phát hiện: cả `promotions.php` (Khuyến mại tự động) và `coupons.php` (Mã giảm giá) đều có cột
`is_active` trong schema và **hiển thị badge "Đã tắt"** khi tắt — nhưng **không có bất kỳ nút nào
để tắt/bật** chương trình khuyến mại hay mã giảm giá sau khi tạo. Một khi tạo xong, chương trình cứ
tự động áp dụng liên tục cho đến khi hết `end_date` (hoặc vĩnh viễn nếu không đặt ngày kết thúc) —
không có cách nào dừng ngay một mã giảm giá bị lộ/lạm dụng, hoặc tạm dừng một chương trình cấu hình
sai mà không xóa hẳn dữ liệu lịch sử đã áp dụng.

Đã bổ sung:
- `promotion_toggle.php`, `coupon_toggle.php` (mới): đảo trạng thái `is_active` cho 1 chương
  trình/mã giảm giá cụ thể.
- `promotions.php`: thêm nút "Tắt"/"Bật lại" theo từng dòng; tách rõ 3 trạng thái hiển thị: "Đã
  tắt" (is_active=0), "Ngoài thời gian" (is_active=1 nhưng ngoài khoảng ngày), "Đang áp dụng".
- `coupons.php`: thêm nút "Tắt"/"Bật lại" theo từng dòng, giữ nguyên 3 trạng thái hiển thị đã có
  (Đã tắt/Hết hạn/Đang hiệu lực).
- Đã xác nhận `coupon_check.php` và logic áp dụng khuyến mại tự động trong `pos_checkout.php` đã
  lọc đúng theo `is_active = 1` từ trước, nên bật/tắt có tác dụng ngay lập tức tại POS mà không cần
  sửa thêm gì ở phía kiểm tra.

Đã test trên app.kt-soft.vn: tạo 1 chương trình khuyến mại test → "Đang áp dụng"; bấm "Tắt" → chuyển
đúng thành "Đã tắt"; bấm "Bật lại" → về đúng "Đang áp dụng". Tương tự với mã giảm giá test → bấm
"Tắt" → chuyển đúng thành "Đã tắt". Đã xóa sạch dữ liệu test.

## Vòng rà soát module Khách hàng thân thiết (phát hiện: chiết khấu hạng thẻ/chiết khấu riêng chưa từng được áp dụng)

Đọc lại chính ghi chú cảnh báo có sẵn trên trang `customer_tiers.php`: "Đây là danh mục tham chiếu,
**chưa tự động áp dụng chiết khấu theo hạng vào đơn hàng**" — kiểm tra lại toàn bộ `pos_checkout.php`
xác nhận đúng là như vậy: cả `customer_tiers.discount_percent` (chiết khấu theo hạng thẻ) lẫn
`customers.discount_percent` (chiết khấu riêng khai báo ở trang khách hàng) **chưa bao giờ được
dùng để giảm giá đơn hàng** — chỉ có coupon và khuyến mại tự động (promotions) là thực sự trừ tiền.
Đây là gap cốt lõi của tính năng "khách hàng thân thiết": mục đích chính của việc phân hạng khách
hàng là tự động thưởng chiết khấu cho khách mua nhiều, nhưng tính năng này hoàn toàn không hoạt
động trong thực tế dù giao diện hiển thị đầy đủ hạng/chiết khấu.

Đã bổ sung:
- `pos_checkout.php`: khi có khách hàng, tính chiết khấu thân thiết = **mức cao hơn** giữa chiết
  khấu riêng của khách và chiết khấu theo hạng thẻ hiện tại (theo tổng chi tiêu lũy kế các đơn chưa
  hủy) — không cộng dồn cả 2 để tránh giảm giá chồng chéo vô lý; khoản này cộng dồn tiếp với chiết
  khấu tay/coupon/khuyến mại tự động như các loại chiết khấu khác đã có.
- `customer_lookup.php`: trả thêm `loyalty_discount_percent` để giao diện POS biết trước mức chiết
  khấu sẽ áp dụng ngay khi tra cứu khách hàng theo SĐT (tính đúng cùng công thức phía server).
- `pos.php`: hiển thị "Chiết khấu thân thiết: X%" ngay trong dòng thông tin khách hàng, và cộng
  khoản này vào tổng tiền xem trước tại POS (trước đây chỉ khuyến mại tự động là tính "ngầm" phía
  server mà giao diện không hiển thị trước — nay chiết khấu thân thiết được hiển thị minh bạch
  ngay khi chọn khách, tránh chênh lệch giữa số hiển thị và số thực thu).
- `customer_tiers.php`: cập nhật lại đúng nội dung ghi chú, không còn cảnh báo "chưa tự động áp
  dụng" nữa.

Đã test trên app.kt-soft.vn: tạo hạng thẻ test "TEST Vàng" (chi tiêu tối thiểu 100.000, chiết khấu
10%), tạo khách hàng có sẵn 1 đơn 200.000 (đủ điều kiện lên hạng) → tra cứu SĐT trả về đúng
`loyalty_discount_percent: 10`; bán tiếp 1 đơn 100.000 cho khách này → hóa đơn tự động giảm đúng
10.000 (10%), khách phải trả đúng 90.000. Đã xóa sạch dữ liệu test (hạng thẻ, khách hàng, đơn hàng,
sản phẩm test).

## Vòng rà soát module Quà tặng đổi điểm

Rà soát nội bộ toàn bộ luồng đổi quà (`gifts.php` khai báo danh mục, `pos_gifts.php` liệt kê quà đủ
điểm, `redeem_gift.php` xử lý đổi quà) — phần xử lý giao dịch bản thân nó đã đúng và an toàn: dùng
`FOR UPDATE` khóa dòng khách hàng/quà tặng tránh trừ điểm/trừ tồn kho quà trùng khi 2 người thu
ngân bấm đổi quà cùng lúc, kiểm tra đủ điểm/còn hàng trước khi trừ, ghi lại `gift_redemptions` đầy
đủ. Gap phát hiện được: bảng `gift_redemptions` đã lưu đủ dữ liệu (ai đổi, quà gì, bao nhiêu điểm,
lúc nào, chi nhánh nào) nhưng **không có bất kỳ trang nào hiển thị lại lịch sử này** — `gifts.php`
trước đó chỉ hiện 1 con số tổng "Đã đổi" theo từng quà, không xem được đã đổi cho ai, khi nào; trang
`customer_view.php` cũng không có mục nào cho lịch sử đổi quà của riêng khách đó. Nếu có tranh chấp
("khách này đã nhận quà chưa?", "khách X đã đổi những quà gì?") thì không có cách nào tra cứu lại.

Đã bổ sung:
- `gifts.php`: thêm bảng "Lịch sử đổi quà" (100 lượt gần nhất) — thời gian, khách hàng (link tới
  trang chi tiết), quà, điểm đã dùng, chi nhánh, người thực hiện.
- `customer_view.php`: thêm mục "Lịch sử đổi quà" riêng cho khách đang xem, đặt cạnh "Lịch sử công
  nợ" đã có từ vòng trước — theo đúng mẫu đã dùng cho các loại lịch sử khác trong trang này.

Đã test trên app.kt-soft.vn: tạo quà test 50 điểm, khách hàng test có 100 điểm → đổi quà qua
`redeem_gift.php` → còn đúng 50 điểm; lịch sử đổi quà hiện đúng cả ở `gifts.php` (kèm tên khách,
chi nhánh, người thực hiện) và ở trang chi tiết khách hàng đó. Đã xóa sạch dữ liệu test.

## Vòng rà soát module Nhóm khách hàng

Truy cập trực tiếp được trang "Danh sách nhóm khách hàng" thật của Sapo (không bị chặn quyền như
nhiều trang cấu hình khác). Cột hiển thị: Mã nhóm, Loại nhóm, Mô tả, Số lượng khách hàng, Ngày tạo.
Sapo còn hỗ trợ 2 loại nhóm — "**Nhóm cố định**" (gán tay từng khách, giống QLBH2 đang có) và
"**Nhóm tự động**" (tự tập hợp khách theo điều kiện chung, dùng cho marketing/chăm sóc) — nhưng
trang tạo "Nhóm tự động" bị hạn chế quyền với tài khoản test nên không xem được chi tiết bộ điều
kiện thật sự dùng gì (chi tiêu, thẻ, khu vực...) — không đủ cơ sở để làm đúng, nên **chưa build
phần này** để tránh đoán mò sai lệch so với thực tế.

Gap xác minh chắc chắn được (nhìn trực tiếp từ danh sách thật): `customer_groups` của QLBH2 thiếu
2 cột Mã nhóm và Mô tả mà Sapo có sẵn — nhóm khách hàng trong QLBH2 chỉ có tên, không có mã riêng để
tham chiếu nhanh (vd trong báo cáo, xuất file) và không có chỗ ghi chú ý nghĩa/tiêu chí của nhóm.

Đã bổ sung:
- `customer_groups`: thêm cột `code` (mã nhóm, unique) và `description` (mô tả).
- `groups.php`: form tạo nhóm thêm ô nhập mã (bỏ trống thì tự sinh mã dạng `NHM########`) và ô mô
  tả; danh sách nhóm hiển thị thêm mã và mô tả (nếu có) cho từng nhóm.

Đã test trên app.kt-soft.vn: tạo nhóm không nhập mã → tự sinh đúng mã `NHM########`, mô tả hiển thị
đúng; tạo nhóm khác với mã tự nhập `TESTBB` → lưu đúng mã đã nhập. Đã xóa sạch dữ liệu test.

**Giới hạn còn lại**: tính năng "Nhóm khách hàng tự động" (dynamic segment) của Sapo — cần xem được
giao diện cấu hình điều kiện thật để làm đúng, hiện bị chặn quyền trên tài khoản khảo sát nên để
lại cho vòng sau nếu có quyền truy cập hoặc mô tả cụ thể hơn từ người dùng.

## Vòng rà soát module Chi nhánh

Trang "Quản lý chi nhánh" của Sapo bị chặn quyền với tài khoản khảo sát (giống nhiều trang cấu hình
admin khác) nên chuyển sang rà soát nội bộ `branches.php` của QLBH2.

Phát hiện: bảng `branches` **đã có sẵn cột `is_active`** trong schema nhưng **chưa từng được dùng ở
bất kỳ đâu** — `branches.php` chỉ có chức năng thêm mới, không có sửa và không có cách tắt/mở một
chi nhánh; đồng thời `is_active` không được lọc ở bất kỳ danh sách chọn chi nhánh nào trong toàn bộ
ứng dụng (chuyển đổi chi nhánh tại POS, tạo phiếu kiểm kho, tạo phiếu chuyển hàng, gán chi nhánh cho
nhân viên). Hệ quả: một khi cửa hàng đóng cửa 1 chi nhánh, chi nhánh đó vẫn hiện vĩnh viễn trong mọi
danh sách chọn, và không có cách nào sửa lại tên/SĐT/địa chỉ nếu nhập sai — phải xóa hẳn (nguy hiểm,
vỡ toàn bộ dữ liệu lịch sử liên kết) hoặc chấp nhận sai sót mãi mãi.

Đã bổ sung:
- `branches.php`: thêm chức năng **sửa** tên/SĐT/địa chỉ ngay trên từng dòng, và nút **"Ngừng hoạt
  động"/"Bật lại"** dùng cột `is_active` có sẵn.
- Lọc `WHERE is_active = 1` ở các danh sách chọn chi nhánh mang tính "thao tác mới" (nơi chọn chi
  nhánh để làm việc, không phải nơi xem lại lịch sử): `pos.php` (chuyển đổi chi nhánh), `pos_switch_
  branch.php` (chặn luôn ở phía server, không chỉ ẩn ở giao diện), `stock_take_form.php` (tạo phiếu
  kiểm kho mới), `stock_transfer_form.php` (tạo phiếu chuyển hàng mới), `users.php` (gán chi nhánh
  cho nhân viên). Các trang xem/lọc lịch sử theo chi nhánh (báo cáo, sổ quỹ, tồn kho, sản phẩm) cố
  tình **không lọc** để không mất khả năng xem lại dữ liệu cũ của chi nhánh đã ngừng hoạt động.

Đã test trên app.kt-soft.vn: tạo chi nhánh test → sửa lại tên/SĐT/địa chỉ → lưu đúng; bấm "Ngừng
hoạt động" → hiện đúng badge, biến mất khỏi bộ chọn chi nhánh tại POS; thử chuyển vào chi nhánh đó
qua `pos_switch_branch.php` trực tiếp (bỏ qua giao diện) → bị từ chối đúng, vẫn ở chi nhánh cũ; bật
lại → hoạt động bình thường. Đã xóa sạch dữ liệu test.

## Vòng rà soát module Nhân viên và phân quyền (phát hiện lỗ hổng bảo mật)

Trang quản lý nhân viên thật của Sapo hóa ra nằm ở hệ thống tài khoản trung tâm riêng
(`merchants.sapo.vn`, dùng chung cho mọi cửa hàng của 1 tài khoản Sapo) chứ không nằm trong admin
của từng cửa hàng — tài khoản "test" không có quyền vào đó nên không đối chiếu trực tiếp được. Hệ
thống phân quyền tập trung đa cửa hàng này vượt quá phạm vi của một phần mềm bán hàng độc lập như
QLBH2 nên không cố tái tạo. Chuyển sang rà soát bảo mật nội bộ của `inc_auth.php`/`users.php`.

`users.php` của QLBH2 vốn đã khá đầy đủ: tạo tài khoản, đổi vai trò, gán chi nhánh, khóa/mở khóa,
đặt lại mật khẩu, tự bảo vệ (không cho tự hạ quyền/khóa chính mình). Nhưng rà kỹ `inc_auth.php` thì
phát hiện **lỗ hổng bảo mật thực sự**: `$_SESSION['user']` chỉ được nạp 1 lần lúc đăng nhập và
không bao giờ được đối chiếu lại với DB sau đó — nghĩa là nếu ADMIN **khóa tài khoản** hoặc **hạ
quyền** một nhân viên trong khi trình duyệt của nhân viên đó vẫn đang đăng nhập (tab vẫn mở), nhân
viên đó **tiếp tục thao tác với quyền cũ vô thời hạn** cho đến khi tự đăng xuất — kể cả với nhân
viên đã bị cho nghỉ việc hoặc phát hiện lạm quyền.

Đã bổ sung:
- `inc_auth.php`: thêm `refreshUserSession()` — mỗi request gọi `requireLogin()`/`requireRole()`
  (tức mọi trang có yêu cầu đăng nhập) sẽ đối chiếu lại `role`/`branch_id`/`is_active` mới nhất từ
  DB (1 câu SELECT theo khóa chính, chạy đúng 1 lần/request nhờ cờ tĩnh — chi phí không đáng kể).
  Nếu tài khoản đã bị khóa hoặc không còn tồn tại, hủy session ngay và chuyển về trang đăng nhập;
  nếu vai trò/chi nhánh đã đổi, đồng bộ lại session để có hiệu lực ngay từ request tiếp theo, không
  cần đợi đăng xuất/đăng nhập lại.
- `login.php`: hiển thị thông báo rõ ràng khi bị đá ra do tài khoản vừa bị khóa/đổi quyền.

Đã test trên app.kt-soft.vn: tạo tài khoản ADMIN test, đăng nhập ở 1 phiên trình duyệt riêng (cookie
jar khác) → xác nhận vào được `users.php` (trang chỉ ADMIN mới vào được); từ phiên admin thật, bấm
"Khóa" tài khoản test đó; gọi lại `users.php` bằng đúng phiên cũ (không đăng nhập lại) → bị chuyển
hướng ngay lập tức về `login.php?locked=1` kèm thông báo, xác nhận lỗ hổng đã được vá. Đã xóa sạch
tài khoản test.

## Vòng rà soát module Tồn kho

Trang danh sách sản phẩm thật của Sapo (cột Ảnh/Sản phẩm/Loại/Nhãn hiệu/Có thể bán/Tồn kho) và
widget "THÔNG TIN KHO" trên dashboard (Sản phẩm dưới định mức/Số tồn kho/Giá trị tồn kho) — đối
chiếu thì QLBH2 đã có tương đương ở `index.php` (tổng tồn kho, danh sách sản phẩm dưới định mức) và
`reports.php` (giá trị tồn kho, bảng chi tiết theo sản phẩm, đã làm ở vòng Báo cáo). Gap cụ thể còn
lại nằm ở chính trang `inventory.php` ("Quản lý kho") — trang duyệt tồn kho theo từng dòng chi
nhánh/sản phẩm: **không có ô tìm kiếm theo tên/SKU, không lọc được riêng sản phẩm dưới định mức,
không hiển thị giá trị tồn** — với danh sách giới hạn 200 dòng gần cập nhật nhất, một cửa hàng có
nhiều sản phẩm gần như không thể tìm ra đúng dòng cần xem hoặc lọc được danh sách hàng cần nhập
thêm.

Đã bổ sung vào `inventory.php`:
- Ô tìm kiếm theo tên sản phẩm/biến thể hoặc mã SKU.
- Checkbox "Chỉ hiện dưới định mức" — lọc nhanh đúng những dòng cần nhập thêm hàng.
- Thêm cột SKU và cột "Giá trị tồn" (số lượng × giá vốn) cho từng dòng.

Đã test trên app.kt-soft.vn: tạo sản phẩm test tồn 2/định mức 10, giá vốn 5.000 → tìm theo SKU ra
đúng kết quả, cột giá trị tồn hiển thị đúng 10.000; bật "Chỉ hiện dưới định mức" → sản phẩm test
xuất hiện đúng trong danh sách lọc. Đã xóa sạch dữ liệu test.

## Vòng rà soát mục "Kế toán và Thuế" — xác nhận hoàn toàn ngoài phạm vi, không có gì để bổ sung

Vào thẳng mục "KẾ TOÁN VÀ THUẾ" trên sidebar thật của Sapo để xem chính xác nội dung bên trong (mục
này trước đó chỉ được suy đoán là "hóa đơn điện tử"). Xác nhận: toàn bộ mục này chỉ là 3 ứng dụng
trả phí của riêng Sapo — **Sapo Invoice** (phát hành hóa đơn điện tử, lập sổ kế toán/tờ khai HKD
theo Thông tư 152/2025/TT-BTC), **Sapo Tax** (kê khai thuế TNCN theo thuế suất × doanh thu), **Sapo
Accounting** (đồng bộ chứng từ với phần mềm kế toán Sapo Accounting) — không có bất kỳ trang cấu
hình/dữ liệu nào khác. Đây đúng là loại tính năng đòi hỏi kết nối API thật với nhà cung cấp hóa đơn
điện tử được Tổng cục Thuế công nhận, khớp chính xác với nội dung `accounting.php` của QLBH2 đã ghi
từ trước ("chưa tích hợp API thật... cần đăng ký tài khoản với 1 nhà cung cấp hóa đơn điện tử").

**Kết luận: không có gì khả thi để xây dựng thêm ở mục này** — không phải do thiếu thời gian rà
soát mà do bản chất tính năng phụ thuộc hoàn toàn vào dịch vụ trả phí của bên thứ 3 (Tổng cục Thuế/
nhà cung cấp hóa đơn điện tử), nằm ngoài quyết định phạm vi ban đầu của dự án QLBH2. Không có thay
đổi code nào trong vòng này.

## Vòng rà soát module Đặt hàng Online — xây mới, có xác nhận phạm vi với người dùng trước khi làm

Xem trực tiếp mục "Đặt hàng Online" thật của Sapo (`/admin/apps/app-landing-order`, nằm trong nhóm
"KÊNH BÁN HÀNG"): đây là 1 ứng dụng riêng cho 1 **trang đặt hàng công khai không cần đăng nhập** để
chia sẻ cho khách qua Facebook/Zalo — khách tự xem sản phẩm, chọn số lượng, điền thông tin và đặt
hàng, đơn tự động đổ về hệ thống chờ xác nhận. Khác hẳn quy mô các vòng trước: kiểm tra code QLBH2
xác nhận **100% các trang đều bắt buộc đăng nhập** (`inc_header.php` luôn gọi `requireLogin()`) —
tức chưa từng có bất kỳ trang công khai nào. Đây là xây MỚI một khả năng (không phải sửa gap nhỏ),
nên đã hỏi ý kiến người dùng trước khi làm thay vì tự ý quyết định phạm vi — người dùng chọn
phương án xây bản đơn giản.

Đã xây dựng:
- `shop.php` (mới, **không yêu cầu đăng nhập**): trang công khai liệt kê sản phẩm loại `PRODUCT`
  đang bán và còn hàng tại chi nhánh đang hoạt động đầu tiên, kèm ảnh/giá/tồn kho; khách nhập số
  lượng từng sản phẩm muốn mua + họ tên/SĐT/địa chỉ nhận hàng/ghi chú rồi gửi.
- `shop_order.php` (mới, **không yêu cầu đăng nhập**): xử lý đơn — validate lại giá và tồn kho phía
  server (không tin số liệu từ trình duyệt), tự tạo/tìm khách hàng theo SĐT, tạo đơn hàng ở trạng
  thái **DRAFT** (`source = 'ONLINE'`, gắn kênh bán hàng loại `WEBSITE` nếu có khai báo), trừ tồn
  kho ngay lúc đặt (nhất quán với cách POS đang làm), dùng khóa `FOR UPDATE` tránh bán trùng khi
  nhiều khách đặt cùng lúc. Đơn tạo ra tái sử dụng đúng pipeline Đặt hàng/Duyệt/Đóng gói/Xuất
  kho/Hoàn thành đã xây ở vòng "Đơn hàng" trước đó — không cần thêm code xử lý trạng thái mới.
- `online_shop_settings.php` (mới): trang cấu hình cho ADMIN/MANAGER — hiển thị đường link công
  khai để copy chia sẻ, và công tắc bật/tắt nhận đơn online tạm thời (không cần gỡ link đã chia sẻ
  khi hết hàng/nghỉ bán).
- `settings.php`: thêm mục "🛒 Đặt hàng Online" vào nhóm "Thiết lập bán hàng".

Đã test trên app.kt-soft.vn: mở `shop.php` **không đăng nhập** → thấy đúng sản phẩm test đang bán;
đặt 3 sản phẩm với thông tin khách mới → nhận đúng mã đơn, tồn kho giảm đúng từ 15 xuống 12; đơn
xuất hiện đúng trong "Đơn hàng → Đặt hàng (DRAFT)" với đúng tên khách/địa chỉ; chuyển đơn qua đủ 4
bước bằng `order_advance.php` đã có sẵn → về đúng "Hoàn thành" — xác nhận đơn từ kênh online tái sử
dụng đúng toàn bộ hạ tầng pipeline/bảo hành/điểm tích lũy đã xây trước đó mà không cần sửa gì thêm.
Đã xóa sạch dữ liệu test.

**Giới hạn của bản đơn giản này** (đã nói rõ khi hỏi phạm vi): chỉ hỗ trợ sản phẩm loại thường
(không biến thể, không combo) để giữ đơn giản; không có xác thực OTP/CAPTCHA chống spam đơn ảo;
không tuỳ chỉnh giao diện/thương hiệu trang; không đồng bộ nhiều chi nhánh (luôn dùng chi nhánh
đang hoạt động đầu tiên). Có thể mở rộng thêm nếu người dùng cần.

## Vòng rà soát module Cân điện tử — xác nhận ngoài phạm vi, nhưng phát hiện + xây dựng gap liên quan quy mô lớn (bán hàng theo số lượng lẻ)

Xem trực tiếp mục "Cân điện tử" thật của Sapo trong Cấu hình: link còn trỏ về trang chủ (`href="/"`),
tức chưa từng được kích hoạt/cấu hình được cho tài khoản khảo sát — xác nhận đây đúng là tính năng
kết nối phần cứng thật (cân điện tử in mã vạch qua cổng USB/Serial/LAN của hãng cân cụ thể), **100%
ngoài phạm vi phần mềm**, giống hệt "Hóa đơn điện tử".

Nhưng mục đích cốt lõi của cân điện tử — bán hàng theo **cân nặng** (thịt/rau/hàng cân lẻ) — có 1
phần hoàn toàn làm được mà không cần phần cứng: cho phép nhập **số lượng lẻ** (vd 0.35kg) thay vì
chỉ số nguyên. Rà lại toàn bộ schema thì phát hiện **mọi cột `quantity` trong 10 bảng đều là kiểu
`INT`** (tồn kho, đơn hàng, phiếu nhập/chuyển/kiểm/trả hàng, combo...) và giao diện POS ép
`parseInt` — nên dù không có máy cân thật, việc bán 0.35kg thịt vẫn không thể nhập được. Đã hỏi ý
kiến người dùng trước vì đây là thay đổi quy mô lớn (đụng ~30 file), không phải 1 gap nhỏ — người
dùng chọn làm.

Đã bổ sung:
- Đổi 10 cột `quantity`/`counted_qty`/`system_qty` từ `INT` sang `DECIMAL(12,3)` (đủ chính xác tới
  gram) ở các bảng: `inventory`, `order_items`, `order_return_items`, `stock_receipt_items`,
  `stock_take_items`, `stock_transfer_items`, `supplier_return_items`, `purchase_order_items`,
  `combo_items`, `product_batches`. Ngưỡng tồn tối thiểu/tối đa (`min_stock`/`max_stock`) giữ
  nguyên `INT` vì so sánh với `DECIMAL` vẫn đúng bình thường, không cần đổi.
- `inc_functions.php`: thêm `postQty()` (đọc số lượng cho phép số lẻ, làm tròn 3 chữ số thập phân)
  và `fmtQty()` (hiển thị đẹp — bỏ số 0 thừa: `1` thay vì `1.000`, `0.5` thay vì `0.500`).
- Rà soát và sửa toàn bộ ~28 file liên quan: mọi chỗ ép kiểu `(int)` trên số lượng đổi thành
  `(float)`/`postQty()`/`fmtQty()`; mọi ô nhập số lượng (`<input type="number">`) thêm
  `step="0.001"` và cho phép giá trị nhỏ hơn 1; JS `parseInt` trên số lượng đổi thành `parseFloat`.
  Bao gồm: POS bán hàng (giỏ hàng, tìm kiếm, chọn lô FEFO, combo), đơn hàng (xem/in/hủy/đổi trả),
  đặt hàng nhập, phiếu nhập kho, kiểm kho, chuyển kho, trả hàng NCC, lô hàng/HSD, sản phẩm (tồn
  kho theo chi nhánh, combo, biến thể), trang Đặt hàng Online mới xây ở vòng trước, báo cáo, dashboard.
- In hóa đơn (`order_print.php`): chế độ "tách dòng" (in mỗi đơn vị 1 dòng) tự động chuyển về in
  gộp 1 dòng khi số lượng có phần lẻ, vì tách dòng cho 0.35kg vốn không có ý nghĩa.

Đã test trên app.kt-soft.vn: tạo sản phẩm "Thịt heo (bán theo kg)" tồn 10.5kg → bán 0.35kg tại
POS → đơn hiển thị đúng "0.35", tổng tiền đúng 52.500, tồn kho giảm đúng còn 10.15, hóa đơn in ra
đúng "x 0.35"; bán tiếp 2 (số nguyên) → hiển thị gọn "2" chứ không phải "2.000" (xác nhận không hồi
quy với hàng bán theo cái); nhập kho thêm 5.25kg → tồn cộng đúng thành 13.4, phiếu nhập hiển thị
đúng "5.25". Đã xóa sạch dữ liệu test.

**Lưu ý cho người dùng**: đây là thay đổi kiểu dữ liệu ở tầng DB — dữ liệu cũ (toàn số nguyên) không
bị ảnh hưởng, tự động hoạt động bình thường như trước; ảnh hưởng chỉ áp dụng cho các giao dịch mới
nhập số lượng lẻ từ bây giờ trở đi.

## Vòng rà soát tiếp module Xử lý đơn hàng

Trang "Xử lý đơn hàng" thật của Sapo (`/admin/settings/order_process_statuses`) tiếp tục bị chặn
quyền với tài khoản khảo sát (thử lại vẫn báo "Bạn không có quyền truy cập") nên không đối chiếu
trực tiếp được — chuyển sang rà soát nội bộ pipeline Đặt hàng/Duyệt/Đóng gói/Xuất kho/Hoàn thành đã
xây ở vòng "Đơn hàng" trước đó, tìm gap vận hành thực tế thay vì đoán giao diện Sapo.

Phát hiện: `order_advance.php` chỉ cho phép **đi tới** (DRAFT → APPROVED → ... → COMPLETED), không
có cách nào lùi lại nếu nhân viên bấm nhầm — ví dụ bấm "Chuyển sang: Hoàn thành" trước khi khách
thực sự nhận hàng/thanh toán xong. Cách duy nhất để sửa là hủy hẳn đơn hàng (`order_cancel.php`),
mất luôn đơn thay vì chỉ lùi lại đúng 1 bước. Đây là gap vận hành thực tế phổ biến (thao tác nhầm
trên giao diện là chuyện thường gặp), không phải suy đoán theo Sapo.

Đã bổ sung:
- `order_revert.php` (mới): cho phép ADMIN/MANAGER lùi đơn hàng về đúng bước liền trước trong
  pipeline, ghi lại `order_status_history` với ghi chú "Lùi bước xử lý (điều chỉnh nhầm)". Nếu lùi
  từ "Hoàn thành", tự động **hoàn tác các tác dụng phụ** đã áp dụng lúc hoàn thành: xóa phiếu bảo
  hành đã tự tạo cho đơn này, trừ lại đúng số điểm tích lũy đã cộng cho khách — tránh để lại dữ liệu
  mồ côi (phiếu bảo hành/điểm cho 1 đơn chưa thực sự hoàn thành).
- `order_view.php`: thêm nút "← Lùi về: <bước trước>" cạnh nút "Chuyển sang: <bước sau>" đã có,
  cảnh báo rõ trong hộp thoại xác nhận nếu lùi từ Hoàn thành sẽ hủy phiếu bảo hành/điểm đã cộng.

Đã test trên app.kt-soft.vn: tạo đơn có sản phẩm bảo hành, gán cho khách hàng test, bấm "Chuyển
sang" liên tiếp tới "Hoàn thành" → xác nhận phiếu bảo hành được tạo và khách được cộng đúng 9 điểm;
bấm "Lùi về: Xuất kho" → đơn về đúng trạng thái trước đó, phiếu bảo hành bị xóa đúng, điểm khách về
đúng 0, lịch sử đơn ghi đúng dòng "Lùi bước xử lý". Đã xóa sạch dữ liệu test.

## Vòng rà soát module Nguồn/Kênh bán hàng

Trang "Kênh bán hàng" thật của Sapo truy cập được (không bị chặn quyền như nhiều trang khác) — hóa
ra đây là danh sách **ứng dụng kết nối** (GrabMart, Sàn TMĐT Shopee/Lazada/Tiki/Sendo, Social
Facebook/Instagram, Sapo Web, Sapo POS, và **Kênh Đặt hàng Online** đã "Truy cập kênh"/"Tắt kênh"
được) — xác nhận đúng cách tiếp cận đã chọn ở vòng trước khi xây `shop.php` như 1 "kênh Đặt hàng
Online" độc lập là hợp lý. Trang "Nguồn bán hàng" (`/admin/settings/order_sources`) tiếp tục bị
chặn quyền nên chuyển sang rà soát nội bộ.

`channels.php` và `order_sources.php` của QLBH2 đã đầy đủ CRUD + toggle. Nhưng phát hiện gap thực
chất: cả 2 bảng `sales_channels`/`order_sources` **chỉ được gán vào đơn hàng qua `order_edit.php`**
— tức nhân viên phải bán hàng xong rồi vào sửa đơn riêng mới gắn được "khách đặt qua Zalo"/"Gọi
điện thoại". `pos_checkout.php` — nơi tạo ra tuyệt đại đa số đơn hàng — **chưa bao giờ set
`source_id`**, nên tính năng "Nguồn bán hàng" trong thực tế gần như không có dữ liệu nào (không ai
nhớ vào sửa lại từng đơn sau khi bán). Ngoài ra `order_view.php` hiển thị nhầm "Nguồn:" bằng cột
ENUM nội bộ `source` (POS/ONLINE) thay vì tên nguồn bán hàng thật (`order_sources.name`) — nên dù
có gán nguồn qua `order_edit.php` thì trang xem đơn cũng không hiện đúng.

Đã bổ sung:
- `pos.php`: thêm ô chọn "Nguồn đơn hàng" (chỉ hiện khi đã khai báo ít nhất 1 nguồn đang dùng) ngay
  trong màn hình bán hàng, lưu theo từng tab đơn giống các trường khác.
- `pos_checkout.php`: nhận `source_id`, xác thực tồn tại và đang active, lưu đúng vào đơn hàng mới.
- `order_view.php`: sửa lại đúng — "Nguồn:" giờ hiện tên nguồn bán hàng thật (join `order_sources`)
  thay vì giá trị ENUM nội bộ.
- `orders.php`: thêm bộ lọc theo nguồn bán hàng vào danh sách đơn, giống bộ lọc kênh bán đã có.

Đã test trên app.kt-soft.vn: tạo nguồn test "Nhắn tin Zalo" → hiện đúng trong ô chọn tại POS; bán 1
đơn chọn nguồn này → trang chi tiết đơn hiện đúng "Nguồn: Nhắn tin Zalo"; lọc danh sách đơn theo
nguồn này → ra đúng đơn vừa tạo. Đã xóa sạch dữ liệu test.

## Vòng rà soát module Lý do hủy trả

`cancel_reasons.php` (CRUD + toggle, phân loại CANCEL/RETURN/BOTH) và cách dùng trong
`order_return_form.php` (trả hàng khách) đã đúng và đầy đủ. Nhưng rà lại các nơi khác dùng chung
danh mục này thì phát hiện 2 gap nhất quán:

1. **`supplier_return_form.php` (trả hàng NCC) hoàn toàn không dùng danh mục lý do dùng chung** —
   chỉ có 1 ô nhập tự do, trong khi đây cũng là 1 dạng "trả hàng" giống hệt `order_return_form.php`
   về bản chất. Kết quả: lý do trả hàng cho NCC không thống nhất giữa các nhân viên — đúng vấn đề
   mà tính năng "Lý do hủy trả" sinh ra để giải quyết, nhưng lại bỏ sót đúng 1 trong 2 luồng trả
   hàng chính của phần mềm.
2. **Form hủy đơn hàng trên `order_view.php` không có lựa chọn "Khác" để nhập lý do tự do** — nếu lý
   do hủy không nằm trong danh sách đã khai báo, nhân viên không có cách nào ghi lại lý do thật; và
   nếu cửa hàng chưa khai báo lý do nào cả thì ô nhập lý do biến mất hoàn toàn, đơn hủy luôn không
   có lý do. Trong khi đó `order_return_form.php`/`supplier_return_form.php` đều đã có sẵn lựa chọn
   "Khác (nhập bên dưới)" — chỉ riêng luồng hủy đơn là thiếu.

Đã bổ sung:
- `supplier_return_form.php`: thêm dropdown chọn lý do dùng chung (lọc `applies_to IN
  ('RETURN','BOTH')`, giống hệt mẫu đã dùng ở `order_return_form.php`) kèm lựa chọn "Khác (nhập bên
  dưới)".
- `order_view.php`/`order_cancel.php`: thêm lựa chọn "Khác (nhập bên cạnh)" cho form hủy đơn khi đã
  có danh sách lý do; khi cửa hàng chưa khai báo lý do nào thì hiện ô nhập tự do thay vì không có gì.

Đã test trên app.kt-soft.vn: tạo lý do test "Hàng lỗi NCC" (áp dụng RETURN) → hiện đúng trong
dropdown trả hàng NCC, tạo trả hàng chọn lý do này → lưu đúng vào `supplier_returns.reason`; hủy 1
đơn hàng chọn "Khác" kèm lý do tự nhập → lưu và hiển thị đúng lý do tự nhập trong lịch sử đơn. Đã
xóa sạch dữ liệu test.

## Bổ sung logo cửa hàng

Theo yêu cầu người dùng — thêm khả năng tải lên và hiển thị logo riêng của cửa hàng, trước đó QLBH2
hoàn toàn không có chỗ nào để cấu hình việc này.

Đã bổ sung:
- `store_settings.php`: thêm ô tải logo (JPEG/PNG/WebP, tối đa 3MB), lưu file vào `uploads/store/`
  với tên ngẫu nhiên (tránh trùng/đoán được), tên file lưu trong `store_settings` (key `store_logo`)
  — tự xóa file logo cũ khi tải logo mới để không tích rác.
- Hiển thị logo tại 4 nơi khi đã cấu hình: menu quản trị (`inc_header.php`, cạnh chữ "QLBH2"), trang
  đăng nhập (`login.php`), hóa đơn in (`order_print.php`, phía trên tên chi nhánh), trang Đặt hàng
  Online công khai (`shop.php`, cạnh tiêu đề) — và dùng làm favicon (icon tab trình duyệt) ở cả 4
  trang trên.

Đã test trên app.kt-soft.vn: tải lên logo thật của người dùng qua `store_settings.php` → xác nhận
file lưu đúng vào `uploads/store/`, ảnh tải về được (HTTP 200); logo hiển thị đúng và rõ nét trên
trang đăng nhập (xem trực tiếp qua trình duyệt) và trang Đặt hàng Online; xác nhận qua HTML rằng
logo cũng xuất hiện đúng trên menu quản trị. Không cần xóa dữ liệu vì đây là cấu hình thật của
người dùng, không phải dữ liệu test.

## Bổ sung tính năng Ước tính thuế hộ kinh doanh (đính chính kết luận "ngoài phạm vi" của vòng rà soát trước)

Vòng rà soát trước (mục "Kế toán và Thuế" ở trên) kết luận toàn bộ mảng kế toán/thuế nằm ngoài
phạm vi vì cần API hóa đơn điện tử thật. Kết luận đó **chỉ đúng cho phần phát hành hóa đơn điện tử**
— sau khi đối chiếu với phần mềm QLBH-SOFT (`D:\QLBH-SOFT`, `src/routes/reports.js`) thì phần **ước
tính thuế** theo luật hiện hành hoàn toàn không cần API bên ngoài, chỉ cần tính từ dữ liệu doanh thu
sẵn có trong `orders`. Đã sửa lại và bổ sung vào `accounting.php`:

- **Ước tính thuế hộ kinh doanh theo năm** — tính từ `SUM(orders.total_amount)` theo năm (loại đơn
  đã hủy), áp dụng đúng ngưỡng/tỷ lệ hiện hành cho nhóm "phân phối, cung cấp hàng hóa":
  - Ngưỡng miễn thuế: **1 tỷ đồng/năm** (Nghị định 68/2026/NĐ-CP, đã sửa bởi Nghị định
    141/2026/NĐ-CP — nâng từ 500 triệu lên 1 tỷ).
  - Thuế GTGT: **1%** trên tổng doanh thu năm (Luật Thuế GTGT 48/2024/QH15, Điều 12 khoản 2).
  - Thuế TNCN: **0,5%** trên phần doanh thu vượt ngưỡng miễn thuế, áp dụng khi doanh thu năm từ
    ngưỡng miễn thuế đến 3 tỷ đồng (Luật Thuế TNCN 109/2025/QH15, Điều 7 khoản 3).
  - Doanh thu năm vượt 3 tỷ: hiển thị cảnh báo phải tính theo phương pháp (doanh thu trừ chi phí
    được trừ) × thuế suất lũy tiến (Điều 7 khoản 2) — phần mềm không có dữ liệu chi phí được trừ nên
    không tự tính, chỉ cảnh báo.
  - Bảng chi tiết doanh thu theo từng tháng trong năm.
- **Sổ doanh thu bán hàng, dịch vụ** theo đúng mẫu Thông tư 152/2025/TT-BTC: tự động chọn mẫu
  **S1a-HKD** (không có cột thuế) khi doanh thu năm dưới ngưỡng miễn thuế, hoặc **S2a-HKD** (có cột
  thuế, để trống tự điền) khi vượt ngưỡng — liệt kê từng đơn hàng nhóm theo danh mục sản phẩm
  (`categories.name`), có tổng từng nhóm và tổng tất cả nhóm, lọc theo khoảng ngày tùy chọn.

Đây chỉ là **số ước tính tham khảo** cho trường hợp 100% doanh thu là bán hàng hóa thông thường —
cửa hàng có thêm ngành nghề khác hoặc cần số liệu chính thức để kê khai vẫn cần đối chiếu với cơ
quan thuế/kế toán viên. Phần hóa đơn điện tử (cần API nhà cung cấp thật) vẫn giữ nguyên ngoài phạm
vi như kết luận trước.

Đã test trên app.kt-soft.vn: tạo đơn hàng test `TEST-TAX-001` (1,5 tỷ đồng, năm 2026, danh mục
test) → trang hiển thị đúng "Vượt ngưỡng — phải nộp thuế", thuế GTGT = 15.000.000đ (1% × 1,5 tỷ),
doanh thu tính TNCN = 500.000.000đ (1,5 tỷ − 1 tỷ), thuế TNCN = 2.500.000đ (0,5% × 500 triệu), tổng
17.500.000đ — khớp tính tay; sổ doanh thu hiển thị đúng mẫu S2a-HKD, nhóm theo danh mục, tổng nhóm
và tổng tất cả nhóm khớp số tiền đơn hàng test. Đã xóa sạch đơn hàng, sản phẩm, danh mục test và
toàn bộ script `fix_*.php` tạm dùng để test.

## Bổ sung tính năng Khóa màn hình (theo mẫu tham khảo từ QLBH-SOFT)

Theo yêu cầu người dùng, port tính năng khóa màn hình của QLBH-SOFT (`src/routes/auth.js`, endpoint
`verify-password`) — dùng cho máy tính bán hàng dùng chung, nhân viên tạm rời máy không cần đăng
xuất hẳn mà chỉ khóa lại, nhập đúng mật khẩu của chính mình để mở lại, không mất phiên làm việc.

Đã bổ sung:
- `inc_auth.php`: thêm cờ `$_SESSION['locked']`; `requireLogin()` kiểm tra cờ này sau khi đồng bộ
  session — nếu đang khóa và trang hiện tại không phải `lock.php`/`logout.php` thì chuyển hướng sang
  `lock.php`. Nhờ đặt trong `requireLogin()` (hàm mọi trang đều gọi qua `requireRole()`), cờ khóa có
  hiệu lực trên toàn bộ trang mà không cần sửa từng trang riêng lẻ.
- `lock.php`: trang khóa màn hình độc lập (không dùng chung layout sidebar, tránh lộ nội dung khi đã
  khóa) — hiển thị tên nhân viên đang đăng nhập, ô nhập mật khẩu, xác thực lại đúng mật khẩu của
  chính tài khoản đó qua `password_verify()`; đúng thì bỏ cờ khóa và quay lại `index.php`; sai thì
  báo lỗi và giữ nguyên trạng thái khóa. Có link "Đăng xuất tài khoản khác" làm lối thoát nếu người
  mở khóa không phải chủ phiên.
- `inc_header.php`: thêm nút "🔒 Khóa màn hình" trên thanh topbar, cạnh tên người dùng.

Đã test trên app.kt-soft.vn: truy cập `lock.php` → session chuyển sang trạng thái khóa; thử vào
`index.php` → bị chuyển hướng ngược lại `lock.php` (xác nhận qua header `Location`); nhập sai mật
khẩu → báo lỗi "Mật khẩu không đúng", vẫn ở màn hình khóa; nhập đúng mật khẩu (`Admin@123`) → chuyển
hướng về `index.php` và tải được bình thường (HTTP 200), không cần đăng nhập lại.

## Bổ sung tính năng Sao lưu 1-click (theo mẫu tham khảo từ QLBH-SOFT)

Theo yêu cầu người dùng, port tính năng sao lưu của QLBH-SOFT (`src/routes/backup.js`, dùng SQLite
`VACUUM INTO`). Hosting của QLBH2 là MySQL trên hosting chia sẻ **không có SSH/shell** nên không thể
gọi `mysqldump` thật — thay vào đó dump SQL bằng PHP thuần (đọc `SHOW CREATE TABLE` + dữ liệu từng
bảng theo lô 500 dòng để tránh tràn bộ nhớ với bảng lớn), nén gzip, giữ lại 20 bản gần nhất giống
policy của QLBH-SOFT.

Đã bổ sung:
- `inc_functions.php`: `backupDir()` (tạo `backups/` cùng `.htaccess` chặn truy cập trực tiếp nếu
  chưa có), `createBackup()` (dump toàn bộ bảng thành `.sql.gz`, tự xóa bản cũ hơn 20 bản gần nhất),
  `listBackups()`.
- `backup.php` (chỉ ADMIN): nút "Tạo sao lưu mới" (POST + CSRF), danh sách bản sao lưu kèm thời
  gian/dung lượng/link tải.
- `backup_download.php` (chỉ ADMIN): broker tải file — validate tên file theo đúng định dạng
  `backup_YYYYMMDD_HHMMSS.sql.gz` và tồn tại trong thư mục `backups/` trước khi `readfile()`, tránh
  path traversal.
- `inc_header.php`: thêm mục "Sao lưu dữ liệu" vào nhóm "Cấu hình" (chỉ ADMIN thấy).
- `backups/.htaccess` (tự tạo khi chạy lần đầu): `Deny from all` / `Require all denied` — chặn truy
  cập trực tiếp file `.sql.gz` qua URL, chỉ tải được qua `backup_download.php` (đã qua xác thực
  quyền ADMIN).

Đã test trên app.kt-soft.vn: bấm "Tạo sao lưu mới" → tạo thành công file thật (~6KB) chứa đúng cấu
trúc + dữ liệu (xác nhận `zcat` ra đúng `INSERT INTO` với dữ liệu bảng `users` thật); truy cập trực
tiếp `backups/<file>.sql.gz` qua URL → HTTP 403 (bị `.htaccess` chặn); tải qua `backup_download.php`
với session ADMIN → HTTP 200, file gzip hợp lệ; truy cập `backup.php`/`backup_download.php` khi chưa
đăng nhập → chuyển hướng về trang đăng nhập (HTTP 302), không lộ dữ liệu. Đã xóa file sao lưu test
sau khi xác nhận.

## Hoàn thiện các vá bảo mật đang làm dở (đăng nhập, phiên, phân quyền chi nhánh, tồn kho)

Phát hiện một loạt sửa đổi bảo mật đã viết sẵn trong working directory nhưng chưa deploy/test/commit
— hoàn thiện nốt theo đúng quy trình: deploy lên app.kt-soft.vn, test thật, dọn dữ liệu test, ghi lại
đây rồi mới commit.

**Đăng nhập và phiên làm việc** (`inc_auth.php`, `login.php`, `seed.php`):
- Đặt tên cookie session riêng theo từng deployment (`qlbh2_<hash từ AUTH_SALT>`) thay vì tên mặc
  định `PHPSESSID` — tránh xung đột/dễ đoán khi hosting chia sẻ chạy nhiều app PHP trên cùng domain.
- Bật cờ `secure` cho cookie session khi truy cập qua HTTPS.
- Chống dò mật khẩu: khóa tạm 15 phút sau 5 lần đăng nhập sai liên tiếp cho từng email (lưu trong
  session PHP, không cần bảng DB riêng).
- Đổi session ID mới khi đăng nhập thành công (`session_regenerate_id`) — chặn tấn công session
  fixation.
- `seed.php`: không còn mật khẩu admin cố định trong code — sinh mật khẩu ngẫu nhiên, chỉ hiển thị 1
  lần duy nhất, và tự khóa lại bằng file `seed.lock` ngay sau khi chạy xong (chạy lại sẽ báo lỗi thay
  vì reset mật khẩu admin về giá trị có thể đoán được).

**Phân quyền theo chi nhánh cho CASHIER** (`orders.php`, `order_view.php`, `order_pay.php`,
`order_return_form.php`, `order_returns.php`, `shipment_form.php`, `shipments.php`, `inventory.php`):
trước đây nhân viên thu ngân (CASHIER) xem được đơn hàng/trả hàng/vận chuyển/tồn kho của **mọi** chi
nhánh dù tài khoản chỉ gắn với 1 chi nhánh — lộ dữ liệu khách hàng, giá bán, doanh thu của chi nhánh
khác. Đã chặn theo `effectiveBranchId($currentUser)`: danh sách tự lọc theo chi nhánh, xem/thao tác
trực tiếp bằng ID đơn của chi nhánh khác bị chặn (403), bộ lọc chi nhánh trên `inventory.php` chỉ
hiện với ADMIN/MANAGER.

**Tồn kho khi trả hàng NCC** (`supplier_return_form.php`): thêm khóa dòng (`FOR UPDATE`) và kiểm tra
đủ số lượng tồn trước khi trừ kho khi tạo phiếu trả hàng nhà cung cấp — tránh tồn kho âm khi có nhiều
thao tác đồng thời trên cùng 1 dòng tồn kho.

Đã test trên app.kt-soft.vn:
- Đăng nhập sai 5 lần liên tiếp → lần thứ 5 trở đi báo "Đăng nhập sai quá nhiều lần" dù mật khẩu có
  đúng hay sai; đăng nhập đúng từ 1 phiên trình duyệt khác (chưa bị khóa) vẫn thành công bình thường
  (khóa theo từng session/email, không khóa toàn hệ thống).
- Tạo tài khoản CASHIER test gắn chi nhánh A + 2 đơn hàng test ở chi nhánh A và B → đăng nhập bằng
  tài khoản này: `orders.php` chỉ liệt kê đơn của chi nhánh A; mở trực tiếp đơn chi nhánh B bằng ID
  → HTTP 403 "Bạn không có quyền xem đơn hàng của chi nhánh khác"; mở đơn chi nhánh A → xem bình
  thường; `inventory.php` không hiện dropdown chọn chi nhánh (so với ADMIN vẫn hiện đầy đủ). Đã xóa
  sạch tài khoản, chi nhánh và đơn hàng test.

## Vòng rà soát module Tổng quan (Dashboard) (đối chiếu trang chủ thực tế của Sapo)

Đối chiếu `index.php` với trang chủ Sapo (suabotauo.mysapogo.com) — phát hiện 1 gap bảo mật cùng
loại với các trang đã sửa ở vòng trước (CASHIER lộ dữ liệu chi nhánh khác), cộng 3 chỉ số Sapo có mà
QLBH2 thiếu hoàn toàn:

1. **Không lọc theo chi nhánh** — Sapo có dropdown "Tất cả chi nhánh" trên trang chủ và mọi chỉ số
   đều tôn trọng lựa chọn này; `index.php` của QLBH2 tính toán trên **toàn bộ** đơn hàng/tồn kho của
   mọi chi nhánh, kể cả khi người xem là CASHIER chỉ được gán 1 chi nhánh — lộ y hệt vấn đề đã sửa ở
   `orders.php`/`shipments.php`/`inventory.php` nhưng bị bỏ sót ở trang chủ.
2. **Thiếu "Đơn trả hàng"** trong nhóm 4 chỉ số trong ngày (Sapo có, QLBH2 chỉ có Doanh thu/Đơn
   mới/Đơn hủy).
3. **Thiếu "Giá trị tồn kho"** — QLBH2 chỉ hiện tổng số lượng tồn, không hiện giá trị tồn kho theo
   giá vốn (Sapo hiện cả 2: "Số tồn kho chi nhánh" và "Giá trị tồn kho chi nhánh").
4. **Thiếu "Top sản phẩm bán chạy"** — mục hoàn toàn không tồn tại trong QLBH2, trong khi đây là 1
   trong 5 khối chính của trang chủ Sapo.

Đã bổ sung vào `index.php`:
- Dropdown chọn chi nhánh (chỉ ADMIN/MANAGER thấy, giống mẫu đã dùng ở `inventory.php`); CASHIER tự
  động khóa theo `effectiveBranchId($currentUser)`, không có dropdown. Toàn bộ query (doanh thu, đơn
  mới, đơn hủy, đơn trả, tồn kho, biểu đồ 7 ngày, đơn chờ xử lý, top sản phẩm) đều lọc theo chi nhánh
  đã chọn.
- Ô "Đơn trả hàng" (đếm từ `order_returns` join `orders` theo ngày).
- Ô "Giá trị tồn kho" (`SUM(inventory.quantity * products.cost_price)`), đặt cạnh "Số tồn kho".
- Ô "Chờ thanh toán" trong khối "Đơn hàng cần xử lý" (đơn `payment_status != 'PAID'`, chưa hủy/chưa
  ở trạng thái nháp) — tương đương ý nghĩa "Chờ thanh toán" của Sapo dù pipeline trạng thái đơn của 2
  phần mềm không giống hệt nhau.
- Bảng "Top sản phẩm bán chạy (7 ngày qua)" — top 5 theo doanh thu, từ `order_items`.

Đã test trên app.kt-soft.vn: tạo 2 chi nhánh + 1 sản phẩm với tồn kho khác nhau ở mỗi chi nhánh
(10 và 20, giá vốn 50.000đ) + 1 đơn hàng thanh toán 1 phần (PARTIAL) ở chi nhánh A → xem "Tất cả chi
nhánh" ra đúng tổng (30 SL, 1.500.000đ giá trị); lọc riêng chi nhánh A ra đúng (10 SL, 500.000đ, có
doanh thu 80.000đ); lọc chi nhánh B ra đúng (20 SL, 1.000.000đ, doanh thu 0); ô "Chờ thanh toán" đếm
đúng 1; "Top sản phẩm bán chạy" hiện đúng sản phẩm/số lượng/doanh thu. Đăng nhập bằng tài khoản
CASHIER test gắn chi nhánh A → không thấy dropdown chọn chi nhánh, số liệu tự động đúng bằng số của
chi nhánh A (không lộ tổng công ty). Đã xóa sạch chi nhánh, sản phẩm, đơn hàng, tài khoản test.

## Vòng rà soát module Kiểm hàng (đối chiếu trang "Kiểm hàng" thực tế của Sapo)

Đối chiếu `stock_takes.php`/`stock_take_form.php`/`stock_take_view.php`/`stock_take_balance.php` với
trang Kiểm hàng thật trên Sapo (danh sách phiếu kiểm có cột Trạng thái/Ngày cân bằng/Nhân viên
tạo/Nhân viên kiểm/Nhân viên cân bằng + bộ lọc Trạng thái/Chi nhánh/Ngày tạo). Phát hiện 1 lỗi sai số
liệu nghiêm trọng (không phải chỉ là thiếu tính năng) cộng gap bảo mật cùng loại các vòng trước:

1. **Lỗi dữ liệu nghiêm trọng ở `stock_take_balance.php`**: khi bấm "Cân bằng kho", code cũ **ghi đè
   thẳng** `inventory.quantity = counted_qty` (số đếm lúc lập phiếu nháp). Phiếu kiểm hàng thường ở
   trạng thái nháp một thời gian trước khi được cân bằng — nếu trong lúc đó có bán hàng/nhập hàng
   khác làm thay đổi tồn kho, ghi đè thẳng sẽ **xóa mất** các thay đổi đó mà không ai biết, vì hệ
   thống không hề kiểm tra tồn kho đã trôi đi bao nhiêu kể từ lúc lập phiếu.
2. **MANAGER không bị giới hạn theo chi nhánh** ở toàn bộ luồng kiểm hàng (danh sách, tạo phiếu, xem
   phiếu, cân bằng, tìm sản phẩm) — dropdown chi nhánh khi tạo phiếu hiện tất cả chi nhánh, danh sách
   phiếu hiện tất cả chi nhánh — cùng loại gap đã sửa ở Đơn hàng/Tồn kho/Dashboard các vòng trước
   nhưng bị bỏ sót ở Kiểm hàng.

Đã sửa:
- `stock_take_balance.php`: đổi từ ghi đè `quantity = counted_qty` sang cộng dồn **chênh lệch**
  (`quantity = quantity + (counted_qty - system_qty)`) vào tồn kho hiện tại — giữ nguyên mọi thay đổi
  tồn kho phát sinh giữa lúc lập phiếu nháp và lúc cân bằng, chỉ áp phần chênh lệch thực sự do kiểm
  đếm phát hiện ra. Đồng thời khóa dòng (`FOR UPDATE`) khi đọc tồn kho hiện tại để tránh xung đột khi
  có thao tác đồng thời.
- `stock_takes.php`, `stock_take_form.php`, `stock_take_view.php`, `stock_take_balance.php`,
  `stock_take_search.php`: MANAGER chỉ xem/tạo/cân bằng được phiếu kiểm hàng của chi nhánh mình
  (`effectiveBranchId($currentUser)`) — dropdown chi nhánh trong form bị khóa cứng, xem trực tiếp
  phiếu của chi nhánh khác bằng ID bị chặn (403). ADMIN vẫn xem được tất cả, có thêm dropdown lọc
  theo chi nhánh trên trang danh sách (giống Sapo).

Đã test trên app.kt-soft.vn: tạo sản phẩm test tồn kho 100 tại 1 chi nhánh, lập phiếu kiểm ghi nhận
đếm thực tế 95 (system_qty=100 lúc lập phiếu) → mô phỏng có 1 đơn bán hàng trừ thêm 10 đơn vị *sau
khi* lập phiếu (tồn kho DB lúc này = 90) → bấm "Cân bằng kho" qua đúng endpoint thật → tồn kho sau
cân bằng = **85** (90 + (95-100) = 85), đúng bằng kỳ vọng của công thức chênh lệch — xác nhận không
còn xóa mất đơn bán hàng phát sinh giữa lúc lập phiếu và lúc cân bằng như code cũ. Tạo tài khoản
MANAGER test gắn chi nhánh B → xem phiếu kiểm của chi nhánh A bằng ID → HTTP 403; danh sách phiếu chỉ
hiện phiếu chi nhánh B (0 phiếu); dropdown chi nhánh trong form tạo phiếu bị khóa cứng về chi nhánh B
(disabled). Đã xóa sạch phiếu kiểm, sản phẩm, chi nhánh, tài khoản test.

## Vòng rà soát module Chuyển hàng (đối chiếu trang "Chuyển hàng" thực tế của Sapo)

Đối chiếu `stock_transfers.php`/`stock_transfer_form.php`/`stock_transfer_view.php`/
`stock_transfer_receive.php` — phát hiện gap nghiêm trọng hơn các vòng trước: đây không chỉ là lộ
thông tin, mà là **lỗ hổng cho phép thao tác nghiệp vụ trái phép** vì phiếu chuyển hàng vốn dĩ nối 2
chi nhánh, nên thiếu kiểm tra quyền ảnh hưởng trực tiếp đến tồn kho thật:

1. **`stock_transfer_receive.php` không kiểm tra quyền theo chi nhánh** — bất kỳ MANAGER nào (dù
   quản lý chi nhánh hoàn toàn không liên quan) cũng gọi được endpoint này để **xác nhận nhận hàng**
   (cộng tồn kho vào chi nhánh nhận) hoặc **hủy phiếu** (hoàn tồn kho về chi nhánh chuyển) cho bất kỳ
   phiếu chuyển nào giữa 2 chi nhánh khác — chỉ cần biết ID phiếu, không cần đứng tên ở 1 trong 2 chi
   nhánh liên quan. Nút bấm trên `stock_transfer_view.php` cũng hiện ra cho mọi MANAGER bất kể có
   liên quan hay không.
2. **`stock_transfer_form.php` không giới hạn "Từ chi nhánh"** — MANAGER chọn được bất kỳ chi nhánh
   nào làm nơi xuất hàng, kể cả chi nhánh mình không quản lý, tự ý rút tồn kho của chi nhánh khác.
3. **`stock_transfers.php` (danh sách) không lọc theo chi nhánh** — MANAGER thấy toàn bộ phiếu
   chuyển hàng giữa mọi cặp chi nhánh, kể cả không liên quan đến mình.

Đã sửa — quy tắc áp dụng: MANAGER chỉ được thao tác khi chi nhánh mình là 1 trong 2 đầu của phiếu
chuyển (chuyển đi hoặc nhận):
- `stock_transfer_receive.php`: thêm kiểm tra quyền ngay tại endpoint (không chỉ ẩn nút UI) —
  "Xác nhận nhận hàng" chỉ cho phép nếu chi nhánh mình là **nơi nhận**; "Hủy chuyển hàng" cho phép
  nếu chi nhánh mình là nơi chuyển **hoặc** nơi nhận.
- `stock_transfer_view.php`: chặn xem phiếu (403) nếu chi nhánh mình không liên quan; 2 nút hành
  động chỉ hiện đúng với quyền tương ứng ở trên.
- `stock_transfer_form.php`: khóa cứng "Từ chi nhánh" về chi nhánh của MANAGER (giống mẫu đã dùng ở
  Kiểm hàng); đồng thời gộp các dòng trùng sản phẩm/biến thể trước khi kiểm tra tồn kho, tránh 1
  request thủ công gửi 2 dòng cùng sản phẩm làm trừ kho 2 lần dù mỗi dòng kiểm tra riêng lẻ đều thấy
  đủ tồn.
- `stock_transfers.php`, `stock_transfer_search.php`: lọc/giới hạn theo chi nhánh liên quan, cùng mẫu
  đã áp dụng ở các module trước.

Đã test trên app.kt-soft.vn: tạo 3 chi nhánh test A/B/C (không liên quan tới C) + 1 phiếu chuyển
IN_TRANSIT từ A sang B + tài khoản MANAGER riêng cho A, B, C. Manager C (không liên quan) xem phiếu
bằng ID → HTTP 403; danh sách phiếu của Manager C → 0 kết quả; **giả lập tấn công**: Manager C gửi
thẳng request POST tới `stock_transfer_receive.php` với `action=receive` (bỏ qua UI hoàn toàn) →
phiếu vẫn giữ nguyên trạng thái `IN_TRANSIT`, tồn kho chi nhánh B không đổi (bị chặn ở tầng server,
không chỉ ẩn nút). Manager A (chi nhánh chuyển) xem được phiếu, có nút "Hủy" nhưng không có nút
"Xác nhận nhận hàng" (đúng quyền). Manager B (chi nhánh nhận) gọi đúng luồng "Xác nhận nhận hàng" →
phiếu chuyển thành `COMPLETED`, tồn kho chi nhánh B tăng đúng 10 đơn vị. Đã xóa sạch chi nhánh, sản
phẩm, phiếu chuyển, tài khoản test.

## Vòng rà soát module Trả hàng nhà cung cấp (phát hiện thiếu kiểm tra quyền)

Rà soát chéo toàn bộ nhóm trang "Sản phẩm/Nhập hàng" (`stock_receipts.php`, `purchase_orders.php`,
`suppliers.php`, `categories.php`, `brands.php`, `price_adjustments.php`, `supplier_returns.php`) để
xem trang nào thiếu `requireRole('ADMIN','MANAGER')` — phát hiện **duy nhất** `supplier_returns.php`
(danh sách trả hàng NCC) bị bỏ sót, trong khi mọi trang cùng nhóm đều có. Hậu quả: bất kỳ tài khoản
đã đăng nhập nào, kể cả CASHIER, truy cập trực tiếp URL `supplier_returns.php` đều xem được toàn bộ
lịch sử trả hàng nhà cung cấp — tên NCC, lý do trả, **giá trị hoàn tiền** — dữ liệu tài chính lẽ ra
chỉ dành cho MANAGER/ADMIN (menu cũng chỉ hiện link này với MANAGER/ADMIN nhưng URL không có gì chặn
truy cập trực tiếp).

Đã sửa: thêm `requireRole('ADMIN', 'MANAGER')` vào đầu `supplier_returns.php`, đúng mẫu các trang
cùng nhóm.

Đã test trên app.kt-soft.vn: tạo tài khoản CASHIER test → truy cập trực tiếp `supplier_returns.php`
→ HTTP 403 "Bạn không có quyền truy cập trang này." (trước khi sửa sẽ trả về HTTP 200 kèm toàn bộ dữ
liệu); ADMIN vẫn truy cập bình thường (HTTP 200). Đã xóa tài khoản test.

## Vòng rà soát module Nhà cung cấp (phát hiện: trả nợ NCC không ghi sổ quỹ)

Đối chiếu `supplier_view.php` (màn ghi nhận trả nợ NCC) với luồng thu nợ khách hàng tương ứng
(`order_pay.php`, đã có `recordCashbookEntry()`) và với luồng chi tiền lúc nhập hàng
(`stock_receipt_form.php`, cũng gọi `recordCashbookEntry($branchId, 'PAYMENT', ...)`) — cùng loại gap
đã phát hiện ở vòng rà soát Sổ quỹ trước đây ("sổ quỹ tách rời hoàn toàn khỏi dòng tiền bán hàng"),
lần này ở phía chi tiền cho nhà cung cấp:

- **`supplier_view.php` khi ghi nhận trả nợ NCC chỉ trừ `suppliers.debt`, không ghi bất kỳ khoản chi
  nào vào sổ quỹ** — trong khi đây rõ ràng là tiền mặt/chuyển khoản chi RA khỏi 1 chi nhánh cụ thể.
  Hậu quả: sổ quỹ không bao giờ khớp với tiền mặt thực tế đã chi ra để trả nợ NCC, y hệt vấn đề đã
  sửa cho thu nợ khách hàng nhưng bị bỏ sót ở chiều ngược lại.
- Do màn hình cũ không có khái niệm chi nhánh (nợ NCC là công nợ chung, không gắn 1 chi nhánh), cũng
  không có cách xác định tiền được chi ra từ quỹ chi nhánh nào để ghi sổ quỹ.

Đã sửa `supplier_view.php`:
- Thêm dropdown "Chi từ chi nhánh" (chỉ ADMIN thấy, có nhiều chi nhánh để chọn); MANAGER tự động
  dùng `effectiveBranchId($currentUser)`, không cần chọn.
- Bọc việc trừ `suppliers.debt` và gọi `recordCashbookEntry($branchId, 'PAYMENT', $amount, 'Trả nợ
  NCC ' . tên NCC, 'CASH', ...)` trong 1 transaction — đúng mẫu đã dùng ở `stock_receipt_form.php`.

Đã test trên app.kt-soft.vn: tạo NCC test với công nợ 500.000đ → ghi nhận trả 200.000đ qua đúng form
thật → công nợ còn lại đúng 300.000đ; kiểm tra DB thấy đã tạo 1 dòng `cashbook_entries` loại
`PAYMENT`, đúng số tiền 200.000đ, đúng chi nhánh; xác nhận dòng này **hiển thị đúng trên trang Sổ
quỹ** (`cashbook.php`) như mọi khoản chi khác. Đã xóa sạch NCC và dòng sổ quỹ test.

## Multi-tenant: trang Quản trị hệ thống (`super_admin_tenants.php`)

Sau khi QLBH2 hỗ trợ multi-tenant và có luồng đăng ký dùng thử (`dang-ky.php`), không còn trang nào
cho chủ hệ thống (KT-SOFT) xem được danh sách toàn bộ khách hàng đã đăng ký — mọi trang đều tự động
chỉ hiện dữ liệu của đúng 1 tenant (đúng thiết kế bảo mật, nhưng chủ hệ thống cũng cần 1 góc nhìn
tổng).

Đã thêm:
- `inc_auth.php`: hàm `requireSuperAdmin()` — chỉ ADMIN của **tenant #1** (tenant chủ sở hữu, tạo ra
  khi chạy `fix_multitenant_migrate.php`) mới qua được, các tenant khác dù là ADMIN cũng bị chặn
  (403). Đây là ngoại lệ CỐ Ý duy nhất phá vỡ quy tắc "mỗi tenant chỉ thấy dữ liệu của mình", vì đây
  là công cụ vận hành nền tảng, không phải nghiệp vụ 1 cửa hàng.
- `super_admin_tenants.php`: danh sách toàn bộ tenant — tên cửa hàng, email quản trị, ngày đăng ký,
  gói (Dùng thử/Trả phí), số ngày còn lại của gói dùng thử, số nhân viên/sản phẩm/đơn hàng (để biết
  đang thực sự dùng hay chỉ đăng ký rồi bỏ), hoạt động gần nhất (từ `activity_logs`), trạng thái
  khóa/hoạt động. Có sẵn 3 hành động: **Nâng cấp** lên gói trả phí, **+14 ngày** gia hạn dùng thử,
  **Khóa/Mở khóa** tài khoản — không cho thao tác lên chính tenant #1 để tránh tự khóa mình.
- `inc_header.php`: thêm mục "Quản trị hệ thống (KT-SOFT)" vào nhóm Cấu hình, chỉ hiện với đúng
  ADMIN của tenant #1.

Đã test trên app.kt-soft.vn: trang hiển thị đúng 1 tenant thật (chủ sở hữu) sau khi dọn sạch dữ liệu
test; tạo 1 tenant + tài khoản ADMIN test khác (không phải tenant #1) → đăng nhập, cố truy cập
`super_admin_tenants.php` → bị chặn đúng HTTP 403, và mục menu cũng không hiện ra cho tài khoản này.
Phát hiện thêm khi dọn dữ liệu test: `activity_logs.tenant_id` có ràng buộc khóa ngoại tới `tenants`
— xóa tenant test phải xóa `activity_logs` liên quan trước, nếu không sẽ báo lỗi khóa ngoại (đã ghi
chú lại để các lần dọn dữ liệu test sau không gặp lại).

## Multi-tenant: cảnh báo đếm ngược hạn dùng thử ngay trong app

`checkTrialExpiry()` chỉ chặn truy cập khi hết hạn hẳn — người dùng không có cảnh báo trước, dễ bị
bất ngờ khi tự nhiên không vào được app nữa. Cần hiện cảnh báo "còn X ngày" ngay trong giao diện
trước khi bị khóa.

Đã thêm vào `inc_header.php` (chỉ tính toán và hiện với ADMIN/MANAGER — `$isManagerUp`):
- Truy vấn `plan`, `trial_ends_at` của tenant hiện tại, tính `trialDaysLeft` (số ngày còn lại, làm
  tròn lên) — chỉ tính khi `plan = 'TRIAL'`.
- Banner nằm giữa `.topbar` và `.content`, 3 mức nội dung: hết hạn hôm nay, còn 1 ngày, còn N ngày.
- Style: nền xanh nhạt bình thường, chuyển sang nền đỏ nhạt (`trial-banner-urgent`) khi còn ≤ 3
  ngày để tạo cảm giác khẩn cấp hơn. Có link "Liên hệ nâng cấp →" trỏ sang `kt-soft.vn/lien-he.php`.
- Tenant gói `PAID` (kể cả tenant #1 chủ sở hữu) không thấy banner này.

Đã test trên app.kt-soft.vn: tạo 2 tenant TRIAL test song song (còn 2 ngày và còn 10 ngày) — tenant
còn 2 ngày hiện đúng banner đỏ "còn 2 ngày"; tenant còn 10 ngày hiện đúng banner xanh "còn 10 ngày"
kèm link nâng cấp; tài khoản chủ sở hữu (gói PAID) không hiện banner nào. Đã dọn sạch 2 tenant test
sau khi xác nhận.

## Multi-tenant: vá lỗ hổng "Sao lưu dữ liệu" rò rỉ xuyên tenant + thêm "Xuất dữ liệu của tôi"

Khi trả lời câu hỏi "người dùng có backup dữ liệu về máy được không", rà lại `backup.php` phát hiện
lỗ hổng nghiêm trọng: hàm `createBackup()` dump **toàn bộ database** (mọi bảng, mọi tenant) thành 1
file `.sql.gz` dùng chung trên server, còn `backup.php`/`backup_download.php` chỉ kiểm tra
`requireRole('ADMIN')` — nghĩa là **bất kỳ tenant nào** (kể cả tài khoản tự đăng ký dùng thử) cũng
tạo và tải được file chứa dữ liệu của **mọi khách hàng khác** trên hệ thống.

Đã sửa:
- `backup.php`, `backup_download.php`: đổi từ `requireRole('ADMIN')` sang `requireSuperAdmin()` —
  chỉ ADMIN của tenant #1 (chủ hệ thống KT-SOFT) mới dùng được, đúng bản chất là công cụ vận hành
  nền tảng chứ không phải tính năng cho từng cửa hàng.
- `tenant_export.php` (file mới): cho phép ADMIN của **từng tenant** tự tải về đúng dữ liệu của
  cửa hàng mình (sản phẩm, khách hàng, đơn hàng, tồn kho, kiểm/chuyển hàng, nhập hàng, bảo hành,
  sổ quỹ, sổ quỹ, hoạt động...) thành file `.sql.gz`. Khác với `backup.php` cũ, file được sinh và
  trả về trực tiếp trong 1 request (không lưu trên server) — tránh lặp lại kiểu rò rỉ "file dùng
  chung" như lỗ hổng vừa vá. Lọc theo tenant dùng đúng 2 tầng đã áp dụng xuyên suốt dự án: bảng có
  `tenant_id` lọc trực tiếp, bảng có `branch_id` lọc qua `branches.tenant_id`, bảng con lọc qua
  chuỗi khóa ngoại về bảng cha đã được lọc đúng tenant.
- `inc_header.php`: đổi mục menu "Sao lưu dữ liệu" (nhóm Cấu hình) thành "Xuất dữ liệu của tôi" cho
  mọi ADMIN; riêng ADMIN của tenant #1 mới thấy thêm mục "Sao lưu dữ liệu (toàn hệ thống)".

Đã test trên app.kt-soft.vn: tạo 1 tenant test có 1 chi nhánh, 1 tài khoản ADMIN, 1 sản phẩm →
đăng nhập bằng tài khoản này, gọi `backup.php` xác nhận trả về đúng HTTP 403; gọi
`tenant_export.php` xác nhận file tải về chỉ có đúng 4 dòng `INSERT` (tenant/branch/user/product
của chính tenant đó), grep toàn bộ file không thấy id của bất kỳ tenant nào khác. Đã dọn sạch dữ
liệu test.

## Multi-tenant: rà soát tổng thể sau lỗ hổng backup — tìm và vá thêm 3 lỗ hổng nữa

Sau khi vá lỗ hổng backup.php, người dùng lo ngại còn sót lỗ hổng khác nên yêu cầu rà soát toàn bộ
codebase (grep-sweep có hệ thống mọi file dùng `hasRole('ADMIN'...)` bypass, mọi endpoint nhận `id`
từ GET/POST, mọi trang export/print/download, mọi trang công khai không cần đăng nhập). Kiểm tra
sâu khoảng 45 file, tìm thêm 3 lỗ hổng nghiêm trọng:

1. **`order_print.php`, `order_quick.php`** — chỉ kiểm tra `requireLogin()`, không lọc theo tenant.
   Bất kỳ tài khoản nào (kể cả tự đăng ký dùng thử) chỉ cần đổi `?id=` tăng dần là xem được hóa đơn
   đầy đủ (tên/SĐT khách hàng, sản phẩm, giá, chi nhánh...) của **tenant bất kỳ**. Đã vá:
   `order_print.php` thêm điều kiện `b.tenant_id = ?` vào JOIN branches có sẵn; `order_quick.php`
   thêm JOIN qua `orders`/`branches` để lọc tenant (bảng `order_items` không có cột tenant/branch
   trực tiếp).
2. **`shop.php`, `shop_order.php`** — trang đặt hàng online công khai (không đăng nhập) chọn "chi
   nhánh đầu tiên đang hoạt động" và tra cứu/tạo khách hàng theo SĐT **không lọc theo tenant nào
   cả** — lộ toàn bộ sản phẩm/giá/tồn kho của bất kỳ tenant nào có chi nhánh id nhỏ nhất trong toàn
   hệ thống, và có thể trộn lẫn dữ liệu khách hàng giữa các tenant khác nhau (2 tenant có khách cùng
   SĐT sẽ dùng chung 1 dòng `customers`). Trang này chưa có cách xác định "đang xem cửa hàng của
   tenant nào" khi không đăng nhập (cần subdomain/slug riêng từng cửa hàng — làm sau). Tạm thời khóa
   cứng cả 2 file chỉ phục vụ đúng **tenant #1** (KT-SOFT, cửa hàng thật duy nhất đang dùng tính
   năng này hiện nay) cho tới khi xây storefront riêng theo từng tenant.

Đã test bằng kịch bản tấn công thật: tạo tenant A (victim, có 1 đơn hàng) và tenant B (attacker)
song song → đăng nhập tenant B, thử đổi id sang đơn hàng tenant A qua `order_print.php` và
`order_quick.php` → đều bị chặn đúng (không thấy dữ liệu); tenant A vẫn xem được đơn hàng của chính
mình bình thường; `shop.php` vẫn hiển thị đúng sản phẩm của tenant #1 như trước khi vá. Đã dọn sạch
dữ liệu test.

`fix_multitenant_migrate.php` (script migrate DDL không có auth) cũng được rà lại — xác nhận đã bị
xóa khỏi server production từ lúc chạy xong (trả về 404), chỉ còn lưu trong git để tham khảo, không
phải rủi ro đang tồn tại.

## Rà soát tổng thể lần 3 (sau khi đổi tên QLBH-CLOUD) — vá thêm 1 lỗ hổng coupon xuyên tenant

Sau khi hoàn tất các tính năng dùng thử 12 tháng/gia hạn/đổi tên thương hiệu, người dùng yêu cầu rà
soát tổng thể toàn bộ app một lần nữa. Quét có hệ thống ~134 file, tìm thêm:

- **`coupon_check.php` (NGHIÊM TRỌNG)** — endpoint AJAX kiểm tra mã giảm giá khi bán hàng, trước đó
  chỉ tra theo `code`, hoàn toàn không lọc `tenant_id` dù bảng `coupons` là bảng trực tiếp có
  `tenant_id`. Bất kỳ tenant nào (kể cả tài khoản tự đăng ký dùng thử) đoán/thử đúng mã giảm giá
  của tenant khác là áp dụng được, lộ luôn điều khoản khuyến mại (% giảm, đơn tối thiểu, hạn dùng,
  số lượt còn lại) của tenant đó. Đã thêm `AND tenant_id = ?` dùng `currentTenantId()`.
- **`fix_multitenant_migrate.php`** — script chạy DDL không yêu cầu đăng nhập, dù mỗi bước đã tự
  bỏ qua nếu đã áp dụng (idempotent) nhưng vẫn có thể bị gọi lại bởi bất kỳ ai. Thêm cơ chế khóa
  file (`fix_multitenant_migrate.lock`, theo đúng mẫu `seed.lock` của `seed.php`) — sau khi chạy
  thành công 1 lần, các lần gọi sau chỉ trả về thông báo đã khóa.

Đã test trên production bằng kịch bản tấn công thật: tạo tenant A (victim, có coupon `SECRET50`)
và tenant B (attacker) — tenant B gọi `coupon_check.php?code=SECRET50` bị từ chối đúng ("không tồn
tại"); tenant A vẫn dùng được coupon của chính mình (tính đúng số tiền giảm). Xác nhận
`fix_multitenant_migrate.php` bị khóa đúng ở lần gọi thứ 2. Đã dọn sạch dữ liệu test.

## Multi-tenant: dùng thử 12 tháng miễn phí, gia hạn theo năm + form gửi yêu cầu gia hạn

Chủ hệ thống quyết định cho khách hàng dùng thử **miễn phí 12 tháng** (thay vì 14 ngày như ban đầu),
gia hạn về sau tính theo năm. Đồng thời phát hiện chưa có cách nào trong app để khách chủ động gửi
yêu cầu gia hạn — trước đó chỉ có link ra trang liên hệ chung của kt-soft.vn.

Đã thêm:
- `dang-ky.php`: tenant mới tạo có hạn dùng thử `INTERVAL 12 MONTH` (thay vì `14 DAY`), cập nhật
  text trên form và banner tương ứng.
- `super_admin_tenants.php`: nút gia hạn nhanh đổi từ "+14 ngày" thành "**+1 năm**" (365 ngày).
- Bảng `renewal_requests` (tenant_id, contact_name, contact_phone, message, status, created_at) —
  lưu các yêu cầu gia hạn khách tự gửi từ trong app.
- `gia_han.php` (trang mới): mọi tenant đã đăng nhập gửi yêu cầu gia hạn/nâng cấp — nhập tên người
  liên hệ (mặc định lấy tên tài khoản), SĐT/Zalo (bắt buộc), ghi chú. Khi gửi: lưu vào
  `renewal_requests` **và** thử gửi email tới `hoangnamcnc@gmail.com` qua `mail()` của hosting
  (best-effort — vì hosting chia sẻ không đảm bảo gửi được nên có lưu DB làm nguồn dữ liệu chính,
  không phụ thuộc hoàn toàn vào email). Trang cũng hiện sẵn thông tin liên hệ trực tiếp: ĐT/Zalo
  **0945289666**, email **hoangnamcnc@gmail.com**. Được thêm vào danh sách ngoại lệ của
  `checkTrialExpiry()` (cùng `trial_expired.php`, `logout.php`) để tenant đã hết hạn vẫn gửi được
  yêu cầu thay vì bị chặn hoàn toàn.
- `inc_header.php`: banner đếm ngược đổi link "Liên hệ nâng cấp" (trỏ ra kt-soft.vn) thành "Yêu cầu
  gia hạn" (trỏ thẳng vào `gia_han.php` trong app); thêm mục menu "Yêu cầu gia hạn" ở nhóm Cấu hình
  cho mọi ADMIN, không chỉ khi sắp hết hạn.
- `trial_expired.php`: cập nhật text "12 tháng", nút chính đổi thành "Yêu cầu gia hạn" (trỏ
  `gia_han.php`), thêm dòng thông tin liên hệ trực tiếp.
- `super_admin_tenants.php`: thêm bảng "Yêu cầu gia hạn đang chờ" ở đầu trang (tên cửa hàng, người
  liên hệ, SĐT/Zalo, ghi chú, thời gian gửi), có nút "Đã liên hệ" đánh dấu `status = 'DONE'` sau khi
  chủ hệ thống đã xử lý — dùng chung transaction/CSRF với các hành động khác trên trang.

Đã test trên production: đăng ký 1 tenant thật qua `dang-ky.php`, xác nhận `trial_ends_at` đúng
+12 tháng (2026 → 2027, không phải +14 ngày); gửi form `gia_han.php`, xác nhận lưu đúng 1 dòng vào
`renewal_requests` với đầy đủ thông tin. Đã dọn sạch dữ liệu test.

## Multi-tenant: hỏi rõ khi bấm "Dùng thử" nhưng trình duyệt đang có sẵn phiên đăng nhập

Người dùng (chính chủ hệ thống) phản ánh: bấm "Dùng thử QLBH2 12 tháng" trên kt-soft.vn thì vào
thẳng tài khoản admin chủ của mình, không thấy trang đăng ký/đăng nhập. Nguyên nhân: `dang-ky.php`
có logic cũ — nếu trình duyệt đang có phiên đăng nhập còn hiệu lực (`currentUser()` trả về khác
null) thì tự động `redirect('index.php')` luôn, để tránh bắt người dùng đã có tài khoản đăng ký
lại. Đúng ý đồ thiết kế ban đầu, nhưng gây nhầm lẫn — người bấm không biết vì sao "biến mất" trang
đăng ký, tưởng là lỗi.

Đã sửa: khi phát hiện đang có phiên đăng nhập, không tự động chuyển hướng nữa mà hiện màn hình hỏi
rõ — "Bạn đang đăng nhập tài khoản `<email>`... tiếp tục vào hệ thống hay đăng ký tài khoản mới?"
với 2 nút:
- **Tiếp tục vào hệ thống** → `index.php` (giữ nguyên hành vi cũ cho người thật sự đã có tài
  khoản).
- **Đăng ký tài khoản mới** → `dang-ky.php?new=1`, bỏ qua bước hỏi và hiện thẳng form đăng ký —
  luồng tạo tenant mới + tự động đăng nhập tài khoản vừa tạo giữ nguyên như cũ (ghi đè session).

Đã test trên production: đăng nhập 1 tài khoản test, xác nhận `dang-ky.php` hiện đúng màn hình hỏi
với email chính xác của tài khoản đang đăng nhập; `dang-ky.php?new=1` hiện đúng form đăng ký bình
thường. Đã dọn sạch dữ liệu test.

## Đánh giá tổng thể phần mềm + sửa theo báo cáo (mobile, giá vốn lịch sử, trần chiết khấu)

Người dùng yêu cầu rà soát đánh giá kỹ toàn bộ hệ sinh thái (không chỉ bảo mật). Chạy 2 agent đọc
code song song cho QLBH-CLOUD và kt-soft.vn/admin, tổng hợp báo cáo bằng tiếng Việt xếp theo mức độ
quan trọng. Đã sửa các mục quan trọng nhất từ báo cáo:

- **Responsive di động**: `inc_header.php` trước đây không có `@media query` nào, sidebar 250px cố
  định tràn ngang trên điện thoại. Thêm nút hamburger mở sidebar dạng drawer trượt (kèm lớp phủ mờ
  phía sau, bấm ra ngoài để đóng), bảng tự cuộn ngang thay vì tràn trang, `.grid-2` về 1 cột dưới
  860px — chỉ sửa 1 file dùng chung nên áp dụng ngay cho toàn bộ ~124 trang.
- **Lỗi lãi gộp tính sai theo giá vốn hiện tại** (nghiêm trọng nhất về số liệu tài chính):
  `reports.php` trước đây tính giá vốn bằng giá vốn *hiện tại* của sản phẩm thay vì giá vốn *tại
  thời điểm bán* — mỗi lần đổi giá nhập hàng, lãi gộp của các kỳ **quá khứ** cũng bị tính lại sai
  theo. Thêm cột `order_items.cost_price`, chốt giá vốn ngay lúc tạo đơn (`pos_checkout.php`,
  `shop_order.php`), `reports.php` đổi sang dùng `oi.cost_price`. Migrate qua
  `fix_add_cost_price.php` (backfill dữ liệu cũ bằng giá vốn hiện tại làm giá trị xấp xỉ, ghi chú
  rõ trong code — thực tế production chưa có đơn hàng thật nào nên backfill = 0 dòng).
- **Trần chiết khấu cộng dồn 50%**: chiết khấu tay + coupon + hạng khách + khuyến mại tự động
  trước đây cộng dồn không giới hạn hợp lý (về lý thuyết có thể giảm 100%). Thêm trần 50% tổng đơn
  ở bước cuối cùng trong `pos_checkout.php`, đồng bộ vào 3 chỗ tính tổng xem trước bằng JS trong
  `pos.php` để nhân viên thấy đúng số tiền sẽ áp dụng trước khi thanh toán.

Đã test trên production: viewport 375px xác nhận sidebar ẩn đúng, hamburger mở được drawer; tạo 1
sản phẩm giá vốn 50.000, bán đơn 100.000, đổi giá vốn sản phẩm lên 90.000 → báo cáo lãi gộp vẫn
hiện đúng 50.000 (không bị tính lại theo giá mới); tạo đơn 100.000 nhập chiết khấu tay 90.000 →
hệ thống chỉ cho giảm đúng 50.000 (50%). Đã dọn sạch dữ liệu test.

## Vá lỗ hổng NGHIÊM TRỌNG ở trang Kế toán và Thuế + thêm đủ 7 mẫu sổ theo Thông tư 152/2025/TT-BTC

Người dùng cung cấp tài liệu "Chi tiết mẫu sổ sách kế toán hộ kinh doanh theo thông tư 152" để đối
chiếu — phát hiện `accounting.php` mới chỉ làm 2/7 mẫu sổ chính thức (S1a-HKD, S2a-HKD). Trong lúc
đọc code để mở rộng, phát hiện thêm **lỗ hổng rò rỉ tài chính nghiêm trọng nhất từng tìm thấy trong
dự án**: cả 2 truy vấn chính của trang (ước tính thuế theo tháng, sổ doanh thu theo khoảng ngày)
hoàn toàn không lọc theo tenant — mọi tenant vào trang này đều thấy **doanh thu gộp của TẤT CẢ
khách hàng trên toàn hệ thống**, không phải của riêng mình. Đã vá ngay bằng cách thêm
`JOIN branches b ... AND b.tenant_id = ?` vào cả 2 truy vấn.

Sau khi vá xong, thêm đủ 5 mẫu sổ còn thiếu:
- Thêm bộ chọn **Nhóm nộp thuế** (chỉ hiện khi đã vượt ngưỡng miễn thuế) — Nhóm 2 (GTGT+TNCN đều
  theo tỷ lệ % doanh thu, dùng mẫu S2a-HKD như cũ) hoặc Nhóm 3 (GTGT theo tỷ lệ nhưng TNCN theo
  thu nhập, dùng mẫu S2b-HKD) — lưu lựa chọn qua `setSetting()`, phần mềm không tự suy ra được vì
  đây là lựa chọn đăng ký với cơ quan thuế.
- Nhóm 3 hiện thêm: **S2c-HKD** (sổ doanh thu, chi phí — chi phí lấy từ khoản chi tay trong sổ quỹ,
  loại trừ `auto_generated` để không tính nhầm chi phí hệ thống tự sinh khi bán/nhập hàng), **S2d-HKD**
  (sổ chi tiết vật liệu/hàng hóa — nhập từ `stock_receipts`, xuất từ `order_items`, tồn hiện tại từ
  `inventory`), **S2e-HKD** (sổ chi tiết tiền — liệt kê thu/chi từ `cashbook_entries`, tính số dư
  cộng dồn từ đầu khoảng ngày đã chọn).
- **S3a-HKD** (nghĩa vụ thuế khác): thẻ thông tin, không tự động tổng hợp vì không có dữ liệu nguồn
  (thuế XNK/TTĐB/tài nguyên/môi trường) — ghi chú rõ đa số cửa hàng bán lẻ không phát sinh loại này.

Đã test trên production bằng kịch bản tấn công thật (tenant A có đơn 999.999.999đ, tenant B rỗng —
xác nhận tenant B thấy đúng 0đ sau khi vá) và kịch bản nghiệp vụ thật (tenant có đơn 1,2 tỷ, nhập
100 đơn vị, chi 2 triệu, thu 500 nghìn qua sổ quỹ — xác nhận nhóm 2 hiện đúng S2a, chuyển nhóm 3
hiện đúng S2b/S2c/S2d/S2e/S3a với số liệu chính xác). Đã dọn sạch dữ liệu test.

## Thêm module Nhân sự: Chấm công, Lịch làm việc, Bảng lương (theo mẫu QLBH-SOFT)

Người dùng yêu cầu bổ sung quản lý nhân viên/chấm công/tính lương giống QLBH-SOFT. Đọc code
QLBH-SOFT (`src/routes/users.js`, `public/quan-ly.html`) để lấy đúng mô hình dữ liệu và công thức
tính lương trước khi làm, thay vì tự bịa: nhân viên có **lương theo giờ** + **% hoa hồng trên
doanh số**, không dùng lương cơ bản cố định — ngày nào không chấm công thì ngày đó tính 0 giờ.

Đã thêm:
- `users.php`: 2 trường Lương/giờ và Tỷ lệ hoa hồng trong form tạo tài khoản, và 1 form sửa nhanh
  ngay trong bảng danh sách (action `update_pay`, có kiểm tra thuộc đúng tenant như các action
  khác của trang).
- `attendance.php` (mới) — **Chấm công**: chọn nhân viên + tháng, ghi nhận giờ vào/giờ ra từng
  ngày (upsert qua `ON DUPLICATE KEY UPDATE`, mỗi nhân viên 1 bản ghi/ngày), xử lý được ca làm qua
  đêm (giờ ra nhỏ hơn giờ vào), tự tính tổng giờ công tháng.
- `work_schedules.php` (mới) — **Lịch làm việc**: xếp lịch ca làm theo ngày cho từng nhân viên
  (chỉ để theo dõi, không ảnh hưởng tính lương).
- `payroll.php` (mới) — **Bảng lương**: theo tháng, tự tính cho từng nhân viên đang hoạt động:
  `Thực nhận = Tổng giờ công tháng × Lương/giờ + (Doanh số bán trong tháng − doanh thu đã trả
  hàng) × % hoa hồng` — đúng công thức của QLBH-SOFT.
- Thêm nhóm menu "Nhân sự" (chỉ ADMIN/MANAGER thấy).
- Bảng mới `attendance`, `work_schedules` (có `tenant_id` trực tiếp) + 2 cột mới trên `users`
  (`hourly_wage`, `commission_percent`), migrate qua `fix_add_payroll.php`.

**Lỗi có sẵn quan trọng phát hiện và vá trong lúc test**: PDO của dự án trả về cột `id` dạng
**chuỗi** (string), không phải số nguyên — khiến mọi chỗ dùng
`in_array($x, array_column($arr, 'id'), true)` (so sánh kiểu chặt) **luôn thất bại** do lệch kiểu
dữ liệu, âm thầm reset lựa chọn về `null`/rỗng mà không báo lỗi gì. Ảnh hưởng **12 file** đã tồn
tại từ trước: `users.php` (gán chi nhánh cho nhân viên **không bao giờ hoạt động**), `index.php`,
`inventory.php` (lọc chi nhánh ở Tổng quan/Kho), `product_form.php` (danh mục/nhãn hiệu/thuế/bảo
hành/bảng giá sản phẩm), `campaigns.php` (nhóm khách hàng), `customer_form.php` (nhân viên phụ
trách), `purchase_order_form.php` + `stock_receipt_form.php` (nhà cung cấp), `stock_take_form.php`
+ `supplier_view.php` (chi nhánh) — cộng thêm `attendance.php`/`work_schedules.php` phát hiện ngay
lúc vừa viết. Đã sửa toàn bộ bằng `array_map('intval', ...)` trước khi đưa vào `in_array`.

Đã test trên production: tạo nhân viên lương 30.000đ/giờ + hoa hồng 2%, chấm công 8h-17h (9 giờ),
bán đơn 1.000.000đ → Bảng lương tính đúng 270.000đ (lương giờ) + 20.000đ (hoa hồng) = 290.000đ
thực nhận; xác nhận gán chi nhánh cho nhân viên mới hoạt động đúng (trước đây luôn bị reset về
rỗng). Đã dọn sạch dữ liệu test.

## Kiểm thử toàn diện toàn bộ luồng nghiệp vụ trên production

Người dùng yêu cầu "test toàn diện phần mềm". Dùng 2 tenant test (A có dữ liệu, B rỗng để đối
chứng cách ly), đi qua toàn bộ chuỗi nghiệp vụ thật bằng request POST/GET thật (không chỉ đọc
code):

1. **POS**: bán 1 đơn kèm coupon 10% + chiết khấu tay 90% → xác nhận trần 50% chặn đúng (discount
   thực tế = 100.000 trên đơn 200.000, không phải ~190.000 nếu cộng dồn không giới hạn); tồn kho
   trừ đúng; `order_items.cost_price` chốt đúng giá vốn tại thời điểm bán.
2. **Hủy đơn**: hủy đơn vừa tạo → xác nhận hoàn tồn kho đúng về số ban đầu.
3. **Nhập hàng**: nhập 10 đơn vị → tồn cộng đúng.
4. **Chuyển hàng**: chuyển 5 đơn vị giữa 2 chi nhánh, xác nhận chi nhánh gửi trừ đúng, trạng thái
   `IN_TRANSIT`, sau khi bấm "Xác nhận đã nhận hàng" thì chi nhánh nhận cộng đúng.
5. **Khách hàng & công nợ**: bán trả góp một phần (trả 30.000/100.000) → công nợ khách hiện đúng
   70.000.
6. **Báo cáo**: đổi giá vốn sản phẩm SAU khi bán (40.000 → 90.000) → lãi gộp báo cáo vẫn dùng
   đúng giá vốn cũ (100.000 − 40.000 = 60.000), không bị tính lại theo giá mới.
7. **Bảng lương**: chấm công 8 giờ, lương 25.000đ/giờ + hoa hồng 3% → thực nhận đúng
   200.000 + 3.000 = 203.000, và **doanh số hoa hồng tự động loại trừ đúng đơn đã hủy** (chỉ tính
   đơn 100.000 còn hiệu lực, không cộng luôn đơn 200.000 đã hủy ở bước 2).
8. **Cách ly tenant**: tenant B (rỗng) xác nhận thấy đúng 0/rỗng ở `index.php`, `customers.php`,
   `payroll.php`, `accounting.php` — không lẫn dữ liệu tenant A.

**Phát hiện thêm 1 lỗ hổng chức năng nghiêm trọng khi test bước 4** — `stock_transfer_form.php` có
cùng lỗi "PDO trả về `id` dạng chuỗi khiến `in_array(..., true)` luôn thất bại" đã vá ở 12 file
khác trước đó, nhưng sót lại vì `array_column()` được gán ra biến `$branchIds` riêng ở dòng khác
thay vì viết liền trong `in_array(...)` — khác pattern grep đã quét trước đó. Hậu quả: **tính năng
Chuyển hàng hoàn toàn không dùng được**, luôn báo lỗi "Vui lòng chọn chi nhánh chuyển và chi nhánh
nhận" dù đã chọn đúng cả 2 chi nhánh. Đã vá bằng `array_map('intval', ...)` giống các chỗ khác, và
quét lại toàn bộ codebase bằng pattern grep mới để xác nhận không còn chỗ sót nào khác.

Toàn bộ 8 bước đều cho kết quả đúng sau khi vá xong lỗi ở bước 4. Đã dọn sạch dữ liệu test (2
tenant, sản phẩm, đơn hàng, phiếu nhập/chuyển, chấm công, khách hàng, coupon...) và xóa hết 5 script
tạm, xác nhận lại bằng 404.

## Rà soát bảo mật: nâng khóa brute-force từ session lên IP + DB

Rà soát phát hiện khóa chống dò mật khẩu ở `inc_auth.php` tuy **có tồn tại** (5 lần sai → khóa 15
phút) nhưng lưu trong `$_SESSION` — kẻ tấn công chỉ cần xóa cookie hoặc mở tab ẩn danh là được
session mới tinh, bộ đếm về 0, **vô hiệu hoàn toàn cơ chế khóa**. Đây là kiểu lỗi dễ bỏ sót vì
đọc code thì thấy "đã có chống brute-force" nên không ai soi lại.

Đã chuyển sang bảng `login_attempts` (khóa theo IP, hỗ trợ nhiều loại hành động qua cột `action`),
dùng chung cho cả đăng nhập và yêu cầu đặt lại mật khẩu. Kiểm chứng trên production: 5 lần sai →
khóa đúng; sau đó **dùng cookie hoàn toàn mới vẫn bị khóa** (đây chính là điểm mà cơ chế cũ thất
bại). Đã dọn sạch dữ liệu test sau khi xong.

Cùng đợt này: tắt `display_errors` trên production (vẫn giữ `log_errors` để debug được) ở cả hai hệ
thống, và thêm `.htaccess` chặn truy cập HTTP trực tiếp vào `config.php`/`schema.sql` (đã xác nhận
trả về 403, trong khi `require` nội bộ của PHP không bị ảnh hưởng).

## PHÁT HIỆN LỚN: chưa từng có email nào được gửi đi

Trong lúc rà soát, người dùng xác nhận không nhận được email thông báo sao lưu. Chẩn đoán trực tiếp
trên server cho kết quả dứt khoát:

```
mail() với From=no-reply@kt-soft.vn -> false
mail() không đặt From               -> false
fsockopen localhost:25              -> [111] Connection refused
```

**Hosting này không có mail server nội bộ**, nên `mail()` của PHP luôn thất bại. Nghĩa là toàn bộ
email từ trước tới thời điểm này **chưa bao giờ rời khỏi server** — không phải "rơi vào spam".
Hậu quả nặng nhất: chức năng **đặt lại mật khẩu** (cả QLBH-CLOUD lẫn admin kt-soft.vn) vô dụng,
khách bấm "Quên mật khẩu" sẽ mất tài khoản vĩnh viễn. Kèm theo: nhắc hết hạn dùng thử, báo cáo
tuần, xác nhận thanh toán, yêu cầu gia hạn đều không chạy.

Kiểm tra DNS cũng cho thấy `kt-soft.vn` **chưa có SPF, DKIM, DMARC lẫn MX** — kể cả khi gửi được
thì mail từ địa chỉ `@kt-soft.vn` cũng rất dễ bị Gmail chặn, và khách bấm Reply sẽ không tới đâu.

**Cách khắc phục**: viết `inc_mail.php` — SMTP client tối giản có xác thực, thay toàn bộ 10 chỗ gọi
`mail()` ở cả hai codebase bằng `sendMail()`. Một chi tiết quan trọng chỉ lộ ra khi bắt tay SMTP
thật:

| Cổng | Kết quả kiểm chứng |
|---|---|
| 587 (STARTTLS) — mặc định phổ biến | **Bị chặn**: mở được TCP nhưng không nhận được greeting, bật TLS thất bại |
| 465 (SSL trực tiếp) | Bắt tay đầy đủ với `smtp.gmail.com`, hỗ trợ `AUTH LOGIN PLAIN` |

Nếu làm theo mặc định 587 thì tính năng vẫn hỏng âm thầm y như cũ — nên code cố định dùng 465.
Tiêu đề mã hóa MIME encoded-word và thân thư base64 để không vỡ tiếng Việt. Khi chưa cấu hình SMTP
thì ghi `error_log` và trả về `false`, **không ném lỗi ra ngoài** — email hỏng không được phép làm
vỡ luồng đăng nhập hay thanh toán đang chạy.

Đã kiểm chứng end-to-end sau khi điền App Password: gửi thành công (Gmail nhận thư sau ~4 giây) và
chạy thật luồng "Quên mật khẩu" của admin kt-soft.vn, không còn lỗi nào trong log. Mật khẩu ứng
dụng để trong `config.php` / `admin/smtp_config.php` — cả hai đều gitignore (repo `kt-soft-web` là
repo PUBLIC) và đã xác nhận không lộ qua HTTP.

## Thanh toán online VNPay + IPN

Giá QLBH-CLOUD hiện tư vấn theo quy mô từng khách, không có bảng giá cố định, nên không làm nút
"mua ngay giá X". Thay vào đó: sau khi thống nhất giá qua điện thoại/Zalo, chủ hệ thống vào
`super_admin_tenants.php` tạo link thanh toán riêng (nhập số tiền + số tháng) rồi gửi khách. Khách
thanh toán xong, hệ thống tự nâng cấp tenant lên `PAID` và cộng dồn `paid_until`.

Hai vấn đề được phát hiện khi tự soi lại code vừa viết:

1. **Thiếu IPN.** Ban đầu chỉ có `vnpay_return.php` (VNPay chuyển hướng *trình duyệt khách* về).
   Nếu khách trả tiền xong rồi đóng tab hoặc rớt mạng trước khi bị chuyển về thì đơn kẹt ở
   `PENDING` vĩnh viễn — **tiền đã trừ nhưng gói không kích hoạt**, đúng kiểu lỗi tệ nhất với hệ
   thống thanh toán. Đã bổ sung `vnpay_ipn.php`: VNPay gọi thẳng server-to-server nên không phụ
   thuộc trình duyệt khách; có đối chiếu lại số tiền với đơn gốc theo khuyến nghị VNPay, và chặn
   cộng dồn thời hạn 2 lần khi VNPay gọi IPN lặp lại.
2. **Khoảng trắng trong `vnp_OrderInfo`.** PHP giải mã `$_GET` rồi ta mã hóa lại để dựng chuỗi ký;
   khoảng trắng có thể đi ra dạng `+` nhưng quay về thành `%20`, làm chữ ký lệch và **từ chối oan
   giao dịch hợp lệ**. Đã bỏ khoảng trắng khỏi `OrderInfo`.

Cũng trong đợt này, bảng mới suýt đặt tên `payments` — trùng với bảng `payments` đã có sẵn (ghi
nhận thanh toán đơn hàng POS). Vì dùng `CREATE TABLE IF NOT EXISTS` nên migration **im lặng không
làm gì**, và lỗi chỉ lộ ra lúc chạy thật (`Unknown column 'p.order_code'`). Đã đổi tên thành
`subscription_payments`. Đã kiểm chứng: gửi tham số giả mạo `vnp_ResponseCode=00` kèm chữ ký sai
thì cả `vnpay_return.php` lẫn `vnpay_ipn.php` đều từ chối đúng.

## Nhóm tính năng giữ chân khách hàng + trang trạng thái

- **Email nhắc còn 3 ngày hết hạn dùng thử** và **báo cáo doanh thu hàng tuần**: hosting không có
  SSH/cron riêng nên không đặt lịch được — thay vào đó kiểm tra ngay trên request của chính người
  dùng khi họ mở app (đánh dấu qua `tenants.trial_reminder_sent_at` và `last_weekly_report_at` để
  chỉ gửi 1 lần/kỳ). Báo cáo tuần chỉ gửi khi tuần đó thực sự có đơn hàng, tránh làm phiền bằng
  email rỗng.
- **Checklist làm quen** trên dashboard cho ADMIN/MANAGER (thêm sản phẩm → tạo đơn thử → thêm nhân
  viên), tự biến mất vĩnh viễn khi cả 3 bước đã xong — không cần lưu trạng thái "đã ẩn" riêng vì
  dữ liệu thật đã chứng minh họ đang dùng.
- **`backup_cron.php`**: endpoint bảo vệ bằng token bí mật riêng (không liên quan phiên đăng nhập)
  để hosting đặt lịch gọi hàng ngày, gọi lại `createBackup()` đã có sẵn.
- **`status.php`** (bên kt-soft.vn): trang trạng thái công khai, kiểm tra trực tiếp lúc tải trang
  (không lưu lịch sử) — website và kết nối DB của QLBH-CLOUD kèm thời gian phản hồi.
- **Menu Nhân sự** bổ sung lối tắt "Nhân viên & phân quyền" (trước đây chỉ vào được qua Cấu hình).

Nhân tiện sửa một lỗi có sẵn: `maybeSendTrialReminder()` dùng `$tenant['name']` nhưng câu `SELECT`
không lấy cột `name` — email nhắc hết hạn bị thiếu tên cửa hàng.

## Kiểm tra sức khỏe dữ liệu thật: 100% khách đăng ký chưa từng quay lại

Khi rà soát, thay vì chỉ đọc code, đã truy vấn dữ liệu thật trên production:

| Khách hàng | Đăng ký | Số lần hoạt động | Sản phẩm | Đơn hàng |
|---|---|---|---|---|
| PHƯỚC TẤN TÂY NGUYÊN | 15/09/2026 | 1 (chính là lúc đăng ký) | 0 | 0 |
| HKD Chung Hiếu | 17/09/2026 | 1 (chính là lúc đăng ký) | 0 | 0 |

Cả hai đăng ký xong **chưa từng đăng nhập lần thứ hai**. Toàn hệ thống: 0 sản phẩm, 0 khách hàng,
0 đơn hàng. Nghĩa là vấn đề lớn nhất hiện tại không nằm ở thiếu tính năng hay lỗ hổng kỹ thuật, mà
ở chỗ khách đăng ký xong thì bỏ đi — và checklist onboarding cũng không cứu được vì họ không quay
lại để nhìn thấy nó. Việc đáng làm nhất là liên hệ trực tiếp hai khách này để hiểu lý do.

Cũng đã kiểm tra tính toàn vẹn bản sao lưu (việc thường bị bỏ sót): tải bản `.sql.gz` mới nhất về,
giải nén và xác nhận có đủ 58 bảng kèm dữ liệu thật — backup hoạt động đúng, không phải file rỗng.

## Cổng kiểm tra tenant dùng chung (`layBanGhiCuaToi()`)

Ba vòng rà soát liên tiếp đều tìm ra **cùng một loại lỗi**: nhận ID từ người dùng (POST/GET) rồi
truy vấn `WHERE id = ?` mà quên kiểm tra bản ghi đó thuộc cửa hàng nào. Hậu quả đã tái hiện được
trên production: bơm đơn hàng/phiếu thu giả vào sổ sách cửa hàng khác, xóa công nợ nhà cung cấp
của họ, ép nhận đơn đặt hàng của họ (ghi đè cả giá vốn sản phẩm). Vá xong chỗ này thì lần sau lại
sót chỗ khác — vì việc kiểm tra được **viết lại thủ công ở từng file**.

Để chặn tận gốc, mọi chỗ nhận ID từ người dùng giờ đi qua một cổng duy nhất trong
`inc_functions.php`:

```php
$order = layDonHangCuaToi($orderId);
if (!$order) { redirect('orders.php'); }   // không tồn tại HOẶC không phải của mình
```

**Nguyên tắc**: không bao giờ viết `SELECT ... FROM <bảng> WHERE id = ?` trực tiếp với ID đến từ
người dùng. Luôn dùng `layBanGhiCuaToi('<bảng>', $id)` hoặc các hàm gọi tắt
(`layDonHangCuaToi`, `layPhieuNhapCuaToi`, `layDonDatHangCuaToi`, `layPhieuKiemHangCuaToi`,
`layPhieuChuyenHangCuaToi`, `laySanPhamCuaToi`, `layKhachHangCuaToi`, `layNhaCungCapCuaToi`,
`layChiNhanhCuaToi`).

Cách mỗi bảng nối về tenant được khai báo **tập trung một chỗ** trong `layBanGhiCuaToi()`, nên khi
viết code mới không cần nhớ bảng nào có `tenant_id` trực tiếp, bảng nào phải `JOIN branches`, bảng
nào đi qua `orders`:

| Nhóm | Cách xác định tenant | Ví dụ bảng |
|---|---|---|
| Có cột `tenant_id` | `WHERE tenant_id = ?` | `products`, `customers`, `suppliers`, `branches`, `coupons`, `gifts`, `promotions`, `price_lists` |
| Qua chi nhánh | `JOIN branches ON ... AND b.tenant_id = ?` | `orders`, `stock_receipts`, `purchase_orders`, `stock_takes`, `inventory`, `cashbook_entries` |
| Qua chi nhánh gửi | `JOIN branches ON b.id = from_branch_id` | `stock_transfers` |
| Qua đơn hàng | `JOIN orders → branches` | `shipments`, `order_returns` |

Gọi với tên bảng chưa khai báo sẽ **ném `InvalidArgumentException`** thay vì âm thầm trả về
`null` — để không vô tình tạo ra một chỗ "luôn không tìm thấy" mà không ai phát hiện.

Đã chuyển 8 điểm sang dùng cổng này (`order_advance`, `order_cancel`, `order_pay`, `order_revert`,
`stock_receipt_pay`, `purchase_order_receive`, `stock_take_balance`, `pos_switch_branch`) và kiểm
chứng từng cái trên production với 2 cửa hàng thật: thao tác trên dữ liệu của mình vẫn chạy đúng
(hoàn tác đơn COMPLETED→SHIPPED, trả nợ phiếu nhập 0→50.000, công nợ NCC 300.000→250.000), còn
thao tác trên dữ liệu cửa hàng khác thì không suy chuyển gì.

## Kiểm thử tự động cách ly dữ liệu (`test_isolation.php`)

Rà soát bằng tay chỉ bắt được những gì người rà soát **nghĩ ra**. File `test_isolation.php` biến
việc đó thành một lệnh chạy được, và bắt cả những chỗ phát sinh về sau:

```
https://app.kt-soft.vn/test_isolation.php?key=<TEST_SECRET>
```

`TEST_SECRET` khai báo trong `config.php`; để trống là tắt hẳn (trả 403). **Nên chạy trước mỗi lần
deploy** thay đổi có đụng tới truy vấn dữ liệu.

Script tự tạo 2 cửa hàng tạm A và B kèm dữ liệu mẫu đủ loại (chi nhánh, sản phẩm, tồn kho, khách
hàng, NCC, phiếu nhập còn nợ, đơn đặt hàng, đơn bán chịu, phiếu kiểm hàng, quà tặng, khuyến mại,
bảng giá), **đăng nhập thật qua HTTP** bằng tài khoản của A, rồi lần lượt thử truy cập dữ liệu của
B — sau đó tự dọn sạch. Nó chỉ xóa đúng 2 tenant nó vừa tạo (nhớ id ngay từ đầu), không bao giờ
đụng tới dữ liệu khách hàng thật.

Ba loại phép thử, 45 phép tất cả:

| Loại | Kiểm tra gì | Vì sao cần |
|---|---|---|
| Ghi dữ liệu | Chuyển POS sang chi nhánh của B rồi bán hàng, trả nợ phiếu nhập của B, xóa công nợ NCC của B, ép nhận đơn đặt hàng của B, hoàn tác/hủy/thu tiền đơn của B, cân bằng phiếu kiểm hàng của B | Đây đúng là 3 lỗ hổng thật đã tìm ra trong các vòng rà soát trước |
| Đọc dữ liệu | Mở thẳng bằng ID các trang chi tiết của B; 11 trang danh sách không được lẫn dữ liệu của B | Rò rỉ kiểu "xem trộm" khó thấy hơn nhưng vẫn là lộ dữ liệu công ty khác |
| **Đối chiếu dương** | Chính A vẫn xem được / thao tác được trên dữ liệu **của chính mình** | Quan trọng nhất: nếu không có, một trang lỗi hay một bản vá quá tay (chặn nhầm cả chủ sở hữu) sẽ khiến **mọi** phép thử "không thấy dữ liệu của B" đều đạt một cách vô nghĩa |

Không kiểm tra mã HTTP trả về, vì hầu hết endpoint đều trả 302 dù thành công hay bị chặn — mà
**đối chiếu thẳng hiệu ứng thực tế trên CSDL** (số tiền đã trả, công nợ, trạng thái đơn, số đơn của
B trước/sau).

Chạy 0 phép thử (vd lỗi kết nối) được báo là **HỎNG**, không phải "đạt hết" — kèm mã HTTP 500 để
có thể nối vào quy trình deploy tự động.

Lưu ý khi sửa script: LiteSpeed chặn user-agent mặc định của cURL bằng 403, nên mọi request nội bộ
đều phải khai báo `CURLOPT_USERAGENT` (hằng `TEST_UA`).

Hai lưu ý nữa rút ra khi mở rộng script:

- **Lấy token CSRF từ trang nào cũng quan trọng.** Ô `csrf` của `order_return_form.php` chỉ hiện ra
  sau khi form đã tìm được đơn hàng, nên lấy token từ chính trang đó sẽ ra chuỗi rỗng, request bị
  chặn vì CSRF, và phép thử "đạt" mà **chưa hề chạm tới hàng rào tenant**. Token là của phiên chứ
  không của từng form, nên script lấy một lần từ `orders.php` rồi dùng chung.
- **Phép thử chuyển hàng chéo hiện còn yếu.** Nó đạt cả khi chưa vá, vì sản phẩm của cửa hàng khác
  không có tồn kho ở chi nhánh mình nên lệnh chuyển tự hỏng vì thiếu hàng. Bản vá vẫn cần (chặn từ
  gốc thay vì dựa vào một tác dụng phụ), nhưng đừng coi phép thử đó là bằng chứng mạnh.

## Chống tạo hàng loạt cửa hàng ảo (`dang-ky.php`)

Token CSRF lấy được chỉ bằng một request GET, nên **một mình nó không chặn được gì** — trước đây
một script có thể tạo vô hạn cửa hàng, làm phình CSDL dùng chung và rác danh sách quản trị hệ thống.

Nay dùng lại bộ đếm theo IP của trang đăng nhập (bảng `login_attempts`), nhưng với hành động
`signup` và đếm **lần thành công** chứ không phải lần thất bại: mỗi IP tạo tối đa `RATE_LIMIT_MAX`
(5) cửa hàng rồi bị khóa `RATE_LIMIT_LOCK_SECONDS` (15 phút). Người dùng thật chỉ đăng ký một lần
nên hạn này rất rộng với họ.

Đã kiểm chứng trên production: 5 lần đầu tạo được, lần thứ 6 bị chặn đúng thông báo — sau đó xóa
sạch 5 cửa hàng thử và gỡ khóa.

## Thư mục ảnh tải lên không chạy được script (`uploads/.htaccess`)

Đường tải ảnh vốn đã an toàn: kiểu file lấy từ MIME do **máy chủ tự đọc** (không tin phần mở rộng
người dùng gửi), đuôi file ép theo bảng trắng, tên file do hệ thống sinh ngẫu nhiên. `uploads/` nay
thêm lớp thứ hai chặn truy cập mọi file `.php/.phtml/.phar/.pl/.py/.cgi/.sh`, phòng khi về sau có
ai nới lỏng chỗ trên.

Chỉ dùng `<FilesMatch>` — `Options`/`AddType` có thể bị cấm ở cấp thư mục và gây lỗi 500 cho cả thư
mục ảnh. Đã kiểm chứng bằng cách đặt thật một file `.php` vào đó: trả **403**, không thực thi; file
`.jpg` vẫn phục vụ bình thường; sau đó xóa file thử.

## Thanh thanh toán dính đáy màn hình ở POS

Đóng vai chủ cửa hàng mới, đăng ký rồi tự đi hết luồng bán hàng, đo được con số cụ thể:

| | Chiều cao màn hình | Vị trí nút Thanh toán | |
|---|---|---|---|
| Máy tính | 768px | 963px | khuất **195px** |
| Điện thoại | 812px | 1086px | khuất **274px** |

Thao tác lặp lại nhiều nhất trong ngày **không bao giờ nhìn thấy được** — phải cuộn mỗi lần bán.
Máy tính còn có phím **F1** cứu, nhưng dòng nhắc phím tắt cũng nằm dưới đáy nên người mới không
biết; **điện thoại thì không có phím tắt nào**.

Điều đáng nói: `pos.php` đã có sẵn cơ chế gom 15 nút phụ vào "⚙️ Chức năng khác", kèm bình luận ghi
đúng vấn đề này — nhưng nó chỉ bật ở `max-width: 900px`, tức là theo **chiều ngang**. Ràng buộc
thật lại nằm ở **chiều cao**: laptop 1366×768 ở cửa hàng thừa chiều ngang (nên 15 nút bung hết)
nhưng thiếu chiều cao. Vì vậy cách thu gọn theo bề ngang không giải quyết được.

Cách xử lý: tách hàng "Tổng tiền" + nút Thanh toán ra thành `#pos-pay-bar` **cố định ở đáy màn
hình** cho mọi kích thước — `left: 250px` để không đè thanh bên, và `left: 0` dưới 860px khi thanh
bên chuyển sang chế độ trượt. `.content` thêm `padding-bottom: 96px` để không che nút "Đặt hàng —
xử lý sau" và dòng gợi ý phím tắt.

Đã nghiệm thu bằng số sau khi sửa: máy tính nút ở 716–756px / màn hình 768px, điện thoại 763–803px
/ 812px — **nằm trọn trong màn hình, không phải cuộn**; nút "Đặt hàng" bên dưới không bị che; tổng
tiền cập nhật trực tiếp trên thanh; và bán thật một đơn qua nút đó thành công.

## Ba điểm cản người mới khác đã sửa cùng đợt

- **`product_form.php`**: "Tên sản phẩm" vốn là ô **thứ ba**, sau "Mã SKU" và "Mã vạch". Khi tự
  đóng vai người dùng tôi đã nhập nhầm tên hàng vào ô mã vạch ngay lần đầu. Đã đưa "Tên sản phẩm"
  lên **ô đầu tiên**, thêm placeholder `vd: Nước ngọt Coca 330ml` để không thể nhầm với hai ô mã.
- **`index.php`**: bước 1 của checklist ghi "hoặc nhập từ file Excel" nhưng **chỉ** dẫn vào trang
  Excel, khiến người chỉ có vài mặt hàng muốn gõ tay phải tự mò ngược ra. Nay trỏ về
  `products.php` — nơi có sẵn **cả hai** nút.
- **`products.php`**: danh sách rỗng chỉ nói "Chưa có sản phẩm nào." Nay chỉ thẳng hai lối đi kèm
  liên kết.

### Nhãn `<label>` bấm được (đã làm nốt)

Trước đây nhãn trong `product_form.php` không gắn `for`/`id` với ô nhập, nên bấm vào chữ "Giá bán"
không đưa con trỏ vào ô — người dùng phải bấm trúng đúng ô, vùng bấm nhỏ hơn hẳn, bất tiện nhất
trên điện thoại và với người lớn tuổi.

Đã gắn `for`/`id` cho **23 cặp** trên cả ba form của trang: form sản phẩm chính, form thêm biến thể
(tiền tố `v-`) và form thêm sản phẩm vào combo (tiền tố `c-`).

Hai chỗ cần xử lý riêng:

- **Khối "Giá riêng theo bảng giá"** có một nhãn đứng trên *nhiều* ô nhập, mà một `for` chỉ trỏ
  được tới một ô. Nhãn tổng nay trỏ tới ô đầu tiên, còn **tên từng bảng giá** đổi từ `<span>` thành
  `<label for="f-pl-<id>">` riêng — bấm vào tên bảng giá nào là vào đúng ô của bảng giá đó. Thêm
  `margin-bottom: 0` để không lệch 4px so với quy tắc `label` chung trong `inc_header.php`.
- **Ô Mã SKU** có hai nhánh (bị khóa khi sửa / nhập được khi tạo mới). Chỉ một nhánh được render
  nên hai nhánh dùng chung `id="f-sku"`, không bao giờ trùng nhau trên cùng trang.

**Không đụng tới** 6 nhãn vốn đã *bọc* luôn ô nhập bên trong (`Đang bán`, `Áp dụng bảo hành`, và
các ô `SL:` / `Tối thiểu:` / `Tối đa:` / `Vị trí kho:` trong bảng tồn kho theo chi nhánh) — dạng này
đã bấm được sẵn, và chúng nằm trong vòng lặp nên gắn `id` sẽ sinh ra id trùng.

Đã kiểm chứng bằng cách tải HTML thật của cả 4 trạng thái trang (tạo mới, sửa hàng thường, sửa hàng
combo, có bảng giá) rồi soi: **không nhãn nào trỏ tới `id` không tồn tại, không `id` nào bị trùng**
(kể cả `f-pl-29`/`f-pl-30` sinh trong vòng lặp), và lưu lại sản phẩm vẫn đúng toàn bộ giá trị — kể
cả hai mức giá theo bảng giá.

## Đặt lại hạn dùng thử theo ngày cụ thể (`super_admin_tenants.php`)

Trang quản trị hệ thống chỉ có nút **"+1 năm"**, và trong code số ngày bị ép `max(1, ...)` nên nó
**chỉ cộng được, không bao giờ lùi**. Bấm nhầm là không sửa lại được từ giao diện — thực tế đã xảy
ra: một cửa hàng bị bấm hai lần, hạn nhảy từ 17/09/2027 lên 16/09/2029 (1091 ngày).

Thêm hành động `set_trial_end`: một ô chọn ngày điền sẵn hạn hiện tại, bấm "Đặt hạn" là ghi thẳng
về đúng ngày đó (`23:59:59`). **Cho phép cả ngày trong quá khứ** — đó là cách kết thúc dùng thử
ngay lập tức. Ngày sai định dạng bị từ chối và báo rõ, không thay đổi gì.

Cột "HẠN DÙNG THỬ" nay hiện thêm **ngày hết hạn cụ thể** dưới số ngày còn lại. Chỉ nhìn "Còn 1091
ngày" thì rất khó nhận ra vừa bấm nhầm; thấy "16/09/2029" thì nhận ra ngay.

**Chốt chặn gói trả phí**: đặt hạn dùng thử sẽ chuyển `plan` về `TRIAL`, nên nếu áp nhầm cho khách
**đã trả phí** thì vô tình hạ họ xuống dùng thử. Ô chọn ngày được ẩn với khách `PAID`, **và** chặn
thêm một lần nữa ở tầng xử lý (ẩn nút không phải là kiểm soát).

Đã kiểm chứng bằng tài khoản quản trị tạm trong tenant #1 (tạo, thử, xóa ngay): trang không lỗi
PHP; lùi hạn về quá khứ **được**; đặt tiến **được**; ngày sai định dạng **bị từ chối**; chuyển cửa
hàng sang `PAID` thì ô chọn ngày biến mất và POST thủ công cũng bị chặn, dữ liệu không đổi.

Nút **"+1 năm"** cũng đặt `plan='TRIAL'` nên có đúng cùng vấn đề, và đã được chặn cùng cách: ẩn với
khách `PAID` trên giao diện, và chặn ở tầng xử lý. Chốt chặn của **cả hai** thao tác nay gộp vào
một chỗ duy nhất, đặt trước khi phân nhánh hành động — để lần sau thêm thao tác gia hạn mới thì chỉ
cần thêm tên nó vào một danh sách, thay vì nhớ chép lại đoạn kiểm tra.

"+1 năm" nay cũng hỏi xác nhận trước khi bấm, kèm nhắc rằng nếu lỡ tay thì dùng ô chọn ngày bên
cạnh để đặt lại.

Đã kiểm chứng: khi còn `TRIAL` thì "+1 năm" chạy bình thường (31/12/2026 → 31/12/2027); chuyển cửa
hàng sang `PAID` thì nút biến mất khỏi trang **và** gửi POST thủ công vẫn bị chặn, hạn lẫn gói đều
không đổi.

### Gia hạn thủ công cho khách đã trả phí (đã bổ sung)

Trước đây `paid_until` **chỉ** được đặt qua thanh toán VNPay, nên khách gia hạn bằng chuyển khoản
tay thì không xử lý được trên trang. Nay khách `PAID` có ba thao tác:

| Nút | Làm gì | Khi nào hiện |
|---|---|---|
| **+12 tháng** | Cộng 12 tháng vào `paid_until` | chỉ khi đã có hạn |
| **Đặt hạn** | Đặt `paid_until` về đúng ngày chọn | luôn hiện |
| **Bỏ hạn** | Đặt `paid_until = NULL` (dùng vô thời hạn) | chỉ khi đang có hạn |

"+12 tháng" dùng **đúng công thức của `vnpay_ipn.php`** — `DATE_ADD(GREATEST(COALESCE(paid_until,
NOW()), NOW()), INTERVAL ? MONTH)` — để gia hạn tay và gia hạn online cho ra cùng kết quả: cộng
tiếp từ hạn cũ nếu còn hiệu lực, cộng từ hôm nay nếu đã hết hạn.

**Cái bẫy quan trọng nhất ở đây**: `paid_until = NULL` nghĩa là **không giới hạn**, nên cộng thêm
tháng vào đó sẽ biến "không giới hạn" thành "có hạn" — đúng điều ngược với ý người bấm. Vì vậy
"+12 tháng" bị **ẩn** với khách đang không giới hạn, **và** bị chặn ở tầng xử lý. Muốn đặt hạn cho
khách đang không giới hạn thì phải dùng ô chọn ngày — một việc có chủ ý, không phải lỡ tay.

Chốt chặn nay chặn **đúng chiều cho từng nhóm**, gộp ở một chỗ trước khi phân nhánh: nhóm dùng thử
(`extend_trial`, `set_trial_end`) chặn với khách `PAID`; nhóm trả phí (`extend_paid`,
`set_paid_until`) chặn với khách `TRIAL`. Mỗi trường hợp có thông báo riêng nói rõ nên dùng nhóm
nút nào.

Cột đổi tên thành **"Hạn sử dụng"** (trước là "Hạn dùng thử") vì giờ quản lý cả hai loại, và hiện
thêm ngày hết hạn cụ thể cho cả khách trả phí.

Đã kiểm chứng bằng 3 cửa hàng tạm — trả phí có hạn / trả phí không giới hạn / dùng thử — với 7 phép
thử: cộng tháng đúng số học (01/01/2027 + 12 tháng = 01/01/2028, khớp VNPay), đặt hạn được, bỏ hạn
được, và **cả 3 trường hợp ap sai nhóm đều bị chặn** (cộng tháng cho khách không giới hạn, cộng
tháng cho khách dùng thử, đặt `paid_until` cho khách dùng thử). Giao diện cũng hiện đúng nút theo
từng loại gói.

## Bù các khoảng trống so với đặc tả (`docs/feature-spec.md` của QLBH2-SOFT)

Đối chiếu đặc tả với mã nguồn thật tìm ra 11 khoảng trống. Đã làm 8, còn 3 bị chặn bởi yếu tố
ngoài (xem cuối mục).

| Đặc tả | Đã làm |
|---|---|
| §4.1 Form tạo đơn ngoài POS | `order_form.php` — đơn giao hàng nhập tay |
| §4.2 Xuất file đơn hàng | `orders_export.php` — giữ nguyên bộ lọc đang xem |
| §4.3 Sao chép đơn | `order_copy.php` — nạp lại vào form để xem trước |
| §3 Chọn nhân viên bán | ô chọn trên POS, chỉ ADMIN/MANAGER |
| §5+§14 Cấu hình giao hàng | `shipping_settings.php` — biểu phí theo khu vực, tự điền |
| §5 Tổng quan vận chuyển | dải đếm vận đơn theo trạng thái trên `shipments.php` |
| §8 Cấu hình kênh marketing | `marketing_settings.php` |
| §9 Danh sách yêu cầu bảo hành | `warranty_claims.php` — kèm bộ đếm theo trạng thái |

### Quyết định quan trọng: KHÔNG nhân bản logic tạo đơn

`order_form.php` **không tự viết** phần tạo đơn mà gửi dữ liệu sang chính `pos_checkout.php`
(kèm `draft=1`, `is_delivery=1`). Lý do: endpoint đó đã xử lý combo, biến thể, sàn giá, bảng giá
theo nhóm khách, khuyến mại, mã giảm giá, trừ tồn kho có khóa dòng, sổ quỹ, phiếu bảo hành và điểm
tích lũy — **và đã được vá nhiều lỗi thật**. Một bản sao chắc chắn sẽ thiếu sót.

`order_copy.php` cũng theo nguyên tắc đó: nó không tạo đơn mới mà nạp nội dung đơn cũ vào form để
người dùng xem lại (hàng có thể đã hết, giá có thể đã đổi) rồi mới đi qua đúng luồng trên.

Đổi lại, `pos_checkout.php` được bổ sung 4 tham số — `customer_name`, `channel_id`, `sold_by_id`,
`source` — đều **lọc theo tenant** như mọi ID nhận từ người dùng. Riêng `sold_by_id` chỉ ADMIN/
MANAGER được đặt khác chính mình (ảnh hưởng trực tiếp tới hoa hồng và bảng lương), thu ngân luôn
ghi chính mình.

### Điểm cần biết khi đọc lại

- Luồng trạng thái đơn **không trừ kho ở bất kỳ bước nào** (`order_advance.php`): kho bị trừ ngay
  lúc tạo đơn và được hoàn lại khi hủy (`order_cancel.php`). Đơn giao hàng tạo từ `order_form.php`
  vì vậy cũng trừ kho ngay, nếu không thì hàng sẽ không bao giờ bị trừ.
- Biểu phí giao hàng lưu JSON trong `store_settings` chứ không tạo bảng mới — biểu phí của một cửa
  hàng nhỏ chỉ vài dòng, không đáng để thêm bảng + migration trên CSDL đang chạy thật.
- `marketing_settings.php` chỉ **khai báo** kênh, phần mềm không tự gửi SMS/Email hàng loạt. Trang
  nói thẳng điều này: gửi thật cần brandname đăng ký với nhà mạng và tên miền xác thực SPF/DKIM;
  gửi khi chưa có thì tin vào hộp thư rác và tên miền bị đánh dấu.

### Ba mục chưa làm và lý do

| Đặc tả | Vì sao chưa |
|---|---|
| §5 Kết nối API hãng vận chuyển | Cần hợp đồng + API key của GHN/GHTK/ViettelPost. Hiện gõ tay tên hãng và mã vận đơn. |
| §12 Hóa đơn điện tử / khai thuế | Cần hợp đồng với nhà cung cấp hóa đơn điện tử. `accounting.php` đã có ước tính thuế hộ kinh doanh và các sổ theo thông tư. |
| §12 Marketplace ứng dụng | Chợ ứng dụng mở rộng — chỉ có nghĩa khi đã có hệ sinh thái nhà phát triển bên thứ ba. |

## Ghi chú "Điều kiện triển khai" hiện ngay trên từng chức năng

Vấn đề thật: một số chức năng chỉ là **khung nội bộ** — muốn chạy tự động thì khách phải có thứ gì
đó ở bên ngoài (hợp đồng hãng vận chuyển, tài khoản hóa đơn điện tử, brandname SMS...). Nếu không
nói rõ **ngay tại chỗ**, khách sẽ tưởng đã dùng được rồi và chỉ phát hiện khi cần gấp.

Hàm dùng chung `hopDieuKienTrienKhai()` trong `inc_functions.php` vẽ một hộp thống nhất gồm ba
phần: **phần mềm hiện làm được gì** (nói trước, để không bị hiểu nhầm là "chưa có gì"), **cần thêm
gì để chạy thật**, và một dòng kết. Đã gắn vào 6 trang:

| Trang | Nội dung |
|---|---|
| `shipments.php`, `shipment_form.php` | Theo dõi vận đơn + đối soát COD đầy đủ, nhưng nhập tay — cần hợp đồng và API key hãng vận chuyển |
| `accounting.php` | Đã có ước tính thuế và các sổ theo thông tư — riêng phát hành hóa đơn điện tử cần nhà cung cấp được Tổng cục Thuế công nhận |
| `campaigns.php`, `marketing_settings.php` | Lưu chiến dịch, chưa tự gửi — SMS cần brandname đăng ký với nhà mạng, email cần tên miền có SPF/DKIM |
| `channels.php` | Thống kê doanh thu theo kênh, chưa nối API sàn — mỗi sàn duyệt quyền riêng |
| `online_shop_settings.php` | **Chạy thật ngay**, chỉ thiếu thanh toán trước |

Ngoài ra `huong_dan.php` có thêm **mục 13 "Chức năng cần chuẩn bị thêm mới chạy thật được"** —
bảng tổng hợp 5 dòng để khách nắm toàn cảnh mà không phải mở từng trang. Các mục sau đó được đánh
số lại (Cấu hình 13→14, Câu hỏi thường gặp 14→15) cho khỏi trùng.

Giọng của các ghi chú cố ý **nói cái được trước, cái thiếu sau**, và luôn kèm câu "chưa có thì vẫn
dùng bình thường theo cách nhập tay" — vì phần lớn các chức năng này thật sự đã dùng được, chỉ là
chưa tự động.

## Vai trò tùy chỉnh — phân quyền theo từng khu vực chức năng (`custom_roles.php`)

Trước đây chỉ có 3 vai trò cố định: ADMIN (toàn quyền), MANAGER (mọi nghiệp vụ trừ cấu hình hệ
thống), CASHIER (chỉ bán hàng/đơn hàng/khách hàng + xem sản phẩm-kho). Không có vai trò trung
gian — muốn giao việc sổ quỹ cho một người mà không cho họ đụng vào kho/nhân sự là không làm được,
chỉ có "được hết" (MANAGER) hoặc "gần như không được gì" (CASHIER).

**Thiết kế**: 7 "nhóm quyền" cố định (`pos`, `products`, `customers`, `marketing`, `warranty`,
`finance`, `hr`), khớp đúng các nhóm menu đã có sẵn trong `inc_header.php`. ADMIN tạo vai trò tùy
chỉnh (vd "Thủ kho", "Thủ quỹ") bằng cách tích chọn những nhóm nào vai trò đó được thao tác, rồi
gán vai trò đó cho nhân viên ở `users.php` như gán vai trò thường.

**Cách hoạt động phía trong** — cố tình chọn theo hướng **không đổi 102 điểm gọi có sẵn**:
- 133 lượt gọi `requireRole()`/`hasRole()` rải khắp 91 file hóa ra chỉ có 2 dạng: `('ADMIN')` một
  mình (dành cho cấu hình/sao lưu/quản trị hệ thống — **tuyệt đối không đụng tới**) và
  `('ADMIN', 'MANAGER')` (dành cho phần lớn thao tác nghiệp vụ).
- `PERMISSION_GROUP_FILES` trong `inc_functions.php` ánh xạ tên file → nhóm quyền (vd
  `cashbook.php` → `finance`). `hasRole()` được mở rộng: khi bị hỏi `'MANAGER'` mà role thật
  không khớp, kiểm thêm — nếu user có `custom_role_id` và vai trò đó chứa đúng nhóm quyền của
  **file đang chạy** (`detectPagePermissionGroup()` đọc `SCRIPT_NAME`) thì vẫn cho qua.
- Nhờ vậy **không phải sửa một dòng nào** trong 91 file gốc — toàn bộ thay đổi nằm ở
  `inc_functions.php` + `inc_auth.php`. `requireRole()` trước đây tự so sánh `$user['role']`
  riêng, không hề gọi `hasRole()` — phải sửa lại để gọi chung, nếu không phần mở rộng vô nghĩa.
- File không được liệt kê trong `PERMISSION_GROUP_FILES` (vd `users.php`) mặc định **vẫn chỉ**
  ADMIN/MANAGER thật vào được — an toàn theo mặc định, không phải khai trắng danh sách chặn.

**Chặn leo thang quyền**: vai trò tùy chỉnh khi gán cho ai đó luôn ép `role = 'CASHIER'` (không
bao giờ là ADMIN/MANAGER thật), và phần mở rộng trong `hasRole()` chỉ kích hoạt với yêu cầu kèm
`'MANAGER'` — không bao giờ với `'ADMIN'` một mình. Vì vậy `users.php`, `settings.php`,
`backup.php`, `custom_roles.php` chính nó... tuyệt đối không nằm trong `PERMISSION_GROUP_FILES`,
để một "Thủ kho" không thể tự tạo tài khoản ADMIN mới hay tự nâng quyền. `custom_roles.php` cũng
chỉ chấp nhận `requireRole('ADMIN')` (không phải `'ADMIN','MANAGER'`) — MANAGER thật cũng không
sửa được quyền vai trò tùy chỉnh, tránh một MANAGER tự tạo vai trò "được hết" rồi gán cho mình.

**Thu hồi quyền có hiệu lực ngay** request kế tiếp (không cần đăng xuất): `refreshUserSession()`
vốn đã đồng bộ lại `role`/`branch_id` mỗi request từ DB — nay đồng bộ thêm `custom_role_id`.

**Menu**: `inc_header.php` trước đây dùng 1 biến `$isManagerUp` chung để bật/tắt cả cụm menu —
không dùng được cho vai trò tùy chỉnh vì mỗi nhóm cần bật/tắt độc lập. Thêm `$canGroup()` xét
đúng từng nhóm; tách riêng `users.php` ra khỏi mục "Nhân sự" để chỉ hiện với ADMIN thật (trước
đây MANAGER thật cũng thấy mục này trong menu nhưng bấm vào bị chặn 403 — lỗi có sẵn, tiện sửa
luôn cho nhất quán khi tách nhóm).

**Kiểm chứng đầy đủ trên production** bằng 1 cửa hàng thử: tạo vai trò "Thủ kho" (chỉ nhóm
`products`), gán cho 1 nhân viên, đăng nhập thật rồi kiểm — vào được `stock_takes.php`,
`suppliers.php`, `purchase_orders.php`...; **bị chặn 403** ở `cashbook.php`, `reports.php`,
`payroll.php`, **và cả `users.php`/`custom_roles.php`** (xác nhận không leo thang được); tạo
danh mục sản phẩm thật **ghi được vào CSDL** (không chỉ mở trang được); gửi thẳng POST giả mạo
vào `cashbook.php` để bơm phiếu thu 999.999đ — **bị chặn**, không chỉ ẩn nút trên giao diện. Gỡ
quyền `products` khỏi vai trò xong thử lại ngay — **bị chặn ở request kế tiếp**, không cần đăng
xuất. Xóa vai trò — nhân viên tự động **về lại CASHIER thường**, không khóa tài khoản, không lỗi
ngầm. Tài khoản ADMIN thật vẫn thấy đủ mọi menu như cũ, 0 lỗi PHP.

Bổ sung `custom_roles` vào `test_isolation.php` (45 → **50 phép thử**): sửa/xóa vai trò của cửa
hàng khác qua POST trực tiếp đều bị chặn, sửa vai trò của chính mình vẫn chạy, và trang danh sách
không rò rỉ tên vai trò của cửa hàng khác.

Di trú CSDL qua `fix_custom_roles.php` (bảng `custom_roles` + cột `users.custom_role_id`, đúng
mẫu `fix_*.php` đã dùng suốt dự án) — idempotent, đã chạy và xóa khỏi máy chủ.
