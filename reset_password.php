<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

$pdo = db();
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = null;
$success = false;

$resetStmt = $pdo->prepare(
    'SELECT pr.*, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id
     WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()'
);
$resetStmt->execute([$token]);
$reset = $resetStmt->fetch();

if (!$reset) {
    $error = 'invalid';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Mật khẩu mới phải có ít nhất 6 ký tự';
    } elseif ($password !== $password2) {
        $error = 'Xác nhận mật khẩu không khớp';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $reset['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')->execute([$reset['id']]);
        // Vo hieu hoa moi token dat lai mat khau khac dang cho cua chinh user nay, tranh 1 link
        // cu con hieu luc bi loi dung lai sau khi mat khau da doi.
        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([$reset['user_id']]);
        logActivity('PASSWORD_RESET_DONE', $reset['email']);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đặt lại mật khẩu - QLBH-CLOUD</title>
<style>
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .box { width: 100%; max-width: 380px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 32px; }
  h1 { margin: 0 0 4px; font-size: 20px; }
  p.sub { margin: 0 0 20px; color: #64748b; font-size: 14px; line-height: 1.6; }
  label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px; color: #334155; }
  input { width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 14px; margin-bottom: 16px; box-sizing: border-box; }
  button { width: 100%; background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
  .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 10px 14px; border-radius: 6px; font-size: 13.5px; margin-bottom: 16px; }
  .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; padding: 10px 14px; border-radius: 6px; font-size: 13.5px; margin-bottom: 16px; }
  .back-link { display: block; text-align: center; margin-top: 14px; font-size: 13px; color: #64748b; }
</style>
</head>
<body>
  <div class="box">
    <h1>Đặt lại mật khẩu</h1>
    <?php if ($error === 'invalid'): ?>
      <div class="alert-error">Link đặt lại mật khẩu không hợp lệ hoặc đã hết hạn (chỉ có hiệu lực trong 1 giờ). Vui lòng yêu cầu lại.</div>
      <a href="forgot_password.php" class="back-link">Yêu cầu link mới →</a>
    <?php elseif ($success): ?>
      <div class="alert-success">Đã đặt lại mật khẩu thành công. Bạn có thể đăng nhập bằng mật khẩu mới.</div>
      <a href="login.php" class="back-link">Đăng nhập ngay →</a>
    <?php else: ?>
      <p class="sub">Đặt mật khẩu mới cho tài khoản <b><?= e($reset['email']) ?></b>.</p>
      <?php if ($error): ?><div class="alert-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label>Mật khẩu mới</label>
        <input type="password" name="password" required minlength="6" placeholder="Tối thiểu 6 ký tự">
        <label>Nhập lại mật khẩu mới</label>
        <input type="password" name="password2" required minlength="6">
        <button type="submit">Đặt lại mật khẩu</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
