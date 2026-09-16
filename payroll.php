<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();

$thang = $_GET['thang'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $thang)) $thang = date('Y-m');

function tinhGioCong(?string $gioVao, ?string $gioRa): float
{
    if (!$gioVao || !$gioRa) return 0;
    [$hV, $mV] = array_map('intval', explode(':', $gioVao));
    [$hR, $mR] = array_map('intval', explode(':', $gioRa));
    $phut = ($hR * 60 + $mR) - ($hV * 60 + $mV);
    if ($phut <= 0) $phut += 24 * 60; // ca qua dem
    return $phut / 60;
}

$employeesStmt = $pdo->prepare(
    "SELECT id, name, hourly_wage, commission_percent FROM users WHERE tenant_id = ? AND is_active = 1 ORDER BY name"
);
$employeesStmt->execute([$tenantId]);
$employees = $employeesStmt->fetchAll();

// Doanh so tinh hoa hong = doanh thu don da hoan thanh do chinh nhan vien ban ra TRU DI phan da
// bi tra lai trong thang - tranh tinh hoa hong tren doanh thu khach da tra hang, dung theo mau
// da ap dung o QLBH-SOFT. Loc them qua branches de dam bao dung tenant (phong thu du).
$revenueStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(o.total_amount), 0) AS doanh_thu
     FROM orders o JOIN branches b ON b.id = o.branch_id
     WHERE o.sold_by_id = ? AND o.status = 'COMPLETED' AND DATE_FORMAT(o.created_at, '%Y-%m') = ? AND b.tenant_id = ?"
);
$returnStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(r.refund_amount), 0) AS tra_hang
     FROM order_returns r
     JOIN orders o ON o.id = r.order_id
     JOIN branches b ON b.id = o.branch_id
     WHERE o.sold_by_id = ? AND DATE_FORMAT(r.created_at, '%Y-%m') = ? AND b.tenant_id = ?"
);
$attendanceStmt = $pdo->prepare(
    "SELECT time_in, time_out FROM attendance WHERE tenant_id = ? AND user_id = ? AND work_date LIKE ?"
);

$rows = [];
$tongThucNhan = 0;
foreach ($employees as $emp) {
    $revenueStmt->execute([$emp['id'], $thang, $tenantId]);
    $doanhThuGop = (float) $revenueStmt->fetch()['doanh_thu'];
    $returnStmt->execute([$emp['id'], $thang, $tenantId]);
    $traHang = (float) $returnStmt->fetch()['tra_hang'];
    $doanhSo = max(0, $doanhThuGop - $traHang);
    $hoaHong = $doanhSo * (float) $emp['commission_percent'] / 100;

    $attendanceStmt->execute([$tenantId, $emp['id'], $thang . '%']);
    $gioCong = 0;
    foreach ($attendanceStmt->fetchAll() as $a) {
        $gioCong += tinhGioCong($a['time_in'], $a['time_out']);
    }
    $luongGioThanhTien = round($gioCong * (float) $emp['hourly_wage']);
    $thucNhan = $luongGioThanhTien + $hoaHong;
    $tongThucNhan += $thucNhan;

    $rows[] = [
        'name' => $emp['name'],
        'gio_cong' => $gioCong,
        'hourly_wage' => (float) $emp['hourly_wage'],
        'luong_gio' => $luongGioThanhTien,
        'doanh_so' => $doanhSo,
        'commission_percent' => (float) $emp['commission_percent'],
        'hoa_hong' => $hoaHong,
        'thuc_nhan' => $thucNhan,
    ];
}

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:4px;">Bảng lương</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Thực nhận = (Tổng giờ công trong tháng, lấy từ Bảng chấm công) × Lương/giờ + (Doanh số bán trong
  tháng − doanh thu đã trả hàng) × Tỷ lệ hoa hồng. Không dùng lương cơ bản cố định — ngày nào không
  chấm công thì ngày đó tính 0 giờ. Cấu hình lương/giờ và % hoa hồng cho từng nhân viên tại trang
  <a href="users.php">Nhân viên và phân quyền</a>.
</p>

<div class="card" style="padding:0;overflow-x:auto;">
  <div style="padding:16px 16px 0;">
    <form method="get">
      <label style="display:block;font-size:12px;margin-bottom:2px;">Tháng</label>
      <input type="month" name="thang" value="<?= e($thang) ?>" class="input" style="max-width:180px;" onchange="this.form.submit()">
    </form>
  </div>
  <table style="margin-top:12px;">
    <thead>
      <tr>
        <th>Họ tên</th><th class="text-right">Giờ công</th><th class="text-right">Lương/giờ</th>
        <th class="text-right">Thành tiền lương giờ</th><th class="text-right">Doanh số bán</th>
        <th class="text-right">% Hoa hồng</th><th class="text-right">Hoa hồng</th><th class="text-right">Thực nhận</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="8" class="muted" style="text-align:center;padding:24px;">Chưa có nhân viên nào đang hoạt động.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td class="text-right"><?= number_format($r['gio_cong'], 1) ?></td>
          <td class="text-right muted"><?= money($r['hourly_wage']) ?></td>
          <td class="text-right"><?= money($r['luong_gio']) ?></td>
          <td class="text-right muted"><?= money($r['doanh_so']) ?></td>
          <td class="text-right muted"><?= number_format($r['commission_percent'], 1) ?>%</td>
          <td class="text-right"><?= money($r['hoa_hong']) ?></td>
          <td class="text-right" style="font-weight:700;color:#2563eb;"><?= money($r['thuc_nhan']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
      <tfoot><tr style="border-top:1px solid #e2e8f0;"><td colspan="7" style="font-weight:700;">Tổng chi lương tháng <?= e($thang) ?></td><td class="text-right" style="font-weight:700;color:#2563eb;"><?= money($tongThucNhan) ?></td></tr></tfoot>
    <?php endif; ?>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
