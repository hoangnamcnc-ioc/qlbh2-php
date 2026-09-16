<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;

$usersStmt = $pdo->prepare("SELECT id, name FROM users WHERE tenant_id = ? AND is_active = 1 ORDER BY name");
$usersStmt->execute([$tenantId]);
$employees = $usersStmt->fetchAll();
$employeeIds = array_map('intval', array_column($employees, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';
    $userId = (int) ($_POST['user_id'] ?? 0);

    if (!in_array($userId, $employeeIds, true)) {
        redirect('attendance.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM attendance WHERE id = ? AND tenant_id = ? AND user_id = ?')
            ->execute([$id, $tenantId, $userId]);
        logActivity('ATTENDANCE_DELETE', "id=$id");
        redirect('attendance.php?user_id=' . $userId . '&thang=' . urlencode($_POST['thang'] ?? ''));
    }

    $workDate = post('work_date');
    $timeIn = post('time_in') ?: null;
    $timeOut = post('time_out') ?: null;
    $note = post('note') ?: null;

    if (!$workDate) {
        $error = 'Vui lòng chọn ngày chấm công';
    } else {
        $pdo->prepare(
            'INSERT INTO attendance (tenant_id, user_id, work_date, time_in, time_out, note)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE time_in = VALUES(time_in), time_out = VALUES(time_out), note = VALUES(note)'
        )->execute([$tenantId, $userId, $workDate, $timeIn, $timeOut, $note]);
        logActivity('ATTENDANCE_SAVE', "user_id=$userId date=$workDate");
        redirect('attendance.php?user_id=' . $userId . '&thang=' . substr($workDate, 0, 7));
    }
}

$selectedUserId = (int) ($_GET['user_id'] ?? ($employees[0]['id'] ?? 0));
$thang = $_GET['thang'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $thang)) $thang = date('Y-m');

$rows = [];
if (in_array($selectedUserId, $employeeIds, true)) {
    $rowsStmt = $pdo->prepare(
        'SELECT * FROM attendance WHERE tenant_id = ? AND user_id = ? AND work_date LIKE ? ORDER BY work_date'
    );
    $rowsStmt->execute([$tenantId, $selectedUserId, $thang . '%']);
    $rows = $rowsStmt->fetchAll();
}

function tinhGioCong(?string $gioVao, ?string $gioRa): float
{
    if (!$gioVao || !$gioRa) return 0;
    [$hV, $mV] = array_map('intval', explode(':', $gioVao));
    [$hR, $mR] = array_map('intval', explode(':', $gioRa));
    $phut = ($hR * 60 + $mR) - ($hV * 60 + $mV);
    if ($phut <= 0) $phut += 24 * 60; // ca qua dem
    return $phut / 60;
}
$tongGio = array_sum(array_map(fn($r) => tinhGioCong($r['time_in'], $r['time_out']), $rows));

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:16px;">Bảng chấm công</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Ghi nhận giờ vào / giờ ra</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="save">
    <div class="grid-2">
      <div class="field"><label>Nhân viên *</label>
        <select class="input" name="user_id" required>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= $selectedUserId === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Ngày *</label><input class="input" type="date" name="work_date" value="<?= e(date('Y-m-d')) ?>" required></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Giờ vào</label><input class="input" type="time" name="time_in"></div>
      <div class="field"><label>Giờ ra</label><input class="input" type="time" name="time_out"></div>
    </div>
    <div class="field"><label>Ghi chú</label><input class="input" name="note" placeholder="Nghỉ phép, đi muộn..."></div>
    <button type="submit" class="btn">Ghi nhận</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <div style="padding:16px 16px 0;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
    <form method="get" style="display:flex;gap:8px;align-items:end;">
      <div><label style="display:block;font-size:12px;margin-bottom:2px;">Nhân viên</label>
        <select name="user_id" class="input" onchange="this.form.submit()">
          <?php foreach ($employees as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= $selectedUserId === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label style="display:block;font-size:12px;margin-bottom:2px;">Tháng</label><input type="month" name="thang" value="<?= e($thang) ?>" class="input" onchange="this.form.submit()"></div>
    </form>
  </div>
  <table style="margin-top:12px;">
    <thead><tr><th>Ngày</th><th>Giờ vào</th><th>Giờ ra</th><th class="text-right">Số giờ</th><th>Ghi chú</th><th></th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="muted" style="text-align:center;padding:24px;">Chưa có dữ liệu chấm công trong tháng này.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= date('d/m/Y', strtotime($r['work_date'])) ?></td>
          <td class="muted"><?= e($r['time_in'] ? substr($r['time_in'], 0, 5) : '—') ?></td>
          <td class="muted"><?= e($r['time_out'] ? substr($r['time_out'], 0, 5) : '—') ?></td>
          <td class="text-right"><?= number_format(tinhGioCong($r['time_in'], $r['time_out']), 1) ?></td>
          <td class="muted"><?= e($r['note'] ?: '—') ?></td>
          <td class="text-right">
            <form method="post" onsubmit="return confirm('Xóa bản ghi chấm công này?');">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="user_id" value="<?= (int) $selectedUserId ?>">
              <input type="hidden" name="thang" value="<?= e($thang) ?>">
              <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px;">Xóa</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
      <tfoot><tr style="border-top:1px solid #e2e8f0;"><td colspan="3" style="font-weight:700;">Tổng giờ công tháng</td><td class="text-right" style="font-weight:700;"><?= number_format($tongGio, 1) ?></td><td colspan="2"></td></tr></tfoot>
    <?php endif; ?>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
