<?php
/**
 * Ke hang: dinh muc ke, ton tren ke, canh bao sap het tren ke (kho van con), ma PLU cho can dien tu, danh dau hang de mat.
 * Tong ton (inventory.quantity) la tong kho + ke; "ton tren ke" la phan dang bay tren ke, tru dan khi ban, bo sung tu kho bang nut "Len ke".
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;

$bs = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? ORDER BY name');
$bs->execute([$tenantId]);
$branches = $bs->fetchAll();
$ids = array_map(static fn($b) => (int) $b['id'], $branches);
$locked = hasRole('ADMIN') ? 0 : effectiveBranchId($currentUser);
$branchId = $locked ?: (int) ($_REQUEST['branch_id'] ?? 0);
if (!in_array($branchId, $ids, true)) {
    $branchId = $ids[0] ?? 0;
}
$q = trim((string) ($_GET['q'] ?? ''));
$only = ($_GET['f'] ?? '') === 'low';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $pid = (int) ($_POST['product_id'] ?? 0);
    $own = $pdo->prepare("SELECT id FROM products WHERE id = ? AND tenant_id = ? AND product_type = 'PRODUCT'");
    $own->execute([$pid, $tenantId]);
    if ($action === 'scale_mode') {
        setSetting('scale_mode', ($_POST['mode'] ?? '') === 'PRICE' ? 'PRICE' : 'WEIGHT');
        redirect('ke_hang.php?branch_id=' . $branchId . '&ok=1');
    } elseif (!$own->fetchColumn() || !$branchId) {
        $error = 'Không tìm thấy sản phẩm.';
    } elseif ($action === 'save') {
        $plu = preg_replace('/\D/', '', (string) ($_POST['plu'] ?? ''));
        $min = max(0.0, postFloat('shelf_min'));
        $loss = !empty($_POST['high_loss']) ? 1 : 0;
        if ($plu !== '' && (strlen($plu) > 5 || ltrim($plu, '0') === '')) {
            $error = 'Mã PLU gồm 1 đến 5 chữ số, khác 0.';
        } else {
            if ($plu !== '') {
                $dup = $pdo->prepare("SELECT COUNT(*) FROM products WHERE tenant_id = ? AND id <> ? AND plu IS NOT NULL AND TRIM(LEADING '0' FROM plu) = ?");
                $dup->execute([$tenantId, $pid, ltrim($plu, '0')]);
                if ((int) $dup->fetchColumn() > 0) $error = 'Mã PLU ' . $plu . ' đã gán cho sản phẩm khác.';
            }
            if (!$error) {
                $pdo->prepare('UPDATE products SET plu = ?, high_loss = ? WHERE id = ? AND tenant_id = ?')->execute([$plu !== '' ? $plu : null, $loss, $pid, $tenantId]);
                $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                $inv->execute([$branchId, $pid]);
                if ($iid = $inv->fetchColumn()) {
                    $pdo->prepare('UPDATE inventory SET shelf_min = ? WHERE id = ?')->execute([$min, $iid]);
                } else {
                    $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity, shelf_min) VALUES (?,?,NULL,0,?)')->execute([$branchId, $pid, $min]);
                }
                redirect('ke_hang.php?' . http_build_query(['branch_id' => $branchId, 'q' => $q, 'f' => $only ? 'low' : '', 'ok' => 1]));
            }
        }
    } elseif ($action === 'restock') {
        $n = max(0.0, postFloat('qty'));
        $inv = $pdo->prepare('SELECT id, quantity, shelf_qty FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL FOR UPDATE');
        $pdo->beginTransaction();
        $inv->execute([$branchId, $pid]);
        $row = $inv->fetch();
        $trongKho = $row ? (float) $row['quantity'] - (float) $row['shelf_qty'] : 0.0;
        if ($n <= 0) {
            $error = 'Nhập số lượng lên kệ lớn hơn 0.';
        } elseif (!$row || $n > $trongKho + 0.0005) {
            $error = 'Trong kho chỉ còn ' . rtrim(rtrim(number_format(max(0, $trongKho), 3, ',', '.'), '0'), ',') . ' để lên kệ.';
        } else {
            $pdo->prepare('UPDATE inventory SET shelf_qty = shelf_qty + ? WHERE id = ?')->execute([$n, $row['id']]);
            logActivity('SHELF_RESTOCK', "p=$pid n=$n");
            $pdo->commit();
            redirect('ke_hang.php?' . http_build_query(['branch_id' => $branchId, 'q' => $q, 'f' => $only ? 'low' : '', 'ok' => 1]));
        }
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

$sql = "SELECT p.id, p.name, p.sku, p.plu, p.high_loss, COALESCE(i.quantity,0) AS qty, COALESCE(i.shelf_qty,0) AS shelf_qty, COALESCE(i.shelf_min,0) AS shelf_min
        FROM products p LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
        WHERE p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'
          AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1)";
$args = [$branchId, $tenantId];
if ($q !== '') { $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.plu = ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $q); }
if ($only) { $sql .= ' AND COALESCE(i.shelf_min,0) > 0 AND COALESCE(i.shelf_qty,0) <= COALESCE(i.shelf_min,0)'; }
$sql .= ' ORDER BY (COALESCE(i.shelf_min,0) > 0 AND COALESCE(i.shelf_qty,0) <= COALESCE(i.shelf_min,0)) DESC, p.name LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
$nLow = (int) $pdo->query('SELECT COUNT(*) FROM inventory i JOIN products p ON p.id = i.product_id WHERE i.branch_id = ' . (int) $branchId . ' AND p.tenant_id = ' . (int) $tenantId . ' AND i.shelf_min > 0 AND i.shelf_qty <= i.shelf_min AND p.is_active = 1')->fetchColumn();
$fmt = static fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
$scaleMode = getSetting('scale_mode', 'WEIGHT');

require_once __DIR__ . '/inc_header.php';
echo thanhTabNghiepVu('kho');
?>
<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Kệ hàng và mã cân</h1>
<p class="muted" style="margin:0 0 14px;max-width:800px;font-size:14px;">
  Đặt <b>định mức kệ</b> cho từng mặt hàng: khi số lượng trên kệ xuống bằng hoặc dưới định mức, hàng được báo "sắp hết trên kệ" dù kho vẫn còn. Bấm <b>Lên kệ</b> để chuyển từ kho ra kệ (tổng tồn không đổi); bán hàng tự trừ dần số trên kệ.
  Gán <b>mã PLU</b> cho hàng cân; quét tem cân điện tử EAN-13 (2 + PLU 5 số + khối lượng hoặc số tiền 5 số + số kiểm tra) ở màn hình bán hàng sẽ tự thêm đúng sản phẩm và số lượng.
</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Đã lưu.</div><?php endif; ?>

<div class="card" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin-bottom:14px;">
  <form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:0;">
    <?php if (!$locked): ?><div><label>Chi nhánh</label><select name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $branchId ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div><label>Tìm</label><input class="input" name="q" value="<?= e($q) ?>" placeholder="Tên, SKU hoặc PLU"></div>
    <div><label><input type="checkbox" name="f" value="low" <?= $only ? 'checked' : '' ?>> Chỉ sắp hết trên kệ (<?= $nLow ?>)</label></div>
    <button class="btn" type="submit">Lọc</button>
  </form>
  <form method="post" style="display:flex;gap:8px;align-items:end;margin:0 0 0 auto;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="scale_mode"><input type="hidden" name="branch_id" value="<?= $branchId ?>">
    <div><label>Giá trị trong tem cân là</label><select name="mode"><option value="WEIGHT" <?= $scaleMode === 'WEIGHT' ? 'selected' : '' ?>>Khối lượng (gram)</option><option value="PRICE" <?= $scaleMode === 'PRICE' ? 'selected' : '' ?>>Thành tiền (đồng)</option></select></div>
    <button class="btn btn-secondary" type="submit">Lưu</button>
  </form>
</div>

<div class="card" style="overflow-x:auto;">
  <table>
    <thead><tr><th>Sản phẩm</th><th style="width:90px;">PLU</th><th style="text-align:right;">Tổng tồn</th><th style="text-align:right;">Trên kệ</th><th style="width:110px;">Định mức kệ</th><th>Dễ mất</th><th></th><th>Lên kệ</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted" style="text-align:center;padding:24px;">Không có sản phẩm phù hợp.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r):
        $low = (float) $r['shelf_min'] > 0 && (float) $r['shelf_qty'] <= (float) $r['shelf_min'];
        $trongKho = max(0.0, (float) $r['qty'] - (float) $r['shelf_qty']); ?>
      <tr style="<?= $low ? 'background:#fffbeb;' : '' ?>">
          <td><form id="sv<?= (int) $r['id'] ?>" method="post" style="display:none;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="product_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="branch_id" value="<?= $branchId ?>"></form>
            <?= e($r['name']) ?><div class="muted" style="font-size:12px;"><?= e($r['sku']) ?><?= $low ? ' · <b style="color:#b45309;">sắp hết trên kệ' . ($trongKho > 0 ? ', kho còn ' . $fmt($trongKho) : ', kho hết hàng') . '</b>' : '' ?></div></td>
          <td><input form="sv<?= (int) $r['id'] ?>" class="input" name="plu" value="<?= e($r['plu'] ?? '') ?>" maxlength="5" style="width:80px;padding:4px 8px;"></td>
          <td style="text-align:right;"><?= $fmt($r['qty']) ?></td>
          <td style="text-align:right;font-weight:600;color:<?= $low ? '#b45309' : 'inherit' ?>;"><?= $fmt($r['shelf_qty']) ?></td>
          <td><input form="sv<?= (int) $r['id'] ?>" class="input" type="number" min="0" step="any" name="shelf_min" value="<?= (float) $r['shelf_min'] > 0 ? e($fmt($r['shelf_min'])) : '' ?>" style="width:100px;padding:4px 8px;"></td>
          <td><input form="sv<?= (int) $r['id'] ?>" type="checkbox" name="high_loss" value="1" <?= (int) $r['high_loss'] ? 'checked' : '' ?>></td>
          <td><button form="sv<?= (int) $r['id'] ?>" class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Lưu</button></td>
        <td>
          <?php if ($trongKho > 0): ?>
          <form method="post" style="display:flex;gap:4px;margin:0;">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="restock"><input type="hidden" name="product_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="branch_id" value="<?= $branchId ?>">
            <input class="input" type="number" min="0.001" step="any" name="qty" value="<?= $low && (float) $r['shelf_min'] > 0 ? e($fmt(min($trongKho, max(1, (float) $r['shelf_min'] * 2 - (float) $r['shelf_qty'])))) : '' ?>" placeholder="SL" style="width:80px;padding:4px 8px;">
            <button class="btn" style="padding:3px 10px;font-size:12px;" type="submit">Lên kệ</button>
          </form>
          <?php else: ?><span class="muted" style="font-size:12px;">kho hết</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="muted" style="font-size:12px;margin-top:10px;">Hiển thị tối đa 200 sản phẩm, dùng ô tìm để thu hẹp. Hàng có biến thể chưa hỗ trợ kệ và mã cân.</p>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
