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
        $pdo->prepare('UPDATE order_sources SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        redirect('order_sources.php');
    } else {
        $name = post('name');
        if ($name === '') {
            $error = 'Vui lòng nhập tên nguồn bán hàng';
        } else {
            $pdo->prepare('INSERT INTO order_sources (name) VALUES (?)')->execute([$name]);
            redirect('order_sources.php');
        }
    }
}

$sources = $pdo->query(
    'SELECT os.*, (SELECT COUNT(*) FROM orders o WHERE o.source_id = os.id) AS order_count
     FROM order_sources os ORDER BY os.id'
)->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Nguồn bán hàng</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Khai báo nguồn tạo ra đơn hàng (vd: Tại quầy, Gọi điện thoại, Nhắn tin Facebook, Zalo...) để gán
  vào từng đơn khi sửa đơn hàng, phục vụ thống kê. Khác với "Kênh bán hàng" (nền tảng bán như
  Shopee/Website) — Nguồn mô tả cách khách tiếp cận để đặt hàng.
</p>

<div class="card" style="max-width:480px;margin-bottom:24px;">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" style="display:flex;gap:8px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="field" style="flex:1;margin:0;"><label>Tên nguồn *</label><input class="input" name="name" required placeholder="vd: Nhắn tin Zalo"></div>
    <button type="submit" class="btn">Thêm</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên nguồn</th><th class="text-right">Số đơn</th><th class="text-center">Trạng thái</th><th></th></tr></thead>
    <tbody>
      <?php if (!$sources): ?><tr><td colspan="4" class="text-center muted" style="padding:24px;">Chưa có nguồn bán hàng nào.</td></tr><?php endif; ?>
      <?php foreach ($sources as $s): ?>
        <tr>
          <td><?= e($s['name']) ?></td>
          <td class="text-right"><?= (int) $s['order_count'] ?></td>
          <td class="text-center">
            <?php if ($s['is_active']): ?><span class="badge badge-green">Đang dùng</span><?php else: ?><span class="badge badge-gray">Đã tắt</span><?php endif; ?>
          </td>
          <td class="text-right">
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;"><?= $s['is_active'] ? 'Tắt' : 'Bật' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
