<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = post('name');
    $address = post('address') ?: null;
    $phone = post('phone') ?: null;

    if ($name === '') {
        $error = 'Vui lòng nhập tên chi nhánh';
    } else {
        $pdo->prepare('INSERT INTO branches (name, address, phone) VALUES (?,?,?)')
            ->execute([$name, $address, $phone]);
        redirect('branches.php');
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
      <b><?= e($b['name']) ?></b>
      <?php if ($b['phone']): ?><span class="muted"> · <?= e($b['phone']) ?></span><?php endif; ?>
      <?php if ($b['address']): ?><div class="muted" style="font-size:13px;"><?= e($b['address']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
