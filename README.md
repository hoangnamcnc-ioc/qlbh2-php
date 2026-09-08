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

Chưa có: phân quyền theo role trên từng trang (mới có role trong DB + session, chưa chặn UI theo
role), Báo cáo chi tiết, quản lý biến thể sản phẩm, nhập hàng + Nhà cung cấp, sửa/hủy đơn sau khi
tạo.
