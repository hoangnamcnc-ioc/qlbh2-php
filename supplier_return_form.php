<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;
$receipt = null;
$items = [];

$q = trim($_GET['q'] ?? '');

if ($q !== '') {
    $stmt = $pdo->prepare(
        'SELECT sr.*, s.name AS supplier_name FROM stock_receipts sr
         LEFT JOIN suppliers s ON s.id = sr.supplier_id
         WHERE sr.code = ?'
    );
    $stmt->execute([$q]);
    $receipt = $stmt->fetch();

    if (!$receipt) {
        $error = 'Không tìm thấy phiếu nhập với mã "' . $q . '"';
    } elseif (!$receipt['supplier_id']) {
        $error = 'Phiếu nhập này không gắn nhà cung cấp, không thể tạo trả hàng NCC';
        $receipt = null;
    } else {
        $stmt = $pdo->prepare(
            'SELECT sri.*, p.name AS product_name, v.name AS variant_name,
                    sri.quantity - COALESCE((
                        SELECT SUM(sret.quantity) FROM supplier_return_items sret WHERE sret.product_id = sri.product_id AND (sret.variant_id <=> sri.variant_id)
                        AND sret.return_id IN (SELECT id FROM supplier_returns WHERE receipt_id = sri.receipt_id)
                    ), 0) AS remaining_qty
             FROM stock_receipt_items sri
             JOIN products p ON p.id = sri.product_id
             LEFT JOIN product_variants v ON v.id = sri.variant_id
             WHERE sri.receipt_id = ?'
        );
        $stmt->execute([$receipt['id']]);
        $items = $stmt->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $receiptId = (int) ($_POST['receipt_id'] ?? 0);
    $reason = post('reason');
    $quantities = $_POST['qty'] ?? [];

    $stmt = $pdo->prepare('SELECT * FROM stock_receipts WHERE id = ?');
    $stmt->execute([$receiptId]);
    $receiptRow = $stmt->fetch();

    if (!$receiptRow || !$receiptRow['supplier_id']) {
        $error = 'Phiếu nhập không hợp lệ';
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

                foreach ($quantities as $itemId => $qtyRaw) {
                    $qty = (int) $qtyRaw;
                    if ($qty <= 0) continue;

                    $stmt = $pdo->prepare(
                        'SELECT sri.*, sri.quantity - COALESCE((
                            SELECT SUM(sret.quantity) FROM supplier_return_items sret WHERE sret.product_id = sri.product_id AND (sret.variant_id <=> sri.variant_id)
                            AND sret.return_id IN (SELECT id FROM supplier_returns WHERE receipt_id = sri.receipt_id)
                         ), 0) AS remaining_qty
                         FROM stock_receipt_items sri WHERE sri.id = ? AND sri.receipt_id = ? FOR UPDATE'
                    );
                    $stmt->execute([(int) $itemId, $receiptId]);
                    $item = $stmt->fetch();

                    if (!$item || $qty > (int) $item['remaining_qty']) {
                        throw new RuntimeException('Số lượng trả vượt quá số lượng còn lại có thể trả');
                    }

                    $lineTotal = (float) $item['cost_price'] * $qty;
                    $refundTotal += $lineTotal;
                    $lineData[] = [$item['product_id'], $item['variant_id'], $qty, $item['cost_price'], $lineTotal];

                    // Trừ tồn kho vì hàng đã trả lại nhà cung cấp
                    if ($item['variant_id']) {
                        $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND variant_id = ?');
                        $inv->execute([$receiptRow['branch_id'], $item['variant_id']]);
                    } else {
                        $inv = $pdo->prepare('SELECT id FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL');
                        $inv->execute([$receiptRow['branch_id'], $item['product_id']]);
                    }
                    $invRow = $inv->fetch();
                    if ($invRow) {
                        $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $invRow['id']]);
                    }
                }

                $code = 'SRT' . substr((string) (int) round(microtime(true) * 1000), -8);

                $pdo->prepare(
                    'INSERT INTO supplier_returns (code, supplier_id, branch_id, receipt_id, reason, refund_amount, created_by_id)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([$code, $receiptRow['supplier_id'], $receiptRow['branch_id'], $receiptId, $reason ?: null, $refundTotal, $currentUser['id']]);
                $returnId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO supplier_return_items (return_id, product_id, variant_id, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)'
                );
                foreach ($lineData as [$productId, $variantId, $qty, $unitPrice, $lineTotal]) {
                    $itemStmt->execute([$returnId, $productId, $variantId, $qty, $unitPrice, $lineTotal]);
                }

                // Giảm công nợ phải trả NCC tương ứng giá trị hàng trả lại
                $pdo->prepare('UPDATE suppliers SET debt = GREATEST(0, debt - ?) WHERE id = ?')
                    ->execute([$refundTotal, $receiptRow['supplier_id']]);

                $pdo->commit();
                logActivity('SUPPLIER_RETURN_CREATE', "code=$code refund=$refundTotal");
                redirect('supplier_returns.php?created=' . $code);
            } catch (RuntimeException $ex) {
                $pdo->rollBack();
                $error = $ex->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="supplier_returns.php" class="muted" style="font-size:14px;">← Danh sách trả hàng NCC</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Tạo trả hàng nhà cung cấp</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:480px;margin-bottom:24px;">
  <form method="get">
    <div class="field">
      <label>Nhập mã phiếu nhập hàng gốc</label>
      <input class="input" name="q" required value="<?= e($q) ?>" placeholder="vd: PN6C9VT3D2L">
    </div>
    <button type="submit" class="btn btn-secondary">Tìm phiếu nhập</button>
  </form>
</div>

<?php if ($receipt && $items): ?>
<div class="card" style="max-width:720px;">
  <h2 style="font-size:16px;font-weight:600;margin:0 0 4px;">
    Phiếu nhập gốc: <span style="font-family:monospace;"><?= e($receipt['code']) ?></span>
  </h2>
  <p class="muted" style="margin:0 0 16px;">Nhà cung cấp: <?= e($receipt['supplier_name']) ?></p>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="receipt_id" value="<?= (int) $receipt['id'] ?>">

    <table style="margin-bottom:16px;">
      <thead>
        <tr><th>Sản phẩm</th><th class="text-right">Giá nhập</th><th class="text-right">SL đã nhập</th><th class="text-right">Còn có thể trả</th><th class="text-center">SL trả</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['product_name']) ?><?php if ($it['variant_name']): ?> <span class="muted">(<?= e($it['variant_name']) ?>)</span><?php endif; ?></td>
            <td class="text-right"><?= money($it['cost_price']) ?></td>
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
      <input class="input" name="reason" placeholder="vd: Hàng lỗi, hết hạn sử dụng...">
    </div>

    <button type="submit" class="btn">Tạo trả hàng NCC</button>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
