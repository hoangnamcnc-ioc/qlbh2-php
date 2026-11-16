<?php
/**
 * Kiem ke quy tien mat: dem tien theo menh gia, so voi so du tien mat tren so quy, luu bien ban. Chenh lech duoc ghi lai de truy nguyen nhan.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$menhGia = [500000, 200000, 100000, 50000, 20000, 10000, 5000, 2000, 1000, 500];

$bs = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? ORDER BY name');
$bs->execute([$tenantId]);
$branches = $bs->fetchAll();
$branchIds = array_map(static fn($b) => (int) $b['id'], $branches);
$myBranch = effectiveBranchId($currentUser);
$branchId = (int) ($_REQUEST['branch_id'] ?? $myBranch);
if (!in_array($branchId, $branchIds, true)) {
    $branchId = $branchIds[0] ?? 0;
}

/** So du tien mat tren so quy cua chi nhanh den het ngay $den (chi phuong thuc CASH). */
$soDuTienMat = static function (int $branch, string $den) use ($pdo): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='RECEIPT' THEN amount ELSE -amount END),0) FROM cashbook_entries WHERE branch_id = ? AND payment_method = 'CASH' AND created_at <= ?");
    $st->execute([$branch, $den . ' 23:59:59']);
    return (float) $st->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $date = (string) ($_POST['count_date'] ?? '');
    $detail = [];
    $counted = 0.0;
    foreach ($menhGia as $m) {
        $q = max(0, (int) ($_POST['q'][$m] ?? 0));
        if ($q > 0) { $detail[$m] = $q; $counted += $m * $q; }
    }
    $khac = max(0.0, postFloat('other_amount'));
    $counted += $khac;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
        $error = 'Chọn ngày kiểm kê hợp lệ (không sau hôm nay).';
    } elseif (!$branchId) {
        $error = 'Chưa có chi nhánh.';
    } elseif ($counted <= 0 && empty($_POST['allow_zero'])) {
        $error = 'Chưa nhập số tờ tiền nào. Nếu quỹ thật sự trống, tích "Quỹ trống".';
    } else {
        $book = $soDuTienMat($branchId, $date);
        $pdo->prepare('INSERT INTO cash_counts (tenant_id, branch_id, count_date, book_balance, counted, detail, note, counted_by_id) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$tenantId, $branchId, $date, $book, $counted, json_encode(['menh_gia' => $detail, 'khac' => $khac]), mb_substr(post('note'), 0, 255) ?: null, $currentUser['id']]);
        logActivity('CASH_COUNT', "branch=$branchId book=$book counted=$counted");
        redirect('kiem_ke_quy.php?branch_id=' . $branchId . '&ok=1');
    }
}

$hist = $pdo->prepare(
    'SELECT c.*, b.name AS branch_name, u.name AS by_name FROM cash_counts c JOIN branches b ON b.id = c.branch_id LEFT JOIN users u ON u.id = c.counted_by_id
     WHERE c.tenant_id = ? ORDER BY c.count_date DESC, c.id DESC LIMIT 60'
);
$hist->execute([$tenantId]);
$hist = $hist->fetchAll();
$soDuHienTai = $branchId ? $soDuTienMat($branchId, date('Y-m-d')) : 0.0;

require_once __DIR__ . '/inc_header.php';
?>

<?= thanhTabNghiepVu('ketoan') ?>
<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Kiểm kê quỹ tiền mặt</h1>
<p class="muted" style="margin:0 0 14px;max-width:760px;font-size:14px;">
  Đếm tiền trong két theo từng mệnh giá, so với số dư tiền mặt trên <a href="cashbook.php">Sổ quỹ</a>. Biên bản được lưu lại;
  chênh lệch cần tìm nguyên nhân rồi ghi phiếu thu hoặc chi điều chỉnh ở Sổ quỹ (phần mềm không tự sửa sổ).
</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Đã lưu biên bản kiểm kê.</div><?php endif; ?>

<form method="post" class="card" style="margin-bottom:16px;max-width:760px;">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
    <div><label>Chi nhánh</label>
      <select name="branch_id" onchange="location.href='kiem_ke_quy.php?branch_id='+this.value"><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $branchId ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Ngày kiểm kê</label><input type="date" name="count_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" class="input"></div>
    <div><label>Số dư tiền mặt trên sổ hôm nay</label><div style="font-size:18px;font-weight:700;padding-top:4px;"><?= money($soDuHienTai) ?></div></div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:12px;">
    <?php foreach ($menhGia as $m): ?>
      <div><label><?= number_format($m, 0, ',', '.') ?> đ</label><input class="input kk-q" data-m="<?= (int) $m ?>" type="number" min="0" step="1" name="q[<?= (int) $m ?>]" placeholder="số tờ"></div>
    <?php endforeach; ?>
    <div><label>Khác (xu, tiền lẻ...)</label><input class="input" id="kk-other" type="number" min="0" step="1" name="other_amount" value="0"></div>
  </div>
  <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
    <div>Tổng đếm được: <b id="kk-total" style="font-size:18px;">0</b> · Chênh lệch so với sổ: <b id="kk-diff" style="font-size:18px;">—</b></div>
    <label style="display:inline;margin:0;"><input type="checkbox" name="allow_zero" value="1"> Quỹ trống</label>
  </div>
  <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
    <div style="flex:1;min-width:220px;"><label>Ghi chú</label><input class="input" name="note" placeholder="Người chứng kiến, lý do chênh lệch nếu biết"></div>
    <button class="btn" type="submit">Lưu biên bản</button>
  </div>
</form>
<script>
(function () {
  var book = <?= json_encode($soDuHienTai) ?>;
  function fmt(n) { return Math.round(n).toLocaleString('vi-VN'); }
  function calc() {
    var t = parseFloat(document.getElementById('kk-other').value) || 0;
    document.querySelectorAll('.kk-q').forEach(function (i) { t += (parseInt(i.value, 10) || 0) * parseInt(i.getAttribute('data-m'), 10); });
    document.getElementById('kk-total').textContent = fmt(t);
    var d = document.getElementById('kk-diff'), x = t - book;
    d.textContent = (x > 0 ? '+' : '') + fmt(x);
    d.style.color = Math.abs(x) < 1 ? '#059669' : '#dc2626';
  }
  document.querySelectorAll('.kk-q, #kk-other').forEach(function (i) { i.addEventListener('input', calc); });
})();
</script>

<div class="card" style="overflow-x:auto;">
  <div style="font-weight:600;margin-bottom:8px;">Biên bản đã lập</div>
  <table>
    <thead><tr><th>Ngày</th><th>Chi nhánh</th><th style="text-align:right;">Số dư sổ</th><th style="text-align:right;">Đếm được</th><th style="text-align:right;">Chênh lệch</th><th>Người kiểm, ghi chú</th></tr></thead>
    <tbody>
    <?php if (!$hist): ?><tr><td colspan="6" class="muted" style="text-align:center;padding:24px;">Chưa có biên bản nào.</td></tr><?php endif; ?>
    <?php foreach ($hist as $h): $diff = (float) $h['counted'] - (float) $h['book_balance']; ?>
      <tr>
        <td><?= e(date('d/m/Y', strtotime($h['count_date']))) ?></td>
        <td><?= e($h['branch_name']) ?></td>
        <td style="text-align:right;"><?= money($h['book_balance']) ?></td>
        <td style="text-align:right;"><?= money($h['counted']) ?></td>
        <td style="text-align:right;font-weight:700;color:<?= abs($diff) < 1 ? '#059669' : '#dc2626' ?>;"><?= abs($diff) < 1 ? 'Khớp' : ($diff > 0 ? '+' : '') . money($diff) ?></td>
        <td><?= e($h['by_name'] ?? '') ?><?= $h['note'] ? ' · ' . e($h['note']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
