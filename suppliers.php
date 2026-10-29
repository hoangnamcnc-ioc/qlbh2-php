<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = post('name');
    $phone = post('phone') ?: null;
    $address = post('address');
    $taxCode = chuanHoaMaSoThue(post('tax_code'));
    $category = mb_substr(post('category'), 0, 100) ?: null;

    if ($name === '') {
        $error = 'Vui lòng nhập tên nhà cung cấp';
    } elseif ($taxCode === null) {
        $error = 'Mã số thuế bắt buộc nhập và phải gồm 10 số (hoặc 13 số: 10 số + 3 số chi nhánh, ví dụ 0312345678-001)';
    } elseif ($address === '') {
        $error = 'Vui lòng nhập địa chỉ nhà cung cấp';
    } else {
        $pdo->prepare('INSERT INTO suppliers (name, phone, address, tenant_id, category, tax_code) VALUES (?,?,?,?,?,?)')
            ->execute([$name, $phone, $address, $tenantId, $category, $taxCode]);
        redirect('suppliers.php');
    }
}

$catFilter = trim((string) ($_GET['nhom'] ?? ''));
$suppliersStmt = $pdo->prepare(
    "SELECT s.*, (SELECT COUNT(*) FROM supplier_reviews r WHERE r.supplier_id = s.id AND r.kind IN ('LATE','DEFECT','PRICE_UP')) AS so_van_de
     FROM suppliers s WHERE s.tenant_id = ?" . ($catFilter !== '' ? ' AND s.category = ?' : '') . ' ORDER BY s.created_at DESC'
);
$suppliersStmt->execute($catFilter !== '' ? [$tenantId, $catFilter] : [$tenantId]);
$suppliers = $suppliersStmt->fetchAll();
$catListStmt = $pdo->prepare("SELECT DISTINCT category FROM suppliers WHERE tenant_id = ? AND category IS NOT NULL AND category <> '' ORDER BY category");
$catListStmt->execute([$tenantId]);
$catList = $catListStmt->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Nhà cung cấp</h1>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm nhà cung cấp</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="grid-2">
      <div class="field"><label>Tên nhà cung cấp *</label><input class="input" name="name" required></div>
      <div class="field"><label>Mã số thuế *</label><input class="input" name="tax_code" required maxlength="14" placeholder="10 hoặc 13 số"></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Số điện thoại</label><input class="input" name="phone"></div>
      <div class="field"><label>Địa chỉ *</label><input class="input" name="address" required></div>
    </div>
    <div class="field"><label>Nhóm ngành hàng</label>
      <input class="input" name="category" list="cat-list" maxlength="100" placeholder="vd: Bánh kẹo, Hóa mỹ phẩm, Gia vị, Nước giải khát...">
      <datalist id="cat-list"><?php foreach ($catList as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
    </div>
    <button type="submit" class="btn">Thêm nhà cung cấp</button>
  </form>
</div>

<?php if ($catList): ?>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
  <a class="btn <?= $catFilter === '' ? '' : 'btn-secondary' ?>" style="padding:4px 12px;font-size:13px;" href="suppliers.php">Tất cả nhóm</a>
  <?php foreach ($catList as $c): ?><a class="btn <?= $catFilter === $c ? '' : 'btn-secondary' ?>" style="padding:4px 12px;font-size:13px;" href="suppliers.php?nhom=<?= urlencode($c) ?>"><?= e($c) ?></a><?php endforeach; ?>
</div>
<?php endif; ?>
<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên nhà cung cấp</th><th>Mã số thuế</th><th>Nhóm ngành</th><th>SĐT</th><th>Địa chỉ</th><th class="text-right">Công nợ phải trả</th></tr></thead>
    <tbody>
      <?php if (!$suppliers): ?>
        <tr><td colspan="6" class="text-center muted" style="padding:32px;">Chưa có nhà cung cấp nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($suppliers as $s): ?>
        <tr>
          <td><a href="supplier_view.php?id=<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a><?= (int) $s['so_van_de'] > 0 ? ' <span class="badge badge-red" title="Số lần bị ghi nhận giao chậm / hàng lỗi / tăng giá">⚠ ' . (int) $s['so_van_de'] . '</span>' : '' ?></td>
          <td><?= e($s['tax_code'] ?: '—') ?></td>
          <td class="muted"><?= e($s['category'] ?: '—') ?></td>
          <td><?= e($s['phone'] ?: '—') ?></td>
          <td><?= e($s['address'] ?: '—') ?></td>
          <td class="text-right" style="<?= $s['debt'] > 0 ? 'color:#dc2626;font-weight:600;' : '' ?>"><?= money($s['debt']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
