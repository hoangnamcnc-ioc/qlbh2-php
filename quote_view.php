<?php
/** Xem, in bao gia; doi trang thai; chuyen thanh don giao hang (nap san vao form tao don, khong tao don ngay). */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$id = (int) ($_REQUEST['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM quotes WHERE id = ? AND tenant_id = ?');
$st->execute([$id, $tenantId]);
$qt = $st->fetch();
if (!$qt) {
    redirect('quotes.php');
}
$it = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id');
$it->execute([$id]);
$items = $it->fetchAll();
$tt = ['DRAFT' => ['Nháp', '#475569'], 'SENT' => ['Đã gửi khách', '#1d4ed8'], 'ACCEPTED' => ['Khách đồng ý', '#15803d'], 'REJECTED' => ['Khách từ chối', '#b91c1c']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['SENT', 'ACCEPTED', 'REJECTED', 'DRAFT'], true)) {
        $pdo->prepare('UPDATE quotes SET status = ? WHERE id = ? AND tenant_id = ?')->execute([$action, $id, $tenantId]);
        logActivity('QUOTE_STATUS', $qt['code'] . ' ' . $action);
        redirect('quote_view.php?id=' . $id);
    } elseif ($action === 'convert') {
        // Nap san vao form tao don giao hang de xem lai ton kho, gia truoc khi tao (giong "Sao chep don")
        $_SESSION['order_copy'] = [
            'from_code' => $qt['code'], 'customer_phone' => $qt['customer_phone'] ?? '', 'customer_name' => $qt['customer_name'],
            'address' => $qt['customer_address'] ?? '', 'shipping_fee' => 0, 'note' => 'Theo báo giá ' . $qt['code'],
            'lines' => array_map(static fn($r) => ['id' => (int) $r['product_id'], 'variantId' => $r['variant_id'] !== null ? (int) $r['variant_id'] : null,
                'name' => $r['name'], 'qty' => (float) $r['quantity'], 'price' => (float) $r['unit_price']], $items),
        ];
        $pdo->prepare("UPDATE quotes SET status = 'ACCEPTED' WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
        logActivity('QUOTE_CONVERT', $qt['code']);
        redirect('order_form.php');
    } elseif ($action === 'delete' && $qt['status'] !== 'ACCEPTED') {
        $pdo->prepare('DELETE FROM quotes WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
        logActivity('QUOTE_DELETE', $qt['code']);
        redirect('quotes.php');
    }
}

$store = getSetting('store_name', 'Cửa hàng');
$storePhone = getSetting('store_phone', '');
$storeAddr = getSetting('store_address', '');
$het = in_array($qt['status'], ['DRAFT', 'SENT'], true) && $qt['valid_until'] < date('Y-m-d');
$sub = array_sum(array_map(static fn($r) => (float) $r['line_total'], $items));
[$lb, $mau] = $tt[$qt['status']];

require_once __DIR__ . '/inc_header.php';
?>
<style>
@media print { .no-print, header, nav, .sidebar, .trial-banner { display: none !important; } .content { padding: 0 !important; } .quote-paper { border: none !important; box-shadow: none !important; } }
</style>
<div class="no-print" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
  <a href="quotes.php" class="muted" style="font-size:14px;">← Báo giá</a>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <button class="btn btn-secondary" type="button" onclick="window.print()">In / lưu PDF</button>
    <form method="post" style="display:contents;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="id" value="<?= $id ?>">
      <?php if ($qt['status'] === 'DRAFT'): ?><button class="btn btn-secondary" name="action" value="SENT" type="submit">Đánh dấu đã gửi khách</button><?php endif; ?>
      <?php if (in_array($qt['status'], ['DRAFT', 'SENT'], true)): ?>
        <button class="btn" name="action" value="convert" type="submit">Khách đồng ý, tạo đơn hàng</button>
        <button class="btn btn-secondary" name="action" value="REJECTED" type="submit">Khách từ chối</button>
      <?php elseif ($qt['status'] === 'ACCEPTED'): ?>
        <button class="btn btn-secondary" name="action" value="convert" type="submit">Tạo đơn hàng từ báo giá này</button>
      <?php else: ?>
        <button class="btn btn-secondary" name="action" value="DRAFT" type="submit">Mở lại</button>
      <?php endif; ?>
      <?php if ($qt['status'] !== 'ACCEPTED'): ?><button class="btn btn-secondary" name="action" value="delete" type="submit" onclick="return confirm('Xóa báo giá này?');">Xóa</button><?php endif; ?>
    </form>
  </div>
</div>

<div class="card quote-paper" style="max-width:860px;padding:28px;">
  <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;">
    <div><div style="font-size:18px;font-weight:700;"><?= e($store) ?></div>
      <div class="muted" style="font-size:13px;"><?= e($storeAddr) ?><?= $storePhone ? ' · ' . e($storePhone) : '' ?></div></div>
    <div style="text-align:right;"><div style="font-size:22px;font-weight:700;">BÁO GIÁ</div><div class="muted" style="font-size:13px;">Số <?= e($qt['code']) ?> · <?= e(date('d/m/Y', strtotime($qt['created_at']))) ?></div>
      <div class="no-print" style="font-size:13px;font-weight:600;color:<?= $mau ?>;"><?= e($lb) ?><?= $het ? ' · đã hết hạn' : '' ?></div></div>
  </div>
  <div style="margin:18px 0;font-size:14px;"><b>Kính gửi:</b> <?= e($qt['customer_name']) ?><?= $qt['customer_phone'] ? ' · ' . e($qt['customer_phone']) : '' ?><?= $qt['customer_address'] ? '<br><span class="muted">' . e($qt['customer_address']) . '</span>' : '' ?></div>
  <table>
    <thead><tr><th>#</th><th>Sản phẩm</th><th style="text-align:right;">Số lượng</th><th style="text-align:right;">Đơn giá</th><th style="text-align:right;">Thành tiền</th></tr></thead>
    <tbody>
      <?php foreach ($items as $i => $r): ?>
        <tr><td><?= $i + 1 ?></td><td><?= e($r['name']) ?></td><td style="text-align:right;"><?= rtrim(rtrim(number_format((float) $r['quantity'], 3, ',', '.'), '0'), ',') ?></td><td style="text-align:right;"><?= money($r['unit_price']) ?></td><td style="text-align:right;"><?= money($r['line_total']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="4" style="text-align:right;">Cộng tiền hàng</td><td style="text-align:right;"><?= money($sub) ?></td></tr>
      <?php if ((float) $qt['discount'] > 0): ?><tr><td colspan="4" style="text-align:right;">Giảm giá</td><td style="text-align:right;">− <?= money($qt['discount']) ?></td></tr><?php endif; ?>
      <tr><td colspan="4" style="text-align:right;font-weight:700;">Tổng cộng</td><td style="text-align:right;font-weight:700;font-size:17px;"><?= money($qt['total']) ?></td></tr>
    </tfoot>
  </table>
  <div style="margin-top:16px;font-size:13px;">Báo giá có hiệu lực đến hết ngày <b><?= e(date('d/m/Y', strtotime($qt['valid_until']))) ?></b>.</div>
  <?php if ($qt['note']): ?><div style="margin-top:8px;font-size:13px;white-space:pre-wrap;"><?= e($qt['note']) ?></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
