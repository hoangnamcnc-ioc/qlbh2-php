<?php
/**
 * Nhan vien TU cham cong vao/ra ca va xem gio cong + luong tam tinh cua CHINH MINH.
 * Truoc day chi Quan ly nhap tay o attendance.php, nhan vien khong co cach nao tu cham cong hay
 * xem luong cua minh. Moi thao tac chi tac dong len ban ghi cua user dang dang nhap.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$tenantId = currentTenantId();
$userId = (int) $currentUser['id'];
$message = null;

function gioCongCuaToi(?string $gioVao, ?string $gioRa): float
{
    if (!$gioVao || !$gioRa) return 0;
    [$hV, $mV] = array_map('intval', explode(':', $gioVao));
    [$hR, $mR] = array_map('intval', explode(':', $gioRa));
    $phut = ($hR * 60 + $mR) - ($hV * 60 + $mV);
    if ($phut == 0) return 0; // vao va ra cung phut = chua lam gi (khong tinh thanh 24 gio)
    if ($phut < 0) $phut += 24 * 60; // ca qua dem
    return $phut / 60;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $today = date('Y-m-d');
    $now = date('H:i:s');

    // Chong cham cong ho: neu cua hang bat gioi han theo mang thi chi cham cong khi dung mang (IP) da dang ky.
    $allowedIps = array_values(array_filter(array_map('trim', explode(',', getSetting('attendance_allowed_ips', '')))));
    if (getSetting('attendance_ip_lock', '0') === '1' && $allowedIps && !in_array(clientIp(), $allowedIps, true)) {
        $message = 'Bạn đang ở ngoài mạng Wi-Fi của cửa hàng (địa chỉ ' . clientIp() . ') nên chưa chấm công được. Hãy kết nối Wi-Fi của cửa hàng rồi thử lại.';
        $action = '';
    }

    // Xac minh vi tri GPS: dien thoai gui toa do + do chinh xac; phai nam trong ban kinh quanh cua hang.
    $gpsNote = '';
    $gpsOn = getSetting('attendance_gps_lock', '0') === '1' && getSetting('store_lat', '') !== '' && getSetting('store_lng', '') !== '';
    if ($gpsOn && in_array($action, ['in', 'out'], true)) {
        $lat = $_POST['lat'] ?? ''; $lng = $_POST['lng'] ?? ''; $acc = (float) ($_POST['acc'] ?? 0);
        $radius = max(30, (int) getSetting('attendance_gps_radius', '150'));
        if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
            $message = 'Cần cho phép truy cập vị trí để chấm công. Hãy bật GPS, bấm "Cho phép" khi trình duyệt hỏi rồi thử lại.';
            $action = '';
        } elseif ($acc > 300) {
            $message = 'Tín hiệu GPS chưa đủ chính xác (sai số ' . round($acc) . ' m). Hãy ra chỗ thoáng/bật định vị chính xác rồi thử lại.';
            $action = '';
        } else {
            $dist = khoangCachMet((float) $lat, (float) $lng, (float) getSetting('store_lat'), (float) getSetting('store_lng'));
            if ($dist > $radius) {
                $message = 'Bạn đang cách cửa hàng khoảng ' . round($dist) . ' m (cho phép trong ' . $radius . ' m) nên chưa chấm công được.';
                $action = '';
            } else {
                $gpsNote = 'GPS ' . ($action === 'in' ? 'vào' : 'ra') . ' cách cửa hàng ' . round($dist) . 'm';
            }
        }
    }

    if ($action === 'in') {
        // UNIQUE (user_id, work_date): bam 2 lan khong tao 2 dong, va khong ghi de gio vao da co.
        $ins = $pdo->prepare('INSERT IGNORE INTO attendance (tenant_id, user_id, work_date, time_in, note) VALUES (?,?,?,?,?)');
        $ins->execute([$tenantId, $userId, $today, $now, $gpsNote !== '' ? $gpsNote : null]);
        $message = $ins->rowCount() ? 'Đã chấm công vào ca lúc ' . substr($now, 0, 5) : 'Hôm nay bạn đã chấm công vào ca rồi.';
        if ($ins->rowCount()) logActivity('ATTENDANCE_SELF_IN', "date=$today");
    } elseif ($action === 'out') {
        // Ra ca cho dong dang mo gan nhat (hom nay, hoac hom qua neu ca qua dem).
        $open = $pdo->prepare(
            'SELECT id FROM attendance WHERE tenant_id = ? AND user_id = ? AND time_in IS NOT NULL AND time_out IS NULL
             AND work_date >= DATE_SUB(?, INTERVAL 1 DAY) ORDER BY work_date DESC LIMIT 1'
        );
        $open->execute([$tenantId, $userId, $today]);
        $id = $open->fetchColumn();
        if ($id) {
            $pdo->prepare("UPDATE attendance SET time_out = ?, note = CASE WHEN ? = '' THEN note ELSE CONCAT_WS(' | ', note, ?) END WHERE id = ? AND user_id = ? AND time_out IS NULL")
                ->execute([$now, $gpsNote, $gpsNote, $id, $userId]);
            $message = 'Đã chấm công ra ca lúc ' . substr($now, 0, 5);
            logActivity('ATTENDANCE_SELF_OUT', "id=$id");
        } else {
            $message = 'Không có ca đang mở để ra ca. Hãy chấm công vào ca trước.';
        }
    }
}

$thang = $_GET['thang'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $thang)) $thang = date('Y-m');

$rowsStmt = $pdo->prepare(
    'SELECT work_date, time_in, time_out, note FROM attendance WHERE tenant_id = ? AND user_id = ? AND work_date LIKE ? ORDER BY work_date DESC'
);
$rowsStmt->execute([$tenantId, $userId, $thang . '%']);
$rows = $rowsStmt->fetchAll();
$tongGio = 0.0;
foreach ($rows as $r) $tongGio += gioCongCuaToi($r['time_in'], $r['time_out']);

$me = $pdo->prepare('SELECT hourly_wage, commission_percent FROM users WHERE id = ? AND tenant_id = ?');
$me->execute([$userId, $tenantId]);
$me = $me->fetch() ?: ['hourly_wage' => 0, 'commission_percent' => 0];

// Doanh so tinh hoa hong giong payroll.php: don hoan thanh do chinh minh ban tru phan da tra.
$rev = $pdo->prepare(
    "SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o JOIN branches b ON b.id = o.branch_id
     WHERE o.sold_by_id = ? AND o.status = 'COMPLETED' AND DATE_FORMAT(o.created_at, '%Y-%m') = ? AND b.tenant_id = ?"
);
$rev->execute([$userId, $thang, $tenantId]);
$doanhThu = (float) $rev->fetchColumn();
$ret = $pdo->prepare(
    "SELECT COALESCE(SUM(r.refund_amount), 0) FROM order_returns r JOIN orders o ON o.id = r.order_id JOIN branches b ON b.id = o.branch_id
     WHERE o.sold_by_id = ? AND DATE_FORMAT(r.created_at, '%Y-%m') = ? AND b.tenant_id = ?"
);
$ret->execute([$userId, $thang, $tenantId]);
$doanhSo = max(0, $doanhThu - (float) $ret->fetchColumn());
$luongGio = round($tongGio * (float) $me['hourly_wage']);
$hoaHong = $doanhSo * (float) $me['commission_percent'] / 100;

$openRow = $pdo->prepare(
    'SELECT work_date, time_in FROM attendance WHERE tenant_id = ? AND user_id = ? AND time_in IS NOT NULL AND time_out IS NULL
     AND work_date >= DATE_SUB(?, INTERVAL 1 DAY) ORDER BY work_date DESC LIMIT 1'
);
$openRow->execute([$tenantId, $userId, date('Y-m-d')]);
$openRow = $openRow->fetch();
$doneToday = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE tenant_id = ? AND user_id = ? AND work_date = ?');
$doneToday->execute([$tenantId, $userId, date('Y-m-d')]);
$hasToday = (int) $doneToday->fetchColumn() > 0;

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:4px;">Chấm công và lương của tôi</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">Bấm vào ca khi bắt đầu làm và ra ca khi kết thúc. Lương tạm tính = giờ công × lương/giờ + hoa hồng; số chính thức do Quản lý chốt ở Bảng lương.</p>

<?php if ($message): ?><div class="alert <?= preg_match('/ngoài mạng|cách cửa hàng|Cần cho phép|GPS chưa/u', $message) ? 'alert-error' : 'alert-success' ?>"><?= e($message) ?></div><?php endif; ?>

<div class="card" style="max-width:560px;margin-bottom:16px;">
  <div style="font-size:14px;margin-bottom:12px;">
    <?php if ($openRow): ?>
      Bạn đang trong ca, vào lúc <b><?= e(substr($openRow['time_in'], 0, 5)) ?></b> ngày <?= e(date('d/m/Y', strtotime($openRow['work_date']))) ?>.
    <?php elseif ($hasToday): ?>
      Hôm nay bạn đã chấm công xong.
    <?php else: ?>
      Bạn chưa chấm công hôm nay.
    <?php endif; ?>
  </div>
  <form method="post" id="ccForm" style="display:flex;gap:10px;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="lat" id="ccLat"><input type="hidden" name="lng" id="ccLng"><input type="hidden" name="acc" id="ccAcc"><input type="hidden" name="action" id="ccAction" disabled>
    <button type="submit" name="action" value="in" class="btn" <?= ($openRow || $hasToday) ? 'disabled style="opacity:.5;"' : '' ?>>Vào ca</button>
    <button type="submit" name="action" value="out" class="btn" <?= $openRow ? '' : 'disabled style="opacity:.5;"' ?>>Ra ca</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <div style="padding:16px 16px 0;">
    <form method="get">
      <label style="display:block;font-size:12px;margin-bottom:2px;">Tháng</label>
      <input type="month" name="thang" value="<?= e($thang) ?>" class="input" style="max-width:180px;" onchange="this.form.submit()">
    </form>
    <div style="display:flex;flex-wrap:wrap;gap:8px 28px;margin:14px 0;font-size:14px;">
      <div>Giờ công: <b><?= e(number_format($tongGio, 1, ',', '.')) ?></b></div>
      <div>Lương giờ (<?= e(money($me['hourly_wage'])) ?>/giờ): <b><?= e(money($luongGio)) ?></b></div>
      <div>Doanh số bán: <b><?= e(money($doanhSo)) ?></b></div>
      <div>Hoa hồng (<?= e(rtrim(rtrim(number_format((float) $me['commission_percent'], 2, '.', ''), '0'), '.')) ?>%): <b><?= e(money($hoaHong)) ?></b></div>
      <div>Tạm tính thực nhận: <b style="color:#16a34a;"><?= e(money($luongGio + $hoaHong)) ?></b></div>
    </div>
  </div>
  <table>
    <thead><tr><th>Ngày</th><th>Vào</th><th>Ra</th><th class="text-right">Giờ</th><th>Ghi chú</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center muted" style="padding:20px;">Chưa có chấm công trong tháng này.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(date('d/m/Y', strtotime($r['work_date']))) ?></td>
          <td><?= e($r['time_in'] ? substr($r['time_in'], 0, 5) : '—') ?></td>
          <td><?= e($r['time_out'] ? substr($r['time_out'], 0, 5) : '—') ?></td>
          <td class="text-right"><?= e(number_format(gioCongCuaToi($r['time_in'], $r['time_out']), 1, ',', '.')) ?></td>
          <td class="muted"><?= e((string) $r['note']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (getSetting('attendance_gps_lock', '0') === '1' && getSetting('store_lat', '') !== ''): ?>
<script>
// Cua hang bat xac minh GPS: lay vi tri truoc khi gui Vao ca / Ra ca.
document.getElementById('ccForm').addEventListener('submit', function (e) {
  const form = e.target;
  if (form.dataset.ready === '1') return;
  e.preventDefault();
  const act = e.submitter ? e.submitter.value : '';
  const status = document.getElementById('ccStatus');
  if (!navigator.geolocation) { alert('Thiết bị này không hỗ trợ định vị nên không chấm công được.'); return; }
  if (status) status.textContent = 'Đang lấy vị trí GPS...';
  navigator.geolocation.getCurrentPosition(function (pos) {
    document.getElementById('ccLat').value = pos.coords.latitude;
    document.getElementById('ccLng').value = pos.coords.longitude;
    document.getElementById('ccAcc').value = pos.coords.accuracy;
    const a = document.getElementById('ccAction'); a.disabled = false; a.value = act;
    form.dataset.ready = '1';
    form.submit();
  }, function (err) {
    if (status) status.textContent = '';
    alert(err.code === 1 ? 'Bạn đã từ chối quyền vị trí. Hãy cho phép vị trí cho trang này trong cài đặt trình duyệt để chấm công.' : 'Không lấy được vị trí, hãy bật GPS và thử lại.');
  }, { enableHighAccuracy: true, timeout: 15000 });
});
</script>
<div id="ccStatus" class="muted" style="font-size:12.5px;margin-top:6px;"></div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
