<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $type = ($_POST['type'] ?? '') === 'PAYMENT' ? 'PAYMENT' : 'RECEIPT';
    $amount = postFloat('amount');
    $reason = post('reason');
    $branchId = (int) ($currentUser['branch_id'] ?? 0);

    if ($amount <= 0) {
        $error = 'Số tiền phải lớn hơn 0';
    } elseif ($reason === '') {
        $error = 'Vui lòng nhập lý do thu/chi';
    } elseif (!$branchId) {
        $error = 'Tài khoản chưa được gán chi nhánh';
    } else {
        $prefix = $type === 'RECEIPT' ? 'PT' : 'PC';
        $code = $prefix . substr((string) (int) round(microtime(true) * 1000), -8);
        $pdo->prepare(
            'INSERT INTO cashbook_entries (code, branch_id, type, amount, reason, created_by_id) VALUES (?,?,?,?,?,?)'
        )->execute([$code, $branchId, $type, $amount, $reason, $currentUser['id']]);
        redirect('cashbook.php');
    }
}

$totals = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN type = 'RECEIPT' THEN amount ELSE 0 END), 0) AS total_receipt,
        COALESCE(SUM(CASE WHEN type = 'PAYMENT' THEN amount ELSE 0 END), 0) AS total_payment
     FROM cashbook_entries"
)->fetch();
$balance = (float) $totals['total_receipt'] - (float) $totals['total_payment'];

$entries = $pdo->query(
    'SELECT ce.*, b.name AS branch_name, u.name AS created_by_name
     FROM cashbook_entries ce
     JOIN branches b ON b.id = ce.branch_id
     JOIN users u ON u.id = ce.created_by_id
     ORDER BY ce.created_at DESC LIMIT 100'
)->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Sổ quỹ</h1>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Tổng thu</div>
    <div style="font-size:22px;font-weight:700;color:#059669;"><?= money($totals['total_receipt']) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Tổng chi</div>
    <div style="font-size:22px;font-weight:700;color:#dc2626;"><?= money($totals['total_payment']) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Tồn quỹ</div>
    <div style="font-size:22px;font-weight:700;color:#2563eb;"><?= money($balance) ?></div>
  </div>
</div>

<div class="card" style="max-width:480px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Tạo phiếu thu / chi</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

    <div class="field">
      <label>Loại phiếu</label>
      <select class="input" name="type">
        <option value="RECEIPT">Phiếu thu</option>
        <option value="PAYMENT">Phiếu chi</option>
      </select>
    </div>

    <div class="field">
      <label>Số tiền</label>
      <input class="input" type="number" min="1" name="amount" required>
    </div>

    <div class="field">
      <label>Lý do</label>
      <input class="input" name="reason" required placeholder="vd: Thu tiền bán hàng, Chi tiền điện...">
    </div>

    <button type="submit" class="btn">Lưu phiếu</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr><th>Mã phiếu</th><th>Loại</th><th>Lý do</th><th>Chi nhánh</th><th>Người tạo</th><th>Ngày</th><th class="text-right">Số tiền</th></tr>
    </thead>
    <tbody>
      <?php if (!$entries): ?>
        <tr><td colspan="7" class="text-center muted" style="padding:32px;">Chưa có phiếu thu/chi nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($entries as $en): ?>
        <tr>
          <td class="muted" style="font-family:monospace;font-size:12px;"><?= e($en['code']) ?></td>
          <td>
            <?php if ($en['type'] === 'RECEIPT'): ?>
              <span class="badge badge-green">Phiếu thu</span>
            <?php else: ?>
              <span class="badge badge-red">Phiếu chi</span>
            <?php endif; ?>
          </td>
          <td><?= e($en['reason']) ?></td>
          <td><?= e($en['branch_name']) ?></td>
          <td><?= e($en['created_by_name']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($en['created_at'])) ?></td>
          <td class="text-right" style="font-weight:600;<?= $en['type'] === 'RECEIPT' ? 'color:#059669;' : 'color:#dc2626;' ?>">
            <?= $en['type'] === 'RECEIPT' ? '+' : '-' ?><?= money($en['amount']) ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
