<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireSuperAdmin();

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    // Khong cho tu khoa/tu ha cap chinh tenant #1 (chu so huu) de tranh tu khoa minh ra khoi he thong.
    if ($id === 1) {
        redirect('super_admin_tenants.php');
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
    } elseif ($action === 'resolve_renewal') {
        $reqId = (int) ($_POST['req_id'] ?? 0);
        $pdo->prepare("UPDATE renewal_requests SET status = 'DONE' WHERE id = ? AND tenant_id = ?")->execute([$reqId, $id]);
        logActivity('SUPER_ADMIN_RENEWAL_RESOLVE', "tenant_id=$id req_id=$reqId");
    }
    redirect('super_admin_tenants.php');
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
        <th>Hạn dùng thử</th><th class="text-right">NV</th><th class="text-right">SP</th>
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
            <?php if ($t['plan'] === 'PAID' || !$t['trial_ends_at']): ?>
              <span class="muted">—</span>
            <?php elseif ($daysLeft < 0): ?>
              <span style="color:#dc2626;font-weight:600;">Đã hết hạn</span>
            <?php else: ?>
              Còn <?= (int) $daysLeft ?> ngày
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
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <input type="hidden" name="action" value="extend_trial">
                <input type="hidden" name="days" value="365">
                <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;">+1 năm</button>
              </form>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <input type="hidden" name="action" value="toggle_active">
                <button type="submit" class="btn <?= $t['is_active'] ? 'btn-danger' : 'btn-secondary' ?>" style="padding:4px 8px;font-size:11px;" onclick="return confirm('<?= $t['is_active'] ? 'Khóa' : 'Mở khóa' ?> tài khoản này?')"><?= $t['is_active'] ? 'Khóa' : 'Mở khóa' ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
