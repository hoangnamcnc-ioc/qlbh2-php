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

<div class="card qr-card" style="max-width:760px;margin-top:24px;">
  <h2 style="font-size:17px;margin:0 0 6px;">📱 Mã QR cho khách quét chọn hàng</h2>
  <p class="muted" style="font-size:13px;margin:0 0 14px;">
    Khách dùng camera điện thoại quét mã là mở ngay trang chọn hàng của cửa hàng (không cần cài app, không cần đăng nhập):
    chọn số lượng, bấm <b>Đặt hàng</b>, đơn về mục <a href="orders.php?status=DRAFT">Đặt hàng (chờ xử lý)</a> và có email báo cho chủ cửa hàng.
    Dán poster ở cửa tiệm, quầy thu ngân, bàn, hoặc in lên hóa đơn/tờ rơi.
  </p>

  <div class="qr-grid">
    <div class="qr-box">
      <div id="qr-main"></div>
      <div class="qr-cap"><b>Quét để đặt hàng</b><br><?= e(getSetting('store_name', 'Cửa hàng')) ?></div>
    </div>
    <div style="flex:1;min-width:220px;">
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
        <button type="button" class="btn" id="qr-print-main">🖨️ In poster QR</button>
        <button type="button" class="btn btn-secondary" id="qr-download-main">⬇ Tải ảnh QR</button>
      </div>
      <label style="font-size:13px;font-weight:600;">QR riêng cho từng bàn / quầy (mỗi dòng một vị trí)</label>
      <textarea class="input" id="qr-labels" rows="4" placeholder="Bàn 1&#10;Bàn 2&#10;Quầy thu ngân"></textarea>
      <div class="muted" style="font-size:12px;margin:4px 0 8px;">Đơn khách đặt từ mã này sẽ ghi sẵn vị trí (vd "[Bàn 2]") để nhân viên biết mang hàng đến đâu.</div>
      <button type="button" class="btn btn-secondary" id="qr-make-labels">Tạo QR theo vị trí</button>
    </div>
  </div>
  <div id="qr-labels-out" class="qr-sheet"></div>
</div>

<style>
  .qr-grid { display:flex; flex-wrap:wrap; gap:24px; align-items:flex-start; }
  .qr-box { text-align:center; border:2px dashed #cbd5e1; border-radius:14px; padding:16px; width:230px; background:#fff; }
  .qr-box canvas, .qr-box img { max-width:100%; }
  .qr-cap { margin-top:8px; font-size:13px; line-height:1.4; }
  .qr-sheet { display:flex; flex-wrap:wrap; gap:14px; margin-top:16px; }
  .qr-sheet .qr-box { width:190px; padding:12px; }
  .qr-sheet .qr-box .where { font-size:17px; font-weight:700; margin-top:6px; }
  @media print {
    body * { visibility:hidden !important; }
    #qr-print-area, #qr-print-area * { visibility:visible !important; }
    #qr-print-area { position:absolute; left:0; top:0; width:100%; }
  }
</style>
<div id="qr-print-area" style="display:none;"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
  const baseUrl = <?= json_encode($shopUrl, JSON_UNESCAPED_SLASHES) ?>;
  const storeName = <?= json_encode(getSetting('store_name', 'Cửa hàng'), JSON_UNESCAPED_UNICODE) ?>;
  const esc = (t) => String(t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const urlFor = (label) => label ? baseUrl + (baseUrl.includes('?') ? '&' : '?') + 't=' + encodeURIComponent(label) : baseUrl;
  function qrInto(el, text, size) {
    el.innerHTML = '';
    new QRCode(el, { text: text, width: size, height: size, correctLevel: QRCode.CorrectLevel.M });
  }
  if (typeof QRCode === 'undefined') {
    document.getElementById('qr-main').innerHTML = '<div class="muted">Không tải được thư viện tạo mã QR. Kiểm tra kết nối mạng rồi tải lại trang.</div>';
    return;
  }
  qrInto(document.getElementById('qr-main'), urlFor(''), 190);

  function imgOf(el) { const c = el.querySelector('canvas'); return c ? c.toDataURL('image/png') : (el.querySelector('img') || {}).src; }
  function printHtml(html) {
    const area = document.getElementById('qr-print-area');
    area.innerHTML = html; area.style.display = 'block';
    setTimeout(() => { window.print(); area.style.display = 'none'; area.innerHTML = ''; }, 150);
  }
  const posterStyle = 'text-align:center;font-family:sans-serif;';
  function posterHtml(label) {
    const tmp = document.createElement('div'); document.body.appendChild(tmp);
    qrInto(tmp, urlFor(label), 460);
    const src = imgOf(tmp); tmp.remove();
    return '<div style="' + posterStyle + 'page-break-after:always;padding:30px 20px;">' +
      '<div style="font-size:34px;font-weight:800;margin-bottom:6px;">' + esc(storeName) + '</div>' +
      '<div style="font-size:22px;margin-bottom:18px;">Quét mã để chọn hàng &amp; đặt hàng</div>' +
      '<img src="' + src + '" style="width:420px;height:420px;">' +
      (label ? '<div style="font-size:40px;font-weight:800;margin-top:14px;">' + esc(label) + '</div>' : '') +
      '<div style="font-size:16px;color:#475569;margin-top:16px;">Mở camera điện thoại → hướng vào mã QR → chọn món → Đặt hàng</div></div>';
  }
  document.getElementById('qr-print-main').addEventListener('click', () => printHtml(posterHtml('')));
  document.getElementById('qr-download-main').addEventListener('click', () => {
    const a = document.createElement('a'); a.href = imgOf(document.getElementById('qr-main')); a.download = 'qr-dat-hang.png'; a.click();
  });
  document.getElementById('qr-make-labels').addEventListener('click', () => {
    const labels = document.getElementById('qr-labels').value.split('\n').map(s => s.trim()).filter(Boolean).slice(0, 60);
    const out = document.getElementById('qr-labels-out'); out.innerHTML = '';
    if (!labels.length) return;
    labels.forEach(l => {
      const box = document.createElement('div'); box.className = 'qr-box';
      const q = document.createElement('div'); box.appendChild(q);
      const cap = document.createElement('div'); cap.className = 'qr-cap'; cap.innerHTML = '<div class="where">' + esc(l) + '</div>';
      box.appendChild(cap); out.appendChild(box);
      qrInto(q, urlFor(l), 150);
    });
    const bar = document.createElement('div'); bar.style.cssText = 'width:100%;';
    bar.innerHTML = '<button type="button" class="btn" id="qr-print-labels">🖨️ In tất cả (mỗi vị trí 1 trang)</button>';
    out.prepend(bar);
    document.getElementById('qr-print-labels').addEventListener('click', () => printHtml(labels.map(posterHtml).join('')));
  });
})();
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
