<?php
/**
 * Mo ca / dong ca ban hang: kiem tien mat dau ca (mo ca) va cuoi ca (dong ca) cho 1 chi nhanh -
 * truoc day khong co, thu ngan phai tu ghi so tay so tien mat dau/cuoi ngay. Tien mat du kien
 * cuoi ca = tien mo ca + phieu thu tien mat - phieu chi tien mat (cashbook_entries) phat sinh
 * trong khoang thoi gian ca dang mo. Khong cong rieng bang payments vi moi khoan thu tien ban
 * hang (pos_checkout.php, order_pay.php...) da duoc ghi lai vao cashbook_entries qua
 * recordCashbookEntry() - cong ca hai nguon se bi tinh trung tien ban hang.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$branchId = effectiveBranchId($currentUser);
$tenantId = currentTenantId();
$error = null;
$itemsOpen = layChecklist($pdo, $tenantId, 'OPEN');
$itemsClose = layChecklist($pdo, $tenantId, 'CLOSE');
$staff = $pdo->prepare('SELECT id, name FROM users WHERE tenant_id = ? AND is_active = 1 ORDER BY name');
$staff->execute([$tenantId]);
$staff = $staff->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['chk_add', 'chk_del', 'chk_seed'], true) && isManagerTier()) {
        $kind = ($_POST['kind'] ?? '') === 'CLOSE' ? 'CLOSE' : 'OPEN';
        if ($action === 'chk_add' && post('label') !== '') {
            $pdo->prepare('INSERT INTO checklist_items (tenant_id, kind, label, sort_order) VALUES (?,?,?,?)')->execute([$tenantId, $kind, mb_substr(post('label'), 0, 255), 100 + (int) $pdo->query('SELECT COUNT(*) FROM checklist_items WHERE tenant_id = ' . $tenantId)->fetchColumn()]);
        } elseif ($action === 'chk_del') {
            $pdo->prepare('DELETE FROM checklist_items WHERE id = ? AND tenant_id = ?')->execute([(int) ($_POST['id'] ?? 0), $tenantId]);
        } elseif ($action === 'chk_seed' && !$itemsOpen && !$itemsClose) {
            $seed = ['OPEN' => ['Kiểm đếm tiền mặt đầu ca', 'Bật đèn, máy tính, máy in hóa đơn', 'Lau dọn quầy, sắp xếp hàng lên kệ', 'Kiểm tra hàng hết hạn, hàng hỏng', 'Bật camera, kiểm tra mạng'],
                'CLOSE' => ['Đếm tiền mặt, chốt két', 'Kiểm tra hàng dễ mất', 'Tắt đèn, máy lạnh, thiết bị điện', 'Khóa cửa, bật báo động', 'Ghi nhận việc cần làm cho ca sau']];
            $ins = $pdo->prepare('INSERT INTO checklist_items (tenant_id, kind, label, sort_order) VALUES (?,?,?,?)');
            foreach ($seed as $k => $ls) foreach ($ls as $i => $l) $ins->execute([$tenantId, $k, $l, $i]);
        }
        redirect('ca_ban_hang.php?cfg=1');
    }

    if ($action === 'open') {
        $openingCash = postFloat('opening_cash');
        $openNote = post('open_note');
        [$resOpen, $errOpen] = docChecklistTuForm($itemsOpen);
        if (!$branchId) {
            $error = 'Tài khoản chưa được gán chi nhánh, không thể mở ca';
        } elseif ($openingCash < 0) {
            $error = 'Số tiền mở ca không được âm';
        } elseif ($errOpen) {
            $error = $errOpen;
        } else {
            // Khoa dong chi nhanh trong giao dich de hai nguoi bam "Mo ca" cung luc khong tao 2 ca.
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? FOR UPDATE')
                ->execute([$branchId, currentTenantId()]);
            $existing = $pdo->prepare("SELECT id FROM pos_shifts WHERE branch_id = ? AND status = 'OPEN'");
            $existing->execute([$branchId]);
            if ($existing->fetchColumn()) {
                $pdo->rollBack();
                $error = 'Chi nhánh này đang có ca chưa đóng, không thể mở ca mới';
            } else {
                $pdo->prepare(
                    'INSERT INTO pos_shifts (branch_id, opened_by_id, opening_cash, open_note) VALUES (?, ?, ?, ?)'
                )->execute([$branchId, $currentUser['id'], $openingCash, $openNote]);
                luuChecklist($pdo, (int) $pdo->lastInsertId(), 'OPEN', $resOpen);
                chamCongVao($pdo, $tenantId, (int) $currentUser['id']);
                $pdo->commit();
                redirect('ca_ban_hang.php');
            }
        }
    } elseif ($action === 'close') {
        $shiftId = postInt('shift_id');
        $countedCash = postFloat('counted_cash');
        $closeNote = post('close_note');
        [$resClose, $errClose] = docChecklistTuForm($itemsClose);
        $handTo = (int) ($_POST['handover_to_id'] ?? 0);
        if (!in_array($handTo, array_map(static fn($u) => (int) $u['id'], $staff), true)) $handTo = 0;
        $handNote = mb_substr(post('handover_note'), 0, 2000);

        $stmt = $pdo->prepare("SELECT * FROM pos_shifts WHERE id = ? AND branch_id = ? AND status = 'OPEN'");
        $stmt->execute([$shiftId, $branchId]);
        $shift = $stmt->fetch();

        if (!$shift) {
            $error = 'Không tìm thấy ca đang mở để đóng';
        } elseif ($errClose) {
            $error = $errClose;
        } else {
            $receiptsStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM cashbook_entries
                 WHERE branch_id = ? AND type = 'RECEIPT' AND payment_method = 'CASH' AND created_at >= ?"
            );
            $receiptsStmt->execute([$branchId, $shift['opened_at']]);
            $cashReceipts = (float) $receiptsStmt->fetchColumn();

            $paymentsStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM cashbook_entries
                 WHERE branch_id = ? AND type = 'PAYMENT' AND payment_method = 'CASH' AND created_at >= ?"
            );
            $paymentsStmt->execute([$branchId, $shift['opened_at']]);
            $cashPayments = (float) $paymentsStmt->fetchColumn();

            $expectedCash = (float) $shift['opening_cash'] + $cashReceipts - $cashPayments;
            $difference = $countedCash - $expectedCash;

            $closeStmt = $pdo->prepare(
                "UPDATE pos_shifts SET status = 'CLOSED', closed_by_id = ?, closed_at = NOW(),
                 cash_receipts = ?, cash_payments = ?, expected_cash = ?,
                 counted_cash = ?, difference = ?, close_note = ?, handover_to_id = ?, handover_note = ? WHERE id = ? AND status = 'OPEN'"
            );
            $closeStmt->execute([
                $currentUser['id'], $cashReceipts, $cashPayments, $expectedCash,
                $countedCash, $difference, $closeNote, $handTo ?: null, $handNote !== '' ? $handNote : null, $shiftId,
            ]);
            if ($closeStmt->rowCount() === 0) {
                $error = 'Ca này đã được đóng trước đó';
            } else {
                luuChecklist($pdo, $shiftId, 'CLOSE', $resClose);
                chamCongRa($pdo, $tenantId, (int) $currentUser['id']);
                redirect('ca_ban_hang.php?closed=' . $shiftId);
            }
        }
    }
}

$openStmt = $pdo->prepare(
    "SELECT s.*, u.name AS opened_by_name FROM pos_shifts s
     JOIN users u ON u.id = s.opened_by_id
     WHERE s.branch_id = ? AND s.status = 'OPEN'"
);
$openStmt->execute([$branchId]);
$openShift = $openStmt->fetch();

$expectedNow = null;
if ($openShift) {
    $receiptsStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM cashbook_entries
         WHERE branch_id = ? AND type = 'RECEIPT' AND payment_method = 'CASH' AND created_at >= ?"
    );
    $receiptsStmt->execute([$branchId, $openShift['opened_at']]);
    $cashReceiptsNow = (float) $receiptsStmt->fetchColumn();

    $paymentsStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM cashbook_entries
         WHERE branch_id = ? AND type = 'PAYMENT' AND payment_method = 'CASH' AND created_at >= ?"
    );
    $paymentsStmt->execute([$branchId, $openShift['opened_at']]);
    $cashPaymentsNow = (float) $paymentsStmt->fetchColumn();

    $expectedNow = (float) $openShift['opening_cash'] + $cashReceiptsNow - $cashPaymentsNow;
}

$justClosedId = (int) ($_GET['closed'] ?? 0);
$justClosed = null;
if ($justClosedId) {
    $jc = $pdo->prepare("SELECT * FROM pos_shifts WHERE id = ? AND branch_id = ?");
    $jc->execute([$justClosedId, $branchId]);
    $justClosed = $jc->fetch();
}

$historyStmt = $pdo->prepare(
    "SELECT s.*, uo.name AS opened_by_name, uc.name AS closed_by_name
     FROM pos_shifts s
     JOIN users uo ON uo.id = s.opened_by_id
     LEFT JOIN users uc ON uc.id = s.closed_by_id
     WHERE s.branch_id = ? AND s.status = 'CLOSED'
     ORDER BY s.closed_at DESC LIMIT 20"
);
$historyStmt->execute([$branchId]);
$history = $historyStmt->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:4px;">Mở ca / đóng ca bán hàng</h1>
<p class="muted" style="margin:0 0 20px;font-size:13px;">
  Kiểm đếm tiền mặt đầu ca và cuối ca cho chi nhánh hiện tại - giúp phát hiện chênh lệch quỹ
  tiền mặt sớm, không phải đợi đến cuối ngày mới đối chiếu.
</p>

<?php if ($error): ?>
  <div class="alert alert-error" style="margin-bottom:16px;"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($justClosed): ?>
  <div class="alert <?= abs((float) $justClosed['difference']) < 1 ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px;">
    Đã đóng ca lúc <?= e(date('H:i d/m/Y', strtotime($justClosed['closed_at']))) ?>.
    Tiền mặt dự kiến: <strong><?= money($justClosed['expected_cash']) ?></strong>,
    thực đếm: <strong><?= money($justClosed['counted_cash']) ?></strong>,
    chênh lệch: <strong><?= money($justClosed['difference']) ?></strong>
    <?= $justClosed['difference'] > 0 ? '(dư quỹ)' : ($justClosed['difference'] < 0 ? '(thiếu quỹ)' : '(khớp)') ?>
    · <a href="ban_giao_ca.php?id=<?= (int) $justClosed['id'] ?>" style="font-weight:600;">Xem, in biên bản bàn giao ca</a>
  </div>
<?php endif; ?>

<?php if (!$openShift): ?>
  <div class="card" style="max-width:480px;">
    <h3 style="margin:0 0 12px;font-size:16px;">Mở ca mới</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="open">
      <div class="field">
        <label>Số tiền mặt trong quỹ lúc mở ca</label>
        <input type="number" name="opening_cash" class="input" min="0" step="1000" value="0" required>
      </div>
      <div class="field">
        <label>Ghi chú (không bắt buộc)</label>
        <input type="text" name="open_note" class="input" placeholder="VD: Ca sáng - thu ngân Lan">
      </div>
      <?php if ($itemsOpen): ?>
        <div class="field"><label>Danh sách mở cửa (tick việc đã làm; việc chưa làm ghi lý do)</label>
          <?php foreach ($itemsOpen as $it): ?>
            <div style="display:flex;gap:8px;align-items:center;margin:4px 0;flex-wrap:wrap;">
              <label style="display:flex;gap:6px;align-items:center;margin:0;min-width:240px;font-weight:normal;"><input type="checkbox" name="chk[<?= (int) $it['id'] ?>]" value="1" onchange="this.parentNode.parentNode.querySelector('.chk-note').style.display=this.checked?'none':'block'"> <?= e($it['label']) ?></label>
              <input class="input chk-note" name="chk_note[<?= (int) $it['id'] ?>]" placeholder="Lý do chưa làm" style="flex:1;min-width:160px;">
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <button type="submit" class="btn">Mở ca</button>
    </form>
  </div>
<?php else: ?>
  <div class="card" style="max-width:560px;">
    <h3 style="margin:0 0 4px;font-size:16px;">Ca đang mở</h3>
    <p class="muted" style="margin:0 0 16px;font-size:13px;">
      Mở lúc <?= e(date('H:i d/m/Y', strtotime($openShift['opened_at']))) ?> bởi <?= e($openShift['opened_by_name']) ?>
      <?= $openShift['open_note'] ? '· ' . e($openShift['open_note']) : '' ?>
    </p>
    <table style="margin-bottom:16px;">
      <tr><td>Tiền mặt đầu ca</td><td class="text-right"><?= money($openShift['opening_cash']) ?></td></tr>
      <tr><td>Tiền mặt dự kiến hiện tại (đầu ca + bán hàng + thu - chi tiền mặt)</td><td class="text-right"><strong><?= money($expectedNow) ?></strong></td></tr>
    </table>
    <h3 style="margin:0 0 12px;font-size:16px;">Đóng ca</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="close">
      <input type="hidden" name="shift_id" value="<?= (int) $openShift['id'] ?>">
      <div class="field">
        <label>Số tiền mặt thực đếm được lúc đóng ca</label>
        <input type="number" name="counted_cash" class="input" min="0" step="1000" required>
      </div>
      <div class="field">
        <label>Ghi chú (không bắt buộc)</label>
        <input type="text" name="close_note" class="input" placeholder="VD: Thiếu do trả lại khách nhầm">
      </div>
      <?php if ($itemsClose): ?>
        <div class="field"><label>Danh sách đóng cửa (tick việc đã làm; việc chưa làm ghi lý do)</label>
          <?php foreach ($itemsClose as $it): ?>
            <div style="display:flex;gap:8px;align-items:center;margin:4px 0;flex-wrap:wrap;">
              <label style="display:flex;gap:6px;align-items:center;margin:0;min-width:240px;font-weight:normal;"><input type="checkbox" name="chk[<?= (int) $it['id'] ?>]" value="1" onchange="this.parentNode.parentNode.querySelector('.chk-note').style.display=this.checked?'none':'block'"> <?= e($it['label']) ?></label>
              <input class="input chk-note" name="chk_note[<?= (int) $it['id'] ?>]" placeholder="Lý do chưa làm" style="flex:1;min-width:160px;">
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="field"><label>Bàn giao cho (người nhận ca)</label>
        <select name="handover_to_id" class="input"><option value="0">— Không có / chưa rõ —</option><?php foreach ($staff as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Nội dung bàn giao (hàng cần nhập, việc dang dở, khách đặt trước...)</label>
        <textarea name="handover_note" class="input" rows="3"></textarea></div>
      <button type="submit" class="btn" onclick="return confirm('Xác nhận đóng ca? Sau khi đóng sẽ không sửa lại được.')">Đóng ca</button>
    </form>
  </div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto;margin-top:24px;">
  <h3 style="margin:0;padding:16px 16px 0;font-size:16px;">Lịch sử ca gần đây</h3>
  <table>
    <thead>
      <tr>
        <th>Mở ca</th><th>Đóng ca</th><th class="text-right">Đầu ca</th>
        <th class="text-right">Dự kiến</th><th class="text-right">Thực đếm</th>
        <th class="text-right">Chênh lệch</th><th>Ghi chú</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$history): ?>
        <tr><td colspan="8" class="text-center muted" style="padding:24px;">Chưa có ca nào được đóng.</td></tr>
      <?php endif; ?>
      <?php foreach ($history as $h): ?>
        <tr>
          <td><?= e(date('H:i d/m', strtotime($h['opened_at']))) ?> · <?= e($h['opened_by_name']) ?></td>
          <td><?= e(date('H:i d/m', strtotime($h['closed_at']))) ?> · <?= e($h['closed_by_name'] ?? '') ?></td>
          <td class="text-right"><?= money($h['opening_cash']) ?></td>
          <td class="text-right"><?= money($h['expected_cash']) ?></td>
          <td class="text-right"><?= money($h['counted_cash']) ?></td>
          <td class="text-right" style="<?= abs((float) $h['difference']) >= 1 ? 'color:#dc2626;font-weight:600;' : '' ?>"><?= money($h['difference']) ?></td>
          <td class="muted" style="font-size:12px;"><?= e($h['close_note'] ?? '') ?></td>
          <td><a href="ban_giao_ca.php?id=<?= (int) $h['id'] ?>" style="font-size:12px;">Biên bản</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (isManagerTier()): ?>
<div class="card" style="margin-top:24px;max-width:860px;" id="cau-hinh">
  <h3 style="margin:0 0 6px;font-size:16px;">Danh sách mở cửa và đóng cửa</h3>
  <p class="muted" style="margin:0 0 12px;font-size:13px;">Các việc thu ngân phải tick khi mở ca và đóng ca; việc chưa làm bắt buộc ghi lý do. Kết quả lưu vào biên bản bàn giao ca.</p>
  <?php if (!$itemsOpen && !$itemsClose): ?>
    <form method="post" style="margin-bottom:12px;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="chk_seed"><button class="btn btn-secondary" type="submit">Tạo danh sách mẫu</button></form>
  <?php endif; ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;">
    <?php foreach (['OPEN' => ['Mở cửa', $itemsOpen], 'CLOSE' => ['Đóng cửa', $itemsClose]] as $k => [$title, $its]): ?>
      <div><b><?= $title ?></b>
        <?php foreach ($its as $it): ?>
          <form method="post" style="display:flex;gap:6px;align-items:center;margin:4px 0;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="chk_del"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
            <span style="flex:1;font-size:14px;">• <?= e($it['label']) ?></span><button class="btn btn-secondary" style="padding:1px 8px;font-size:12px;" type="submit" title="Xóa">×</button></form>
        <?php endforeach; ?>
        <form method="post" style="display:flex;gap:6px;margin-top:8px;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="chk_add"><input type="hidden" name="kind" value="<?= $k ?>">
          <input class="input" name="label" placeholder="Thêm việc..." required><button class="btn btn-secondary" type="submit">Thêm</button></form>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
