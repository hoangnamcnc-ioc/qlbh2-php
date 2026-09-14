<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

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

$rows = $pdo->prepare(
    "SELECT MONTH(created_at) AS thang, COALESCE(SUM(total_amount),0) AS doanh_thu
     FROM orders WHERE YEAR(created_at) = ? AND status != 'CANCELLED' GROUP BY MONTH(created_at)"
);
$rows->execute([$nam]);
$byMonth = array_fill(1, 12, 0.0);
foreach ($rows->fetchAll() as $r) {
    $byMonth[(int) $r['thang']] = (float) $r['doanh_thu'];
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

// Sổ doanh thu theo mẫu S1a-HKD / S2a-HKD (Thông tư 152/2025/TT-BTC)
$tuNgay = $_GET['tu'] ?? date('Y-m-01');
$denNgay = $_GET['den'] ?? date('Y-m-d');
$mauSo = $vuotNguong ? 'S2a-HKD' : 'S1a-HKD';

$soRows = $pdo->prepare(
    "SELECT o.code, DATE(o.created_at) AS ngay, c.name AS khach_hang,
            COALESCE(cat.name, 'Chưa phân loại') AS nhom, oi.line_total
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     LEFT JOIN customers c ON c.id = o.customer_id
     JOIN products p ON p.id = oi.product_id
     LEFT JOIN categories cat ON cat.id = p.category_id
     WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status != 'CANCELLED'
     ORDER BY cat.name, o.created_at"
);
$soRows->execute([$tuNgay, $denNgay]);
$nhomMap = [];
foreach ($soRows->fetchAll() as $r) {
    $nhomMap[$r['nhom']][] = $r;
}
$tongTatCa = 0;
foreach ($nhomMap as $dong) {
    foreach ($dong as $d) $tongTatCa += (float) $d['line_total'];
}

require_once __DIR__ . '/inc_header.php';
?>

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
      trừ) nhân thuế suất lũy tiến 15%/17%/20%. Phần mềm chưa có dữ liệu chi phí được trừ nên
      không tự tính, vui lòng liên hệ cơ quan thuế/kế toán viên.
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

<div class="card" style="margin-bottom:24px;">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 4px;">Sổ doanh thu bán hàng hóa, dịch vụ</h2>
  <p class="muted" style="font-size:13px;margin:0 0 12px;">
    Theo mẫu <b><?= e($mauSo) ?></b> (Thông tư 152/2025/TT-BTC) — <?= $vuotNguong ? 'doanh thu năm trên ngưỡng miễn thuế nên dùng mẫu có cột thuế theo từng nhóm ngành hàng; cột thuế để trống, tự điền theo hướng dẫn cơ quan thuế/kế toán viên.' : 'doanh thu năm dưới ngưỡng miễn thuế nên dùng mẫu đơn giản, không có cột thuế.' ?>
  </p>
  <form method="get" style="display:flex;gap:8px;align-items:end;margin-bottom:16px;">
    <input type="hidden" name="nam" value="<?= e($nam) ?>">
    <div><label style="display:block;font-size:12px;margin-bottom:2px;">Từ ngày</label><input type="date" name="tu" value="<?= e($tuNgay) ?>" class="input"></div>
    <div><label style="display:block;font-size:12px;margin-bottom:2px;">Đến ngày</label><input type="date" name="den" value="<?= e($denNgay) ?>" class="input"></div>
    <button type="submit" class="btn btn-secondary">Xem sổ</button>
  </form>

  <?php if (!$nhomMap): ?>
    <p class="muted">Không có dữ liệu trong khoảng thời gian này.</p>
  <?php endif; ?>
  <?php foreach ($nhomMap as $tenNhom => $dong): ?>
    <h3 style="font-size:13px;font-weight:600;margin:16px 0 6px;"><?= e($tenNhom) ?></h3>
    <div class="card" style="padding:0;overflow-x:auto;margin-bottom:8px;">
      <table>
        <thead><tr><th>Số hiệu</th><th>Ngày</th><th>Diễn giải</th><th class="text-right">Số tiền</th><?php if ($vuotNguong): ?><th class="text-right">Thuế GTGT</th><th class="text-right">Thuế TNCN</th><?php endif; ?></tr></thead>
        <tbody>
          <?php $tongNhom = 0; ?>
          <?php foreach ($dong as $d): $tongNhom += (float) $d['line_total']; ?>
            <tr>
              <td class="muted" style="font-family:monospace;"><?= e($d['code']) ?></td>
              <td class="muted"><?= date('d/m/Y', strtotime($d['ngay'])) ?></td>
              <td>Bán hàng hóa, dịch vụ<?= $d['khach_hang'] ? ' - ' . e($d['khach_hang']) : ' - Khách lẻ' ?></td>
              <td class="text-right"><?= money($d['line_total']) ?></td>
              <?php if ($vuotNguong): ?><td></td><td></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <tr style="border-top:1px solid #e2e8f0;"><td colspan="3" style="font-weight:700;">Tổng cộng nhóm</td><td class="text-right" style="font-weight:700;"><?= money($tongNhom) ?></td><?php if ($vuotNguong): ?><td></td><td></td><?php endif; ?></tr>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
  <?php if ($nhomMap): ?>
    <p style="text-align:right;font-weight:700;font-size:15px;color:#2563eb;margin-top:8px;">Tổng tất cả nhóm: <?= money($tongTatCa) ?></p>
  <?php endif; ?>
</div>

<div class="card" style="max-width:640px;">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 12px;">Hóa đơn điện tử</h2>
  <p style="margin:0 0 12px;">
    Tính năng phát hành hóa đơn điện tử yêu cầu kết nối với nhà cung cấp được Tổng cục Thuế
    công nhận (vd Viettel, VNPT, MISA, M-Invoice...).
  </p>
  <p class="muted" style="margin:0;">
    QLBH2 hiện <b>chưa tích hợp API thật</b> với các nhà cung cấp này. Để dùng được, bạn cần:
  </p>
  <ol class="muted" style="margin:8px 0 0;padding-left:20px;">
    <li>Đăng ký tài khoản với 1 nhà cung cấp hóa đơn điện tử.</li>
    <li>Lấy API key/thông tin kết nối họ cung cấp.</li>
    <li>Nhờ lập trình lại phần này để gọi API thật của nhà cung cấp đó khi hoàn thành đơn hàng.</li>
  </ol>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
