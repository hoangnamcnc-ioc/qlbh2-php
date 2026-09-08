<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $pdo = db();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$currentUser['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        $error = 'Mật khẩu hiện tại không đúng';
    } elseif (strlen($new) < 6) {
        $error = 'Mật khẩu mới phải có ít nhất 6 ký tự';
    } elseif ($new !== $confirm) {
        $error = 'Xác nhận mật khẩu mới không khớp';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $currentUser['id']]);
        $success = 'Đã đổi mật khẩu thành công.';
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Đổi mật khẩu</h1>

<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:420px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label>Mật khẩu hiện tại</label>
      <input class="input" type="password" name="current_password" required>
    </div>

    <div class="field">
      <label>Mật khẩu mới (tối thiểu 6 ký tự)</label>
      <input class="input" type="password" name="new_password" required minlength="6">
    </div>

    <div class="field">
      <label>Xác nhận mật khẩu mới</label>
      <input class="input" type="password" name="confirm_password" required minlength="6">
    </div>

    <button type="submit" class="btn">Đổi mật khẩu</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
