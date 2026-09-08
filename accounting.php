<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');
require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:16px;">Kế toán và Thuế</h1>

<div class="card" style="max-width:640px;">
  <p style="margin:0 0 12px;">
    Tính năng hóa đơn điện tử / khai thuế yêu cầu kết nối với nhà cung cấp hóa đơn điện tử
    được Tổng cục Thuế công nhận (vd Viettel, VNPT, MISA, M-Invoice...).
  </p>
  <p class="muted" style="margin:0;">
    QLBH2 hiện <b>chưa tích hợp API thật</b> với các nhà cung cấp này. Để dùng được, bạn cần:
  </p>
  <ol class="muted" style="margin:8px 0 0;padding-left:20px;">
    <li>Đăng ký tài khoản với 1 nhà cung cấp hóa đơn điện tử.</li>
    <li>Lấy API key/thông tin kết nối họ cung cấp.</li>
    <li>Nhờ lập trình lại phần này để gọi API thật của nhà cung cấp đó khi hoàn thành đơn hàng.</li>
  </ol>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
