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
        $pdo->prepare('UPDATE sales_channels SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        redirect('channels.php');
    } else {
        $name = post('name');
        $type = in_array($_POST['type'] ?? '', ['SHOPEE', 'LAZADA', 'TIKTOK', 'FACEBOOK', 'WEBSITE', 'OTHER'], true)
            ? $_POST['type'] : 'OTHER';
        $shopName = post('shop_name') ?: null;
        $note = post('note') ?: null;

        if ($name === '') {
            $error = 'Vui lòng nhập tên kênh bán hàng';
        } else {
            $pdo->prepare('INSERT INTO sales_channels (name, type, shop_name, note) VALUES (?,?,?,?)')
                ->execute([$name, $type, $shopName, $note]);
            redirect('channels.php');
        }
    }
}

$channels = $pdo->query(
    'SELECT sc.*, (SELECT COUNT(*) FROM orders o WHERE o.channel_id = sc.id) AS order_count
     FROM sales_channels sc ORDER BY sc.created_at DESC'
)->fetchAll();

$typeLabels = [
    'SHOPEE' => 'Shopee', 'LAZADA' => 'Lazada', 'TIKTOK' => 'TikTok Shop',
    'FACEBOOK' => 'Facebook', 'WEBSITE' => 'Website riêng', 'OTHER' => 'Khác',
];

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:8px;">Kênh bán hàng</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Khai báo các kênh bán hàng (Shopee, Facebook, Website...) để gắn vào đơn hàng và thống kê doanh
  thu theo kênh. Đây là khung dữ liệu nội bộ — <b>chưa kết nối API thật</b> của các sàn/nền tảng,
  đơn hàng từ các kênh này cần nhập tay hoặc import, gắn đúng kênh khi tạo/sửa đơn.
</p>

<div class="card" style="max-width:560px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm kênh bán hàng</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="grid-2">
      <div class="field">
        <label>Tên kênh *</label>
        <input class="input" name="name" required placeholder="vd: Shopee Shop ABC">
      </div>
      <div class="field">
        <label>Loại kênh</label>
        <select class="input" name="type">
          <?php foreach ($typeLabels as $val => $lbl): ?>
            <option value="<?= e($val) ?>"><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field">
      <label>Tên gian hàng / trang</label>
      <input class="input" name="shop_name" placeholder="vd: @shopabc">
    </div>
    <div class="field">
      <label>Ghi chú</label>
      <input class="input" name="note" placeholder="vd: người phụ trách, link trang...">
    </div>
    <button type="submit" class="btn">Thêm kênh</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr><th>Tên kênh</th><th>Loại</th><th>Gian hàng</th><th class="text-right">Số đơn</th><th class="text-center">Trạng thái</th><th></th></tr>
    </thead>
    <tbody>
      <?php if (!$channels): ?>
        <tr><td colspan="6" class="text-center muted" style="padding:32px;">Chưa có kênh bán hàng nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($channels as $c): ?>
        <tr>
          <td><?= e($c['name']) ?><?php if ($c['note']): ?><br><span class="muted" style="font-size:12px;"><?= e($c['note']) ?></span><?php endif; ?></td>
          <td><?= e($typeLabels[$c['type']] ?? $c['type']) ?></td>
          <td><?= e($c['shop_name'] ?: '—') ?></td>
          <td class="text-right"><?= (int) $c['order_count'] ?></td>
          <td class="text-center">
            <?php if ($c['is_active']): ?><span class="badge badge-green">Đang dùng</span>
            <?php else: ?><span class="badge badge-gray">Đã tắt</span><?php endif; ?>
          </td>
          <td class="text-right">
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;"><?= $c['is_active'] ? 'Tắt' : 'Bật' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
