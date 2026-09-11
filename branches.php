<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE branches SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        logActivity('BRANCH_TOGGLE', 'id=' . $id);
        redirect('branches.php');
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = post('name');
        $address = post('address') ?: null;
        $phone = post('phone') ?: null;
        if ($name === '') {
            $error = 'Vui lòng nhập tên chi nhánh';
        } else {
            $pdo->prepare('UPDATE branches SET name = ?, address = ?, phone = ? WHERE id = ?')
                ->execute([$name, $address, $phone, $id]);
            logActivity('BRANCH_UPDATE', $name);
            redirect('branches.php');
        }
    } else {
        $name = post('name');
        $address = post('address') ?: null;
        $phone = post('phone') ?: null;

        if ($name === '') {
            $error = 'Vui lòng nhập tên chi nhánh';
        } else {
            $pdo->prepare('INSERT INTO branches (name, address, phone) VALUES (?,?,?)')
                ->execute([$name, $address, $phone]);
            logActivity('BRANCH_CREATE', $name);
            redirect('branches.php');
        }
    }
}

$branches = $pdo->query('SELECT * FROM branches ORDER BY id')->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Chi nhánh</h1>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm chi nhánh</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="grid-2">
      <div class="field"><label>Tên chi nhánh *</label><input class="input" name="name" required></div>
      <div class="field"><label>Số điện thoại</label><input class="input" name="phone"></div>
    </div>
    <div class="field"><label>Địa chỉ</label><input class="input" name="address"></div>
    <button type="submit" class="btn">Thêm chi nhánh</button>
  </form>
</div>

<div class="card" style="max-width:640px;padding:0;">
  <?php foreach ($branches as $b): ?>
    <div style="padding:12px 16px;border-top:1px solid #f1f5f9;">
      <?php if (!$b['is_active']): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <b><?= e($b['name']) ?></b> <span class="badge badge-gray">Ngừng hoạt động</span>
            <?php if ($b['phone']): ?><span class="muted"> · <?= e($b['phone']) ?></span><?php endif; ?>
            <?php if ($b['address']): ?><div class="muted" style="font-size:13px;"><?= e($b['address']) ?></div><?php endif; ?>
          </div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;">Bật lại</button>
          </form>
        </div>
      <?php else: ?>
        <form method="post" style="display:flex;align-items:end;gap:8px;flex-wrap:wrap;">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
          <div class="field" style="margin:0;flex:1;min-width:160px;"><label style="font-size:11px;">Tên chi nhánh</label><input class="input" name="name" value="<?= e($b['name']) ?>" required></div>
          <div class="field" style="margin:0;flex:1;min-width:140px;"><label style="font-size:11px;">SĐT</label><input class="input" name="phone" value="<?= e($b['phone'] ?? '') ?>"></div>
          <div class="field" style="margin:0;flex:2;min-width:200px;"><label style="font-size:11px;">Địa chỉ</label><input class="input" name="address" value="<?= e($b['address'] ?? '') ?>"></div>
          <button type="submit" class="btn btn-secondary" style="padding:8px 12px;font-size:12px;">Lưu</button>
        </form>
        <form method="post" style="margin-top:6px;">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
          <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px;" onclick="return confirm('Ngừng hoạt động chi nhánh này? Chi nhánh sẽ ẩn khỏi các danh sách chọn chi nhánh (POS, kiểm kho, chuyển hàng...), dữ liệu lịch sử vẫn được giữ nguyên.');">Ngừng hoạt động</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
