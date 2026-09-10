<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE customer_tiers SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        logActivity('CUSTOMER_TIER_TOGGLE', 'id=' . $id);
        redirect('customer_tiers.php');
    } else {
        $name = post('name');
        $minSpend = postFloat('min_spend');
        $discountPercent = postFloat('discount_percent');

        if ($name === '') {
            $error = 'Vui lòng nhập tên hạng thẻ';
        } else {
            $pdo->prepare('INSERT INTO customer_tiers (name, min_spend, discount_percent) VALUES (?,?,?)')
                ->execute([$name, $minSpend, $discountPercent]);
            logActivity('CUSTOMER_TIER_CREATE', $name);
            redirect('customer_tiers.php');
        }
    }
}

$tiers = $pdo->query('SELECT * FROM customer_tiers ORDER BY min_spend')->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Hạng thẻ khách hàng</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Khai báo các mốc chi tiêu để tự động xếp hạng khách hàng (theo tổng chi tiêu tích lũy, không tính
  đơn đã hủy) — hiển thị trong trang chi tiết khách hàng. Đây là danh mục tham chiếu, chưa tự động
  áp dụng chiết khấu theo hạng vào đơn hàng.
</p>

<div class="card" style="max-width:520px;margin-bottom:24px;">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="field" style="flex:1;margin:0;min-width:140px;"><label>Tên hạng *</label><input class="input" name="name" required placeholder="vd: Vàng"></div>
    <div class="field" style="margin:0;width:150px;"><label>Chi tiêu tối thiểu</label><input class="input" type="number" min="0" name="min_spend" value="0"></div>
    <div class="field" style="margin:0;width:120px;"><label>Chiết khấu %</label><input class="input" type="number" min="0" max="100" step="0.1" name="discount_percent" value="0"></div>
    <button type="submit" class="btn">Thêm</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên hạng</th><th class="text-right">Chi tiêu tối thiểu</th><th class="text-right">Chiết khấu</th><th class="text-center">Trạng thái</th><th></th></tr></thead>
    <tbody>
      <?php if (!$tiers): ?><tr><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có hạng thẻ nào.</td></tr><?php endif; ?>
      <?php foreach ($tiers as $t): ?>
        <tr>
          <td><?= e($t['name']) ?></td>
          <td class="text-right"><?= money($t['min_spend']) ?></td>
          <td class="text-right"><?= number_format((float) $t['discount_percent'], 1) ?>%</td>
          <td class="text-center">
            <?php if ($t['is_active']): ?><span class="badge badge-green">Đang dùng</span><?php else: ?><span class="badge badge-gray">Đã tắt</span><?php endif; ?>
          </td>
          <td class="text-right">
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;"><?= $t['is_active'] ? 'Tắt' : 'Bật' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
