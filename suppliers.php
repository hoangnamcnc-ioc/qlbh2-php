<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = post('name');
    $phone = post('phone') ?: null;
    $address = post('address') ?: null;

    if ($name === '') {
        $error = 'Vui lòng nhập tên nhà cung cấp';
    } else {
        $pdo->prepare('INSERT INTO suppliers (name, phone, address) VALUES (?,?,?)')
            ->execute([$name, $phone, $address]);
        redirect('suppliers.php');
    }
}

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY created_at DESC')->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Nhà cung cấp</h1>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm nhà cung cấp</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="grid-2">
      <div class="field"><label>Tên nhà cung cấp *</label><input class="input" name="name" required></div>
      <div class="field"><label>Số điện thoại</label><input class="input" name="phone"></div>
    </div>
    <div class="field"><label>Địa chỉ</label><input class="input" name="address"></div>
    <button type="submit" class="btn">Thêm nhà cung cấp</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên nhà cung cấp</th><th>SĐT</th><th>Địa chỉ</th><th class="text-right">Công nợ phải trả</th></tr></thead>
    <tbody>
      <?php if (!$suppliers): ?>
        <tr><td colspan="4" class="text-center muted" style="padding:32px;">Chưa có nhà cung cấp nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($suppliers as $s): ?>
        <tr>
          <td><a href="supplier_view.php?id=<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a></td>
          <td><?= e($s['phone'] ?: '—') ?></td>
          <td><?= e($s['address'] ?: '—') ?></td>
          <td class="text-right" style="<?= $s['debt'] > 0 ? 'color:#dc2626;font-weight:600;' : '' ?>"><?= money($s['debt']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
