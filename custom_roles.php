<?php
/**
 * Quan ly VAI TRO TUY CHINH (Thu kho, Thu quy, Nhan vien ban hang...) - moi vai tro la 1 tap
 * "nhom quyen" (PERMISSION_GROUPS trong inc_functions.php) duoc tich chon. Nhan vien gan vai
 * tro nay se duoc thao tac (khong chi xem) tren dung nhung khu vuc da tich, ma khong can nang
 * len MANAGER (duoc toan bo).
 *
 * CO Y chi ADMIN moi vao duoc trang nay (khong dung requireRole('ADMIN','MANAGER')): neu MANAGER
 * cung sua duoc quyen cua vai tro tuy chinh, ho co the tu tao 1 vai tro "duoc het moi thu" roi
 * gan cho chinh minh hoac nguoi khac - tuong duong tu nang quyen. Xem giai thich day du trong
 * chu thich cua PERMISSION_GROUP_FILES.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN');

$pdo = db();
$tenantId = currentTenantId();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'create';

    $selectedGroups = array_values(array_intersect(
        (array) ($_POST['permissions'] ?? []),
        array_keys(PERMISSION_GROUPS)
    ));
    $permissionsStr = implode(',', $selectedGroups);
    $name = mb_substr(trim(post('name')), 0, 100);

    if ($action === 'create') {
        if ($name === '') {
            $error = 'Vui lòng đặt tên cho vai trò.';
        } else {
            $pdo->prepare('INSERT INTO custom_roles (tenant_id, name, permissions) VALUES (?, ?, ?)')
                ->execute([$tenantId, $name, $permissionsStr]);
            logActivity('CUSTOM_ROLE_CREATE', "name=$name perms=$permissionsStr");
            redirect('custom_roles.php?created=1');
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        // Bat buoc xac nhan vai tro thuoc dung tenant hien tai truoc khi sua - neu khong, ADMIN
        // cua tenant nay co the sua/xoa vai tro cua tenant khac chi bang cach doan id.
        $own = $pdo->prepare('SELECT id FROM custom_roles WHERE id = ? AND tenant_id = ?');
        $own->execute([$id, $tenantId]);
        if ($own->fetch() && $name !== '') {
            $pdo->prepare('UPDATE custom_roles SET name = ?, permissions = ? WHERE id = ?')
                ->execute([$name, $permissionsStr, $id]);
            logActivity('CUSTOM_ROLE_UPDATE', "id=$id name=$name perms=$permissionsStr");
        }
        redirect('custom_roles.php?updated=1');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $own = $pdo->prepare('SELECT id FROM custom_roles WHERE id = ? AND tenant_id = ?');
        $own->execute([$id, $tenantId]);
        if ($own->fetch()) {
            // Nhan vien dang gan vai tro nay tu dong ve lai CASHIER thuong (khong con quyen thao
            // tac nao ngoai muc mac dinh) thay vi bi khoa tai khoan hay loi ngam.
            $pdo->prepare('UPDATE users SET custom_role_id = NULL WHERE custom_role_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM custom_roles WHERE id = ?')->execute([$id]);
            logActivity('CUSTOM_ROLE_DELETE', "id=$id");
        }
        redirect('custom_roles.php?deleted=1');
    }
}

$rolesStmt = $pdo->prepare(
    'SELECT cr.*, (SELECT COUNT(*) FROM users u WHERE u.custom_role_id = cr.id) AS user_count
     FROM custom_roles cr WHERE cr.tenant_id = ? ORDER BY cr.created_at'
);
$rolesStmt->execute([$tenantId]);
$roles = $rolesStmt->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<a href="users.php" class="muted" style="font-size:14px;">← Nhân viên và phân quyền</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 8px;">Vai trò tùy chỉnh</h1>
<p class="muted" style="margin:0 0 16px;font-size:13px;max-width:680px;">
  Ngoài 3 vai trò có sẵn (Quản trị viên, Quản lý, Thu ngân), tạo thêm vai trò riêng — ví dụ
  <b>Thủ kho</b> (chỉ tích khu vực "Sản phẩm, kho...") hoặc <b>Thủ quỹ</b> (chỉ tích "Sổ quỹ,
  báo cáo...") — rồi gán cho nhân viên ở trang <a href="users.php">Nhân viên và phân quyền</a>.
  Người được gán chỉ <b>thao tác</b> được đúng các khu vực đã tích, những khu vực khác họ không
  thấy trong menu và không truy cập được kể cả gõ thẳng đường dẫn.
</p>

<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Đã tạo vai trò mới.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Đã lưu thay đổi.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Đã xóa vai trò — nhân viên đang gán vai trò này đã chuyển về Thu ngân thường.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:560px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Tạo vai trò mới</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="field">
      <label for="new-name">Tên vai trò *</label>
      <input class="input" id="new-name" name="name" required placeholder="vd: Thủ kho">
    </div>
    <div class="field">
      <label>Được thao tác trên</label>
      <?php foreach (PERMISSION_GROUPS as $key => $label): ?>
        <label style="display:block;font-weight:400;margin:4px 0;">
          <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"> <?= e($label) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn">Tạo vai trò</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Vai trò</th><th>Được thao tác trên</th><th class="text-center">Số người dùng</th><th></th></tr></thead>
    <tbody>
      <?php if (!$roles): ?>
        <tr><td colspan="4" class="text-center muted" style="padding:24px;">Chưa có vai trò tùy chỉnh nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($roles as $r): ?>
        <?php $rolePerms = array_filter(explode(',', $r['permissions'])); ?>
        <tr>
          <td style="vertical-align:top;padding-top:14px;"><b><?= e($r['name']) ?></b></td>
          <td style="max-width:520px;">
            <form method="post" style="display:flex;flex-wrap:wrap;gap:4px 16px;align-items:center;">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="name" value="<?= e($r['name']) ?>">
              <?php foreach (PERMISSION_GROUPS as $key => $label): ?>
                <label style="font-weight:400;font-size:13px;white-space:nowrap;">
                  <input type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= in_array($key, $rolePerms, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                  <?= e($label) ?>
                </label>
              <?php endforeach; ?>
            </form>
          </td>
          <td class="text-center" style="vertical-align:top;padding-top:14px;"><?= (int) $r['user_count'] ?></td>
          <td style="vertical-align:top;padding-top:10px;white-space:nowrap;">
            <form method="post" onsubmit="return confirm('Xóa vai trò &quot;<?= e(addslashes($r['name'])) ?>&quot;? Nhân viên đang gán vai trò này sẽ chuyển về Thu ngân thường.')">
              <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:12px;">Xóa</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
