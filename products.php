<?php
require_once __DIR__ . '/inc_header.php';

$q = trim($_GET['q'] ?? '');
$pdo = db();

if ($q !== '') {
    $stmt = $pdo->prepare(
        'SELECT p.*, COALESCE(SUM(i.quantity),0) AS total_qty
         FROM products p LEFT JOIN inventory i ON i.product_id = p.id
         WHERE p.name LIKE ? OR p.sku LIKE ?
         GROUP BY p.id ORDER BY p.created_at DESC LIMIT 100'
    );
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like]);
} else {
    $stmt = $pdo->query(
        'SELECT p.*, COALESCE(SUM(i.quantity),0) AS total_qty
         FROM products p LEFT JOIN inventory i ON i.product_id = p.id
         GROUP BY p.id ORDER BY p.created_at DESC LIMIT 100'
    );
}
$products = $stmt->fetchAll();
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;">Danh sách sản phẩm</h1>
  <?php if (hasRole('ADMIN', 'MANAGER')): ?>
    <a href="product_form.php" class="btn">+ Thêm sản phẩm</a>
  <?php endif; ?>
</div>

<form style="margin-bottom:16px;">
  <input type="text" name="q" class="input" style="max-width:320px;" placeholder="Tìm theo tên hoặc SKU..." value="<?= e($q) ?>">
</form>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>SKU</th><th>Tên sản phẩm</th><th>Đơn vị</th>
        <th class="text-right">Giá vốn</th><th class="text-right">Giá bán</th>
        <th class="text-right">Tồn kho</th><th>Trạng thái</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$products): ?>
        <tr><td colspan="7" class="text-center muted" style="padding:32px;">Chưa có sản phẩm nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p): ?>
        <tr>
          <td class="muted" style="font-family:monospace;font-size:12px;"><?= e($p['sku']) ?></td>
          <td><?php if (hasRole('ADMIN', 'MANAGER')): ?><a href="product_form.php?id=<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a><?php else: ?><?= e($p['name']) ?><?php endif; ?></td>
          <td><?= e($p['unit'] ?: '—') ?></td>
          <td class="text-right"><?= money($p['cost_price']) ?></td>
          <td class="text-right"><?= money($p['sell_price']) ?></td>
          <td class="text-right"><?= (int) $p['total_qty'] ?></td>
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
