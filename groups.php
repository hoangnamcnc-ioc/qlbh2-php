<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'assign_price_list') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $priceListId = (int) ($_POST['price_list_id'] ?? 0) ?: null;
        $pdo->prepare('UPDATE customer_groups SET price_list_id = ? WHERE id = ?')->execute([$priceListId, $groupId]);
        redirect('groups.php');
    } else {
        $name = post('name');
        $code = strtoupper(post('code'));
        $description = post('description') ?: null;
        if ($name === '') {
            $error = 'Vui lòng nhập tên nhóm';
        } else {
            $check = $pdo->prepare('SELECT id FROM customer_groups WHERE name = ?');
            $check->execute([$name]);
            if ($check->fetch()) {
                $error = 'Nhóm khách hàng này đã tồn tại';
            } else {
                if ($code === '') {
                    $code = 'NHM' . substr((string) (int) round(microtime(true) * 1000), -8);
                }
                $codeCheck = $pdo->prepare('SELECT id FROM customer_groups WHERE code = ?');
                $codeCheck->execute([$code]);
                if ($codeCheck->fetch()) {
                    $error = 'Mã nhóm này đã tồn tại';
                } else {
                    $pdo->prepare('INSERT INTO customer_groups (name, code, description) VALUES (?,?,?)')->execute([$name, $code, $description]);
                    redirect('groups.php');
                }
            }
        }
    }
}

$groups = $pdo->query(
    'SELECT g.*, (SELECT COUNT(*) FROM customers c WHERE c.group_id = g.id) AS customer_count, pl.name AS price_list_name
     FROM customer_groups g LEFT JOIN price_lists pl ON pl.id = g.price_list_id
     ORDER BY g.name'
)->fetchAll();
$priceLists = $pdo->query('SELECT * FROM price_lists ORDER BY name')->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="customers.php" class="muted" style="font-size:14px;">← Danh sách khách hàng</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Nhóm khách hàng</h1>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" style="display:flex;align-items:end;gap:12px;flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div>
      <label style="font-size:12px;">Tên nhóm mới</label>
      <input class="input" name="name" required placeholder="vd: VIP, Bán buôn..." style="width:220px;">
    </div>
    <div>
      <label style="font-size:12px;">Mã nhóm (bỏ trống = tự sinh)</label>
      <input class="input" name="code" placeholder="vd: VIP" style="width:140px;">
    </div>
    <div>
      <label style="font-size:12px;">Mô tả</label>
      <input class="input" name="description" placeholder="tùy chọn" style="width:220px;">
    </div>
    <button type="submit" class="btn">Thêm nhóm</button>
  </form>
</div>

<div class="card" style="max-width:720px;padding:0;">
  <?php if (!$groups): ?>
    <div class="text-center muted" style="padding:32px;">Chưa có nhóm khách hàng nào.</div>
  <?php endif; ?>
  <?php foreach ($groups as $g): ?>
    <form method="post" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid #f1f5f9;gap:12px;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="assign_price_list">
      <input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
      <div style="flex:1;">
        <?= e($g['name']) ?>
        <span class="muted" style="font-size:12px;font-family:monospace;"> (<?= e($g['code'] ?: '—') ?>)</span>
        <span class="muted" style="font-size:12px;"> · <?= (int) $g['customer_count'] ?> khách hàng</span>
        <?php if ($g['description']): ?><div class="muted" style="font-size:12px;"><?= e($g['description']) ?></div><?php endif; ?>
      </div>
      <select name="price_list_id" class="input" style="max-width:200px;" onchange="this.form.submit()">
        <option value="">— Giá mặc định —</option>
        <?php foreach ($priceLists as $pl): ?>
          <option value="<?= (int) $pl['id'] ?>" <?= (int) ($g['price_list_id'] ?? 0) === (int) $pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endforeach; ?>
</div>

<p class="muted" style="font-size:12px;margin-top:8px;"><a href="price_lists.php">Quản lý bảng giá</a></p>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
