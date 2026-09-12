<?php
require_once __DIR__ . '/inc_header.php';

$q = trim($_GET['q'] ?? '');
$categoryId = (int) ($_GET['category_id'] ?? 0);
$pdo = db();

$sql = 'SELECT p.*, cat.name AS category_name, COALESCE(SUM(i.quantity),0) AS total_qty
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        LEFT JOIN categories cat ON cat.id = p.category_id';
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($categoryId) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryId;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY p.id ORDER BY p.created_at DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;">Danh sách sản phẩm</h1>
  <?php if (hasRole('ADMIN', 'MANAGER')): ?>
    <div style="display:flex;gap:8px;">
      <a href="categories.php" class="btn btn-secondary">Danh mục</a>
      <a href="brands.php" class="btn btn-secondary">Nhãn hiệu</a>
      <a href="products_export.php" class="btn btn-secondary">Xuất file</a>
      <a href="products_import.php" class="btn btn-secondary">Nhập file</a>
      <a href="product_form.php" class="btn">+ Thêm sản phẩm</a>
    </div>
  <?php endif; ?>
</div>

<form style="margin-bottom:16px;display:flex;gap:8px;">
  <input type="text" name="q" class="input" style="max-width:320px;" placeholder="Tìm theo tên hoặc SKU..." value="<?= e($q) ?>">
  <select name="category_id" class="input" style="max-width:220px;">
    <option value="">Tất cả danh mục</option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary">Lọc</button>
</form>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>SKU</th><th>Tên sản phẩm</th><th>Danh mục</th><th>Đơn vị</th>
        <th class="text-right">Giá vốn</th><th class="text-right">Giá bán</th>
        <th class="text-right">Tồn kho</th><th>Trạng thái</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$products): ?>
        <tr><td colspan="8" class="text-center muted" style="padding:32px;">Chưa có sản phẩm nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p): ?>
        <tr>
          <td class="muted" style="font-family:monospace;font-size:12px;"><?= e($p['sku']) ?></td>
          <td><?php if (hasRole('ADMIN', 'MANAGER')): ?><a href="product_form.php?id=<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a><?php else: ?><?= e($p['name']) ?><?php endif; ?></td>
          <td class="muted"><?= e($p['category_name'] ?: '—') ?></td>
          <td><?= e($p['unit'] ?: '—') ?></td>
          <td class="text-right"><?= money($p['cost_price']) ?></td>
          <td class="text-right"><?= money($p['sell_price']) ?></td>
          <td class="text-right"><?= fmtQty($p['total_qty']) ?></td>
          <td>
            <?php if ($p['is_active']): ?>
              <span class="badge badge-green">Đang bán</span>
            <?php else: ?>
              <span class="badge badge-gray">Ngừng bán</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
