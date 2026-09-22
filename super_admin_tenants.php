<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
require_once __DIR__ . '/inc_vnpay.php';
requireSuperAdmin();

$pdo = db();
$error = null;
$newPaymentLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'create_payment_link') {
        $amount = (int) ($_POST['amount'] ?? 0);
        $months = max(1, (int) ($_POST['months'] ?? 12));
        if ($amount < 1000) {
            $error = 'Số tiền không hợp lệ.';
        } else {
            $orderCode = vnpayGenOrderCode();
            $pdo->prepare('INSERT INTO subscription_payments (tenant_id, order_code, amount, months) VALUES (?, ?, ?, ?)')
                ->execute([$id, $orderCode, $amount, $months]);
            logActivity('SUPER_ADMIN_PAYMENT_LINK_CREATE', "tenant_id=$id order=$orderCode amount=$amount");
            $newPaymentLink = 'https://' . $_SERVER['HTTP_HOST'] . '/pay.php?order=' . $orderCode;
        }
    }

    // Khong cho tu khoa/tu ha cap chinh tenant #1 (chu so huu) de tranh tu khoa minh ra khoi he thong.
    if ($id === 1 && $action !== 'create_payment_link') {
        redirect('super_admin_tenants.php');
    }

    // Cac thao tac gia han gan chat voi GOI hien tai: nhom dung thu dat plan='TRIAL', nhom tra
    // phi ghi vao paid_until. Ap nham chieu nao cung sai (vd "+1 nam" dung thu se ha mot khach
    // da tra phi xuong dung thu), nen chan chung mot cho o day truoc khi phan nhanh - an nut
    // tren giao dien khong phai la kiem soat.
    $nhomDungThu = ['extend_trial', 'set_trial_end'];
    $nhomTraPhi  = ['extend_paid', 'set_paid_until'];
    if (in_array($action, $nhomDungThu, true) || in_array($action, $nhomTraPhi, true)) {
        $cur = $pdo->prepare('SELECT plan, paid_until FROM tenants WHERE id = ?');
        $cur->execute([$id]);
        $curRow = $cur->fetch();
        $dangTraPhi = $curRow && $curRow['plan'] === 'PAID';

        if (in_array($action, $nhomDungThu, true) && $dangTraPhi) {
            redirect('super_admin_tenants.php?err=paid');
        }
        if (in_array($action, $nhomTraPhi, true) && !$dangTraPhi) {
            redirect('super_admin_tenants.php?err=trial');
        }
        // paid_until = NULL nghia la KHONG GIOI HAN. Cong them thang vao do se bien "khong gioi
        // han" thanh "co han" - dung la dieu nguoc voi y muon khi bam nut gia han. Muon dat han
        // cho khach dang khong gioi han thi phai dung o chon ngay (viec co y, khong phai lo tay).
        if ($action === 'extend_paid' && $curRow['paid_until'] === null) {
            redirect('super_admin_tenants.php?err=unlimited');
        }
    }

    if ($action === 'toggle_active') {
        $pdo->prepare('UPDATE tenants SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        logActivity('SUPER_ADMIN_TENANT_TOGGLE', 'tenant_id=' . $id);
    } elseif ($action === 'upgrade_paid') {
        $pdo->prepare("UPDATE tenants SET plan = 'PAID', trial_ends_at = NULL WHERE id = ?")->execute([$id]);
        logActivity('SUPER_ADMIN_TENANT_UPGRADE', 'tenant_id=' . $id);
    } elseif ($action === 'extend_trial') {
        $days = max(1, (int) ($_POST['days'] ?? 365));
        $pdo->prepare(
            "UPDATE tenants SET plan = 'TRIAL', trial_ends_at = DATE_ADD(GREATEST(COALESCE(trial_ends_at, NOW()), NOW()), INTERVAL ? DAY) WHERE id = ?"
        )->execute([$days, $id]);
        logActivity('SUPER_ADMIN_TENANT_EXTEND', "tenant_id=$id days=$days");
    } elseif ($action === 'set_trial_end') {
        // Nut "+1 nam" chi CONG duoc (days ep toi thieu 1), nen bam nham la khong lui lai duoc.
        // Day la duong dat thang han su dung ve dung ngay mong muon - ke ca ngay trong qua khu
        // (de ket thuc dung thu ngay lap tuc).
        $date = trim($_POST['trial_end'] ?? '');
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            redirect('super_admin_tenants.php?err=date');
        }
        $pdo->prepare("UPDATE tenants SET plan = 'TRIAL', trial_ends_at = ? WHERE id = ?")
            ->execute([$d->format('Y-m-d') . ' 23:59:59', $id]);
        logActivity('SUPER_ADMIN_TENANT_SET_TRIAL_END', "tenant_id=$id date=$date");
    } elseif ($action === 'extend_paid') {
        // Dung dung cong thuc cua VNPay (vnpay_ipn.php) de gia han tay va gia han online cho ra
        // cung ket qua: cong tiep tu han cu neu con hieu luc, tu hom nay neu da het han.
        $months = max(1, min(120, (int) ($_POST['months'] ?? 12)));
        $pdo->prepare(
            "UPDATE tenants SET paid_until = DATE_ADD(GREATEST(COALESCE(paid_until, NOW()), NOW()), INTERVAL ? MONTH) WHERE id = ?"
        )->execute([$months, $id]);
        logActivity('SUPER_ADMIN_TENANT_EXTEND_PAID', "tenant_id=$id months=$months");
    } elseif ($action === 'set_paid_until') {
        if (($_POST['unlimited'] ?? '') === '1') {
            $pdo->prepare('UPDATE tenants SET paid_until = NULL WHERE id = ?')->execute([$id]);
            logActivity('SUPER_ADMIN_TENANT_PAID_UNLIMITED', "tenant_id=$id");
        } else {
            $date = trim($_POST['paid_until'] ?? '');
            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                redirect('super_admin_tenants.php?err=date');
            }
            $pdo->prepare('UPDATE tenants SET paid_until = ? WHERE id = ?')
                ->execute([$d->format('Y-m-d') . ' 23:59:59', $id]);
            logActivity('SUPER_ADMIN_TENANT_SET_PAID_UNTIL', "tenant_id=$id date=$date");
        }
    } elseif ($action === 'resolve_renewal') {
        $reqId = (int) ($_POST['req_id'] ?? 0);
        $pdo->prepare("UPDATE renewal_requests SET status = 'DONE' WHERE id = ? AND tenant_id = ?")->execute([$reqId, $id]);
        logActivity('SUPER_ADMIN_RENEWAL_RESOLVE', "tenant_id=$id req_id=$reqId");
    }
    // Khong redirect khi vua tao link thanh toan - can giu $newPaymentLink de hien thi cho chu
    // he thong copy gui khach (redirect se lam mat bien nay vi day la request moi).
    if ($action !== 'create_payment_link') {
        redirect('super_admin_tenants.php');
    }
}

$pendingRenewals = $pdo->query(
    "SELECT r.*, t.name AS tenant_name
     FROM renewal_requests r JOIN tenants t ON t.id = r.tenant_id
     WHERE r.status = 'PENDING'
     ORDER BY r.created_at DESC"
)->fetchAll();

$tenants = $pdo->query(
    "SELECT t.*,
            (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count,
            (SELECT COUNT(*) FROM products p WHERE p.tenant_id = t.id) AS product_count,
            (SELECT COUNT(*) FROM orders o JOIN branches b ON b.id = o.branch_id WHERE b.tenant_id = t.id) AS order_count,
            (SELECT MAX(al.created_at) FROM activity_logs al WHERE al.tenant_id = t.id) AS last_activity
     FROM tenants t
     ORDER BY t.created_at DESC"
)->fetchAll();

$totalTenants = count($tenants);
$totalTrial = count(array_filter($tenants, fn ($t) => $t['plan'] === 'TRIAL'));
$totalPaid = count(array_filter($tenants, fn ($t) => $t['plan'] === 'PAID'));
$totalActiveUsers = count(array_filter($tenants, fn ($t) => $t['last_activity'] && strtotime($t['last_activity']) >= strtotime('-7 days')));

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:4px;">Quản trị hệ thống — Danh sách khách hàng (tenant)</h1>
<p class="muted" style="margin:0 0 24px;font-size:13px;">
  Trang riêng cho chủ hệ thống KT-SOFT — không phải nghiệp vụ của 1 cửa hàng. Chỉ tài khoản ADMIN
  gốc mới xem được trang này.
</p>

<?php if (!vnpayConfigured()): ?>
  <div class="alert" style="background:#fef9c3;border:1px solid #fde047;color:#854d0e;margin-bottom:20px;">
    ⚠️ Chưa cấu hình VNPay (điền <code>VNPAY_TMN_CODE</code>/<code>VNPAY_HASH_SECRET</code> trong <code>config.php</code>) — link thanh toán vẫn tạo được nhưng khách sẽ thấy thông báo "chưa sẵn sàng" khi bấm thanh toán.
  </div>
<?php endif; ?>

<?php if ($error): ?><div class="alert alert-error" style="margin-bottom:20px;"><?= e($error) ?></div><?php endif; ?>
<?php if (($_GET['err'] ?? '') === 'date'): ?>
  <div class="alert alert-error" style="margin-bottom:20px;">Ngày không hợp lệ — chưa thay đổi hạn dùng thử của cửa hàng nào.</div>
<?php endif; ?>
<?php if (($_GET['err'] ?? '') === 'trial'): ?>
  <div class="alert alert-error" style="margin-bottom:20px;">Cửa hàng này đang ở <b>gói dùng thử</b> — dùng nhóm nút "hạn dùng thử" cho họ. Chưa thay đổi gì.</div>
<?php endif; ?>
<?php if (($_GET['err'] ?? '') === 'unlimited'): ?>
  <div class="alert alert-error" style="margin-bottom:20px;">Cửa hàng này đang <b>không giới hạn hạn sử dụng</b> — cộng thêm tháng sẽ biến thành có hạn, ngược với ý muốn. Nếu thật sự muốn đặt hạn, hãy dùng ô chọn ngày. Chưa thay đổi gì.</div>
<?php endif; ?>
<?php if (($_GET['err'] ?? '') === 'paid'): ?>
  <div class="alert alert-error" style="margin-bottom:20px;">Cửa hàng này đang ở <b>gói trả phí</b> — không đặt hạn dùng thử cho họ được (sẽ vô tình hạ họ về gói dùng thử). Chưa thay đổi gì.</div>
<?php endif; ?>
<?php if ($newPaymentLink): ?>
  <div class="alert alert-success" style="margin-bottom:20px;">
    ✅ Đã tạo link thanh toán — gửi link này cho khách hàng:<br>
    <input type="text" readonly value="<?= e($newPaymentLink) ?>" onclick="this.select()" style="width:100%;margin-top:8px;padding:8px 10px;border:1px solid #86efac;border-radius:6px;font-family:monospace;font-size:13px;">
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;">
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Tổng số khách hàng</div>
    <div style="font-size:22px;font-weight:700;"><?= $totalTenants ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Đang dùng thử</div>
    <div style="font-size:22px;font-weight:700;color:#d97706;"><?= $totalTrial ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Đã trả phí</div>
    <div style="font-size:22px;font-weight:700;color:#059669;"><?= $totalPaid ?></div>
  </div>
  <div class="card">
    <div class="muted" style="font-size:12px;text-transform:uppercase;margin-bottom:4px;">Hoạt động 7 ngày qua</div>
    <div style="font-size:22px;font-weight:700;color:#2563eb;"><?= $totalActiveUsers ?></div>
  </div>
</div>

<?php if ($pendingRenewals): ?>
<div class="card" style="margin-bottom:24px;">
  <div style="font-weight:600;margin-bottom:12px;">📩 Yêu cầu gia hạn đang chờ (<?= count($pendingRenewals) ?>)</div>
  <table>
    <thead><tr><th>Cửa hàng</th><th>Người liên hệ</th><th>SĐT/Zalo</th><th>Ghi chú</th><th>Thời gian gửi</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($pendingRenewals as $r): ?>
        <tr>
          <td><b><?= e($r['tenant_name']) ?></b></td>
          <td><?= e($r['contact_name']) ?></td>
          <td style="font-family:monospace;"><?= e($r['contact_phone']) ?></td>
          <td class="muted"><?= e($r['message'] ?: '—') ?></td>
          <td class="muted" style="font-size:12px;"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
          <td>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="id" value="<?= (int) $r['tenant_id'] ?>">
              <input type="hidden" name="req_id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="action" value="resolve_renewal">
              <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;">Đã liên hệ</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>Cửa hàng</th><th>Email quản trị</th><th>Ngày đăng ký</th><th>Gói</th>
        <th>Hạn sử dụng</th><th class="text-right">NV</th><th class="text-right">SP</th>
        <th class="text-right">Đơn hàng</th><th>Hoạt động gần nhất</th><th>Trạng thái</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tenants as $t): ?>
        <?php
          $isOwner = (int) $t['id'] === 1;
          $daysLeft = $t['trial_ends_at'] ? ceil((strtotime($t['trial_ends_at']) - time()) / 86400) : null;
          $lastActive = $t['last_activity'] ? date('d/m/Y H:i', strtotime($t['last_activity'])) : '—';
        ?>
        <tr>
          <td><b><?= e($t['name']) ?></b><?php if ($isOwner): ?> <span class="badge badge-gray">Chủ sở hữu</span><?php endif; ?></td>
          <td class="muted" style="font-family:monospace;font-size:12px;"><?= e($t['owner_email'] ?: '—') ?></td>
          <td class="muted"><?= date('d/m/Y', strtotime($t['created_at'])) ?></td>
          <td>
            <?php if ($t['plan'] === 'PAID'): ?>
              <span class="badge badge-green">Trả phí</span>
            <?php else: ?>
              <span class="badge badge-gray">Dùng thử</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($t['plan'] === 'PAID'): ?>
              <?php if (!$t['paid_until']): ?>
                <span class="muted">Không giới hạn</span>
              <?php else: ?>
                <?php $paidDaysLeft = ceil((strtotime($t['paid_until']) - time()) / 86400); ?>
                <?php if ($paidDaysLeft < 0): ?>
                  <span style="color:#dc2626;font-weight:600;">Đã hết hạn</span>
                <?php else: ?>
                  Còn <?= (int) $paidDaysLeft ?> ngày
                <?php endif; ?>
                <div class="muted" style="font-size:11px;"><?= date('d/m/Y', strtotime($t['paid_until'])) ?></div>
              <?php endif; ?>
            <?php elseif (!$t['trial_ends_at']): ?>
              <span class="muted">—</span>
            <?php elseif ($daysLeft < 0): ?>
              <span style="color:#dc2626;font-weight:600;">Đã hết hạn</span>
              <div class="muted" style="font-size:11px;"><?= date('d/m/Y', strtotime($t['trial_ends_at'])) ?></div>
            <?php else: ?>
              Còn <?= (int) $daysLeft ?> ngày
              <div class="muted" style="font-size:11px;"><?= date('d/m/Y', strtotime($t['trial_ends_at'])) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-right"><?= (int) $t['user_count'] ?></td>
          <td class="text-right"><?= (int) $t['product_count'] ?></td>
          <td class="text-right"><?= (int) $t['order_count'] ?></td>
          <td class="muted" style="font-size:12px;"><?= e($lastActive) ?></td>
          <td>
            <?php if ($t['is_active']): ?><span class="badge badge-green">Hoạt động</span>
            <?php else: ?><span class="badge badge-red">Đã khóa</span><?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <?php if (!$isOwner): ?>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <?php if ($t['plan'] !== 'PAID'): ?>
                  <input type="hidden" name="action" value="upgrade_paid">
                  <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;" onclick="return confirm('Nâng cấp lên gói trả phí?')">Nâng cấp</button>
                <?php endif; ?>
              </form>
              <?php if ($t['plan'] !== 'PAID'): // ca 2 thao tac duoi day deu dat plan = 'TRIAL' ?>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <input type="hidden" name="action" value="extend_trial">
                <input type="hidden" name="days" value="365">
                <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;"
                        onclick="return confirm('Cộng thêm 1 năm vào hạn dùng thử? Nếu bấm nhầm, dùng ô chọn ngày bên cạnh để đặt lại về đúng ngày.')">+1 năm</button>
              </form>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <input type="hidden" name="action" value="set_trial_end">
                <input type="date" name="trial_end" required
                       value="<?= e($t['trial_ends_at'] ? date('Y-m-d', strtotime($t['trial_ends_at'])) : date('Y-m-d')) ?>"
                       style="padding:3px 6px;font-size:11px;border:1px solid #cbd5e1;border-radius:6px;">
                <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;"
                        onclick="return confirm('Đặt lại hạn dùng thử về đúng ngày đã chọn? Có thể chọn ngày trong quá khứ để kết thúc dùng thử ngay.')">Đặt hạn</button>
              </form>
              <?php else: // khach DA TRA PHI: gia han vao paid_until, khong dung toi han dung thu ?>
                <?php if ($t['paid_until']): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <input type="hidden" name="action" value="extend_paid">
                  <input type="hidden" name="months" value="12">
                  <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;"
                          onclick="return confirm('Gia hạn thêm 12 tháng cho khách trả phí này? Nếu bấm nhầm, dùng ô chọn ngày bên cạnh để đặt lại.')">+12 tháng</button>
                </form>
                <?php endif; ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <input type="hidden" name="action" value="set_paid_until">
                  <input type="date" name="paid_until" required
                         value="<?= e($t['paid_until'] ? date('Y-m-d', strtotime($t['paid_until'])) : date('Y-m-d', strtotime('+12 months'))) ?>"
                         style="padding:3px 6px;font-size:11px;border:1px solid #cbd5e1;border-radius:6px;">
                  <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;"
                          onclick="return confirm('Đặt hạn trả phí về đúng ngày đã chọn?')">Đặt hạn</button>
                </form>
                <?php if ($t['paid_until']): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <input type="hidden" name="action" value="set_paid_until">
                  <input type="hidden" name="unlimited" value="1">
                  <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;"
                          onclick="return confirm('Bỏ hạn sử dụng của khách này (dùng vô thời hạn)?')">Bỏ hạn</button>
                </form>
                <?php endif; ?>
              <?php endif; ?>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <input type="hidden" name="action" value="toggle_active">
                <button type="submit" class="btn <?= $t['is_active'] ? 'btn-danger' : 'btn-secondary' ?>" style="padding:4px 8px;font-size:11px;" onclick="return confirm('<?= $t['is_active'] ? 'Khóa' : 'Mở khóa' ?> tài khoản này?')"><?= $t['is_active'] ? 'Khóa' : 'Mở khóa' ?></button>
              </form>
              <details style="display:inline-block;position:relative;">
                <summary class="btn btn-secondary" style="padding:4px 8px;font-size:11px;display:inline-block;cursor:pointer;list-style:none;">💳 Tạo link TT</summary>
                <form method="post" style="position:absolute;z-index:10;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;box-shadow:0 4px 12px rgba(0,0,0,.1);top:100%;right:0;width:200px;margin-top:4px;">
                  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <input type="hidden" name="action" value="create_payment_link">
                  <label style="display:block;font-size:11px;margin-bottom:2px;">Số tiền (VNĐ)</label>
                  <input type="number" name="amount" min="1000" step="1000" required style="width:100%;padding:5px 7px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px;margin-bottom:8px;box-sizing:border-box;">
                  <label style="display:block;font-size:11px;margin-bottom:2px;">Số tháng</label>
                  <input type="number" name="months" value="12" min="1" required style="width:100%;padding:5px 7px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px;margin-bottom:8px;box-sizing:border-box;">
                  <button type="submit" class="btn" style="width:100%;padding:6px;font-size:12px;">Tạo link</button>
                </form>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
