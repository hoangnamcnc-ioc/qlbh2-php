<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    checkCsrf();
    $tid = currentTenantId();
    // Logic tao SQL dump rieng 1 tenant dung CHUNG voi sao luu dinh ky qua email (daily_cron.php)
    // - rut ra ham generateTenantExportSql() trong inc_functions.php de khong co 2 ban sao danh
    // sach 52 bang co the lech nhau theo thoi gian.
    $sql = generateTenantExportSql($tid);

    logActivity('TENANT_EXPORT', 'tenant_id=' . $tid);

    $filename = 'qlbh2_export_' . date('Ymd_His') . '.sql.gz';
    $gz = gzencode($sql, 9);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($gz));
    echo $gz;
    exit;
}

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:16px;">Xuất dữ liệu của tôi</h1>

<div class="card" style="max-width:760px;">
  <p class="muted" style="margin:0 0 16px;">
    Tải về toàn bộ dữ liệu của cửa hàng bạn (sản phẩm, khách hàng, đơn hàng, tồn kho, kiểm/chuyển
    hàng, nhập hàng, bảo hành, sổ quỹ...) thành 1 file <code>.sql.gz</code> lưu về máy tính của bạn.
    File chỉ chứa dữ liệu của riêng cửa hàng bạn, không có dữ liệu của khách hàng khác trên hệ thống.
  </p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="download" value="1">
    <button type="submit" class="btn">⬇️ Tải xuống dữ liệu của tôi</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
