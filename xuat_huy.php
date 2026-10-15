<?php
/**
 * Xuat huy hang (het han, hu hong, mat...): tru ton kho (va lo hang neu co) KEM LY DO, de biet
 * hao hut vi dau thay vi chinh ton bang kiem hang roi khong ai biet. Bao cao tong gia tri hao hut theo ly do.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;

$branchesStmt = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name');
$branchesStmt->execute([$tenantId]);
$branches = $branchesStmt->fetchAll();
// MANAGER chi xuat huy o chi nhanh minh quan ly (giong Kiem hang)
$lockedBranchId = hasRole('ADMIN') ? 0 : effectiveBranchId($currentUser);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $branchId = (int) ($_POST['branch_id'] ?? 0);
    if ($lockedBranchId) {
        $branchId = $lockedBranchId;
    }
    if (!in_array($branchId, array_map('intval', array_column($branches, 'id')), true)) {
        $branchId = 0;
    }
    [$pid, $vid] = array_pad(array_map('intval', explode(':', (string) ($_POST['item'] ?? '0:0'))), 2, 0);
    $vid = $vid ?: null;
    $qty = round(postQty('quantity'), 3);
    $reason = array_key_exists($_POST['reason'] ?? '', LY_DO_XUAT_HUY) ? $_POST['reason'] : 'OTHER';
    $note = mb_substr(post('note'), 0, 255) ?: null;
    $batchId = (int) ($_POST['batch_id'] ?? 0) ?: null;

    $product = $pid ? laySanPhamCuaToi($pid) : null;
    if (!$branchId) {
        $error = 'Vui lòng chọn chi nhánh';
    } elseif (!$product) {
        $error = 'Vui lòng chọn sản phẩm';
    } elseif ($qty <= 0) {
        $error = 'Số lượng xuất hủy phải lớn hơn 0';
    } else {
        $cost = (float) $product['cost_price'];
        if ($vid) {
            $v = $pdo->prepare('SELECT cost_price FROM product_variants WHERE id = ? AND product_id = ? AND tenant_id = ?');
            $v->execute([$vid, $pid, $tenantId]);
            $vRow = $v->fetch();
            if (!$vRow) {
                $error = 'Biến thể không hợp lệ';
            } else {
                $cost = (float) $vRow['cost_price'];
            }
        }
        if (!$error) {
            $pdo->beginTransaction();
            try {
                if ($vid) {
                    $inv = $pdo->prepare('SELECT quantity FROM inventory WHERE branch_id = ? AND variant_id = ? FOR UPDATE');
                    $inv->execute([$branchId, $vid]);
                } else {
                    $inv = $pdo->prepare('SELECT quantity FROM inventory WHERE branch_id = ? AND product_id = ? AND variant_id IS NULL FOR UPDATE');
                    $inv->execute([$branchId, $pid]);
                }
                $onHand = (float) ($inv->fetchColumn() ?: 0);
                if ($qty > $onHand + 0.0005) {
                    throw new RuntimeException('Số lượng xuất hủy (' . fmtQty($qty) . ') vượt tồn kho hiện có (' . fmtQty($onHand) . ')');
                }
                if ($batchId) {
                    // Lo duoc chon phai cung san pham + cung chi nhanh + con du so luong
                    $b = $pdo->prepare(
                        'SELECT b.id, b.quantity FROM product_batches b JOIN branches br ON br.id = b.branch_id
                         WHERE b.id = ? AND b.product_id = ? AND b.branch_id = ? AND br.tenant_id = ? FOR UPDATE'
                    );
                    $b->execute([$batchId, $pid, $branchId, $tenantId]);
                    $batch = $b->fetch();
                    if (!$batch) {
                        throw new RuntimeException('Lô hàng không hợp lệ cho sản phẩm/chi nhánh này');
                    }
                    if ($qty > (float) $batch['quantity'] + 0.0005) {
                        throw new RuntimeException('Số lượng vượt số còn lại của lô (' . fmtQty($batch['quantity']) . ')');
                    }
                    $pdo->prepare('UPDATE product_batches SET quantity = GREATEST(0, quantity - ?) WHERE id = ?')->execute([$qty, $batchId]);
                } elseif (!$vid) {
                    // Khong chon lo: tru FEFO neu san pham co khai bao lo, giu so phu khop ton kho
                    truLoHangFEFO($pdo, $pid, $branchId, $qty);
                }
                capNhatTonKho($pdo, $branchId, $pid, $vid, -$qty);
                $code = genCode('XH');
                $pdo->prepare(
                    'INSERT INTO stock_write_offs (tenant_id, code, branch_id, product_id, variant_id, batch_id, quantity, cost_price, reason, note, created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$tenantId, $code, $branchId, $pid, $vid, $batchId, $qty, $cost, $reason, $note, $currentUser['id']]);
                $pdo->commit();
                logActivity('STOCK_WRITE_OFF', "$code product_id=$pid qty=$qty reason=$reason");
                redirect('xuat_huy.php?done=' . urlencode($code));
            } catch (RuntimeException $ex) {
                $pdo->rollBack();
                $error = $ex->getMessage();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $error = 'Không thể xuất hủy, vui lòng thử lại';
            }
        }
    }
}

// Danh sach chon: san pham khong bien the + tung bien the
$itemsStmt = $pdo->prepare(
    "SELECT CONCAT(p.id, ':0') AS k, p.name AS label, p.sku FROM products p
     WHERE p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT'
       AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1)
     UNION ALL
     SELECT CONCAT(p.id, ':', v.id), CONCAT(p.name, ' - ', v.name), v.sku FROM product_variants v JOIN products p ON p.id = v.product_id
     WHERE v.tenant_id = ? AND v.is_active = 1 AND p.product_type = 'PRODUCT'
     ORDER BY label"
);
$itemsStmt->execute([$tenantId, $tenantId]);
$items = $itemsStmt->fetchAll();

$batchRows = $pdo->prepare(
    "SELECT b.id, b.product_id, b.branch_id, b.lot_number, b.expiry_date, b.quantity
     FROM product_batches b JOIN branches br ON br.id = b.branch_id
     WHERE b.quantity > 0 AND br.tenant_id = ? ORDER BY (b.expiry_date IS NULL), b.expiry_date"
);
$batchRows->execute([$tenantId]);
$allBatches = $batchRows->fetchAll();

// Gia tri nhap san tu lo (nut "Xuat huy" o trang Lo hang)
$pre = ['item' => '', 'batch' => 0, 'qty' => '', 'reason' => 'OTHER', 'branch' => $lockedBranchId ?: (int) ($currentUser['branch_id'] ?? 0)];
if (!empty($_GET['batch_id'])) {
    foreach ($allBatches as $b) {
        if ((int) $b['id'] === (int) $_GET['batch_id']) {
            $pre['item'] = $b['product_id'] . ':0';
            $pre['batch'] = (int) $b['id'];
            $pre['qty'] = fmtQty($b['quantity']);
            $pre['branch'] = (int) $b['branch_id'];
            $pre['reason'] = $b['expiry_date'] && strtotime($b['expiry_date']) < strtotime('today') ? 'EXPIRED' : 'OTHER';
        }
    }
}

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$listStmt = $pdo->prepare(
    "SELECT w.*, p.name AS product_name, v.name AS variant_name, br.name AS branch_name, u.name AS user_name, b.lot_number
     FROM stock_write_offs w
     JOIN products p ON p.id = w.product_id
     LEFT JOIN product_variants v ON v.id = w.variant_id
     JOIN branches br ON br.id = w.branch_id
     LEFT JOIN users u ON u.id = w.created_by_id
     LEFT JOIN product_batches b ON b.id = w.batch_id
     WHERE w.tenant_id = ? AND w.created_at BETWEEN ? AND ?
     ORDER BY w.created_at DESC LIMIT 300"
);
$listStmt->execute([$tenantId, $from . ' 00:00:00', $to . ' 23:59:59']);
$list = $listStmt->fetchAll();
$byReason = [];
foreach ($list as $w) {
    $byReason[$w['reason']] = ($byReason[$w['reason']] ?? 0) + (float) $w['quantity'] * (float) $w['cost_price'];
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="batches.php" class="muted" style="font-size:14px;">← Lô hàng & Hạn sử dụng</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Xuất hủy hàng</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:760px;">
  Dùng khi hàng hết hạn, hư hỏng, vỡ, mất... Hệ thống trừ tồn kho (và lô hàng nếu có) và ghi lại <b>lý do + giá trị vốn</b>,
  để cuối kỳ biết hao hụt vì đâu. Không dùng để sửa số liệu kiểm kho sai.
</p>

<?php if (isset($_GET['done'])): ?><div class="alert alert-success">Đã xuất hủy, mã phiếu <?= e($_GET['done']) ?></div><?php endif; ?>

<div class="card" style="max-width:680px;margin-bottom:24px;">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" id="wo-form">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <?php if (!$lockedBranchId && count($branches) > 1): ?>
      <div class="field"><label>Chi nhánh *</label>
        <select class="input" name="branch_id" id="wo-branch">
          <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $pre['branch'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
        </select></div>
    <?php else: ?>
      <input type="hidden" name="branch_id" id="wo-branch" value="<?= (int) ($lockedBranchId ?: ($branches[0]['id'] ?? 0)) ?>">
    <?php endif; ?>
    <div class="field"><label>Sản phẩm *</label>
      <select class="input" name="item" id="wo-item" required>
        <option value="">— Chọn sản phẩm —</option>
        <?php foreach ($items as $it): ?>
          <option value="<?= e($it['k']) ?>" <?= $it['k'] === $pre['item'] ? 'selected' : '' ?>><?= e($it['label']) ?> (<?= e($it['sku']) ?>)</option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label>Lô hàng (nếu có)</label>
      <select class="input" name="batch_id" id="wo-batch"><option value="">— Không chọn lô (tự trừ lô hết hạn sớm nhất) —</option></select></div>
    <div class="grid-2">
      <div class="field"><label>Số lượng *</label><input class="input" type="number" min="0.001" step="0.001" name="quantity" id="wo-qty" required value="<?= e($pre['qty']) ?>"></div>
      <div class="field"><label>Lý do *</label>
        <select class="input" name="reason">
          <?php foreach (LY_DO_XUAT_HUY as $k => $label): ?><option value="<?= e($k) ?>" <?= $k === $pre['reason'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="field"><label>Ghi chú</label><input class="input" name="note" maxlength="255" placeholder="vd: tủ mát hỏng, vỡ khi vận chuyển..."></div>
    <button type="submit" class="btn btn-danger" onclick="return confirm('Xác nhận xuất hủy? Tồn kho sẽ bị trừ ngay.');">Xuất hủy</button>
  </form>
</div>

<form style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
  <div><label style="display:block;font-size:12px;margin-bottom:2px;">Từ ngày</label><input type="date" name="from" value="<?= e($from) ?>" class="input" style="width:auto;"></div>
  <div><label style="display:block;font-size:12px;margin-bottom:2px;">Đến ngày</label><input type="date" name="to" value="<?= e($to) ?>" class="input" style="width:auto;"></div>
  <button class="btn btn-secondary" type="submit">Xem</button>
</form>

<?php if ($byReason): ?>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
    <?php foreach ($byReason as $k => $val): ?>
      <div class="card" style="min-width:170px;"><div class="muted" style="font-size:12px;"><?= e(LY_DO_XUAT_HUY[$k] ?? $k) ?></div><div style="font-size:18px;font-weight:700;color:#dc2626;"><?= money($val) ?></div></div>
    <?php endforeach; ?>
    <div class="card" style="min-width:170px;"><div class="muted" style="font-size:12px;">Tổng giá trị hao hụt (theo giá vốn)</div><div style="font-size:18px;font-weight:700;"><?= money(array_sum($byReason)) ?></div></div>
  </div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Mã</th><th>Thời gian</th><th>Sản phẩm</th><th>Chi nhánh</th><th class="text-right">SL</th><th class="text-right">Giá trị vốn</th><th>Lý do</th><th>Người lập</th></tr></thead>
    <tbody>
      <?php if (!$list): ?><tr><td colspan="8" class="text-center muted" style="padding:24px;">Chưa có phiếu xuất hủy trong khoảng này.</td></tr><?php endif; ?>
      <?php foreach ($list as $w): ?>
        <tr>
          <td style="font-family:monospace;"><?= e($w['code']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($w['created_at'])) ?></td>
          <td><?= e($w['product_name']) ?><?= $w['variant_name'] ? ' - ' . e($w['variant_name']) : '' ?><?= $w['lot_number'] ? ' <span class="muted" style="font-size:12px;">(lô ' . e($w['lot_number']) . ')</span>' : '' ?><?= $w['note'] ? '<br><span class="muted" style="font-size:12px;">' . e($w['note']) . '</span>' : '' ?></td>
          <td class="muted"><?= e($w['branch_name']) ?></td>
          <td class="text-right"><?= fmtQty($w['quantity']) ?></td>
          <td class="text-right"><?= money((float) $w['quantity'] * (float) $w['cost_price']) ?></td>
          <td><?= e(LY_DO_XUAT_HUY[$w['reason']] ?? $w['reason']) ?></td>
          <td class="muted"><?= e($w['user_name'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
const BATCHES = <?= json_encode(array_map(fn($b) => [
    'id' => (int) $b['id'], 'pid' => (int) $b['product_id'], 'bid' => (int) $b['branch_id'], 'lot' => $b['lot_number'],
    'exp' => $b['expiry_date'] ? date('d/m/Y', strtotime($b['expiry_date'])) : '', 'qty' => (float) $b['quantity'],
], $allBatches), JSON_UNESCAPED_UNICODE) ?>;
const PRESET_BATCH = <?= (int) $pre['batch'] ?>;
function fillBatches() {
  const item = document.getElementById('wo-item').value.split(':');
  const pid = parseInt(item[0], 10) || 0;
  const bid = parseInt(document.getElementById('wo-branch').value, 10) || 0;
  const sel = document.getElementById('wo-batch');
  const keep = sel.value;
  sel.innerHTML = '<option value="">— Không chọn lô (tự trừ lô hết hạn sớm nhất) —</option>' +
    BATCHES.filter(b => b.pid === pid && b.bid === bid).map(b =>
      `<option value="${b.id}">Lô ${b.lot.replace(/[<>&"]/g, '')}${b.exp ? ' · HSD ' + b.exp : ''} · còn ${b.qty}</option>`).join('');
  if (keep) sel.value = keep;
}
document.getElementById('wo-item').addEventListener('change', fillBatches);
document.getElementById('wo-branch').addEventListener('change', fillBatches);
fillBatches();
if (PRESET_BATCH) document.getElementById('wo-batch').value = String(PRESET_BATCH);
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
