<?php
/**
 * Khach theo doi don da dat qua QR/shop: nhap MA DON + SO DIEN THOAI dat hang (khong can dang nhap).
 * Chi hien thong tin neu ca hai khop, va thuoc dung cua hang (theo ?s=<shop_slug>).
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

$pdo = db();
$shopSlug = (string) ($_GET['s'] ?? ($_POST['s'] ?? ''));
$shopTenantId = shopTenantFromSlug($shopSlug);
if ($shopTenantId === 0) {
    http_response_code(404);
    exit('Không tìm thấy cửa hàng.');
}
$storeName = getSettingForTenant($shopTenantId, 'store_name', 'Cửa hàng');
$storeLogo = getSettingForTenant($shopTenantId, 'store_logo', '');
$shopLink = 'shop.php' . ($shopSlug !== '' ? '?s=' . urlencode($shopSlug) : '');

$code = strtoupper(trim((string) ($_GET['code'] ?? ($_POST['code'] ?? ''))));
$phone = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? ''));
$error = null;
$order = null;
$items = [];
$history = [];
$points = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $khoa = rateLimitSecondsLeft('track');
    if ($khoa > 0) {
        $error = 'Bạn tra cứu sai quá nhiều lần. Vui lòng thử lại sau ' . ceil($khoa / 60) . ' phút.';
    } elseif ($code === '' || $phone === '') {
        $error = 'Vui lòng nhập mã đơn và số điện thoại đã dùng khi đặt hàng.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT o.*, c.phone AS customer_phone, c.loyalty_points
             FROM orders o JOIN branches b ON b.id = o.branch_id LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.code = ? AND b.tenant_id = ?'
        );
        $stmt->execute([$code, $shopTenantId]);
        $row = $stmt->fetch();
        if (!$row || preg_replace('/\D/', '', (string) $row['customer_phone']) !== $phone) {
            rateLimitRecordFailure('track');
            $error = 'Không tìm thấy đơn hàng khớp mã đơn và số điện thoại này. Vui lòng kiểm tra lại.';
        } else {
            $order = $row;
            $points = $row['loyalty_points'] !== null ? (int) $row['loyalty_points'] : null;
            $it = $pdo->prepare('SELECT oi.quantity, oi.unit_price, oi.line_total, p.name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
            $it->execute([$order['id']]);
            $items = $it->fetchAll();
            $hs = $pdo->prepare('SELECT to_status, changed_at FROM order_status_history WHERE order_id = ? ORDER BY changed_at, id');
            $hs->execute([$order['id']]);
            $history = $hs->fetchAll();
        }
    }
}

$steps = [
    'DRAFT' => ['Đã nhận đơn', 'Cửa hàng đã nhận đơn, sẽ gọi xác nhận với bạn.'],
    'APPROVED' => ['Đã xác nhận', 'Cửa hàng đã xác nhận đơn hàng.'],
    'PACKED' => ['Đã đóng gói', 'Hàng đã được đóng gói, sắp giao/phục vụ.'],
    'SHIPPED' => ['Đang giao', 'Đơn hàng đang trên đường giao tới bạn.'],
    'COMPLETED' => ['Hoàn thành', 'Đơn hàng đã hoàn thành. Cảm ơn bạn!'],
];
$stepKeys = array_keys($steps);
$currentIdx = $order ? array_search($order['status'], $stepKeys, true) : false;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Theo dõi đơn hàng - <?= e($storeName) ?></title>
<?php if ($storeLogo): ?><link rel="icon" href="uploads/store/<?= e($storeLogo) ?>"><?php endif; ?>
<style>
  :root { --brand:#2563eb; --line:#e2e8f0; --muted:#64748b; }
  * { box-sizing:border-box; }
  body { margin:0; background:#f1f5f9; font-family:-apple-system,Segoe UI,Roboto,sans-serif; color:#0f172a; }
  .wrap { max-width:520px; margin:0 auto; padding:16px 14px 40px; }
  .brand { display:flex; align-items:center; gap:10px; font-weight:700; font-size:18px; margin:6px 0 14px; }
  .brand img { width:32px; height:32px; object-fit:contain; border-radius:8px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:16px; margin-bottom:14px; }
  label { display:block; font-size:13px; font-weight:600; margin:10px 0 4px; }
  input { width:100%; border:1px solid #cbd5e1; border-radius:10px; padding:11px 12px; font-size:15px; }
  button { width:100%; margin-top:14px; background:var(--brand); color:#fff; border:none; border-radius:12px; padding:13px; font-size:15px; font-weight:700; cursor:pointer; }
  .alert-error { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; border-radius:10px; padding:11px 13px; font-size:14px; margin-bottom:12px; }
  .step { display:flex; gap:12px; position:relative; padding-bottom:16px; }
  .step:last-child { padding-bottom:0; }
  .dot { flex:0 0 22px; height:22px; border-radius:50%; background:#e2e8f0; color:#fff; font-size:13px; display:flex; align-items:center; justify-content:center; z-index:1; }
  .step.done .dot { background:var(--brand); }
  .step.now .dot { background:#16a34a; box-shadow:0 0 0 4px #dcfce7; }
  .step:not(:last-child)::after { content:''; position:absolute; left:10px; top:22px; bottom:0; width:2px; background:#e2e8f0; }
  .step.done:not(:last-child)::after { background:var(--brand); }
  .st-name { font-weight:600; font-size:14.5px; }
  .st-desc { color:var(--muted); font-size:12.5px; }
  .line { display:flex; justify-content:space-between; gap:10px; font-size:14px; padding:5px 0; border-bottom:1px dashed var(--line); }
  .muted { color:var(--muted); font-size:13px; }
  a { color:var(--brand); }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><?php if ($storeLogo): ?><img src="uploads/store/<?= e($storeLogo) ?>" alt=""><?php else: ?>🛒<?php endif; ?><span><?= e($storeName) ?></span></div>

  <?php if (!$order): ?>
    <div class="card">
      <h1 style="font-size:18px;margin:0 0 4px;">Theo dõi đơn hàng</h1>
      <p class="muted" style="margin:0;">Nhập mã đơn (hiện sau khi đặt hàng) và số điện thoại bạn đã dùng để đặt.</p>
      <?php if ($error): ?><div class="alert-error" style="margin-top:12px;"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="s" value="<?= e($shopSlug) ?>">
        <label>Mã đơn hàng</label>
        <input name="code" value="<?= e($code) ?>" placeholder="vd: DH6CLAULECF" autocomplete="off" required>
        <label>Số điện thoại đặt hàng</label>
        <input name="phone" type="tel" inputmode="numeric" placeholder="09xxxxxxxx" autocomplete="tel" required>
        <button type="submit">Xem tình trạng đơn</button>
      </form>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="muted">Mã đơn</div>
      <div style="font-size:20px;font-weight:800;"><?= e($order['code']) ?></div>
      <div class="muted" style="margin-top:2px;">Đặt lúc <?= e(date('H:i d/m/Y', strtotime($order['created_at']))) ?></div>
    </div>

    <div class="card">
      <?php if ($order['status'] === 'CANCELLED'): ?>
        <div class="alert-error" style="margin:0;"><b>Đơn hàng đã được hủy.</b> Nếu cần hỗ trợ, vui lòng liên hệ cửa hàng.</div>
      <?php else: ?>
        <?php foreach ($steps as $key => [$name, $desc]): $i = array_search($key, $stepKeys, true);
              $cls = $currentIdx !== false && $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'now' : ''); ?>
          <div class="step <?= $cls ?>">
            <div class="dot"><?= $cls === 'done' ? '✓' : ($cls === 'now' ? '●' : '') ?></div>
            <div><div class="st-name"><?= e($name) ?></div><?php if ($cls === 'now'): ?><div class="st-desc"><?= e($desc) ?></div><?php endif; ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <?php foreach ($items as $it): ?>
        <div class="line"><span><?= e($it['name']) ?> × <?= e(fmtQty($it['quantity'])) ?></span><span><?= money($it['line_total']) ?></span></div>
      <?php endforeach; ?>
      <div class="line" style="border:none;font-weight:700;font-size:16px;"><span>Tổng cộng</span><span><?= money($order['total_amount']) ?></span></div>
      <?php if ((float) $order['paid_amount'] > 0): ?><div class="muted">Đã thanh toán: <?= money($order['paid_amount']) ?></div><?php endif; ?>
      <?php if ($points !== null): ?><div class="muted" style="margin-top:6px;">⭐ Điểm tích lũy của bạn: <b><?= (int) $points ?></b> điểm (điểm được cộng khi đơn hoàn thành)</div><?php endif; ?>
    </div>
  <?php endif; ?>

  <p style="text-align:center;"><a href="<?= e($shopLink) ?>">← Quay lại chọn hàng</a></p>
</div>
</body>
</html>
