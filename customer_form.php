<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$customer = null;
$error = null;

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    if (!$customer) redirect('customers.php');
}

$groups = $pdo->query('SELECT * FROM customer_groups ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = post('name');
    $phone = post('phone') ?: null;
    $address = post('address') ?: null;
    $groupId = post('group_id') ?: null;

    if ($name === '') {
        $error = 'Vui lòng nhập tên khách hàng';
    } elseif ($phone) {
        $check = $pdo->prepare('SELECT id FROM customers WHERE phone = ? AND id != ?');
        $check->execute([$phone, $id]);
        if ($check->fetch()) {
            $error = 'Số điện thoại này đã tồn tại trong hệ thống';
        }
    }

    if (!$error) {
        if ($id) {
            $pdo->prepare('UPDATE customers SET name=?, phone=?, address=?, group_id=? WHERE id=?')
                ->execute([$name, $phone, $address, $groupId, $id]);
            redirect('customer_view.php?id=' . $id);
        } else {
            $code = 'CUZN' . substr((string) (time() * 1000), -8);
            $pdo->prepare('INSERT INTO customers (code, name, phone, address, group_id) VALUES (?,?,?,?,?)')
                ->execute([$code, $name, $phone, $address, $groupId]);
            redirect('customer_view.php?id=' . $pdo->lastInsertId());
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="customers.php" class="muted" style="font-size:14px;">← Danh sách khách hàng</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">
  <?= $customer ? 'Sửa khách hàng' : 'Thêm khách hàng' ?>
</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:640px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label>Tên khách hàng *</label>
      <input class="input" name="name" required value="<?= e($customer['name'] ?? '') ?>">
    </div>

    <div class="grid-2">
      <div class="field">
        <label>Số điện thoại</label>
        <input class="input" name="phone" value="<?= e($customer['phone'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Nhóm khách hàng</label>
        <select class="input" name="group_id">
          <option value="">— Không nhóm —</option>
          <?php foreach ($groups as $g): ?>
            <option value="<?= (int) $g['id'] ?>" <?= ($customer['group_id'] ?? null) == $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label>Địa chỉ</label>
      <input class="input" name="address" value="<?= e($customer['address'] ?? '') ?>">
    </div>

    <button type="submit" class="btn">Lưu khách hàng</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
