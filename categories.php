<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = post('name');
    $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;

    if ($name === '') {
        $error = 'Vui lòng nhập tên danh mục';
    } else {
        $pdo->prepare('INSERT INTO categories (name, parent_id) VALUES (?,?)')->execute([$name, $parentId]);
        redirect('categories.php');
    }
}

$categories = $pdo->query(
    'SELECT c.*, p.name AS parent_name, (SELECT COUNT(*) FROM products WHERE category_id = c.id) AS product_count
     FROM categories c LEFT JOIN categories p ON p.id = c.parent_id ORDER BY c.name'
)->fetchAll();
$allCategories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Danh mục sản phẩm</h1>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm danh mục</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="grid-2">
      <div class="field"><label>Tên danh mục *</label><input class="input" name="name" required></div>
      <div class="field">
        <label>Danh mục cha</label>
        <select class="input" name="parent_id">
          <option value="">— Không có —</option>
          <?php foreach ($allCategories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn">Thêm danh mục</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên danh mục</th><th>Danh mục cha</th><th class="text-right">Số sản phẩm</th></tr></thead>
    <tbody>
      <?php if (!$categories): ?>
        <tr><td colspan="3" class="text-center muted" style="padding:32px;">Chưa có danh mục nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($categories as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td class="muted"><?= e($c['parent_name'] ?: '—') ?></td>
          <td class="text-right"><?= (int) $c['product_count'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
