# Công cụ vận hành QLBH-CLOUD

Cần đặt biến môi trường `FTP_USERPASS='tên_đăng_nhập:mật_khẩu'` (không lưu mật khẩu vào file nào).

- `tools/deploy.sh file1.php file2.php ...` — deploy an toàn: sao lưu bản đang chạy trên server vào
  `tools/backups/<thời gian>/`, kiểm tra cú pháp PHP trên server, chỉ ghi đè khi tất cả đều đạt, so khớp
  từng byte sau khi ghi, dọn file tạm. Khôi phục: `tools/deploy.sh --rollback tools/backups/<thư mục>`.
- `tools/regression_test.sh` — chạy lại các kịch bản nghiệp vụ chính trên cửa hàng thử tạm (bán, hủy,
  trả hàng, ca, phân quyền, hạn mức, chấm công, shop online, đối chiếu) rồi tự xóa dữ liệu thử.
  Chạy sau mỗi lần deploy; thoát mã 0 nếu tất cả đạt.
- `tools/test_probe.php` — file phụ trợ của bộ kiểm thử, chỉ được tải lên tạm thời và tự xóa.
- `tools/isolation_test.sh` — kiểm thử cách ly giữa các cửa hàng: tạo 2 cửa hàng thử, đặt dữ liệu đánh dấu ở cửa hàng A
  rồi từ cửa hàng B mở mọi trang, quét id, gọi API tìm kiếm/xuất file, thử ghi chéo; báo "rò rỉ" nếu thấy dữ liệu của A.
