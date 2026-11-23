<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');
require_once __DIR__ . '/inc_xlsx.php';

$pdo = db();

// Căn cứ pháp lý (đối chiếu trực tiếp văn bản gốc):
//   - Nghị định 68/2026/NĐ-CP ngày 05/03/2026 (Điều 3, Điều 4): chính sách thuế hộ kinh
//     doanh, cá nhân kinh doanh — thay thế Thông tư 40/2021/TT-BTC và 100/2021/TT-BTC.
//   - Nghị định 141/2026/NĐ-CP (hiệu lực 01/01/2026), Điều 1 khoản 1: sửa "500 triệu đồng"
//     thành "01 tỷ đồng" tại Điều 3, Điều 4 NĐ 68/2026 — ngưỡng miễn thuế HIỆN HÀNH là 1 tỷ.
//   - Luật Thuế GTGT 48/2024/QH15, Điều 12 khoản 2 điểm b1 (phân phối, cung cấp hàng hóa): 1%.
//   - Luật Thuế TNCN 109/2025/QH15, Điều 7 khoản 3 điểm b (phân phối, cung cấp hàng hóa): 0,5%,
//     áp dụng khi doanh thu năm trên ngưỡng miễn thuế đến 3 tỷ đồng (khoản 3 điểm a).
//     Trên 3 tỷ: bắt buộc tính theo (doanh thu - chi phí được trừ) x thuế suất lũy tiến
//     (Điều 7 khoản 2) — phần mềm không có dữ liệu chi phí được trừ nên không tự tính.
//   - Thông tư 152/2025/TT-BTC ngày 31/12/2025: hướng dẫn kế toán hộ kinh doanh — Điều 4
//     (doanh thu <= ngưỡng miễn thuế): Mẫu S1a-HKD (Ngày tháng/Diễn giải/Số tiền, không có
//     cột thuế). Điều 5 (doanh thu > ngưỡng): Mẫu S2a-HKD, ghi theo từng nhóm ngành nghề
//     cùng tỷ lệ % tính thuế, có cột thuế GTGT/TNCN từng nhóm.
// Các tỷ lệ dưới đây CHỈ áp dụng cho nhóm "phân phối, cung cấp hàng hóa" (bán lẻ hàng hóa
// thông thường) — nếu cửa hàng có thêm ngành nghề khác (dịch vụ, sản xuất...) thì phần
// doanh thu ngành đó cần áp tỷ lệ khác theo đúng luật, phần mềm chưa phân biệt được.
const NGUONG_MIEN_THUE_NAM = 1000000000;
const MUC_TRAN_TY_LE_TNCN = 3000000000;
const TY_LE_GTGT_HANG_HOA = 0.01;
const TY_LE_TNCN_HANG_HOA = 0.005;

$nam = $_GET['nam'] ?? date('Y');
if (!preg_match('/^\d{4}$/', $nam)) $nam = date('Y');

$tenantId = currentTenantId();

// Danh dau da nop to khai / thong bao doanh thu (chi ghi nho de nhac viec, khong gui gi cho co quan thue)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tax_done') {
    checkCsrf();
    $k = (string) ($_POST['k'] ?? '');
    if (preg_match('/^\d{4}(Q[1-4]|N)$/', $k)) {
        setSetting('tax_done_' . $k, !empty($_POST['done']) ? date('Y-m-d') : '');
        logActivity('TAX_DONE', $k . ' ' . (!empty($_POST['done']) ? 'done' : 'undone'));
    }
    redirect('accounting.php?nam=' . urlencode((string) $nam) . '#han-nop');
}

$rows = $pdo->prepare(
    "SELECT MONTH(o.created_at) AS thang, COALESCE(SUM(o.total_amount),0) AS doanh_thu
     FROM orders o JOIN branches b ON b.id = o.branch_id
     WHERE YEAR(o.created_at) = ? AND o.status NOT IN ('CANCELLED','DRAFT') AND b.tenant_id = ?
     GROUP BY MONTH(o.created_at)"
);
$rows->execute([$nam, $tenantId]);
$byMonth = array_fill(1, 12, 0.0);
foreach ($rows->fetchAll() as $r) {
    $byMonth[(int) $r['thang']] = (float) $r['doanh_thu'];
}
// Doanh thu tinh thue la doanh thu THUAN: tru tien hang ban bi tra lai (theo thang phat sinh tra hang).
$retRows = $pdo->prepare(
    "SELECT MONTH(r.created_at) AS thang, COALESCE(SUM(r.refund_amount),0) AS tra_hang
     FROM order_returns r JOIN orders o ON o.id = r.order_id JOIN branches b ON b.id = o.branch_id
     WHERE YEAR(r.created_at) = ? AND b.tenant_id = ? GROUP BY MONTH(r.created_at)"
);
$retRows->execute([$nam, $tenantId]);
foreach ($retRows->fetchAll() as $r) {
    $byMonth[(int) $r['thang']] = max(0.0, $byMonth[(int) $r['thang']] - (float) $r['tra_hang']);
}
$tongDoanhThuNam = array_sum($byMonth);
$vuotNguong = $tongDoanhThuNam > NGUONG_MIEN_THUE_NAM;

$ucTinh = null;
if ($vuotNguong) {
    if ($tongDoanhThuNam <= MUC_TRAN_TY_LE_TNCN) {
        $gtgt = round($tongDoanhThuNam * TY_LE_GTGT_HANG_HOA);
        $doanhThuTinhTncn = $tongDoanhThuNam - NGUONG_MIEN_THUE_NAM;
        $tncn = round($doanhThuTinhTncn * TY_LE_TNCN_HANG_HOA);
        $ucTinh = ['ap_dung' => true, 'gtgt' => $gtgt, 'doanh_thu_tncn' => $doanhThuTinhTncn, 'tncn' => $tncn, 'tong' => $gtgt + $tncn];
    } else {
        $ucTinh = ['ap_dung' => false];
    }
}

// Tinh TNCN theo thu nhap (doanh thu - gia von - chi phi duoc tru): bat buoc tren 3 ty, tuy chon trong khoang 1-3 ty.
$cpNam = tongHopChiPhiNam($pdo, $tenantId, $nam);
$thuNhapNam = max(0.0, $tongDoanhThuNam - $cpNam['gia_von'] - $cpNam['chi_phi_duoc_tru']);
$thueSuatThuNhap = thueSuatTncnThuNhap($tongDoanhThuNam);
$tncnThuNhap = $vuotNguong ? round($thuNhapNam * $thueSuatThuNhap) : 0;
$gtgtNam = round($tongDoanhThuNam * TY_LE_GTGT_HANG_HOA);

// Lich han ke khai: quy nop truoc ngay cuoi cua thang dau quy ke tiep; thong bao doanh thu nam truoc 31/1 nam sau.
$lichHan = [];
$homNay = date('Y-m-d');
foreach ([1 => [$nam . '-04-30', 'Quý 1'], 2 => [$nam . '-07-31', 'Quý 2'], 3 => [$nam . '-10-31', 'Quý 3'], 4 => [((int) $nam + 1) . '-01-31', 'Quý 4']] as $q => [$han, $ten]) {
    $key = $nam . 'Q' . $q;
    $lichHan[] = ['key' => $key, 'han' => $han, 'ten' => 'Khai và nộp thuế GTGT, TNCN ' . $ten . '/' . $nam, 'bat_buoc' => $vuotNguong, 'xong' => getSetting('tax_done_' . $key, '')];
}
$lichHan[] = ['key' => $nam . 'N', 'han' => ((int) $nam + 1) . '-01-31', 'ten' => 'Thông báo doanh thu năm ' . $nam . ' (hộ dưới ngưỡng cũng phải nộp)', 'bat_buoc' => true, 'xong' => getSetting('tax_done_' . $nam . 'N', '')];

// Nhom nop thue khi da vuot nguong (chi ho kinh doanh tu chon, phan mem khong the tu suy ra):
//   Nhom 2: GTGT + TNCN deu tinh theo ty le (%) tren doanh thu -> dung mau S2a-HKD.
//   Nhom 3: GTGT theo ty le (%) nhung TNCN tinh theo thu nhap (doanh thu - chi phi duoc tru)
//   -> dung mau S2b-HKD (so doanh thu) + S2c-HKD (doanh thu, chi phi) + S2d-HKD (vat lieu, hang
//   hoa) + S2e-HKD (so tien), theo dung huong dan tai Thong tu 152/2025/TT-BTC.
if (isset($_GET['nhom']) && in_array($_GET['nhom'], ['2', '3'], true)) {
    setSetting('accounting_tax_group', $_GET['nhom']);
}
$nhomThue = getSetting('accounting_tax_group', '2') === '3' ? '3' : '2';

// Sổ doanh thu theo mẫu S1a-HKD / S2a-HKD / S2b-HKD (Thông tư 152/2025/TT-BTC)
$tuNgay = $_GET['tu'] ?? date('Y-m-01');
$denNgay = $_GET['den'] ?? date('Y-m-d');
$mauSo = !$vuotNguong ? 'S1a-HKD' : ($nhomThue === '3' ? 'S2b-HKD' : 'S2a-HKD');
$hienCotThue = $vuotNguong && $nhomThue === '2';

$soRows = $pdo->prepare(
    "SELECT o.code, DATE(o.created_at) AS ngay, c.name AS khach_hang,
            COALESCE(cat.name, 'Chưa phân loại') AS nhom,
            oi.line_total * (1 - IF(o.sub_total > 0, o.discount / o.sub_total, 0)) AS line_total
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     JOIN branches b ON b.id = o.branch_id
     LEFT JOIN customers c ON c.id = o.customer_id
     JOIN products p ON p.id = oi.product_id
     LEFT JOIN categories cat ON cat.id = p.category_id
     WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status NOT IN ('CANCELLED','DRAFT') AND b.tenant_id = ?
     ORDER BY cat.name, o.created_at"
);
$soRows->execute([$tuNgay, $denNgay, $tenantId]);
$nhomMap = [];
foreach ($soRows->fetchAll() as $r) {
    $nhomMap[$r['nhom']][] = $r;
}
$tongTatCa = 0;
foreach ($nhomMap as $dong) {
    foreach ($dong as $d) $tongTatCa += (float) $d['line_total'];
}
// Hang ban bi tra lai trong ky: ghi giam doanh thu (dong am) de so khop voi doanh thu thuan.
$traRows = $pdo->prepare(
    "SELECT r.code, DATE(r.created_at) AS ngay, r.refund_amount
     FROM order_returns r JOIN orders o ON o.id = r.order_id JOIN branches b ON b.id = o.branch_id
     WHERE DATE(r.created_at) BETWEEN ? AND ? AND b.tenant_id = ? ORDER BY r.created_at"
);
$traRows->execute([$tuNgay, $denNgay, $tenantId]);
$traHang = $traRows->fetchAll();
$tongTra = array_sum(array_map(static fn($r) => (float) $r['refund_amount'], $traHang));

// Xuat Excel: doanh thu theo thang (doanh thu thuan), uoc tinh thue, so doanh thu chi tiet va hang tra lai trong ky.
if (isset($_GET['export'])) {
    $storeName = getSetting('store_name', 'Cửa hàng');
    $rowsX = [
        ['SỔ DOANH THU VÀ ƯỚC TÍNH THUẾ HỘ KINH DOANH', '', '', '', ''],
        ['Lưu ý: số liệu tham khảo từ dữ liệu bạn nhập, không thay thế tư vấn của kế toán/cơ quan thuế.', '', '', '', ''],
        ['Cửa hàng', $storeName, '', '', ''],
        ['Mẫu sổ', $mauSo . ' (Thông tư 152/2025/TT-BTC)', '', '', ''],
        ['Kỳ sổ chi tiết', date('d/m/Y', strtotime($tuNgay)) . ' - ' . date('d/m/Y', strtotime($denNgay)), '', '', ''],
        ['Doanh thu thuần năm ' . $nam . ' (đã trừ chiết khấu và hàng trả lại)', (string) round($tongDoanhThuNam), '', '', ''],
        ['Ngưỡng miễn thuế', (string) NGUONG_MIEN_THUE_NAM, '', '', ''],
        ['Trạng thái', $vuotNguong ? 'Vượt ngưỡng miễn thuế' : 'Dưới ngưỡng - miễn thuế', '', '', ''],
    ];
    if ($ucTinh && !empty($ucTinh['ap_dung'])) {
        $rowsX[] = ['Thuế GTGT ước tính', (string) $ucTinh['gtgt'], '', '', ''];
        $rowsX[] = ['Thuế TNCN ước tính', (string) $ucTinh['tncn'], '', '', ''];
        $rowsX[] = ['Tổng thuế ước tính', (string) $ucTinh['tong'], '', '', ''];
    }
    $rowsX[] = ['', '', '', '', ''];
    $rowsX[] = ['DOANH THU THEO THÁNG', 'Doanh thu thuần', '', '', ''];
    foreach ($byMonth as $m => $v) {
        $rowsX[] = ['Tháng ' . $m . '/' . $nam, (string) round($v), '', '', ''];
    }
    $rowsX[] = ['', '', '', '', ''];
    $rowsX[] = ['SỔ DOANH THU CHI TIẾT (sau chiết khấu)', '', '', '', ''];
    $rowsX[] = ['Số hiệu', 'Ngày', 'Nhóm hàng', 'Diễn giải', 'Số tiền'];
    foreach ($nhomMap as $tenNhom => $dong) {
        foreach ($dong as $d) {
            $rowsX[] = [$d['code'], date('d/m/Y', strtotime($d['ngay'])), $tenNhom,
                'Bán hàng hóa, dịch vụ' . ($d['khach_hang'] ? ' - ' . $d['khach_hang'] : ' - Khách lẻ'), (string) round((float) $d['line_total'])];
        }
    }
    foreach ($traHang as $t) {
        $rowsX[] = [$t['code'], date('d/m/Y', strtotime($t['ngay'])), '', 'Khách trả lại hàng (ghi giảm doanh thu)', (string) -round((float) $t['refund_amount'])];
    }
    $rowsX[] = ['', '', '', 'TỔNG DOANH THU THUẦN TRONG KỲ', (string) round(max(0, $tongTatCa - $tongTra))];
    logActivity('EXPORT_ACCOUNTING', "nam=$nam tu=$tuNgay den=$denNgay");
    downloadXlsx($rowsX, 'so_doanh_thu_' . $nam . '.xlsx');
    exit;
}

require_once __DIR__ . '/inc_header.php';
?>

<?= thanhTabThue() ?>
<h1 style="font-size:24px;font-weight:600;margin-bottom:16px;">Kế toán và Thuế</h1>

<div class="card" style="margin-bottom:24px;">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 12px;">Ước tính thuế hộ kinh doanh</h2>
  <p class="muted" style="font-size:13px;margin:0 0 12px;">
    Áp dụng cho trường hợp 100% doanh thu là bán hàng hóa thông thường (nhóm "phân phối, cung
    cấp hàng hóa" — GTGT 1%, TNCN 0,5% theo Luật Thuế GTGT 48/2024/QH15 và Luật Thuế TNCN
    109/2025/QH15, ngưỡng miễn thuế 1 tỷ đồng/năm theo Nghị định 68/2026/NĐ-CP đã sửa bởi
    Nghị định 141/2026/NĐ-CP). Nếu cửa hàng có thêm ngành nghề khác hoặc số liệu chưa chính
    xác, vui lòng đối chiếu với cơ quan thuế/kế toán viên — đây chỉ là số ước tính tham khảo.
  </p>
  <form method="get" style="display:flex;gap:8px;align-items:end;margin-bottom:16px;">
    <div><label style="display:block;font-size:12px;margin-bottom:2px;">Năm</label>
      <input type="number" name="nam" value="<?= e($nam) ?>" class="input" style="width:100px;"></div>
    <button type="submit" class="btn btn-secondary">Xem</button>
  </form>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px;">
    <div class="card">
      <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Doanh thu năm <?= e($nam) ?></div>
      <div style="font-size:18px;font-weight:700;"><?= money($tongDoanhThuNam) ?></div>
    </div>
    <div class="card">
      <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Ngưỡng miễn thuế</div>
      <div style="font-size:18px;font-weight:700;"><?= money(NGUONG_MIEN_THUE_NAM) ?></div>
    </div>
    <div class="card">
      <div class="muted" style="font-size:11px;text-transform:uppercase;margin-bottom:4px;">Trạng thái</div>
      <div style="font-size:16px;font-weight:700;<?= $vuotNguong ? 'color:#dc2626;' : 'color:#059669;' ?>">
        <?= $vuotNguong ? 'Vượt ngưỡng — phải nộp thuế' : 'Dưới ngưỡng — miễn thuế' ?>
      </div>
    </div>
  </div>

  <?php if ($ucTinh && $ucTinh['ap_dung']): ?>
    <table>
      <tbody>
        <tr><td>Thuế GTGT ước tính (1% × tổng doanh thu năm)</td><td class="text-right" style="font-weight:600;"><?= money($ucTinh['gtgt']) ?></td></tr>
        <tr><td>Doanh thu tính TNCN (phần vượt ngưỡng miễn thuế)</td><td class="text-right"><?= money($ucTinh['doanh_thu_tncn']) ?></td></tr>
        <tr><td>Thuế TNCN ước tính (0,5% × doanh thu tính TNCN)</td><td class="text-right" style="font-weight:600;"><?= money($ucTinh['tncn']) ?></td></tr>
        <tr style="border-top:1px solid #e2e8f0;"><td style="font-weight:700;padding-top:8px;">Tổng thuế ước tính phải nộp</td><td class="text-right" style="font-weight:700;color:#2563eb;padding-top:8px;"><?= money($ucTinh['tong']) ?></td></tr>
      </tbody>
    </table>
  <?php elseif ($ucTinh && !$ucTinh['ap_dung']): ?>
    <div class="alert alert-warning">
      Doanh thu năm đã vượt <?= money(MUC_TRAN_TY_LE_TNCN) ?> — theo Điều 7 khoản 2 Luật Thuế
      TNCN 109/2025/QH15, thuế TNCN phải tính theo phương pháp (doanh thu trừ chi phí được
      trừ) nhân thuế suất lũy tiến 15%/17%/20%. Xem phần "TNCN theo thu nhập" bên dưới: phần mềm tính từ
      giá vốn và chi phí đã phân loại; đối chiếu với cơ quan thuế/kế toán viên trước khi kê khai.
    </div>
  <?php endif; ?>

  <table style="margin-top:16px;">
    <thead><tr><th>Tháng</th><th class="text-right">Doanh thu</th></tr></thead>
    <tbody>
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <tr><td><?= e($nam) ?>-<?= str_pad((string) $m, 2, '0', STR_PAD_LEFT) ?></td><td class="text-right"><?= money($byMonth[$m]) ?></td></tr>
      <?php endfor; ?>
    </tbody>
  </table>
</div>

<?php if ($vuotNguong): ?>
<div class="card" style="margin-bottom:24px;">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 8px;">TNCN theo thu nhập (doanh thu trừ chi phí)</h2>
  <p class="muted" style="font-size:13px;margin:0 0 12px;">
    Bắt buộc khi doanh thu năm trên <?= money(MUC_TRAN_TY_LE_TNCN) ?>; từ 1 đến 3 tỷ bạn được chọn cách có lợi hơn.
    Chi phí lấy từ giá vốn hàng bán (trừ hàng trả lại) và các khoản chi đã đánh dấu "được trừ" ở
    <a href="chi_phi.php">Chi phí kinh doanh</a>.
  </p>
  <table style="max-width:640px;">
    <tbody>
      <tr><td>Doanh thu thuần năm <?= e($nam) ?></td><td class="text-right"><?= money($tongDoanhThuNam) ?></td></tr>
      <tr><td>Trừ giá vốn hàng bán</td><td class="text-right">− <?= money($cpNam['gia_von']) ?></td></tr>
      <tr><td>Trừ chi phí được trừ<?php if ($cpNam['khau_hao'] + $cpNam['phan_bo_tra_truoc'] > 0): ?><div class="muted" style="font-size:12px;">gồm khấu hao tài sản <?= money($cpNam['khau_hao']) ?> và phân bổ chi phí trả trước <?= money($cpNam['phan_bo_tra_truoc']) ?> (<a href="phan_bo.php">xem</a>)</div><?php endif; ?></td><td class="text-right">− <?= money($cpNam['chi_phi_duoc_tru']) ?></td></tr>
      <tr style="border-top:1px solid #e2e8f0;"><td style="font-weight:600;">Thu nhập tính thuế</td><td class="text-right" style="font-weight:600;"><?= money($thuNhapNam) ?></td></tr>
      <tr><td>Thuế TNCN ước tính (<?= rtrim(rtrim(number_format($thueSuatThuNhap * 100, 1, ',', ''), '0'), ',') ?>% × thu nhập)</td><td class="text-right" style="font-weight:700;color:#2563eb;"><?= money($tncnThuNhap) ?></td></tr>
      <tr><td>Thuế GTGT ước tính (1% × doanh thu)</td><td class="text-right"><?= money($gtgtNam) ?></td></tr>
      <tr style="border-top:1px solid #e2e8f0;"><td style="font-weight:700;">Tổng thuế ước tính (cách theo thu nhập)</td><td class="text-right" style="font-weight:700;"><?= money($tncnThuNhap + $gtgtNam) ?></td></tr>
    </tbody>
  </table>
  <?php if ($ucTinh && $ucTinh['ap_dung']): $t1 = $ucTinh['tong']; $t2 = $tncnThuNhap + $gtgtNam; ?>
    <div class="alert" style="margin:14px 0 0;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;">
      So sánh hai cách: theo % doanh thu <b><?= money($t1) ?></b>, theo thu nhập <b><?= money($t2) ?></b>.
      <?= $t1 <= $t2 ? 'Cách % doanh thu đang nhẹ hơn ' . money($t2 - $t1) . '.' : 'Cách theo thu nhập đang nhẹ hơn ' . money($t1 - $t2) . '.' ?>
      Chi phí còn thiếu chứng từ có thể làm cách theo thu nhập thấp hơn thực tế; đối chiếu với kế toán trước khi chọn.
    </div>
  <?php endif; ?>
  <?php if ($cpNam['chua_phan_loai_so'] > 0): ?>
    <div class="alert" style="margin:14px 0 0;background:#fffbeb;border:1px solid #fde68a;color:#92400e;">
      Còn <b><?= (int) $cpNam['chua_phan_loai_so'] ?></b> khoản chi chưa phân loại (<?= money($cpNam['chua_phan_loai_tien']) ?>) chưa được tính là chi phí được trừ.
      <a href="chi_phi.php?from=<?= e($nam) ?>-01-01&amp;to=<?= e($nam) ?>-12-31&amp;f=todo">Phân loại ngay</a>.
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" id="han-nop" style="margin-bottom:24px;max-width:760px;">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 8px;">Lịch hạn kê khai và nộp thuế <?= e($nam) ?></h2>
  <p class="muted" style="font-size:13px;margin:0 0 10px;">Nhắc việc dựa trên doanh thu đã ghi sổ. Ngày hạn rơi vào ngày nghỉ có thể được lùi theo quy định, đối chiếu với cơ quan thuế. Bấm "Đã nộp" để ghi nhớ.</p>
  <table>
    <thead><tr><th>Hạn</th><th>Việc phải làm</th><th>Tình trạng</th></tr></thead>
    <tbody>
      <?php foreach ($lichHan as $h):
          $conLai = (int) floor((strtotime($h['han']) - strtotime($homNay)) / 86400); ?>
        <tr<?= !$h['bat_buoc'] ? ' style="opacity:.55;"' : '' ?>>
          <td style="white-space:nowrap;"><?= e(date('d/m/Y', strtotime($h['han']))) ?></td>
          <td><?= e($h['ten']) ?><?= !$h['bat_buoc'] ? ' <span class="muted" style="font-size:12px;">(chỉ khi doanh thu vượt 1 tỷ)</span>' : '' ?></td>
          <td>
            <form method="post" style="display:flex;gap:8px;align-items:center;margin:0;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="tax_done"><input type="hidden" name="k" value="<?= e($h['key']) ?>">
              <?php if ($h['xong']): ?>
                <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:99px;font-size:12px;">Đã nộp <?= e(date('d/m/Y', strtotime($h['xong']))) ?></span>
                <button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Bỏ đánh dấu</button>
              <?php else: ?>
                <?php if ($conLai < 0): ?><span style="color:#dc2626;font-size:13px;">Quá hạn <?= -$conLai ?> ngày</span>
                <?php elseif ($conLai <= 30): ?><span style="color:#d97706;font-size:13px;">Còn <?= $conLai ?> ngày</span>
                <?php else: ?><span class="muted" style="font-size:13px;">Còn <?= $conLai ?> ngày</span><?php endif; ?>
                <input type="hidden" name="done" value="1"><button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Đã nộp</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($vuotNguong): ?>
<div class="card" style="margin-bottom:24px;max-width:640px;">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 8px;">Nhóm nộp thuế</h2>
  <p class="muted" style="font-size:13px;margin:0 0 12px;">
    Đã vượt ngưỡng miễn thuế nên cần chọn đúng nhóm đang đăng ký với cơ quan thuế — quyết định sổ
    sách nào phải lập theo Thông tư 152/2025/TT-BTC. Phần mềm không tự suy ra được nhóm này.
  </p>
  <form method="get" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="nam" value="<?= e($nam) ?>">
    <input type="hidden" name="tu" value="<?= e($tuNgay) ?>">
    <input type="hidden" name="den" value="<?= e($denNgay) ?>">
    <label style="display:flex;align-items:center;gap:6px;font-size:13.5px;font-weight:normal;">
      <input type="radio" name="nhom" value="2" <?= $nhomThue === '2' ? 'checked' : '' ?> onchange="this.form.submit()"> Nhóm 2 — GTGT &amp; TNCN đều theo tỷ lệ (%) trên doanh thu
    </label>
    <label style="display:flex;align-items:center;gap:6px;font-size:13.5px;font-weight:normal;">
      <input type="radio" name="nhom" value="3" <?= $nhomThue === '3' ? 'checked' : '' ?> onchange="this.form.submit()"> Nhóm 3 — GTGT theo tỷ lệ, TNCN theo thu nhập (doanh thu trừ chi phí)
    </label>
  </form>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:24px;">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 4px;">Sổ doanh thu bán hàng hóa, dịch vụ</h2>
  <p class="muted" style="font-size:13px;margin:0 0 12px;">
    Theo mẫu <b><?= e($mauSo) ?></b> (Thông tư 152/2025/TT-BTC) — <?= !$vuotNguong ? 'doanh thu năm dưới ngưỡng miễn thuế nên dùng mẫu đơn giản, không có cột thuế.' : ($nhomThue === '3' ? 'nhóm 3 chỉ ghi doanh thu theo nhóm ngành hàng, không ước tính cột thuế (xem thêm Sổ doanh thu, chi phí bên dưới để xác định thu nhập tính thuế).' : 'doanh thu năm trên ngưỡng miễn thuế nên dùng mẫu có cột thuế theo từng nhóm ngành hàng; cột thuế để trống, tự điền theo hướng dẫn cơ quan thuế/kế toán viên.') ?>
  </p>
  <form method="get" style="display:flex;gap:8px;align-items:end;margin-bottom:16px;">
    <input type="hidden" name="nam" value="<?= e($nam) ?>">
    <input type="hidden" name="nhom" value="<?= e($nhomThue) ?>">
    <div><label style="display:block;font-size:12px;margin-bottom:2px;">Từ ngày</label><input type="date" name="tu" value="<?= e($tuNgay) ?>" class="input"></div>
    <div><label style="display:block;font-size:12px;margin-bottom:2px;">Đến ngày</label><input type="date" name="den" value="<?= e($denNgay) ?>" class="input"></div>
    <button type="submit" class="btn btn-secondary">Xem sổ</button>
      <a class="btn btn-secondary" href="?export=1&nam=<?= e($nam) ?>&tu=<?= e($tuNgay) ?>&den=<?= e($denNgay) ?>">⬇ Xuất Excel</a>
  </form>

  <?php if (!$nhomMap): ?>
    <p class="muted">Không có dữ liệu trong khoảng thời gian này.</p>
  <?php endif; ?>
  <?php foreach ($nhomMap as $tenNhom => $dong): ?>
    <h3 style="font-size:13px;font-weight:600;margin:16px 0 6px;"><?= e($tenNhom) ?></h3>
    <div class="card" style="padding:0;overflow-x:auto;margin-bottom:8px;">
      <table>
        <thead><tr><th>Số hiệu</th><th>Ngày</th><th>Diễn giải</th><th class="text-right">Số tiền</th><?php if ($hienCotThue): ?><th class="text-right">Thuế GTGT</th><th class="text-right">Thuế TNCN</th><?php endif; ?></tr></thead>
        <tbody>
          <?php $tongNhom = 0; ?>
          <?php foreach ($dong as $d): $tongNhom += (float) $d['line_total']; ?>
            <tr>
              <td class="muted" style="font-family:monospace;"><?= e($d['code']) ?></td>
              <td class="muted"><?= date('d/m/Y', strtotime($d['ngay'])) ?></td>
              <td>Bán hàng hóa, dịch vụ<?= $d['khach_hang'] ? ' - ' . e($d['khach_hang']) : ' - Khách lẻ' ?></td>
              <td class="text-right"><?= money($d['line_total']) ?></td>
              <?php if ($hienCotThue): ?><td></td><td></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <tr style="border-top:1px solid #e2e8f0;"><td colspan="3" style="font-weight:700;">Tổng cộng nhóm</td><td class="text-right" style="font-weight:700;"><?= money($tongNhom) ?></td><?php if ($hienCotThue): ?><td></td><td></td><?php endif; ?></tr>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
  <?php if ($traHang): ?>
    <h3 style="font-size:13px;font-weight:600;margin:16px 0 6px;">Hàng bán bị trả lại (ghi giảm doanh thu)</h3>
    <div class="card" style="padding:0;overflow-x:auto;margin-bottom:8px;"><table>
      <thead><tr><th>Số hiệu</th><th>Ngày</th><th>Diễn giải</th><th class="text-right">Số tiền</th></tr></thead>
      <tbody>
        <?php foreach ($traHang as $t): ?>
          <tr><td class="muted" style="font-family:monospace;"><?= e($t['code']) ?></td><td class="muted"><?= date('d/m/Y', strtotime($t['ngay'])) ?></td><td>Khách trả lại hàng</td><td class="text-right" style="color:#dc2626;">-<?= money($t['refund_amount']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <?php if ($nhomMap || $traHang): ?>
    <p style="text-align:right;font-weight:700;font-size:15px;color:#2563eb;margin-top:8px;">Tổng doanh thu thuần trong kỳ (sau chiết khấu, trừ hàng trả lại): <?= money(max(0, $tongTatCa - $tongTra)) ?></p>
  <?php endif; ?>
</div>

<?php if ($vuotNguong && $nhomThue === '3'):
    // --- S2c-HKD: So doanh thu, chi phi ---
    $chiPhiRows = $pdo->prepare(
        "SELECT ce.code, DATE(ce.created_at) AS ngay, ce.reason, ce.amount
         FROM cashbook_entries ce JOIN branches b ON b.id = ce.branch_id
         WHERE " . dieuKienChiKinhDoanh() . " AND ce.deductible = 1
           AND DATE(ce.created_at) BETWEEN ? AND ? AND b.tenant_id = ?
         ORDER BY ce.created_at"
    );
    $chiPhiRows->execute([$tuNgay, $denNgay, $tenantId]);
    $chiPhiRows = $chiPhiRows->fetchAll();
    $tongChiPhiKhac = array_sum(array_column($chiPhiRows, 'amount'));
    $giaVonKy = giaVonTheoKy($pdo, $tenantId, $tuNgay, $denNgay);
    $pbKy = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN e.source = 'ASSET' THEN e.amount ELSE 0 END),0) AS khau_hao,
                COALESCE(SUM(CASE WHEN e.source = 'ITEM' AND i.kind = 'PREPAID' THEN e.amount ELSE 0 END),0) AS tra_truoc
         FROM allocation_entries e LEFT JOIN allocation_items i ON e.source = 'ITEM' AND i.id = e.source_id
         WHERE e.tenant_id = ? AND e.period BETWEEN ? AND ?"
    );
    $pbKy->execute([$tenantId, substr($tuNgay, 0, 7), substr($denNgay, 0, 7)]);
    $pbKy = $pbKy->fetch();
    $tongChiPhi = $tongChiPhiKhac + $giaVonKy + (float) $pbKy['khau_hao'] + (float) $pbKy['tra_truoc'];
    $chuaPhanLoaiKy = $pdo->prepare(
        "SELECT COUNT(*) FROM cashbook_entries ce JOIN branches b ON b.id = ce.branch_id
         WHERE " . dieuKienChiKinhDoanh() . " AND ce.deductible IS NULL AND DATE(ce.created_at) BETWEEN ? AND ? AND b.tenant_id = ?"
    );
    $chuaPhanLoaiKy->execute([$tuNgay, $denNgay, $tenantId]);
    $chuaPhanLoaiKy = (int) $chuaPhanLoaiKy->fetchColumn();

    // --- S2d-HKD: So chi tiet vat lieu, dung cu, san pham, hang hoa (nhap - xuat - ton) ---
    $nhapRows = $pdo->prepare(
        "SELECT p.name, p.sku, SUM(sri.quantity) AS qty
         FROM stock_receipt_items sri
         JOIN stock_receipts sr ON sr.id = sri.receipt_id
         JOIN branches b ON b.id = sr.branch_id
         JOIN products p ON p.id = sri.product_id
         WHERE DATE(sr.created_at) BETWEEN ? AND ? AND b.tenant_id = ?
         GROUP BY sri.product_id"
    );
    $nhapRows->execute([$tuNgay, $denNgay, $tenantId]);
    $nhapMap = [];
    foreach ($nhapRows->fetchAll() as $r) $nhapMap[$r['name'] . '|' . $r['sku']] = (float) $r['qty'];

    $xuatRows = $pdo->prepare(
        "SELECT p.name, p.sku, SUM(oi.quantity) AS qty
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         JOIN branches b ON b.id = o.branch_id
         JOIN products p ON p.id = oi.product_id
         WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status != 'CANCELLED' AND b.tenant_id = ?
         GROUP BY oi.product_id"
    );
    $xuatRows->execute([$tuNgay, $denNgay, $tenantId]);
    $xuatMap = [];
    foreach ($xuatRows->fetchAll() as $r) $xuatMap[$r['name'] . '|' . $r['sku']] = (float) $r['qty'];

    $tonRows = $pdo->prepare(
        "SELECT p.name, p.sku, SUM(i.quantity) AS qty
         FROM inventory i
         JOIN branches b ON b.id = i.branch_id
         JOIN products p ON p.id = i.product_id
         WHERE b.tenant_id = ?
         GROUP BY i.product_id"
    );
    $tonRows->execute([$tenantId]);
    $tonMap = [];
    foreach ($tonRows->fetchAll() as $r) $tonMap[$r['name'] . '|' . $r['sku']] = (float) $r['qty'];

    $s2dKeys = array_unique(array_merge(array_keys($nhapMap), array_keys($xuatMap), array_keys($tonMap)));
    sort($s2dKeys);

    // --- S2e-HKD: So chi tiet tien (thu - chi trong ky, so du cong don tu dau ky) ---
    $tienRows = $pdo->prepare(
        "SELECT ce.code, DATE(ce.created_at) AS ngay, ce.reason, ce.type, ce.amount, ce.payment_method
         FROM cashbook_entries ce JOIN branches b ON b.id = ce.branch_id
         WHERE DATE(ce.created_at) BETWEEN ? AND ? AND b.tenant_id = ?
         ORDER BY ce.created_at"
    );
    $tienRows->execute([$tuNgay, $denNgay, $tenantId]);
    $tienRows = $tienRows->fetchAll();
?>
<div class="card" style="margin-bottom:24px;">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 4px;">Sổ doanh thu, chi phí</h2>
  <p class="muted" style="font-size:13px;margin:0 0 12px;">
    Theo mẫu <b>S2c-HKD</b> — làm căn cứ xác định thu nhập tính thuế TNCN. Chi phí gồm giá vốn hàng bán
    và các khoản chi đã đánh dấu "được trừ" ở <a href="chi_phi.php">Chi phí kinh doanh</a>.
  </p>
  <?php if ($chuaPhanLoaiKy > 0): ?>
    <div class="alert" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;">
      Còn <b><?= $chuaPhanLoaiKy ?></b> khoản chi trong kỳ chưa phân loại nên chưa được tính vào chi phí.
      <a href="chi_phi.php?from=<?= e($tuNgay) ?>&amp;to=<?= e($denNgay) ?>&amp;f=todo">Phân loại ngay</a>.
    </div>
  <?php endif; ?>
  <table>
    <thead><tr><th>Số hiệu</th><th>Ngày</th><th>Diễn giải</th><th class="text-right">Số tiền</th></tr></thead>
    <tbody>
      <tr><td class="muted" style="font-family:monospace;">GIAVON</td><td class="muted">—</td><td>Giá vốn hàng bán trong kỳ (đã trừ hàng trả lại)</td><td class="text-right"><?= money($giaVonKy) ?></td></tr>
      <?php if ((float) $pbKy['khau_hao'] > 0): ?><tr><td class="muted" style="font-family:monospace;">KHAUHAO</td><td class="muted">—</td><td>Khấu hao tài sản cố định đã trích trong kỳ</td><td class="text-right"><?= money($pbKy['khau_hao']) ?></td></tr><?php endif; ?>
      <?php if ((float) $pbKy['tra_truoc'] > 0): ?><tr><td class="muted" style="font-family:monospace;">PHANBO</td><td class="muted">—</td><td>Phân bổ chi phí trả trước đã trích trong kỳ</td><td class="text-right"><?= money($pbKy['tra_truoc']) ?></td></tr><?php endif; ?>
      <?php if (!$chiPhiRows): ?><tr><td colspan="4" class="muted">Không có khoản chi được trừ nào trong kỳ.</td></tr><?php endif; ?>
      <?php foreach ($chiPhiRows as $r): ?>
        <tr>
          <td class="muted" style="font-family:monospace;"><?= e($r['code']) ?></td>
          <td class="muted"><?= date('d/m/Y', strtotime($r['ngay'])) ?></td>
          <td><?= e($r['reason']) ?></td>
          <td class="text-right"><?= money($r['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <table style="margin-top:12px;">
    <tbody>
      <tr><td>Tổng doanh thu trong kỳ</td><td class="text-right" style="font-weight:700;"><?= money($tongTatCa) ?></td></tr>
      <tr><td>Tổng chi phí trong kỳ</td><td class="text-right" style="font-weight:700;"><?= money($tongChiPhi) ?></td></tr>
      <tr style="border-top:1px solid #e2e8f0;"><td style="font-weight:700;padding-top:8px;">Thu nhập tính thuế tạm tính</td><td class="text-right" style="font-weight:700;color:#2563eb;padding-top:8px;"><?= money($tongTatCa - $tongChiPhi) ?></td></tr>
    </tbody>
  </table>
</div>

<div class="card" style="margin-bottom:24px;padding:0;overflow-x:auto;">
  <div style="padding:16px 16px 0;">
    <h2 style="font-size:15px;font-weight:600;margin:0 0 4px;">Sổ chi tiết vật liệu, dụng cụ, sản phẩm, hàng hóa</h2>
    <p class="muted" style="font-size:13px;margin:0 0 12px;">
      Theo mẫu <b>S2d-HKD</b> — nhập/xuất theo khoảng ngày đã chọn ở trên; tồn là số tồn kho hiện
      tại tại thời điểm xem trang (không phải tồn cuối ngày <?= e($denNgay) ?>).
    </p>
  </div>
  <table>
    <thead><tr><th>Sản phẩm</th><th>SKU</th><th class="text-right">Nhập trong kỳ</th><th class="text-right">Xuất trong kỳ</th><th class="text-right">Tồn hiện tại</th></tr></thead>
    <tbody>
      <?php if (!$s2dKeys): ?><tr><td colspan="5" class="muted">Không có dữ liệu.</td></tr><?php endif; ?>
      <?php foreach ($s2dKeys as $key): [$ten, $sku] = explode('|', $key, 2); ?>
        <tr>
          <td><?= e($ten) ?></td>
          <td class="muted" style="font-family:monospace;"><?= e($sku) ?></td>
          <td class="text-right"><?= fmtQty($nhapMap[$key] ?? 0) ?></td>
          <td class="text-right"><?= fmtQty($xuatMap[$key] ?? 0) ?></td>
          <td class="text-right"><?= fmtQty($tonMap[$key] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-bottom:24px;padding:0;overflow-x:auto;">
  <div style="padding:16px 16px 0;">
    <h2 style="font-size:15px;font-weight:600;margin:0 0 4px;">Sổ chi tiết tiền</h2>
    <p class="muted" style="font-size:13px;margin:0 0 12px;">
      Theo mẫu <b>S2e-HKD</b> — theo dõi thu/chi tiền mặt và chuyển khoản. Số dư cộng dồn tính từ
      đầu khoảng ngày đã chọn (<?= date('d/m/Y', strtotime($tuNgay)) ?>), không phải số dư tuyệt
      đối từ lúc bắt đầu kinh doanh.
    </p>
  </div>
  <table>
    <thead><tr><th>Số hiệu</th><th>Ngày</th><th>Diễn giải</th><th class="text-right">Thu</th><th class="text-right">Chi</th><th class="text-right">Số dư</th></tr></thead>
    <tbody>
      <?php if (!$tienRows): ?><tr><td colspan="6" class="muted">Không có phát sinh nào trong kỳ.</td></tr><?php endif; ?>
      <?php $soDu = 0; foreach ($tienRows as $r): $amt = (float) $r['amount']; $soDu += $r['type'] === 'RECEIPT' ? $amt : -$amt; ?>
        <tr>
          <td class="muted" style="font-family:monospace;"><?= e($r['code']) ?></td>
          <td class="muted"><?= date('d/m/Y', strtotime($r['ngay'])) ?></td>
          <td><?= e($r['reason']) ?> <span class="muted">(<?= e($r['payment_method']) ?>)</span></td>
          <td class="text-right"><?= $r['type'] === 'RECEIPT' ? money($amt) : '' ?></td>
          <td class="text-right"><?= $r['type'] === 'PAYMENT' ? money($amt) : '' ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($soDu) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:24px;max-width:640px;">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 8px;">Sổ theo dõi nghĩa vụ thuế khác</h2>
  <p style="margin:0 0 8px;font-size:14px;">
    Theo mẫu <b>S3a-HKD</b> — chỉ áp dụng nếu cửa hàng có hoạt động chịu các loại thuế khác: thuế
    xuất/nhập khẩu, tiêu thụ đặc biệt, tài nguyên, bảo vệ môi trường, sử dụng đất...
  </p>
  <p class="muted" style="margin:0;font-size:13px;">
    Phần lớn cửa hàng bán lẻ/tạp hóa thông thường <b>không phát sinh</b> các loại thuế này nên
    không bắt buộc lập sổ. Nếu có phát sinh, ghi từng khoản phải nộp và đã nộp ở sổ riêng, xuất Excel được.
  </p>
  <p style="margin:12px 0 0;"><a class="btn" href="thue_khac.php?nam=<?= e($nam) ?>">Mở sổ S3a-HKD</a></p>
</div>

<div class="card" style="max-width:640px;">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 12px;">Hóa đơn điện tử</h2>
  <?php hopDieuKienTrienKhai(
      'Phần mềm đã tính sẵn <b>ước tính thuế hộ kinh doanh</b> và lập các <b>sổ theo thông tư</b>
       ở phần trên, nhưng <b>chưa phát hành được hóa đơn điện tử</b> — việc đó bắt buộc phải qua
       nhà cung cấp được Tổng cục Thuế công nhận.',
      [
          'Đăng ký tài khoản với một nhà cung cấp hóa đơn điện tử (Viettel, VNPT, MISA, M-Invoice...).',
          'Lấy API key / thông tin kết nối họ cấp.',
          'Nhờ lập trình phần kết nối để tự phát hành hóa đơn khi hoàn thành đơn hàng.',
      ],
      'Chưa có thì vẫn xuất được các sổ ở trên để nộp thuế theo cách thông thường.'
  ); ?>
</div>

<p class="muted" style="font-size:12px;margin:14px 0 0;max-width:820px;">⚖️ Số liệu trên trang này được tính từ dữ liệu bạn nhập theo cách tính mặc định của phần mềm, chỉ mang tính <b>tham khảo</b> và không thay thế tư vấn của kế toán/cơ quan thuế. Xem <a href="dieu_khoan.php" target="_blank" rel="noopener">Điều khoản sử dụng</a>.</p>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
