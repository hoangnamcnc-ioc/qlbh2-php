<?php
/**
 * Cong no phai thu (khach no cua hang) va phai tra (cua hang no nha cung cap) tren 1 trang, co xuat Excel de in/gui ke toan.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$currentUser = currentUser();

// Thu no khach / tra no NCC ngay tren trang nay (cung nghiep vu voi customer_view.php / supplier_view.php:
// khoa dong, tru no, ghi but toan cong no va so quy chi nhanh dang lam viec).
$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['thu', 'tra'], true)) {
    checkCsrf();
    $isThu = $_POST['action'] === 'thu';
    $objId = (int) ($_POST['id'] ?? 0);
    $amount = postFloat('amount');
    $method = in_array($_POST['method'] ?? '', ['CASH', 'BANK_TRANSFER', 'CARD', 'QR_CODE'], true) ? $_POST['method'] : 'CASH';
    $branchId = (int) effectiveBranchId($currentUser);
    $table = $isThu ? 'customers' : 'suppliers';
    if (!$branchId) {
        $msg = ['err', 'Chưa xác định chi nhánh để ghi sổ quỹ — chọn chi nhánh làm việc rồi thử lại.'];
    } else {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare("SELECT name, debt FROM $table WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $lock->execute([$objId, $tenantId]);
            $obj = $lock->fetch();
            if (!$obj || $amount <= 0 || $amount > (float) $obj['debt']) {
                $pdo->rollBack();
                $msg = ['err', 'Số tiền không hợp lệ (phải lớn hơn 0 và không vượt quá số đang nợ).'];
            } else {
                $pdo->prepare("UPDATE $table SET debt = debt - ? WHERE id = ?")->execute([$amount, $objId]);
                if ($isThu) {
                    $pdo->prepare('INSERT INTO customer_debt_entries (customer_id, amount, note, created_by_id) VALUES (?,?,?,?)')
                        ->execute([$objId, -$amount, 'Thu nợ trực tiếp', $currentUser['id']]);
                    recordCashbookEntry($branchId, 'RECEIPT', $amount, 'Thu nợ trực tiếp KH ' . $obj['name'], $method, $currentUser['id']);
                } else {
                    recordCashbookEntry($branchId, 'PAYMENT', $amount, 'Trả nợ NCC ' . $obj['name'], $method === 'BANK_TRANSFER' ? 'BANK_TRANSFER' : 'CASH', $currentUser['id']);
                    logActivity('SUPPLIER_DEBT_PAY', "supplier_id=$objId amount=$amount");
                }
                $pdo->commit();
                $msg = ['ok', ($isThu ? 'Đã ghi nhận thu ' : 'Đã ghi nhận trả ') . money($amount) . ' — ' . $obj['name']];
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = ['err', 'Không ghi nhận được, vui lòng thử lại.'];
        }
    }
}

// Phai thu: khach con no, kem so ngay tu lan ghi no cu nhat con chua tra (xep theo tuoi no).
$stmt = $pdo->prepare(
    "SELECT c.id, c.name, c.phone, c.debt,
            (SELECT MIN(e.created_at) FROM customer_debt_entries e WHERE e.customer_id = c.id AND e.amount > 0) AS dau_tien,
            (SELECT MAX(e.created_at) FROM customer_debt_entries e WHERE e.customer_id = c.id) AS gan_nhat
     FROM customers c WHERE c.tenant_id = ? AND c.debt > 0 ORDER BY c.debt DESC"
);
$stmt->execute([$tenantId]);
$phaiThu = $stmt->fetchAll();

// Phai tra: nha cung cap con no (tong phieu nhap - da tra - hang tra lai NCC), lay theo cot debt da duoc cac phieu cap nhat.
$stmt = $pdo->prepare('SELECT s.id, s.name, s.phone, s.debt FROM suppliers s WHERE s.tenant_id = ? AND s.debt > 0 ORDER BY s.debt DESC');
$stmt->execute([$tenantId]);
$phaiTra = $stmt->fetchAll();

// Phieu nhap con no NCC co ghi han tra (xep theo han, qua han len dau)
$phieuCoHan = layPhieuNhapDenHan($pdo, $tenantId, 0, 3650, 200);

$tongThu = array_sum(array_map(static fn($r) => (float) $r['debt'], $phaiThu));
$tongTra = array_sum(array_map(static fn($r) => (float) $r['debt'], $phaiTra));
$tuoi = static fn(?string $ts): string => $ts ? (string) max(0, (int) floor((time() - strtotime($ts)) / 86400)) : '';

if (isset($_GET['export'])) {
    $rows = [
        ['BÁO CÁO CÔNG NỢ', date('d/m/Y H:i'), '', '', ''],
        ['Lưu ý: số liệu tham khảo từ dữ liệu bạn nhập, không thay thế tư vấn của kế toán/cơ quan thuế.', '', '', '', ''],
        ['Cửa hàng', getSetting('store_name', 'Cửa hàng'), '', '', ''],
        ['', '', '', '', ''],
        ['PHẢI THU TỪ KHÁCH HÀNG', '', '', '', ''],
        ['Khách hàng', 'Số điện thoại', 'Còn nợ', 'Nợ từ ngày', 'Số ngày nợ'],
    ];
    foreach ($phaiThu as $r) {
        $rows[] = [$r['name'], (string) $r['phone'], (string) round((float) $r['debt']),
            $r['dau_tien'] ? date('d/m/Y', strtotime($r['dau_tien'])) : '', $tuoi($r['dau_tien'])];
    }
    $rows[] = ['Tổng phải thu', '', (string) round($tongThu), '', ''];
    $rows[] = ['', '', '', '', ''];
    $rows[] = ['PHẢI TRẢ NHÀ CUNG CẤP', '', '', '', ''];
    $rows[] = ['Nhà cung cấp', 'Số điện thoại', 'Còn nợ', '', ''];
    foreach ($phaiTra as $r) {
        $rows[] = [$r['name'], (string) $r['phone'], (string) round((float) $r['debt']), '', ''];
    }
    $rows[] = ['Tổng phải trả', '', (string) round($tongTra), '', ''];
    $rows[] = ['', '', '', '', ''];
    $rows[] = ['Chênh lệch (phải thu - phải trả)', '', (string) round($tongThu - $tongTra), '', ''];
    logActivity('EXPORT_DEBT_REPORT', 'thu=' . count($phaiThu) . ' tra=' . count($phaiTra));
    downloadXlsx($rows, 'cong_no_' . date('Ymd') . '.xlsx');
    exit;
}

require_once __DIR__ . '/inc_header.php';
?>

<?= thanhTabNghiepVu('no') ?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">Công nợ phải thu / phải trả</h1>
  <a class="btn btn-secondary" href="?export=1">⬇ Xuất Excel</a>
</div>
<p class="muted" style="font-size:13px;margin:0 0 16px;max-width:760px;">
  Tổng hợp số tiền khách còn nợ cửa hàng và số tiền cửa hàng còn nợ nhà cung cấp tại thời điểm hiện tại.
  Thu nợ khách hoặc trả nợ nhà cung cấp ngay ở từng dòng bên dưới (sẽ ghi vào sổ quỹ chi nhánh đang làm việc); muốn xem chi tiết từng đơn thì bấm tên khách / nhà cung cấp.
</p>

<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
  <div class="card" style="min-width:200px;"><div class="muted" style="font-size:12px;">Phải thu từ khách</div><div style="font-size:22px;font-weight:700;color:#059669;"><?= money($tongThu) ?></div><div class="muted" style="font-size:12px;"><?= count($phaiThu) ?> khách đang nợ</div></div>
  <div class="card" style="min-width:200px;"><div class="muted" style="font-size:12px;">Phải trả nhà cung cấp</div><div style="font-size:22px;font-weight:700;color:#dc2626;"><?= money($tongTra) ?></div><div class="muted" style="font-size:12px;"><?= count($phaiTra) ?> nhà cung cấp</div></div>
  <div class="card" style="min-width:200px;"><div class="muted" style="font-size:12px;">Chênh lệch</div><div style="font-size:22px;font-weight:700;"><?= money($tongThu - $tongTra) ?></div><div class="muted" style="font-size:12px;">phải thu − phải trả</div></div>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg[0] === 'ok' ? 'success' : 'error' ?>"><?= e($msg[1]) ?></div><?php endif; ?>
<h2 style="font-size:16px;margin:0 0 8px;">Khách hàng còn nợ</h2>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:24px;">
  <table>
    <thead><tr><th>Khách hàng</th><th>SĐT</th><th class="text-right">Còn nợ</th><th>Nợ từ</th><th class="text-right">Số ngày</th><th>Thu nợ</th></tr></thead>
    <tbody>
      <?php if (!$phaiThu): ?><tr><td colspan="6" class="text-center muted" style="padding:20px;">Không có khách nào đang nợ.</td></tr><?php endif; ?>
      <?php foreach ($phaiThu as $r): ?>
        <?php $ngay = (int) $tuoi($r['dau_tien']); ?>
        <tr>
          <td><a href="customer_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
          <td><?= e((string) $r['phone']) ?></td>
          <td class="text-right"><b><?= money($r['debt']) ?></b></td>
          <td class="muted"><?= $r['dau_tien'] ? e(date('d/m/Y', strtotime($r['dau_tien']))) : '—' ?></td>
          <td class="text-right" style="<?= $ngay >= 30 ? 'color:#dc2626;font-weight:600;' : '' ?>"><?= $r['dau_tien'] ? $ngay : '—' ?></td>
          <td>
            <form method="post" style="display:flex;gap:4px;align-items:center;" onsubmit="return confirm('Ghi nhận thu nợ khách này?');">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="thu"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="number" name="amount" min="1" max="<?= (float) $r['debt'] ?>" value="<?= (float) $r['debt'] ?>" required style="width:110px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px;">
              <select name="method" style="padding:4px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;width:auto;"><option value="CASH">Tiền mặt</option><option value="BANK_TRANSFER">Chuyển khoản</option></select>
              <button type="submit" class="btn" style="padding:4px 10px;font-size:13px;">Thu</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2 style="font-size:16px;margin:0 0 8px;">Nhà cung cấp cửa hàng còn nợ</h2>
<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Nhà cung cấp</th><th>SĐT</th><th class="text-right">Còn nợ</th><th>Trả nợ</th></tr></thead>
    <tbody>
      <?php if (!$phaiTra): ?><tr><td colspan="4" class="text-center muted" style="padding:20px;">Không còn nợ nhà cung cấp nào.</td></tr><?php endif; ?>
      <?php foreach ($phaiTra as $r): ?>
        <tr>
          <td><a href="supplier_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
          <td><?= e((string) $r['phone']) ?></td>
          <td class="text-right"><b><?= money($r['debt']) ?></b></td>
          <td>
            <form method="post" style="display:flex;gap:4px;align-items:center;" onsubmit="return confirm('Ghi nhận trả nợ nhà cung cấp này?');">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="tra"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="number" name="amount" min="1" max="<?= (float) $r['debt'] ?>" value="<?= (float) $r['debt'] ?>" required style="width:110px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px;">
              <select name="method" style="padding:4px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;width:auto;"><option value="CASH">Tiền mặt</option><option value="BANK_TRANSFER">Chuyển khoản</option></select>
              <button type="submit" class="btn" style="padding:4px 10px;font-size:13px;">Trả</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2 style="font-size:16px;margin:24px 0 8px;">Hạn trả tiền nhà cung cấp</h2>
<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Nhà cung cấp</th><th>Phiếu nhập</th><th>Hạn trả</th><th class="text-right">Còn nợ</th><th>Tình trạng</th></tr></thead>
    <tbody>
      <?php if (!$phieuCoHan): ?><tr><td colspan="5" class="text-center muted" style="padding:20px;">Chưa có phiếu nhập nào còn nợ kèm hạn trả. Khi lập phiếu nhập có nợ, nhập "Hạn trả tiền NCC" để được nhắc.</td></tr><?php endif; ?>
      <?php foreach ($phieuCoHan as $r):
        $conNgay = (int) floor((strtotime($r['due_date']) - strtotime('today')) / 86400); ?>
        <tr>
          <td><?= $r['supplier_id'] ? '<a href="supplier_view.php?id=' . (int) $r['supplier_id'] . '">' . e($r['supplier_name']) . '</a>' : '—' ?></td>
          <td><a href="stock_receipt_view.php?id=<?= (int) $r['id'] ?>" style="font-family:monospace;"><?= e($r['code']) ?></a></td>
          <td><?= e(date('d/m/Y', strtotime($r['due_date']))) ?></td>
          <td class="text-right"><b><?= money($r['con_no']) ?></b></td>
          <td><?php if ($conNgay < 0): ?><span class="badge badge-red">Quá hạn <?= -$conNgay ?> ngày</span>
              <?php elseif ($conNgay <= 7): ?><span class="badge badge-red">Còn <?= $conNgay ?> ngày</span>
              <?php else: ?><span class="muted">Còn <?= $conNgay ?> ngày</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p class="muted" style="font-size:12px;margin:14px 0 0;max-width:820px;">⚖️ Số liệu trên trang này được tính từ dữ liệu bạn nhập theo cách tính mặc định của phần mềm, chỉ mang tính <b>tham khảo</b> và không thay thế tư vấn của kế toán/cơ quan thuế. Xem <a href="dieu_khoan.php" target="_blank" rel="noopener">Điều khoản sử dụng</a>.</p>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
