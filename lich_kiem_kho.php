<?php
/**
 * Lich kiem kho theo nhom hang (moi nhom kiem moi N ngay) va kiem hang de mat moi ngay.
 * Bam "Kiem ngay" mo phieu kiem da nap san hang cua nhom; luu phieu thi ghi nhan ngay da kiem.
 * Hang de mat danh dau o trang Ke hang.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;

$cats = $pdo->prepare('SELECT c.id, c.name, s.every_days, s.last_done FROM categories c LEFT JOIN stock_take_schedules s ON s.category_id = c.id AND s.tenant_id = c.tenant_id WHERE c.tenant_id = ? ORDER BY c.name');
$cats->execute([$tenantId]);
$cats = $cats->fetchAll();
$catIds = array_map(static fn($c) => (int) $c['id'], $cats);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $cid = (int) ($_POST['category_id'] ?? 0);
    if ($action === 'set' && in_array($cid, $catIds, true)) {
        $every = (int) ($_POST['every_days'] ?? 0);
        if ($every <= 0) {
            $pdo->prepare('DELETE FROM stock_take_schedules WHERE tenant_id = ? AND category_id = ?')->execute([$tenantId, $cid]);
        } elseif ($every > 365) {
            $error = 'Chu kỳ tối đa 365 ngày.';
        } else {
            $pdo->prepare('INSERT INTO stock_take_schedules (tenant_id, category_id, every_days) VALUES (?,?,?) ON DUPLICATE KEY UPDATE every_days = VALUES(every_days)')->execute([$tenantId, $cid, $every]);
        }
        if (!$error) redirect('lich_kiem_kho.php?ok=1');
    } elseif ($action === 'done' && in_array($cid, $catIds, true)) {
        $pdo->prepare('UPDATE stock_take_schedules SET last_done = CURDATE() WHERE tenant_id = ? AND category_id = ?')->execute([$tenantId, $cid]);
        redirect('lich_kiem_kho.php?ok=1');
    }
}

$homNay = date('Y-m-d');
$ds = [];
foreach ($cats as $c) {
    if (!$c['every_days']) continue;
    $due = $c['last_done'] ? date('Y-m-d', strtotime($c['last_done'] . ' +' . (int) $c['every_days'] . ' days')) : $homNay;
    $ds[] = $c + ['due' => $due, 'late' => (int) floor((strtotime($homNay) - strtotime($due)) / 86400)];
}
usort($ds, static fn($a, $b) => strcmp($a['due'], $b['due']));
$nLoss = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE tenant_id = " . (int) $tenantId . " AND high_loss = 1 AND is_active = 1")->fetchColumn();
$lossLast = getSetting('loss_check_last', '');
$lossDone = $lossLast === $homNay;

require_once __DIR__ . '/inc_header.php';
echo thanhTabNghiepVu('kho');
?>
<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Lịch kiểm kho</h1>
<p class="muted" style="margin:0 0 14px;max-width:780px;font-size:14px;">Đặt chu kỳ kiểm cho từng nhóm hàng (ví dụ bánh kẹo 7 ngày một lần) và kiểm hàng dễ mất mỗi ngày. Hệ thống nhắc nhóm nào đến hạn; bấm "Kiểm ngay" để mở phiếu kiểm đã nạp sẵn hàng của nhóm, lưu phiếu là ghi nhận đã kiểm.</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Đã lưu.</div><?php endif; ?>

<div class="card" style="margin-bottom:16px;max-width:900px;border-left:4px solid <?= $nLoss && !$lossDone ? '#d97706' : '#059669' ?>;">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <div><b>Kiểm hàng dễ mất hôm nay</b><div class="muted" style="font-size:13px;"><?= $nLoss ?> mặt hàng đã đánh dấu "dễ mất" ở <a href="ke_hang.php">Kệ hàng</a>.
      <?= !$nLoss ? 'Chưa đánh dấu mặt hàng nào.' : ($lossDone ? '<span style="color:#059669;">Hôm nay đã kiểm.</span>' : '<span style="color:#b45309;">Hôm nay chưa kiểm.</span>' . ($lossLast ? ' Lần gần nhất ' . e(date('d/m/Y', strtotime($lossLast))) . '.' : '')) ?></div></div>
    <?php if ($nLoss): ?><a class="btn" href="stock_take_form.php?loss=1">Kiểm hàng dễ mất</a><?php endif; ?>
  </div>
</div>

<?php if ($ds): ?>
<div class="card" style="margin-bottom:16px;max-width:900px;overflow-x:auto;">
  <div style="font-weight:600;margin-bottom:8px;">Nhóm hàng theo lịch</div>
  <table>
    <thead><tr><th>Nhóm hàng</th><th>Chu kỳ</th><th>Kiểm lần cuối</th><th>Hạn kiểm</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($ds as $d): ?>
        <tr>
          <td><?= e($d['name']) ?></td><td><?= (int) $d['every_days'] ?> ngày</td>
          <td><?= $d['last_done'] ? e(date('d/m/Y', strtotime($d['last_done']))) : '<span class="muted">chưa kiểm</span>' ?></td>
          <td><?= e(date('d/m/Y', strtotime($d['due']))) ?>
            <?= $d['late'] > 0 ? '<span style="color:#dc2626;font-size:12px;">quá ' . $d['late'] . ' ngày</span>' : ($d['late'] === 0 ? '<span style="color:#d97706;font-size:12px;">đến hạn hôm nay</span>' : '<span class="muted" style="font-size:12px;">còn ' . (-$d['late']) . ' ngày</span>') ?></td>
          <td style="white-space:nowrap;">
            <a class="btn" style="padding:3px 10px;font-size:12px;" href="stock_take_form.php?cat=<?= (int) $d['id'] ?>">Kiểm ngay</a>
            <form method="post" style="display:inline;margin:0;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="done"><input type="hidden" name="category_id" value="<?= (int) $d['id'] ?>"><button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit" title="Đã kiểm bằng cách khác">Đánh dấu đã kiểm</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card" style="max-width:900px;overflow-x:auto;">
  <div style="font-weight:600;margin-bottom:8px;">Đặt chu kỳ cho nhóm hàng</div>
  <table>
    <tbody>
      <?php if (!$cats): ?><tr><td class="muted">Chưa có nhóm hàng. Tạo ở <a href="categories.php">Danh mục</a>.</td></tr><?php endif; ?>
      <?php foreach ($cats as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><form method="post" style="display:flex;gap:6px;align-items:center;margin:0;">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="set"><input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
            kiểm mỗi <input class="input" type="number" name="every_days" min="0" max="365" value="<?= $c['every_days'] ? (int) $c['every_days'] : '' ?>" placeholder="—" style="width:80px;padding:4px 8px;"> ngày
            <button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Lưu</button>
            <span class="muted" style="font-size:12px;">để trống hoặc 0 = không đặt lịch</span>
          </form></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
