<?php
/**
 * Huong dan + nut cai QLBH-CLOUD thanh ung dung tren Windows / Android / iPhone-iPad (PWA).
 * Moi nguoi dung dang nhap deu mo duoc: cai dat la viec cua tung thiet bi.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
require_once __DIR__ . '/inc_header.php';
$duongDan = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Cài ứng dụng QLBH-CLOUD</h1>
<p class="muted" style="margin:0 0 20px;max-width:720px;font-size:14px;">
  Cài QLBH-CLOUD lên máy tính, điện thoại hoặc máy tính bảng để mở bằng một biểu tượng ngoài màn hình như một ứng dụng
  thật: mở nhanh hơn, toàn màn hình, không phải gõ địa chỉ. Dữ liệu vẫn nằm trên máy chủ, cài hay gỡ ứng dụng không mất dữ liệu.
  Mỗi thiết bị cài riêng một lần.
</p>

<div id="pwa-state" class="alert alert-success" style="display:none;max-width:720px;">✅ Thiết bị này đã cài QLBH-CLOUD (đang mở dưới dạng ứng dụng).</div>

<div id="pwa-now" class="card" style="max-width:720px;margin-bottom:20px;">
  <div style="font-weight:600;margin-bottom:6px;">Thiết bị bạn đang dùng: <span id="pwa-dev">…</span></div>
  <div id="pwa-now-help" class="muted" style="font-size:14px;margin-bottom:10px;"></div>
  <button type="button" id="pwa-btn" class="btn" style="display:none;">⬇ Cài ứng dụng vào thiết bị này</button>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;max-width:1000px;margin-bottom:20px;">
  <div class="card" id="g-win">
    <div style="font-size:22px;">💻</div>
    <h2 style="font-size:15px;margin:6px 0;">Máy tính Windows / macOS</h2>
    <p class="muted" style="font-size:13px;margin:0 0 8px;">Dùng <b>Google Chrome</b> hoặc <b>Microsoft Edge</b>:</p>
    <ol style="font-size:13px;margin:0;padding-left:18px;line-height:1.7;">
      <li>Mở trang này, bấm nút <b>Cài ứng dụng</b> ở trên (hoặc biểu tượng ⊕ / máy tính nhỏ ở bên phải thanh địa chỉ).</li>
      <li>Bấm <b>Cài đặt</b> trong hộp thoại hiện ra.</li>
      <li>Biểu tượng QLBH-CLOUD xuất hiện ở màn hình nền / menu Start; ghim xuống thanh tác vụ nếu muốn.</li>
    </ol>
    <p class="muted" style="font-size:12px;margin:8px 0 0;">Không thấy nút? Vào menu ⋮ → <i>Truyền, lưu và chia sẻ</i> → <i>Cài đặt trang này dưới dạng ứng dụng</i> (Chrome) hoặc menu ⋯ → <i>Ứng dụng</i> → <i>Cài đặt trang web này dưới dạng ứng dụng</i> (Edge).</p>
  </div>
  <div class="card" id="g-and">
    <div style="font-size:22px;">🤖</div>
    <h2 style="font-size:15px;margin:6px 0;">Điện thoại / máy tính bảng Android</h2>
    <p class="muted" style="font-size:13px;margin:0 0 8px;">Dùng <b>Chrome</b> (hoặc Samsung Internet, Edge):</p>
    <ol style="font-size:13px;margin:0;padding-left:18px;line-height:1.7;">
      <li>Mở trang này, bấm nút <b>Cài ứng dụng</b> ở trên.</li>
      <li>Nếu không có nút: bấm menu <b>⋮</b> (góc trên bên phải) → <b>Cài đặt ứng dụng</b> hoặc <b>Thêm vào màn hình chính</b>.</li>
      <li>Xác nhận <b>Cài đặt</b>. Biểu tượng QLBH-CLOUD xuất hiện trên màn hình chính.</li>
    </ol>
  </div>
  <div class="card" id="g-ios">
    <div style="font-size:22px;">🍎</div>
    <h2 style="font-size:15px;margin:6px 0;">iPhone / iPad (iOS)</h2>
    <p class="muted" style="font-size:13px;margin:0 0 8px;">Bắt buộc dùng <b>Safari</b> (Chrome trên iPhone không cài được):</p>
    <ol style="font-size:13px;margin:0;padding-left:18px;line-height:1.7;">
      <li>Mở trang này bằng Safari.</li>
      <li>Bấm nút <b>Chia sẻ</b> (hình vuông có mũi tên lên) ở thanh dưới hoặc trên cùng.</li>
      <li>Kéo xuống chọn <b>Thêm vào MH chính</b> (Add to Home Screen) → <b>Thêm</b>.</li>
    </ol>
    <p class="muted" style="font-size:12px;margin:8px 0 0;">iOS không có nút cài tự động, nên chỉ làm được theo các bước trên.</p>
  </div>
</div>

<div class="card" style="max-width:720px;margin-bottom:20px;">
  <h2 style="font-size:15px;margin:0 0 8px;">Gửi link cài cho nhân viên</h2>
  <p class="muted" style="font-size:13px;margin:0 0 8px;">Gửi địa chỉ dưới đây qua Zalo hoặc tin nhắn; nhân viên mở trên thiết bị của mình, đăng nhập rồi làm theo hướng dẫn ở trên.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <input class="input" id="pwa-link" readonly value="<?= e($duongDan) ?>cai_ung_dung.php" style="flex:1;min-width:220px;">
    <button type="button" class="btn btn-secondary" id="pwa-copy">Sao chép link</button>
  </div>
</div>

<div class="card" style="max-width:720px;margin-bottom:20px;">
  <h2 style="font-size:15px;margin:0 0 8px;">Dùng ứng dụng</h2>
  <ul style="font-size:13px;margin:0;padding-left:18px;line-height:1.7;">
    <li>Mở bằng biểu tượng QLBH-CLOUD ngoài màn hình; đăng nhập một lần, lần sau vào thẳng (đến khi đăng xuất).</li>
    <li>Cần có mạng để bán hàng và xem số liệu; mất mạng ứng dụng báo "không có kết nối" thay vì treo.</li>
    <li>Cập nhật phần mềm tự động, không cần cài lại.</li>
    <li>Gỡ ứng dụng như gỡ ứng dụng thường (nhấn giữ biểu tượng → Gỡ / Xóa; trên máy tính: mở ứng dụng → menu ⋮ → Gỡ cài đặt). Dữ liệu cửa hàng không bị mất.</li>
  </ul>
</div>

<script>
(function () {
  var ua = navigator.userAgent || '';
  var ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var android = /Android/i.test(ua);
  var dev = ios ? 'iPhone / iPad' : (android ? 'Android' : 'Máy tính');
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  var $ = function (id) { return document.getElementById(id); };
  $('pwa-dev').textContent = dev;
  if (standalone) $('pwa-state').style.display = 'block';
  var help = $('pwa-now-help'), btn = $('pwa-btn'), deferred = null;
  // Lam noi bat huong dan dung voi thiet bi dang dung
  var mine = ios ? 'g-ios' : (android ? 'g-and' : 'g-win');
  $(mine).style.borderColor = '#2563eb'; $(mine).style.boxShadow = '0 0 0 2px #bfdbfe';
  if (standalone) {
    help.textContent = 'Bạn đang dùng ứng dụng đã cài. Muốn cài thêm trên thiết bị khác, gửi link bên dưới.';
  } else if (ios) {
    help.textContent = /CriOS|FxiOS|EdgiOS/.test(ua)
      ? 'Bạn đang dùng trình duyệt không hỗ trợ cài đặt trên iPhone. Hãy mở link này bằng Safari rồi làm theo hướng dẫn iOS bên dưới.'
      : 'Làm theo hướng dẫn iPhone / iPad bên dưới (Chia sẻ → Thêm vào MH chính).';
  } else {
    help.textContent = 'Đang kiểm tra trình duyệt có hỗ trợ cài đặt…';
    setTimeout(function () {
      if (!deferred) help.textContent = 'Trình duyệt này chưa hiện nút cài tự động. Hãy dùng Chrome hoặc Edge, hoặc làm theo hướng dẫn thủ công bên dưới.';
    }, 2500);
  }
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); deferred = e;
    btn.style.display = 'inline-block';
    help.textContent = 'Trình duyệt sẵn sàng cài đặt. Bấm nút bên dưới.';
  });
  btn.addEventListener('click', function () {
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.finally(function () { deferred = null; btn.style.display = 'none'; });
  });
  window.addEventListener('appinstalled', function () {
    btn.style.display = 'none'; help.textContent = 'Đã cài đặt xong. Mở QLBH-CLOUD từ biểu tượng ngoài màn hình / menu Start.';
  });
  $('pwa-copy').addEventListener('click', function () {
    var el = $('pwa-link'); el.select();
    var b = this;
    (navigator.clipboard ? navigator.clipboard.writeText(el.value) : Promise.reject()).catch(function () { document.execCommand('copy'); });
    b.textContent = 'Đã sao chép ✓'; setTimeout(function () { b.textContent = 'Sao chép link'; }, 1500);
  });
})();
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
