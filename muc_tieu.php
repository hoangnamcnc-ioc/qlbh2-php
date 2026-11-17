<?php
/** Muc tieu doanh thu thang: dat muc tieu, theo doi tien do, du bao va so tien can ban moi ngay con lai; lich su cac thang. */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$thang = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['thang'] ?? '') ? $_GET['thang'] : date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $m = (string) ($_POST['month'] ?? '');
    $amount = postFloat('amount');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
        $error = 'Chọn tháng hợp lệ.';
    } elseif (($_POST['action'] ?? '') === 'clear') {
        $pdo->prepare('DELETE FROM sales_targets WHERE tenant_id = ? AND month = ?')->execute([$tenantId, $m]);
        redirect('muc_tieu.php?thang=' . $m . '&ok=1');
    } elseif ($amount <= 0 || $amount > 1e13) {
        $error = 'Nhập mục tiêu doanh thu lớn hơn 0.';
    } else {
        $pdo->prepare('INSERT INTO sales_targets (tenant_id, month, amount, note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), note = VALUES(note)')
            ->execute([$tenantId, $m, $amount, mb_substr(post('note'), 0, 255) ?: null]);
        logActivity('SALES_TARGET', "$m $amount");
        redirect('muc_tieu.php?thang=' . $m . '&ok=1');
    }
}

/** Doanh thu thuan cua cua hang trong 1 thang (tru tien hang tra lai). */
$doanhThuThang = static function (string $m) use ($pdo, $tenantId): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(o.total_amount),0) FROM orders o JOIN branches b ON b.id = o.branch_id WHERE b.tenant_id = ? AND DATE_FORMAT(o.created_at,'%Y-%m') = ? AND o.status NOT IN ('CANCELLED','DRAFT')");
    $st->execute([$tenantId, $m]);
    $v = (float) $st->fetchColumn();
    $st = $pdo->prepare("SELECT COALESCE(SUM(r.refund_amount),0) FROM order_returns r JOIN orders o ON o.id = r.order_id JOIN branches b ON b.id = o.branch_id WHERE b.tenant_id = ? AND DATE_FORMAT(r.created_at,'%Y-%m') = ?");
    $st->execute([$tenantId, $m]);
    return max(0.0, $v - (float) $st->fetchColumn());
};
$tgt = $pdo->prepare('SELECT amount, note FROM sales_targets WHERE tenant_id = ? AND month = ?');
$tgt->execute([$tenantId, $thang]);
$tgt = $tgt->fetch();
$target = $tgt ? (float) $tgt['amount'] : 0.0;
$mtd = $doanhThuThang($thang);
$soNgay = (int) date('t', strtotime($thang . '-01'));
$laThangNay = $thang === date('Y-m');
$daQua = $laThangNay ? (int) date('j') : ($thang < date('Y-m') ? $soNgay : 0);
$conLai = max(0, $soNgay - $daQua);
$duBao = $daQua > 0 ? $mtd / $daQua * $soNgay : 0.0;
$pct = $target > 0 ? $mtd / $target * 100 : 0;
$canMoiNgay = ($target > $mtd && $conLai > 0) ? ($target - $mtd) / $conLai : 0.0;
$nhip = $target > 0 && $daQua > 0 ? $target * $daQua / $soNgay : 0.0; // so tien nen dat duoc den hom nay neu ban deu

$hist = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
    $t = $pdo->prepare('SELECT amount FROM sales_targets WHERE tenant_id = ? AND month = ?');
    $t->execute([$tenantId, $m]);
    $hist[] = ['m' => $m, 'rev' => $doanhThuThang($m), 'tgt' => (float) ($t->fetchColumn() ?: 0)];
}

require_once __DIR__ . '/inc_header.php';
echo thanhTabNghiepVu('baocao');
?>
<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Mục tiêu doanh thu tháng</h1>
<p class="muted" style="margin:0 0 14px;max-width:760px;font-size:14px;">Đặt mục tiêu cho từng tháng, xem đã đạt bao nhiêu, dự báo cả tháng theo nhịp bán hiện tại và cần bán bao nhiêu mỗi ngày còn lại. Doanh thu là doanh thu thuần của toàn bộ chi nhánh (đã trừ hàng trả lại).</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Đã lưu.</div><?php endif; ?>

<form method="get" class="card" style="display:flex;gap:10px;align-items:end;margin-bottom:16px;flex-wrap:wrap;">
  <div><label>Tháng</label><input type="month" name="thang" value="<?= e($thang) ?>" class="input"></div>
  <button class="btn" type="submit">Xem</button>
</form>

<div class="card" style="margin-bottom:16px;max-width:860px;">
  <?php if ($target > 0): $mau = $pct >= 100 ? '#059669' : ($laThangNay && $mtd < $nhip * 0.85 ? '#dc2626' : '#2563eb'); ?>
    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
      <div><span class="muted" style="font-size:12px;text-transform:uppercase;">Đã đạt</span><div style="font-size:24px;font-weight:700;color:<?= $mau ?>;"><?= money($mtd) ?></div></div>
      <div style="text-align:right;"><span class="muted" style="font-size:12px;text-transform:uppercase;">Mục tiêu</span><div style="font-size:24px;font-weight:700;"><?= money($target) ?></div></div>
    </div>
    <div style="height:14px;background:#e2e8f0;border-radius:99px;overflow:hidden;position:relative;">
      <div style="height:100%;width:<?= min(100, round($pct, 1)) ?>%;background:<?= $mau ?>;"></div>
      <?php if ($laThangNay && $nhip > 0): ?><div title="Nhịp cần đạt đến hôm nay" style="position:absolute;top:0;bottom:0;left:<?= min(100, round($nhip / $target * 100, 1)) ?>%;width:2px;background:#0f172a;"></div><?php endif; ?>
    </div>
    <div style="margin-top:8px;font-size:14px;"><b><?= round($pct, 1) ?>%</b> mục tiêu
      <?php if ($laThangNay): ?>· đã qua <?= $daQua ?>/<?= $soNgay ?> ngày · nhịp cần đạt đến hôm nay <b><?= money($nhip) ?></b><?php endif; ?></div>
    <?php if ($laThangNay): ?>
      <div style="margin-top:6px;font-size:14px;">Dự báo cả tháng theo nhịp hiện tại: <b><?= money($duBao) ?></b>
        <?= $duBao >= $target ? '<span style="color:#059669;">(vượt mục tiêu)</span>' : '<span style="color:#dc2626;">(thiếu ' . money($target - $duBao) . ')</span>' ?></div>
      <?php if ($canMoiNgay > 0): ?><div style="margin-top:4px;font-size:14px;">Để đạt mục tiêu cần bán trung bình <b><?= money($canMoiNgay) ?>/ngày</b> trong <?= $conLai ?> ngày còn lại.</div><?php endif; ?>
      <?php if ($pct >= 100): ?><div style="margin-top:4px;color:#059669;font-weight:600;">Đã đạt mục tiêu tháng này.</div><?php endif; ?>
    <?php endif; ?>
  <?php else: ?>
    <div class="muted">Tháng <?= e($thang) ?> chưa đặt mục tiêu. Doanh thu hiện tại: <b><?= money($mtd) ?></b>.</div>
  <?php endif; ?>
</div>

<form method="post" class="card" style="margin-bottom:16px;max-width:860px;display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="month" value="<?= e($thang) ?>">
  <div><label>Mục tiêu tháng <?= e($thang) ?></label><input class="input" type="number" name="amount" min="1" step="1" value="<?= $target > 0 ? e((string) (int) $target) : '' ?>" required></div>
  <div style="flex:1;min-width:200px;"><label>Ghi chú</label><input class="input" name="note" value="<?= e($tgt['note'] ?? '') ?>"></div>
  <button class="btn" type="submit" name="action" value="save">Lưu mục tiêu</button>
  <?php if ($target > 0): ?><button class="btn btn-secondary" type="submit" name="action" value="clear" onclick="return confirm('Xóa mục tiêu tháng này?');">Xóa</button><?php endif; ?>
</form>

<div class="card" style="overflow-x:auto;max-width:860px;">
  <div style="font-weight:600;margin-bottom:8px;">6 tháng gần nhất</div>
  <table>
    <thead><tr><th>Tháng</th><th style="text-align:right;">Doanh thu</th><th style="text-align:right;">Mục tiêu</th><th style="text-align:right;">Đạt</th></tr></thead>
    <tbody>
      <?php foreach (array_reverse($hist) as $h): $p = $h['tgt'] > 0 ? $h['rev'] / $h['tgt'] * 100 : null; ?>
        <tr><td><a href="muc_tieu.php?thang=<?= e($h['m']) ?>"><?= e($h['m']) ?></a></td><td style="text-align:right;"><?= money($h['rev']) ?></td>
          <td style="text-align:right;"><?= $h['tgt'] > 0 ? money($h['tgt']) : '<span class="muted">—</span>' ?></td>
          <td style="text-align:right;font-weight:600;color:<?= $p === null ? '#94a3b8' : ($p >= 100 ? '#059669' : '#dc2626') ?>;"><?= $p === null ? '—' : round($p) . '%' ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
