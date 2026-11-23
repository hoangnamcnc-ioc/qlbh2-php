<?php
/**
 * So do nghiep vu cua tung nhom menu: bam vao ten nhom o menu trai thi ben phai hien cac buoc lam viec cua nhom do,
 * moi o chuc nang bam thang de tac nghiep. Chi hien chuc nang nguoi dung duoc phep dung (lay tu chinh menu).
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
require_once __DIR__ . '/inc_header.php';
require_once __DIR__ . '/inc_so_do.php';

$g = (string) ($_GET['g'] ?? '');
if (!isset($navGroupsFull[$g]) || in_array($g, ['Chức năng'], true)) {
    redirect('so_do_chuc_nang.php');
}
// Cac file cua nhom nay ma nguoi dung duoc phep mo (ke ca trang da gop vao tab cua trang khac)
$files = [];
foreach ($navGroupsFull[$g] as $file => $text) {
    if (isset($allNavFiles[$file]) && $file !== '#goi-y' && str_ends_with($file, '.php')) {
        $files[$file] = $text;
    }
}
$stages = [];
$onMap = [];
$tileInfo = [];
foreach (soDoGiaiDoan() as [$title, $sub, $tiles]) {
    foreach ($tiles as $t) { $tileInfo[$t[3]] = $t; }
}
// Mot so nhom co cac buoc rieng (theo trinh tu lam viec cua nhom); nhom khac dung cac giai doan chung cua toan he thong.
$flows = [
    'Tài chính & Báo cáo' => [
        ['1. Chi phí và tiền', 'Mọi khoản chi, thu tiền khác và đối chiếu', ['chi_phi.php', 'cashbook.php', 'kiem_ke_quy.php', 'bank_reconcile.php', 'cong_no.php']],
        ['2. Hóa đơn và sổ sách', 'Hóa đơn, khấu hao, phân bổ, sổ kế toán', ['hoa_don.php', 'phan_bo.php']],
        ['3. Kiểm soát và báo cáo', 'Đối chiếu số liệu, xem kết quả', ['doi_chieu.php', 'canh_bao.php', 'reports.php', 'phan_tich.php', 'tro_ly.php']],
    ],
    'Nhân sự' => [
        ['1. Hồ sơ nhân sự', 'Hợp đồng, bảo hiểm, người phụ thuộc, ngày phép', ['nhan_su.php']],
        ['2. Chấm công và lịch làm việc', 'Giờ công hằng ngày, xếp lịch', ['attendance.php', 'work_schedules.php']],
        ['3. Tính lương', 'Lương giờ, hoa hồng, trừ bảo hiểm và thuế TNCN', ['payroll.php']],
        ['Việc của tôi', 'Chấm công, lương tạm tính và nghỉ phép của chính bạn', ['cham_cong_cua_toi.php', 'nghi_phep.php']],
    ],
];
if ($g === 'Thuế') {
    $T = static fn(string $m, array $x = []) => 'to_khai_thue.php?' . http_build_query(['mau' => $m] + $x);
    $y = date('Y');
    $thueFlow = [
        ['1. Xem hạn nộp', 'Lịch nghĩa vụ thuế, hạn tờ khai và nộp tiền', [['📅', 'Lịch nghĩa vụ thuế', 'Hạn nộp tờ khai, nộp thuế, đánh dấu đã nộp', 'accounting.php#han-nop']]],
        ['2. Kiểm tra sẵn sàng', 'Việc cần làm trước khi lập tờ khai', [
            ['✅', 'Sẵn sàng kê khai', 'Danh sách kiểm tra trước khi lập tờ khai quý, điểm số sách', $T('sansang')],
            ['🔎', 'Kiểm tra số liệu', 'Cảnh báo sai sót, thiếu sót trong sổ sách', 'kiem_tra_du_lieu.php']]],
        ['3. Tổng hợp số liệu', 'Thuế giá trị gia tăng, thu nhập cá nhân theo quý và năm', [
            ['📊', 'Tổng hợp thuế theo quý', 'GTGT, TNCN theo quý, năm, so sánh hai cách tính', $T('tonghop')],
            ['🔮', 'Dự báo ngưỡng doanh thu', 'Khả năng chạm 1 tỷ, 3 tỷ, thuế phát sinh, dòng tiền 30 ngày', $T('dubao')]]],
        ['4. Số liệu để kê khai', 'Số liệu điền tờ khai, thông báo doanh thu, sổ tài khoản, bảng kê tồn kho', [
            ['📝', 'Thông báo doanh thu năm', 'Mẫu 01/TKN-CNKD cho hộ dưới 1 tỷ', $T('01tkn')],
            ['📄', 'Tờ khai quý 01/CNKD', 'Mẫu 01/CNKD điền sẵn: GTGT, TNCN theo quý cho hộ doanh thu trên 1 tỷ', $T('01cnkd')],
            ['📑', 'Quyết toán TNCN 02/CNKD-TNCN-QTT', 'Mẫu 02 điền sẵn cho hộ tính TNCN trên thu nhập', $T('02qtt')],
            ['🧺', 'Bảng kê thu mua không hóa đơn', 'Mẫu 02/TNDN: các khoản mua của người bán không có hóa đơn', $T('thumua')],
            ['📍', 'Thông báo địa điểm kinh doanh', 'Mẫu 01/TB-ĐĐKD: thành lập, thay đổi, tạm ngừng, chấm dứt', $T('ddkd')],
            ['🎁', 'Giảm 30% thuế TNCN (NĐ 398/2026)', 'Mẫu số 01 kèm tờ khai: giảm 30% thuế TNCN năm 2026, 2027 cho hộ dưới 10 tỷ', $T('giam43')],
            ['🏦', 'Thông báo số tài khoản', 'Mẫu 01/BK-STK: số tài khoản ngân hàng, ví điện tử gửi cơ quan thuế', $T('bkstk')],
            ['📦', 'Bảng kê tồn kho, máy móc', 'Mẫu 01/BK-HTK: hàng tồn kho, máy móc, thiết bị tại 31/12', $T('bkhtk')],
            ['🗂️', 'Sổ sách S1a–S2e', 'Sổ doanh thu, chi phí, vật tư, tiền theo mẫu Thông tư 152', 'accounting.php'],
            ['📋', 'Sổ thuế khác (S3a)', 'Thuế tiêu thụ đặc biệt, bảo vệ môi trường, tài nguyên...', 'thue_khac.php']]],
    ];
    foreach ($thueFlow as [$title, $sub, $tl]) { $stages[] = [$title, $sub, $tl]; }
    $onMap = ['to_khai_thue.php' => true, 'thue_khac.php' => true];
} elseif (isset($flows[$g])) {
    foreach ($flows[$g] as [$title, $sub, $fl]) {
        $tl = [];
        foreach ($fl as $file) {
            if (!isset($files[$file])) continue;
            $tl[] = $tileInfo[$file] ?? ['🔹', $files[$file], 'Chức năng của nhóm', $file];
            $onMap[$file] = true;
        }
        if ($tl) $stages[] = [$title, $sub, $tl];
    }
} else {
    foreach (soDoGiaiDoan() as [$title, $sub, $tiles]) {
        $mine = array_values(array_filter($tiles, static fn($t) => isset($files[$t[3]])));
        foreach ($mine as $t) { $onMap[$t[3]] = true; }
        if ($mine) $stages[] = [$title, $sub, $mine];
    }
}
$extra = [];
foreach ($files as $file => $text) {
    if (!isset($onMap[$file]) && $file !== 'nhom.php') {
        $extra[] = ['🔹', $text, 'Chức năng của nhóm', $file];
    }
}
if ($extra) {
    $stages[] = [$stages ? 'Khác' : $g, $stages ? 'Các chức năng còn lại của nhóm' : 'Chức năng của nhóm', $extra];
}
$icon = $groupIcons[$g] ?? '•';
soDoCss();
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 6px;">
  <h1 style="font-size:24px;font-weight:600;margin:0;"><?= e($icon) ?> <?= e($g) ?>: sơ đồ nghiệp vụ</h1>
  <a class="btn btn-secondary" href="so_do_chuc_nang.php">Tất cả quy trình</a>
</div>
<p class="sdcn-intro">Làm lần lượt theo các bước từ trái sang phải, trên xuống dưới. Bấm thẳng vào ô chức năng để mở và làm việc; chỉ hiện chức năng bạn được phép dùng.</p>

<div class="sdcn-flow">
  <?php foreach ($stages as $i => [$title, $sub, $tiles]): ?>
    <?php if ($i > 0): ?><div class="sdcn-arrow" aria-hidden="true">➜</div><?php endif; ?>
    <section class="sdcn-stage">
      <h2><?= e($title) ?></h2>
      <div class="sub"><?= e($sub) ?></div>
      <div class="sdcn-grid">
        <?php foreach ($tiles as [$ic, $name, $desc, $file]): ?>
          <a class="sdcn-tile" href="<?= e($file) ?>">
            <span class="ic"><?= $ic ?></span>
            <span class="t"><?= e($name) ?></span>
            <span class="d"><?= e($desc) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
