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
        redirect('work_schedules.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM work_schedules WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
        logActivity('WORK_SCHEDULE_DELETE', "id=$id");
        redirect('work_schedules.php?thang=' . urlencode($_POST['thang'] ?? ''));
    }

    $workDate = post('work_date');
    $shift = post('shift');
    $note = post('note') ?: null;

    if (!$workDate || $shift === '') {
        $error = 'Vui lòng chọn ngày và nhập tên ca làm việc';
    } else {
        $pdo->prepare('INSERT INTO work_schedules (tenant_id, user_id, work_date, shift, note) VALUES (?,?,?,?,?)')
            ->execute([$tenantId, $userId, $workDate, $shift, $note]);
        logActivity('WORK_SCHEDULE_CREATE', "user_id=$userId date=$workDate");
        redirect('work_schedules.php?thang=' . substr($workDate, 0, 7));
    }
}

$thang = $_GET['thang'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $thang)) $thang = date('Y-m');

$rowsStmt = $pdo->prepare(
    "SELECT ws.*, u.name AS employee_name FROM work_schedules ws
     JOIN users u ON u.id = ws.user_id
     WHERE ws.tenant_id = ? AND ws.work_date LIKE ?
     ORDER BY ws.work_date, u.name"
);
$rowsStmt->execute([$tenantId, $thang . '%']);
$rows = $rowsStmt->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:16px;">Lịch làm việc</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm lịch làm việc</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="save">
    <div class="grid-2">
      <div class="field"><label>Nhân viên *</label>
        <select class="input" name="user_id" required>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>"><?= e($emp['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Ngày *</label><input class="input" type="date" name="work_date" value="<?= e(date('Y-m-d')) ?>" required></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Ca làm việc *</label><input class="input" name="shift" required placeholder="vd: Ca sáng 8h-14h"></div>
      <div class="field"><label>Ghi chú</label><input class="input" name="note"></div>
    </div>
    <button type="submit" class="btn">Thêm vào lịch</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <div style="padding:16px 16px 0;">
    <form method="get">
      <label style="display:block;font-size:12px;margin-bottom:2px;">Tháng</label>
      <input type="month" name="thang" value="<?= e($thang) ?>" class="input" style="max-width:180px;" onchange="this.form.submit()">
    </form>
  </div>
  <table style="margin-top:12px;">
    <thead><tr><th>Ngày</th><th>Nhân viên</th><th>Ca làm việc</th><th>Ghi chú</th><th></th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:24px;">Chưa có lịch làm việc nào trong tháng này.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= date('d/m/Y', strtotime($r['work_date'])) ?></td>
          <td><?= e($r['employee_name']) ?></td>
          <td><?= e($r['shift']) ?></td>
          <td class="muted"><?= e($r['note'] ?: '—') ?></td>
          <td class="text-right">
            <form method="post" onsubmit="return confirm('Xóa lịch làm việc này?');">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="thang" value="<?= e($thang) ?>">
              <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px;">Xóa</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
