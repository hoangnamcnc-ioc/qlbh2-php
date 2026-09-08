<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$cardId = (int) ($_GET['card_id'] ?? 0);

$stmt = $pdo->prepare('SELECT w.*, p.name AS product_name FROM warranty_cards w JOIN products p ON p.id = w.product_id WHERE w.id = ?');
$stmt->execute([$cardId]);
$card = $stmt->fetch();
if (!$card) redirect('warranty_cards.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $issue = post('issue_description');
    if ($issue === '') {
        $error = 'Vui lòng mô tả lỗi/yêu cầu bảo hành';
    } else {
        $code = 'YC' . substr((string) (int) round(microtime(true) * 1000), -8);
        $pdo->prepare('INSERT INTO warranty_claims (code, warranty_card_id, issue_description, created_by_id) VALUES (?,?,?,?)')
            ->execute([$code, $cardId, $issue, $currentUser['id']]);
        redirect('warranty_card_view.php?id=' . $cardId);
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="warranty_card_view.php?id=<?= (int) $cardId ?>" class="muted" style="font-size:14px;">← Phiếu bảo hành <?= e($card['code']) ?></a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Tạo yêu cầu bảo hành</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:480px;">
  <p class="muted" style="margin:0 0 12px;">Sản phẩm: <?= e($card['product_name']) ?></p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field"><label>Mô tả lỗi / yêu cầu *</label><input class="input" name="issue_description" required></div>
    <button type="submit" class="btn">Tạo yêu cầu</button>
  </form>
</div>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
