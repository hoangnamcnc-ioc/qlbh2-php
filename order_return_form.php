<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$error = null;
$order = null;
$items = [];

$q = trim($_GET['q'] ?? '');

if ($q !== '') {
    $stmt = $pdo->prepare(
        'SELECT o.*, c.name AS customer_name FROM orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         WHERE o.code = ?'
    );
    $stmt->execute([$q]);
    $order = $stmt->fetch();

    if (!$order) {
        $error = 'Không tìm thấy đơn hàng với mã "' . $q . '"';
    } else {
        $stmt = $pdo->prepare(
            'SELECT oi.*, p.name AS product_name, v.name AS variant_name,
                    oi.quantity - COALESCE((
                        SELECT SUM(ori.quantity) FROM order_return_items ori WHERE ori.order_item_id = oi.id
                    ), 0) AS remaining_qty
             FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             LEFT JOIN product_variants v ON v.id = oi.variant_id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([$order['id']]);
        $items = $stmt->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $reason = post('reason');
    $quantities = $_POST['qty'] ?? [];

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $orderRow = $stmt->fetch();

    if (!$orderRow) {
        $error = 'Đơn hàng không hợp lệ';
    } else {
        $hasQty = false;
        foreach ($quantities as $qty) {
            if ((int) $qty > 0) { $hasQty = true; break; }
        }

        if (!$hasQty) {
            $error = 'Vui lòng nhập số lượng trả cho ít nhất 1 sản phẩm';
        } else {
            try {
                $pdo->beginTransaction();

                $refundTotal = 0.0;
                $lineData = [];

                foreach ($quantities as $orderItemId => $qtyRaw) {
                    $qty = (int) $qtyRaw;
                    if ($qty <= 0) continue;

                    $stmt = $pdo->prepare(
                        'SELECT oi.*, oi.quantity - COALESCE((
                            SELECT SUM(ori.quantity) FROM order_return_items ori WHERE ori.order_item_id = oi.id
                         ), 0) AS remaining_qty
                         FROM order_items oi WHERE oi.id = ? AND oi.order_id = ? FOR UPDATE'
                    );
                    $stmt->execute([(int) $orderItemId, $orderId]);
                    $item = $stmt->fetch();

                    if (!$item || $qty > (int) $item['remaining_qty']) {
                        throw new RuntimeException('Số lượng trả vượt quá số lượng còn lại có thể trả');
                    }

                    $lineTotal = (float) $item['unit_price'] * $qty;
                    $refundTotal += $lineTotal;
                    $lineData[] = [$item['id'], $item['product_id'], $item['variant_id'], $qty, $item['unit_price'], $lineTotal];

                    // Hoàn lại tồn kho tại chi nhánh đã bán (đúng biến thể nếu có).
                    // Dịch vụ không quản lý tồn kho nên bỏ qua; Combo hoàn ngược lại tồn kho
                    // của từng sản phẩm thành phần thay vì chính sản phẩm combo.
                    $typeStmt = $pdo->prepare('SELECT product_type FROM products WHERE id = ?');
                    $typeStmt->execute([$item['product_id']]);
                    $productType = $typeStmt->fetchColumn() ?: 'PRODUCT';

                    if ($productType === 'SERVICE') {
                        // không hoàn tồn kho
                    } elseif ($productType === 'COMBO') {
                        $comboStmt = $pdo->prepare('SELECT component_product_id, quantity AS comp_qty FROM combo_items WHERE combo_product_id = ?');
                        $comboStmt->execute([$item['product_id']]);
                        foreach ($comboStmt->fetchAll() as $comp) {
                            $restoreQty = (int) $comp['comp_qty'] * $qty;
                            $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                            $inv->execute([$orderRow['branch_id'], $comp['component_product_id']]);
                            $invRow = $inv->fetch();
                            if ($invRow) {
                                $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')
                                    ->execute([$restoreQty, $invRow['id']]);
                            } else {
                                $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,NULL,?)')
                                    ->execute([$orderRow['branch_id'], $comp['component_product_id'], $restoreQty]);
                            }
                        }
                    } else {
                        if ($item['variant_id']) {
                            $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND variant_id = ?');
                            $inv->execute([$orderRow['branch_id'], $item['variant_id']]);
                        } else {
                            $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                            $inv->execute([$orderRow['branch_id'], $item['product_id']]);
                        }
                        $invRow = $inv->fetch();
                        if ($invRow) {
                            $pdo->prepare('UPDATE inventory SET quantity = quantity + ? WHERE id = ?')
                                ->execute([$qty, $invRow['id']]);
                        } else {
                            $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
                                ->execute([$orderRow['branch_id'], $item['product_id'], $item['variant_id'], $qty]);
                        }
                    }
                }

                $code = 'SRN' . substr((string) (int) round(microtime(true) * 1000), -8);

                $pdo->prepare(
                    'INSERT INTO order_returns (code, order_id, customer_id, reason, refund_amount, created_by_id)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$code, $orderId, $orderRow['customer_id'], $reason ?: null, $refundTotal, $currentUser['id']]);
                $returnId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO order_return_items (return_id, order_item_id, product_id, variant_id, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?)'
                );
                foreach ($lineData as [$orderItemId, $productId, $variantId, $qty, $unitPrice, $lineTotal]) {
                    $itemStmt->execute([$returnId, $orderItemId, $productId, $variantId, $qty, $unitPrice, $lineTotal]);
                }

                if ($refundTotal > 0) {
                    $cbCode = 'PC' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                    $pdo->prepare(
                        'INSERT INTO cashbook_entries (code, branch_id, type, amount, reason, created_by_id) VALUES (?,?,?,?,?,?)'
                    )->execute([
                        $cbCode, $orderRow['branch_id'], 'PAYMENT', $refundTotal,
                        'Hoàn tiền đơn trả hàng ' . $code, $currentUser['id'],
                    ]);
                }

                $pdo->commit();
                redirect('order_returns.php?created=' . $code);
            } catch (RuntimeException $ex) {
                $pdo->rollBack();
                $error = $ex->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="order_returns.php" class="muted" style="font-size:14px;">← Danh sách đơn trả hàng</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Tạo đơn trả hàng</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:480px;margin-bottom:24px;">
  <form method="get">
    <div class="field">
      <label>Nhập mã đơn hàng gốc</label>
      <input class="input" name="q" required value="<?= e($q) ?>" placeholder="vd: DH6C9VT3D2L">
    </div>
    <button type="submit" class="btn btn-secondary">Tìm đơn hàng</button>
  </form>
</div>

<?php if ($order && $items): ?>
<div class="card" style="max-width:720px;">
  <h2 style="font-size:16px;font-weight:600;margin:0 0 4px;">
    Đơn gốc: <span style="font-family:monospace;"><?= e($order['code']) ?></span>
  </h2>
  <p class="muted" style="margin:0 0 16px;">Khách hàng: <?= e($order['customer_name'] ?: 'Khách lẻ') ?></p>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">

    <table style="margin-bottom:16px;">
      <thead>
        <tr><th>Sản phẩm</th><th class="text-right">Đơn giá</th><th class="text-right">SL đã mua</th><th class="text-right">Còn có thể trả</th><th class="text-center">SL trả</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['product_name']) ?><?php if ($it['variant_name']): ?> <span class="muted">(<?= e($it['variant_name']) ?>)</span><?php endif; ?></td>
            <td class="text-right"><?= money($it['unit_price']) ?></td>
            <td class="text-right"><?= (int) $it['quantity'] ?></td>
            <td class="text-right"><?= (int) $it['remaining_qty'] ?></td>
            <td class="text-center">
              <?php if ((int) $it['remaining_qty'] > 0): ?>
                <input type="number" name="qty[<?= (int) $it['id'] ?>]" min="0" max="<?= (int) $it['remaining_qty'] ?>" value="0" style="width:70px;text-align:center;padding:4px;border:1px solid #cbd5e1;border-radius:6px;">
              <?php else: ?>
                <span class="muted">Đã trả hết</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="field">
      <label>Lý do trả hàng</label>
      <input class="input" name="reason" placeholder="vd: Hàng lỗi, đổi ý...">
    </div>

    <button type="submit" class="btn">Tạo đơn trả hàng</button>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
