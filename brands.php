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
        $error = 'Vui lòng nhập tên nhãn hiệu';
    } else {
        $check = $pdo->prepare('SELECT id FROM brands WHERE name = ?');
        $check->execute([$name]);
        if ($check->fetch()) {
            $error = 'Nhãn hiệu này đã tồn tại';
        } else {
            $pdo->prepare('INSERT INTO brands (name) VALUES (?)')->execute([$name]);
            redirect('brands.php');
        }
    }
}

$brands = $pdo->query(
    'SELECT b.*, (SELECT COUNT(*) FROM products WHERE brand_id = b.id) AS product_count FROM brands b ORDER BY name'
)->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Nhãn hiệu</h1>

<div class="card" style="max-width:480px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm nhãn hiệu</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" style="display:flex;gap:8px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div style="flex:1;"><label style="font-size:13px;font-weight:500;display:block;margin-bottom:4px;">Tên nhãn hiệu *</label><input class="input" name="name" required></div>
    <button type="submit" class="btn">Thêm</button>
  </form>
</div>

<div class="card" style="max-width:480px;padding:0;">
  <?php if (!$brands): ?>
    <div class="text-center muted" style="padding:32px;">Chưa có nhãn hiệu nào.</div>
  <?php endif; ?>
  <?php foreach ($brands as $b): ?>
    <div style="display:flex;justify-content:space-between;padding:12px 16px;border-top:1px solid #f1f5f9;">
      <span><?= e($b['name']) ?></span>
      <span class="muted" style="font-size:12px;"><?= (int) $b['product_count'] ?> sản phẩm</span>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
