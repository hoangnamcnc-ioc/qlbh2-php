<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

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
    // Cot them de chuyen du lieu tu KiotViet / Sapo / file Excel khac
    'category' => ['display' => 'Nhóm hàng', 'required' => false,
        'labels' => ['Nhóm hàng(3 Cấp)', 'Nhóm hàng', 'Danh mục', 'Loại sản phẩm', 'Nhóm sản phẩm', 'category']],
    'brand' => ['display' => 'Thương hiệu', 'required' => false,
        'labels' => ['Thương hiệu', 'Nhãn hiệu', 'brand']],
    'stock' => ['display' => 'Tồn kho', 'required' => false,
        'labels' => ['Tồn kho', 'Tồn', 'Số lượng tồn', 'Số lượng', 'stock', 'quantity']],
    'min_stock' => ['display' => 'Tồn tối thiểu', 'required' => false,
        'labels' => ['Tồn nhỏ nhất', 'Tồn tối thiểu', 'Định mức tồn', 'min_stock']],
    'active' => ['display' => 'Đang kinh doanh', 'required' => false,
        'labels' => ['Đang kinh doanh', 'Trạng thái', 'Hoạt động', 'active']],
];

/** Doc so tu file Excel cua cac phan mem khac: chap nhan 1.200.000 / 1,200,000 / 1200000 / 12,5 / 12.5. */
function parseSoNhap(string $v): float
{
    $v = trim(str_replace([' ', "\xC2\xA0"], '', $v));
    if ($v === '') {
        return 0.0;
    }
    if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $v)) {
        $v = str_replace(',', '.', str_replace('.', '', $v));
    } elseif (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $v)) {
        $v = str_replace(',', '', $v);
    } elseif (substr_count($v, ',') === 1 && strpos($v, '.') === false) {
        $v = str_replace(',', '.', $v);
    }
    return is_numeric($v) ? (float) $v : 0.0;
}

/** Tim hoac tao nhom hang / thuong hieu theo ten (khong phan biet hoa thuong), co bo nho dem trong 1 lan nhap. */
function timHoacTaoDanhMuc(PDO $pdo, string $table, int $tenantId, string $name, ?int $parentId = null): int
{
    static $cache = [];
    $key = $table . '|' . $tenantId . '|' . mb_strtolower($name) . '|' . ($parentId ?? 0);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if ($table === 'categories') {
        $st = $pdo->prepare('SELECT id FROM categories WHERE tenant_id = ? AND LOWER(name) = LOWER(?) AND ' . ($parentId ? 'parent_id = ?' : 'parent_id IS NULL') . ' LIMIT 1');
        $st->execute($parentId ? [$tenantId, $name, $parentId] : [$tenantId, $name]);
        $id = (int) $st->fetchColumn();
        if (!$id) {
            $pdo->prepare('INSERT INTO categories (name, parent_id, tenant_id) VALUES (?,?,?)')->execute([$name, $parentId, $tenantId]);
            $id = (int) $pdo->lastInsertId();
        }
    } else {
        $st = $pdo->prepare('SELECT id FROM brands WHERE tenant_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
        $st->execute([$tenantId, $name]);
        $id = (int) $st->fetchColumn();
        if (!$id) {
            $pdo->prepare('INSERT INTO brands (name, tenant_id) VALUES (?,?)')->execute([$name, $tenantId]);
            $id = (int) $pdo->lastInsertId();
        }
    }
    return $cache[$key] = $id;
}

/** "Do uong>>Nuoc ngot" (KiotViet 3 cap) -> id nhom con cuoi cung, tu tao nhom cha neu chua co. */
function nhomHangTuChuoi(PDO $pdo, int $tenantId, string $path): ?int
{
    $parent = null;
    foreach (preg_split('/\s*(?:>>|>|\/)\s*/u', $path, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $parent = timHoacTaoDanhMuc($pdo, 'categories', $tenantId, mb_substr($part, 0, 255), $parent);
    }
    return $parent;
}

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
            $stockSet = 0;

            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') { $skipped++; continue; }

                $sku = trim((string) ($row['sku'] ?? ''));
                $barcode = trim((string) ($row['barcode'] ?? '')) ?: null;
                $unit = trim((string) ($row['unit'] ?? '')) ?: null;
                $costPrice = parseSoNhap((string) ($row['cost_price'] ?? 0));
                $sellPrice = parseSoNhap((string) ($row['sell_price'] ?? 0));
                $catPath = trim((string) ($row['category'] ?? ''));
                $categoryId = $catPath !== '' ? nhomHangTuChuoi($pdo, $tenantId, $catPath) : null;
                $brandName = trim((string) ($row['brand'] ?? ''));
                $brandId = $brandName !== '' ? timHoacTaoDanhMuc($pdo, 'brands', $tenantId, mb_substr($brandName, 0, 255)) : null;
                $stockQty = array_key_exists('stock', $row) && trim((string) $row['stock']) !== '' ? max(0.0, parseSoNhap((string) $row['stock'])) : null;
                $minStockImp = array_key_exists('min_stock', $row) && trim((string) $row['min_stock']) !== '' ? max(0, (int) round(parseSoNhap((string) $row['min_stock']))) : null;
                $activeRaw = mb_strtolower(trim((string) ($row['active'] ?? '')));
                $isActive = in_array($activeRaw, ['0', 'không', 'khong', 'ngừng', 'ngung', 'no', 'false', 'ngừng kinh doanh'], true) ? 0 : 1;

                $existing = null;
                if ($sku !== '') {
                    $check = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND tenant_id = ?');
                    $check->execute([$sku, $tenantId]);
                    $existing = $check->fetch();
                }

                if ($existing) {
                    $pdo->prepare('UPDATE products SET barcode=?, name=?, unit=?, cost_price=?, sell_price=?, category_id = COALESCE(?, category_id), brand_id = COALESCE(?, brand_id), is_active = ? WHERE id=? AND tenant_id = ?')
                        ->execute([$barcode, $name, $unit, $costPrice, $sellPrice, $categoryId, $brandId, $isActive, $existing['id'], $tenantId]);
                    $productIdImp = (int) $existing['id'];
                    $updated++;
                } else {
                    // File cua khach thuong khong co cot ma hang - tu sinh ma de ho khong phai tu
                    // nghi ra ma cho hang tram san pham.
                    if ($sku === '') {
                        $sku = genCode('SP');
                    }
                    $pdo->prepare('INSERT INTO products (sku, barcode, name, unit, cost_price, sell_price, tenant_id, category_id, brand_id, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$sku, $barcode, $name, $unit, $costPrice, $sellPrice, $tenantId, $categoryId, $brandId, $isActive]);
                    $productIdImp = (int) $pdo->lastInsertId();
                    $created++;
                }

                // Ton kho dau ky (chi khi file co cot Ton kho): ghi vao chi nhanh cua nguoi nhap neu mat hang CHUA co dong ton o chi nhanh do -
                // khong ghi de so ton dang co.
                if ($stockQty !== null || $minStockImp !== null) {
                    $targetBranch = (int) ($currentUser['branch_id'] ?: ($branches0 ??= (int) $pdo->query('SELECT id FROM branches WHERE tenant_id = ' . (int) $tenantId . ' ORDER BY id LIMIT 1')->fetchColumn()));
                    if ($targetBranch) {
                        $has = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                        $has->execute([$targetBranch, $productIdImp]);
                        if (!$has->fetchColumn()) {
                            $pdo->prepare('INSERT INTO inventory (branch_id, product_id, quantity, min_stock) VALUES (?,?,?,?)')
                                ->execute([$targetBranch, $productIdImp, $stockQty ?? 0, $minStockImp ?? 0]);
                            $stockSet++;
                        }
                    }
                }
            }

            unset($_SESSION['import_preview_products']);
            $result = "Đã thêm mới $created, cập nhật $updated"
                . ($stockSet ? ", ghi tồn kho đầu kỳ cho $stockSet mặt hàng" : '')
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
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;">
      <b>Đang dùng KiotViet, Sapo hoặc phần mềm khác?</b> Xuất danh sách hàng hóa ra Excel (.xlsx) từ phần mềm cũ rồi tải lên đây — hệ thống tự nhận
      các cột <i>Mã hàng, Tên hàng, Mã vạch, Nhóm hàng (3 cấp, tự tạo nhóm cha–con), Thương hiệu, Giá vốn, Giá bán, Tồn kho, Tồn nhỏ nhất, ĐVT, Đang kinh doanh</i>
      (số kiểu 1.200.000 hoặc 1,200,000 đều được). Tồn kho chỉ ghi cho hàng chưa có tồn ở chi nhánh của bạn, không đè số đang có. Nhớ xem trước kết quả rồi mới xác nhận.
    </div>
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
