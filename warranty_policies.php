<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = post('name');
    $duration = postInt('duration_months');
    $note = post('note') ?: null;

    if ($name === '' || $duration <= 0) {
        $error = 'Vui lòng nhập tên chính sách và số tháng bảo hành hợp lệ';
    } else {
        $pdo->prepare('INSERT INTO warranty_policies (name, duration_months, note) VALUES (?,?,?)')
            ->execute([$name, $duration, $note]);
        redirect('warranty_policies.php');
    }
}

$policies = $pdo->query('SELECT * FROM warranty_policies ORDER BY created_at DESC')->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Chính sách bảo hành</h1>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Thêm chính sách</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="grid-2">
      <div class="field"><label>Tên chính sách *</label><input class="input" name="name" required placeholder="vd: Bảo hành điện tử 12 tháng"></div>
      <div class="field"><label>Số tháng bảo hành *</label><input class="input" type="number" min="1" name="duration_months" required value="12"></div>
    </div>
    <div class="field"><label>Ghi chú / điều kiện</label><input class="input" name="note"></div>
    <button type="submit" class="btn">Thêm chính sách</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Tên chính sách</th><th class="text-right">Số tháng</th><th>Ghi chú</th></tr></thead>
    <tbody>
      <?php if (!$policies): ?>
        <tr><td colspan="3" class="text-center muted" style="padding:32px;">Chưa có chính sách bảo hành nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($policies as $p): ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td class="text-right"><?= (int) $p['duration_months'] ?></td>
          <td class="muted"><?= e($p['note'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
