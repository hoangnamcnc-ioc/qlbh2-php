<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$srcError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'create';

    // Nguon ban hang (cach khach tiep can: Zalo, goi dien...) - chi ADMIN, nay quan ly chung tren trang nay
    if (in_array($action, ['source_create', 'source_toggle'], true)) {
        requireRole('ADMIN');
        if ($action === 'source_toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare('UPDATE order_sources SET is_active = 1 - is_active WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
            logActivity('ORDER_SOURCE_TOGGLE', 'id=' . $id);
            redirect('channels.php#nguon');
        }
        $srcName = post('name');
        if ($srcName === '') {
            $srcError = 'Vui lòng nhập tên nguồn bán hàng';
        } else {
            $pdo->prepare('INSERT INTO order_sources (name, tenant_id) VALUES (?, ?)')->execute([$srcName, $tenantId]);
            logActivity('ORDER_SOURCE_CREATE', $srcName);
            redirect('channels.php#nguon');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE sales_channels SET is_active = 1 - is_active WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
        logActivity('CHANNEL_TOGGLE', 'id=' . $id);
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
            $pdo->prepare('INSERT INTO sales_channels (name, type, shop_name, note, tenant_id) VALUES (?,?,?,?,?)')
                ->execute([$name, $type, $shopName, $note, $tenantId]);
            logActivity('CHANNEL_CREATE', $name);
            redirect('channels.php');
        }
    }
}

$channelsStmt = $pdo->prepare(
    'SELECT sc.*, (SELECT COUNT(*) FROM orders o WHERE o.channel_id = sc.id) AS order_count
     FROM sales_channels sc WHERE sc.tenant_id = ? ORDER BY sc.created_at DESC'
);
$channelsStmt->execute([$tenantId]);
$channels = $channelsStmt->fetchAll();

$sources = [];
if (hasRole('ADMIN')) {
    $srcStmt = $pdo->prepare(
        'SELECT os.*, (SELECT COUNT(*) FROM orders o WHERE o.source_id = os.id) AS order_count
         FROM order_sources os WHERE os.tenant_id = ? ORDER BY os.id'
    );
    $srcStmt->execute([$tenantId]);
    $sources = $srcStmt->fetchAll();
}

$typeLabels = [
    'SHOPEE' => 'Shopee', 'LAZADA' => 'Lazada', 'TIKTOK' => 'TikTok Shop',
    'FACEBOOK' => 'Facebook', 'WEBSITE' => 'Website riêng', 'OTHER' => 'Khác',
];

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:8px;">Kênh bán hàng</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Khai báo các kênh bán hàng (Shopee, Facebook, Website...) để gắn vào đơn hàng và thống kê doanh
  thu theo từng kênh.
</p>

<?php hopDieuKienTrienKhai(
    'Phần mềm <b>thống kê doanh thu theo kênh</b> đầy đủ, nhưng <b>chưa kết nối API thật</b> của
     các sàn — đơn từ Shopee/Lazada/TikTok cần nhập tay hoặc nhập từ file, nhớ gắn đúng kênh khi
     tạo/sửa đơn.',
    [
        'Đăng ký tài khoản người bán và xin quyền truy cập API của từng sàn (mỗi sàn duyệt riêng).',
        'Nhờ lập trình phần đồng bộ đơn cho từng sàn — mỗi sàn một giao thức khác nhau, không dùng chung được.',
    ],
    'Riêng kênh <b>Website</b> thì đã chạy thật: xem <a href="online_shop_settings.php">Đặt hàng online</a>.'
); ?>

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

<?php if (hasRole('ADMIN')): ?>
<h2 id="nguon" style="font-size:18px;font-weight:600;margin:32px 0 6px;">Nguồn bán hàng</h2>
<p class="muted" style="margin:0 0 12px;font-size:13px;max-width:720px;">
  Kênh ở trên là <b>nền tảng bán</b> (Shopee, Website...). Nguồn là <b>cách khách tiếp cận để đặt hàng</b>
  (Tại quầy, Gọi điện thoại, Nhắn tin Zalo...). Một đơn có thể gắn cả kênh lẫn nguồn khi tạo / sửa đơn, phục vụ thống kê.
</p>
<div class="card" style="max-width:480px;margin-bottom:16px;">
  <?php if ($srcError): ?><div class="alert alert-error"><?= e($srcError) ?></div><?php endif; ?>
  <form method="post" style="display:flex;gap:8px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="source_create">
    <div class="field" style="flex:1;margin:0;"><label>Tên nguồn *</label><input class="input" name="name" required placeholder="vd: Nhắn tin Zalo"></div>
    <button type="submit" class="btn">Thêm nguồn</button>
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
          <td class="text-center"><?php if ($s['is_active']): ?><span class="badge badge-green">Đang dùng</span><?php else: ?><span class="badge badge-gray">Đã tắt</span><?php endif; ?></td>
          <td class="text-right">
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="source_toggle">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;"><?= $s['is_active'] ? 'Tắt' : 'Bật' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
