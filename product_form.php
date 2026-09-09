<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$product = null;
$images = [];
$inventories = [];
$variants = [];
$variantInventories = [];
$branches = $pdo->query('SELECT * FROM branches ORDER BY name')->fetchAll();
$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$brands = $pdo->query('SELECT * FROM brands ORDER BY name')->fetchAll();
$priceLists = $pdo->query('SELECT * FROM price_lists ORDER BY name')->fetchAll();
$comboItems = [];
$productPrices = [];
$allProducts = [];
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

    $stmt = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order');
    $stmt->execute([$id]);
    $images = $stmt->fetchAll();

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

    if ($product['product_type'] === 'COMBO') {
        $stmt = $pdo->prepare(
            'SELECT ci.*, p.name AS product_name, p.sku AS product_sku
             FROM combo_items ci JOIN products p ON p.id = ci.component_product_id
             WHERE ci.combo_product_id = ?'
        );
        $stmt->execute([$id]);
        $comboItems = $stmt->fetchAll();
        $allProducts = $pdo->prepare("SELECT id, sku, name FROM products WHERE id != ? AND product_type != 'COMBO' ORDER BY name");
        $allProducts->execute([$id]);
        $allProducts = $allProducts->fetchAll();
    }

    $stmt = $pdo->prepare('SELECT * FROM product_prices WHERE product_id = ? AND variant_id IS NULL');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $pp) {
        $productPrices[$pp['price_list_id']] = $pp;
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
    $categoryId = (int) ($_POST['category_id'] ?? 0) ?: null;
    $brandId = (int) ($_POST['brand_id'] ?? 0) ?: null;
    $tags = post('tags') ?: null;
    $productType = in_array($_POST['product_type'] ?? '', ['PRODUCT', 'SERVICE', 'COMBO'], true) ? $_POST['product_type'] : 'PRODUCT';

    if ($name === '' || ($id === 0 && $sku === '')) {
        $error = 'Vui lòng nhập Tên sản phẩm' . ($id === 0 ? ' và Mã SKU' : '');
    } else {
        try {
            if ($id) {
                $pdo->prepare(
                    'UPDATE products SET name=?, barcode=?, unit=?, cost_price=?, sell_price=?, is_active=?, category_id=?, brand_id=?, tags=?, product_type=? WHERE id=?'
                )->execute([$name, $barcode, $unit, $costPrice, $sellPrice, $isActive, $categoryId, $brandId, $tags, $productType, $id]);

                foreach ($_POST['price_list_id'] ?? [] as $plId => $price) {
                    $plId = (int) $plId;
                    $price = $price === '' ? null : (float) $price;
                    if ($price === null) {
                        $pdo->prepare('DELETE FROM product_prices WHERE product_id = ? AND price_list_id = ? AND variant_id IS NULL')->execute([$id, $plId]);
                    } else {
                        $check = $pdo->prepare('SELECT id FROM product_prices WHERE product_id = ? AND price_list_id = ? AND variant_id IS NULL');
                        $check->execute([$id, $plId]);
                        $existing = $check->fetch();
                        if ($existing) {
                            $pdo->prepare('UPDATE product_prices SET price = ? WHERE id = ?')->execute([$price, $existing['id']]);
                        } else {
                            $pdo->prepare('INSERT INTO product_prices (product_id, price_list_id, price) VALUES (?,?,?)')->execute([$id, $plId, $price]);
                        }
                    }
                }
            } else {
                $check = $pdo->prepare('SELECT id FROM products WHERE sku = ?');
                $check->execute([$sku]);
                if ($check->fetch()) {
                    throw new RuntimeException('Mã SKU đã tồn tại, vui lòng chọn mã khác');
                }
                $pdo->prepare(
                    'INSERT INTO products (sku, barcode, name, unit, cost_price, sell_price, category_id, brand_id, tags, product_type) VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([$sku, $barcode, $name, $unit, $costPrice, $sellPrice, $categoryId, $brandId, $tags, $productType]);
                $id = (int) $pdo->lastInsertId();

                if ($productType === 'PRODUCT') {
                    $initialQty = postInt('initial_qty');
                    $minStock = postInt('min_stock');
                    // Gán tồn kho ban đầu vào chi nhánh của người tạo; nếu tài khoản chưa gán
                    // chi nhánh (vd admin tổng) thì dùng chi nhánh đầu tiên trong hệ thống.
                    $targetBranchId = $currentUser['branch_id'] ?: ($branches[0]['id'] ?? null);
                    if ($targetBranchId) {
                        $pdo->prepare('INSERT INTO inventory (branch_id, product_id, quantity, min_stock) VALUES (?,?,?,?)')
                            ->execute([$targetBranchId, $id, $initialQty, $minStock]);
                    }
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
<div style="display:flex;align-items:center;justify-content:space-between;margin:8px 0 24px;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">
    <?= $product ? e($product['name']) : 'Thêm sản phẩm' ?>
  </h1>
  <?php if ($product): ?>
    <form method="post" action="product_copy.php" style="display:inline;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
      <button type="submit" class="btn btn-secondary">Sao chép sản phẩm</button>
    </form>
  <?php endif; ?>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($product): ?>
<div class="card" style="max-width:640px;margin-bottom:16px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Hình ảnh sản phẩm</h2>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
    <?php foreach ($images as $img): ?>
      <div style="position:relative;width:90px;">
        <img src="uploads/products/<?= e($img['filename']) ?>" style="width:90px;height:90px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;">
        <form method="post" action="product_image_delete.php" style="position:absolute;top:2px;right:2px;">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="image_id" value="<?= (int) $img['id'] ?>">
          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
          <button type="submit" style="background:#dc2626;color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:12px;cursor:pointer;line-height:1;">×</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (!$images): ?><p class="muted" style="font-size:13px;margin:0;">Chưa có ảnh nào.</p><?php endif; ?>
  </div>
  <form method="post" action="product_image_upload.php" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
    <button type="submit" class="btn btn-secondary">Tải ảnh lên</button>
  </form>
</div>
<?php endif; ?>

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
        <label>Loại sản phẩm</label>
        <select class="input" name="product_type">
          <?php $pt = $product['product_type'] ?? 'PRODUCT'; ?>
          <option value="PRODUCT" <?= $pt === 'PRODUCT' ? 'selected' : '' ?>>Hàng hóa</option>
          <option value="SERVICE" <?= $pt === 'SERVICE' ? 'selected' : '' ?>>Dịch vụ (không quản lý tồn kho)</option>
          <option value="COMBO" <?= $pt === 'COMBO' ? 'selected' : '' ?>>Combo (gồm nhiều sản phẩm khác)</option>
        </select>
      </div>
      <div></div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label>Danh mục (<a href="categories.php" class="muted">quản lý</a>)</label>
        <select class="input" name="category_id">
          <option value="">— Không chọn —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= ($product['category_id'] ?? null) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Nhãn hiệu (<a href="brands.php" class="muted">quản lý</a>)</label>
        <select class="input" name="brand_id">
          <option value="">— Không chọn —</option>
          <?php foreach ($brands as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= ($product['brand_id'] ?? null) == $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
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

    <div class="field">
      <label>Tags (cách nhau bằng dấu phẩy)</label>
      <input class="input" name="tags" value="<?= e($product['tags'] ?? '') ?>" placeholder="vd: ban chay, moi ve">
    </div>

    <?php if ($product && $priceLists): ?>
    <div class="field">
      <label>Giá riêng theo bảng giá (<a href="price_lists.php" class="muted">quản lý</a>)</label>
      <?php foreach ($priceLists as $pl): ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
          <span class="muted" style="font-size:13px;width:160px;"><?= e($pl['name']) ?></span>
          <input class="input" type="number" min="0" step="1000" name="price_list_id[<?= (int) $pl['id'] ?>]"
                 value="<?= e((string) ($productPrices[$pl['id']]['price'] ?? '')) ?>"
                 placeholder="để trống = dùng giá mặc định" style="max-width:220px;">
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

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

<?php if ($product && !$variants && $product['product_type'] === 'PRODUCT'): ?>
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

<?php if ($product && $product['product_type'] === 'PRODUCT'): ?>
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

<?php if ($product && $product['product_type'] === 'COMBO'): ?>
<h2 style="font-size:18px;font-weight:600;margin:32px 0 12px;">Thành phần Combo</h2>
<div class="card" style="max-width:640px;padding:0;margin-bottom:16px;">
  <?php if (!$comboItems): ?>
    <div class="muted" style="padding:16px;font-size:13px;">Chưa có sản phẩm thành phần nào.</div>
  <?php endif; ?>
  <?php foreach ($comboItems as $ci): ?>
    <form method="post" action="combo_item_delete.php" style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-top:1px solid #f1f5f9;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="combo_item_id" value="<?= (int) $ci['id'] ?>">
      <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
      <div style="font-size:14px;">
        <?= e($ci['product_name']) ?>
        <span class="muted" style="font-family:monospace;font-size:12px;"> (<?= e($ci['product_sku']) ?>)</span>
        <span class="muted"> × <?= (int) $ci['quantity'] ?></span>
      </div>
      <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px;">Xóa</button>
    </form>
  <?php endforeach; ?>
</div>
<div class="card" style="max-width:640px;">
  <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm sản phẩm vào combo</h3>
  <form method="post" action="combo_item_save.php" style="display:flex;gap:8px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
    <div class="field" style="flex:1;margin:0;">
      <label>Sản phẩm</label>
      <select class="input" name="component_product_id" required>
        <?php foreach ($allProducts as $ap): ?>
          <option value="<?= (int) $ap['id'] ?>"><?= e($ap['name']) ?> (<?= e($ap['sku']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0;">
      <label>Số lượng</label>
      <input class="input" type="number" min="1" name="quantity" value="1" style="width:90px;">
    </div>
    <button type="submit" class="btn">Thêm</button>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
