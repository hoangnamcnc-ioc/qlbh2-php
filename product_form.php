<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$product = null;
$inventories = [];
$variants = [];
$variantInventories = [];
$branches = $pdo->query('SELECT * FROM branches ORDER BY name')->fetchAll();
$error = null;

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) {
        redirect('products.php');
    }
    $stmt = $pdo->prepare('SELECT * FROM inventory WHERE product_id = ? AND variant_id IS NULL');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $inv) {
        $inventories[$inv['branch_id']] = $inv;
    }

    $stmt = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY id');
    $stmt->execute([$id]);
    $variants = $stmt->fetchAll();

    $variantInventories = [];
    if ($variants) {
        $stmt = $pdo->prepare(
            'SELECT * FROM inventory WHERE variant_id IN (' . implode(',', array_fill(0, count($variants), '?')) . ')'
        );
        $stmt->execute(array_column($variants, 'id'));
        foreach ($stmt->fetchAll() as $inv) {
            $variantInventories[$inv['variant_id']][$inv['branch_id']] = $inv;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $sku = post('sku');
    $barcode = post('barcode') ?: null;
    $name = post('name');
    $unit = post('unit') ?: null;
    $costPrice = postFloat('cost_price');
    $sellPrice = postFloat('sell_price');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || ($id === 0 && $sku === '')) {
        $error = 'Vui lòng nhập Tên sản phẩm' . ($id === 0 ? ' và Mã SKU' : '');
    } else {
        try {
            if ($id) {
                $pdo->prepare(
                    'UPDATE products SET name=?, barcode=?, unit=?, cost_price=?, sell_price=?, is_active=? WHERE id=?'
                )->execute([$name, $barcode, $unit, $costPrice, $sellPrice, $isActive, $id]);
            } else {
                $check = $pdo->prepare('SELECT id FROM products WHERE sku = ?');
                $check->execute([$sku]);
                if ($check->fetch()) {
                    throw new RuntimeException('Mã SKU đã tồn tại, vui lòng chọn mã khác');
                }
                $pdo->prepare(
                    'INSERT INTO products (sku, barcode, name, unit, cost_price, sell_price) VALUES (?,?,?,?,?,?)'
                )->execute([$sku, $barcode, $name, $unit, $costPrice, $sellPrice]);
                $id = (int) $pdo->lastInsertId();

                $initialQty = postInt('initial_qty');
                $minStock = postInt('min_stock');
                if ($branches) {
                    $pdo->prepare('INSERT INTO inventory (branch_id, product_id, quantity, min_stock) VALUES (?,?,?,?)')
                        ->execute([$branches[0]['id'], $id, $initialQty, $minStock]);
                }
            }
            redirect('product_form.php?id=' . $id . '&saved=1');
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $success = 'Đã lưu thành công';
}
$variantError = $_GET['variant_error'] ?? null;

require_once __DIR__ . '/inc_header.php';
?>

<a href="products.php" class="muted" style="font-size:14px;">← Danh sách sản phẩm</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">
  <?= $product ? e($product['name']) : 'Thêm sản phẩm' ?>
</h1>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:640px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

    <div class="grid-2">
      <div class="field">
        <label>Mã SKU <?= $product ? '' : '*' ?></label>
        <?php if ($product): ?>
          <input class="input" value="<?= e($product['sku']) ?>" disabled>
        <?php else: ?>
          <input class="input" name="sku" required>
        <?php endif; ?>
      </div>
      <div class="field">
        <label>Mã barcode</label>
        <input class="input" name="barcode" value="<?= e($product['barcode'] ?? '') ?>">
      </div>
    </div>

    <div class="field">
      <label>Tên sản phẩm *</label>
      <input class="input" name="name" required value="<?= e($product['name'] ?? '') ?>">
    </div>

    <div class="grid-2">
      <div class="field">
        <label>Đơn vị tính</label>
        <input class="input" name="unit" value="<?= e($product['unit'] ?? '') ?>">
      </div>
      <div></div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label>Giá vốn</label>
        <input class="input" type="number" min="0" name="cost_price" value="<?= e((string) ($product['cost_price'] ?? '0')) ?>">
      </div>
      <div class="field">
        <label>Giá bán</label>
        <input class="input" type="number" min="0" name="sell_price" value="<?= e((string) ($product['sell_price'] ?? '0')) ?>">
      </div>
    </div>

    <?php if (!$product): ?>
      <div class="grid-2">
        <div class="field">
          <label>Tồn kho ban đầu</label>
          <input class="input" type="number" min="0" name="initial_qty" value="0">
        </div>
        <div class="field">
          <label>Định mức tối thiểu</label>
          <input class="input" type="number" min="0" name="min_stock" value="0">
        </div>
      </div>
    <?php else: ?>
      <div class="field">
        <label><input type="checkbox" name="is_active" <?= $product['is_active'] ? 'checked' : '' ?>> Đang bán</label>
      </div>
    <?php endif; ?>

    <button type="submit" class="btn">Lưu sản phẩm</button>
  </form>
</div>

<?php if ($product && !$variants): ?>
<h2 style="font-size:18px;font-weight:600;margin:32px 0 12px;">Tồn kho theo chi nhánh</h2>
<div class="card" style="max-width:640px;padding:0;">
  <?php foreach ($branches as $b): ?>
    <?php $inv = $inventories[$b['id']] ?? ['quantity' => 0, 'min_stock' => 0]; ?>
    <form method="post" action="inventory_save.php" style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-top:1px solid #f1f5f9;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
      <input type="hidden" name="branch_id" value="<?= (int) $b['id'] ?>">
      <input type="hidden" name="redirect" value="product_form.php?id=<?= (int) $product['id'] ?>">
      <div style="flex:1;font-size:14px;font-weight:500;">
        <?= e($b['name']) ?>
        <?php if ((int) $inv['quantity'] <= (int) $inv['min_stock']): ?>
          <span class="badge badge-red">Dưới định mức</span>
        <?php endif; ?>
      </div>
      <label class="muted" style="font-size:12px;">SL:
        <input type="number" min="0" name="quantity" value="<?= (int) $inv['quantity'] ?>" style="width:80px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:6px;">
      </label>
      <label class="muted" style="font-size:12px;">Định mức:
        <input type="number" min="0" name="min_stock" value="<?= (int) $inv['min_stock'] ?>" style="width:80px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:6px;">
      </label>
      <button type="submit" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;">Cập nhật</button>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($product): ?>
<h2 style="font-size:18px;font-weight:600;margin:32px 0 12px;">Biến thể sản phẩm (màu/size)</h2>
<?php if ($variantError): ?><div class="alert alert-error" style="max-width:640px;"><?= e($variantError) ?></div><?php endif; ?>

<?php if ($variants): ?>
  <div class="muted" style="max-width:640px;margin-bottom:12px;font-size:13px;">
    Sản phẩm này bán theo biến thể — mỗi biến thể có giá và tồn kho riêng theo từng chi nhánh.
  </div>
  <?php foreach ($variants as $v): ?>
    <div class="card" style="max-width:640px;margin-bottom:12px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <div>
          <b><?= e($v['name']) ?></b>
          <span class="muted" style="font-family:monospace;font-size:12px;"> (<?= e($v['sku']) ?>)</span>
          <?php if (!$v['is_active']): ?><span class="badge badge-gray">Đã ẩn</span><?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
          <span class="muted" style="font-size:13px;">Giá bán: <?= money($v['sell_price']) ?></span>
          <?php if ($v['is_active']): ?>
            <form method="post" action="variant_delete.php" onsubmit="return confirm('Ẩn biến thể này?');">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="variant_id" value="<?= (int) $v['id'] ?>">
              <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
              <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px;">Xóa</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php foreach ($branches as $b): ?>
        <?php $inv = $variantInventories[$v['id']][$b['id']] ?? ['quantity' => 0, 'min_stock' => 0]; ?>
        <form method="post" action="inventory_save.php" style="display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid #f1f5f9;">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
          <input type="hidden" name="variant_id" value="<?= (int) $v['id'] ?>">
          <input type="hidden" name="branch_id" value="<?= (int) $b['id'] ?>">
          <input type="hidden" name="redirect" value="product_form.php?id=<?= (int) $product['id'] ?>">
          <div style="flex:1;font-size:13px;">
            <?= e($b['name']) ?>
            <?php if ((int) $inv['quantity'] <= (int) $inv['min_stock']): ?>
              <span class="badge badge-red">Dưới định mức</span>
            <?php endif; ?>
          </div>
          <label class="muted" style="font-size:12px;">SL:
            <input type="number" min="0" name="quantity" value="<?= (int) $inv['quantity'] ?>" style="width:70px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:6px;">
          </label>
          <label class="muted" style="font-size:12px;">Định mức:
            <input type="number" min="0" name="min_stock" value="<?= (int) $inv['min_stock'] ?>" style="width:70px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:6px;">
          </label>
          <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;">Cập nhật</button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="card" style="max-width:640px;">
  <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm biến thể mới</h3>
  <form method="post" action="variant_save.php">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
    <div class="grid-2">
      <div class="field"><label>Mã SKU biến thể *</label><input class="input" name="sku" required placeholder="vd: <?= e($product['sku']) ?>-DO-L"></div>
      <div class="field"><label>Tên biến thể *</label><input class="input" name="name" required placeholder="vd: Đỏ - L"></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Giá vốn</label><input class="input" type="number" min="0" name="cost_price" value="0"></div>
      <div class="field"><label>Giá bán</label><input class="input" type="number" min="0" name="sell_price" value="0"></div>
    </div>
    <div class="field"><label>Tồn kho ban đầu</label><input class="input" type="number" min="0" name="initial_qty" value="0" style="max-width:200px;"></div>
    <button type="submit" class="btn">Thêm biến thể</button>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
