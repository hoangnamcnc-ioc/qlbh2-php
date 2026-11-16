<?php
/**
 * Chi phi kinh doanh: phan loai cac khoan chi trong so quy de tinh thue TNCN theo thu nhap (doanh thu - chi phi duoc tru).
 * Khoan chi chua phan loai KHONG duoc tru. Canh bao: chi tien mat tu 5 trieu, chia nho khoan chi trong ngay, thieu chung tu.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$nhom = nhomChiPhi();
$pttt = ['CASH' => 'Tiền mặt', 'BANK_TRANSFER' => 'Chuyển khoản', 'CARD' => 'Quẹt thẻ', 'QR_CODE' => 'Quét mã QR'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $amount = postFloat('amount');
        $reason = post('reason');
        $group = array_key_exists($_POST['expense_group'] ?? '', $nhom) ? $_POST['expense_group'] : 'OTHER';
        $method = array_key_exists($_POST['payment_method'] ?? '', $pttt) ? $_POST['payment_method'] : 'BANK_TRANSFER';
        $deduct = !empty($_POST['deductible']) ? 1 : 0;
        $hasDoc = !empty($_POST['has_document']) ? 1 : 0;
        $branchId = effectiveBranchId($currentUser);
        if ($amount <= 0) {
            $error = 'Số tiền phải lớn hơn 0';
        } elseif ($reason === '') {
            $error = 'Vui lòng nhập nội dung chi';
        } elseif (!$branchId) {
            $error = 'Tài khoản chưa được gán chi nhánh';
        } else {
            $code = 'PC' . substr((string) (int) round(microtime(true) * 1000), -8);
            $pdo->prepare(
                'INSERT INTO cashbook_entries (code, branch_id, type, amount, reason, payment_method, created_by_id, expense_group, deductible, has_document)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([$code, $branchId, 'PAYMENT', $amount, $reason, $method, $currentUser['id'], $group, $deduct, $hasDoc]);
            logActivity('EXPENSE_ADD', "$code $amount $group");
            redirect('chi_phi.php?ok=1');
        }
    } elseif ($action === 'classify') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT ce.* FROM cashbook_entries ce JOIN branches b ON b.id = ce.branch_id WHERE ce.id = ? AND b.tenant_id = ? AND ' . dieuKienChiKinhDoanh()
        );
        $stmt->execute([$id, $tenantId]);
        $en = $stmt->fetch();
        if (!$en) {
            $error = 'Không tìm thấy khoản chi';
        } elseif (kyDaKhoa($en['created_at'])) {
            $error = 'Kỳ này đã khóa sổ đến ' . date('d/m/Y', strtotime(ngayKhoaSo())) . ', không sửa được khoản chi.';
        } else {
            $group = array_key_exists($_POST['expense_group'] ?? '', $nhom) ? $_POST['expense_group'] : null;
            $d = (string) ($_POST['deductible'] ?? '');
            $deduct = $d === '1' ? 1 : ($d === '0' ? 0 : null);
            $hasDoc = !empty($_POST['has_document']) ? 1 : 0;
            $pdo->prepare('UPDATE cashbook_entries SET expense_group = ?, deductible = ?, has_document = ? WHERE id = ?')
                ->execute([$group, $deduct, $hasDoc, $id]);
            logActivity('EXPENSE_CLASSIFY', $en['code'] . ' ' . ($group ?? '-') . ' ' . ($deduct === null ? '?' : $deduct));
            redirect('chi_phi.php?' . http_build_query(array_intersect_key($_GET, array_flip(['from', 'to', 'f']))) . '&ok=1');
        }
    } elseif ($action === 'lock' && hasRole('ADMIN')) {
        $d = (string) ($_POST['locked_until'] ?? '');
        if ($d === '') {
            setSetting('books_locked_until', '');
            logActivity('BOOKS_UNLOCK', '');
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d <= date('Y-m-d')) {
            setSetting('books_locked_until', $d);
            logActivity('BOOKS_LOCK', $d);
        } else {
            $error = 'Ngày khóa sổ không hợp lệ (không được sau hôm nay).';
        }
        if (!$error) {
            redirect('chi_phi.php?ok=1');
        }
    }
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$filter = in_array($_GET['f'] ?? '', ['todo', 'nodoc', 'warn'], true) ? $_GET['f'] : '';

$stmt = $pdo->prepare(
    'SELECT ce.*, b.name AS branch_name, u.name AS by_name
     FROM cashbook_entries ce JOIN branches b ON b.id = ce.branch_id LEFT JOIN users u ON u.id = ce.created_by_id
     WHERE b.tenant_id = ? AND ce.created_at BETWEEN ? AND ? AND ' . dieuKienChiKinhDoanh() . ' ORDER BY ce.created_at DESC, ce.id DESC'
);
$stmt->execute([$tenantId, $from . ' 00:00:00', $to . ' 23:59:59']);
$all = $stmt->fetchAll();

// Canh bao: chi tien mat tu 5 trieu (khoan duoc tru) va chia nho khoan chi tien mat trong ngay.
$canhBao = [];
$theoNgay = [];
foreach ($all as $en) {
    $isCash = $en['payment_method'] === 'CASH';
    $duocTru = $en['deductible'] === null || (int) $en['deductible'] === 1;
    if ($isCash && $duocTru && (float) $en['amount'] >= NGUONG_CHI_TIEN_MAT) {
        $canhBao[$en['id']][] = 'Chi tiền mặt từ ' . money(NGUONG_CHI_TIEN_MAT) . ': chuyển khoản hoặc có chứng từ thanh toán không dùng tiền mặt mới chắc chắn được trừ.';
    }
    if ($isCash && $duocTru) {
        $theoNgay[substr($en['created_at'], 0, 10)][] = $en;
    }
}
foreach ($theoNgay as $ngay => $ds) {
    $tong = array_sum(array_map(static fn($x) => (float) $x['amount'], $ds));
    $lonNhat = max(array_map(static fn($x) => (float) $x['amount'], $ds));
    if (count($ds) >= 2 && $tong >= NGUONG_CHI_TIEN_MAT && $lonNhat < NGUONG_CHI_TIEN_MAT) {
        foreach ($ds as $x) {
            $canhBao[$x['id']][] = 'Nhiều khoản chi tiền mặt nhỏ trong ngày ' . date('d/m/Y', strtotime($ngay)) . ' cộng lại ' . money($tong) . ': dễ bị coi là chia nhỏ để tránh mốc ' . money(NGUONG_CHI_TIEN_MAT) . '.';
        }
    }
}

$rows = array_values(array_filter($all, static function ($en) use ($filter, $canhBao) {
    if ($filter === 'todo') return $en['deductible'] === null;
    if ($filter === 'nodoc') return !(int) $en['has_document'] && $en['deductible'] !== null && (int) $en['deductible'] === 1;
    if ($filter === 'warn') return !empty($canhBao[$en['id']]);
    return true;
}));

$tong = ['duoc_tru' => 0.0, 'khong_tru' => 0.0, 'chua' => 0.0];
$theoNhom = [];
foreach ($all as $en) {
    $a = (float) $en['amount'];
    if ($en['deductible'] === null) $tong['chua'] += $a;
    elseif ((int) $en['deductible'] === 1) {
        $tong['duoc_tru'] += $a;
        $k = $en['expense_group'] ?: 'OTHER';
        $theoNhom[$k] = ($theoNhom[$k] ?? 0) + $a;
    } else $tong['khong_tru'] += $a;
}
$soChua = count(array_filter($all, static fn($e) => $e['deductible'] === null));

if (isset($_GET['export'])) {
    $x = [['CHI PHÍ KINH DOANH ' . date('d/m/Y', strtotime($from)) . ' - ' . date('d/m/Y', strtotime($to)), '', '', '', '', '', ''],
        ['Ngày', 'Mã phiếu', 'Nội dung', 'Nhóm chi phí', 'Hình thức', 'Số tiền', 'Được trừ khi tính thuế', 'Có chứng từ']];
    foreach (array_reverse($all) as $en) {
        $x[] = [date('d/m/Y', strtotime($en['created_at'])), $en['code'], $en['reason'], $nhom[$en['expense_group']] ?? 'Chưa phân loại',
            $pttt[$en['payment_method']] ?? '', (string) round((float) $en['amount']),
            $en['deductible'] === null ? 'Chưa phân loại' : ((int) $en['deductible'] === 1 ? 'Được trừ' : 'Không được trừ'), (int) $en['has_document'] ? 'Có' : 'Chưa'];
    }
    $x[] = ['', '', '', '', 'Tổng được trừ', (string) round($tong['duoc_tru']), '', ''];
    logActivity('EXPORT_EXPENSES', "$from $to");
    downloadXlsx($x, 'chi_phi_' . $from . '_' . $to . '.xlsx');
    exit;
}

$locked = ngayKhoaSo();
require_once __DIR__ . '/inc_header.php';
$qs = static fn(array $extra = []) => http_build_query(array_merge(['from' => $from, 'to' => $to], $extra));
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Chi phí kinh doanh</h1>
<p class="muted" style="margin:0 0 16px;max-width:760px;font-size:14px;">
  Phân loại các khoản chi trong sổ quỹ để tính thuế TNCN theo thu nhập (doanh thu trừ giá vốn trừ chi phí được trừ). Khoản chi
  <b>chưa phân loại không được trừ</b>. Chi cho nhập hàng, hoàn tiền đơn, chủ rút tiền không nằm ở đây vì đã tính riêng.
</p>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Đã lưu.</div><?php endif; ?>
<?php if ($locked): ?><div class="alert" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;">🔒 Sổ đã khóa đến <?= e(date('d/m/Y', strtotime($locked))) ?>: không sửa được khoản chi và không hủy được đơn thuộc kỳ đã khóa (trả hàng vẫn ghi vào kỳ hiện tại).</div><?php endif; ?>

<form method="get" class="card" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:16px;">
  <div><label>Từ ngày</label><input type="date" name="from" value="<?= e($from) ?>" class="input"></div>
  <div><label>Đến ngày</label><input type="date" name="to" value="<?= e($to) ?>" class="input"></div>
  <button class="btn" type="submit">Xem</button>
  <a class="btn btn-secondary" href="chi_phi.php?<?= e($qs(['export' => 1])) ?>">Xuất Excel</a>
</form>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
  <div class="card"><div class="muted" style="font-size:11px;text-transform:uppercase;">Được trừ khi tính thuế</div><div style="font-size:18px;font-weight:700;color:#059669;"><?= money($tong['duoc_tru']) ?></div></div>
  <div class="card"><div class="muted" style="font-size:11px;text-transform:uppercase;">Không được trừ</div><div style="font-size:18px;font-weight:700;"><?= money($tong['khong_tru']) ?></div></div>
  <a class="card" href="chi_phi.php?<?= e($qs(['f' => 'todo'])) ?>" style="text-decoration:none;color:inherit;"><div class="muted" style="font-size:11px;text-transform:uppercase;">Chưa phân loại (<?= $soChua ?> khoản)</div><div style="font-size:18px;font-weight:700;<?= $soChua ? 'color:#d97706;' : '' ?>"><?= money($tong['chua']) ?></div></a>
  <a class="card" href="chi_phi.php?<?= e($qs(['f' => 'warn'])) ?>" style="text-decoration:none;color:inherit;"><div class="muted" style="font-size:11px;text-transform:uppercase;">Khoản có cảnh báo</div><div style="font-size:18px;font-weight:700;<?= $canhBao ? 'color:#dc2626;' : '' ?>"><?= count($canhBao) ?></div></a>
</div>

<?php if ($theoNhom): ?>
<div class="card" style="margin-bottom:16px;">
  <div style="font-weight:600;margin-bottom:8px;">Chi phí được trừ theo nhóm</div>
  <table><tbody>
    <?php arsort($theoNhom); foreach ($theoNhom as $k => $v): ?>
      <tr><td><?= e($nhom[$k] ?? $k) ?></td><td class="text-right" style="text-align:right;font-weight:600;"><?= money($v) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
  <div style="font-weight:600;margin-bottom:10px;">Ghi khoản chi mới</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="add">
    <div><label>Nội dung</label><input class="input" name="reason" placeholder="Tiền thuê tháng 11" required></div>
    <div><label>Số tiền</label><input class="input" name="amount" type="number" min="1" step="1" required></div>
    <div><label>Nhóm chi phí</label><select name="expense_group"><?php foreach ($nhom as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Hình thức trả</label><select name="payment_method"><?php foreach ($pttt as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === 'BANK_TRANSFER' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label><input type="checkbox" name="deductible" value="1" checked> Được trừ khi tính thuế</label>
         <label><input type="checkbox" name="has_document" value="1"> Có hóa đơn, chứng từ</label></div>
    <div><button class="btn" type="submit">Ghi chi phí</button></div>
  </form>
</div>

<?php if ($filter): ?><p style="font-size:13px;">Đang lọc: <b><?= ['todo' => 'chưa phân loại', 'nodoc' => 'thiếu chứng từ', 'warn' => 'có cảnh báo'][$filter] ?></b> · <a href="chi_phi.php?<?= e($qs()) ?>">Bỏ lọc</a></p><?php endif; ?>
<div style="overflow-x:auto;" class="card">
  <table>
    <thead><tr><th>Ngày</th><th>Nội dung</th><th style="text-align:right;">Số tiền</th><th>Hình thức</th><th>Phân loại</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted" style="text-align:center;padding:24px;">Không có khoản chi nào trong kỳ này.</td></tr><?php endif; ?>
    <?php foreach ($rows as $en): $khoa = kyDaKhoa($en['created_at']); ?>
      <tr>
        <td style="white-space:nowrap;"><?= e(date('d/m/Y', strtotime($en['created_at']))) ?><div class="muted" style="font-size:11px;"><?= e($en['code']) ?></div></td>
        <td><?= e($en['reason']) ?><div class="muted" style="font-size:11px;"><?= e($en['branch_name']) ?></div>
          <?php foreach ($canhBao[$en['id']] ?? [] as $w): ?><div style="font-size:12px;color:#b45309;margin-top:2px;">⚠ <?= e($w) ?></div><?php endforeach; ?>
          <?php if ($en['deductible'] !== null && (int) $en['deductible'] === 1 && !(int) $en['has_document']): ?><div style="font-size:12px;color:#92400e;">Chưa có hóa đơn, chứng từ</div><?php endif; ?></td>
        <td style="text-align:right;white-space:nowrap;font-weight:600;"><?= money($en['amount']) ?></td>
        <td><?= e($pttt[$en['payment_method']] ?? '') ?></td>
        <td colspan="2">
          <?php if ($khoa): ?>
            <span class="muted">🔒 <?= e($nhom[$en['expense_group']] ?? 'Chưa phân loại') ?> · <?= $en['deductible'] === null ? 'chưa phân loại' : ((int) $en['deductible'] ? 'được trừ' : 'không được trừ') ?></span>
          <?php else: ?>
          <form method="post" action="chi_phi.php?<?= e($qs($filter ? ['f' => $filter] : [])) ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="classify"><input type="hidden" name="id" value="<?= (int) $en['id'] ?>">
            <select name="expense_group" style="width:auto;"><option value="">Nhóm…</option><?php foreach ($nhom as $k => $v): ?><option value="<?= e($k) ?>" <?= $en['expense_group'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <select name="deductible" style="width:auto;">
              <option value="" <?= $en['deductible'] === null ? 'selected' : '' ?>>Chưa phân loại</option>
              <option value="1" <?= $en['deductible'] !== null && (int) $en['deductible'] === 1 ? 'selected' : '' ?>>Được trừ</option>
              <option value="0" <?= $en['deductible'] !== null && (int) $en['deductible'] === 0 ? 'selected' : '' ?>>Không được trừ</option>
            </select>
            <label style="display:inline;margin:0;white-space:nowrap;"><input type="checkbox" name="has_document" value="1" <?= (int) $en['has_document'] ? 'checked' : '' ?>> Có chứng từ</label>
            <button class="btn btn-secondary" type="submit">Lưu</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (hasRole('ADMIN')): ?>
<div class="card" style="margin-top:16px;max-width:620px;">
  <div style="font-weight:600;margin-bottom:6px;">Khóa sổ</div>
  <p class="muted" style="font-size:13px;margin:0 0 10px;">Sau khi chốt số liệu kỳ trước, khóa sổ để không ai sửa phân loại chi phí hay hủy các đơn đã tính vào kỳ đó. Để trống rồi lưu để mở khóa.</p>
  <form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="lock">
    <div><label>Khóa đến hết ngày</label><input type="date" name="locked_until" max="<?= e(date('Y-m-d')) ?>" value="<?= e($locked ?? '') ?>" class="input"></div>
    <button class="btn" type="submit">Lưu</button>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
