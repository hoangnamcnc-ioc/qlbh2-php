<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$result = null;
$previewRows = null;
$previewFilename = '';

// Cac ten cot duoc chap nhan - nhan ca tieng Viet lan tieng Anh, khong phan biet hoa/thuong va
// khong phu thuoc dau tieng Viet (xem xlsxNormalizeHeader). Thu tu cot trong file KHONG con
// quan trong - truoc day doc theo vi tri nen file that cua khach hay bi doc lech toan bo ma van
// bao "thanh cong".
const PRODUCT_IMPORT_SPEC = [
    'name' => ['display' => 'Tên sản phẩm', 'required' => true,
        'labels' => ['Tên sản phẩm', 'Tên hàng', 'Tên hàng hóa', 'Tên', 'name', 'product_name']],
    'sku' => ['display' => 'Mã hàng (SKU)', 'required' => false,
        'labels' => ['Mã hàng', 'Mã sản phẩm', 'Mã SKU', 'SKU', 'Mã', 'sku']],
    'barcode' => ['display' => 'Mã vạch', 'required' => false,
        'labels' => ['Mã vạch', 'Barcode', 'Mã barcode', 'barcode']],
    'unit' => ['display' => 'Đơn vị tính', 'required' => false,
        'labels' => ['Đơn vị tính', 'Đơn vị', 'ĐVT', 'unit']],
    'cost_price' => ['display' => 'Giá vốn', 'required' => false,
        'labels' => ['Giá vốn', 'Giá nhập', 'cost_price']],
    'sell_price' => ['display' => 'Giá bán', 'required' => false,
        'labels' => ['Giá bán', 'Đơn giá', 'Giá', 'sell_price']],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    if (($_POST['action'] ?? '') === 'confirm') {
        // Buoc 2: nguoi dung da xem truoc va bam Xac nhan - gio moi ghi that vao CSDL.
        $rows = $_SESSION['import_preview_products'] ?? null;
        if (!$rows) {
            $error = 'Phiên xem trước đã hết hạn, vui lòng chọn lại file.';
        } else {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') { $skipped++; continue; }

                $sku = trim((string) ($row['sku'] ?? ''));
                $barcode = trim((string) ($row['barcode'] ?? '')) ?: null;
                $unit = trim((string) ($row['unit'] ?? '')) ?: null;
                $costPrice = (float) str_replace([',', ' '], '', (string) ($row['cost_price'] ?? 0));
                $sellPrice = (float) str_replace([',', ' '], '', (string) ($row['sell_price'] ?? 0));

                $existing = null;
                if ($sku !== '') {
                    $check = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND tenant_id = ?');
                    $check->execute([$sku, $tenantId]);
                    $existing = $check->fetch();
                }

                if ($existing) {
                    $pdo->prepare('UPDATE products SET barcode=?, name=?, unit=?, cost_price=?, sell_price=? WHERE id=?')
                        ->execute([$barcode, $name, $unit, $costPrice, $sellPrice, $existing['id']]);
                    $updated++;
                } else {
                    // File cua khach thuong khong co cot ma hang - tu sinh ma de ho khong phai tu
                    // nghi ra ma cho hang tram san pham.
                    if ($sku === '') {
                        $sku = genCode('SP');
                    }
                    $pdo->prepare('INSERT INTO products (sku, barcode, name, unit, cost_price, sell_price, tenant_id) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$sku, $barcode, $name, $unit, $costPrice, $sellPrice, $tenantId]);
                    $created++;
                }
            }

            unset($_SESSION['import_preview_products']);
            $result = "Đã thêm mới $created, cập nhật $updated"
                . ($skipped ? ", bỏ qua $skipped dòng thiếu tên sản phẩm" : '') . '.';
            logActivity('IMPORT_PRODUCTS', "created=$created updated=$updated skipped=$skipped");
        }
    } elseif (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Vui lòng chọn file để tải lên';
    } else {
        // Buoc 1: doc file, anh xa cot theo tieu de, giu tam trong session de xem truoc.
        try {
            $previewRows = readMappedImportRows($_FILES['file']['tmp_name'], $_FILES['file']['name'], PRODUCT_IMPORT_SPEC);
            $_SESSION['import_preview_products'] = $previewRows;
            $previewFilename = $_FILES['file']['name'];
        } catch (Throwable $e) {
            $previewRows = null;
            $error = $e->getMessage();
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="products.php" class="muted" style="font-size:14px;">← Danh sách sản phẩm</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Nhập file sản phẩm (Excel)</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?><div class="alert alert-success"><?= e($result) ?></div><?php endif; ?>

<?php if ($previewRows): ?>
  <?php renderImportPreview($previewRows, PRODUCT_IMPORT_SPEC, $previewFilename, csrfToken()); ?>
<?php else: ?>
  <div class="card" style="max-width:720px;">
    <p class="muted" style="margin:0 0 14px;">
      Tải lên file <b>Excel (.xlsx)</b>. Dòng đầu tiên phải là <b>dòng tiêu đề ghi tên cột</b> —
      thứ tự các cột thế nào cũng được, hệ thống tự nhận theo tên cột.
    </p>
    <table class="data-table" style="margin-bottom:14px;">
      <thead><tr><th>Cột</th><th>Bắt buộc</th><th>Tên cột chấp nhận</th></tr></thead>
      <tbody>
        <?php foreach (PRODUCT_IMPORT_SPEC as $def): ?>
          <tr>
            <td><b><?= e($def['display']) ?></b></td>
            <td><?= $def['required'] ? '<span style="color:#dc2626;font-weight:600;">Có</span>' : '<span class="muted">Không</span>' ?></td>
            <td class="muted" style="font-size:13px;"><?= e(implode(', ', array_slice($def['labels'], 0, 4))) ?>…</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted" style="margin:0 0 14px;font-size:13px;">
      Sản phẩm trùng mã hàng sẽ được cập nhật. Nếu file không có cột mã hàng, hệ thống <b>tự sinh mã</b>.
      <a href="products_export.php">Tải file mẫu (xuất từ dữ liệu hiện tại)</a>.
    </p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <div class="field"><label>Chọn file Excel (.xlsx)</label><input class="input" type="file" name="file" accept=".xlsx" required></div>
      <button type="submit" class="btn">Xem trước dữ liệu</button>
    </form>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
