<?php
/**
 * Du lieu mau: xem trang thai, TAO du lieu mau 1 tuan (chi khi cua hang con trong) hoac XOA TOAN BO du lieu kinh doanh
 * de bat dau su dung chinh thuc. Chi Quan tri.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_demo.php';
$currentUser = requireRole('ADMIN');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$isDemo = cuaHangDangDungDuLieuMau($tenantId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' && !$isDemo && cuaHangDangTrong($tenantId)) {
        $branchId = effectiveBranchId($currentUser);
        if (!$branchId) {
            $error = 'Tài khoản chưa được gán chi nhánh nên chưa tạo được dữ liệu mẫu.';
        } else {
            try {
                taoDuLieuMau($pdo, $tenantId, $branchId, (int) $currentUser['id']);
                logActivity('DEMO_DATA_CREATE', 'tenant_id=' . $tenantId);
                redirect('du_lieu_mau.php?created=1');
            } catch (Throwable $ex) {
                $error = 'Không tạo được dữ liệu mẫu, vui lòng thử lại. Nếu vẫn lỗi hãy liên hệ hỗ trợ.';
                error_log('taoDuLieuMau: ' . $ex->getMessage());
            }
        }
    } elseif ($action === 'wipe' && $isDemo) {
        if (strtoupper(trim((string) ($_POST['confirm_text'] ?? ''))) !== 'XOA' || empty($_POST['confirm_check'])) {
            $error = 'Vui lòng tích xác nhận và gõ đúng chữ XOA để xóa dữ liệu mẫu.';
        } else {
            try {
                xoaToanBoDuLieuKinhDoanh($pdo, $tenantId);
                logActivity('DEMO_DATA_WIPE', 'tenant_id=' . $tenantId);
                redirect('du_lieu_mau.php?wiped=1');
            } catch (Throwable $ex) {
                $error = 'Không xóa được dữ liệu mẫu, dữ liệu vẫn giữ nguyên. Vui lòng thử lại.';
                error_log('xoaToanBoDuLieuKinhDoanh: ' . $ex->getMessage());
            }
        }
    }
    $isDemo = cuaHangDangDungDuLieuMau($tenantId);
}

$empty = !$isDemo && cuaHangDangTrong($tenantId);
$stats = $isDemo ? demThongKeDuLieuCuaHang($tenantId) : [];
require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Dữ liệu mẫu</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:760px;">
  Dữ liệu mẫu là 1 tuần bán hàng của một cửa hàng tạp hóa nhỏ (hàng hóa, nhập hàng, khách hàng, đơn bán, công nợ, trả hàng,
  ca bán hàng, sổ quỹ, chấm công…) để bạn xem và thử mọi chức năng trước khi nhập dữ liệu thật.
</p>

<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Đã tạo dữ liệu mẫu 1 tuần bán hàng. Hãy vào Báo cáo, Đơn hàng, Kho, Sổ quỹ… để tham khảo.</div><?php endif; ?>
<?php if (isset($_GET['wiped'])): ?><div class="alert alert-success">Đã xóa toàn bộ dữ liệu mẫu. Cửa hàng của bạn đang trống, sẵn sàng để nhập dữ liệu thật (bắt đầu từ Sản phẩm).</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($isDemo): ?>
  <div class="card" style="max-width:640px;margin-bottom:16px;">
    <h2 style="font-size:16px;margin:0 0 10px;">Cửa hàng đang dùng dữ liệu mẫu</h2>
    <table style="width:auto;">
      <?php foreach ($stats as $label => $n): ?>
        <tr><td style="padding:3px 24px 3px 0;"><?= e($label) ?></td><td class="text-right"><b><?= (int) $n ?></b></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="font-size:12.5px;margin:10px 0 0;">
      Tài khoản nhân viên mẫu (Thu ngân Lan, Thu ngân Minh) không đăng nhập được; bạn có thể đặt lại mật khẩu của họ ở
      <a href="users.php">Nhân viên &amp; phân quyền</a> nếu muốn thử đăng nhập bằng vai trò thu ngân.
    </p>
  </div>

  <div class="card" style="max-width:640px;border:1px solid #fecaca;">
    <h2 style="font-size:16px;margin:0 0 6px;color:#b91c1c;">Xóa dữ liệu mẫu và bắt đầu chính thức</h2>
    <p style="font-size:13.5px;margin:0 0 10px;">
      Thao tác này <b>xóa vĩnh viễn toàn bộ dữ liệu kinh doanh</b> của cửa hàng: sản phẩm, khách hàng, nhà cung cấp, đơn hàng,
      phiếu nhập, kho, sổ quỹ, ca bán hàng, chấm công, khuyến mại… <b>kể cả những gì bạn đã tự nhập thêm</b>.
      Giữ lại: tài khoản của bạn, các tài khoản nhân viên thật, chi nhánh và cấu hình cửa hàng. Không khôi phục được.
    </p>
    <p style="font-size:13px;margin:0 0 12px;">
      Nếu muốn giữ lại bản sao, hãy <a href="tenant_export.php">tải dữ liệu của tôi</a> trước.
    </p>
    <form method="post" onsubmit="return confirm('Xóa vĩnh viễn toàn bộ dữ liệu mẫu và dữ liệu kinh doanh hiện có?');">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="wipe">
      <label style="font-weight:400;display:block;margin-bottom:8px;font-size:13.5px;">
        <input type="checkbox" name="confirm_check" value="1"> Tôi hiểu dữ liệu sẽ bị xóa vĩnh viễn
      </label>
      <div class="field" style="max-width:260px;">
        <label>Gõ chữ <b>XOA</b> để xác nhận</label>
        <input class="input" name="confirm_text" autocomplete="off" placeholder="XOA">
      </div>
      <button type="submit" class="btn btn-danger">Xóa dữ liệu mẫu, bắt đầu chính thức</button>
    </form>
  </div>

<?php elseif ($empty): ?>
  <div class="card" style="max-width:640px;">
    <h2 style="font-size:16px;margin:0 0 8px;">Tạo dữ liệu mẫu</h2>
    <p style="font-size:13.5px;margin:0 0 12px;">
      Cửa hàng của bạn đang trống. Bấm nút dưới để tạo 1 tuần bán hàng mẫu (khoảng 24 sản phẩm, 14 khách hàng, 3 nhà cung cấp,
      hơn 80 đơn hàng…). Bạn có thể xóa sạch bất cứ lúc nào để bắt đầu dùng chính thức.
    </p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="create">
      <button type="submit" class="btn">Tạo dữ liệu mẫu 1 tuần bán hàng</button>
    </form>
  </div>

<?php else: ?>
  <div class="alert alert-warning" style="max-width:640px;">
    Cửa hàng đã có dữ liệu thật nên không tạo dữ liệu mẫu được (để tránh lẫn với dữ liệu của bạn).
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
