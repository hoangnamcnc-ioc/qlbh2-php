<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ? AND tenant_id = ?');
$stmt->execute([$id, $tenantId]);
$supplier = $stmt->fetch();
if (!$supplier) redirect('suppliers.php');

$branchesStmt = $pdo->prepare('SELECT id, name FROM branches WHERE is_active = 1 AND tenant_id = ? ORDER BY name');
$branchesStmt->execute([$tenantId]);
$branches = $branchesStmt->fetchAll();

$error = null;
$reviewKinds = ['LATE' => 'Giao chậm', 'DEFECT' => 'Hàng lỗi / móp méo', 'PRICE_UP' => 'Tăng giá bất thường', 'GOOD' => 'Tốt (đúng hẹn, đúng hàng)', 'OTHER' => 'Khác'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    checkCsrf();
    $kind = array_key_exists($_POST['kind'] ?? '', $reviewKinds) ? $_POST['kind'] : 'OTHER';
    $pdo->prepare('INSERT INTO supplier_reviews (tenant_id, supplier_id, kind, note, created_by_id) VALUES (?,?,?,?,?)')
        ->execute([$tenantId, $id, $kind, mb_substr(post('note'), 0, 255) ?: null, $currentUser['id']]);
    redirect('supplier_view.php?id=' . $id . '#danh-gia');
}
$infoError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'info') {
    checkCsrf();
    $newName = mb_substr(post('name'), 0, 255);
    $newTax = chuanHoaMaSoThue(post('tax_code'));
    $newAddr = mb_substr(post('address'), 0, 255);
    if ($newName === '') {
        $infoError = 'Vui lòng nhập tên nhà cung cấp';
    } elseif ($newTax === null) {
        $infoError = 'Mã số thuế bắt buộc nhập và phải gồm 10 số (hoặc 13 số: 10 số + 3 số chi nhánh, ví dụ 0312345678-001)';
    } elseif ($newAddr === '') {
        $infoError = 'Vui lòng nhập địa chỉ nhà cung cấp';
    } else {
        $pdo->prepare('UPDATE suppliers SET name = ?, tax_code = ?, address = ?, phone = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$newName, $newTax, $newAddr, mb_substr(post('phone'), 0, 50) ?: null, $id, $tenantId]);
        logActivity('SUPPLIER_EDIT', "supplier_id=$id");
        redirect('supplier_view.php?id=' . $id);
    }
    // Loi: giu lai gia tri vua go de nguoi dung sua tiep
    $supplier['name'] = $newName; $supplier['tax_code'] = post('tax_code'); $supplier['address'] = $newAddr; $supplier['phone'] = post('phone');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'category') {
    checkCsrf();
    $pdo->prepare('UPDATE suppliers SET category = ? WHERE id = ? AND tenant_id = ?')
        ->execute([mb_substr(post('category'), 0, 100) ?: null, $id, $tenantId]);
    redirect('supplier_view.php?id=' . $id);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $amount = postFloat('amount');
    $branchId = hasRole('ADMIN') ? (int) ($_POST['branch_id'] ?? 0) : effectiveBranchId($currentUser);
    if ($branchId && !in_array($branchId, array_map('intval', array_column($branches, 'id')), true)) {
        $branchId = 0;
    }

    if ($amount <= 0 || $amount > (float) $supplier['debt']) {
        $error = 'Số tiền không hợp lệ';
    } elseif (!$branchId) {
        $error = 'Vui lòng chọn chi nhánh chi tiền';
    } else {
        // Trả nợ NCC là tiền mặt/chuyển khoản đi RA khỏi 1 chi nhánh cụ thể — phải ghi vào sổ
        // quỹ chi nhánh đó (khoản PAYMENT), giống hệt cách stock_receipt_form.php đã ghi sổ quỹ
        // khi trả tiền NCC ngay lúc nhập hàng. Nếu không, sổ quỹ sẽ không khớp với tiền mặt thực
        // tế đã chi ra để trả nợ.
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE suppliers SET debt = debt - ? WHERE id = ?')->execute([$amount, $id]);
            recordCashbookEntry($branchId, 'PAYMENT', $amount, 'Trả nợ NCC ' . $supplier['name'], 'CASH', $currentUser['id']);
            $pdo->commit();
            logActivity('SUPPLIER_DEBT_PAY', "supplier_id=$id amount=$amount");
            redirect('supplier_view.php?id=' . $id);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'Không thể ghi nhận trả nợ, vui lòng thử lại';
        }
    }
}

$receipts = $pdo->prepare(
    'SELECT r.*, u.name AS created_by_name FROM stock_receipts r
     JOIN users u ON u.id = r.created_by_id
     WHERE r.supplier_id = ? ORDER BY r.created_at DESC LIMIT 50'
);
$receipts->execute([$id]);
$receipts = $receipts->fetchAll();
$reviews = $pdo->prepare(
    'SELECT r.*, u.name AS user_name FROM supplier_reviews r LEFT JOIN users u ON u.id = r.created_by_id
     WHERE r.supplier_id = ? AND r.tenant_id = ? ORDER BY r.created_at DESC LIMIT 100'
);
$reviews->execute([$id, $tenantId]);
$reviews = $reviews->fetchAll();
$reviewCount = array_count_values(array_column($reviews, 'kind'));

require_once __DIR__ . '/inc_header.php';
?>

<a href="suppliers.php" class="muted" style="font-size:14px;">← Nhà cung cấp</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;"><?= e($supplier['name']) ?></h1>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px;">
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Công nợ phải trả</div>
    <div style="font-size:18px;font-weight:700;<?= $supplier['debt'] > 0 ? 'color:#dc2626;' : '' ?>"><?= money($supplier['debt']) ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">SĐT</div>
    <div style="font-size:16px;"><?= e($supplier['phone'] ?: '—') ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Mã số thuế</div>
    <div style="font-size:16px;"><?= e($supplier['tax_code'] ?: '—') ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Địa chỉ</div>
    <div style="font-size:16px;"><?= e($supplier['address'] ?: '—') ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Nhóm ngành hàng</div>
    <form method="post" style="display:flex;gap:6px;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="category">
      <input class="input" name="category" maxlength="100" value="<?= e($supplier['category'] ?? '') ?>" placeholder="vd: Bánh kẹo" style="min-width:0;">
      <button class="btn btn-secondary" type="submit" style="padding:4px 10px;">Lưu</button>
    </form>
  </div>
</div>

<details class="card" style="margin-bottom:24px;max-width:640px;" <?= $infoError || !$supplier['tax_code'] || !$supplier['address'] ? 'open' : '' ?>>
  <summary style="cursor:pointer;font-size:14px;font-weight:600;">Thông tin nhà cung cấp<?= !$supplier['tax_code'] || !$supplier['address'] ? ' <span class="badge badge-red">Thiếu mã số thuế / địa chỉ</span>' : '' ?></summary>
  <?php if ($infoError): ?><div class="alert alert-error" style="margin-top:12px;"><?= e($infoError) ?></div><?php endif; ?>
  <form method="post" style="margin-top:12px;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="info">
    <div class="grid-2">
      <div class="field"><label>Tên nhà cung cấp *</label><input class="input" name="name" required value="<?= e($supplier['name']) ?>"></div>
      <div class="field"><label>Mã số thuế *</label><input class="input" name="tax_code" required maxlength="14" placeholder="10 hoặc 13 số" value="<?= e($supplier['tax_code'] ?? '') ?>"></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Địa chỉ *</label><input class="input" name="address" required value="<?= e($supplier['address'] ?? '') ?>"></div>
      <div class="field"><label>Số điện thoại</label><input class="input" name="phone" value="<?= e($supplier['phone'] ?? '') ?>"></div>
    </div>
    <button type="submit" class="btn">Lưu thông tin</button>
  </form>
</details>

<?php if ($supplier['debt'] > 0): ?>
<div class="card" style="margin-bottom:24px;max-width:480px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Ghi nhận trả nợ nhà cung cấp</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" style="display:flex;align-items:end;gap:12px;flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <?php if (hasRole('ADMIN')): ?>
      <div>
        <label style="font-size:12px;">Chi từ chi nhánh</label>
        <select name="branch_id" class="input" style="width:180px;" required>
          <?php foreach ($branches as $b): ?>
            <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div>
      <label style="font-size:12px;">Số tiền trả</label>
      <input type="number" name="amount" min="1" max="<?= (float) $supplier['debt'] ?>" required style="width:200px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;">
    </div>
    <button type="submit" class="btn">Ghi nhận trả</button>
  </form>
</div>
<?php endif; ?>

<h2 id="danh-gia" style="font-size:18px;font-weight:600;margin:0 0 12px;">Đánh giá nhà cung cấp</h2>
<div class="card" style="margin-bottom:24px;max-width:760px;">
  <p class="muted" style="font-size:13px;margin:0 0 10px;">Ghi lại mỗi lần giao chậm, hàng lỗi/móp méo hoặc tăng giá để có căn cứ khi thương lượng hoặc đổi nhà cung cấp.</p>
  <?php if ($reviewCount): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
      <?php foreach ($reviewKinds as $k => $label): if (empty($reviewCount[$k])) continue; ?>
        <span class="badge <?= $k === 'GOOD' ? 'badge-green' : ($k === 'OTHER' ? 'badge-gray' : 'badge-red') ?>"><?= e($label) ?>: <?= (int) $reviewCount[$k] ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="review">
    <div><label style="font-size:12px;display:block;">Loại</label>
      <select name="kind" class="input" style="width:auto;"><?php foreach ($reviewKinds as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div style="flex:1;min-width:200px;"><label style="font-size:12px;display:block;">Ghi chú</label><input class="input" name="note" maxlength="255" placeholder="vd: giao trễ 2 ngày, phiếu PN12345"></div>
    <button class="btn" type="submit">Ghi nhận</button>
  </form>
  <?php foreach ($reviews as $rv): ?>
    <div style="display:flex;justify-content:space-between;gap:8px;font-size:13px;padding:5px 0;border-top:1px solid #f1f5f9;">
      <span><b><?= e($reviewKinds[$rv['kind']] ?? $rv['kind']) ?></b><?= $rv['note'] ? ' — ' . e($rv['note']) : '' ?></span>
      <span class="muted" style="white-space:nowrap;font-size:12px;"><?= date('d/m/Y', strtotime($rv['created_at'])) ?> · <?= e($rv['user_name'] ?? '') ?></span>
    </div>
  <?php endforeach; ?>
</div>

<h2 style="font-size:18px;font-weight:600;margin:0 0 12px;">Lịch sử nhập hàng</h2>
<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã phiếu</th><th>Người tạo</th><th>Ngày</th><th class="text-right">Tổng tiền</th></tr></thead>
    <tbody>
      <?php if (!$receipts): ?>
        <tr><td colspan="4" class="text-center muted" style="padding:24px;">Chưa có phiếu nhập nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($receipts as $r): ?>
        <tr>
          <td><a href="stock_receipt_view.php?id=<?= (int) $r['id'] ?>" style="font-family:monospace;"><?= e($r['code']) ?></a></td>
          <td><?= e($r['created_by_name']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($r['total_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
