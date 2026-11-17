<?php
/**
 * Chi dinh ky (tien dien, thue mat bang, internet...): khai bao mot lan, moi thang bam "Tao chi phi thang nay" de ghi ca loat
 * vao so quy (phieu chi), da phan loai san de tinh thue. Moi khoan chi chi tao mot lan moi thang.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$nhom = nhomChiPhi();
$pttt = ['CASH' => 'Tiền mặt', 'BANK_TRANSFER' => 'Chuyển khoản', 'CARD' => 'Quẹt thẻ', 'QR_CODE' => 'Quét mã QR'];
$thang = date('Y-m');

$bs = $pdo->prepare('SELECT id, name FROM branches WHERE tenant_id = ? ORDER BY name');
$bs->execute([$tenantId]);
$branches = $bs->fetchAll();
$branchIds = array_map(static fn($b) => (int) $b['id'], $branches);

/** Tao phieu chi thang nay cho cac khoan dang bat chua duoc tao. Tra ve so phieu moi. */
$taoThang = static function (int $onlyId = 0) use ($pdo, $tenantId, $currentUser, $thang): int {
    $st = $pdo->prepare('SELECT * FROM recurring_expenses WHERE tenant_id = ? AND is_active = 1' . ($onlyId ? ' AND id = ?' : ''));
    $st->execute($onlyId ? [$tenantId, $onlyId] : [$tenantId]);
    $n = 0;
    foreach ($st->fetchAll() as $r) {
        $pdo->beginTransaction();
        try {
            // Khoa duy nhat (recurring_id, month) chan tao trung ke ca khi bam hai lan
            $ins = $pdo->prepare('INSERT IGNORE INTO recurring_runs (recurring_id, month) VALUES (?,?)');
            $ins->execute([$r['id'], $thang]);
            if ($ins->rowCount() === 0) { $pdo->rollBack(); continue; }
            $code = 'PC' . substr((string) (int) round(microtime(true) * 1000), -8) . $r['id'];
            $pdo->prepare('INSERT INTO cashbook_entries (code, branch_id, type, amount, reason, payment_method, created_by_id, expense_group, deductible, has_document) VALUES (?,?,?,?,?,?,?,?,?,0)')
                ->execute([substr($code, 0, 50), $r['branch_id'], 'PAYMENT', $r['amount'], $r['name'] . ' (chi định kỳ ' . $thang . ')', $r['payment_method'], $currentUser['id'], $r['expense_group'], $r['deductible']]);
            $pdo->prepare('UPDATE recurring_runs SET cashbook_code = ? WHERE recurring_id = ? AND month = ?')->execute([substr($code, 0, 50), $r['id'], $thang]);
            $pdo->commit();
            $n++;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    return $n;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $name = mb_substr(post('name'), 0, 255);
        $amount = postFloat('amount');
        $day = max(1, min(28, (int) ($_POST['day_of_month'] ?? 1)));
        $group = array_key_exists($_POST['expense_group'] ?? '', $nhom) ? $_POST['expense_group'] : 'OTHER';
        $method = array_key_exists($_POST['payment_method'] ?? '', $pttt) ? $_POST['payment_method'] : 'BANK_TRANSFER';
        $branch = (int) ($_POST['branch_id'] ?? 0);
        if ($name === '' || $amount <= 0) {
            $error = 'Nhập tên khoản chi và số tiền lớn hơn 0.';
        } elseif (!in_array($branch, $branchIds, true)) {
            $error = 'Chọn chi nhánh ghi chi phí.';
        } else {
            $pdo->prepare('INSERT INTO recurring_expenses (tenant_id, branch_id, name, amount, day_of_month, expense_group, payment_method, deductible, created_by_id) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$tenantId, $branch, $name, $amount, $day, $group, $method, !empty($_POST['deductible']) ? 1 : 0, $currentUser['id']]);
            logActivity('RECURRING_ADD', $name);
            redirect('chi_dinh_ky.php?ok=1');
        }
    } elseif ($action === 'toggle' || $action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'toggle') {
            $pdo->prepare('UPDATE recurring_expenses SET is_active = 1 - is_active WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
        } else {
            $own = $pdo->prepare('SELECT COUNT(*) FROM recurring_expenses WHERE id = ? AND tenant_id = ?');
            $own->execute([$id, $tenantId]);
            if ((int) $own->fetchColumn() === 1) {
                $pdo->prepare('DELETE FROM recurring_runs WHERE recurring_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM recurring_expenses WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
            }
        }
        redirect('chi_dinh_ky.php?ok=1');
    } elseif ($action === 'run') {
        $n = $taoThang();
        logActivity('RECURRING_RUN', "$thang $n");
        redirect('chi_dinh_ky.php?ok=' . urlencode($n > 0 ? "Đã tạo $n phiếu chi tháng $thang." : "Tháng $thang không còn khoản nào cần tạo."));
    } elseif ($action === 'run_one') {
        $n = $taoThang((int) ($_POST['id'] ?? 0));
        redirect('chi_dinh_ky.php?ok=' . urlencode($n > 0 ? 'Đã tạo phiếu chi.' : 'Khoản này đã tạo trong tháng.'));
    }
}

$items = $pdo->prepare('SELECT r.*, b.name AS bname, (SELECT cashbook_code FROM recurring_runs x WHERE x.recurring_id = r.id AND x.month = ?) AS done_code FROM recurring_expenses r JOIN branches b ON b.id = r.branch_id WHERE r.tenant_id = ? ORDER BY r.is_active DESC, r.day_of_month, r.name');
$items->execute([$thang, $tenantId]);
$items = $items->fetchAll();
$chua = count(array_filter($items, static fn($r) => (int) $r['is_active'] === 1 && !$r['done_code']));
$tongThang = array_sum(array_map(static fn($r) => (int) $r['is_active'] === 1 ? (float) $r['amount'] : 0, $items));

require_once __DIR__ . '/inc_header.php';
echo thanhTabNghiepVu('ketoan');
?>
<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Chi định kỳ</h1>
<p class="muted" style="margin:0 0 14px;max-width:780px;font-size:14px;">Khai báo các khoản chi lặp lại mỗi tháng (tiền điện, thuê mặt bằng, internet, lương quản lý...). Đầu hoặc cuối tháng bấm một nút để tạo cả loạt phiếu chi vào <a href="cashbook.php">Sổ quỹ</a>, đã gắn nhóm chi phí và đánh dấu được trừ khi tính thuế. Mỗi khoản chỉ tạo một lần mỗi tháng.</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success"><?= $_GET['ok'] === '1' ? 'Đã lưu.' : e((string) $_GET['ok']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
  <div>Tháng <b><?= e($thang) ?></b>: tổng các khoản đang bật <b><?= money($tongThang) ?></b>, còn <b style="color:<?= $chua ? '#d97706' : '#059669' ?>;"><?= $chua ?></b> khoản chưa tạo.</div>
  <form method="post" style="margin:0;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="run">
    <button class="btn" type="submit" <?= $chua ? '' : 'disabled' ?> onclick="return confirm('Tạo <?= $chua ?> phiếu chi cho tháng <?= e($thang) ?>?');">Tạo chi phí tháng này</button></form>
</div>

<div class="card" style="margin-bottom:16px;">
  <div style="font-weight:600;margin-bottom:10px;">Thêm khoản chi định kỳ</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="add">
    <div><label>Khoản chi</label><input class="input" name="name" placeholder="Tiền điện, thuê mặt bằng..." required></div>
    <div><label>Số tiền mỗi tháng</label><input class="input" type="number" name="amount" min="1" step="1" required></div>
    <div><label>Ngày trong tháng (nhắc)</label><input class="input" type="number" name="day_of_month" min="1" max="28" value="5"></div>
    <div><label>Nhóm chi phí</label><select name="expense_group"><?php foreach ($nhom as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Hình thức trả</label><select name="payment_method"><?php foreach ($pttt as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === 'BANK_TRANSFER' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Chi nhánh ghi sổ</label><select name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
    <div><label><input type="checkbox" name="deductible" value="1" checked> Được trừ khi tính thuế</label></div>
    <div><button class="btn" type="submit">Thêm</button></div>
  </form>
</div>

<div class="card" style="overflow-x:auto;">
  <table>
    <thead><tr><th>Khoản chi</th><th>Nhóm</th><th>Chi nhánh</th><th style="text-align:right;">Số tiền</th><th>Tháng <?= e($thang) ?></th><th></th></tr></thead>
    <tbody>
    <?php if (!$items): ?><tr><td colspan="6" class="muted" style="text-align:center;padding:24px;">Chưa có khoản chi định kỳ nào.</td></tr><?php endif; ?>
    <?php foreach ($items as $r): $on = (int) $r['is_active'] === 1; ?>
      <tr style="<?= $on ? '' : 'opacity:.55;' ?>">
        <td><?= e($r['name']) ?><div class="muted" style="font-size:12px;">nhắc ngày <?= (int) $r['day_of_month'] ?> · <?= e($pttt[$r['payment_method']] ?? '') ?><?= (int) $r['deductible'] ? '' : ' · không được trừ' ?></div></td>
        <td><?= e($nhom[$r['expense_group']] ?? $r['expense_group']) ?></td>
        <td><?= e($r['bname']) ?></td>
        <td style="text-align:right;font-weight:600;"><?= money($r['amount']) ?></td>
        <td><?php if (!$on): ?><span class="muted">Đang tắt</span><?php elseif ($r['done_code']): ?><span style="color:#059669;">Đã tạo <?= e($r['done_code']) ?></span><?php else: ?>
          <form method="post" style="margin:0;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="run_one"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Tạo ngay</button></form><?php endif; ?></td>
        <td style="white-space:nowrap;">
          <form method="post" style="display:inline;margin:0;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit"><?= $on ? 'Tắt' : 'Bật' ?></button></form>
          <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Xóa khoản chi định kỳ này? Các phiếu chi đã tạo vẫn giữ nguyên.');"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Xóa</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
