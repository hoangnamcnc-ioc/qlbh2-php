<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;
$paymentLabels = ['CASH' => 'Tiền mặt', 'BANK_TRANSFER' => 'Chuyển khoản', 'CARD' => 'Quẹt thẻ', 'QR_CODE' => 'Quét mã QR'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $type = ($_POST['type'] ?? '') === 'PAYMENT' ? 'PAYMENT' : 'RECEIPT';
    $amount = postFloat('amount');
    $reason = post('reason');
    $paymentMethod = in_array($_POST['payment_method'] ?? '', ['CASH', 'BANK_TRANSFER', 'CARD', 'QR_CODE'], true)
        ? $_POST['payment_method'] : 'CASH';
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
            'INSERT INTO cashbook_entries (code, branch_id, type, amount, reason, payment_method, created_by_id) VALUES (?,?,?,?,?,?,?)'
        )->execute([$code, $branchId, $type, $amount, $reason, $paymentMethod, $currentUser['id']]);
        redirect('cashbook.php');
    }
}

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';
$typeFilter = $_GET['type'] ?? '';
$branchFilter = (int) ($_GET['branch_id'] ?? 0);
$paymentFilter = $_GET['payment_method'] ?? '';

$branches = $pdo->query('SELECT * FROM branches ORDER BY name')->fetchAll();

// Số dư đầu kỳ: tổng thu - chi của mọi phiếu trước ngày bắt đầu lọc.
$opening = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN type='RECEIPT' THEN amount ELSE -amount END),0) AS bal
     FROM cashbook_entries WHERE created_at < ?"
);
$opening->execute([$fromDt]);
$openingBalance = (float) $opening->fetch()['bal'];

$where = ['ce.created_at BETWEEN ? AND ?'];
$params = [$fromDt, $toDt];
if ($typeFilter === 'RECEIPT' || $typeFilter === 'PAYMENT') {
    $where[] = 'ce.type = ?';
    $params[] = $typeFilter;
}
if ($branchFilter) {
    $where[] = 'ce.branch_id = ?';
    $params[] = $branchFilter;
}
if (array_key_exists($paymentFilter, $paymentLabels)) {
    $where[] = 'ce.payment_method = ?';
    $params[] = $paymentFilter;
}
$whereSql = implode(' AND ', $where);

$totals = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN type = 'RECEIPT' THEN amount ELSE 0 END), 0) AS total_receipt,
            COALESCE(SUM(CASE WHEN type = 'PAYMENT' THEN amount ELSE 0 END), 0) AS total_payment
     FROM cashbook_entries ce WHERE $whereSql"
);
$totals->execute($params);
$totals = $totals->fetch();
$closingBalance = $openingBalance + (float) $totals['total_receipt'] - (float) $totals['total_payment'];

$entries = $pdo->prepare(
    "SELECT ce.*, b.name AS branch_name, u.name AS created_by_name
     FROM cashbook_entries ce
     JOIN branches b ON b.id = ce.branch_id
     JOIN users u ON u.id = ce.created_by_id
     WHERE $whereSql
     ORDER BY ce.created_at DESC LIMIT 200"
);
$entries->execute($params);
$entries = $entries->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">Sổ quỹ</h1>
  <a href="cashbook_export.php?<?= http_build_query($_GET) ?>" class="btn btn-secondary">Xuất file</a>
</div>

<form style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
  <div><label style="display:block;font-size:12px;margin-bottom:2px;">Từ ngày</label>
    <input type="date" name="from" value="<?= e($from) ?>" class="input" style="width:auto;"></div>
  <div><label style="display:block;font-size:12px;margin-bottom:2px;">Đến ngày</label>
    <input type="date" name="to" value="<?= e($to) ?>" class="input" style="width:auto;"></div>
  <select name="type" class="input" style="max-width:150px;">
    <option value="">Tất cả</option>
    <option value="RECEIPT" <?= $typeFilter === 'RECEIPT' ? 'selected' : '' ?>>Phiếu thu</option>
    <option value="PAYMENT" <?= $typeFilter === 'PAYMENT' ? 'selected' : '' ?>>Phiếu chi</option>
  </select>
  <select name="branch_id" class="input" style="max-width:170px;">
    <option value="">Tất cả chi nhánh</option>
    <?php foreach ($branches as $b): ?>
      <option value="<?= (int) $b['id'] ?>" <?= $branchFilter === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="payment_method" class="input" style="max-width:170px;">
    <option value="">Tất cả hình thức TT</option>
    <?php foreach ($paymentLabels as $val => $lbl): ?>
      <option value="<?= e($val) ?>" <?= $paymentFilter === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary">Lọc</button>
  <a href="cashbook.php" class="btn btn-secondary">Xóa lọc</a>
</form>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;">
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Số dư đầu kỳ</div>
    <div style="font-size:18px;font-weight:700;"><?= money($openingBalance) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Tổng thu</div>
    <div style="font-size:18px;font-weight:700;color:#059669;">+<?= money($totals['total_receipt']) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Tổng chi</div>
    <div style="font-size:18px;font-weight:700;color:#dc2626;">-<?= money($totals['total_payment']) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Tồn cuối kỳ</div>
    <div style="font-size:18px;font-weight:700;color:#2563eb;"><?= money($closingBalance) ?></div>
  </div>
</div>

<div class="card" style="max-width:480px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Tạo phiếu thu / chi</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

    <div class="grid-2">
      <div class="field">
        <label>Loại phiếu</label>
        <select class="input" name="type">
          <option value="RECEIPT">Phiếu thu</option>
          <option value="PAYMENT">Phiếu chi</option>
        </select>
      </div>
      <div class="field">
        <label>Hình thức thanh toán</label>
        <select class="input" name="payment_method">
          <?php foreach ($paymentLabels as $val => $lbl): ?>
            <option value="<?= e($val) ?>"><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
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
      <tr><th>Mã phiếu</th><th>Loại</th><th>Lý do</th><th>Hình thức TT</th><th>Chi nhánh</th><th>Người tạo</th><th>Ngày</th><th class="text-right">Số tiền</th></tr>
    </thead>
    <tbody>
      <?php if (!$entries): ?>
        <tr><td colspan="8" class="text-center muted" style="padding:32px;">Không có phiếu thu/chi nào khớp bộ lọc.</td></tr>
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
          <td class="muted"><?= e($paymentLabels[$en['payment_method']] ?? $en['payment_method']) ?></td>
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
