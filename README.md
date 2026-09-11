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
