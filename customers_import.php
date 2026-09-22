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

const CUSTOMER_IMPORT_SPEC = [
    'name' => ['display' => 'Tên khách hàng', 'required' => true,
        'labels' => ['Tên khách hàng', 'Tên khách', 'Họ tên', 'Tên', 'name']],
    'phone' => ['display' => 'Số điện thoại', 'required' => false,
        'labels' => ['Số điện thoại', 'Điện thoại', 'SĐT', 'phone']],
    'code' => ['display' => 'Mã khách hàng', 'required' => false,
        'labels' => ['Mã khách hàng', 'Mã khách', 'Mã KH', 'Mã', 'code']],
    'address' => ['display' => 'Địa chỉ', 'required' => false,
        'labels' => ['Địa chỉ', 'address']],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    if (($_POST['action'] ?? '') === 'confirm') {
        // Buoc 2: nguoi dung da xem truoc va bam Xac nhan - gio moi ghi that vao CSDL.
        $rows = $_SESSION['import_preview_customers'] ?? null;
        if (!$rows) {
            $error = 'Phiên xem trước đã hết hạn, vui lòng chọn lại file.';
        } else {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') { $skipped++; continue; }

                $phone = trim((string) ($row['phone'] ?? '')) ?: null;
                $address = trim((string) ($row['address'] ?? '')) ?: null;
                $code = trim((string) ($row['code'] ?? ''));

                $existing = null;
                if ($phone) {
                    $check = $pdo->prepare('SELECT id FROM customers WHERE phone = ? AND tenant_id = ?');
                    $check->execute([$phone, $tenantId]);
                    $existing = $check->fetch();
                }

                if ($existing) {
                    $pdo->prepare('UPDATE customers SET name=?, address=? WHERE id=?')
                        ->execute([$name, $address, $existing['id']]);
                    $updated++;
                } else {
                    $newCode = $code ?: genCode('KH');
                    $pdo->prepare('INSERT INTO customers (code, name, phone, address, tenant_id) VALUES (?,?,?,?,?)')
                        ->execute([$newCode, $name, $phone, $address, $tenantId]);
                    $created++;
                }
            }

            unset($_SESSION['import_preview_customers']);
            $result = "Đã thêm mới $created, cập nhật $updated"
                . ($skipped ? ", bỏ qua $skipped dòng thiếu tên khách hàng" : '') . '.';
            logActivity('IMPORT_CUSTOMERS', "created=$created updated=$updated skipped=$skipped");
        }
    } elseif (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Vui lòng chọn file để tải lên';
    } else {
        // Buoc 1: doc file, anh xa cot theo tieu de, giu tam trong session de xem truoc.
        try {
            $previewRows = readMappedImportRows($_FILES['file']['tmp_name'], $_FILES['file']['name'], CUSTOMER_IMPORT_SPEC);
            $_SESSION['import_preview_customers'] = $previewRows;
            $previewFilename = $_FILES['file']['name'];
        } catch (Throwable $e) {
            $previewRows = null;
            $error = $e->getMessage();
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="customers.php" class="muted" style="font-size:14px;">← Danh sách khách hàng</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Nhập file khách hàng (Excel)</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?><div class="alert alert-success"><?= e($result) ?></div><?php endif; ?>

<?php if ($previewRows): ?>
  <?php renderImportPreview($previewRows, CUSTOMER_IMPORT_SPEC, $previewFilename, csrfToken()); ?>
<?php else: ?>
  <div class="card" style="max-width:720px;">
    <p class="muted" style="margin:0 0 14px;">
      Tải lên file <b>Excel (.xlsx)</b>. Dòng đầu tiên phải là <b>dòng tiêu đề ghi tên cột</b> —
      thứ tự các cột thế nào cũng được, hệ thống tự nhận theo tên cột.
    </p>
    <table class="data-table" style="margin-bottom:14px;">
      <thead><tr><th>Cột</th><th>Bắt buộc</th><th>Tên cột chấp nhận</th></tr></thead>
      <tbody>
        <?php foreach (CUSTOMER_IMPORT_SPEC as $def): ?>
          <tr>
            <td><b><?= e($def['display']) ?></b></td>
            <td><?= $def['required'] ? '<span style="color:#dc2626;font-weight:600;">Có</span>' : '<span class="muted">Không</span>' ?></td>
            <td class="muted" style="font-size:13px;"><?= e(implode(', ', array_slice($def['labels'], 0, 4))) ?>…</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted" style="margin:0 0 14px;font-size:13px;">
      Khách trùng số điện thoại sẽ được cập nhật tên/địa chỉ. Nếu file không có cột mã khách,
      hệ thống <b>tự sinh mã</b>. <a href="customers_export.php">Tải file mẫu (xuất từ dữ liệu hiện tại)</a>.
    </p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <div class="field"><label>Chọn file Excel (.xlsx)</label><input class="input" type="file" name="file" accept=".xlsx" required></div>
      <button type="submit" class="btn">Xem trước dữ liệu</button>
    </form>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
