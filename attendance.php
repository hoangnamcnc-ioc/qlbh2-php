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

    // Cau hinh chong cham cong ho: chi cho cham cong tu mang (dia chi IP) cua cua hang. Chi Quan tri doi duoc.
    if ($action === 'gps_save') {
        if (hasRole('ADMIN')) {
            setSetting('attendance_gps_lock', !empty($_POST['enabled']) ? '1' : '0');
            setSetting('attendance_gps_radius', (string) max(30, min(2000, (int) ($_POST['radius'] ?? 150))));
            $lat = (string) ($_POST['lat'] ?? ''); $lng = (string) ($_POST['lng'] ?? '');
            if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
                setSetting('store_lat', $lat);
                setSetting('store_lng', $lng);
            }
            logActivity('ATTENDANCE_GPS_CONFIG');
        }
        redirect('attendance.php?gps=1');
    }
    if (in_array($action, ['ip_toggle', 'ip_add', 'ip_remove'], true)) {
        if (hasRole('ADMIN')) {
            $ips = array_values(array_filter(array_map('trim', explode(',', getSetting('attendance_allowed_ips', '')))));
            if ($action === 'ip_toggle') {
                setSetting('attendance_ip_lock', !empty($_POST['enabled']) ? '1' : '0');
            } elseif ($action === 'ip_add') {
                $ip = clientIp();
                if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $ips, true)) {
                    $ips[] = $ip;
                }
                setSetting('attendance_allowed_ips', implode(',', array_slice($ips, 0, 10)));
            } else {
                $rm = trim((string) ($_POST['ip'] ?? ''));
                setSetting('attendance_allowed_ips', implode(',', array_filter($ips, static fn($x) => $x !== $rm)));
            }
            logActivity('ATTENDANCE_IP_LOCK_CONFIG', $action);
        }
        redirect('attendance.php?ip=1');
    }

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

<?php if (hasRole('ADMIN')):
    $gpsLock = getSetting('attendance_gps_lock', '0') === '1';
    $gpsRadius = (int) getSetting('attendance_gps_radius', '150');
    $storeLat = getSetting('store_lat', ''); $storeLng = getSetting('store_lng', '');
?>
<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 6px;">Xác minh vị trí GPS khi chấm công</h2>
  <p class="muted" style="font-size:12.5px;margin:0 0 10px;">
    Khi bật, nhân viên bấm Vào ca/Ra ca trên điện thoại phải <b>cho phép truy cập vị trí</b> và đang ở trong bán kính quy định quanh cửa hàng.
    Vị trí được ghi vào ghi chú chấm công (khoảng cách tới cửa hàng). Đây là biện pháp răn đe — GPS trên điện thoại có thể bị giả lập bởi người rành kỹ thuật.
  </p>
  <form method="post" id="gpsForm">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="gps_save">
    <input type="hidden" name="lat" id="gpsLat"><input type="hidden" name="lng" id="gpsLng">
    <label style="font-weight:400;display:block;margin-bottom:8px;"><input type="checkbox" name="enabled" value="1" <?= $gpsLock ? 'checked' : '' ?>> Bật xác minh vị trí GPS</label>
    <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
      <div><label style="font-size:12px;">Bán kính cho phép (mét)</label><input class="input" type="number" name="radius" min="30" max="2000" value="<?= (int) $gpsRadius ?>" style="width:140px;"></div>
      <button type="button" class="btn btn-secondary" id="gpsSetHere">📍 Đặt vị trí cửa hàng = vị trí hiện tại của tôi</button>
      <button type="submit" class="btn">Lưu</button>
    </div>
    <div id="gpsMsg" class="muted" style="font-size:12.5px;margin-top:8px;">
      <?php if ($storeLat !== '' && $storeLng !== ''): ?>
        Vị trí cửa hàng đã lưu: <a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= e($storeLat) ?>,<?= e($storeLng) ?>"><?= e(number_format((float) $storeLat, 5)) ?>, <?= e(number_format((float) $storeLng, 5)) ?></a>
      <?php else: ?>Chưa đặt vị trí cửa hàng — hãy mở trang này trên điện thoại/máy tính đang đứng ở cửa hàng rồi bấm nút 📍.<?php endif; ?>
    </div>
  </form>
  <?php if ($gpsLock && ($storeLat === '' || $storeLng === '')): ?><p class="alert alert-warning" style="margin:10px 0 0;font-size:12.5px;">Đã bật nhưng chưa đặt vị trí cửa hàng nên chưa có tác dụng.</p><?php endif; ?>
</div>
<script>
document.getElementById('gpsSetHere').addEventListener('click', function () {
  const msg = document.getElementById('gpsMsg');
  if (!navigator.geolocation) { msg.textContent = 'Thiết bị/trình duyệt này không hỗ trợ định vị.'; return; }
  msg.textContent = 'Đang lấy vị trí... (hãy bấm Cho phép khi trình duyệt hỏi)';
  navigator.geolocation.getCurrentPosition(function (pos) {
    document.getElementById('gpsLat').value = pos.coords.latitude;
    document.getElementById('gpsLng').value = pos.coords.longitude;
    msg.textContent = 'Đã lấy vị trí (sai số khoảng ' + Math.round(pos.coords.accuracy) + ' m). Bấm Lưu để áp dụng.';
  }, function (err) {
    msg.textContent = 'Không lấy được vị trí: ' + (err.code === 1 ? 'bạn đã từ chối quyền vị trí.' : 'thử lại ngoài trời/bật GPS.');
  }, { enableHighAccuracy: true, timeout: 15000 });
});
</script>
<?php endif; ?>

<?php if (hasRole('ADMIN')):
    $ipLock = getSetting('attendance_ip_lock', '0') === '1';
    $allowedIps = array_values(array_filter(array_map('trim', explode(',', getSetting('attendance_allowed_ips', '')))));
?>
<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 6px;">Chống chấm công hộ</h2>
  <p class="muted" style="font-size:12.5px;margin:0 0 10px;">
    Khi bật, nhân viên chỉ tự chấm công (mục "Chấm công &amp; lương của tôi") được khi đang dùng <b>mạng Wi-Fi của cửa hàng</b>.
    Bạn đang ở mạng có địa chỉ <b><?= e(clientIp()) ?></b> — bấm "Thêm mạng hiện tại" khi đang kết nối Wi-Fi cửa hàng.
    Lưu ý: nếu nhà mạng đổi địa chỉ (IP động) bạn cần thêm lại; điện thoại dùng 4G sẽ không chấm công được.
  </p>
  <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="ip_toggle">
    <label style="font-weight:400;"><input type="checkbox" name="enabled" value="1" <?= $ipLock ? 'checked' : '' ?> onchange="this.form.submit()"> Bật giới hạn theo mạng cửa hàng</label>
  </form>
  <div style="margin-top:8px;">
    <?php foreach ($allowedIps as $ip): ?>
      <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin:0 8px 4px 0;background:#f1f5f9;border-radius:6px;padding:3px 8px;font-size:13px;">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="ip_remove"><input type="hidden" name="ip" value="<?= e($ip) ?>">
        <?= e($ip) ?> <button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;padding:0 2px;" title="Bỏ mạng này">×</button>
      </form>
    <?php endforeach; ?>
    <form method="post" style="display:inline;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="ip_add">
      <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12.5px;">+ Thêm mạng hiện tại</button>
    </form>
  </div>
  <?php if ($ipLock && !$allowedIps): ?><p class="alert alert-warning" style="margin:10px 0 0;font-size:12.5px;">Đã bật nhưng chưa có mạng nào được phép nên giới hạn chưa có tác dụng.</p><?php endif; ?>
</div>
<?php endif; ?>

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
