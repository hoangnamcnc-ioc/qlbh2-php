<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

$user = currentUser();
if (!$user) {
    redirect('login.php');
}

// Truy cập trang này (dù qua nút "Khóa màn hình" hay do bị điều hướng tới vì đã khóa từ trước)
// luôn đảm bảo phiên đang ở trạng thái khóa, để tab khác của cùng phiên cũng bị chặn ngay.
$_SESSION['locked'] = true;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $password = $_POST['password'] ?? '';
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['locked'] = false;
        logActivity('UNLOCK', $user['email'] ?? '');
        redirect('index.php');
    }
    $error = 'Mật khẩu không đúng.';
}

$storeLogo = getSetting('store_logo', '');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Màn hình đã khóa - QLBH-CLOUD</title>
<?php if ($storeLogo): ?><link rel="icon" href="uploads/store/<?= e($storeLogo) ?>"><?php endif; ?>
<style>
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #0f172a; font-family: -apple-system, Segoe UI, Roboto, sans-serif; }
  .box { background: #fff; border-radius: 12px; padding: 32px; width: 100%; max-width: 340px; text-align: center; }
  .lock-icon { font-size: 40px; margin-bottom: 8px; }
  h1 { font-size: 17px; margin: 0 0 2px; color: #1e293b; }
  .muted { color: #64748b; font-size: 13px; margin: 0 0 20px; }
  input { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 14px; margin-bottom: 12px; text-align: center; }
  button { width: 100%; background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
  button:hover { background: #1d4ed8; }
  .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 8px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
  .logout-link { display: block; margin-top: 16px; font-size: 12px; color: #94a3b8; text-decoration: none; }
  .logout-link:hover { text-decoration: underline; }
</style>
</head>
<body>
  <div class="box">
    <div class="lock-icon">🔒</div>
    <h1><?= e($user['name']) ?></h1>
    <p class="muted">Màn hình đã khóa. Nhập mật khẩu để tiếp tục.</p>
    <?php if ($error): ?><div class="alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="password" name="password" placeholder="Mật khẩu" autofocus required>
      <button type="submit">Mở khóa</button>
    </form>
    <a class="logout-link" href="logout.php">Đăng xuất tài khoản khác</a>
  </div>
</body>
</html>
