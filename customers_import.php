<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Vui lòng chọn file CSV để tải lên';
    } else {
        $handle = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$handle) {
            $error = 'Không đọc được file';
        } else {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);

            $header = fgetcsv($handle);
            $created = 0;
            $updated = 0;
            $skipped = 0;

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 4) { $skipped++; continue; }
                [$code, $name, $phone, $address] = array_pad($row, 6, null);
                $name = trim((string) $name);
                $phone = trim((string) $phone) ?: null;
                if ($name === '') { $skipped++; continue; }

                $existing = null;
                if ($phone) {
                    $check = $pdo->prepare('SELECT id FROM customers WHERE phone = ?');
                    $check->execute([$phone]);
                    $existing = $check->fetch();
                }

                if ($existing) {
                    $pdo->prepare('UPDATE customers SET name=?, address=? WHERE id=?')
                        ->execute([$name, $address ?: null, $existing['id']]);
                    $updated++;
                } else {
                    $newCode = trim((string) $code) ?: ('CUZN' . substr((string) (int) round(microtime(true) * 1000 + $created), -8));
                    $pdo->prepare('INSERT INTO customers (code, name, phone, address) VALUES (?,?,?,?)')
                        ->execute([$newCode, $name, $phone, $address ?: null]);
                    $created++;
                }
            }
            fclose($handle);
            $result = "Đã thêm mới $created, cập nhật $updated, bỏ qua $skipped dòng không hợp lệ.";
            logActivity('IMPORT_CUSTOMERS', "file={$_FILES['file']['name']} created=$created updated=$updated skipped=$skipped");
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="customers.php" class="muted" style="font-size:14px;">← Danh sách khách hàng</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Nhập file khách hàng (CSV)</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?><div class="alert alert-success"><?= e($result) ?></div><?php endif; ?>

<div class="card" style="max-width:640px;">
  <p class="muted" style="margin:0 0 12px;">
    File CSV cần có dòng tiêu đề: <code>code,name,phone,address,debt,loyalty_points</code>.
    Khách trùng số điện thoại sẽ được cập nhật tên/địa chỉ, số mới sẽ được tạo khách hàng mới.
    <a href="customers_export.php">Tải file mẫu (xuất từ dữ liệu hiện tại)</a>.
  </p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field"><label>Chọn file CSV</label><input class="input" type="file" name="file" accept=".csv" required></div>
    <button type="submit" class="btn">Nhập file</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
