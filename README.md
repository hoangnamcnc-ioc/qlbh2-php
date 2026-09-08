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

Chưa có: Vận chuyển, Marketing, Bảo hành, Kế toán/Thuế, Khuyến mại/coupon, Đặt hàng nhập (trước khi
nhập kho thực tế), Điều chỉnh giá vốn riêng, xuất/nhập file Excel, sửa đơn hàng (chỉ hủy được).

**Lưu ý kỹ thuật khi migrate DB có dữ liệu cũ**: nếu nâng cấp từ bản trước khi có biến thể, bảng
`inventory` có unique key cũ `(branch_id, product_id)` được ràng buộc bởi FK — cần thêm index phụ
trên `product_id` trước khi xóa key cũ để đổi sang `(branch_id, product_id, variant_id)` (xem lịch
sử migration trong quá trình phát triển nếu cần làm lại thao tác này).
