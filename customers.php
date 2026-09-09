<?php
require_once __DIR__ . '/inc_header.php';

$q = trim($_GET['q'] ?? '');
$tab = $_GET['tab'] ?? 'all';
$pdo = db();

$sql = 'SELECT c.*, g.name AS group_name,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.status != "CANCELLED") AS order_count,
        (SELECT COALESCE(SUM(o.total_amount),0) FROM orders o WHERE o.customer_id = c.id AND o.status != "CANCELLED") AS total_spent
        FROM customers c LEFT JOIN customer_groups g ON g.id = c.group_id';
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.code LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
if ($tab === 'active') {
    $where[] = 'EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = c.id AND o.status != "CANCELLED")';
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY c.created_at DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
  <h1 style="font-size:24px;font-weight:600;">Danh sách khách hàng</h1>
  <div style="display:flex;gap:8px;">
    <?php if (hasRole('ADMIN', 'MANAGER')): ?>
      <a href="customers_export.php" class="btn btn-secondary">Xuất file</a>
      <a href="customers_import.php" class="btn btn-secondary">Nhập file</a>
      <a href="groups.php" class="btn btn-secondary">Nhóm khách hàng</a>
    <?php endif; ?>
    <a href="customer_form.php" class="btn">+ Thêm khách hàng</a>
  </div>
</div>

<div style="display:flex;gap:4px;margin-bottom:12px;border-bottom:1px solid #e2e8f0;">
  <a href="?tab=all<?= $q ? '&q=' . urlencode($q) : '' ?>" style="padding:8px 12px;font-size:14px;<?= $tab !== 'active' ? 'border-bottom:2px solid #2563eb;color:#2563eb;font-weight:600;' : 'color:#64748b;' ?>">Tất cả khách hàng</a>
  <a href="?tab=active<?= $q ? '&q=' . urlencode($q) : '' ?>" style="padding:8px 12px;font-size:14px;<?= $tab === 'active' ? 'border-bottom:2px solid #2563eb;color:#2563eb;font-weight:600;' : 'color:#64748b;' ?>">Đang giao dịch</a>
</div>

<form style="margin-bottom:16px;">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <input type="text" name="q" class="input" style="max-width:320px;" placeholder="Tìm theo tên, SĐT hoặc mã khách hàng..." value="<?= e($q) ?>">
</form>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>Mã KH</th><th>Tên khách hàng</th><th>SĐT</th><th>Nhóm</th>
        <th class="text-right">Công nợ</th><th class="text-right">Tổng chi tiêu</th><th class="text-right">Số đơn</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$customers): ?>
        <tr><td colspan="7" class="text-center muted" style="padding:32px;">Chưa có khách hàng nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($customers as $c): ?>
        <tr>
          <td class="muted" style="font-family:monospace;font-size:12px;"><?= e($c['code']) ?></td>
          <td><a href="customer_view.php?id=<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a></td>
          <td><?= e($c['phone'] ?: '—') ?></td>
          <td><?= e($c['group_name'] ?: '—') ?></td>
          <td class="text-right" style="<?= $c['debt'] > 0 ? 'color:#dc2626;font-weight:600;' : '' ?>"><?= money($c['debt']) ?></td>
          <td class="text-right"><?= money($c['total_spent']) ?></td>
          <td class="text-right"><?= (int) $c['order_count'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
