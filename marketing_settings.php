<?php
/**
 * Cau hinh kenh marketing (§8 dac ta).
 *
 * Pham vi CO Y gioi han - va noi that ngay tren trang: he thong LUU chien dich va cho phep xuat
 * danh sach nguoi nhan, chu KHONG tu gui SMS/Email hang loat. Gui that doi hoi tai khoan gateway
 * rieng (SMS brandname phai dang ky voi nha mang, email hang loat phai co domain da xac thuc
 * SPF/DKIM) - lam nua voi khong co nhung thu do thi tin se roi thang vao hom thu rac va ten mien
 * bi danh dau, hai nhieu hon loi.
 *
 * Nhung gi khai bao o day duoc dung de: hien dung ten nguoi gui tren chien dich, va nhac nguoi
 * dung con thieu gi truoc khi mang di gui.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    setSetting('mkt_sender_name', mb_substr(trim(post('sender_name')), 0, 100));
    setSetting('mkt_reply_email', mb_substr(trim(post('reply_email')), 0, 150));
    setSetting('mkt_sms_brandname', mb_substr(trim(post('sms_brandname')), 0, 50));
    setSetting('mkt_sms_provider', mb_substr(trim(post('sms_provider')), 0, 100));
    setSetting('mkt_footer', mb_substr(trim(post('footer')), 0, 300));
    logActivity('MARKETING_SETTINGS_SAVE', '');
    $saved = true;
}

$senderName = getSetting('mkt_sender_name', '');
$replyEmail = getSetting('mkt_reply_email', '');
$smsBrandname = getSetting('mkt_sms_brandname', '');
$smsProvider = getSetting('mkt_sms_provider', '');
$footer = getSetting('mkt_footer', '');

require_once __DIR__ . '/inc_header.php';
?>

<a href="campaigns.php" class="muted" style="font-size:14px;">← Chiến dịch</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 4px;">Cấu hình kênh marketing</h1>

<?php if ($saved): ?><div class="alert alert-success">Đã lưu cấu hình kênh marketing.</div><?php endif; ?>

<?php hopDieuKienTrienKhai(
    'Những gì khai báo ở đây được dùng để hiển thị đúng tên người gửi trên chiến dịch. Phần mềm
     <b>chưa tự gửi</b> SMS/Email hàng loạt.',
    [
        '<b>SMS</b>: brandname đã đăng ký với nhà mạng + tài khoản nhà cung cấp SMS.',
        '<b>Email</b>: tên miền riêng đã cấu hình SPF và DKIM.',
        'Lập trình phần kết nối với nhà cung cấp bạn chọn.',
    ],
    'Khai báo sẵn ở đây trước cũng có ích: khi làm phần gửi thật thì không phải nhập lại.'
); ?>

<form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

  <div class="card" style="max-width:720px;margin-bottom:16px;">
    <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">Email</h3>
    <div class="grid-2">
      <div class="field">
        <label for="f-sender">Tên người gửi hiển thị</label>
        <input class="input" id="f-sender" name="sender_name" value="<?= e($senderName) ?>" placeholder="vd: Tạp hóa Ngọc Lan">
      </div>
      <div class="field">
        <label for="f-reply">Email nhận phản hồi</label>
        <input class="input" id="f-reply" type="email" name="reply_email" value="<?= e($replyEmail) ?>" placeholder="vd: cskh@cuahangcuaban.vn">
      </div>
    </div>
  </div>

  <div class="card" style="max-width:720px;margin-bottom:16px;">
    <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">SMS</h3>
    <div class="grid-2">
      <div class="field">
        <label for="f-brand">Brandname</label>
        <input class="input" id="f-brand" name="sms_brandname" value="<?= e($smsBrandname) ?>" placeholder="vd: NGOCLAN">
        <p class="muted" style="font-size:12px;margin:4px 0 0;">Tên hiển thị khi khách nhận tin. Phải đăng ký với nhà mạng.</p>
      </div>
      <div class="field">
        <label for="f-provider">Nhà cung cấp SMS</label>
        <input class="input" id="f-provider" name="sms_provider" value="<?= e($smsProvider) ?>" placeholder="vd: Viettel, VNPT, eSMS...">
      </div>
    </div>
  </div>

  <div class="card" style="max-width:720px;margin-bottom:16px;">
    <div class="field">
      <label for="f-footer">Chân trang gắn vào cuối mỗi tin</label>
      <input class="input" id="f-footer" name="footer" value="<?= e($footer) ?>" placeholder="vd: Soạn TU gửi 9999 để ngừng nhận tin">
      <p class="muted" style="font-size:12px;margin:4px 0 0;">
        Nên có cách để khách từ chối nhận tin — vừa đúng quy định, vừa đỡ bị báo cáo là tin rác.
      </p>
    </div>
  </div>

  <div style="max-width:720px;text-align:right;">
    <button type="submit" class="btn">Lưu cấu hình</button>
  </div>
</form>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
