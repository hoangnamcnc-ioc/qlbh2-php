<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

if (currentUser()) {
    redirect('index.php');
}

$error = null;
if (isset($_GET['locked'])) {
    $error = 'Tài khoản của bạn đã bị khóa hoặc thay đổi quyền, vui lòng đăng nhập lại.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = strtolower(post('email'));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Vui lòng nhập email và mật khẩu';
    } else {
        $user = attemptLogin($email, $password);
        if (!$user) {
            $error = 'Email hoặc mật khẩu không đúng';
        } else {
            redirect('index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đăng nhập - QLBH2</title>
<style>
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .box { width: 100%; max-width: 360px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 32px; }
  h1 { margin: 0 0 4px; font-size: 20px; }
  p.sub { margin: 0 0 20px; color: #64748b; font-size: 14px; }
  label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px; color: #334155; }
  input { width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 14px; margin-bottom: 16px; box-sizing: border-box; }
  button { width: 100%; background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
  .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 10px 14px; border-radius: 6px; font-size: 14px; margin-bottom: 16px; }
</style>
</head>
<body>
  <form class="box" method="post">
    <h1>QLBH2</h1>
    <p class="sub">Đăng nhập để tiếp tục</p>
    <?php if ($error): ?>
      <div class="alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <label>Email</label>
    <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
    <label>Mật khẩu</label>
    <input type="password" name="password" required>
    <button type="submit">Đăng nhập</button>
  </form>
</body>
</html>
