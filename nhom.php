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
if (!isset($navGroupsFull[$g]) || in_array($g, ['Chức năng', 'Cá nhân'], true)) {
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
        ['2. Hóa đơn và sổ sách', 'Hóa đơn, khấu hao, phân bổ, sổ kế toán', ['hoa_don.php', 'phan_bo.php', 'accounting.php', 'thue_khac.php']],
        ['3. Kiểm soát và báo cáo', 'Đối chiếu số liệu, xem kết quả', ['doi_chieu.php', 'canh_bao.php', 'reports.php', 'phan_tich.php', 'tro_ly.php']],
    ],
    'Nhân sự' => [
        ['1. Hồ sơ nhân sự', 'Hợp đồng, bảo hiểm, người phụ thuộc, ngày phép', ['nhan_su.php']],
        ['2. Chấm công và lịch làm việc', 'Giờ công hằng ngày, xếp lịch', ['attendance.php', 'work_schedules.php']],
        ['3. Tính lương', 'Lương giờ, hoa hồng, trừ bảo hiểm và thuế TNCN', ['payroll.php']],
    ],
];
if (isset($flows[$g])) {
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
