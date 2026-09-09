<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();
$error = null;
$appliesLabels = ['CANCEL' => 'Chỉ hủy đơn', 'RETURN' => 'Chỉ trả hàng', 'BOTH' => 'Cả hủy và trả hàng'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE cancel_reasons SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        logActivity('CANCEL_REASON_TOGGLE', 'id=' . $id);
        redirect('cancel_reasons.php');
    } else {
        $name = post('name');
        $appliesTo = in_array($_POST['applies_to'] ?? '', ['CANCEL', 'RETURN', 'BOTH'], true) ? $_POST['applies_to'] : 'BOTH';

        if ($name === '') {
            $error = 'Vui lòng nhập lý do';
        } else {
            $pdo->prepare('INSERT INTO cancel_reasons (name, applies_to) VALUES (?,?)')->execute([$name, $appliesTo]);
            logActivity('CANCEL_REASON_CREATE', $name);
            redirect('cancel_reasons.php');
        }
    }
}

$reasons = $pdo->query('SELECT * FROM cancel_reasons ORDER BY id')->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Lý do hủy trả</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Danh sách lý do có sẵn để chọn nhanh khi hủy đơn hoặc tạo đơn trả hàng, giúp thống nhất cách ghi
  nhận lý do giữa các nhân viên.
</p>

<div class="card" style="max-width:520px;margin-bottom:24px;">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="field" style="flex:1;margin:0;min-width:180px;"><label>Lý do *</label><input class="input" name="name" required placeholder="vd: Hàng lỗi, Đổi ý không mua nữa"></div>
    <div class="field" style="margin:0;width:180px;">
      <label>Áp dụng cho</label>
      <select class="input" name="applies_to">
        <?php foreach ($appliesLabels as $val => $lbl): ?><option value="<?= e($val) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn">Thêm</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Lý do</th><th>Áp dụng cho</th><th class="text-center">Trạng thái</th><th></th></tr></thead>
    <tbody>
      <?php if (!$reasons): ?><tr><td colspan="4" class="text-center muted" style="padding:24px;">Chưa có lý do nào.</td></tr><?php endif; ?>
      <?php foreach ($reasons as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td class="muted"><?= e($appliesLabels[$r['applies_to']] ?? $r['applies_to']) ?></td>
          <td class="text-center">
            <?php if ($r['is_active']): ?><span class="badge badge-green">Đang dùng</span><?php else: ?><span class="badge badge-gray">Đã tắt</span><?php endif; ?>
          </td>
          <td class="text-right">
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;"><?= $r['is_active'] ? 'Tắt' : 'Bật' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
