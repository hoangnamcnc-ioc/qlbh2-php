<?php
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$branches = $pdo->query('SELECT * FROM branches ORDER BY name')->fetchAll();
$branchId = (int) ($_GET['branch_id'] ?? 0);

$sql = 'SELECT i.*, p.name AS product_name, b.name AS branch_name
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        JOIN branches b ON b.id = i.branch_id';
$params = [];
if ($branchId) {
    $sql .= ' WHERE i.branch_id = ?';
    $params[] = $branchId;
}
$sql .= ' ORDER BY i.updated_at DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inventories = $stmt->fetchAll();
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Quản lý kho</h1>

<form style="margin-bottom:16px;display:flex;gap:8px;">
  <select name="branch_id" class="input" style="max-width:240px;">
    <option value="">Tất cả chi nhánh</option>
    <?php foreach ($branches as $b): ?>
      <option value="<?= (int) $b['id'] ?>" <?= $branchId === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary">Lọc</button>
</form>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr><th>Sản phẩm</th><th>Chi nhánh</th><th class="text-right">Tồn kho</th><th class="text-right">Định mức</th><th>Trạng thái</th></tr>
    </thead>
    <tbody>
      <?php if (!$inventories): ?>
        <tr><td colspan="5" class="text-center muted" style="padding:32px;">Chưa có dữ liệu tồn kho.</td></tr>
      <?php endif; ?>
      <?php foreach ($inventories as $inv): $isLow = (int) $inv['quantity'] <= (int) $inv['min_stock']; ?>
        <tr>
          <td><a href="product_form.php?id=<?= (int) $inv['product_id'] ?>"><?= e($inv['product_name']) ?></a></td>
          <td><?= e($inv['branch_name']) ?></td>
          <td class="text-right" style="font-weight:600;"><?= (int) $inv['quantity'] ?></td>
          <td class="text-right muted"><?= (int) $inv['min_stock'] ?></td>
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
