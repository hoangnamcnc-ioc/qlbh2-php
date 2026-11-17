<?php
/**
 * Bien ban ban giao ca: tom tat mot ca ban hang (thoi gian, nguoi mo/dong, nguoi nhan ca, tien mat du kien va thuc dem, doanh thu va cac hinh thuc thu,
 * ket qua danh sach mo/dong cua, noi dung ban giao) de in va ky. Moi nguoi dang nhap xem duoc ca cua chi nhanh minh; quan ly xem moi chi nhanh trong cua hang.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$tenantId = currentTenantId();
$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare(
    'SELECT s.*, b.name AS branch_name, uo.name AS opened_by_name, uc.name AS closed_by_name, uh.name AS handover_name
     FROM pos_shifts s JOIN branches b ON b.id = s.branch_id
     JOIN users uo ON uo.id = s.opened_by_id LEFT JOIN users uc ON uc.id = s.closed_by_id LEFT JOIN users uh ON uh.id = s.handover_to_id
     WHERE s.id = ? AND b.tenant_id = ?'
);
$st->execute([$id, $tenantId]);
$sh = $st->fetch();
if (!$sh || (!isManagerTier() && (int) $sh['branch_id'] !== effectiveBranchId($currentUser))) {
    redirect('ca_ban_hang.php');
}
$den = $sh['closed_at'] ?: date('Y-m-d H:i:s');

$o = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total_amount),0) FROM orders WHERE branch_id = ? AND created_at BETWEEN ? AND ? AND status NOT IN ('CANCELLED','DRAFT')");
$o->execute([$sh['branch_id'], $sh['opened_at'], $den]);
[$soDon, $doanhThu] = $o->fetch(PDO::FETCH_NUM);
$r = $pdo->prepare("SELECT COALESCE(SUM(refund_amount),0), COUNT(*) FROM order_returns r JOIN orders o ON o.id = r.order_id WHERE o.branch_id = ? AND r.created_at BETWEEN ? AND ?");
$r->execute([$sh['branch_id'], $sh['opened_at'], $den]);
[$traHang, $soTra] = $r->fetch(PDO::FETCH_NUM);
$c = $pdo->prepare("SELECT payment_method, COALESCE(SUM(CASE WHEN type='RECEIPT' THEN amount ELSE -amount END),0) AS net FROM cashbook_entries WHERE branch_id = ? AND created_at BETWEEN ? AND ? GROUP BY payment_method");
$c->execute([$sh['branch_id'], $sh['opened_at'], $den]);
$theoPt = $c->fetchAll(PDO::FETCH_KEY_PAIR);
$ptLabel = ['CASH' => 'Tiền mặt', 'BANK_TRANSFER' => 'Chuyển khoản', 'CARD' => 'Quẹt thẻ', 'QR_CODE' => 'Quét mã QR'];
$chk = $pdo->prepare('SELECT * FROM shift_checklist_results WHERE shift_id = ? ORDER BY kind DESC, id');
$chk->execute([$id]);
$chk = $chk->fetchAll();
$store = getSetting('store_name', 'Cửa hàng');
$isOpen = $sh['status'] === 'OPEN';

require_once __DIR__ . '/inc_header.php';
?>
<style>@media print { .no-print, .sidebar, .topbar, .trial-banner, header { display: none !important; } .content, .main { padding: 0 !important; margin: 0 !important; } .paper { border: none !important; box-shadow: none !important; } }</style>
<div class="no-print" style="display:flex;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
  <a href="ca_ban_hang.php" class="muted" style="font-size:14px;">← Mở ca, đóng ca</a>
  <button class="btn" type="button" onclick="window.print()">In biên bản</button>
</div>
<div class="card paper" style="max-width:820px;padding:28px;">
  <div style="text-align:center;margin-bottom:16px;">
    <div style="font-size:15px;font-weight:600;"><?= e($store) ?> · <?= e($sh['branch_name']) ?></div>
    <div style="font-size:22px;font-weight:700;margin-top:4px;">BIÊN BẢN BÀN GIAO CA</div>
    <?php if ($isOpen): ?><div style="color:#b45309;font-size:13px;">Ca đang mở, số liệu tính đến <?= e(date('H:i d/m/Y')) ?></div><?php endif; ?>
  </div>
  <table style="font-size:14px;margin-bottom:14px;">
    <tr><td style="width:40%;">Mở ca</td><td><?= e(date('H:i d/m/Y', strtotime($sh['opened_at']))) ?> · <?= e($sh['opened_by_name']) ?></td></tr>
    <tr><td>Đóng ca</td><td><?= $sh['closed_at'] ? e(date('H:i d/m/Y', strtotime($sh['closed_at']))) . ' · ' . e($sh['closed_by_name'] ?? '') : '<i>chưa đóng</i>' ?></td></tr>
    <tr><td>Người nhận ca</td><td><?= $sh['handover_name'] ? e($sh['handover_name']) : '<span class="muted">chưa ghi</span>' ?></td></tr>
  </table>

  <div style="font-weight:600;margin:10px 0 4px;">Tiền mặt</div>
  <table style="font-size:14px;">
    <tr><td>Đầu ca</td><td style="text-align:right;"><?= money($sh['opening_cash']) ?></td></tr>
    <?php if (!$isOpen): ?>
      <tr><td>Thu tiền mặt trong ca</td><td style="text-align:right;"><?= money($sh['cash_receipts']) ?></td></tr>
      <tr><td>Chi tiền mặt trong ca</td><td style="text-align:right;">− <?= money($sh['cash_payments']) ?></td></tr>
      <tr><td>Dự kiến cuối ca</td><td style="text-align:right;"><?= money($sh['expected_cash']) ?></td></tr>
      <tr><td>Thực đếm</td><td style="text-align:right;font-weight:600;"><?= money($sh['counted_cash']) ?></td></tr>
      <tr><td><b>Chênh lệch</b></td><td style="text-align:right;font-weight:700;color:<?= abs((float) $sh['difference']) < 1 ? '#059669' : '#dc2626' ?>;"><?= abs((float) $sh['difference']) < 1 ? 'Khớp' : money($sh['difference']) . ((float) $sh['difference'] > 0 ? ' (dư)' : ' (thiếu)') ?></td></tr>
      <?php if ($sh['close_note']): ?><tr><td>Ghi chú đóng ca</td><td style="text-align:right;"><?= e($sh['close_note']) ?></td></tr><?php endif; ?>
    <?php endif; ?>
  </table>

  <div style="font-weight:600;margin:14px 0 4px;">Bán hàng trong ca</div>
  <table style="font-size:14px;">
    <tr><td>Số đơn</td><td style="text-align:right;"><?= (int) $soDon ?></td></tr>
    <tr><td>Doanh thu</td><td style="text-align:right;"><?= money($doanhThu) ?></td></tr>
    <tr><td>Trả hàng (<?= (int) $soTra ?> phiếu)</td><td style="text-align:right;">− <?= money($traHang) ?></td></tr>
    <?php foreach ($theoPt as $pt => $net): ?><tr><td class="muted">Thu thuần <?= e($ptLabel[$pt] ?? $pt) ?></td><td style="text-align:right;" class="muted"><?= money($net) ?></td></tr><?php endforeach; ?>
  </table>

  <?php if ($chk): ?>
    <div style="font-weight:600;margin:14px 0 4px;">Danh sách mở, đóng cửa</div>
    <table style="font-size:13px;">
      <?php foreach ($chk as $x): ?>
        <tr><td style="width:70px;" class="muted"><?= $x['kind'] === 'OPEN' ? 'Mở cửa' : 'Đóng cửa' ?></td><td><?= $x['done'] ? '☑' : '☐' ?> <?= e($x['label']) ?><?= !$x['done'] && $x['note'] ? ' <span style="color:#b45309;">— ' . e($x['note']) . '</span>' : '' ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <div style="font-weight:600;margin:14px 0 4px;">Nội dung bàn giao</div>
  <div style="font-size:14px;white-space:pre-wrap;min-height:40px;border:1px solid #e2e8f0;border-radius:6px;padding:8px;"><?= $sh['handover_note'] ? e($sh['handover_note']) : '<span class="muted">Không có.</span>' ?></div>

  <div style="display:flex;justify-content:space-around;margin-top:36px;text-align:center;font-size:14px;">
    <div><b>Người giao ca</b><div class="muted" style="font-size:12px;">(ký, ghi họ tên)</div><div style="height:60px;"></div><?= e($sh['closed_by_name'] ?? $sh['opened_by_name']) ?></div>
    <div><b>Người nhận ca</b><div class="muted" style="font-size:12px;">(ký, ghi họ tên)</div><div style="height:60px;"></div><?= e($sh['handover_name'] ?? '') ?></div>
    <div><b>Quản lý</b><div class="muted" style="font-size:12px;">(ký, ghi họ tên)</div><div style="height:60px;"></div></div>
  </div>
</div>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
