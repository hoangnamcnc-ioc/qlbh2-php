<?php
/**
 * Doi soat chuyen khoan tu dong: cau hinh ket noi thong bao bien dong so du (SePay/Casso...), xem cac giao dich ngan hang da nhan,
 * gan tay giao dich chua khop vao don, va thu kiem tra duong ket noi. Chi Quan tri/Quan ly nhom Tai chinh.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_bank.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$msg = null; $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'regen' && hasRole('ADMIN')) {
        bankWebhookKey($tenantId, true);
        logActivity('BANK_WEBHOOK_KEY_REGEN');
        $msg = 'Đã tạo khóa mới. Hãy cập nhật khóa này ở dịch vụ thông báo biến động số dư (khóa cũ không còn dùng được).';
    } elseif ($action === 'simulate') {
        $amount = (float) ($_POST['amount'] ?? 0);
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($amount <= 0 || $content === '') {
            $error = 'Nhập số tiền và nội dung chuyển khoản để thử.';
        } else {
            $r = bankXuLyGiaoDich($pdo, $tenantId, ['provider' => 'manual-test', 'id' => 'T' . bin2hex(random_bytes(6)), 'amount' => $amount, 'content' => $content, 'time' => date('c')]);
            $msg = $r['status'] === 'MATCHED' ? 'Khớp đơn ' . $r['order_code'] . ' — đã ghi nhận thu.' : ($r['status'] === 'POS_REF' ? 'Nhận diện mã thanh toán POS.' : 'Giao dịch chưa khớp đơn nào (xem bảng bên dưới).');
        }
    } elseif ($action === 'assign') {
        $txRowId = (int) ($_POST['tx'] ?? 0);
        $code = trim((string) ($_POST['code'] ?? ''));
        $pdo->beginTransaction();
        try {
            $tx = $pdo->prepare("SELECT * FROM bank_transactions WHERE id = ? AND tenant_id = ? AND status = 'UNMATCHED' FOR UPDATE");
            $tx->execute([$txRowId, $tenantId]);
            $tx = $tx->fetch();
            $o = $pdo->prepare("SELECT o.* FROM orders o JOIN branches b ON b.id = o.branch_id WHERE o.code = ? AND b.tenant_id = ? AND o.status <> 'CANCELLED' FOR UPDATE");
            $o->execute([$code, $tenantId]);
            $o = $o->fetch();
            if (!$tx || !$o) {
                throw new RuntimeException('Không tìm thấy giao dịch chưa khớp hoặc mã đơn không hợp lệ.');
            }
            $applied = bankGhiThuDon($pdo, $o, (float) $tx['amount'], (int) $currentUser['id'], 'Thu chuyển khoản đơn hàng ' . $o['code'] . ' (gán tay)');
            if ($applied <= 0) {
                throw new RuntimeException('Đơn này đã thanh toán đủ, không còn số tiền cần thu.');
            }
            $pdo->prepare("UPDATE bank_transactions SET status = 'MATCHED', order_id = ?, applied_amount = ? WHERE id = ?")->execute([$o['id'], $applied, $txRowId]);
            $pdo->commit();
            logActivity('BANK_TX_ASSIGN', "tx=$txRowId order={$o['code']} amount=$applied");
            $msg = 'Đã ghi nhận ' . money($applied) . ' vào đơn ' . $o['code'] . ($applied < (float) $tx['amount'] ? ' (phần dư ' . money((float) $tx['amount'] - $applied) . ' không ghi vào đơn).' : '.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Không gán được giao dịch, vui lòng thử lại.';
        }
    } elseif ($action === 'ignore') {
        $pdo->prepare("UPDATE bank_transactions SET status = 'IGNORED' WHERE id = ? AND tenant_id = ? AND status = 'UNMATCHED'")->execute([(int) ($_POST['tx'] ?? 0), $tenantId]);
        $msg = 'Đã bỏ qua giao dịch.';
    }
}

$key = bankWebhookKey($tenantId);
$webhookUrl = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/bank_webhook.php';
$webhookUrl = str_replace('//bank_webhook.php', '/bank_webhook.php', $webhookUrl);
$stmt = $pdo->prepare('SELECT t.*, o.code AS order_code FROM bank_transactions t LEFT JOIN orders o ON o.id = t.order_id WHERE t.tenant_id = ? ORDER BY t.id DESC LIMIT 100');
$stmt->execute([$tenantId]);
$txs = $stmt->fetchAll();
$unmatched = count(array_filter($txs, static fn($t) => $t['status'] === 'UNMATCHED'));
$labels = ['MATCHED' => ['Đã khớp đơn', '#059669'], 'POS_REF' => ['Chờ đơn POS', '#d97706'], 'POS_DONE' => ['Đã gắn đơn POS', '#059669'], 'UNMATCHED' => ['Chưa khớp', '#dc2626'], 'IGNORED' => ['Đã bỏ qua', '#64748b']];

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:4px;">Đối soát chuyển khoản tự động</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:780px;">
  Khi khách chuyển khoản, dịch vụ thông báo biến động số dư (SePay, Casso...) gửi giao dịch về đây; hệ thống tìm <b>mã đơn</b> trong nội dung chuyển khoản
  (khách quét mã QR sau khi đặt hàng nên nội dung đã có sẵn mã đơn) và <b>tự ghi nhận thu</b> vào đơn, sổ quỹ, công nợ. Chuyển khoản tại quầy POS dùng <b>mã thanh toán TTxxxxxx</b> hiện cùng mã QR.
</p>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:820px;margin-bottom:20px;">
  <h2 style="font-size:15px;margin:0 0 8px;">1. Kết nối dịch vụ thông báo biến động số dư</h2>
  <ol style="font-size:13.5px;line-height:1.7;margin:0 0 10px 18px;padding:0;">
    <li>Đăng ký dịch vụ liên kết tài khoản ngân hàng nhận tiền của cửa hàng (vd <b>SePay</b> hoặc <b>Casso</b> — có gói miễn phí/giá thấp, cửa hàng tự ký với họ).</li>
    <li>Trong phần <b>Webhook</b> của dịch vụ, nhập <b>địa chỉ nhận</b> và <b>khóa</b> bên dưới (kiểu xác thực: <i>API Key</i> / <i>secure token</i>; hoặc dùng địa chỉ có kèm <code>?key=</code>).</li>
    <li>Bấm "Gửi thử" ở dịch vụ, hoặc dùng ô thử bên dưới để kiểm tra đường truyền.</li>
  </ol>
  <label style="font-size:12px;font-weight:600;">Địa chỉ nhận (Webhook URL)</label>
  <input class="input" readonly value="<?= e($webhookUrl) ?>" onclick="this.select()" style="font-family:monospace;font-size:12.5px;">
  <label style="font-size:12px;font-weight:600;margin-top:8px;display:block;">Khóa xác thực (giữ bí mật)</label>
  <input class="input" readonly value="<?= e($key) ?>" onclick="this.select()" style="font-family:monospace;font-size:12.5px;">
  <label style="font-size:12px;font-weight:600;margin-top:8px;display:block;">Hoặc dùng một địa chỉ duy nhất (nếu dịch vụ không cho nhập khóa riêng)</label>
  <input class="input" readonly value="<?= e($webhookUrl . '?key=' . $key) ?>" onclick="this.select()" style="font-family:monospace;font-size:11.5px;">
  <?php if (hasRole('ADMIN')): ?>
    <form method="post" style="margin-top:10px;" onsubmit="return confirm('Tạo khóa mới? Khóa cũ sẽ ngừng hoạt động, bạn phải cập nhật lại ở dịch vụ thông báo.');">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="regen">
      <button class="btn btn-secondary" style="padding:4px 10px;font-size:12.5px;">Tạo khóa mới</button>
    </form>
  <?php endif; ?>
  <hr style="border:none;border-top:1px solid #e2e8f0;margin:14px 0;">
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="simulate">
    <div><label style="font-size:12px;">Thử giao dịch — số tiền</label><input class="input" type="number" name="amount" min="1" style="width:140px;"></div>
    <div style="flex:1;min-width:200px;"><label style="font-size:12px;">Nội dung chuyển khoản (vd chứa mã đơn)</label><input class="input" name="content" placeholder="vd: DH6CLAULECF"></div>
    <button class="btn btn-secondary">Thử</button>
  </form>
  <p class="muted" style="font-size:12px;margin:6px 0 0;">Giao dịch thử được xử lý như giao dịch thật (nếu khớp đơn sẽ ghi thu thật) — chỉ dùng mã đơn bạn thực sự muốn ghi thu.</p>
</div>

<h2 style="font-size:16px;margin:0 0 8px;">Giao dịch ngân hàng gần đây <?php if ($unmatched): ?><span style="color:#dc2626;font-weight:600;">· <?= (int) $unmatched ?> chưa khớp</span><?php endif; ?></h2>
<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Thời gian</th><th class="text-right">Số tiền</th><th>Nội dung</th><th>Trạng thái</th><th>Xử lý</th></tr></thead>
    <tbody>
      <?php if (!$txs): ?><tr><td colspan="5" class="text-center muted" style="padding:22px;">Chưa nhận giao dịch nào. Sau khi kết nối, các khoản tiền vào tài khoản sẽ hiện ở đây.</td></tr><?php endif; ?>
      <?php foreach ($txs as $t): [$lab, $col] = $labels[$t['status']]; ?>
        <tr>
          <td class="muted"><?= e(date('d/m H:i', strtotime($t['tx_time']))) ?></td>
          <td class="text-right"><b><?= money($t['amount']) ?></b></td>
          <td style="max-width:320px;word-break:break-word;"><?= e((string) $t['content']) ?></td>
          <td><span style="color:<?= $col ?>;font-weight:600;"><?= e($lab) ?></span><?= $t['order_code'] ? ' · <a href="order_view.php?id=' . (int) $t['order_id'] . '">' . e($t['order_code']) . '</a>' : '' ?><?= $t['pos_ref'] ? ' · ' . e($t['pos_ref']) : '' ?></td>
          <td>
            <?php if ($t['status'] === 'UNMATCHED'): ?>
              <form method="post" style="display:flex;gap:4px;">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="tx" value="<?= (int) $t['id'] ?>">
                <input class="input" name="code" placeholder="Mã đơn" style="width:130px;padding:4px 8px;font-size:12.5px;" required>
                <button class="btn" style="padding:4px 10px;font-size:12px;">Gán vào đơn</button>
              </form>
              <form method="post" style="margin-top:4px;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="ignore"><input type="hidden" name="tx" value="<?= (int) $t['id'] ?>">
                <button class="btn btn-secondary" style="padding:2px 8px;font-size:11.5px;">Bỏ qua</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
