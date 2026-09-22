<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$branchesStmt = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? ORDER BY name');
$branchesStmt->execute([$tenantId]);
$branches = $branchesStmt->fetchAll();

$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $branchId = (int) ($_POST['branch_id'] ?? 0);

    // Xac nhan chi nhanh thuc su thuoc tenant hien tai - tranh truyen branch_id cua tenant khac
    // qua form de ghi de ton kho cua ho.
    if (!in_array($branchId, array_map('intval', array_column($branches, 'id')), true)) {
        $error = 'Vui lòng chọn chi nhánh hợp lệ.';
    } elseif (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Vui lòng chọn file để tải lên';
    } else {
        try {
            $rows = readImportRows($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        } catch (Throwable $e) {
            $rows = null;
            $error = 'Không đọc được file: ' . $e->getMessage();
        }

        if ($rows !== null) {
            array_shift($rows); // bo dong tieu de
            $updated = 0;
            $skipped = 0;
            $notFound = 0;

            foreach ($rows as $row) {
                if (count($row) < 2) { $skipped++; continue; }
                [$sku, $quantity] = array_pad($row, 2, null);
                $sku = trim((string) $sku);
                if ($sku === '' || !is_numeric($quantity)) { $skipped++; continue; }

                $prod = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND tenant_id = ?');
                $prod->execute([$sku, $tenantId]);
                $product = $prod->fetch();
                if (!$product) { $notFound++; continue; }

                // Nhap ton kho la khai bao so luong THUC TE dang co (ghi de), khac voi Nhap hang
                // (cong don) - vi day la nhap ton dau ky tu file Excel co san cua khach, khong
                // phai 1 lo hang moi nhap them.
                $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                $inv->execute([$branchId, $product['id']]);
                $invRow = $inv->fetch();
                if ($invRow) {
                    $pdo->prepare('UPDATE inventory SET quantity = ? WHERE id = ?')->execute([(float) $quantity, $invRow['id']]);
                } else {
                    $pdo->prepare('INSERT INTO inventory (branch_id, product_id, quantity) VALUES (?,?,?)')
                        ->execute([$branchId, $product['id'], (float) $quantity]);
                }
                $updated++;
            }
            $result = "Đã cập nhật tồn kho cho $updated sản phẩm, $notFound SKU không tìm thấy, bỏ qua $skipped dòng không hợp lệ.";
            logActivity('IMPORT_INVENTORY', "file={$_FILES['file']['name']} branch_id=$branchId updated=$updated not_found=$notFound skipped=$skipped");
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="inventory.php" class="muted" style="font-size:14px;">← Quản lý kho</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Nhập tồn kho (Excel)</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?><div class="alert alert-success"><?= e($result) ?></div><?php endif; ?>

<div class="card" style="max-width:640px;">
  <p class="muted" style="margin:0 0 12px;">
    Nhận file <b>Excel (.xlsx)</b>, cần có dòng tiêu đề: <code>sku,quantity</code>.
    Dùng khi mới bắt đầu sử dụng phần mềm, khai báo số lượng tồn thực tế đang có (không phải cộng
    dồn thêm) — sản phẩm phải được tạo trước (qua "Nhập file sản phẩm") thì mới nhập được tồn kho.
    Số lượng nhập vào sẽ <b>thay thế</b> số tồn hiện có của sản phẩm đó tại chi nhánh đã chọn.
  </p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field">
      <label>Chi nhánh</label>
      <select class="input" name="branch_id" required>
        <option value="">— Chọn chi nhánh —</option>
        <?php foreach ($branches as $b): ?>
          <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Chọn file Excel (.xlsx)</label><input class="input" type="file" name="file" accept=".xlsx" required></div>
    <button type="submit" class="btn">Nhập tồn kho</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
