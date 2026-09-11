<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $code = strtoupper(post('code'));
    $type = ($_POST['discount_type'] ?? '') === 'PERCENT' ? 'PERCENT' : 'AMOUNT';
    $value = postFloat('discount_value');
    $minOrder = postFloat('min_order_amount');
    $maxUses = postInt('max_uses') ?: null;
    $startDate = post('start_date') ?: null;
    $endDate = post('end_date') ?: null;

    if ($code === '' || $value <= 0) {
        $error = 'Vui lòng nhập mã và giá trị giảm hợp lệ';
    } else {
        $check = $pdo->prepare('SELECT id FROM coupons WHERE code = ?');
        $check->execute([$code]);
        if ($check->fetch()) {
            $error = 'Mã giảm giá này đã tồn tại';
        } else {
            $pdo->prepare(
                'INSERT INTO coupons (code, discount_type, discount_value, min_order_amount, max_uses, start_date, end_date) VALUES (?,?,?,?,?,?,?)'
            )->execute([$code, $type, $value, $minOrder, $maxUses, $startDate, $endDate]);
            redirect('coupons.php');
        }
    }
}

$coupons = $pdo->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Mã giảm giá</h1>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Tạo mã giảm giá</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="grid-2">
      <div class="field"><label>Mã *</label><input class="input" name="code" required placeholder="vd: SALE10" style="text-transform:uppercase;"></div>
      <div class="field">
        <label>Loại giảm</label>
        <select class="input" name="discount_type">
          <option value="AMOUNT">Số tiền cố định</option>
          <option value="PERCENT">Phần trăm (%)</option>
        </select>
      </div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Giá trị giảm *</label><input class="input" type="number" min="1" name="discount_value" required></div>
      <div class="field"><label>Đơn tối thiểu</label><input class="input" type="number" min="0" name="min_order_amount" value="0"></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Số lượt dùng tối đa (bỏ trống = không giới hạn)</label><input class="input" type="number" min="1" name="max_uses"></div>
      <div></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Ngày bắt đầu</label><input class="input" type="date" name="start_date"></div>
      <div class="field"><label>Ngày kết thúc</label><input class="input" type="date" name="end_date"></div>
    </div>
    <button type="submit" class="btn">Tạo mã</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã</th><th>Loại giảm</th><th class="text-right">Giá trị</th><th class="text-right">Đơn tối thiểu</th><th class="text-right">Đã dùng</th><th>Hiệu lực</th><th></th></tr></thead>
    <tbody>
      <?php if (!$coupons): ?>
        <tr><td colspan="7" class="text-center muted" style="padding:32px;">Chưa có mã giảm giá nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($coupons as $c): ?>
        <tr>
          <td style="font-family:monospace;font-weight:600;"><?= e($c['code']) ?></td>
          <td><?= $c['discount_type'] === 'PERCENT' ? 'Phần trăm' : 'Số tiền' ?></td>
          <td class="text-right"><?= $c['discount_type'] === 'PERCENT' ? (int) $c['discount_value'] . '%' : money($c['discount_value']) ?></td>
          <td class="text-right"><?= money($c['min_order_amount']) ?></td>
          <td class="text-right"><?= (int) $c['used_count'] ?><?= $c['max_uses'] ? '/' . (int) $c['max_uses'] : '' ?></td>
          <td>
            <?php if (!$c['is_active']): ?><span class="badge badge-gray">Đã tắt</span>
            <?php elseif ($c['end_date'] && strtotime($c['end_date']) < time()): ?><span class="badge badge-red">Hết hạn</span>
            <?php else: ?><span class="badge badge-green">Đang hiệu lực</span><?php endif; ?>
          </td>
          <td>
            <form method="post" action="coupon_toggle.php" onsubmit="return confirm('<?= $c['is_active'] ? 'Tắt' : 'Bật lại' ?> mã giảm giá này?');">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;"><?= $c['is_active'] ? 'Tắt' : 'Bật lại' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
