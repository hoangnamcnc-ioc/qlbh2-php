<?php
/**
 * Hang ton lau / ban cham: con ton kho nhung KHONG ban duoc cai nao trong N ngay gan nhat.
 * Cho biet von dang bi "chon" vao hang e (gia von x ton) de xa hang, giam gia hoac ngung nhap.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();

$branchesStmt = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? ORDER BY name');
$branchesStmt->execute([$tenantId]);
$branches = $branchesStmt->fetchAll();
$lockedBranchId = hasRole('ADMIN') ? 0 : effectiveBranchId($currentUser);

$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, [15, 30, 60, 90, 180, 365], true)) {
    $days = 30;
}
$branchId = $lockedBranchId ?: (int) ($_GET['branch_id'] ?? 0);
if ($branchId && !in_array($branchId, array_map('intval', array_column($branches, 'id')), true)) {
    $branchId = 0;
}

$invBranch = $branchId ? ' AND i.branch_id = ?' : '';
$ordBranch = $branchId ? ' AND o.branch_id = ?' : '';
$sql = "SELECT p.id, p.name, p.sku, p.cost_price, p.sell_price, inv.qty,
               (SELECT MAX(o.created_at) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE oi.product_id = p.id AND o.status NOT IN ('DRAFT','CANCELLED')$ordBranch) AS last_sold,
               (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE oi.product_id = p.id AND o.status NOT IN ('DRAFT','CANCELLED')
                   AND o.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)$ordBranch) AS sold_n
        FROM products p
        JOIN (SELECT i.product_id, SUM(i.quantity) AS qty FROM inventory i JOIN branches b ON b.id = i.branch_id
              WHERE b.tenant_id = ?$invBranch GROUP BY i.product_id HAVING SUM(i.quantity) > 0) inv ON inv.product_id = p.id
        WHERE p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'
        HAVING sold_n = 0
        ORDER BY inv.qty * p.cost_price DESC
        LIMIT 500";
$params = [];
if ($branchId) { $params[] = $branchId; }          // last_sold
if ($branchId) { $params[] = $branchId; }          // sold_n
$params[] = $tenantId;                              // inv
if ($branchId) { $params[] = $branchId; }
$params[] = $tenantId;                              // products
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$tongVon = 0.0;
foreach ($rows as $r) {
    $tongVon += (float) $r['qty'] * (float) $r['cost_price'];
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="reports.php" class="muted" style="font-size:14px;">← Báo cáo</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Hàng tồn lâu / bán chậm</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:760px;">
  Các mặt hàng còn tồn kho nhưng <b>không bán được cái nào</b> trong khoảng thời gian chọn. Số "vốn đang chôn" = giá vốn × tồn.
  Nên xả hàng (giảm giá, khuyến mại), trả lại nhà cung cấp hoặc ngừng nhập thêm.
</p>

<form style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:16px;">
  <div><label style="display:block;font-size:12px;margin-bottom:2px;">Không bán được trong</label>
    <select name="days" class="input" style="width:auto;">
      <?php foreach ([15, 30, 60, 90, 180, 365] as $d): ?><option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= $d ?> ngày gần nhất</option><?php endforeach; ?>
    </select></div>
  <?php if (!$lockedBranchId && count($branches) > 1): ?>
    <div><label style="display:block;font-size:12px;margin-bottom:2px;">Chi nhánh</label>
      <select name="branch_id" class="input" style="width:auto;">
        <option value="0">Tất cả</option>
        <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $branchId ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select></div>
  <?php endif; ?>
  <button class="btn" type="submit">Xem</button>
</form>

<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
  <div class="card" style="min-width:200px;"><div class="muted" style="font-size:12px;">Số mặt hàng ế</div><div style="font-size:22px;font-weight:700;"><?= count($rows) ?></div></div>
  <div class="card" style="min-width:200px;"><div class="muted" style="font-size:12px;">Vốn đang bị chôn (giá vốn × tồn)</div><div style="font-size:22px;font-weight:700;color:#dc2626;"><?= money($tongVon) ?></div></div>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">Tồn</th><th class="text-right">Giá vốn</th><th class="text-right">Vốn chôn</th><th>Bán lần cuối</th><th></th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center muted" style="padding:24px;">Không có mặt hàng nào ế trong <?= $days ?> ngày — tốt!</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['name']) ?> <span class="muted" style="font-family:monospace;font-size:12px;">(<?= e($r['sku']) ?>)</span></td>
          <td class="text-right"><?= fmtQty($r['qty']) ?></td>
          <td class="text-right"><?= money($r['cost_price']) ?></td>
          <td class="text-right"><b><?= money((float) $r['qty'] * (float) $r['cost_price']) ?></b></td>
          <td class="muted"><?= $r['last_sold'] ? date('d/m/Y', strtotime($r['last_sold'])) : 'Chưa bán lần nào' ?></td>
          <td class="text-right" style="white-space:nowrap;">
            <a class="btn btn-secondary" style="padding:4px 10px;font-size:12px;" href="product_form.php?id=<?= (int) $r['id'] ?>">Giảm giá</a>
            <a class="btn btn-secondary" style="padding:4px 10px;font-size:12px;" href="xuat_huy.php">Xuất hủy</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="muted" style="font-size:12px;margin:12px 0 0;max-width:820px;">⚖️ Số liệu tính từ dữ liệu bạn nhập, chỉ mang tính tham khảo. Hàng mới nhập gần đây chưa kịp bán cũng có thể xuất hiện ở đây.</p>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
