<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
$stmt->execute([$id]);
$supplier = $stmt->fetch();
if (!$supplier) redirect('suppliers.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $amount = postFloat('amount');
    if ($amount <= 0 || $amount > (float) $supplier['debt']) {
        $error = 'Số tiền không hợp lệ';
    } else {
        $pdo->prepare('UPDATE suppliers SET debt = debt - ? WHERE id = ?')->execute([$amount, $id]);
        redirect('supplier_view.php?id=' . $id);
    }
}

$receipts = $pdo->prepare(
    'SELECT r.*, u.name AS created_by_name FROM stock_receipts r
     JOIN users u ON u.id = r.created_by_id
     WHERE r.supplier_id = ? ORDER BY r.created_at DESC LIMIT 50'
);
$receipts->execute([$id]);
$receipts = $receipts->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="suppliers.php" class="muted" style="font-size:14px;">← Nhà cung cấp</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;"><?= e($supplier['name']) ?></h1>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px;">
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Công nợ phải trả</div>
    <div style="font-size:18px;font-weight:700;<?= $supplier['debt'] > 0 ? 'color:#dc2626;' : '' ?>"><?= money($supplier['debt']) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">SĐT</div>
    <div style="font-size:16px;"><?= e($supplier['phone'] ?: '—') ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Địa chỉ</div>
    <div style="font-size:16px;"><?= e($supplier['address'] ?: '—') ?></div>
  </div>
</div>

<?php if ($supplier['debt'] > 0): ?>
<div class="card" style="margin-bottom:24px;max-width:480px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Ghi nhận trả nợ nhà cung cấp</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" style="display:flex;align-items:end;gap:12px;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div>
      <label style="font-size:12px;">Số tiền trả</label>
      <input type="number" name="amount" min="1" max="<?= (float) $supplier['debt'] ?>" required style="width:200px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;">
    </div>
    <button type="submit" class="btn">Ghi nhận trả</button>
  </form>
</div>
<?php endif; ?>

<h2 style="font-size:18px;font-weight:600;margin:0 0 12px;">Lịch sử nhập hàng</h2>
<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã phiếu</th><th>Người tạo</th><th>Ngày</th><th class="text-right">Tổng tiền</th></tr></thead>
    <tbody>
      <?php if (!$receipts): ?>
        <tr><td colspan="4" class="text-center muted" style="padding:24px;">Chưa có phiếu nhập nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($receipts as $r): ?>
        <tr>
          <td><a href="stock_receipt_view.php?id=<?= (int) $r['id'] ?>" style="font-family:monospace;"><?= e($r['code']) ?></a></td>
          <td><?= e($r['created_by_name']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($r['total_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
