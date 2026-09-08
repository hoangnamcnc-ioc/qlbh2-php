# Hướng dẫn triển khai QLBH2 (PHP) lên kt-soft.vn

Không cần Vercel/Supabase — chạy trực tiếp trên hosting chia sẻ hiện có, giống cách bạn đã
deploy hocketoanthue.vn.

## Bước 1 — Tạo database MySQL

1. Vào cPanel của hosting → **MySQL Databases** → tạo database mới (vd `ktsoft_qlbh2`).
2. Tạo user MySQL, gán quyền đầy đủ (All Privileges) vào database vừa tạo.
3. Vào **phpMyAdmin** → chọn database vừa tạo → tab **Import** → chọn file `schema.sql` → Go.

## Bước 2 — Upload code lên hosting

1. Nếu bạn muốn dùng subdomain `app.kt-soft.vn`: vào cPanel → **Subdomains** → tạo subdomain
   `app` trỏ vào một thư mục riêng (vd `app.kt-soft.vn` → `/home/.../app_kt_soft`).
2. Upload toàn bộ file trong `D:\QLBH2-PHP` (trừ `.git`, `README.md`, `DEPLOY.md`) vào thư mục đó,
   qua **File Manager** của cPanel hoặc FTP (FileZilla).

## Bước 3 — Cấu hình kết nối DB

1. Trên hosting, đổi tên/sao chép `config.sample.php` thành `config.php`.
2. Sửa `config.php` với thông tin DB thật từ Bước 1:
   ```php
   define('DB_HOST', '127.0.0.1'); // ĐÚNG cho iNet — không dùng 'localhost'
   define('DB_NAME', 'ktsoft_qlbh2');
   define('DB_USER', 'ten_user_mysql');
   define('DB_PASS', 'mat_khau_mysql');
   define('AUTH_SALT', '...'); // chuỗi ngẫu nhiên dài, tự tạo
   ```

## Bước 4 — Tạo tài khoản admin

1. Mở trình duyệt, truy cập `https://app.kt-soft.vn/seed.php`.
2. Trang sẽ báo tài khoản `admin@qlbh2.local` / `Admin@123` đã tạo xong.
3. **Xóa file `seed.php` khỏi hosting ngay sau đó** (qua File Manager) — đây là bước bảo mật bắt buộc.

## Bước 5 — Đăng nhập và đổi mật khẩu

1. Truy cập `https://app.kt-soft.vn/login.php`.
2. Đăng nhập bằng tài khoản vừa tạo → **đổi mật khẩu ngay** (chưa có trang đổi mật khẩu trong UI —
   có thể nhờ chỉnh trực tiếp qua phpMyAdmin bằng cách chạy:
   ```sql
   UPDATE users SET password_hash = '<hash_moi>' WHERE email = 'admin@qlbh2.local';
   ```
   với `<hash_moi>` tạo bằng PHP: `php -r "echo password_hash('mat_khau_moi', PASSWORD_DEFAULT);"`,
   hoặc tôi có thể bổ sung trang đổi mật khẩu sau nếu bạn cần).

## Sau khi deploy

- Mỗi lần sửa code, upload lại file đã đổi qua File Manager/FTP — không có bước build nào cần chạy.
- Sửa cấu trúc bảng → chỉnh `schema.sql` → chạy phần SQL thay đổi tương ứng qua phpMyAdmin trên
  database thật (KHÔNG import lại toàn bộ `schema.sql` vì sẽ báo lỗi bảng đã tồn tại, trừ khi bạn
  xóa bảng cũ trước — cẩn thận mất dữ liệu).
