<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) redirect('customers.php');

$stmt = $pdo->prepare(
    "SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 50"
);
$stmt->execute([$id]);
$orders = $stmt->fetchAll();

$validOrders = array_filter($orders, fn($o) => $o['status'] !== 'CANCELLED');
$totalSpent = array_sum(array_map(fn($o) => (float) $o['total_amount'], $validOrders));

$debtError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['amount'])) {
    checkCsrf();
    $amount = postFloat('amount');
    if ($amount <= 0 || $amount > (float) $customer['debt']) {
        $debtError = 'Số tiền không hợp lệ';
    } else {
        $pdo->prepare('UPDATE customers SET debt = debt - ? WHERE id = ?')->execute([$amount, $id]);
        redirect('customer_view.php?id=' . $id);
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="customers.php" class="muted" style="font-size:14px;">← Danh sách khách hàng</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 0;"><?= e($customer['name']) ?></h1>
<p class="muted" style="font-family:monospace;margin:0 0 24px;"><?= e($customer['code']) ?></p>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px;">
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Công nợ hiện tại</div>
    <div style="font-size:18px;font-weight:700;<?= $customer['debt'] > 0 ? 'color:#dc2626;' : '' ?>"><?= money($customer['debt']) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Điểm tích lũy</div>
    <div style="font-size:18px;font-weight:700;"><?= (int) $customer['loyalty_points'] ?> điểm</div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Tổng chi tiêu</div>
    <div style="font-size:18px;font-weight:700;"><?= money($totalSpent) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Số đơn hàng</div>
    <div style="font-size:18px;font-weight:700;"><?= count($validOrders) ?></div>
  </div>
</div>

<?php if ($customer['debt'] > 0): ?>
<div class="card" style="margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Ghi nhận thu công nợ</h2>
  <?php if ($debtError): ?><div class="alert alert-error"><?= e($debtError) ?></div><?php endif; ?>
  <form method="post" style="display:flex;align-items:end;gap:12px;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div>
      <label style="font-size:12px;">Số tiền thu (nợ hiện tại: <?= money($customer['debt']) ?>)</label>
      <input type="number" name="amount" min="1" max="<?= (float) $customer['debt'] ?>" required style="width:200px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;">
    </div>
    <button type="submit" class="btn" style="background:#059669;">Ghi nhận thu</button>
  </form>
</div>
<?php endif; ?>

<h2 style="font-size:18px;font-weight:600;margin:0 0 12px;">Thông tin khách hàng</h2>
<div class="card" style="max-width:640px;margin-bottom:24px;">
  <p style="margin:4px 0;"><b>SĐT:</b> <?= e($customer['phone'] ?: '—') ?></p>
  <p style="margin:4px 0;"><b>Địa chỉ:</b> <?= e($customer['address'] ?: '—') ?></p>
  <a href="customer_form.php?id=<?= (int) $customer['id'] ?>" class="btn btn-secondary" style="margin-top:8px;display:inline-block;">Sửa thông tin</a>
</div>

<h2 style="font-size:18px;font-weight:600;margin:0 0 12px;">Lịch sử đơn hàng</h2>
<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã đơn</th><th>Ngày</th><th>Trạng thái</th><th class="text-right">Tổng tiền</th></tr></thead>
    <tbody>
      <?php if (!$orders): ?>
        <tr><td colspan="4" class="text-center muted" style="padding:24px;">Khách hàng chưa có đơn hàng nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><a href="order_view.php?id=<?= (int) $o['id'] ?>" style="font-family:monospace;"><?= e($o['code']) ?></a></td>
          <td class="muted"><?= date('d/m/Y', strtotime($o['created_at'])) ?></td>
          <td><?= e($o['status']) ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($o['total_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
