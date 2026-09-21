<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

if (currentUser()) {
    redirect('index.php');
}

$sent = false;
$rateLimited = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = strtolower(trim(post('email')));

    if (rateLimitSecondsLeft('forgot_password') > 0) {
        // Gioi han so lan yeu cau dat lai mat khau theo IP - tranh bi spam gui email hang loat
        // hoac do doan token bang cach tao token lien tuc. Khac voi login: o day khong can giau
        // trang thai khoa (khong lo thong tin tai khoan) nen bao thang cho nguoi dung biet.
        $rateLimited = true;
    } elseif ($email !== '') {
        rateLimitRecordFailure('forgot_password');
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
                ->execute([$user['id'], $token]);

            $resetLink = 'https://' . $_SERVER['HTTP_HOST'] . '/reset_password.php?token=' . $token;
            $subject = 'QLBH-CLOUD - Yeu cau dat lai mat khau';
            $body = "Xin chao {$user['name']},\n\n"
                . "Co yeu cau dat lai mat khau cho tai khoan nay. Bam vao link duoi day de dat mat khau moi\n"
                . "(link co hieu luc trong 1 gio):\n\n$resetLink\n\n"
                . "Neu ban khong yeu cau, vui long bo qua email nay.\n";
            $headers = 'From: QLBH-CLOUD <no-reply@kt-soft.vn>';
            @mail($email, $subject, $body, $headers);
            logActivity('PASSWORD_RESET_REQUEST', $email);
        }
        // Luon hien thong bao thanh cong nhu nhau du email co ton tai hay khong - tranh lo
        // thong tin "email nay co dang ky trong he thong hay khong" cho ke tan cong do quet.
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quên mật khẩu - QLBH-CLOUD</title>
<style>
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .box { width: 100%; max-width: 380px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 32px; }
  h1 { margin: 0 0 4px; font-size: 20px; }
  p.sub { margin: 0 0 20px; color: #64748b; font-size: 14px; line-height: 1.6; }
  label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px; color: #334155; }
  input { width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 14px; margin-bottom: 16px; box-sizing: border-box; }
  button { width: 100%; background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
  .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; padding: 10px 14px; border-radius: 6px; font-size: 13.5px; margin-bottom: 16px; line-height: 1.6; }
  .back-link { display: block; text-align: center; margin-top: 14px; font-size: 13px; color: #64748b; }
</style>
</head>
<body>
  <div class="box">
    <h1>Quên mật khẩu</h1>
    <?php if ($rateLimited): ?>
      <div class="alert-success" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c;">
        Bạn đã yêu cầu quá nhiều lần. Vui lòng thử lại sau ít phút, hoặc liên hệ hỗ trợ: ĐT/Zalo
        <b>0945289666</b>.
      </div>
      <a href="login.php" class="back-link">← Quay lại đăng nhập</a>
    <?php elseif ($sent): ?>
      <div class="alert-success">
        Nếu email này có tài khoản trong hệ thống, chúng tôi đã gửi link đặt lại mật khẩu (hiệu
        lực 1 giờ). Vui lòng kiểm tra hộp thư (kể cả mục Spam). Nếu không nhận được email sau vài
        phút, liên hệ hỗ trợ: ĐT/Zalo <b>0945289666</b> hoặc email <b>hoangnamcnc@gmail.com</b>.
      </div>
      <a href="login.php" class="back-link">← Quay lại đăng nhập</a>
    <?php else: ?>
      <p class="sub">Nhập email đăng nhập, chúng tôi sẽ gửi link đặt lại mật khẩu.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <label>Email</label>
        <input type="email" name="email" required autofocus>
        <button type="submit">Gửi link đặt lại mật khẩu</button>
      </form>
      <a href="login.php" class="back-link">← Quay lại đăng nhập</a>
    <?php endif; ?>
  </div>
</body>
</html>
