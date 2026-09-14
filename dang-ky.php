<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

// Neu da dang nhap roi thi hoi ro: vao he thong tiep hay dang ky tai khoan (cua hang) moi -
// tranh nguoi dung bam "Dung thu" tren kt-soft.vn nhung trinh duyet dang co san phien dang nhap
// cu bi tu dong day vao thang tai khoan cu ma khong ro vi sao.
$loggedInUser = currentUser();
if ($loggedInUser && !isset($_GET['new'])) {
    $pageTitle = 'Đăng ký dùng thử QLBH2';
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <style>
      body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #0f172a; font-family: -apple-system, Segoe UI, Roboto, sans-serif; padding: 24px; box-sizing: border-box; }
      .box { background: #fff; border-radius: 12px; padding: 36px; width: 100%; max-width: 420px; text-align: center; }
      h1 { font-size: 19px; margin: 0 0 10px; color: #1e293b; }
      p { color: #64748b; font-size: 14px; line-height: 1.6; margin: 0 0 22px; }
      p b { color: #1e293b; }
      .choice-btn { display: block; width: 100%; box-sizing: border-box; border-radius: 8px; padding: 13px; font-size: 14.5px; font-weight: 600; text-decoration: none; margin-bottom: 12px; }
      .choice-btn:hover { text-decoration: none; }
      .choice-primary { background: #2563eb; color: #fff; }
      .choice-primary:hover { background: #1d4ed8; }
      .choice-secondary { background: #fff; color: #2563eb; border: 1.5px solid #2563eb; }
      .choice-secondary:hover { background: #eff6ff; }
    </style>
    </head>
    <body>
      <div class="box">
        <h1>Bạn đang đăng nhập</h1>
        <p>Trình duyệt này đang đăng nhập tài khoản <b><?= e($loggedInUser['email']) ?></b> (<?= e($loggedInUser['name']) ?>). Bạn muốn tiếp tục vào hệ thống với tài khoản này, hay đăng ký một tài khoản/cửa hàng mới?</p>
        <a class="choice-btn choice-primary" href="index.php">Tiếp tục vào hệ thống</a>
        <a class="choice-btn choice-secondary" href="dang-ky.php?new=1">Đăng ký tài khoản mới</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $companyName = post('company_name');
    $adminName = post('admin_name');
    $email = trim(strtolower(post('email')));
    $password = post('password');

    if ($companyName === '' || $adminName === '' || $email === '' || strlen($password) < 6) {
        $error = 'Vui lòng nhập đủ Tên cửa hàng, Tên người quản trị, Email và Mật khẩu (tối thiểu 6 ký tự)';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email không hợp lệ';
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'Email này đã được đăng ký, vui lòng đăng nhập hoặc dùng email khác';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO tenants (name, owner_email, plan, trial_ends_at, is_active) VALUES (?, ?, 'TRIAL', DATE_ADD(NOW(), INTERVAL 12 MONTH), 1)"
                )->execute([$companyName, $email]);
                $tenantId = (int) $pdo->lastInsertId();

                $pdo->prepare('INSERT INTO branches (name, tenant_id, is_active) VALUES (?, ?, 1)')
                    ->execute(['Chi nhánh chính', $tenantId]);
                $branchId = (int) $pdo->lastInsertId();

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare(
                    'INSERT INTO users (name, email, password_hash, role, branch_id, tenant_id, is_active) VALUES (?, ?, ?, \'ADMIN\', ?, ?, 1)'
                )->execute([$adminName, $email, $hash, $branchId, $tenantId]);
                $userId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO store_settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)'
                )->execute([$tenantId, 'store_name', $companyName]);

                $pdo->commit();

                // Tu dong dang nhap vao tai khoan vua tao - doi session ID moi giong het
                // attemptLogin() that su, tranh session fixation.
                session_regenerate_id(true);
                $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                unset($user['password_hash']);
                $_SESSION['user'] = $user;
                logActivity('TENANT_SIGNUP', "tenant=$companyName email=$email");

                redirect('index.php?welcome=1');
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $error = 'Không thể tạo tài khoản, vui lòng thử lại';
            }
        }
    }
}

$pageTitle = 'Đăng ký dùng thử QLBH2';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<style>
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #0f172a; font-family: -apple-system, Segoe UI, Roboto, sans-serif; padding: 24px; box-sizing: border-box; }
  .box { background: #fff; border-radius: 12px; padding: 36px; width: 100%; max-width: 420px; }
  h1 { font-size: 20px; margin: 0 0 6px; color: #1e293b; text-align: center; }
  p.lead { color: #64748b; font-size: 13px; text-align: center; margin: 0 0 24px; }
  .field { margin-bottom: 14px; }
  label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px; color: #334155; }
  input { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 14px; }
  button { width: 100%; background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 11px; font-size: 14px; font-weight: 600; cursor: pointer; margin-top: 6px; }
  button:hover { background: #1d4ed8; }
  .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
  .trial-note { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 10px 14px; border-radius: 6px; font-size: 12.5px; margin-bottom: 18px; line-height: 1.5; }
  .login-link { display: block; text-align: center; margin-top: 18px; font-size: 13px; color: #64748b; }
  .login-link a { color: #2563eb; text-decoration: none; }
  .login-link a:hover { text-decoration: underline; }
</style>
</head>
<body>
  <div class="box">
    <h1>Dùng thử QLBH2</h1>
    <p class="lead">Tạo tài khoản quản trị cho cửa hàng của bạn — miễn phí sử dụng 12 tháng.</p>

    <div class="trial-note">
      🎁 Sử dụng miễn phí <b>12 tháng</b> đầy đủ tính năng, dữ liệu của bạn hoàn toàn riêng biệt,
      không ảnh hưởng đến cửa hàng khác. Hết hạn có thể yêu cầu gia hạn thêm để tiếp tục sử dụng.
    </div>

    <?php if ($error): ?><div class="alert-error"><?= e($error) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <div class="field">
        <label>Tên cửa hàng / công ty *</label>
        <input name="company_name" required value="<?= e($_POST['company_name'] ?? '') ?>" placeholder="vd: Cửa hàng Minh Anh">
      </div>
      <div class="field">
        <label>Tên người quản trị *</label>
        <input name="admin_name" required value="<?= e($_POST['admin_name'] ?? '') ?>" placeholder="vd: Nguyễn Văn A">
      </div>
      <div class="field">
        <label>Email đăng nhập *</label>
        <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Mật khẩu *</label>
        <input type="password" name="password" required minlength="6" placeholder="Tối thiểu 6 ký tự">
      </div>
      <button type="submit">Bắt đầu dùng thử miễn phí</button>
    </form>

    <div class="login-link">Đã có tài khoản? <a href="login.php">Đăng nhập</a></div>
  </div>
</body>
</html>
