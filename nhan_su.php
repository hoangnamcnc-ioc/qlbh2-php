<?php
/**
 * Ho so nhan su: hop dong lao dong, dong bao hiem, nguoi phu thuoc, ma so thue, ngay phep nam.
 * Du lieu nay dung cho bang luong (khau tru bao hiem, thue TNCN) va nghi phep. Canh bao hop dong sap het han.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$loaiHD = loaiHopDong();
$nam = date('Y');

$emp = $pdo->prepare(
    'SELECT u.id, u.name, u.hourly_wage, h.user_id, h.contract_type, h.contract_number, h.contract_start, h.contract_end, h.insured, h.insured_salary, h.region, h.dependents, h.tax_code, h.annual_leave_days, h.note FROM users u LEFT JOIN hr_profiles h ON h.user_id = u.id
     WHERE u.tenant_id = ? AND u.is_active = 1 ORDER BY u.name'
);
$emp->execute([$tenantId]);
$emp = $emp->fetchAll();
$ids = array_map(static fn($e) => (int) $e['id'], $emp);

$laAdmin = hasRole('ADMIN');
$brStmt = $pdo->prepare('SELECT id, name FROM branches WHERE is_active = 1 AND tenant_id = ? ORDER BY name');
$brStmt->execute([$tenantId]);
$chiNhanh = $brStmt->fetchAll();
$them = !empty($_GET['them']) && $laAdmin;
$f = ['name' => '', 'email' => '', 'role' => 'CASHIER', 'branch_id' => 0, 'hourly_wage' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_employee' && $laAdmin) {
    checkCsrf();
    $them = true;
    $name = mb_substr(post('name'), 0, 100);
    $email = trim(strtolower(post('email')));
    $password = post('password');
    $role = in_array($_POST['role'] ?? '', ['CASHIER', 'MANAGER'], true) ? $_POST['role'] : 'CASHIER';
    $branchId = (int) ($_POST['branch_id'] ?? 0) ?: null;
    if ($branchId && !in_array($branchId, array_map('intval', array_column($chiNhanh, 'id')), true)) $branchId = null;
    $wage = max(0.0, postFloat('hourly_wage'));
    $type = array_key_exists($_POST['contract_type'] ?? '', $loaiHD) ? $_POST['contract_type'] : 'INDEFINITE';
    $start = (string) ($_POST['contract_start'] ?? '');
    $end = (string) ($_POST['contract_end'] ?? '');
    $okDate = static fn($d) => $d === '' || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    $insured = !empty($_POST['insured']) ? 1 : 0;
    $insSalary = max(0.0, postFloat('insured_salary'));
    $region = max(1, min(4, (int) ($_POST['region'] ?? 1)));
    $dep = max(0, min(20, (int) ($_POST['dependents'] ?? 0)));
    $leave = max(0.0, min(60.0, postFloat('annual_leave_days', 12)));
    $tax = preg_replace('/\D/', '', (string) ($_POST['tax_code'] ?? ''));
    $f = ['name' => $name, 'email' => $email, 'role' => $role, 'branch_id' => (int) $branchId, 'hourly_wage' => $wage];
    $dup = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $dup->execute([$email]);
    if ($name === '' || $email === '' || strlen($password) < 6) {
        $error = 'Vui lòng nhập đủ Họ tên, Email đăng nhập và Mật khẩu (tối thiểu 6 ký tự).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email không hợp lệ.';
    } elseif ($dup->fetch()) {
        $error = 'Email này đã được sử dụng.';
    } elseif (!$okDate($start) || !$okDate($end) || ($end !== '' && $start !== '' && $end < $start)) {
        $error = 'Ngày hợp đồng không hợp lệ (ngày hết hạn phải sau ngày bắt đầu).';
    } elseif ($type === 'FIXED' && $end === '') {
        $error = 'Hợp đồng có thời hạn cần ngày hết hạn.';
    } elseif ($insured && $insSalary <= 0) {
        $error = 'Đã chọn đóng bảo hiểm thì cần nhập lương đóng bảo hiểm.';
    } elseif ($tax !== '' && !in_array(strlen($tax), [10, 13], true)) {
        $error = 'Mã số thuế cá nhân gồm 10 số.';
    } else {
        $pdo->prepare('INSERT INTO users (name, email, password_hash, role, branch_id, tenant_id, hourly_wage, commission_percent) VALUES (?,?,?,?,?,?,?,0)')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $branchId, $tenantId, $wage]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO hr_profiles (tenant_id, user_id, contract_type, contract_number, contract_start, contract_end, insured, insured_salary, region, dependents, tax_code, annual_leave_days, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tenantId, $newId, $type, mb_substr(post('contract_number'), 0, 50) ?: null, $start ?: null, $end ?: null, $insured, $insSalary, $region, $dep, $tax ?: null, $leave, mb_substr(post('note'), 0, 255) ?: null]);
        logActivity('USER_CREATE', $email . ' (tu ho so nhan su)');
        redirect('nhan_su.php?added=1');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $uid = (int) ($_POST['user_id'] ?? 0);
    $type = array_key_exists($_POST['contract_type'] ?? '', $loaiHD) ? $_POST['contract_type'] : 'INDEFINITE';
    $start = (string) ($_POST['contract_start'] ?? '');
    $end = (string) ($_POST['contract_end'] ?? '');
    $okDate = static fn($d) => $d === '' || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    $insured = !empty($_POST['insured']) ? 1 : 0;
    $insSalary = max(0.0, postFloat('insured_salary'));
    $region = max(1, min(4, (int) ($_POST['region'] ?? 1)));
    $dep = max(0, min(20, (int) ($_POST['dependents'] ?? 0)));
    $leave = max(0.0, min(60.0, postFloat('annual_leave_days', 12)));
    $tax = preg_replace('/\D/', '', (string) ($_POST['tax_code'] ?? ''));
    if (!in_array($uid, $ids, true)) {
        $error = 'Không tìm thấy nhân viên.';
    } elseif (!$okDate($start) || !$okDate($end)) {
        $error = 'Ngày hợp đồng không hợp lệ.';
    } elseif ($end !== '' && $start !== '' && $end < $start) {
        $error = 'Ngày hết hạn hợp đồng phải sau ngày bắt đầu.';
    } elseif ($type === 'FIXED' && $end === '') {
        $error = 'Hợp đồng có thời hạn cần ngày hết hạn.';
    } elseif ($insured && $insSalary <= 0) {
        $error = 'Đã chọn đóng bảo hiểm thì cần nhập lương đóng bảo hiểm.';
    } elseif ($tax !== '' && !in_array(strlen($tax), [10, 13], true)) {
        $error = 'Mã số thuế cá nhân gồm 10 số.';
    } else {
        $pdo->prepare(
            'INSERT INTO hr_profiles (tenant_id, user_id, contract_type, contract_number, contract_start, contract_end, insured, insured_salary, region, dependents, tax_code, annual_leave_days, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE contract_type=VALUES(contract_type), contract_number=VALUES(contract_number), contract_start=VALUES(contract_start), contract_end=VALUES(contract_end),
               insured=VALUES(insured), insured_salary=VALUES(insured_salary), region=VALUES(region), dependents=VALUES(dependents), tax_code=VALUES(tax_code),
               annual_leave_days=VALUES(annual_leave_days), note=VALUES(note)'
        )->execute([$tenantId, $uid, $type, mb_substr(post('contract_number'), 0, 50) ?: null, $start ?: null, $end ?: null, $insured, $insSalary, $region, $dep, $tax ?: null, $leave, mb_substr(post('note'), 0, 255) ?: null]);
        logActivity('HR_PROFILE', "user=$uid type=$type");
        redirect('nhan_su.php?ok=1');
    }
}

$edit = (int) ($_GET['id'] ?? 0);
$homNay = date('Y-m-d');
$canhBao = [];
foreach ($emp as $e) {
    if (!$e['user_id']) {
        $canhBao[] = [$e['name'], 'chưa có hồ sơ nhân sự', 'info'];
        continue;
    }
    if ($e['contract_end']) {
        $left = (int) floor((strtotime($e['contract_end']) - strtotime($homNay)) / 86400);
        if ($left < 0) $canhBao[] = [$e['name'], 'hợp đồng đã hết hạn ' . date('d/m/Y', strtotime($e['contract_end'])), 'bad'];
        elseif ($left <= 30) $canhBao[] = [$e['name'], 'hợp đồng hết hạn sau ' . $left . ' ngày (' . date('d/m/Y', strtotime($e['contract_end'])) . ')', 'warn'];
    }
    if ($e['contract_type'] === 'PROBATION' && $e['contract_start'] && strtotime($e['contract_start'] . ' +2 months') < strtotime($homNay) && !$e['contract_end']) {
        $canhBao[] = [$e['name'], 'thử việc quá 2 tháng chưa có ngày kết thúc: nên ký hợp đồng chính thức', 'warn'];
    }
    if ($e['contract_type'] !== 'SEASONAL' && !(int) $e['insured'] && $e['contract_type'] !== 'PROBATION') {
        $canhBao[] = [$e['name'], 'chưa khai đóng bảo hiểm (hợp đồng từ 1 tháng trở lên thường phải đóng)', 'info'];
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">Hồ sơ nhân sự</h1>
  <?php if ($laAdmin): ?><a class="btn" href="nhan_su.php?them=1#them">+ Thêm hồ sơ nhân sự</a>
  <?php else: ?><span class="muted" style="font-size:12px;">Chỉ quản trị viên thêm được nhân viên mới.</span><?php endif; ?>
</div>
<p class="muted" style="margin:0 0 14px;max-width:780px;font-size:14px;">
  Hợp đồng, bảo hiểm, người phụ thuộc và ngày phép của từng nhân viên. Dữ liệu này dùng để khấu trừ bảo hiểm và thuế TNCN trong
  <a href="payroll.php">Bảng lương</a>, và tính ngày phép còn lại ở <a href="nghi_phep.php">Nghỉ phép</a>.
</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['added'])): ?><div class="alert alert-success">Đã thêm nhân viên và hồ sơ nhân sự. Nhân viên đăng nhập bằng email và mật khẩu vừa tạo.</div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Đã lưu hồ sơ.</div><?php endif; ?>

<?php if ($canhBao): ?>
<div class="card" style="margin-bottom:16px;">
  <div style="font-weight:600;margin-bottom:6px;">Cần chú ý</div>
  <?php foreach ($canhBao as [$ten, $nd, $muc]): ?>
    <div style="font-size:13px;padding:3px 0;color:<?= $muc === 'bad' ? '#b91c1c' : ($muc === 'warn' ? '#b45309' : '#475569') ?>;"><?= $muc === 'bad' ? '⛔' : ($muc === 'warn' ? '⚠' : '•') ?> <b><?= e($ten) ?></b>: <?= e($nd) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="overflow-x:auto;margin-bottom:16px;">
  <table>
    <thead><tr><th>Nhân viên</th><th>Hợp đồng</th><th>Hết hạn</th><th>Bảo hiểm</th><th style="text-align:right;">Phụ thuộc</th><th style="text-align:right;">Phép còn lại</th><th></th></tr></thead>
    <tbody>
    <?php if (!$emp): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:24px;">Chưa có nhân viên.</td></tr><?php endif; ?>
    <?php foreach ($emp as $e):
        $sd = $e['user_id'] ? soDuNghiPhep($pdo, (int) $e['id'], $nam, (float) $e['annual_leave_days']) : null; ?>
      <tr>
        <td><?= e($e['name']) ?></td>
        <td><?= $e['user_id'] ? e($loaiHD[$e['contract_type']] ?? '') . ($e['contract_number'] ? ' · ' . e($e['contract_number']) : '') : '<span class="muted">Chưa khai</span>' ?></td>
        <td><?= $e['contract_end'] ? e(date('d/m/Y', strtotime($e['contract_end']))) : '—' ?></td>
        <td><?= $e['user_id'] && (int) $e['insured'] ? money($e['insured_salary']) : '<span class="muted">Không</span>' ?></td>
        <td style="text-align:right;"><?= $e['user_id'] ? (int) $e['dependents'] : '—' ?></td>
        <td style="text-align:right;"><?= $sd ? rtrim(rtrim(number_format($sd['con_lai'], 1, ',', ''), '0'), ',') . '/' . rtrim(rtrim(number_format($sd['quy_dinh'], 1, ',', ''), '0'), ',') : '—' ?></td>
        <td><a class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" href="nhan_su.php?id=<?= (int) $e['id'] ?>#sua">Sửa hồ sơ</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($them): ?>
<div class="card" id="them" style="max-width:860px;margin-bottom:16px;">
  <div style="font-weight:600;margin-bottom:10px;">Thêm nhân viên và hồ sơ nhân sự</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="add_employee">
    <div><label>Họ và tên *</label><input class="input" name="name" value="<?= e($f['name']) ?>" required maxlength="100"></div>
    <div><label>Email đăng nhập *</label><input class="input" type="email" name="email" value="<?= e($f['email']) ?>" required></div>
    <div><label>Mật khẩu * (từ 6 ký tự)</label><input class="input" type="text" name="password" minlength="6" required autocomplete="new-password"></div>
    <div><label>Vai trò</label><select name="role"><option value="CASHIER" <?= $f['role'] === 'CASHIER' ? 'selected' : '' ?>>Thu ngân</option><option value="MANAGER" <?= $f['role'] === 'MANAGER' ? 'selected' : '' ?>>Quản lý</option></select></div>
    <div><label>Chi nhánh</label><select name="branch_id"><option value="0">Tất cả / chưa gán</option><?php foreach ($chiNhanh as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (int) $f['branch_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Lương theo giờ (đ)</label><input class="input" type="number" min="0" step="1" name="hourly_wage" value="<?= e((string) (int) $f['hourly_wage']) ?>"></div>
    <div><label>Loại hợp đồng</label><select name="contract_type"><?php foreach ($loaiHD as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Số hợp đồng</label><input class="input" name="contract_number"></div>
    <div><label>Ngày bắt đầu</label><input class="input" type="date" name="contract_start" value="<?= e(date('Y-m-d')) ?>"></div>
    <div><label>Ngày hết hạn (nếu có)</label><input class="input" type="date" name="contract_end"></div>
    <div><label><input type="checkbox" name="insured" value="1"> Đóng BHXH, BHYT, BHTN</label></div>
    <div><label>Lương đóng bảo hiểm</label><input class="input" type="number" min="0" step="1" name="insured_salary" value="0"></div>
    <div><label>Vùng (lương tối thiểu)</label><select name="region"><?php for ($r = 1; $r <= 4; $r++): ?><option value="<?= $r ?>">Vùng <?= $r ?></option><?php endfor; ?></select></div>
    <div><label>Số người phụ thuộc</label><input class="input" type="number" min="0" max="20" name="dependents" value="0"></div>
    <div><label>Mã số thuế cá nhân</label><input class="input" name="tax_code"></div>
    <div><label>Ngày phép năm</label><input class="input" type="number" min="0" max="60" step="0.5" name="annual_leave_days" value="12"></div>
    <div style="grid-column:1/-1;"><label>Ghi chú</label><input class="input" name="note"></div>
    <div><button class="btn" type="submit">Thêm nhân viên</button> <a class="btn btn-secondary" href="nhan_su.php">Hủy</a></div>
  </form>
  <p class="muted" style="font-size:12px;margin:10px 0 0;">Tài khoản được tạo cùng lúc để nhân viên đăng nhập, chấm công, xem lương. Phân quyền chi tiết hơn ở <a href="users.php">Nhân viên &amp; phân quyền</a>.</p>
</div>
<?php endif; ?>

<?php foreach ($emp as $e): if ((int) $e['id'] !== $edit) continue; ?>
<div class="card" id="sua" style="max-width:820px;">
  <div style="font-weight:600;margin-bottom:10px;">Hồ sơ của <?= e($e['name']) ?></div>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="user_id" value="<?= (int) $e['id'] ?>">
    <div><label>Loại hợp đồng</label><select name="contract_type"><?php foreach ($loaiHD as $k => $v): ?><option value="<?= e($k) ?>" <?= ($e['contract_type'] ?? 'INDEFINITE') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Số hợp đồng</label><input class="input" name="contract_number" value="<?= e($e['contract_number'] ?? '') ?>"></div>
    <div><label>Ngày bắt đầu</label><input class="input" type="date" name="contract_start" value="<?= e($e['contract_start'] ?? '') ?>"></div>
    <div><label>Ngày hết hạn (nếu có)</label><input class="input" type="date" name="contract_end" value="<?= e($e['contract_end'] ?? '') ?>"></div>
    <div><label><input type="checkbox" name="insured" value="1" <?= !empty($e['insured']) ? 'checked' : '' ?>> Đóng BHXH, BHYT, BHTN</label></div>
    <div><label>Lương đóng bảo hiểm</label><input class="input" type="number" min="0" step="1" name="insured_salary" value="<?= e((string) (int) ($e['insured_salary'] ?? 0)) ?>"></div>
    <div><label>Vùng (lương tối thiểu)</label><select name="region"><?php for ($r = 1; $r <= 4; $r++): ?><option value="<?= $r ?>" <?= (int) ($e['region'] ?? 1) === $r ? 'selected' : '' ?>>Vùng <?= $r ?></option><?php endfor; ?></select></div>
    <div><label>Số người phụ thuộc</label><input class="input" type="number" min="0" max="20" name="dependents" value="<?= (int) ($e['dependents'] ?? 0) ?>"></div>
    <div><label>Mã số thuế cá nhân</label><input class="input" name="tax_code" value="<?= e($e['tax_code'] ?? '') ?>"></div>
    <div><label>Ngày phép năm</label><input class="input" type="number" min="0" max="60" step="0.5" name="annual_leave_days" value="<?= e((string) ($e['annual_leave_days'] ?? 12)) ?>"></div>
    <div style="grid-column:1/-1;"><label>Ghi chú</label><input class="input" name="note" value="<?= e($e['note'] ?? '') ?>"></div>
    <div><button class="btn" type="submit">Lưu hồ sơ</button> <a class="btn btn-secondary" href="nhan_su.php">Đóng</a></div>
  </form>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
