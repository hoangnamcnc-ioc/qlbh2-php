<?php
/**
 * Tai san co dinh va khau hao duong thang, chi phi tra truoc phan bo, doanh thu nhan truoc phan bo.
 * Khau hao va phan bo chi phi tra truoc DA TRICH la chi phi duoc tru khi tinh TNCN theo thu nhap (xem accounting.php).
 * Doanh thu nhan truoc chi theo doi lich ghi nhan, khong tu dieu chinh doanh thu tinh thue.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_xlsx.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$tab = in_array($_GET['tab'] ?? '', ['asset', 'prepaid', 'deferred'], true) ? $_GET['tab'] : 'asset';
$lastMonth = date('Y-m', strtotime('first day of last month'));

function ymOk(string $v): bool { return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'asset_add') {
        $name = mb_substr(post('name'), 0, 255);
        $cost = postFloat('cost');
        $salvage = postFloat('salvage');
        $life = (int) ($_POST['life_months'] ?? 0);
        $acq = (string) ($_POST['acquired_date'] ?? '');
        $svc = (string) ($_POST['in_service_date'] ?? '') ?: $acq;
        if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $acq) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $svc)) {
            $error = 'Nhập tên tài sản và ngày mua, ngày đưa vào sử dụng.';
        } elseif ($cost <= 0 || $salvage < 0 || $salvage >= $cost) {
            $error = 'Nguyên giá phải lớn hơn 0 và lớn hơn giá trị thanh lý.';
        } elseif ($life < 1 || $life > 600) {
            $error = 'Thời gian khấu hao từ 1 đến 600 tháng.';
        } elseif (kyDaKhoa($acq)) {
            $error = 'Ngày mua thuộc kỳ đã khóa sổ.';
        } else {
            $n = (int) $pdo->query('SELECT COUNT(*) FROM fixed_assets WHERE tenant_id = ' . $tenantId)->fetchColumn() + 1;
            $pdo->prepare('INSERT INTO fixed_assets (tenant_id, code, name, acquired_date, in_service_date, cost, salvage, life_months, note, created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tenantId, 'TS' . str_pad((string) $n, 3, '0', STR_PAD_LEFT), $name, $acq, $svc, $cost, $salvage, $life, mb_substr(post('note'), 0, 255) ?: null, $currentUser['id']]);
            logActivity('ASSET_ADD', $name);
            redirect('phan_bo.php?tab=asset&ok=1');
        }
    } elseif ($action === 'asset_dispose') {
        $id = (int) ($_POST['id'] ?? 0);
        $d = (string) ($_POST['disposed_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $error = 'Chọn ngày thanh lý.';
        } else {
            $pdo->prepare('UPDATE fixed_assets SET disposed_date = ?, disposal_amount = ? WHERE id = ? AND tenant_id = ? AND disposed_date IS NULL')
                ->execute([$d, postFloat('disposal_amount'), $id, $tenantId]);
            // Bo cac ky khau hao chua trich sau thang thanh ly (cac ky da trich giu nguyen)
            logActivity('ASSET_DISPOSE', (string) $id);
            redirect('phan_bo.php?tab=asset&ok=1');
        }
    } elseif ($action === 'item_add') {
        $kind = ($_POST['kind'] ?? '') === 'DEFERRED' ? 'DEFERRED' : 'PREPAID';
        $name = mb_substr(post('name'), 0, 255);
        $total = postFloat('total');
        $start = (string) ($_POST['start_month'] ?? '');
        $months = (int) ($_POST['months'] ?? 0);
        if ($name === '' || $total <= 0 || !ymOk($start) || $months < 1 || $months > 120) {
            $error = 'Nhập tên, số tiền, tháng bắt đầu và số tháng phân bổ (1 đến 120).';
        } else {
            $pdo->prepare('INSERT INTO allocation_items (tenant_id, kind, name, total, start_month, months, note, created_by_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$tenantId, $kind, $name, $total, $start, $months, mb_substr(post('note'), 0, 255) ?: null, $currentUser['id']]);
            logActivity('ALLOC_ADD', "$kind $name");
            redirect('phan_bo.php?tab=' . strtolower($kind) . '&ok=1');
        }
    } elseif ($action === 'item_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $has = $pdo->prepare("SELECT COUNT(*) FROM allocation_entries WHERE tenant_id = ? AND source = 'ITEM' AND source_id = ?");
        $has->execute([$tenantId, $id]);
        $soKy = (int) $has->fetchColumn();
        if ($soKy > 0) {
            $error = 'Khoản này đã trích ' . $soKy . ' kỳ nên không xóa được.';
        } else {
            $pdo->prepare('DELETE FROM allocation_items WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
            redirect('phan_bo.php?tab=' . $tab . '&ok=1');
        }
    } elseif ($action === 'asset_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $has = $pdo->prepare("SELECT COUNT(*) FROM allocation_entries WHERE tenant_id = ? AND source = 'ASSET' AND source_id = ?");
        $has->execute([$tenantId, $id]);
        if ((int) $has->fetchColumn() > 0) {
            $error = 'Tài sản đã trích khấu hao nên không xóa được. Dùng "Thanh lý" nếu không còn sử dụng.';
        } else {
            $pdo->prepare('DELETE FROM fixed_assets WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
            redirect('phan_bo.php?tab=asset&ok=1');
        }
    } elseif ($action === 'post') {
        $den = (string) ($_POST['den_thang'] ?? '');
        if (!ymOk($den) || $den > date('Y-m')) {
            $error = 'Chọn tháng trích hợp lệ (không sau tháng hiện tại).';
        } else {
            $n = trichPhanBoDen($pdo, $tenantId, $den);
            logActivity('ALLOC_POST', "$den $n");
            redirect('phan_bo.php?tab=' . $tab . '&ok=' . urlencode("Đã trích đến tháng $den: thêm $n kỳ."));
        }
    }
}

$assets = $pdo->prepare('SELECT * FROM fixed_assets WHERE tenant_id = ? ORDER BY code');
$assets->execute([$tenantId]);
$assets = $assets->fetchAll();
$items = $pdo->prepare('SELECT * FROM allocation_items WHERE tenant_id = ? ORDER BY start_month DESC, id DESC');
$items->execute([$tenantId]);
$items = $items->fetchAll();
$sumBy = static function (string $source) use ($pdo, $tenantId): array {
    $st = $pdo->prepare('SELECT source_id, COALESCE(SUM(amount),0) AS s, COUNT(*) AS n FROM allocation_entries WHERE tenant_id = ? AND source = ? GROUP BY source_id');
    $st->execute([$tenantId, $source]);
    $o = [];
    foreach ($st->fetchAll() as $r) { $o[(int) $r['source_id']] = $r; }
    return $o;
};
$posA = $sumBy('ASSET');
$posI = $sumBy('ITEM');

if (isset($_GET['export'])) {
    $x = [['TÀI SẢN CỐ ĐỊNH VÀ KHẤU HAO', '', '', '', '', '', '', ''], ['Mã', 'Tên tài sản', 'Ngày dùng', 'Nguyên giá', 'Thanh lý (giá trị còn lại tối thiểu)', 'Số tháng', 'Đã khấu hao', 'Giá trị còn lại']];
    foreach ($assets as $a) {
        $acc = (float) ($posA[(int) $a['id']]['s'] ?? 0);
        $x[] = [$a['code'], $a['name'], date('d/m/Y', strtotime($a['in_service_date'])), (string) round((float) $a['cost']), (string) round((float) $a['salvage']), (string) $a['life_months'], (string) round($acc), (string) round((float) $a['cost'] - $acc)];
    }
    logActivity('EXPORT_ASSETS', '');
    downloadXlsx($x, 'tai_san_co_dinh.xlsx');
    exit;
}

require_once __DIR__ . '/inc_header.php';
$tabs = ['asset' => 'Tài sản cố định', 'prepaid' => 'Chi phí trả trước', 'deferred' => 'Doanh thu nhận trước'];
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Tài sản và phân bổ</h1>
<p class="muted" style="margin:0 0 14px;max-width:780px;font-size:14px;">
  Tài sản cố định khấu hao đều theo tháng (bắt đầu từ tháng sau tháng dùng). Chi phí trả trước (thuê nhiều tháng, bảo hiểm, công cụ)
  phân bổ đều. Phần <b>đã trích</b> của hai loại này được tính là chi phí được trừ khi tính TNCN theo thu nhập.
  Doanh thu nhận trước chỉ lập lịch ghi nhận, không tự điều chỉnh doanh thu tính thuế.
</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success"><?= $_GET['ok'] === '1' ? 'Đã lưu.' : e((string) $_GET['ok']) ?></div><?php endif; ?>

<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
  <?php foreach ($tabs as $k => $v): ?>
    <a href="phan_bo.php?tab=<?= e($k) ?>" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;<?= $k === $tab ? 'background:var(--brand,#2563eb);color:#fff;font-weight:600;' : 'background:#f1f5f9;color:#334155;' ?>"><?= e($v) ?></a>
  <?php endforeach; ?>
</div>

<form method="post" class="card" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:16px;">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="post">
  <div><label>Trích khấu hao, phân bổ đến hết tháng</label><input type="month" name="den_thang" value="<?= e($lastMonth) ?>" max="<?= e(date('Y-m')) ?>" class="input"></div>
  <button class="btn" type="submit">Trích</button>
  <span class="muted" style="font-size:12px;">Làm lại nhiều lần không bị trùng; chỉ thêm các tháng còn thiếu cho cả ba loại.</span>
</form>

<?php if ($tab === 'asset'): ?>
<div class="card" style="margin-bottom:16px;">
  <div style="font-weight:600;margin-bottom:10px;">Thêm tài sản cố định</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="asset_add">
    <div><label>Tên tài sản</label><input class="input" name="name" required placeholder="Máy lạnh, kệ trưng bày..."></div>
    <div><label>Ngày mua</label><input class="input" type="date" name="acquired_date" value="<?= e(date('Y-m-d')) ?>" required></div>
    <div><label>Ngày đưa vào dùng</label><input class="input" type="date" name="in_service_date" value="<?= e(date('Y-m-d')) ?>"></div>
    <div><label>Nguyên giá</label><input class="input" type="number" name="cost" min="1" step="1" required></div>
    <div><label>Giá trị thanh lý ước tính</label><input class="input" type="number" name="salvage" min="0" step="1" value="0"></div>
    <div><label>Số tháng khấu hao</label><input class="input" type="number" name="life_months" min="1" max="600" value="36" required></div>
    <div><label>Ghi chú</label><input class="input" name="note"></div>
    <div><button class="btn" type="submit">Thêm</button></div>
  </form>
</div>
<div class="card" style="overflow-x:auto;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><b>Danh sách tài sản</b><a class="btn btn-secondary" href="phan_bo.php?tab=asset&amp;export=1">Xuất Excel</a></div>
  <table>
    <thead><tr><th>Mã</th><th>Tài sản</th><th style="text-align:right;">Nguyên giá</th><th style="text-align:right;">Mỗi tháng</th><th style="text-align:right;">Đã trích</th><th style="text-align:right;">Còn lại</th><th></th></tr></thead>
    <tbody>
    <?php if (!$assets): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:24px;">Chưa có tài sản nào.</td></tr><?php endif; ?>
    <?php foreach ($assets as $a):
        $acc = (float) ($posA[(int) $a['id']]['s'] ?? 0);
        $sch = lichKhauHao($a);
        $per = $sch ? reset($sch) : 0; ?>
      <tr>
        <td><?= e($a['code']) ?></td>
        <td><?= e($a['name']) ?><div class="muted" style="font-size:12px;">dùng từ <?= e(date('d/m/Y', strtotime($a['in_service_date']))) ?> · <?= (int) $a['life_months'] ?> tháng<?= $a['disposed_date'] ? ' · <b>đã thanh lý ' . e(date('d/m/Y', strtotime($a['disposed_date']))) . '</b>' : '' ?></div></td>
        <td style="text-align:right;"><?= money($a['cost']) ?></td>
        <td style="text-align:right;"><?= money($per) ?></td>
        <td style="text-align:right;"><?= money($acc) ?></td>
        <td style="text-align:right;font-weight:600;"><?= money((float) $a['cost'] - $acc) ?></td>
        <td style="white-space:nowrap;">
          <?php if (!$a['disposed_date']): ?>
          <form method="post" style="display:inline-flex;gap:4px;margin:0;" onsubmit="return confirm('Thanh lý tài sản này? Các tháng chưa trích sẽ không còn trích nữa.');">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="asset_dispose"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <input type="date" name="disposed_date" value="<?= e(date('Y-m-d')) ?>" class="input" style="width:140px;padding:3px 6px;">
            <input type="number" name="disposal_amount" min="0" step="1" placeholder="Thu về" class="input" style="width:100px;padding:3px 6px;">
            <button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Thanh lý</button>
          </form>
          <?php endif; ?>
          <?php if (empty($posA[(int) $a['id']])): ?>
          <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Xóa tài sản này?');">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="asset_delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Xóa</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php else:
    $kind = $tab === 'deferred' ? 'DEFERRED' : 'PREPAID';
    $isPre = $kind === 'PREPAID'; ?>
<div class="card" style="margin-bottom:16px;">
  <div style="font-weight:600;margin-bottom:10px;"><?= $isPre ? 'Thêm chi phí trả trước' : 'Thêm doanh thu nhận trước' ?></div>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="item_add"><input type="hidden" name="kind" value="<?= e($kind) ?>">
    <div><label><?= $isPre ? 'Khoản chi' : 'Khoản thu' ?></label><input class="input" name="name" required placeholder="<?= $isPre ? 'Thuê mặt bằng 12 tháng' : 'Thu trước gói bảo trì 12 tháng' ?>"></div>
    <div><label>Tổng số tiền</label><input class="input" type="number" name="total" min="1" step="1" required></div>
    <div><label>Bắt đầu từ tháng</label><input class="input" type="month" name="start_month" value="<?= e(date('Y-m')) ?>" required></div>
    <div><label>Số tháng phân bổ</label><input class="input" type="number" name="months" min="1" max="120" value="12" required></div>
    <div><label>Ghi chú</label><input class="input" name="note"></div>
    <div><button class="btn" type="submit">Thêm</button></div>
  </form>
</div>
<div class="card" style="overflow-x:auto;">
  <table>
    <thead><tr><th><?= $isPre ? 'Khoản chi' : 'Khoản thu' ?></th><th>Phân bổ</th><th style="text-align:right;">Tổng</th><th style="text-align:right;">Mỗi tháng</th><th style="text-align:right;"><?= $isPre ? 'Đã tính vào chi phí' : 'Đã ghi nhận' ?></th><th style="text-align:right;">Còn lại</th><th></th></tr></thead>
    <tbody>
    <?php $co = 0; foreach ($items as $it): if ($it['kind'] !== $kind) continue; $co++;
        $acc = (float) ($posI[(int) $it['id']]['s'] ?? 0); $sch = lichPhanBo($it); ?>
      <tr>
        <td><?= e($it['name']) ?><?php if ($it['note']): ?><div class="muted" style="font-size:12px;"><?= e($it['note']) ?></div><?php endif; ?></td>
        <td><?= e($it['start_month']) ?> → <?= e(congThang($it['start_month'], (int) $it['months'] - 1)) ?> (<?= (int) $it['months'] ?> tháng)</td>
        <td style="text-align:right;"><?= money($it['total']) ?></td>
        <td style="text-align:right;"><?= money(reset($sch)) ?></td>
        <td style="text-align:right;"><?= money($acc) ?></td>
        <td style="text-align:right;font-weight:600;"><?= money((float) $it['total'] - $acc) ?></td>
        <td><?php if (empty($posI[(int) $it['id']])): ?>
          <form method="post" style="margin:0;" onsubmit="return confirm('Xóa khoản này?');"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="item_delete"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Xóa</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; if (!$co): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:24px;">Chưa có khoản nào.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
