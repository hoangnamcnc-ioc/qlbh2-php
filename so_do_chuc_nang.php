<?php
/**
 * So do chuc nang: luong lam viec cua cua hang theo 4 buoc (Thiet lap -> Cung ung & kho -> Ban hang
 * & van chuyen -> Hau kiem & bao cao). Bam vao o de mo chuc nang. CHI hien nhung chuc nang tai khoan
 * hien tai duoc phep dung (lay tu chinh menu ben trai, nen luon khop phan quyen).
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
require_once __DIR__ . '/inc_header.php';

// Cac file duoc phep = moi lien ket dang co trong menu cua nguoi dung (inc_header.php dung chung quy tac phan quyen).
$allowed = [];
// $allNavFiles giu day du cac trang nguoi dung duoc mo, ke ca trang da gop vao tab (menu rut gon khong lam mat o tren so do).
foreach ($navGroups as $links) {
    foreach (array_keys($links) as $file) {
        $allowed[$file] = true;
    }
}
foreach (array_keys($allNavFiles ?? []) as $file) {
    $allowed[$file] = true;
}
// Mot so chuc nang khong nam o menu rieng nhung thuoc cung nhom quyen voi trang menu.
$alsoAllowed = [
    'online_shop_settings.php' => 'settings.php',
    'shipping_settings.php' => 'settings.php',
    'cancel_reasons.php' => 'settings.php',
    'tenant_export.php' => 'settings.php',
    'activity_log.php' => 'settings.php',
    'backup.php' => 'settings.php',
];
foreach ($alsoAllowed as $file => $via) {
    if (isset($allowed[$via])) {
        $allowed[$file] = true;
    }
}

/** [icon, tieu de, mo ta, file] */
require_once __DIR__ . '/inc_so_do.php';
$stages = soDoGiaiDoan();
// Luoi an toan: bat ky chuc nang nao co trong MENU cua vai tro nay ma chua co o rieng tren so do thi tu dong them vao the "Khac",
// de so do luon liet ke DUNG va DU cac chuc nang cua vai tro (ke ca chuc nang moi them sau nay).
$onMap = [];
foreach ($stages as [$t0, $s0, $tl]) { foreach ($tl as $x) { $onMap[$x[3]] = true; } }
$extra = [];
foreach ($navGroups as $links) {
    foreach ($links as $file => $text) {
        if (!isset($onMap[$file]) && !in_array($file, ['so_do_chuc_nang.php', 'huong_dan.php', 'index.php', '#goi-y', 'logout.php'], true) && str_ends_with($file, '.php')) {
            $extra[] = ['🔹', $text, 'Chức năng khác', $file];
        }
    }
}
if ($extra) {
    $stages[] = ['Khác', 'Các chức năng còn lại của bạn', $extra];
}
?>
<?php soDoCss(); ?>

<?php if (isset($_GET['welcome'])): ?>
  <div class="alert alert-success" style="margin-bottom:16px;">
    🎉 Chào mừng bạn đến với QLBH-CLOUD! Tài khoản dùng thử miễn phí 12 tháng đã sẵn sàng. Đi theo các bước từ trái sang phải:
    thêm sản phẩm ở bước 1, bán hàng thử ở bước 3. Muốn xem thử số liệu có sẵn, vào ô <b>Dữ liệu mẫu</b>.
  </div>
<?php endif; ?>
<h1 style="font-size:24px;font-weight:600;margin:0 0 6px;">Sơ đồ chức năng</h1>
<p class="sdcn-intro">
  Luồng làm việc của cửa hàng theo chiều mũi tên: thiết lập → cung ứng & kho → bán hàng & vận chuyển → hậu kiểm & báo cáo.
  Bấm vào ô để mở chức năng; chỉ hiện những chức năng bạn được phép dùng.
</p>

<div class="sdcn-flow">
  <?php foreach ($stages as $i => [$title, $sub, $tiles]): ?>
    <?php if ($i > 0 && $title !== 'Khác'): ?><div class="sdcn-arrow" aria-hidden="true">➜</div><?php endif; ?>
    <section class="sdcn-stage">
      <h2><?= e($title) ?></h2>
      <div class="sub"><?= e($sub) ?></div>
      <?php $visible = array_filter($tiles, static fn($t) => isset($allowed[$t[3]])); ?>
      <?php if (!$visible): ?>
        <div class="sdcn-empty">Bạn chưa có chức năng nào ở bước này.</div>
      <?php else: ?>
        <div class="sdcn-grid">
          <?php foreach ($visible as [$icon, $name, $desc, $file]): ?>
            <a class="sdcn-tile" href="<?= e($file) ?>">
              <span class="ic"><?= $icon ?></span>
              <span class="t"><?= e($name) ?></span>
              <span class="d"><?= e($desc) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
