<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN');

$pdo = db();
$error = null;
$typeLabels = ['OUTPUT' => 'Thuế đầu ra (bán hàng)', 'INPUT' => 'Thuế đầu vào (mua hàng)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE tax_rates SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        logActivity('TAX_RATE_TOGGLE', 'id=' . $id);
        redirect('tax_rates.php');
    } else {
        $name = post('name');
        $rate = postFloat('rate_percent');
        $type = in_array($_POST['type'] ?? '', ['OUTPUT', 'INPUT'], true) ? $_POST['type'] : 'OUTPUT';

        if ($name === '') {
            $error = 'Vui lòng nhập tên mức thuế';
        } else {
            $pdo->prepare('INSERT INTO tax_rates (name, rate_percent, type) VALUES (?,?,?)')->execute([$name, $rate, $type]);
            logActivity('TAX_RATE_CREATE', $name);
            redirect('tax_rates.php');
        }
    }
}

$taxRates = $pdo->query('SELECT * FROM tax_rates ORDER BY type, rate_percent')->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="settings.php" class="muted" style="font-size:14px;">← Cấu hình</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Thuế</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;">
  Khai báo các mức thuế suất đầu ra/đầu vào để tham chiếu khi lập hóa đơn hoặc báo cáo — đây là
  danh mục tham chiếu nội bộ, chưa tự động tính vào giá bán trong POS.
</p>

<div class="card" style="max-width:480px;margin-bottom:24px;">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="field" style="flex:1;margin:0;min-width:140px;"><label>Tên mức thuế *</label><input class="input" name="name" required placeholder="vd: VAT 8%"></div>
    <div class="field" style="margin:0;width:100px;"><label>Thuế suất %</label><input class="input" type="number" step="0.01" min="0" max="100" name="rate_percent" value="0"></div>
    <div class="field" style="margin:0;width:160px;">
      <label>Loại</label>
      <select class="input" name="type">
        <?php foreach ($typeLabels as $val => $lbl): ?><option value="<?= e($val) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn">Thêm</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên mức thuế</th><th>Loại</th><th class="text-right">Thuế suất</th><th class="text-center">Trạng thái</th><th></th></tr></thead>
    <tbody>
      <?php if (!$taxRates): ?><tr><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có mức thuế nào.</td></tr><?php endif; ?>
      <?php foreach ($taxRates as $t): ?>
        <tr>
          <td><?= e($t['name']) ?></td>
          <td class="muted"><?= e($typeLabels[$t['type']] ?? $t['type']) ?></td>
          <td class="text-right"><?= number_format((float) $t['rate_percent'], 2) ?>%</td>
          <td class="text-center">
            <?php if ($t['is_active']): ?><span class="badge badge-green">Đang dùng</span><?php else: ?><span class="badge badge-gray">Đã tắt</span><?php endif; ?>
          </td>
          <td class="text-right">
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:12px;"><?= $t['is_active'] ? 'Tắt' : 'Bật' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
