<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT c.*, u.name AS assigned_staff_name FROM customers c LEFT JOIN users u ON u.id = c.assigned_staff_id WHERE c.id = ?'
);
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
        $pdo->prepare('INSERT INTO customer_debt_entries (customer_id, amount, note, created_by_id) VALUES (?,?,?,?)')
            ->execute([$id, -$amount, 'Thu nợ trực tiếp', $currentUser['id']]);
        $collectBranchId = effectiveBranchId($currentUser);
        if ($collectBranchId) {
            recordCashbookEntry($collectBranchId, 'RECEIPT', $amount, 'Thu nợ trực tiếp KH ' . $customer['name'], 'CASH', $currentUser['id']);
        }
        redirect('customer_view.php?id=' . $id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    checkCsrf();
    if ($_POST['action'] === 'add_address') {
        $addr = post('address');
        if ($addr !== '') {
            if (isset($_POST['is_default'])) {
                $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?')->execute([$id]);
            }
            $pdo->prepare('INSERT INTO customer_addresses (customer_id, recipient_name, phone, address, is_default) VALUES (?,?,?,?,?)')
                ->execute([$id, post('recipient_name') ?: null, post('addr_phone') ?: null, $addr, isset($_POST['is_default']) ? 1 : 0]);
        }
        redirect('customer_view.php?id=' . $id . '#addresses');
    } elseif ($_POST['action'] === 'delete_address') {
        $pdo->prepare('DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?')->execute([(int) $_POST['address_id'], $id]);
        redirect('customer_view.php?id=' . $id . '#addresses');
    } elseif ($_POST['action'] === 'add_note') {
        $note = post('note');
        if ($note !== '') {
            $pdo->prepare('INSERT INTO customer_notes (customer_id, note, created_by_id) VALUES (?,?,?)')
                ->execute([$id, $note, $currentUser['id']]);
        }
        redirect('customer_view.php?id=' . $id . '#notes');
    } elseif ($_POST['action'] === 'delete_note') {
        $pdo->prepare('DELETE FROM customer_notes WHERE id = ? AND customer_id = ?')->execute([(int) $_POST['note_id'], $id]);
        redirect('customer_view.php?id=' . $id . '#notes');
    }
}

$addresses = $pdo->prepare('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, created_at DESC');
$addresses->execute([$id]);
$addresses = $addresses->fetchAll();

$notes = $pdo->prepare(
    'SELECT n.*, u.name AS created_by_name FROM customer_notes n JOIN users u ON u.id = n.created_by_id
     WHERE n.customer_id = ? ORDER BY n.created_at DESC'
);
$notes->execute([$id]);
$notes = $notes->fetchAll();

$debtEntries = $pdo->prepare(
    'SELECT de.*, o.code AS order_code, u.name AS created_by_name FROM customer_debt_entries de
     LEFT JOIN orders o ON o.id = de.order_id
     JOIN users u ON u.id = de.created_by_id
     WHERE de.customer_id = ? ORDER BY de.created_at DESC LIMIT 50'
);
$debtEntries->execute([$id]);
$debtEntries = $debtEntries->fetchAll();

$giftRedemptions = $pdo->prepare(
    'SELECT r.*, g.name AS gift_name FROM gift_redemptions r JOIN gifts g ON g.id = r.gift_id
     WHERE r.customer_id = ? ORDER BY r.created_at DESC LIMIT 50'
);
$giftRedemptions->execute([$id]);
$giftRedemptions = $giftRedemptions->fetchAll();

$tiers = $pdo->query('SELECT * FROM customer_tiers WHERE is_active = 1 ORDER BY min_spend')->fetchAll();
$currentTier = null;
$nextTier = null;
foreach ($tiers as $t) {
    if ($totalSpent >= (float) $t['min_spend']) {
        $currentTier = $t;
    } elseif ($nextTier === null) {
        $nextTier = $t;
    }
}
$genderLabels = ['MALE' => 'Nam', 'FEMALE' => 'Nữ', 'OTHER' => 'Khác'];
$paymentLabels = ['CASH' => 'Tiền mặt', 'BANK_TRANSFER' => 'Chuyển khoản', 'CARD' => 'Quẹt thẻ', 'QR_CODE' => 'Quét mã QR'];

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
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Hạng thẻ</div>
    <div style="font-size:18px;font-weight:700;">
      <?= $currentTier ? e($currentTier['name']) : '<span class="muted" style="font-size:14px;">Chưa có hạng</span>' ?>
    </div>
    <?php if ($nextTier): ?>
      <div class="muted" style="font-size:11px;margin-top:2px;">Cần thêm <?= money((float) $nextTier['min_spend'] - $totalSpent) ?> để lên hạng <?= e($nextTier['name']) ?></div>
    <?php endif; ?>
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

<?php if ($debtEntries): ?>
<h2 style="font-size:18px;font-weight:600;margin:0 0 12px;">Lịch sử công nợ</h2>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:24px;">
  <table>
    <thead><tr><th>Thời gian</th><th>Nội dung</th><th>Đơn hàng</th><th>Người thực hiện</th><th class="text-right">Số tiền</th></tr></thead>
    <tbody>
      <?php foreach ($debtEntries as $de): ?>
        <tr>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($de['created_at'])) ?></td>
          <td><?= e($de['note']) ?></td>
          <td><?php if ($de['order_code']): ?><a href="order_view.php?id=<?= (int) $de['order_id'] ?>" style="font-family:monospace;"><?= e($de['order_code']) ?></a><?php else: ?>—<?php endif; ?></td>
          <td class="muted"><?= e($de['created_by_name']) ?></td>
          <td class="text-right" style="font-weight:600;<?= $de['amount'] > 0 ? 'color:#dc2626;' : 'color:#059669;' ?>">
            <?= $de['amount'] > 0 ? '+' : '' ?><?= money($de['amount']) ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($giftRedemptions): ?>
<h2 style="font-size:18px;font-weight:600;margin:0 0 12px;">Lịch sử đổi quà</h2>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:24px;">
  <table>
    <thead><tr><th>Thời gian</th><th>Quà</th><th class="text-right">Điểm đã dùng</th></tr></thead>
    <tbody>
      <?php foreach ($giftRedemptions as $gr): ?>
        <tr>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($gr['created_at'])) ?></td>
          <td><?= e($gr['gift_name']) ?></td>
          <td class="text-right" style="font-weight:600;">-<?= (int) $gr['points_used'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<h2 style="font-size:18px;font-weight:600;margin:0 0 12px;">Thông tin khách hàng</h2>
<div class="card" style="max-width:640px;margin-bottom:24px;">
  <div class="grid-2">
    <div>
      <p style="margin:4px 0;"><b>SĐT:</b> <?= e($customer['phone'] ?: '—') ?></p>
      <p style="margin:4px 0;"><b>Email:</b> <?= e($customer['email'] ?: '—') ?></p>
      <p style="margin:4px 0;"><b>Ngày sinh:</b> <?= $customer['birthday'] ? date('d/m/Y', strtotime($customer['birthday'])) : '—' ?></p>
      <p style="margin:4px 0;"><b>Giới tính:</b> <?= e($genderLabels[$customer['gender']] ?? '—') ?></p>
      <p style="margin:4px 0;"><b>Địa chỉ:</b> <?= e($customer['address'] ?: '—') ?></p>
    </div>
    <div>
      <p style="margin:4px 0;"><b>Nhân viên phụ trách:</b> <?= e($customer['assigned_staff_name'] ?? '—') ?></p>
      <p style="margin:4px 0;"><b>Chiết khấu riêng:</b> <?= (float) $customer['discount_percent'] > 0 ? number_format((float) $customer['discount_percent'], 1) . '%' : '—' ?></p>
      <p style="margin:4px 0;"><b>Thanh toán mặc định:</b> <?= e($paymentLabels[$customer['default_payment_method']] ?? '—') ?></p>
      <p style="margin:4px 0;"><b>Mã số thuế:</b> <?= e($customer['tax_code'] ?: '—') ?></p>
      <?php if ($customer['tags']): ?><p style="margin:4px 0;"><b>Tags:</b> <?php foreach (explode(',', $customer['tags']) as $t): ?><span class="badge badge-gray" style="margin-right:4px;"><?= e(trim($t)) ?></span><?php endforeach; ?></p><?php endif; ?>
    </div>
  </div>
  <?php if ($customer['description']): ?><p style="margin:12px 0 0;" class="muted"><?= e($customer['description']) ?></p><?php endif; ?>
  <a href="customer_form.php?id=<?= (int) $customer['id'] ?>" class="btn btn-secondary" style="margin-top:12px;display:inline-block;">Sửa thông tin</a>
</div>

<div class="grid-2" style="margin-bottom:24px;max-width:900px;">
  <div id="addresses">
    <h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Địa chỉ</h2>
    <div class="card">
      <?php if (!$addresses): ?><p class="muted" style="margin:0 0 12px;">Chưa có địa chỉ nào.</p><?php endif; ?>
      <?php foreach ($addresses as $a): ?>
        <div style="padding:8px 0;border-top:1px solid #f1f5f9;font-size:13px;">
          <div style="display:flex;justify-content:space-between;">
            <div>
              <?php if ($a['is_default']): ?><span class="badge badge-green" style="margin-right:4px;">Mặc định</span><?php endif; ?>
              <b><?= e($a['recipient_name'] ?: $customer['name']) ?></b> <?= $a['phone'] ? '· ' . e($a['phone']) : '' ?>
            </div>
            <form method="post" onsubmit="return confirm('Xóa địa chỉ này?');">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="delete_address">
              <input type="hidden" name="address_id" value="<?= (int) $a['id'] ?>">
              <button type="submit" style="border:none;background:none;color:#ef4444;cursor:pointer;font-size:12px;">Xóa</button>
            </form>
          </div>
          <div class="muted"><?= e($a['address']) ?></div>
        </div>
      <?php endforeach; ?>
      <form method="post" style="margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="add_address">
        <div class="grid-2">
          <input class="input" name="recipient_name" placeholder="Tên người nhận" style="margin-bottom:8px;">
          <input class="input" name="addr_phone" placeholder="SĐT người nhận" style="margin-bottom:8px;">
        </div>
        <input class="input" name="address" placeholder="Địa chỉ *" style="margin-bottom:8px;">
        <label style="font-weight:400;font-size:12px;"><input type="checkbox" name="is_default"> Đặt làm mặc định</label>
        <button type="submit" class="btn btn-secondary" style="margin-top:8px;">Thêm địa chỉ</button>
      </form>
    </div>
  </div>

  <div id="notes">
    <h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Ghi chú</h2>
    <div class="card">
      <?php if (!$notes): ?><p class="muted" style="margin:0 0 12px;">Chưa có ghi chú nào.</p><?php endif; ?>
      <?php foreach ($notes as $n): ?>
        <div style="padding:8px 0;border-top:1px solid #f1f5f9;font-size:13px;">
          <div style="display:flex;justify-content:space-between;">
            <span class="muted"><?= e($n['created_by_name']) ?> · <?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></span>
            <form method="post" onsubmit="return confirm('Xóa ghi chú này?');">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="delete_note">
              <input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
              <button type="submit" style="border:none;background:none;color:#ef4444;cursor:pointer;font-size:12px;">Xóa</button>
            </form>
          </div>
          <div><?= nl2br(e($n['note'])) ?></div>
        </div>
      <?php endforeach; ?>
      <form method="post" style="margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="add_note">
        <textarea class="input" name="note" rows="2" placeholder="Thêm ghi chú về khách hàng..." style="margin-bottom:8px;"></textarea>
        <button type="submit" class="btn btn-secondary">Thêm ghi chú</button>
      </form>
    </div>
  </div>
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
