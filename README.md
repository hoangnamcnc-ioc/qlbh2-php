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

Giới hạn: giá theo bảng giá hiện chỉ áp cho sản phẩm gốc (chưa hỗ trợ giá riêng theo biến thể); POS
chưa tự động chọn bảng giá theo khách hàng khi nhập SĐT (cần bổ sung nếu có nhu cầu sử dụng ngay).
