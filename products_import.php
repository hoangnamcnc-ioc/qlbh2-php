<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
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
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                if (count($row) < 6) { $skipped++; continue; }
                [$sku, $barcode, $name, $unit, $costPrice, $sellPrice] = array_pad($row, 7, null);
                $sku = trim((string) $sku);
                $name = trim((string) $name);
                if ($sku === '' || $name === '') { $skipped++; continue; }

                $check = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND tenant_id = ?');
                $check->execute([$sku, $tenantId]);
                $existing = $check->fetch();

                if ($existing) {
                    $pdo->prepare('UPDATE products SET barcode=?, name=?, unit=?, cost_price=?, sell_price=? WHERE id=?')
                        ->execute([$barcode ?: null, $name, $unit ?: null, (float) $costPrice, (float) $sellPrice, $existing['id']]);
                    $updated++;
                } else {
                    $pdo->prepare('INSERT INTO products (sku, barcode, name, unit, cost_price, sell_price, tenant_id) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$sku, $barcode ?: null, $name, $unit ?: null, (float) $costPrice, (float) $sellPrice, $tenantId]);
                    $created++;
                }
            }
            $result = "Đã thêm mới $created, cập nhật $updated, bỏ qua $skipped dòng không hợp lệ.";
            logActivity('IMPORT_PRODUCTS', "file={$_FILES['file']['name']} created=$created updated=$updated skipped=$skipped");
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="products.php" class="muted" style="font-size:14px;">← Danh sách sản phẩm</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Nhập file sản phẩm (Excel)</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?><div class="alert alert-success"><?= e($result) ?></div><?php endif; ?>

<div class="card" style="max-width:640px;">
  <p class="muted" style="margin:0 0 12px;">
    Nhận file <b>Excel (.xlsx)</b>, cần có dòng tiêu đề:
    <code>sku,barcode,name,unit,cost_price,sell_price,is_active</code>.
    Sản phẩm trùng SKU sẽ được cập nhật, SKU mới sẽ được tạo mới.
    <a href="products_export.php">Tải file mẫu (xuất từ dữ liệu hiện tại)</a>.
  </p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field"><label>Chọn file Excel (.xlsx)</label><input class="input" type="file" name="file" accept=".xlsx" required></div>
    <button type="submit" class="btn">Nhập file</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
