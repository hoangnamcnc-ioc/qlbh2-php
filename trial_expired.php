<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

$user = currentUser();
if (!$user) {
    redirect('login.php');
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đã hết hạn dùng thử - QLBH-CLOUD</title>
<style>
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #0f172a; font-family: -apple-system, Segoe UI, Roboto, sans-serif; }
  .box { background: #fff; border-radius: 12px; padding: 36px; width: 100%; max-width: 420px; text-align: center; }
  .icon { font-size: 40px; margin-bottom: 8px; }
  h1 { font-size: 19px; margin: 0 0 10px; color: #1e293b; }
  p { color: #64748b; font-size: 14px; line-height: 1.6; }
  .btn { display: inline-block; background: #2563eb; color: #fff; border: none; border-radius: 8px; padding: 12px 22px; font-size: 14px; font-weight: 600; text-decoration: none; margin-top: 16px; }
  .btn:hover { background: #1d4ed8; }
  .logout-link { display: block; margin-top: 18px; font-size: 12px; color: #94a3b8; text-decoration: none; }
  .logout-link:hover { text-decoration: underline; }
</style>
</head>
<body>
  <div class="box">
    <div class="icon">⏳</div>
    <h1>Đã hết hạn dùng thử QLBH-CLOUD</h1>
    <p>
      Cảm ơn <?= e($user['name']) ?> đã trải nghiệm QLBH-CLOUD! Thời gian sử dụng miễn phí 12 tháng đã
      kết thúc. Dữ liệu của bạn vẫn được giữ nguyên — gửi yêu cầu gia hạn để KT-SOFT liên hệ và
      tiếp tục cho bạn sử dụng (gia hạn tính theo năm).
    </p>
    <a class="btn" href="gia_han.php">Yêu cầu gia hạn</a>
    <p style="font-size:12px;color:#94a3b8;margin-top:14px;">
      Hoặc liên hệ trực tiếp: ĐT/Zalo <b>0945289666</b> — Email <b>hoangnamcnc@gmail.com</b>
    </p>
    <a class="logout-link" href="logout.php">Đăng xuất</a>
  </div>
</body>
</html>
