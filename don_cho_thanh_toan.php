<?php
/**
 * Don dat online/QR CHUA THANH TOAN qua 1 gio (mac dinh): danh sach de nhan vien goi / nhan tin nhac khach.
 * He thong khong co SMS/Zalo tu dong (can hop dong nha mang) nen cung cap nut Goi, Nhan SMS soan san (kem link theo doi don co
 * ma QR thanh toan) va Zalo; chu cua hang con nhan email nhac tu dong qua payment_reminder_cron.php neu da dat lich tren hosting.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$tenantId = currentTenantId();
$minutes = max(5, min(10080, (int) ($_GET['m'] ?? 60)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $oid = (int) ($_POST['order_id'] ?? 0);
    $own = layDonHangCuaToi($oid);
    if ($own && (isManagerTier() || (int) $own['branch_id'] === effectiveBranchId($currentUser))) {
        $pdo->prepare("INSERT INTO payment_reminders (order_id, channel) VALUES (?, 'MANUAL') ON DUPLICATE KEY UPDATE reminded_at = NOW(), channel = 'MANUAL'")->execute([$oid]);
        logActivity('PAYMENT_REMINDED', 'order_id=' . $oid);
    }
    redirect('don_cho_thanh_toan.php?m=' . $minutes);
}

$scope = ''; $params = [$tenantId, $minutes];
if (!isManagerTier()) {
    $scope = ' AND o.branch_id = ?';
    $params[] = effectiveBranchId($currentUser);
}
$stmt = $pdo->prepare(
    "SELECT o.id, o.code, o.status, o.total_amount, o.paid_amount, o.created_at, c.name AS customer_name, c.phone AS customer_phone,
            r.reminded_at, TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS tuoi_phut
     FROM orders o JOIN branches b ON b.id = o.branch_id
     LEFT JOIN customers c ON c.id = o.customer_id
     LEFT JOIN payment_reminders r ON r.order_id = o.id
     WHERE b.tenant_id = ? AND o.source = 'ONLINE' AND o.status <> 'CANCELLED' AND o.total_amount > o.paid_amount
       AND o.created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)$scope
     ORDER BY o.created_at ASC LIMIT 200"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$storeName = getSetting('store_name', 'Cửa hàng');
$slug = getSetting('shop_slug', '');
$base = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$tuoi = static fn(int $p): string => $p >= 1440 ? intdiv($p, 1440) . ' ngày' : ($p >= 60 ? intdiv($p, 60) . ' giờ' : $p . ' phút');

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:4px;">Đơn online chờ thanh toán</h1>
<p class="muted" style="margin:0 0 14px;font-size:13px;max-width:780px;">
  Đơn khách đặt qua QR/trang đặt hàng chưa thanh toán đủ sau <b><?= $minutes >= 60 ? ($minutes / 60) . ' giờ' : $minutes . ' phút' ?></b>.
  Bấm <b>Gọi</b> hoặc <b>Nhắn SMS</b> (đã soạn sẵn kèm link có mã QR thanh toán) để nhắc khách, rồi bấm "Đã nhắc" để đánh dấu. Khách chuyển khoản đã đối soát tự động sẽ biến mất khỏi danh sách.
</p>

<form style="margin-bottom:14px;display:flex;gap:8px;align-items:center;">
  <label style="font-size:13px;">Chưa thanh toán quá</label>
  <select name="m" class="input" style="max-width:150px;" onchange="this.form.submit()">
    <?php foreach ([15 => '15 phút', 30 => '30 phút', 60 => '1 giờ', 180 => '3 giờ', 1440 => '1 ngày'] as $v => $l): ?>
      <option value="<?= $v ?>" <?= $minutes === $v ? 'selected' : '' ?>><?= e($l) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Đơn</th><th>Khách</th><th class="text-right">Còn phải thu</th><th>Đã chờ</th><th>Nhắc</th><th>Liên hệ</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center muted" style="padding:24px;">Không có đơn online nào chờ thanh toán quá thời gian này. 👍</td></tr><?php endif; ?>
      <?php foreach ($rows as $r):
          $owe = (float) $r['total_amount'] - (float) $r['paid_amount'];
          $phone = preg_replace('/\D/', '', (string) $r['customer_phone']);
          $link = $base . '/shop_track.php?s=' . urlencode($slug) . '&code=' . urlencode($r['code']);
          $sms = 'Chào ' . ($r['customer_name'] ?: 'bạn') . ', đơn ' . $r['code'] . ' tại ' . $storeName . ' còn ' . number_format($owe, 0, ',', '.') . 'đ chưa thanh toán. Bạn quét mã QR để thanh toán tại: ' . $link . ' — Cảm ơn bạn!';
      ?>
        <tr>
          <td><a href="order_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['code']) ?></a><div class="muted" style="font-size:12px;"><?= e(date('d/m H:i', strtotime($r['created_at']))) ?></div></td>
          <td><?= e((string) ($r['customer_name'] ?: '—')) ?><div class="muted" style="font-size:12px;"><?= e($phone) ?></div></td>
          <td class="text-right"><b><?= money($owe) ?></b></td>
          <td style="<?= (int) $r['tuoi_phut'] >= 1440 ? 'color:#dc2626;font-weight:600;' : '' ?>"><?= e($tuoi((int) $r['tuoi_phut'])) ?></td>
          <td class="muted" style="font-size:12.5px;"><?= $r['reminded_at'] ? 'Đã nhắc ' . e(date('d/m H:i', strtotime($r['reminded_at']))) : '—' ?></td>
          <td style="white-space:nowrap;">
            <?php if ($phone !== ''): ?>
              <a class="btn btn-secondary" style="padding:3px 9px;font-size:12px;" href="tel:<?= e($phone) ?>">📞 Gọi</a>
              <a class="btn btn-secondary" style="padding:3px 9px;font-size:12px;" href="sms:<?= e($phone) ?>?body=<?= e(rawurlencode($sms)) ?>">💬 SMS</a>
              <a class="btn btn-secondary" style="padding:3px 9px;font-size:12px;" target="_blank" rel="noopener" href="https://zalo.me/<?= e($phone) ?>">Zalo</a>
            <?php endif; ?>
            <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="order_id" value="<?= (int) $r['id'] ?>">
              <button class="btn" style="padding:3px 9px;font-size:12px;">Đã nhắc</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
