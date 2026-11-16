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
    if ($phut == 0) return 0; // vao va ra cung phut = chua lam gi (khong tinh thanh 24 gio)
    if ($phut < 0) $phut += 24 * 60; // ca qua dem
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

$profStmt = $pdo->prepare('SELECT * FROM hr_profiles WHERE tenant_id = ?');
$profStmt->execute([$tenantId]);
$profiles = [];
foreach ($profStmt->fetchAll() as $pr) { $profiles[(int) $pr['user_id']] = $pr; }
$thamSo = thamSoLuong();

$rows = [];
$tongThucNhan = 0;
$tongBhNld = 0;
$tongThue = 0;
$tongLinh = 0;
$tongBhDn = 0;
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

    // Khau tru bao hiem va thue TNCN theo ho so nhan su (neu da khai). Chua co ho so thi khong khau tru.
    $pr = $profiles[(int) $emp['id']] ?? null;
    $bhNld = 0;
    $bhDn = 0;
    $thue = 0;
    if ($pr && (int) $pr['insured'] && (float) $pr['insured_salary'] > 0) {
        $bh = tinhBaoHiem((float) $pr['insured_salary'], (int) $pr['region']);
        $bhNld = $bh['nld'];
        $bhDn = $bh['dn'];
    }
    if ($pr && $pr['contract_type'] !== 'SEASONAL') {
        $giamTru = $thamSo['giam_tru_ban_than'] + (int) $pr['dependents'] * $thamSo['giam_tru_phu_thuoc'];
        $thue = thueTncnTienLuong(max(0.0, $thucNhan - $bhNld - $giamTru));
    } elseif ($pr) {
        // Thoi vu: khau tru 10% khi thu nhap tu 2 trieu/lan neu khong co cam ket (don gian hoa, ke toan can doi chieu)
        $thue = $thucNhan >= 2000000 ? (int) round($thucNhan * 0.10) : 0;
    }
    $linh = $thucNhan - $bhNld - $thue;
    $tongBhNld += $bhNld;
    $tongBhDn += $bhDn;
    $tongThue += $thue;
    $tongLinh += $linh;

    $rows[] = [
        'name' => $emp['name'],
        'gio_cong' => $gioCong,
        'hourly_wage' => (float) $emp['hourly_wage'],
        'luong_gio' => $luongGioThanhTien,
        'doanh_so' => $doanhSo,
        'commission_percent' => (float) $emp['commission_percent'],
        'hoa_hong' => $hoaHong,
        'thuc_nhan' => $thucNhan,
        'bh_nld' => $bhNld, 'bh_dn' => $bhDn, 'thue' => $thue, 'linh' => $linh, 'co_ho_so' => (bool) $pr,
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
        <th class="text-right">% Hoa hồng</th><th class="text-right">Hoa hồng</th><th class="text-right">Tổng thu nhập</th>
        <th class="text-right">Trừ bảo hiểm</th><th class="text-right">Thuế TNCN</th><th class="text-right">Thực lĩnh</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="11" class="muted" style="text-align:center;padding:24px;">Chưa có nhân viên nào đang hoạt động.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td class="text-right"><?= number_format($r['gio_cong'], 1) ?></td>
          <td class="text-right muted"><?= money($r['hourly_wage']) ?></td>
          <td class="text-right"><?= money($r['luong_gio']) ?></td>
          <td class="text-right muted"><?= money($r['doanh_so']) ?></td>
          <td class="text-right muted"><?= number_format($r['commission_percent'], 1) ?>%</td>
          <td class="text-right"><?= money($r['hoa_hong']) ?></td>
          <td class="text-right"><?= money($r['thuc_nhan']) ?></td>
          <td class="text-right muted"><?= $r['bh_nld'] ? '− ' . money($r['bh_nld']) : ($r['co_ho_so'] ? '—' : '<a href="nhan_su.php">chưa khai hồ sơ</a>') ?></td>
          <td class="text-right muted"><?= $r['thue'] ? '− ' . money($r['thue']) : '—' ?></td>
          <td class="text-right" style="font-weight:700;color:#2563eb;"><?= money($r['linh']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
      <tfoot><tr style="border-top:1px solid #e2e8f0;"><td colspan="7" style="font-weight:700;">Tổng tháng <?= e($thang) ?></td><td class="text-right" style="font-weight:700;"><?= money($tongThucNhan) ?></td><td class="text-right" style="font-weight:700;">− <?= money($tongBhNld) ?></td><td class="text-right" style="font-weight:700;">− <?= money($tongThue) ?></td><td class="text-right" style="font-weight:700;color:#2563eb;"><?= money($tongLinh) ?></td></tr></tfoot>
    <?php endif; ?>
  </table>
</div>

<?php if ($rows): ?>
<div class="card" style="margin-top:16px;max-width:760px;font-size:13px;">
  <b>Doanh nghiệp đóng thêm:</b> bảo hiểm phần của chủ <?= money($tongBhDn) ?>. Tổng chi phí nhân sự tháng này = tổng thu nhập + bảo hiểm phần của chủ = <b><?= money($tongThucNhan + $tongBhDn) ?></b>.
  <div class="muted" style="margin-top:6px;">
    Bảo hiểm người lao động 10,5% (BHXH 8%, BHYT 1,5%, BHTN 1%) trên lương đóng bảo hiểm trong hồ sơ nhân sự; thuế TNCN lũy tiến từng phần sau giảm trừ bản thân 15,5 triệu và người phụ thuộc 6,2 triệu/người.
    Nhân viên chưa khai hồ sơ ở <a href="nhan_su.php">Hồ sơ nhân sự</a> thì chưa khấu trừ. Tham số theo quy định 2026, kế toán cần đối chiếu văn bản hiện hành trước khi trả lương. Chi lương trả bằng tiền từ két nhớ ghi ở
    <a href="chi_phi.php">Chi phí kinh doanh</a> (nhóm Lương nhân viên) để tính vào chi phí được trừ.
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
