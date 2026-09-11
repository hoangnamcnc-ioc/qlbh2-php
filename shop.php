<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

$pdo = db();
$branch = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
$storeName = getSetting('store_name', 'Cửa hàng');
$enabled = getSetting('online_shop_enabled', '1') === '1';

$products = [];
if ($branch && $enabled) {
    $products = $pdo->prepare(
        "SELECT p.id, p.name, p.sku, p.sell_price, p.product_type,
                COALESCE(i.quantity, 0) AS qty,
                (SELECT filename FROM product_images WHERE product_id = p.id ORDER BY sort_order LIMIT 1) AS image
         FROM products p
         LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
         WHERE p.is_active = 1 AND p.product_type = 'PRODUCT' AND COALESCE(i.quantity, 0) > 0
         ORDER BY p.name"
    );
    $products->execute([$branch['id']]);
    $products = $products->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đặt hàng online - <?= e($storeName) ?></title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f8fafc; margin: 0; color: #1e293b; }
  .wrap { max-width: 640px; margin: 0 auto; padding: 16px; }
  h1 { font-size: 20px; margin: 8px 0 16px; }
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
  .product-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-top: 1px solid #f1f5f9; }
  .product-row:first-child { border-top: none; }
  .product-row img { width: 52px; height: 52px; object-fit: cover; border-radius: 6px; background: #f1f5f9; }
  .product-name { font-weight: 600; font-size: 14px; }
  .product-price { color: #2563eb; font-weight: 600; font-size: 14px; }
  .product-stock { color: #94a3b8; font-size: 12px; }
  .qty-input { width: 56px; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: center; }
  label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px; color: #334155; }
  input[type=text], input[type=tel], textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 14px; margin-bottom: 14px; box-sizing: border-box; font-family: inherit; }
  button { width: 100%; background: #2563eb; color: #fff; border: none; border-radius: 8px; padding: 12px; font-size: 15px; font-weight: 600; cursor: pointer; }
  .muted { color: #64748b; font-size: 13px; }
  .alert { padding: 12px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
  .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
  .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🛒 Đặt hàng online — <?= e($storeName) ?></h1>

  <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success">Đặt hàng thành công! Mã đơn <b><?= e($_GET['code'] ?? '') ?></b> — chúng tôi sẽ liên hệ xác nhận sớm nhất.</div>
  <?php elseif (isset($_GET['err'])): ?>
    <div class="alert alert-error"><?= e($_GET['err']) ?></div>
  <?php endif; ?>

  <?php if (!$branch || !$enabled): ?>
    <div class="card">Cửa hàng hiện chưa mở đặt hàng online, vui lòng quay lại sau.</div>
  <?php elseif (!$products): ?>
    <div class="card">Hiện chưa có sản phẩm nào để đặt hàng.</div>
  <?php else: ?>
    <form method="post" action="shop_order.php">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <div class="card">
        <?php foreach ($products as $p): ?>
          <div class="product-row">
            <?php if ($p['image']): ?><img src="uploads/products/<?= e($p['image']) ?>" alt="">
            <?php else: ?><div style="width:52px;height:52px;border-radius:6px;background:#f1f5f9;"></div><?php endif; ?>
            <div style="flex:1;">
              <div class="product-name"><?= e($p['name']) ?></div>
              <div class="product-price"><?= number_format((float) $p['sell_price'], 0, ',', '.') ?>đ</div>
              <div class="product-stock">Còn <?= (int) $p['qty'] ?></div>
            </div>
            <input class="qty-input" type="number" min="0" max="<?= (int) $p['qty'] ?>" name="qty[<?= (int) $p['id'] ?>]" value="0">
          </div>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <label>Họ tên *</label>
        <input type="text" name="customer_name" required>
        <label>Số điện thoại *</label>
        <input type="tel" name="customer_phone" required pattern="[0-9]{9,11}">
        <label>Địa chỉ nhận hàng *</label>
        <input type="text" name="customer_address" required>
        <label>Ghi chú (nếu có)</label>
        <textarea name="note" rows="2"></textarea>
        <button type="submit">Đặt hàng</button>
        <p class="muted" style="margin:10px 0 0;">Đơn hàng sẽ được nhân viên cửa hàng xác nhận qua điện thoại trước khi giao.</p>
      </div>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
