<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone,
            b.name AS branch_name, b.address AS branch_address, b.phone AS branch_phone,
            u.name AS sold_by_name
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     JOIN branches b ON b.id = o.branch_id
     JOIN users u ON u.id = o.sold_by_id
     WHERE o.id = ?'
);
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { http_response_code(404); exit('Không tìm thấy đơn hàng'); }

$items = $pdo->prepare(
    'SELECT oi.*, p.name AS product_name, v.name AS variant_name
     FROM order_items oi JOIN products p ON p.id = oi.product_id
     LEFT JOIN product_variants v ON v.id = oi.variant_id
     WHERE oi.order_id = ?'
);
$items->execute([$id]);
$items = $items->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Hóa đơn <?= e($order['code']) ?></title>
<style>
  body { font-family: 'Courier New', monospace; max-width: 380px; margin: 0 auto; padding: 16px; font-size: 13px; color: #000; }
  h1 { font-size: 16px; text-align: center; margin: 0 0 4px; }
  .center { text-align: center; }
  .line { border-top: 1px dashed #000; margin: 8px 0; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  td, th { padding: 2px 0; }
  .text-right { text-align: right; }
  .total-row td { font-weight: bold; font-size: 14px; padding-top: 6px; }
  @media print {
    .no-print { display: none; }
  }
</style>
</head>
<body>
  <h1><?= e($order['branch_name']) ?></h1>
  <?php if ($order['branch_address']): ?><p class="center" style="margin:2px 0;"><?= e($order['branch_address']) ?></p><?php endif; ?>
  <?php if ($order['branch_phone']): ?><p class="center" style="margin:2px 0;">ĐT: <?= e($order['branch_phone']) ?></p><?php endif; ?>
  <div class="line"></div>
  <p style="margin:2px 0;">Hóa đơn: <b><?= e($order['code']) ?></b></p>
  <p style="margin:2px 0;">Ngày: <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p>
  <p style="margin:2px 0;">Thu ngân: <?= e($order['sold_by_name']) ?></p>
  <?php if ($order['customer_name']): ?><p style="margin:2px 0;">Khách hàng: <?= e($order['customer_name']) ?> <?= $order['customer_phone'] ? '(' . e($order['customer_phone']) . ')' : '' ?></p><?php endif; ?>
  <?php if ($order['is_delivery']): ?>
    <p style="margin:2px 0;">Giao hàng: <?= e($order['shipping_address'] ?: '') ?></p>
  <?php endif; ?>
  <?php if ($order['note']): ?><p style="margin:2px 0;">Ghi chú: <?= e($order['note']) ?></p><?php endif; ?>
  <div class="line"></div>

  <table>
    <thead><tr><th style="text-align:left;">Sản phẩm</th><th class="text-right">SL</th><th class="text-right">T.Tiền</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?><?= $it['variant_name'] ? ' (' . e($it['variant_name']) . ')' : '' ?><br><span style="color:#555;"><?= money($it['unit_price']) ?> x <?= (int) $it['quantity'] ?></span></td>
          <td class="text-right" style="vertical-align:top;"><?= (int) $it['quantity'] ?></td>
          <td class="text-right" style="vertical-align:top;"><?= money($it['line_total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="line"></div>

  <table>
    <tr><td>Tổng tiền hàng</td><td class="text-right"><?= money($order['sub_total']) ?></td></tr>
    <tr><td>Chiết khấu</td><td class="text-right"><?= money($order['discount']) ?></td></tr>
    <?php if ($order['shipping_fee'] > 0): ?><tr><td>Phí giao hàng</td><td class="text-right"><?= money($order['shipping_fee']) ?></td></tr><?php endif; ?>
    <tr class="total-row"><td>KHÁCH PHẢI TRẢ</td><td class="text-right"><?= money($order['total_amount']) ?></td></tr>
  </table>
  <div class="line"></div>
  <p class="center"><?= e(getSetting('print_footer_note', 'Cảm ơn quý khách!')) ?></p>

  <div class="no-print center" style="margin-top:16px;">
    <button onclick="window.print()">In hóa đơn</button>
  </div>

  <script>window.onload = () => window.print();</script>
</body>
</html>
