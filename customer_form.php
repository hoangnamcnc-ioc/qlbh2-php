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
$staffList = $pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = post('name');
    $phone = post('phone') ?: null;
    $address = post('address') ?: null;
    $groupId = post('group_id') ?: null;
    $birthday = post('birthday') ?: null;
    $gender = in_array($_POST['gender'] ?? '', ['MALE', 'FEMALE', 'OTHER'], true) ? $_POST['gender'] : null;
    $email = post('email') ?: null;
    $taxCode = post('tax_code') ?: null;
    $website = post('website') ?: null;
    $description = post('description') ?: null;
    $tags = post('tags') ?: null;
    $assignedStaffId = (int) ($_POST['assigned_staff_id'] ?? 0) ?: null;
    $defaultPaymentMethod = in_array($_POST['default_payment_method'] ?? '', ['CASH', 'BANK_TRANSFER', 'CARD', 'QR_CODE'], true)
        ? $_POST['default_payment_method'] : null;
    $discountPercent = postFloat('discount_percent');

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
            $pdo->prepare(
                'UPDATE customers SET name=?, phone=?, address=?, group_id=?, birthday=?, gender=?, email=?, tax_code=?, website=?, description=?, tags=?, assigned_staff_id=?, default_payment_method=?, discount_percent=? WHERE id=?'
            )->execute([$name, $phone, $address, $groupId, $birthday, $gender, $email, $taxCode, $website, $description, $tags, $assignedStaffId, $defaultPaymentMethod, $discountPercent, $id]);
            redirect('customer_view.php?id=' . $id);
        } else {
            $code = 'CUZN' . substr((string) (time() * 1000), -8);
            $pdo->prepare(
                'INSERT INTO customers (code, name, phone, address, group_id, birthday, gender, email, tax_code, website, description, tags, assigned_staff_id, default_payment_method, discount_percent) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$code, $name, $phone, $address, $groupId, $birthday, $gender, $email, $taxCode, $website, $description, $tags, $assignedStaffId, $defaultPaymentMethod, $discountPercent]);
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

    <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thông tin cá nhân</h2>
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
        <label>Email</label>
        <input class="input" type="email" name="email" value="<?= e($customer['email'] ?? '') ?>">
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label>Ngày sinh</label>
        <input class="input" type="date" name="birthday" value="<?= e($customer['birthday'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Giới tính</label>
        <select class="input" name="gender">
          <option value="">— Không chọn —</option>
          <option value="MALE" <?= ($customer['gender'] ?? '') === 'MALE' ? 'selected' : '' ?>>Nam</option>
          <option value="FEMALE" <?= ($customer['gender'] ?? '') === 'FEMALE' ? 'selected' : '' ?>>Nữ</option>
          <option value="OTHER" <?= ($customer['gender'] ?? '') === 'OTHER' ? 'selected' : '' ?>>Khác</option>
        </select>
      </div>
    </div>

    <div class="field">
      <label>Địa chỉ</label>
      <input class="input" name="address" value="<?= e($customer['address'] ?? '') ?>">
    </div>

    <div class="grid-2">
      <div class="field">
        <label>Nhóm khách hàng</label>
        <select class="input" name="group_id">
          <option value="">— Không nhóm —</option>
          <?php foreach ($groups as $g): ?>
            <option value="<?= (int) $g['id'] ?>" <?= ($customer['group_id'] ?? null) == $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Nhân viên phụ trách</label>
        <select class="input" name="assigned_staff_id">
          <option value="">— Không chọn —</option>
          <?php foreach ($staffList as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= ($customer['assigned_staff_id'] ?? null) == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label>Tags (cách nhau bằng dấu phẩy)</label>
      <input class="input" name="tags" value="<?= e($customer['tags'] ?? '') ?>">
    </div>

    <h2 style="font-size:14px;font-weight:600;margin:20px 0 12px;">Thông tin doanh nghiệp (không bắt buộc)</h2>
    <div class="grid-2">
      <div class="field"><label>Mã số thuế</label><input class="input" name="tax_code" value="<?= e($customer['tax_code'] ?? '') ?>"></div>
      <div class="field"><label>Website</label><input class="input" name="website" value="<?= e($customer['website'] ?? '') ?>"></div>
    </div>
    <div class="field">
      <label>Mô tả</label>
      <textarea class="input" name="description" rows="2"><?= e($customer['description'] ?? '') ?></textarea>
    </div>

    <h2 style="font-size:14px;font-weight:600;margin:20px 0 12px;">Thông tin gợi ý khi bán hàng</h2>
    <div class="grid-2">
      <div class="field">
        <label>Chiết khấu riêng cho khách (%)</label>
        <input class="input" type="number" min="0" max="100" step="0.1" name="discount_percent" value="<?= e((string) ($customer['discount_percent'] ?? '0')) ?>">
      </div>
      <div class="field">
        <label>Hình thức thanh toán mặc định</label>
        <select class="input" name="default_payment_method">
          <option value="">— Không chọn —</option>
          <option value="CASH" <?= ($customer['default_payment_method'] ?? '') === 'CASH' ? 'selected' : '' ?>>Tiền mặt</option>
          <option value="BANK_TRANSFER" <?= ($customer['default_payment_method'] ?? '') === 'BANK_TRANSFER' ? 'selected' : '' ?>>Chuyển khoản</option>
          <option value="CARD" <?= ($customer['default_payment_method'] ?? '') === 'CARD' ? 'selected' : '' ?>>Quẹt thẻ</option>
          <option value="QR_CODE" <?= ($customer['default_payment_method'] ?? '') === 'QR_CODE' ? 'selected' : '' ?>>Quét mã QR</option>
        </select>
      </div>
    </div>

    <button type="submit" class="btn">Lưu khách hàng</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
