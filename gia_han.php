<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

const SUPPORT_PHONE = '0945289666';
const SUPPORT_EMAIL = 'hoangnamcnc@gmail.com';

$pdo = db();
$tenant = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
$tenant->execute([$currentUser['tenant_id']]);
$tenant = $tenant->fetch();

$sent = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $contactName = post('contact_name') ?: $currentUser['name'];
    $contactPhone = post('contact_phone');
    $message = post('message') ?: null;

    if ($contactPhone === '') {
        $error = 'Vui lòng nhập số điện thoại/Zalo để KT-SOFT liên hệ lại.';
    } else {
        $pdo->prepare(
            'INSERT INTO renewal_requests (tenant_id, contact_name, contact_phone, message) VALUES (?, ?, ?, ?)'
        )->execute([$currentUser['tenant_id'], $contactName, $contactPhone, $message]);

        $subject = 'QLBH2 - Yeu cau gia han: ' . $tenant['name'];
        $body = "Cua hang: {$tenant['name']}\n"
            . "Email dang ky: {$tenant['owner_email']}\n"
            . "Nguoi lien he: $contactName\n"
            . "SDT/Zalo: $contactPhone\n"
            . "Ghi chu: " . ($message ?: '(không có)') . "\n"
            . "Han hien tai: " . ($tenant['trial_ends_at'] ?? '(gói trả phí)') . "\n";
        $headers = 'From: QLBH2 <no-reply@kt-soft.vn>' . "\r\n" . 'Reply-To: ' . (post('contact_email') ?: 'no-reply@kt-soft.vn');
        @mail(SUPPORT_EMAIL, $subject, $body, $headers);

        logActivity('RENEWAL_REQUEST', 'tenant_id=' . $currentUser['tenant_id']);
        $sent = true;
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:16px;">Yêu cầu gia hạn / nâng cấp</h1>

<div class="card" style="max-width:640px;">
  <?php if ($sent): ?>
    <div class="alert alert-success">
      Đã gửi yêu cầu gia hạn thành công! KT-SOFT sẽ liên hệ lại sớm nhất qua số điện thoại/Zalo bạn
      cung cấp.
    </div>
  <?php else: ?>
    <p class="muted" style="margin:0 0 16px;">
      Gói hiện tại của bạn <?= $tenant['plan'] === 'PAID' ? 'là <b>gói trả phí</b>' : (($tenant['trial_ends_at'] ?? null) ? 'là <b>dùng thử</b>, ' . (strtotime($tenant['trial_ends_at']) < time() ? '<b style="color:#dc2626;">đã hết hạn</b>' : 'hết hạn ngày <b>' . date('d/m/Y', strtotime($tenant['trial_ends_at'])) . '</b>') : 'dùng thử') ?>.
      Gửi yêu cầu bên dưới, KT-SOFT sẽ liên hệ gia hạn/nâng cấp cho bạn (gia hạn tính theo năm).
    </p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <label>Người liên hệ</label>
      <input type="text" name="contact_name" value="<?= e($currentUser['name']) ?>" style="width:100%;margin-bottom:12px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
      <label>Số điện thoại / Zalo *</label>
      <input type="text" name="contact_phone" required style="width:100%;margin-bottom:12px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;">
      <label>Ghi chú (ví dụ: muốn gia hạn bao lâu, nâng cấp gói nào...)</label>
      <textarea name="message" rows="3" style="width:100%;margin-bottom:16px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;font-family:inherit;"></textarea>
      <button type="submit" class="btn">Gửi yêu cầu gia hạn</button>
    </form>
  <?php endif; ?>

  <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;font-size:13px;color:#64748b;">
    Hoặc liên hệ trực tiếp KT-SOFT:<br>
    📞 Điện thoại / Zalo: <b><?= SUPPORT_PHONE ?></b><br>
    📧 Email: <b><?= SUPPORT_EMAIL ?></b>
  </div>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
