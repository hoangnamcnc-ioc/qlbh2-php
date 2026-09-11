<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN');

$pdo = db();
$error = null;
$branches = $pdo->query('SELECT * FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();
$roleLabels = ['ADMIN' => 'Quản trị viên', 'MANAGER' => 'Quản lý', 'CASHIER' => 'Thu ngân'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id !== (int) $currentUser['id']) {
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            logActivity('USER_TOGGLE', 'user_id=' . $id);
        }
        redirect('users.php');
    } elseif ($action === 'reset_password') {
        $id = (int) ($_POST['id'] ?? 0);
        $newPassword = post('new_password');
        if (strlen($newPassword) < 6) {
            $error = 'Mật khẩu mới phải có ít nhất 6 ký tự';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
            logActivity('USER_RESET_PASSWORD', 'user_id=' . $id);
            redirect('users.php?reset=1');
        }
    } elseif ($action === 'update_role') {
        $id = (int) ($_POST['id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['ADMIN', 'MANAGER', 'CASHIER'], true) ? $_POST['role'] : 'CASHIER';
        $branchId = (int) ($_POST['branch_id'] ?? 0) ?: null;
        if ($id === (int) $currentUser['id'] && $role !== 'ADMIN') {
            $error = 'Không thể tự hạ quyền tài khoản đang đăng nhập';
        } else {
            $pdo->prepare('UPDATE users SET role = ?, branch_id = ? WHERE id = ?')->execute([$role, $branchId, $id]);
            logActivity('USER_ROLE_CHANGE', "user_id=$id role=$role");
            redirect('users.php');
        }
    } else {
        $name = post('name');
        $email = trim(strtolower(post('email')));
        $password = post('password');
        $role = in_array($_POST['role'] ?? '', ['ADMIN', 'MANAGER', 'CASHIER'], true) ? $_POST['role'] : 'CASHIER';
        $branchId = (int) ($_POST['branch_id'] ?? 0) ?: null;

        if ($name === '' || $email === '' || strlen($password) < 6) {
            $error = 'Vui lòng nhập đủ Tên, Email và Mật khẩu (tối thiểu 6 ký tự)';
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'Email này đã được sử dụng';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('INSERT INTO users (name, email, password_hash, role, branch_id) VALUES (?,?,?,?,?)')
                    ->execute([$name, $email, $hash, $role, $branchId]);
                logActivity('USER_CREATE', $email);
                redirect('users.php?created=1');
            }
        }
    }
}

$users = $pdo->query(
    'SELECT u.*, b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id = u.branch_id ORDER BY u.created_at'
)->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:8px;">Nhân viên và phân quyền</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Quản trị viên (ADMIN) có toàn quyền; Quản lý (MANAGER) có quyền vận hành đầy đủ trừ cấu hình hệ
  thống; Thu ngân (CASHIER) chỉ thấy Bán hàng/Đơn hàng/Khách hàng và xem Sản phẩm-Kho.
</p>

<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Đã tạo tài khoản thành công</div><?php endif; ?>
<?php if (isset($_GET['reset'])): ?><div class="alert alert-success">Đã đặt lại mật khẩu thành công</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm tài khoản nhân viên</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="grid-2">
      <div class="field"><label>Họ tên *</label><input class="input" name="name" required></div>
      <div class="field"><label>Email đăng nhập *</label><input class="input" type="email" name="email" required></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Mật khẩu *</label><input class="input" type="password" name="password" required minlength="6" placeholder="Tối thiểu 6 ký tự"></div>
      <div class="field">
        <label>Vai trò</label>
        <select class="input" name="role">
          <?php foreach ($roleLabels as $val => $lbl): ?>
            <option value="<?= e($val) ?>"><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field">
      <label>Chi nhánh</label>
      <select class="input" name="branch_id">
        <option value="">— Không gán —</option>
        <?php foreach ($branches as $b): ?>
          <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn">Tạo tài khoản</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr><th>Họ tên</th><th>Email</th><th>Vai trò</th><th>Chi nhánh</th><th class="text-center">Trạng thái</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?><?php if ((int) $u['id'] === (int) $currentUser['id']): ?> <span class="badge badge-gray">Bạn</span><?php endif; ?></td>
          <td class="muted" style="font-family:monospace;"><?= e($u['email']) ?></td>
          <td>
            <form method="post" style="display:flex;gap:6px;align-items:center;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="update_role">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <select name="role" class="input" style="max-width:130px;padding:4px 8px;" onchange="this.form.submit()">
                <?php foreach ($roleLabels as $val => $lbl): ?>
                  <option value="<?= e($val) ?>" <?= $u['role'] === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="branch_id" class="input" style="max-width:130px;padding:4px 8px;" onchange="this.form.submit()">
                <option value="">— —</option>
                <?php foreach ($branches as $b): ?>
                  <option value="<?= (int) $b['id'] ?>" <?= (int) ($u['branch_id'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="muted"><?= e($u['branch_name'] ?: '—') ?></td>
          <td class="text-center">
            <?php if ($u['is_active']): ?><span class="badge badge-green">Đang hoạt động</span>
            <?php else: ?><span class="badge badge-gray">Đã khóa</span><?php endif; ?>
          </td>
          <td class="text-right" style="white-space:nowrap;">
            <button type="button" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;" onclick="document.getElementById('reset-<?= (int) $u['id'] ?>').style.display='flex'">Đổi mật khẩu</button>
            <?php if ((int) $u['id'] !== (int) $currentUser['id']): ?>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn <?= $u['is_active'] ? 'btn-danger' : 'btn-secondary' ?>" style="padding:4px 10px;font-size:12px;"><?= $u['is_active'] ? 'Khóa' : 'Mở khóa' ?></button>
              </form>
            <?php endif; ?>
            <form method="post" id="reset-<?= (int) $u['id'] ?>" style="display:none;gap:6px;margin-top:6px;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input class="input" type="password" name="new_password" placeholder="Mật khẩu mới" minlength="6" style="width:140px;padding:4px 8px;">
              <button type="submit" class="btn" style="padding:4px 10px;font-size:12px;">Lưu</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
