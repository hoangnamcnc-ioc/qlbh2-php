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
$branchIds = array_map('intval', array_column($branches, 'id'));

$error = null;
$result = null;
$previewRows = null;
$previewFilename = '';
$previewBranchId = 0;

const INVENTORY_IMPORT_SPEC = [
    'sku' => ['display' => 'Mã hàng (SKU)', 'required' => true,
        'labels' => ['Mã hàng', 'Mã sản phẩm', 'Mã SKU', 'SKU', 'Mã', 'sku']],
    'quantity' => ['display' => 'Số lượng tồn', 'required' => true,
        'labels' => ['Số lượng tồn', 'Số lượng', 'Tồn kho', 'Tồn', 'quantity']],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $branchId = (int) ($_POST['branch_id'] ?? 0);

    // Xac nhan chi nhanh thuc su thuoc tenant hien tai - tranh truyen branch_id cua tenant khac
    // qua form de ghi de ton kho cua ho.
    if (!in_array($branchId, $branchIds, true)) {
        $error = 'Vui lòng chọn chi nhánh hợp lệ.';
    } elseif (($_POST['action'] ?? '') === 'confirm') {
        // Buoc 2: nguoi dung da xem truoc va bam Xac nhan - gio moi ghi that vao CSDL.
        $rows = $_SESSION['import_preview_inventory'] ?? null;
        if (!$rows) {
            $error = 'Phiên xem trước đã hết hạn, vui lòng chọn lại file.';
        } else {
            $updated = 0;
            $skipped = 0;
            $notFound = [];

            foreach ($rows as $row) {
                $sku = trim((string) ($row['sku'] ?? ''));
                $quantity = str_replace([',', ' '], '', (string) ($row['quantity'] ?? ''));
                if ($sku === '' || !is_numeric($quantity)) { $skipped++; continue; }

                $prod = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND tenant_id = ?');
                $prod->execute([$sku, $tenantId]);
                $product = $prod->fetch();
                if (!$product) {
                    $notFound[] = $sku;
                    continue;
                }

                // Nhap ton kho la khai bao so luong THUC TE dang co (ghi de), khac voi Nhap hang
                // (cong don) - vi day la nhap ton dau ky tu file Excel co san cua khach.
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

            unset($_SESSION['import_preview_inventory']);
            // Liet ke thang cac ma hang khong tim thay thay vi chi bao so luong - de nguoi dung
            // biet chinh xac phai sua gi trong file.
            $result = "Đã cập nhật tồn kho cho $updated sản phẩm."
                . ($notFound ? ' Không tìm thấy ' . count($notFound) . ' mã hàng: '
                    . e(implode(', ', array_slice($notFound, 0, 10)))
                    . (count($notFound) > 10 ? '…' : '')
                    . ' — hãy tạo sản phẩm trước (Nhập file sản phẩm) rồi nhập tồn kho lại.' : '')
                . ($skipped ? " Bỏ qua $skipped dòng thiếu mã hàng hoặc số lượng không hợp lệ." : '');
            logActivity('IMPORT_INVENTORY', "branch_id=$branchId updated=$updated not_found=" . count($notFound) . " skipped=$skipped");
        }
    } elseif (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Vui lòng chọn file để tải lên';
    } else {
        // Buoc 1: doc file, anh xa cot theo tieu de, giu tam trong session de xem truoc.
        try {
            $previewRows = readMappedImportRows($_FILES['file']['tmp_name'], $_FILES['file']['name'], INVENTORY_IMPORT_SPEC);
            $_SESSION['import_preview_inventory'] = $previewRows;
            $previewFilename = $_FILES['file']['name'];
            $previewBranchId = $branchId;
        } catch (Throwable $e) {
            $previewRows = null;
            $error = $e->getMessage();
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="inventory.php" class="muted" style="font-size:14px;">← Quản lý kho</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Nhập tồn kho (Excel)</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?><div class="alert alert-success"><?= $result ?></div><?php endif; ?>

<?php if ($previewRows): ?>
  <?php renderImportPreview($previewRows, INVENTORY_IMPORT_SPEC, $previewFilename, csrfToken(), ['branch_id' => $previewBranchId]); ?>
<?php else: ?>
  <div class="card" style="max-width:720px;">
    <p class="muted" style="margin:0 0 14px;">
      Tải lên file <b>Excel (.xlsx)</b>. Dòng đầu tiên phải là <b>dòng tiêu đề ghi tên cột</b> —
      thứ tự các cột thế nào cũng được, hệ thống tự nhận theo tên cột.
    </p>
    <table class="data-table" style="margin-bottom:14px;">
      <thead><tr><th>Cột</th><th>Bắt buộc</th><th>Tên cột chấp nhận</th></tr></thead>
      <tbody>
        <?php foreach (INVENTORY_IMPORT_SPEC as $def): ?>
          <tr>
            <td><b><?= e($def['display']) ?></b></td>
            <td><?= $def['required'] ? '<span style="color:#dc2626;font-weight:600;">Có</span>' : '<span class="muted">Không</span>' ?></td>
            <td class="muted" style="font-size:13px;"><?= e(implode(', ', array_slice($def['labels'], 0, 4))) ?>…</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted" style="margin:0 0 14px;font-size:13px;">
      Dùng khi mới bắt đầu sử dụng phần mềm, khai báo số lượng tồn <b>thực tế đang có</b> —
      số lượng nhập vào sẽ <b>thay thế</b> số tồn hiện có (không cộng dồn). Sản phẩm phải được
      tạo trước qua "Nhập file sản phẩm".
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
      <button type="submit" class="btn">Xem trước dữ liệu</button>
    </form>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
