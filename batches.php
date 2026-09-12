<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;
$branches = $pdo->query('SELECT * FROM branches ORDER BY name')->fetchAll();
$products = $pdo->query("SELECT id, sku, name FROM products WHERE is_active = 1 AND product_type = 'PRODUCT' ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM product_batches WHERE id = ?')->execute([$id]);
        logActivity('BATCH_DELETE', 'id=' . $id);
        redirect('batches.php');
    } else {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $lotNumber = post('lot_number');
        $expiryDate = post('expiry_date') ?: null;
        $quantity = postQty('quantity');

        if (!$productId || !$branchId || $lotNumber === '' || $quantity <= 0) {
            $error = 'Vui lòng chọn sản phẩm, chi nhánh, nhập số lô và số lượng lớn hơn 0';
        } else {
            $pdo->prepare('INSERT INTO product_batches (product_id, branch_id, lot_number, expiry_date, quantity) VALUES (?,?,?,?,?)')
                ->execute([$productId, $branchId, $lotNumber, $expiryDate, $quantity]);
            logActivity('BATCH_CREATE', "product_id=$productId lot=$lotNumber qty=$quantity");
            redirect('batches.php');
        }
    }
}

$batches = $pdo->query(
    "SELECT b.*, p.name AS product_name, p.sku, br.name AS branch_name
     FROM product_batches b
     JOIN products p ON p.id = b.product_id
     JOIN branches br ON br.id = b.branch_id
     WHERE b.quantity > 0
     ORDER BY (b.expiry_date IS NULL), b.expiry_date"
)->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Lô hàng & Hạn sử dụng</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Theo dõi lô hàng theo hạn sử dụng cho từng sản phẩm/chi nhánh (sổ phụ, không thay thế tồn kho
  chính). Khi bán hàng, hệ thống tự động trừ vào lô có hạn sử dụng gần nhất trước
  (FEFO — First Expired, First Out) nếu sản phẩm có khai báo lô; sản phẩm không khai báo lô vẫn bán
  bình thường theo tồn kho như cũ.
</p>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="grid-2">
      <div class="field">
        <label>Sản phẩm *</label>
        <select class="input" name="product_id" required>
          <option value="">— Chọn sản phẩm —</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Chi nhánh *</label>
        <select class="input" name="branch_id" required>
          <?php foreach ($branches as $b): ?>
            <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Số lô *</label><input class="input" name="lot_number" required placeholder="vd: LOT20260901"></div>
      <div class="field"><label>Hạn sử dụng</label><input class="input" type="date" name="expiry_date"></div>
    </div>
    <div class="field"><label>Số lượng *</label><input class="input" type="number" min="0.001" step="0.001" name="quantity" required></div>
    <button type="submit" class="btn">Thêm lô hàng</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Sản phẩm</th><th>Chi nhánh</th><th>Số lô</th><th>Hạn sử dụng</th><th class="text-right">Số lượng còn</th><th></th></tr></thead>
    <tbody>
      <?php if (!$batches): ?><tr><td colspan="6" class="text-center muted" style="padding:24px;">Chưa có lô hàng nào.</td></tr><?php endif; ?>
      <?php foreach ($batches as $b): ?>
        <?php
          $expiringSoon = $b['expiry_date'] && strtotime($b['expiry_date']) <= strtotime('+30 days');
          $expired = $b['expiry_date'] && strtotime($b['expiry_date']) < strtotime('today');
        ?>
        <tr>
          <td><?= e($b['product_name']) ?> <span class="muted" style="font-family:monospace;font-size:12px;">(<?= e($b['sku']) ?>)</span></td>
          <td class="muted"><?= e($b['branch_name']) ?></td>
          <td style="font-family:monospace;"><?= e($b['lot_number']) ?></td>
          <td>
            <?php if ($b['expiry_date']): ?>
              <?= date('d/m/Y', strtotime($b['expiry_date'])) ?>
              <?php if ($expired): ?><span class="badge badge-red">Đã hết hạn</span>
              <?php elseif ($expiringSoon): ?><span class="badge badge-red">Sắp hết hạn</span><?php endif; ?>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td class="text-right"><?= fmtQty($b['quantity']) ?></td>
          <td class="text-right">
            <form method="post" onsubmit="return confirm('Xóa lô hàng này?');">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px;">Xóa</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
