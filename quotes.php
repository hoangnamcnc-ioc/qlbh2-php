<?php
/** Danh sach bao gia: tao, gui khach, theo doi trang thai, chuyen thanh don hang. */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$tt = ['DRAFT' => ['Nháp', '#475569'], 'SENT' => ['Đã gửi', '#1d4ed8'], 'ACCEPTED' => ['Khách đồng ý', '#15803d'], 'REJECTED' => ['Khách từ chối', '#b91c1c']];
$f = array_key_exists($_GET['status'] ?? '', $tt) ? $_GET['status'] : '';
$q = trim((string) ($_GET['q'] ?? ''));

$sql = 'SELECT * FROM quotes WHERE tenant_id = ?';
$args = [$tenantId];
if ($f) { $sql .= ' AND status = ?'; $args[] = $f; }
if ($q !== '') { $sql .= ' AND (code LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like); }
$sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
$homNay = date('Y-m-d');

require_once __DIR__ . '/inc_header.php';
echo thanhTabNghiepVu('don');
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">Báo giá</h1>
  <a class="btn" href="quote_form.php">+ Tạo báo giá</a>
</div>
<form method="get" class="card" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:16px;">
  <div><label>Tìm</label><input class="input" name="q" value="<?= e($q) ?>" placeholder="Mã, tên hoặc SĐT khách"></div>
  <div><label>Trạng thái</label><select name="status"><option value="">Tất cả</option><?php foreach ($tt as $k => [$v]): ?><option value="<?= e($k) ?>" <?= $f === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <button class="btn" type="submit">Lọc</button>
</form>
<div class="card" style="overflow-x:auto;">
  <table>
    <thead><tr><th>Mã</th><th>Khách hàng</th><th>Ngày lập</th><th>Hiệu lực đến</th><th style="text-align:right;">Tổng tiền</th><th>Trạng thái</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted" style="text-align:center;padding:24px;">Chưa có báo giá nào.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): [$lb, $mau] = $tt[$r['status']];
        $het = in_array($r['status'], ['DRAFT', 'SENT'], true) && $r['valid_until'] < $homNay; ?>
      <tr>
        <td><a href="quote_view.php?id=<?= (int) $r['id'] ?>"><b><?= e($r['code']) ?></b></a></td>
        <td><?= e($r['customer_name']) ?><div class="muted" style="font-size:12px;"><?= e($r['customer_phone'] ?? '') ?></div></td>
        <td><?= e(date('d/m/Y', strtotime($r['created_at']))) ?></td>
        <td><?= e(date('d/m/Y', strtotime($r['valid_until']))) ?><?= $het ? ' <span style="color:#b91c1c;font-size:12px;">hết hạn</span>' : '' ?></td>
        <td style="text-align:right;font-weight:600;"><?= money($r['total']) ?></td>
        <td style="color:<?= $mau ?>;font-weight:600;"><?= e($lb) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
