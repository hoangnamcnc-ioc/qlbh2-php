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
        $pdo->prepare('UPDATE gifts SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        logActivity('GIFT_TOGGLE', 'id=' . $id);
        redirect('gifts.php');
    } else {
        $name = post('name');
        $pointsRequired = postInt('points_required');
        $stockRaw = post('stock_qty');
        $stockQty = $stockRaw === '' ? null : max(0, (int) $stockRaw);
        $note = post('note') ?: null;

        if ($name === '' || $pointsRequired <= 0) {
            $error = 'Vui lòng nhập tên quà và số điểm cần đổi (lớn hơn 0)';
        } else {
            $pdo->prepare('INSERT INTO gifts (name, points_required, stock_qty, note) VALUES (?,?,?,?)')
                ->execute([$name, $pointsRequired, $stockQty, $note]);
            logActivity('GIFT_CREATE', $name);
            redirect('gifts.php');
        }
    }
}

$gifts = $pdo->query(
    'SELECT g.*, (SELECT COUNT(*) FROM gift_redemptions r WHERE r.gift_id = g.id) AS redeemed_count
     FROM gifts g ORDER BY g.points_required'
)->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Danh mục quà đổi điểm</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Khai báo quà tặng khách hàng có thể đổi bằng điểm tích lũy. Đổi quà thực hiện ngay trong
  <a href="pos.php">Bán hàng (POS)</a> — nút "Đổi quà" — chọn khách hàng theo SĐT rồi chọn quà phù
  hợp với số điểm đang có.
</p>

<div class="card" style="max-width:560px;margin-bottom:24px;">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="grid-2">
      <div class="field"><label>Tên quà tặng *</label><input class="input" name="name" required placeholder="vd: Bình nước giữ nhiệt"></div>
      <div class="field"><label>Số điểm cần đổi *</label><input class="input" type="number" min="1" name="points_required" required></div>
    </div>
    <div class="field">
      <label>Số lượng trong kho (để trống = không giới hạn)</label>
      <input class="input" type="number" min="0" name="stock_qty" placeholder="vd: 50">
    </div>
    <div class="field"><label>Ghi chú</label><input class="input" name="note" placeholder="vd: mô tả quà, điều kiện áp dụng..."></div>
    <button type="submit" class="btn">Thêm quà tặng</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên quà</th><th class="text-right">Điểm cần đổi</th><th class="text-right">Tồn kho</th><th class="text-right">Đã đổi</th><th class="text-center">Trạng thái</th><th></th></tr></thead>
    <tbody>
      <?php if (!$gifts): ?><tr><td colspan="6" class="text-center muted" style="padding:24px;">Chưa có quà tặng nào.</td></tr><?php endif; ?>
      <?php foreach ($gifts as $g): ?>
        <tr>
          <td><?= e($g['name']) ?><?php if ($g['note']): ?><br><span class="muted" style="font-size:12px;"><?= e($g['note']) ?></span><?php endif; ?></td>
          <td class="text-right"><?= (int) $g['points_required'] ?></td>
          <td class="text-right"><?= $g['stock_qty'] === null ? 'Không giới hạn' : (int) $g['stock_qty'] ?></td>
          <td class="text-right"><?= (int) $g['redeemed_count'] ?></td>
          <td class="text-center">
            <?php if ($g['is_active']): ?><span class="badge badge-green">Đang dùng</span><?php else: ?><span class="badge badge-gray">Đã tắt</span><?php endif; ?>
          </td>
          <td class="text-right">
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;"><?= $g['is_active'] ? 'Tắt' : 'Bật' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
