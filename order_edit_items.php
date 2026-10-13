<?php
/**
 * Sua SAN PHAM trong don (so luong, don gia, bo dong, them mat hang) cho don CHUA giao di
 * (Dat hang / Da duyet / Da dong goi). Moi thay doi dong bo day du: ton kho + lo hang (FEFO),
 * tong tien, trang thai thanh toan va cong no khach - khong de so sach lech nhau.
 * Don da giao/hoan thanh phai dung phieu tra hang; don da co phieu tra hang khong sua duoc.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$order = layDonHangCuaToi($id);
if (!$order) redirect('orders.php');

$editable = in_array($order['status'], ['DRAFT', 'APPROVED', 'PACKED'], true);
$hasReturn = $pdo->prepare('SELECT COUNT(*) FROM order_returns WHERE order_id = ?');
$hasReturn->execute([$id]);
$hasReturn = (int) $hasReturn->fetchColumn() > 0;

$error = null;

function layDongDon(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare(
        'SELECT oi.*, p.name AS product_name, p.sku, p.product_type, v.name AS variant_name
         FROM order_items oi JOIN products p ON p.id = oi.product_id LEFT JOIN product_variants v ON v.id = oi.variant_id
         WHERE oi.order_id = ? ORDER BY oi.id'
    );
    $st->execute([$orderId]);
    return $st->fetchAll();
}

/** Dieu chinh ton kho cac THANH PHAN cua 1 combo theo so luong combo thay doi (delta > 0 = lay them). */
function dieuChinhTonCombo(PDO $pdo, int $comboProductId, int $branchId, float $delta, callable $checkStock): void
{
    $cs = $pdo->prepare('SELECT component_product_id, quantity AS comp_qty FROM combo_items WHERE combo_product_id = ?');
    $cs->execute([$comboProductId]);
    foreach ($cs->fetchAll() as $c) {
        $need = (float) $c['comp_qty'] * $delta;
        if ($need > 0) {
            $checkStock((int) $c['component_product_id'], null, $need);
        }
        capNhatTonKho($pdo, $branchId, (int) $c['component_product_id'], null, -$need);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (!$editable) {
        $error = 'Chỉ sửa được sản phẩm khi đơn đang ở trạng thái Đặt hàng, Đã duyệt hoặc Đã đóng gói.';
    } elseif ($hasReturn) {
        $error = 'Đơn đã có phiếu trả hàng nên không sửa sản phẩm được nữa.';
    } else {
        $qtyIn = (array) ($_POST['qty'] ?? []);
        $priceIn = (array) ($_POST['price'] ?? []);
        $newProducts = (array) ($_POST['new_product_id'] ?? []);
        $newQtys = (array) ($_POST['new_qty'] ?? []);
        $newPrices = (array) ($_POST['new_price'] ?? []);
        $allowNegative = getSetting('allow_negative_stock', '0') === '1';

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $order = $lock->fetch();
            if (!in_array($order['status'], ['DRAFT', 'APPROVED', 'PACKED'], true)) {
                throw new RuntimeException('Trạng thái đơn đã thay đổi, vui lòng tải lại trang.');
            }
            $branchId = (int) $order['branch_id'];
            $oldTotal = (float) $order['total_amount'];
            $paid = (float) $order['paid_amount'];
            $changed = [];

            $checkStock = static function (int $productId, ?int $variantId, float $need) use ($pdo, $branchId, $allowNegative): void {
                if ($allowNegative || $need <= 0) return;
                if ($variantId) {
                    $st = $pdo->prepare('SELECT COALESCE(quantity,0) FROM inventory WHERE branch_id = ? AND variant_id = ?');
                    $st->execute([$branchId, $variantId]);
                } else {
                    $st = $pdo->prepare('SELECT COALESCE(quantity,0) FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                    $st->execute([$branchId, $productId]);
                }
                if ((float) $st->fetchColumn() < $need) {
                    throw new RuntimeException('Không đủ tồn kho để tăng số lượng (cần thêm ' . fmtQty($need) . ').');
                }
            };

            // 1) Cac dong hien co
            $lines = layDongDon($pdo, $id);
            foreach ($lines as $line) {
                $iid = (int) $line['id'];
                if (!array_key_exists($iid, $qtyIn)) {
                    continue;
                }
                $newQty = round((float) $qtyIn[$iid], 3);
                $newPrice = round(max(0, (float) ($priceIn[$iid] ?? $line['unit_price'])), 2);
                $oldQty = (float) $line['quantity'];
                $variantId = $line['variant_id'] ? (int) $line['variant_id'] : null;
                $isService = $line['product_type'] === 'SERVICE';

                $isCombo = $line['product_type'] === 'COMBO';
                if ($newQty <= 0) {
                    if ($isCombo) {
                        dieuChinhTonCombo($pdo, (int) $line['product_id'], $branchId, -$oldQty, $checkStock);
                    } elseif (!$isService) {
                        hoanLoHangTheoDong($pdo, $iid);
                        capNhatTonKho($pdo, $branchId, (int) $line['product_id'], $variantId, $oldQty);
                    }
                    $pdo->prepare('DELETE FROM order_items WHERE id = ? AND order_id = ?')->execute([$iid, $id]);
                    $changed[] = 'bỏ ' . $line['product_name'];
                    continue;
                }
                $delta = $newQty - $oldQty;
                if ($isCombo && $delta != 0) {
                    dieuChinhTonCombo($pdo, (int) $line['product_id'], $branchId, $delta, $checkStock);
                } elseif ($delta < 0 && !$isService) {
                    hoanLoHangTheoDong($pdo, $iid, -$delta);
                    capNhatTonKho($pdo, $branchId, (int) $line['product_id'], $variantId, -$delta);
                } elseif ($delta > 0 && !$isService && !$isCombo) {
                    $checkStock((int) $line['product_id'], $variantId, $delta);
                    capNhatTonKho($pdo, $branchId, (int) $line['product_id'], $variantId, -$delta);
                    if (!$variantId) {
                        foreach (truLoHangFEFO($pdo, (int) $line['product_id'], $branchId, $delta) as [$bid, $take]) {
                            $pdo->prepare('INSERT INTO order_item_batches (order_item_id, batch_id, quantity) VALUES (?,?,?)')->execute([$iid, $bid, $take]);
                        }
                    }
                }
                if ($delta != 0 || abs($newPrice - (float) $line['unit_price']) > 0.001) {
                    $pdo->prepare('UPDATE order_items SET quantity = ?, unit_price = ?, line_total = ? WHERE id = ?')
                        ->execute([$newQty, $newPrice, round($newQty * $newPrice, 2), $iid]);
                    $changed[] = $line['product_name'];
                }
            }

            // 2) Them mat hang moi (san pham thuong/dich vu, khong bien the/combo)
            foreach ($newProducts as $k => $pidRaw) {
                $pid = (int) $pidRaw;
                $qty = round((float) ($newQtys[$k] ?? 0), 3);
                if ($pid <= 0 || $qty <= 0) continue;
                $ps = $pdo->prepare('SELECT * FROM products WHERE id = ? AND tenant_id = ? AND is_active = 1');
                $ps->execute([$pid, $tenantId]);
                $prod = $ps->fetch();
                if (!$prod) throw new RuntimeException('Sản phẩm thêm vào không hợp lệ.');
                $vc = $pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id = ?');
                $vc->execute([$pid]);
                if ((int) $vc->fetchColumn() > 0) throw new RuntimeException('Sản phẩm "' . $prod['name'] . '" có biến thể — hãy tạo đơn mới để chọn biến thể.');
                $price = round(max(0, (float) ($newPrices[$k] !== '' && $newPrices[$k] !== null ? $newPrices[$k] : $prod['sell_price'])), 2);
                $isService = $prod['product_type'] === 'SERVICE';
                $isComboNew = $prod['product_type'] === 'COMBO';
                if ($isComboNew) {
                    dieuChinhTonCombo($pdo, $pid, $branchId, $qty, $checkStock);
                } elseif (!$isService) {
                    $checkStock($pid, null, $qty);
                    capNhatTonKho($pdo, $branchId, $pid, null, -$qty);
                }
                $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total, cost_price) VALUES (?,?,?,?,?,?)')
                    ->execute([$id, $pid, $qty, $price, round($qty * $price, 2), (float) $prod['cost_price']]);
                $newIid = (int) $pdo->lastInsertId();
                if (!$isService && !$isComboNew) {
                    foreach (truLoHangFEFO($pdo, $pid, $branchId, $qty) as [$bid, $take]) {
                        $pdo->prepare('INSERT INTO order_item_batches (order_item_id, batch_id, quantity) VALUES (?,?,?)')->execute([$newIid, $bid, $take]);
                    }
                }
                $changed[] = 'thêm ' . $prod['name'];
            }

            // 3) Tinh lai tong don
            $sum = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(line_total),0) AS s FROM order_items WHERE order_id = ?');
            $sum->execute([$id]);
            $sumRow = $sum->fetch();
            if ((int) $sumRow['n'] === 0) {
                throw new RuntimeException('Đơn phải còn ít nhất 1 sản phẩm. Muốn bỏ cả đơn hãy dùng "Hủy đơn".');
            }
            $subTotal = (float) $sumRow['s'];
            $discount = min((float) $order['discount'], $subTotal);
            $newTotal = $subTotal - $discount + (float) $order['shipping_fee'];
            if ($newTotal < $paid) {
                throw new RuntimeException('Tổng đơn mới (' . money($newTotal) . ') thấp hơn số tiền đã thu (' . money($paid) . '). Hãy hoàn tiền/tạo phiếu trả hàng thay vì giảm đơn.');
            }
            $payStatus = $paid >= $newTotal && $newTotal > 0 ? 'PAID' : ($paid > 0 ? 'PARTIAL' : 'UNPAID');
            $pdo->prepare('UPDATE orders SET sub_total = ?, discount = ?, total_amount = ?, payment_status = ? WHERE id = ?')
                ->execute([$subTotal, $discount, $newTotal, $payStatus, $id]);

            // Cong no khach: don da ghi no thi no cua don tang/giam dung bang phan tong tien thay doi.
            if ($order['customer_id'] && $order['status'] !== 'DRAFT') {
                $orderDebt = congNoConLaiCuaDon($pdo, $id);
                $tracked = $pdo->prepare('SELECT COUNT(*) FROM customer_debt_entries WHERE order_id = ?');
                $tracked->execute([$id]);
                $delta = $newTotal - $oldTotal;
                if ((int) $tracked->fetchColumn() > 0 && $delta != 0) {
                    $delta = $delta < 0 ? -min($orderDebt, -$delta) : $delta;
                    if ($delta != 0) {
                        $pdo->prepare('UPDATE customers SET debt = GREATEST(0, debt + ?) WHERE id = ?')->execute([$delta, $order['customer_id']]);
                        $pdo->prepare('INSERT INTO customer_debt_entries (customer_id, order_id, amount, note, created_by_id) VALUES (?,?,?,?,?)')
                            ->execute([$order['customer_id'], $id, $delta, 'Điều chỉnh sản phẩm đơn ' . $order['code'], $currentUser['id']]);
                    }
                }
            }

            $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, note, changed_by_id) VALUES (?,?,?,?,?)')
                ->execute([$id, $order['status'], $order['status'], mb_substr('Sửa sản phẩm trong đơn: ' . implode(', ', $changed ?: ['không đổi']), 0, 250), $currentUser['id']]);
            $pdo->commit();
            logActivity('ORDER_EDIT_ITEMS', "order_id=$id");
            redirect('order_view.php?id=' . $id);
        } catch (RuntimeException $ex) {
            $pdo->rollBack();
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'Không lưu được thay đổi, vui lòng thử lại.';
        }
    }
}

$lines = layDongDon($pdo, $id);
require_once __DIR__ . '/inc_header.php';
?>

<a href="order_view.php?id=<?= (int) $id ?>" class="muted" style="font-size:14px;">← Đơn hàng <?= e($order['code']) ?></a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 12px;">Sửa sản phẩm trong đơn</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!$editable): ?>
  <div class="alert alert-warning">Đơn đang ở trạng thái <b><?= e($order['status']) ?></b> nên không sửa sản phẩm được. Đơn đã giao/hoàn thành dùng <a href="order_returns.php">phiếu trả hàng</a> để đổi trả.</div>
<?php elseif ($hasReturn): ?>
  <div class="alert alert-warning">Đơn đã có phiếu trả hàng nên không sửa sản phẩm được nữa.</div>
<?php else: ?>
<p class="muted" style="font-size:13px;max-width:720px;">
  Đổi số lượng hoặc đơn giá, đặt số lượng <b>0</b> để bỏ dòng, hoặc thêm mặt hàng mới. Tồn kho, lô hạn dùng, tổng tiền,
  trạng thái thanh toán và công nợ khách tự cập nhật theo. Số tiền chiết khấu của đơn được giữ nguyên (đổi ở mục "Sửa đơn"). Sửa được cả combo (tồn kho các thành phần tự điều chỉnh); chỉ chưa <b>thêm mới</b> được hàng có biến thể (hãy tạo đơn mới để chọn biến thể).
</p>
<form method="post" class="card" style="padding:0;overflow-x:auto;max-width:860px;">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">Số lượng</th><th class="text-right">Đơn giá</th></tr></thead>
    <tbody>
      <?php foreach ($lines as $l): ?>
        <?php $locked = false; ?>
        <tr>
          <td><?= e($l['product_name']) ?><?= $l['variant_name'] ? ' — ' . e($l['variant_name']) : '' ?>
            <span class="muted" style="font-size:12px;">(<?= e($l['sku']) ?>)</span><?= $l['product_type'] === 'COMBO' ? ' <span class="muted">· combo</span>' : '' ?></td>
          <td class="text-right"><input class="input" type="number" step="0.001" min="0" name="qty[<?= (int) $l['id'] ?>]" value="<?= e(rtrim(rtrim(number_format((float) $l['quantity'], 3, '.', ''), '0'), '.')) ?>" style="width:100px;text-align:right;" <?= $locked ? 'disabled' : '' ?>></td>
          <td class="text-right"><input class="input" type="number" step="1" min="0" name="price[<?= (int) $l['id'] ?>]" value="<?= e((string) round((float) $l['unit_price'])) ?>" style="width:130px;text-align:right;" <?= $locked ? 'disabled' : '' ?>></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tbody id="new-lines"></tbody>
  </table>
  <div style="padding:14px 16px;border-top:1px solid #e2e8f0;">
    <label style="font-size:12px;">Thêm mặt hàng (gõ tên hoặc mã)</label>
    <input class="input" id="add-search" placeholder="Tìm sản phẩm..." style="max-width:320px;" autocomplete="off">
    <div id="add-results" style="max-width:420px;"></div>
  </div>
  <div style="padding:0 16px 16px;"><button type="submit" class="btn">Lưu thay đổi</button>
    <a href="order_view.php?id=<?= (int) $id ?>" class="btn btn-secondary" style="margin-left:8px;">Hủy</a></div>
</form>
<script>
const esc = (t) => String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const search = document.getElementById('add-search');
const results = document.getElementById('add-results');
let timer = null;
search.addEventListener('input', () => {
  clearTimeout(timer);
  const q = search.value.trim();
  if (q.length < 2) { results.innerHTML = ''; return; }
  timer = setTimeout(() => {
    fetch('pos_search.php?q=' + encodeURIComponent(q)).then(r => r.json()).then(list => {
      results.innerHTML = list.filter(p => !p.variant_id).slice(0, 8).map(p =>
        `<div class="pick" data-id="${p.id}" data-name="${esc(p.name)}" data-price="${p.sell_price}" style="padding:6px 10px;border:1px solid #e2e8f0;cursor:pointer;margin-top:2px;">${esc(p.name)} <span class="muted">(${esc(p.sku)}) · ${Math.round(p.sell_price).toLocaleString('vi-VN')}đ</span></div>`
      ).join('') || '<div class="muted" style="padding:6px;">Không tìm thấy (hàng có biến thể: tạo đơn mới).</div>';
    }).catch(() => {});
  }, 250);
});
results.addEventListener('click', (e) => {
  const el = e.target.closest('.pick');
  if (!el) return;
  const tr = document.createElement('tr');
  tr.innerHTML = `<td>${esc(el.dataset.name)} <span class="muted">· mới</span><input type="hidden" name="new_product_id[]" value="${esc(el.dataset.id)}"></td>
    <td class="text-right"><input class="input" type="number" step="0.001" min="0" name="new_qty[]" value="1" style="width:100px;text-align:right;"></td>
    <td class="text-right"><input class="input" type="number" step="1" min="0" name="new_price[]" value="${Math.round(el.dataset.price)}" style="width:130px;text-align:right;"></td>`;
  document.getElementById('new-lines').appendChild(tr);
  results.innerHTML = ''; search.value = '';
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
