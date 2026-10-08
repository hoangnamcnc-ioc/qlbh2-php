<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();

$error = null;

/** Tao slug mac dinh tu ten cua hang + ky tu ngau nhien de khong trung giua cac cua hang. */
function taoShopSlugMacDinh(string $storeName): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', str_replace(['đ', 'Đ'], ['d', 'D'], $storeName));
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $ascii)), '-');
    $base = substr($base !== '' ? $base : 'cua-hang', 0, 40);
    return $base . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
}

function shopSlugDaDung(PDO $pdo, string $slug, int $tenantId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM store_settings WHERE setting_key = 'shop_slug' AND setting_value = ? AND tenant_id <> ?");
    $stmt->execute([$slug, $tenantId]);
    return (int) $stmt->fetchColumn() > 0;
}

$tenantId = currentTenantId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $enabled = isset($_POST['enabled']) ? '1' : '0';
    $slug = strtolower(trim((string) ($_POST['shop_slug'] ?? '')));
    if ($slug !== '' && !preg_match('/^[a-z0-9-]{3,60}$/', $slug)) {
        $error = 'Đường dẫn chỉ gồm chữ thường không dấu, số và dấu gạch ngang (3–60 ký tự).';
    } elseif ($slug !== '' && shopSlugDaDung($pdo, $slug, $tenantId)) {
        $error = 'Đường dẫn này đã có cửa hàng khác dùng, vui lòng chọn tên khác.';
    } else {
        setSetting('online_shop_enabled', $enabled);
        if ($slug !== '') {
            setSetting('shop_slug', $slug);
        }
        logActivity('ONLINE_SHOP_SETTINGS_UPDATE');
        redirect('online_shop_settings.php?saved=1');
    }
}

$enabled = getSetting('online_shop_enabled', '1') === '1';
$shopSlug = getSetting('shop_slug', '');
if ($shopSlug === '' && $tenantId !== 1) {
    // Cua hang chua co duong dan rieng: tao san 1 cai (tenant #1 giu link cu shop.php khong tham so).
    do {
        $shopSlug = taoShopSlugMacDinh(getSetting('store_name', 'cua hang'));
    } while (shopSlugDaDung($pdo, $shopSlug, $tenantId));
    setSetting('shop_slug', $shopSlug);
}
$shopUrl = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/shop.php' . ($shopSlug !== '' ? '?s=' . $shopSlug : '');
$shopUrl = str_replace('//shop.php', '/shop.php', $shopUrl);

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 16px;">Đặt hàng Online</h1>

<?php hopDieuKienTrienKhai(
    'Trang đặt hàng công khai <b>chạy thật ngay, không cần đăng ký gì thêm</b> — bật lên là có
     đường dẫn để gửi cho khách.',
    [
        'Sản phẩm phải <b>đang bán</b> và <b>còn tồn kho</b> ở chi nhánh bán online thì khách mới thấy.',
        'Khách <b>chưa thanh toán online được</b> — đơn về ở trạng thái <b>Đặt hàng / Chưa thanh toán</b>,
         cửa hàng gọi lại xác nhận rồi thu tiền khi giao. Muốn khách trả trước qua thẻ/QR thì cần
         tài khoản cổng thanh toán và lập trình thêm.',
        'Phải có người <b>xem đơn mới hằng ngày</b> ở Danh sách đơn hàng — hệ thống không tự gọi
         điện hay tự giao hàng.',
    ]
); ?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Đã lưu cấu hình</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:560px;margin-bottom:24px;">
  <p style="margin:0 0 12px;font-size:14px;">
    Chia sẻ đường dẫn dưới đây cho khách hàng (qua Facebook/Zalo/tin nhắn) để khách tự chọn sản
    phẩm và đặt hàng — không cần đăng nhập. Đơn đặt sẽ vào mục
    <a href="orders.php?status=DRAFT">Đơn hàng → Đặt hàng (chờ xử lý)</a> để nhân viên xác nhận
    trước khi chuyển sang các bước Duyệt/Đóng gói/Xuất kho/Hoàn thành.
  </p>
  <div style="display:flex;gap:8px;">
    <input class="input" readonly value="<?= e($shopUrl) ?>" onclick="this.select()" style="font-family:monospace;font-size:13px;">
  </div>
</div>

<div class="card" style="max-width:560px;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field">
      <label>Đường dẫn cửa hàng (shop.php?s=…)</label>
      <input class="input" name="shop_slug" value="<?= e($shopSlug) ?>" placeholder="vd: cua-hang-minh-anh" style="max-width:320px;">
      <div class="muted" style="font-size:12px;margin-top:4px;">Đổi đường dẫn thì link đã gửi khách trước đó sẽ không còn dùng được.</div>
    </div>
    <label style="font-weight:400;"><input type="checkbox" name="enabled" <?= $enabled ? 'checked' : '' ?>> Mở nhận đặt hàng online</label>
    <p class="muted" style="font-size:12px;margin:6px 0 12px;">Tắt tạm thời khi hết hàng/nghỉ bán mà không cần gỡ đường dẫn đã chia sẻ.</p>
    <button type="submit" class="btn">Lưu</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
