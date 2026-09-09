<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = post('name');
    if ($name === '') {
        $error = 'Vui lòng nhập tên bảng giá';
    } else {
        $pdo->prepare('INSERT INTO price_lists (name) VALUES (?)')->execute([$name]);
        redirect('price_lists.php');
    }
}

$priceLists = $pdo->query(
    'SELECT pl.*, (SELECT COUNT(*) FROM customer_groups WHERE price_list_id = pl.id) AS group_count,
            (SELECT COUNT(*) FROM product_prices WHERE price_list_id = pl.id) AS price_count
     FROM price_lists pl ORDER BY pl.created_at DESC'
)->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:8px;">Bảng giá</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Tạo bảng giá riêng cho từng nhóm khách hàng (vd Bán buôn, VIP). Gán bảng giá vào
  <a href="groups.php">Nhóm khách hàng</a>, sau đó nhập giá riêng cho từng sản phẩm trong trang chi
  tiết sản phẩm. Khi bán hàng, nhập đúng SĐT khách thuộc nhóm đó để POS tự áp giá.
</p>

<div class="card" style="max-width:480px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Tạo bảng giá</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" style="display:flex;gap:8px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div style="flex:1;"><label style="font-size:13px;font-weight:500;display:block;margin-bottom:4px;">Tên bảng giá *</label><input class="input" name="name" required placeholder="vd: Giá bán buôn"></div>
    <button type="submit" class="btn">Thêm</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên bảng giá</th><th class="text-right">Số nhóm KH dùng</th><th class="text-right">Số sản phẩm đã đặt giá</th></tr></thead>
    <tbody>
      <?php if (!$priceLists): ?>
        <tr><td colspan="3" class="text-center muted" style="padding:32px;">Chưa có bảng giá nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($priceLists as $pl): ?>
        <tr>
          <td><?= e($pl['name']) ?></td>
          <td class="text-right"><?= (int) $pl['group_count'] ?></td>
          <td class="text-right"><?= (int) $pl['price_count'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
