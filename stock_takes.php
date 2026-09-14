<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$tenantId = currentTenantId();

// MANAGER chỉ xem được phiếu kiểm hàng của chi nhánh mình — tránh lộ số liệu tồn kho/chênh
// lệch kiểm kho của chi nhánh khác, giống các trang Đơn hàng/Tồn kho đã chặn theo chi nhánh.
// ADMIN xem được mọi chi nhánh CỦA TENANT MÌNH (không phải toàn hệ thống).
if (hasRole('ADMIN')) {
    $branchId = (int) ($_GET['branch_id'] ?? 0);
    $where = $branchId ? ' WHERE t.branch_id = ? AND b.tenant_id = ?' : ' WHERE b.tenant_id = ?';
    $params = $branchId ? [$branchId, $tenantId] : [$tenantId];
} else {
    $branchId = effectiveBranchId($currentUser);
    $where = ' WHERE t.branch_id = ?';
    $params = [$branchId];
}

$takesStmt = $pdo->prepare(
    "SELECT t.*, b.name AS branch_name, u.name AS created_by_name,
            (SELECT COUNT(*) FROM stock_take_items i WHERE i.take_id = t.id AND i.counted_qty != i.system_qty) AS diff_count
     FROM stock_takes t
     JOIN branches b ON b.id = t.branch_id
     JOIN users u ON u.id = t.created_by_id
     $where
     ORDER BY t.created_at DESC LIMIT 100"
);
$takesStmt->execute($params);
$takes = $takesStmt->fetchAll();

if (hasRole('ADMIN')) {
    $branchesStmt = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? ORDER BY name');
    $branchesStmt->execute([$tenantId]);
    $branches = $branchesStmt->fetchAll();
}
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;">Danh sách phiếu kiểm hàng</h1>
  <div style="display:flex;gap:8px;align-items:center;">
    <?php if (hasRole('ADMIN')): ?>
      <form method="get">
        <select name="branch_id" class="input" style="width:auto;" onchange="this.form.submit()">
          <option value="0">Tất cả chi nhánh</option>
          <?php foreach ($branches as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= $branchId === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <a href="stock_take_form.php" class="btn">+ Tạo phiếu kiểm hàng</a>
  </div>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã phiếu</th><th>Chi nhánh</th><th>Người tạo</th><th>Ngày</th><th class="text-right">Số dòng lệch</th><th>Trạng thái</th></tr></thead>
    <tbody>
      <?php if (!$takes): ?>
        <tr><td colspan="6" class="text-center muted" style="padding:32px;">Chưa có phiếu kiểm hàng nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($takes as $t): ?>
        <tr>
          <td><a href="stock_take_view.php?id=<?= (int) $t['id'] ?>" style="font-family:monospace;"><?= e($t['code']) ?></a></td>
          <td><?= e($t['branch_name']) ?></td>
          <td><?= e($t['created_by_name']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td>
          <td class="text-right">
            <?php if ($t['diff_count'] > 0): ?>
              <span class="badge badge-red"><?= (int) $t['diff_count'] ?> dòng lệch</span>
            <?php else: ?>
              <span class="badge badge-green">Khớp</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($t['status'] === 'BALANCED'): ?>
              <span class="badge badge-green">Đã cân bằng</span>
            <?php else: ?>
              <span class="badge badge-gray">Chưa cân bằng</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
