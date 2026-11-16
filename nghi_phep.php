<?php
/**
 * Nghi phep: nhan vien tu gui don, quan ly duyet hoac tu choi. Ngay phep nam con lai tinh tu so ngay phep trong ho so nhan su
 * (mac dinh 12 ngay) tru cac don phep nam da duyet. Ngay nghi khong tinh Chu nhat.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$tenantId = currentTenantId();
$userId = (int) $currentUser['id'];
$isMgr = isManagerTier() || userHasPermissionGroup('hr');
$error = null;
$loaiNghi = ['ANNUAL' => 'Phép năm', 'SICK' => 'Nghỉ ốm', 'UNPAID' => 'Nghỉ không lương', 'OTHER' => 'Việc riêng, khác'];
$trangThai = ['PENDING' => ['Chờ duyệt', '#b45309'], 'APPROVED' => ['Đã duyệt', '#15803d'], 'REJECTED' => ['Từ chối', '#b91c1c']];
$nam = date('Y');

$quyDinh = static function (int $uid) use ($pdo, $tenantId): float {
    $st = $pdo->prepare('SELECT annual_leave_days FROM hr_profiles WHERE user_id = ? AND tenant_id = ?');
    $st->execute([$uid, $tenantId]);
    $v = $st->fetchColumn();
    return $v === false ? 12.0 : (float) $v;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'request') {
        $type = array_key_exists($_POST['leave_type'] ?? '', $loaiNghi) ? $_POST['leave_type'] : 'ANNUAL';
        $from = (string) ($_POST['from_date'] ?? '');
        $to = (string) ($_POST['to_date'] ?? '') ?: $from;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $error = 'Chọn ngày nghỉ.';
        } elseif ($to < $from) {
            $error = 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.';
        } elseif ($from < date('Y-m-d', strtotime('-30 days'))) {
            $error = 'Chỉ xin được ngày nghỉ trong vòng 30 ngày gần đây trở đi.';
        } elseif (strtotime($to) - strtotime($from) > 60 * 86400) {
            $error = 'Một đơn tối đa 60 ngày.';
        } else {
            $days = demNgayNghi($from, $to);
            $trung = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id = ? AND status <> 'REJECTED' AND from_date <= ? AND to_date >= ?");
            $trung->execute([$userId, $to, $from]);
            if ($days <= 0) {
                $error = 'Khoảng ngày chọn chỉ có Chủ nhật, không cần xin nghỉ.';
            } elseif ((int) $trung->fetchColumn() > 0) {
                $error = 'Bạn đã có đơn nghỉ trùng các ngày này.';
            } else {
                $pdo->prepare('INSERT INTO leave_requests (tenant_id, user_id, leave_type, from_date, to_date, days, reason) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$tenantId, $userId, $type, $from, $to, $days, mb_substr(post('reason'), 0, 255) ?: null]);
                logActivity('LEAVE_REQUEST', "$type $from $to $days");
                redirect('nghi_phep.php?ok=Đã gửi đơn nghỉ phép, chờ quản lý duyệt.');
            }
        }
    } elseif ($action === 'cancel') {
        $pdo->prepare("DELETE FROM leave_requests WHERE id = ? AND user_id = ? AND tenant_id = ? AND status = 'PENDING'")->execute([(int) ($_POST['id'] ?? 0), $userId, $tenantId]);
        redirect('nghi_phep.php?ok=Đã hủy đơn.');
    } elseif (($action === 'approve' || $action === 'reject') && $isMgr) {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ? AND tenant_id = ? AND status = 'PENDING'");
        $st->execute([$id, $tenantId]);
        $r = $st->fetch();
        if (!$r) {
            $error = 'Đơn không còn ở trạng thái chờ duyệt.';
        } elseif ((int) $r['user_id'] === $userId && !hasRole('ADMIN')) {
            $error = 'Bạn không tự duyệt đơn của chính mình.';
        } else {
            $note = mb_substr(post('decision_note'), 0, 255) ?: null;
            if ($action === 'approve' && $r['leave_type'] === 'ANNUAL') {
                $sd = soDuNghiPhep($pdo, (int) $r['user_id'], substr($r['from_date'], 0, 4), $quyDinh((int) $r['user_id']));
                if ((float) $r['days'] > $sd['con_lai']) {
                    $error = 'Nhân viên chỉ còn ' . rtrim(rtrim(number_format($sd['con_lai'], 1, ',', ''), '0'), ',') . ' ngày phép năm, đơn xin ' . (float) $r['days'] . ' ngày. Từ chối hoặc nhờ nhân viên đổi sang nghỉ không lương.';
                }
            }
            if (!$error) {
                $pdo->prepare('UPDATE leave_requests SET status = ?, decided_by_id = ?, decided_at = NOW(), decision_note = ? WHERE id = ?')
                    ->execute([$action === 'approve' ? 'APPROVED' : 'REJECTED', $userId, $note, $id]);
                logActivity('LEAVE_' . strtoupper($action), (string) $id);
                redirect('nghi_phep.php?ok=Đã ' . ($action === 'approve' ? 'duyệt' : 'từ chối') . ' đơn.');
            }
        }
    }
}

$mine = $pdo->prepare('SELECT * FROM leave_requests WHERE user_id = ? AND tenant_id = ? ORDER BY from_date DESC, id DESC LIMIT 30');
$mine->execute([$userId, $tenantId]);
$mine = $mine->fetchAll();
$sdMine = soDuNghiPhep($pdo, $userId, $nam, $quyDinh($userId));

$pending = [];
$recent = [];
if ($isMgr) {
    $st = $pdo->prepare("SELECT l.*, u.name FROM leave_requests l JOIN users u ON u.id = l.user_id WHERE l.tenant_id = ? AND l.status = 'PENDING' ORDER BY l.from_date");
    $st->execute([$tenantId]);
    $pending = $st->fetchAll();
    $st = $pdo->prepare("SELECT l.*, u.name FROM leave_requests l JOIN users u ON u.id = l.user_id WHERE l.tenant_id = ? AND l.status <> 'PENDING' ORDER BY l.decided_at DESC, l.id DESC LIMIT 30");
    $st->execute([$tenantId]);
    $recent = $st->fetchAll();
}

require_once __DIR__ . '/inc_header.php';
$fmt = static fn($n) => rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',');
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Nghỉ phép</h1>
<p class="muted" style="margin:0 0 14px;max-width:760px;font-size:14px;">Gửi đơn xin nghỉ, theo dõi ngày phép năm còn lại. Ngày nghỉ không tính Chủ nhật. Quản lý duyệt hoặc từ chối ngay trên trang này.</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success"><?= e((string) $_GET['ok']) ?></div><?php endif; ?>

<?php if ($isMgr && $pending): ?>
<div class="card" style="margin-bottom:16px;">
  <div style="font-weight:600;margin-bottom:8px;">Đơn chờ duyệt (<?= count($pending) ?>)</div>
  <?php foreach ($pending as $p): $sd = soDuNghiPhep($pdo, (int) $p['user_id'], substr($p['from_date'], 0, 4), $quyDinh((int) $p['user_id'])); ?>
    <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;border-top:1px solid #f1f5f9;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <div style="flex:1;min-width:240px;"><b><?= e($p['name']) ?></b> · <?= e($loaiNghi[$p['leave_type']] ?? '') ?> · <?= e(date('d/m', strtotime($p['from_date']))) ?><?= $p['to_date'] !== $p['from_date'] ? '–' . e(date('d/m/Y', strtotime($p['to_date']))) : '/' . e(date('Y', strtotime($p['from_date']))) ?> (<?= $fmt($p['days']) ?> ngày)
        <div class="muted" style="font-size:12px;"><?= e($p['reason'] ?? '') ?> · phép năm còn <?= $fmt($sd['con_lai']) ?>/<?= $fmt($sd['quy_dinh']) ?></div></div>
      <input class="input" name="decision_note" placeholder="Ghi chú (tùy chọn)" style="width:200px;">
      <button class="btn" name="action" value="approve" type="submit">Duyệt</button>
      <button class="btn btn-secondary" name="action" value="reject" type="submit">Từ chối</button>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:16px;align-items:start;">
  <div class="card">
    <div style="font-weight:600;margin-bottom:4px;">Xin nghỉ</div>
    <div class="muted" style="font-size:13px;margin-bottom:10px;">Phép năm <?= e($nam) ?> của bạn: còn <b><?= $fmt($sdMine['con_lai']) ?></b> / <?= $fmt($sdMine['quy_dinh']) ?> ngày</div>
    <form method="post" style="display:grid;gap:10px;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="request">
      <div><label>Loại nghỉ</label><select name="leave_type"><?php foreach ($loaiNghi as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div><label>Từ ngày</label><input class="input" type="date" name="from_date" value="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" required></div>
        <div><label>Đến ngày</label><input class="input" type="date" name="to_date" value="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"></div>
      </div>
      <div><label>Lý do</label><input class="input" name="reason" placeholder="Việc gia đình, đi khám..."></div>
      <div><button class="btn" type="submit">Gửi đơn</button></div>
    </form>
  </div>
  <div class="card" style="overflow-x:auto;">
    <div style="font-weight:600;margin-bottom:8px;">Đơn của tôi</div>
    <table>
      <tbody>
        <?php if (!$mine): ?><tr><td class="muted" style="text-align:center;padding:16px;">Chưa có đơn nào.</td></tr><?php endif; ?>
        <?php foreach ($mine as $m): [$tt, $mau] = $trangThai[$m['status']]; ?>
          <tr>
            <td><?= e($loaiNghi[$m['leave_type']] ?? '') ?><div class="muted" style="font-size:12px;"><?= e(date('d/m/Y', strtotime($m['from_date']))) ?><?= $m['to_date'] !== $m['from_date'] ? ' – ' . e(date('d/m/Y', strtotime($m['to_date']))) : '' ?> · <?= $fmt($m['days']) ?> ngày</div></td>
            <td style="color:<?= $mau ?>;font-weight:600;"><?= e($tt) ?><?php if ($m['decision_note']): ?><div class="muted" style="font-size:12px;font-weight:400;"><?= e($m['decision_note']) ?></div><?php endif; ?></td>
            <td><?php if ($m['status'] === 'PENDING'): ?><form method="post" style="margin:0;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Hủy</button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($isMgr && $recent): ?>
<div class="card" style="overflow-x:auto;">
  <div style="font-weight:600;margin-bottom:8px;">Đã xử lý gần đây</div>
  <table>
    <thead><tr><th>Nhân viên</th><th>Loại</th><th>Ngày nghỉ</th><th style="text-align:right;">Số ngày</th><th>Kết quả</th></tr></thead>
    <tbody>
      <?php foreach ($recent as $r): [$tt, $mau] = $trangThai[$r['status']]; ?>
        <tr><td><?= e($r['name']) ?></td><td><?= e($loaiNghi[$r['leave_type']] ?? '') ?></td>
          <td><?= e(date('d/m/Y', strtotime($r['from_date']))) ?><?= $r['to_date'] !== $r['from_date'] ? ' – ' . e(date('d/m/Y', strtotime($r['to_date']))) : '' ?></td>
          <td style="text-align:right;"><?= $fmt($r['days']) ?></td><td style="color:<?= $mau ?>;font-weight:600;"><?= e($tt) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
