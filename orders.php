<?php
require_once __DIR__ . '/inc_header.php';

$statusLabels = [
    'DRAFT' => 'Đặt hàng', 'APPROVED' => 'Duyệt', 'PACKED' => 'Đóng gói',
    'SHIPPED' => 'Xuất kho', 'COMPLETED' => 'Hoàn thành', 'CANCELLED' => 'Đã hủy',
];
$paymentStatusLabels = ['PAID' => 'Đã thanh toán', 'PARTIAL' => 'Trả một phần', 'UNPAID' => 'Chưa thanh toán'];

$status = $_GET['status'] ?? '';
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';
$staffId = (int) ($_GET['staff_id'] ?? 0);
$channelId = (int) ($_GET['channel_id'] ?? 0);

$pdo = db();
$staffList = $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll();
$channelList = $pdo->query('SELECT id, name FROM sales_channels ORDER BY name')->fetchAll();

$sql = 'SELECT o.*, c.name AS customer_name, u.name AS staff_name, sc.name AS channel_name FROM orders o
        LEFT JOIN customers c ON c.id = o.customer_id
        JOIN users u ON u.id = o.sold_by_id
        LEFT JOIN sales_channels sc ON sc.id = o.channel_id';
$where = [];
$params = [];

if ($status !== '' && array_key_exists($status, $statusLabels)) {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if ($fromDate !== '') {
    $where[] = 'o.created_at >= ?';
    $params[] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $where[] = 'o.created_at <= ?';
    $params[] = $toDate . ' 23:59:59';
}
if ($staffId) {
    $where[] = 'o.sold_by_id = ?';
    $params[] = $staffId;
}
if ($channelId) {
    $where[] = 'o.channel_id = ?';
    $params[] = $channelId;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY o.created_at DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
  <h1 style="font-size:24px;font-weight:600;">Danh sách đơn hàng</h1>
  <a href="pos.php" class="btn">+ Tạo đơn hàng</a>
</div>

<form style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;">
  <select name="status" class="input" style="max-width:180px;">
    <option value="">Tất cả trạng thái</option>
    <?php foreach ($statusLabels as $k => $label): ?>
      <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="staff_id" class="input" style="max-width:180px;">
    <option value="">Tất cả nhân viên</option>
    <?php foreach ($staffList as $s): ?>
      <option value="<?= (int) $s['id'] ?>" <?= $staffId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="channel_id" class="input" style="max-width:180px;">
    <option value="">Tất cả kênh bán</option>
    <?php foreach ($channelList as $ch): ?>
      <option value="<?= (int) $ch['id'] ?>" <?= $channelId === (int) $ch['id'] ? 'selected' : '' ?>><?= e($ch['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="from" class="input" style="max-width:160px;" value="<?= e($fromDate) ?>">
  <input type="date" name="to" class="input" style="max-width:160px;" value="<?= e($toDate) ?>">
  <button type="submit" class="btn btn-secondary">Lọc</button>
  <a href="orders.php" class="btn btn-secondary">Xóa lọc</a>
</form>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr><th style="width:32px;"></th><th>Mã đơn hàng</th><th>Ngày tạo</th><th>Khách hàng</th><th>Nhân viên</th><th>Kênh bán</th><th>Trạng thái</th><th>Thanh toán</th><th class="text-right">Tổng tiền</th></tr>
    </thead>
    <tbody>
      <?php if (!$orders): ?>
        <tr><td colspan="9" class="text-center muted" style="padding:32px;">Không có đơn hàng nào khớp bộ lọc.</td></tr>
      <?php endif; ?>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><a href="#" class="quick-toggle" data-id="<?= (int) $o['id'] ?>" style="text-decoration:none;">▸</a></td>
          <td><a href="order_view.php?id=<?= (int) $o['id'] ?>" style="font-family:monospace;"><?= e($o['code']) ?></a></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
          <td><?= e($o['customer_name'] ?: 'Khách lẻ') ?></td>
          <td class="muted"><?= e($o['staff_name']) ?></td>
          <td class="muted"><?= e($o['channel_name'] ?: 'Trực tiếp') ?></td>
          <td><span class="badge badge-gray"><?= e($statusLabels[$o['status']] ?? $o['status']) ?></span></td>
          <td>
            <?php if ($o['payment_status'] === 'PAID'): ?><span class="badge badge-green"><?= e($paymentStatusLabels['PAID']) ?></span>
            <?php elseif ($o['payment_status'] === 'PARTIAL'): ?><span class="badge badge-red"><?= e($paymentStatusLabels['PARTIAL']) ?></span>
            <?php else: ?><span class="badge badge-red"><?= e($paymentStatusLabels['UNPAID']) ?></span><?php endif; ?>
          </td>
          <td class="text-right" style="font-weight:600;"><?= money($o['total_amount']) ?></td>
        </tr>
        <tr class="quick-row" data-row-for="<?= (int) $o['id'] ?>" style="display:none;">
          <td></td>
          <td colspan="8" class="muted" style="font-size:13px;padding:8px 12px;background:#f8fafc;">Đang tải...</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
document.querySelectorAll('.quick-toggle').forEach(a => {
  a.addEventListener('click', (e) => {
    e.preventDefault();
    const id = a.dataset.id;
    const row = document.querySelector(`.quick-row[data-row-for="${id}"]`);
    const isOpen = row.style.display !== 'none';
    if (isOpen) { row.style.display = 'none'; a.textContent = '▸'; return; }
    a.textContent = '▾';
    row.style.display = '';
    fetch('order_quick.php?id=' + id)
      .then(r => r.text())
      .then(html => { row.querySelector('td:last-child').innerHTML = html; });
  });
});
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
