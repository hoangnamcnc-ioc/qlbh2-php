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
<title>Đã hết hạn dùng thử - QLBH2</title>
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
    <h1>Đã hết hạn dùng thử QLBH2</h1>
    <p>
      Cảm ơn <?= e($user['name']) ?> đã trải nghiệm QLBH2! Thời gian dùng thử 14 ngày đã kết
      thúc. Dữ liệu của bạn vẫn được giữ nguyên — liên hệ KT-SOFT để nâng cấp lên gói chính
      thức và tiếp tục sử dụng.
    </p>
    <a class="btn" href="https://kt-soft.vn/lien-he.php" target="_blank" rel="noopener">Liên hệ nâng cấp</a>
    <a class="logout-link" href="logout.php">Đăng xuất</a>
  </div>
</body>
</html>
