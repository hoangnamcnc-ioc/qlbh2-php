<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$statusLabels = ['PENDING' => 'Chờ xử lý', 'PROCESSING' => 'Đang xử lý', 'DONE' => 'Hoàn thành', 'REJECTED' => 'Từ chối'];
$error = null;

$stmt = $pdo->prepare(
    'SELECT c.*, w.code AS card_code, w.id AS card_id, p.name AS product_name
     FROM warranty_claims c
     JOIN warranty_cards w ON w.id = c.warranty_card_id
     JOIN products p ON p.id = w.product_id
     WHERE c.id = ?'
);
$stmt->execute([$id]);
$claim = $stmt->fetch();
if (!$claim) redirect('warranty_cards.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasRole('ADMIN', 'MANAGER')) {
    checkCsrf();
    $status = $_POST['status'] ?? '';
    $note = post('note') ?: null;
    if (in_array($status, ['PENDING', 'PROCESSING', 'DONE', 'REJECTED'], true)) {
        $resolvedAt = in_array($status, ['DONE', 'REJECTED'], true) ? date('Y-m-d H:i:s') : null;
        $pdo->prepare('UPDATE warranty_claims SET status = ?, note = ?, resolved_at = ? WHERE id = ?')
            ->execute([$status, $note, $resolvedAt, $id]);
        redirect('warranty_claim_view.php?id=' . $id);
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="warranty_card_view.php?id=<?= (int) $claim['card_id'] ?>" class="muted" style="font-size:14px;">← Phiếu bảo hành <?= e($claim['card_code']) ?></a>
<h1 style="font-size:24px;font-weight:600;font-family:monospace;margin:8px 0 4px;"><?= e($claim['code']) ?></h1>
<p class="muted" style="margin:0 0 24px;">Sản phẩm: <?= e($claim['product_name']) ?> · <?= date('d/m/Y H:i', strtotime($claim['created_at'])) ?></p>

<div class="card" style="max-width:480px;">
  <p style="margin:0 0 12px;"><b>Mô tả:</b> <?= e($claim['issue_description']) ?></p>
  <p style="margin:0 0 16px;">Trạng thái: <span class="badge badge-gray"><?= e($statusLabels[$claim['status']] ?? $claim['status']) ?></span></p>

  <?php if (hasRole('ADMIN', 'MANAGER')): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <div class="field">
        <label>Cập nhật trạng thái</label>
        <select class="input" name="status">
          <?php foreach ($statusLabels as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $claim['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Ghi chú xử lý</label><input class="input" name="note" value="<?= e($claim['note'] ?? '') ?>"></div>
      <button type="submit" class="btn">Cập nhật</button>
    </form>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
