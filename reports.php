<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');
require_once __DIR__ . '/inc_header.php';

$pdo = db();

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';

// --- Doanh thu & lãi gộp trong kỳ ---
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS order_count, COALESCE(SUM(total_amount),0) AS revenue
     FROM orders WHERE created_at BETWEEN ? AND ? AND status != 'CANCELLED'"
);
$stmt->execute([$fromDt, $toDt]);
$salesSummary = $stmt->fetch();

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(oi.quantity * COALESCE(v.cost_price, p.cost_price)),0) AS total_cost
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     JOIN products p ON p.id = oi.product_id
     LEFT JOIN product_variants v ON v.id = oi.variant_id
     WHERE o.created_at BETWEEN ? AND ? AND o.status != 'CANCELLED'"
);
$stmt->execute([$fromDt, $toDt]);
$totalCost = (float) $stmt->fetch()['total_cost'];
$grossProfit = (float) $salesSummary['revenue'] - $totalCost;

// --- Top sản phẩm bán chạy ---
$stmt = $pdo->prepare(
    "SELECT CASE WHEN v.name IS NOT NULL THEN CONCAT(p.name, ' - ', v.name) ELSE p.name END AS name,
            p.sku, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS total
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     JOIN products p ON p.id = oi.product_id
     LEFT JOIN product_variants v ON v.id = oi.variant_id
     WHERE o.created_at BETWEEN ? AND ? AND o.status != 'CANCELLED'
     GROUP BY oi.product_id, oi.variant_id ORDER BY qty DESC LIMIT 10"
);
$stmt->execute([$fromDt, $toDt]);
$topProducts = $stmt->fetchAll();

// --- Top khách hàng ---
$stmt = $pdo->prepare(
    "SELECT c.name, c.phone, COUNT(o.id) AS order_count, SUM(o.total_amount) AS total
     FROM orders o JOIN customers c ON c.id = o.customer_id
     WHERE o.created_at BETWEEN ? AND ? AND o.status != 'CANCELLED'
     GROUP BY o.customer_id ORDER BY total DESC LIMIT 10"
);
$stmt->execute([$fromDt, $toDt]);
$topCustomers = $stmt->fetchAll();

// --- Doanh thu theo ngày ---
$stmt = $pdo->prepare(
    "SELECT DATE(created_at) AS d, COUNT(*) AS order_count, SUM(total_amount) AS total
     FROM orders WHERE created_at BETWEEN ? AND ? AND status != 'CANCELLED'
     GROUP BY DATE(created_at) ORDER BY d DESC"
);
$stmt->execute([$fromDt, $toDt]);
$byDay = $stmt->fetchAll();

// --- Tồn kho ---
$stock = $pdo->query(
    "SELECT COALESCE(SUM(i.quantity),0) AS total_qty,
            COALESCE(SUM(i.quantity * COALESCE(v.cost_price, p.cost_price)),0) AS total_value
     FROM inventory i
     JOIN products p ON p.id = i.product_id
     LEFT JOIN product_variants v ON v.id = i.variant_id"
)->fetch();

// --- Doanh thu theo kênh bán hàng ---
$stmt = $pdo->prepare(
    "SELECT COALESCE(sc.name, 'Trực tiếp') AS channel_name, COUNT(o.id) AS order_count, SUM(o.total_amount) AS total
     FROM orders o LEFT JOIN sales_channels sc ON sc.id = o.channel_id
     WHERE o.created_at BETWEEN ? AND ? AND o.status != 'CANCELLED'
     GROUP BY o.channel_id ORDER BY total DESC"
);
$stmt->execute([$fromDt, $toDt]);
$byChannel = $stmt->fetchAll();

// --- Doanh thu theo phương thức thanh toán ---
$paymentLabels = ['CASH' => 'Tiền mặt', 'BANK_TRANSFER' => 'Chuyển khoản', 'CARD' => 'Quẹt thẻ', 'QR_CODE' => 'Quét mã QR'];
$stmt = $pdo->prepare(
    "SELECT p.method, COUNT(*) AS payment_count, SUM(p.amount) AS total
     FROM payments p JOIN orders o ON o.id = p.order_id
     WHERE o.created_at BETWEEN ? AND ? AND o.status != 'CANCELLED'
     GROUP BY p.method ORDER BY total DESC"
);
$stmt->execute([$fromDt, $toDt]);
$byPaymentMethod = $stmt->fetchAll();

// --- Doanh thu theo nhân viên bán hàng ---
$stmt = $pdo->prepare(
    "SELECT u.name AS staff_name, COUNT(o.id) AS order_count, SUM(o.total_amount) AS total
     FROM orders o JOIN users u ON u.id = o.sold_by_id
     WHERE o.created_at BETWEEN ? AND ? AND o.status != 'CANCELLED'
     GROUP BY o.sold_by_id ORDER BY total DESC"
);
$stmt->execute([$fromDt, $toDt]);
$byStaff = $stmt->fetchAll();

// --- Trả hàng trong kỳ ---
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS return_count, COALESCE(SUM(refund_amount),0) AS total_refund
     FROM order_returns WHERE created_at BETWEEN ? AND ?"
);
$stmt->execute([$fromDt, $toDt]);
$returnSummary = $stmt->fetch();

$stmt = $pdo->prepare(
    "SELECT CASE WHEN v.name IS NOT NULL THEN CONCAT(p.name, ' - ', v.name) ELSE p.name END AS name,
            SUM(ori.quantity) AS qty, SUM(ori.line_total) AS total
     FROM order_return_items ori
     JOIN order_returns r ON r.id = ori.return_id
     JOIN products p ON p.id = ori.product_id
     LEFT JOIN product_variants v ON v.id = ori.variant_id
     WHERE r.created_at BETWEEN ? AND ?
     GROUP BY ori.product_id, ori.variant_id ORDER BY qty DESC LIMIT 10"
);
$stmt->execute([$fromDt, $toDt]);
$returnsByProduct = $stmt->fetchAll();

// --- Sổ quỹ trong kỳ ---
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN type='RECEIPT' THEN amount ELSE 0 END),0) AS total_receipt,
            COALESCE(SUM(CASE WHEN type='PAYMENT' THEN amount ELSE 0 END),0) AS total_payment
     FROM cashbook_entries WHERE created_at BETWEEN ? AND ?"
);
$stmt->execute([$fromDt, $toDt]);
$cashSummary = $stmt->fetch();
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:16px;">Báo cáo</h1>

<form style="margin-bottom:24px;display:flex;gap:8px;align-items:end;">
  <div><label style="display:block;font-size:12px;margin-bottom:2px;">Từ ngày</label>
    <input type="date" name="from" value="<?= e($from) ?>" class="input" style="width:auto;"></div>
  <div><label style="display:block;font-size:12px;margin-bottom:2px;">Đến ngày</label>
    <input type="date" name="to" value="<?= e($to) ?>" class="input" style="width:auto;"></div>
  <button type="submit" class="btn btn-secondary">Lọc</button>
</form>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Doanh thu</div>
    <div style="font-size:20px;font-weight:700;color:#2563eb;"><?= money($salesSummary['revenue']) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Số đơn hàng</div>
    <div style="font-size:20px;font-weight:700;"><?= (int) $salesSummary['order_count'] ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Lãi gộp ước tính</div>
    <div style="font-size:20px;font-weight:700;color:<?= $grossProfit >= 0 ? '#059669' : '#dc2626' ?>;"><?= money($grossProfit) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Giá trị tồn kho hiện tại</div>
    <div style="font-size:20px;font-weight:700;"><?= money($stock['total_value']) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Thu / Chi (sổ quỹ)</div>
    <div style="font-size:16px;font-weight:700;">
      <span style="color:#059669;">+<?= money($cashSummary['total_receipt']) ?></span> /
      <span style="color:#dc2626;">-<?= money($cashSummary['total_payment']) ?></span>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
  <div>
    <h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Top sản phẩm bán chạy</h2>
    <div class="card" style="padding:0;overflow-x:auto;">
      <table>
        <thead><tr><th>Sản phẩm</th><th class="text-right">SL bán</th><th class="text-right">Doanh thu</th></tr></thead>
        <tbody>
          <?php if (!$topProducts): ?>
            <tr><td colspan="3" class="text-center muted" style="padding:20px;">Không có dữ liệu</td></tr>
          <?php endif; ?>
          <?php foreach ($topProducts as $p): ?>
            <tr>
              <td><?= e($p['name']) ?></td>
              <td class="text-right"><?= (int) $p['qty'] ?></td>
              <td class="text-right" style="font-weight:600;"><?= money($p['total']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Top khách hàng</h2>
    <div class="card" style="padding:0;overflow-x:auto;">
      <table>
        <thead><tr><th>Khách hàng</th><th class="text-right">Số đơn</th><th class="text-right">Chi tiêu</th></tr></thead>
        <tbody>
          <?php if (!$topCustomers): ?>
            <tr><td colspan="3" class="text-center muted" style="padding:20px;">Không có dữ liệu</td></tr>
          <?php endif; ?>
          <?php foreach ($topCustomers as $c): ?>
            <tr>
              <td><?= e($c['name']) ?></td>
              <td class="text-right"><?= (int) $c['order_count'] ?></td>
              <td class="text-right" style="font-weight:600;"><?= money($c['total']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Doanh thu theo kênh bán hàng</h2>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:24px;">
  <table>
    <thead><tr><th>Kênh bán</th><th class="text-right">Số đơn</th><th class="text-right">Doanh thu</th></tr></thead>
    <tbody>
      <?php if (!$byChannel): ?>
        <tr><td colspan="3" class="text-center muted" style="padding:20px;">Không có dữ liệu</td></tr>
      <?php endif; ?>
      <?php foreach ($byChannel as $c): ?>
        <tr>
          <td><?= e($c['channel_name']) ?></td>
          <td class="text-right"><?= (int) $c['order_count'] ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($c['total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Doanh thu theo ngày</h2>
<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Ngày</th><th class="text-right">Số đơn</th><th class="text-right">Doanh thu</th></tr></thead>
    <tbody>
      <?php if (!$byDay): ?>
        <tr><td colspan="3" class="text-center muted" style="padding:20px;">Không có dữ liệu trong khoảng thời gian này</td></tr>
      <?php endif; ?>
      <?php foreach ($byDay as $d): ?>
        <tr>
          <td><?= date('d/m/Y', strtotime($d['d'])) ?></td>
          <td class="text-right"><?= (int) $d['order_count'] ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($d['total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="grid-2" style="margin-bottom:24px;">
  <div>
    <h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Doanh thu theo phương thức thanh toán</h2>
    <div class="card" style="padding:0;overflow-x:auto;">
      <table>
        <thead><tr><th>Phương thức</th><th class="text-right">Số lượt</th><th class="text-right">Số tiền</th></tr></thead>
        <tbody>
          <?php if (!$byPaymentMethod): ?>
            <tr><td colspan="3" class="text-center muted" style="padding:20px;">Không có dữ liệu</td></tr>
          <?php endif; ?>
          <?php foreach ($byPaymentMethod as $p): ?>
            <tr>
              <td><?= e($paymentLabels[$p['method']] ?? $p['method']) ?></td>
              <td class="text-right"><?= (int) $p['payment_count'] ?></td>
              <td class="text-right" style="font-weight:600;"><?= money($p['total']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Doanh thu theo nhân viên</h2>
    <div class="card" style="padding:0;overflow-x:auto;">
      <table>
        <thead><tr><th>Nhân viên</th><th class="text-right">Số đơn</th><th class="text-right">Doanh thu</th></tr></thead>
        <tbody>
          <?php if (!$byStaff): ?>
            <tr><td colspan="3" class="text-center muted" style="padding:20px;">Không có dữ liệu</td></tr>
          <?php endif; ?>
          <?php foreach ($byStaff as $s): ?>
            <tr>
              <td><?= e($s['staff_name']) ?></td>
              <td class="text-right"><?= (int) $s['order_count'] ?></td>
              <td class="text-right" style="font-weight:600;"><?= money($s['total']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Trả hàng trong kỳ</h2>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:16px;">
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Số đơn trả hàng</div>
    <div style="font-size:18px;font-weight:700;"><?= (int) $returnSummary['return_count'] ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Tổng tiền hoàn trả</div>
    <div style="font-size:18px;font-weight:700;color:#dc2626;"><?= money($returnSummary['total_refund']) ?></div>
  </div>
</div>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:24px;">
  <table>
    <thead><tr><th>Sản phẩm bị trả nhiều nhất</th><th class="text-right">SL trả</th><th class="text-right">Tiền hoàn</th></tr></thead>
    <tbody>
      <?php if (!$returnsByProduct): ?>
        <tr><td colspan="3" class="text-center muted" style="padding:20px;">Không có dữ liệu trả hàng trong kỳ</td></tr>
      <?php endif; ?>
      <?php foreach ($returnsByProduct as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td class="text-right"><?= (int) $r['qty'] ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($r['total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
