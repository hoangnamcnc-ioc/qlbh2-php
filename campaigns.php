<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$groups = $pdo->query('SELECT * FROM customer_groups ORDER BY name')->fetchAll();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = post('name');
    $channel = in_array($_POST['channel'] ?? '', ['SMS', 'EMAIL', 'OTHER'], true) ? $_POST['channel'] : 'SMS';
    $message = post('message');
    $groupId = (int) ($_POST['target_group_id'] ?? 0) ?: null;

    if ($name === '' || $message === '') {
        $error = 'Vui lòng nhập tên chiến dịch và nội dung';
    } else {
        $pdo->prepare('INSERT INTO campaigns (name, channel, message, target_group_id, created_by_id) VALUES (?,?,?,?,?)')
            ->execute([$name, $channel, $message, $groupId, currentUser()['id']]);
        redirect('campaigns.php');
    }
}

$campaigns = $pdo->query(
    'SELECT c.*, g.name AS group_name FROM campaigns c LEFT JOIN customer_groups g ON g.id = c.target_group_id ORDER BY c.created_at DESC'
)->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:8px;">Marketing</h1>
<div class="alert alert-warning" style="max-width:640px;">
  Đây chỉ là nơi lưu trữ nội dung chiến dịch nội bộ — hệ thống <b>chưa gửi SMS/Email thật</b>.
  Muốn gửi thật cần cấu hình tài khoản dịch vụ SMS/Email (vd Twilio, eSMS, SendGrid...).
</div>

<div class="card" style="max-width:640px;margin:16px 0 24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Tạo chiến dịch</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="grid-2">
      <div class="field"><label>Tên chiến dịch *</label><input class="input" name="name" required></div>
      <div class="field">
        <label>Kênh</label>
        <select class="input" name="channel">
          <option value="SMS">SMS</option>
          <option value="EMAIL">Email</option>
          <option value="OTHER">Khác</option>
        </select>
      </div>
    </div>
    <div class="field">
      <label>Nhóm khách hàng mục tiêu</label>
      <select class="input" name="target_group_id">
        <option value="">— Tất cả khách hàng —</option>
        <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Nội dung *</label><input class="input" name="message" required></div>
    <button type="submit" class="btn">Lưu chiến dịch</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên chiến dịch</th><th>Kênh</th><th>Nhóm mục tiêu</th><th>Nội dung</th><th>Ngày tạo</th></tr></thead>
    <tbody>
      <?php if (!$campaigns): ?>
        <tr><td colspan="5" class="text-center muted" style="padding:32px;">Chưa có chiến dịch nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($campaigns as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><?= e($c['channel']) ?></td>
          <td><?= e($c['group_name'] ?: 'Tất cả') ?></td>
          <td class="muted"><?= e($c['message']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
