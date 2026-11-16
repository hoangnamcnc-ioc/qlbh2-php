<?php
/**
 * So hoa don: ghi nhan hoa don ban ra va mua vao (so hieu, ky hieu, ngay, doi tac, tien, VAT), gan voi don hang, xuat Excel,
 * liet ke don hang chua co hoa don. Day la so THEO DOI; viec phat hanh hoa don dien tu phai lam qua nha cung cap hoa don duoc co quan thue chap nhan.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$tab = in_array($_GET['tab'] ?? '', ['purchase', 'missing'], true) ? $_GET['tab'] : 'sale';
$nam = preg_match('/^\d{4}$/', $_GET['nam'] ?? '') ? $_GET['nam'] : date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $dir = ($_POST['direction'] ?? '') === 'PURCHASE' ? 'PURCHASE' : 'SALE';
        $symbol = strtoupper(trim(mb_substr(post('symbol'), 0, 20)));
        $number = preg_replace('/\D/', '', (string) ($_POST['number'] ?? ''));
        $date = (string) ($_POST['issue_date'] ?? '');
        $partner = mb_substr(post('partner_name'), 0, 255);
        $mst = preg_replace('/[^0-9\-]/', '', (string) ($_POST['partner_tax_code'] ?? ''));
        $before = max(0.0, postFloat('amount_before_tax'));
        $rate = (float) ($_POST['vat_rate'] ?? 0);
        $rate = in_array($rate, [0.0, 5.0, 8.0, 10.0], true) ? $rate : 0.0;
        $vat = isset($_POST['vat_amount']) && $_POST['vat_amount'] !== '' ? max(0.0, postFloat('vat_amount')) : round($before * $rate / 100);
        $orderCode = trim((string) ($_POST['order_code'] ?? ''));
        $orderId = null;
        if ($orderCode !== '' && $dir === 'SALE') {
            $o = $pdo->prepare('SELECT o.id FROM orders o JOIN branches b ON b.id = o.branch_id WHERE o.code = ? AND b.tenant_id = ?');
            $o->execute([$orderCode, $tenantId]);
            $orderId = $o->fetchColumn() ?: null;
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM invoice_register WHERE tenant_id = ? AND direction = ? AND symbol = ? AND number = ? AND (? = \'SALE\' OR partner_tax_code = ?)');
        $dup->execute([$tenantId, $dir, $symbol, $number, $dir, $mst]);
        if ($symbol === '' || $number === '') {
            $error = 'Nhập ký hiệu và số hóa đơn.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
            $error = 'Ngày hóa đơn không hợp lệ.';
        } elseif ($partner === '') {
            $error = $dir === 'SALE' ? 'Nhập tên người mua.' : 'Nhập tên người bán.';
        } elseif ($dir === 'PURCHASE' && !in_array(strlen(str_replace('-', '', $mst)), [10, 13], true)) {
            $error = 'Hóa đơn mua vào cần mã số thuế người bán (10 hoặc 13 số).';
        } elseif ($before <= 0) {
            $error = 'Nhập tiền hàng trước thuế.';
        } elseif ((int) $dup->fetchColumn() > 0) {
            $error = 'Hóa đơn ký hiệu ' . $symbol . ' số ' . $number . ' đã có trong sổ.';
        } elseif (kyDaKhoa($date)) {
            $error = 'Ngày hóa đơn thuộc kỳ đã khóa sổ.';
        } else {
            $pdo->prepare('INSERT INTO invoice_register (tenant_id, direction, symbol, number, issue_date, partner_name, partner_tax_code, amount_before_tax, vat_rate, vat_amount, total, order_id, note, created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tenantId, $dir, $symbol, $number, $date, $partner, $mst ?: null, $before, $rate, $vat, $before + $vat, $orderId, mb_substr(post('note'), 0, 255) ?: null, $currentUser['id']]);
            logActivity('INVOICE_ADD', "$dir $symbol-$number");
            redirect('hoa_don.php?tab=' . strtolower($dir) . '&nam=' . substr($date, 0, 4) . '&ok=1');
        }
        $tab = $dir === 'PURCHASE' ? 'purchase' : 'sale';
    } elseif ($action === 'void' || $action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT issue_date FROM invoice_register WHERE id = ? AND tenant_id = ?');
        $st->execute([$id, $tenantId]);
        $d = $st->fetchColumn();
        if ($d && kyDaKhoa((string) $d)) {
            $error = 'Hóa đơn thuộc kỳ đã khóa sổ.';
        } elseif ($d && $action === 'void') {
            $pdo->prepare("UPDATE invoice_register SET status = 'VOID' WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
            logActivity('INVOICE_VOID', (string) $id);
            redirect('hoa_don.php?tab=' . $tab . '&nam=' . $nam . '&ok=1');
        } elseif ($d) {
            $pdo->prepare('DELETE FROM invoice_register WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
            redirect('hoa_don.php?tab=' . $tab . '&nam=' . $nam . '&ok=1');
        }
    }
}

$dirSel = $tab === 'purchase' ? 'PURCHASE' : 'SALE';
$rows = [];
if ($tab !== 'missing') {
    $st = $pdo->prepare('SELECT r.*, o.code AS order_code FROM invoice_register r LEFT JOIN orders o ON o.id = r.order_id WHERE r.tenant_id = ? AND r.direction = ? AND YEAR(r.issue_date) = ? ORDER BY r.issue_date DESC, r.id DESC');
    $st->execute([$tenantId, $dirSel, $nam]);
    $rows = $st->fetchAll();
}
$sum = ['before' => 0.0, 'vat' => 0.0, 'total' => 0.0];
foreach ($rows as $r) {
    if ($r['status'] === 'VOID') continue;
    $sum['before'] += (float) $r['amount_before_tax'];
    $sum['vat'] += (float) $r['vat_amount'];
    $sum['total'] += (float) $r['total'];
}

$missing = [];
if ($tab === 'missing') {
    $st = $pdo->prepare(
        "SELECT o.id, o.code, o.created_at, o.total_amount, c.name AS customer
         FROM orders o JOIN branches b ON b.id = o.branch_id LEFT JOIN customers c ON c.id = o.customer_id
         WHERE b.tenant_id = ? AND YEAR(o.created_at) = ? AND o.status = 'COMPLETED' AND o.total_amount > 0
           AND NOT EXISTS (SELECT 1 FROM invoice_register r WHERE r.order_id = o.id AND r.status = 'ISSUED')
         ORDER BY o.created_at DESC LIMIT 300"
    );
    $st->execute([$tenantId, $nam]);
    $missing = $st->fetchAll();
}

if (isset($_GET['export']) && $tab !== 'missing') {
    $x = [['SỔ HÓA ĐƠN ' . ($dirSel === 'SALE' ? 'BÁN RA' : 'MUA VÀO') . ' NĂM ' . $nam, '', '', '', '', '', '', '', ''],
        ['Ngày', 'Ký hiệu', 'Số', $dirSel === 'SALE' ? 'Người mua' : 'Người bán', 'MST', 'Tiền hàng', 'Thuế GTGT', 'Tổng tiền', 'Trạng thái']];
    foreach (array_reverse($rows) as $r) {
        $x[] = [date('d/m/Y', strtotime($r['issue_date'])), $r['symbol'], $r['number'], $r['partner_name'], $r['partner_tax_code'] ?? '', (string) round((float) $r['amount_before_tax']), (string) round((float) $r['vat_amount']), (string) round((float) $r['total']), $r['status'] === 'VOID' ? 'Đã hủy' : 'Hợp lệ'];
    }
    $x[] = ['', '', '', '', 'Cộng (không tính hóa đơn hủy)', (string) round($sum['before']), (string) round($sum['vat']), (string) round($sum['total']), ''];
    logActivity('EXPORT_INVOICES', "$dirSel $nam");
    downloadXlsx($x, 'so_hoa_don_' . strtolower($dirSel) . '_' . $nam . '.xlsx');
    exit;
}

require_once __DIR__ . '/inc_header.php';
echo thanhTabNghiepVu('ketoan');
$tabs = ['sale' => 'Hóa đơn bán ra', 'purchase' => 'Hóa đơn mua vào', 'missing' => 'Đơn chưa có hóa đơn'];
?>
<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Sổ hóa đơn</h1>
<p class="muted" style="margin:0 0 14px;max-width:780px;font-size:14px;">
  Ghi nhận hóa đơn bán ra và mua vào để đối chiếu với đơn hàng, sổ sách và tờ khai. Đây là <b>sổ theo dõi</b>: phần mềm chưa phát hành hóa đơn điện tử.
  Hộ kinh doanh trên 1 tỷ đồng/năm phải dùng hóa đơn điện tử có mã của cơ quan thuế, lập qua nhà cung cấp hóa đơn (Viettel, VNPT, MISA...) rồi ghi số hóa đơn vào đây.
</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Đã lưu.</div><?php endif; ?>

<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;align-items:center;">
  <?php foreach ($tabs as $k => $v): ?><a href="hoa_don.php?tab=<?= e($k) ?>&amp;nam=<?= e($nam) ?>" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;<?= $k === $tab ? 'background:var(--brand,#2563eb);color:#fff;font-weight:600;' : 'background:#f1f5f9;color:#334155;' ?>"><?= e($v) ?></a><?php endforeach; ?>
  <form method="get" style="margin:0 0 0 auto;display:flex;gap:6px;align-items:center;"><input type="hidden" name="tab" value="<?= e($tab) ?>"><label style="margin:0;">Năm</label><input type="number" name="nam" value="<?= e($nam) ?>" class="input" style="width:90px;"><button class="btn btn-secondary" type="submit">Xem</button></form>
</div>

<?php if ($tab === 'missing'): ?>
<div class="card" style="overflow-x:auto;">
  <p class="muted" style="font-size:13px;margin:0 0 8px;">Đơn đã hoàn thành trong năm <?= e($nam) ?> chưa gắn với hóa đơn bán ra nào. Khi lập hóa đơn cho đơn, ghi vào sổ kèm mã đơn để đơn rời danh sách này.</p>
  <table>
    <thead><tr><th>Mã đơn</th><th>Ngày</th><th>Khách hàng</th><th style="text-align:right;">Giá trị</th><th></th></tr></thead>
    <tbody>
    <?php if (!$missing): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:24px;">Mọi đơn hoàn thành đều đã có hóa đơn.</td></tr><?php endif; ?>
    <?php foreach ($missing as $m): ?>
      <tr><td><a href="order_view.php?id=<?= (int) $m['id'] ?>"><?= e($m['code']) ?></a></td><td><?= e(date('d/m/Y', strtotime($m['created_at']))) ?></td><td><?= e($m['customer'] ?? 'Khách lẻ') ?></td><td style="text-align:right;"><?= money($m['total_amount']) ?></td>
        <td><a class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" href="hoa_don.php?tab=sale&amp;order=<?= e($m['code']) ?>#them">Ghi hóa đơn</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="card" id="them" style="margin-bottom:16px;">
  <div style="font-weight:600;margin-bottom:10px;">Ghi hóa đơn <?= $tab === 'sale' ? 'bán ra' : 'mua vào' ?></div>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="direction" value="<?= e($dirSel) ?>">
    <div><label>Ký hiệu</label><input class="input" name="symbol" placeholder="C26TAA" required></div>
    <div><label>Số hóa đơn</label><input class="input" name="number" placeholder="00000123" required></div>
    <div><label>Ngày hóa đơn</label><input class="input" type="date" name="issue_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
    <div><label><?= $tab === 'sale' ? 'Người mua' : 'Người bán' ?></label><input class="input" name="partner_name" required></div>
    <div><label>Mã số thuế<?= $tab === 'purchase' ? ' (bắt buộc)' : '' ?></label><input class="input" name="partner_tax_code" <?= $tab === 'purchase' ? 'required' : '' ?>></div>
    <div><label>Tiền hàng trước thuế</label><input class="input" type="number" min="1" step="1" name="amount_before_tax" required></div>
    <div><label>Thuế suất GTGT</label><select name="vat_rate"><option value="0">0% / không chịu thuế</option><option value="5">5%</option><option value="8">8%</option><option value="10">10%</option></select></div>
    <div><label>Tiền thuế (để trống = tự tính)</label><input class="input" type="number" min="0" step="1" name="vat_amount"></div>
    <?php if ($tab === 'sale'): ?><div><label>Mã đơn hàng (nếu có)</label><input class="input" name="order_code" value="<?= e((string) ($_GET['order'] ?? '')) ?>" placeholder="Mã đơn trong hệ thống"></div><?php endif; ?>
    <div><label>Ghi chú</label><input class="input" name="note"></div>
    <div><button class="btn" type="submit">Ghi sổ</button></div>
  </form>
</div>
<div class="card" style="overflow-x:auto;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:10px;flex-wrap:wrap;">
    <b>Năm <?= e($nam) ?>: <?= money($sum['before']) ?> tiền hàng · <?= money($sum['vat']) ?> thuế · <?= money($sum['total']) ?> tổng</b>
    <a class="btn btn-secondary" href="hoa_don.php?tab=<?= e($tab) ?>&amp;nam=<?= e($nam) ?>&amp;export=1">Xuất Excel</a>
  </div>
  <table>
    <thead><tr><th>Ngày</th><th>Ký hiệu / số</th><th><?= $tab === 'sale' ? 'Người mua' : 'Người bán' ?></th><th style="text-align:right;">Tiền hàng</th><th style="text-align:right;">Thuế</th><th style="text-align:right;">Tổng</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:24px;">Năm <?= e($nam) ?> chưa có hóa đơn nào.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $void = $r['status'] === 'VOID'; ?>
      <tr style="<?= $void ? 'opacity:.5;text-decoration:line-through;' : '' ?>">
        <td><?= e(date('d/m/Y', strtotime($r['issue_date']))) ?></td>
        <td><?= e($r['symbol']) ?> / <?= e($r['number']) ?><?php if ($r['order_code']): ?><div class="muted" style="font-size:12px;">đơn <?= e($r['order_code']) ?></div><?php endif; ?></td>
        <td><?= e($r['partner_name']) ?><div class="muted" style="font-size:12px;"><?= e($r['partner_tax_code'] ?? '') ?></div></td>
        <td style="text-align:right;"><?= money($r['amount_before_tax']) ?></td><td style="text-align:right;"><?= money($r['vat_amount']) ?></td><td style="text-align:right;font-weight:600;"><?= money($r['total']) ?></td>
        <td style="white-space:nowrap;"><?php if (!$void): ?>
          <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Đánh dấu hóa đơn này đã hủy?');"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Hủy</button></form>
        <?php else: ?><span class="muted" style="font-size:12px;text-decoration:none;">Đã hủy</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
