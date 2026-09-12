<?php
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$branches = $pdo->query('SELECT * FROM branches ORDER BY name')->fetchAll();
$branchId = (int) ($_GET['branch_id'] ?? 0);
$q = trim($_GET['q'] ?? '');
$lowOnly = isset($_GET['low_only']);

$sql = 'SELECT i.*, p.name AS product_name, p.sku, v.name AS variant_name, b.name AS branch_name,
               (i.quantity * COALESCE(v.cost_price, p.cost_price)) AS stock_value
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        LEFT JOIN product_variants v ON v.id = i.variant_id
        JOIN branches b ON b.id = i.branch_id';
$where = [];
$params = [];
if ($branchId) {
    $where[] = 'i.branch_id = ?';
    $params[] = $branchId;
}
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR v.name LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($lowOnly) {
    $where[] = 'i.quantity <= i.min_stock';
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY i.updated_at DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inventories = $stmt->fetchAll();
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Quản lý kho</h1>

<form style="margin-bottom:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
  <input type="text" name="q" class="input" style="max-width:260px;" placeholder="Tìm theo tên sản phẩm hoặc SKU..." value="<?= e($q) ?>">
  <select name="branch_id" class="input" style="max-width:240px;">
    <option value="">Tất cả chi nhánh</option>
    <?php foreach ($branches as $b): ?>
      <option value="<?= (int) $b['id'] ?>" <?= $branchId === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <label style="font-weight:400;font-size:13px;"><input type="checkbox" name="low_only" value="1" <?= $lowOnly ? 'checked' : '' ?>> Chỉ hiện dưới định mức</label>
  <button type="submit" class="btn btn-secondary">Lọc</button>
</form>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr><th>SKU</th><th>Sản phẩm</th><th>Chi nhánh</th><th class="text-right">Tồn kho</th><th class="text-right">Định mức</th><th class="text-right">Giá trị tồn</th><th>Trạng thái</th></tr>
    </thead>
    <tbody>
      <?php if (!$inventories): ?>
        <tr><td colspan="7" class="text-center muted" style="padding:32px;">Chưa có dữ liệu tồn kho.</td></tr>
      <?php endif; ?>
      <?php foreach ($inventories as $inv): $isLow = (float) $inv['quantity'] <= (float) $inv['min_stock']; ?>
        <tr>
          <td class="muted" style="font-family:monospace;font-size:12px;"><?= e($inv['sku']) ?></td>
          <td><a href="product_form.php?id=<?= (int) $inv['product_id'] ?>"><?= e($inv['product_name']) ?><?php if ($inv['variant_name']): ?> <span class="muted">(<?= e($inv['variant_name']) ?>)</span><?php endif; ?></a></td>
          <td><?= e($inv['branch_name']) ?></td>
          <td class="text-right" style="font-weight:600;"><?= fmtQty($inv['quantity']) ?></td>
          <td class="text-right muted"><?= (int) $inv['min_stock'] ?></td>
          <td class="text-right"><?= money($inv['stock_value']) ?></td>
          <td>
            <?php if ($isLow): ?>
              <span class="badge badge-red">Dưới định mức</span>
            <?php else: ?>
              <span class="badge badge-green">Bình thường</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
