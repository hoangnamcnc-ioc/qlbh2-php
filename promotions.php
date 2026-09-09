<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = post('name');
    $minOrder = postFloat('min_order_amount');
    $percent = postFloat('discount_percent');
    $startDate = post('start_date') ?: null;
    $endDate = post('end_date') ?: null;

    if ($name === '' || $percent <= 0 || $percent > 100) {
        $error = 'Vui lòng nhập tên chương trình và % giảm hợp lệ (1-100)';
    } else {
        $pdo->prepare('INSERT INTO promotions (name, min_order_amount, discount_percent, start_date, end_date) VALUES (?,?,?,?,?)')
            ->execute([$name, $minOrder, $percent, $startDate, $endDate]);
        redirect('promotions.php');
    }
}

$promotions = $pdo->query('SELECT * FROM promotions ORDER BY created_at DESC')->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:8px;">Quản lý khuyến mại</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Khác với Mã giảm giá — chương trình này <b>tự động áp dụng</b> trong POS khi đơn hàng đạt giá trị
  tối thiểu, khách hàng không cần nhập mã. Nếu nhiều chương trình cùng đủ điều kiện, hệ thống chọn
  chương trình có % giảm cao nhất.
</p>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Tạo chương trình khuyến mại</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field"><label>Tên chương trình *</label><input class="input" name="name" required placeholder="vd: Giảm 5% cho đơn từ 500k"></div>
    <div class="grid-2">
      <div class="field"><label>Đơn tối thiểu</label><input class="input" type="number" min="0" name="min_order_amount" value="0"></div>
      <div class="field"><label>% Giảm giá *</label><input class="input" type="number" min="1" max="100" step="0.1" name="discount_percent" required></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Ngày bắt đầu</label><input class="input" type="date" name="start_date"></div>
      <div class="field"><label>Ngày kết thúc</label><input class="input" type="date" name="end_date"></div>
    </div>
    <button type="submit" class="btn">Tạo chương trình</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên chương trình</th><th class="text-right">Đơn tối thiểu</th><th class="text-right">% Giảm</th><th>Thời gian</th><th>Trạng thái</th></tr></thead>
    <tbody>
      <?php if (!$promotions): ?>
        <tr><td colspan="5" class="text-center muted" style="padding:32px;">Chưa có chương trình khuyến mại nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($promotions as $p):
        $active = $p['is_active']
            && (!$p['start_date'] || strtotime($p['start_date']) <= time())
            && (!$p['end_date'] || strtotime($p['end_date'] . ' 23:59:59') >= time());
      ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td class="text-right"><?= money($p['min_order_amount']) ?></td>
          <td class="text-right"><?= rtrim(rtrim(number_format((float) $p['discount_percent'], 1), '0'), '.') ?>%</td>
          <td class="muted"><?= $p['start_date'] ? date('d/m/Y', strtotime($p['start_date'])) : '—' ?> → <?= $p['end_date'] ? date('d/m/Y', strtotime($p['end_date'])) : '—' ?></td>
          <td><?php if ($active): ?><span class="badge badge-green">Đang áp dụng</span><?php else: ?><span class="badge badge-gray">Không áp dụng</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
