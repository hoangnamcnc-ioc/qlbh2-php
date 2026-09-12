<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireLogin();

$pdo = db();
$error = null;
$order = null;
$items = [];
$policies = $pdo->query('SELECT * FROM warranty_policies ORDER BY name')->fetchAll();

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare(
        'SELECT o.*, c.name AS customer_name FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.code = ?'
    );
    $stmt->execute([$q]);
    $order = $stmt->fetch();
    if (!$order) {
        $error = 'Không tìm thấy đơn hàng với mã "' . $q . '"';
    } else {
        $stmt = $pdo->prepare(
            'SELECT oi.*, p.name AS product_name, v.name AS variant_name
             FROM order_items oi JOIN products p ON p.id = oi.product_id
             LEFT JOIN product_variants v ON v.id = oi.variant_id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([$order['id']]);
        $items = $stmt->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $orderItemId = (int) ($_POST['order_item_id'] ?? 0);
    $policyId = (int) ($_POST['policy_id'] ?? 0) ?: null;

    $stmt = $pdo->prepare(
        'SELECT oi.*, o.customer_id FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.id = ?'
    );
    $stmt->execute([$orderItemId]);
    $item = $stmt->fetch();

    if (!$item) {
        $error = 'Sản phẩm không hợp lệ';
    } else {
        $duration = 12;
        if ($policyId) {
            $p = $pdo->prepare('SELECT duration_months FROM warranty_policies WHERE id = ?');
            $p->execute([$policyId]);
            $duration = (int) ($p->fetchColumn() ?: 12);
        }
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+$duration months"));
        $code = 'WR' . substr((string) (int) round(microtime(true) * 1000), -8);

        $pdo->prepare(
            'INSERT INTO warranty_cards (code, order_item_id, product_id, customer_id, policy_id, start_date, end_date, created_by_id) VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$code, $orderItemId, $item['product_id'], $item['customer_id'], $policyId, $startDate, $endDate, $currentUser['id']]);

        redirect('warranty_cards.php?created=' . $code);
    }
}

require_once __DIR__ . '/inc_header.php';
?>

<a href="warranty_cards.php" class="muted" style="font-size:14px;">← Danh sách phiếu bảo hành</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 24px;">Tạo phiếu bảo hành</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:480px;margin-bottom:24px;">
  <form method="get">
    <div class="field"><label>Nhập mã đơn hàng gốc</label><input class="input" name="q" required value="<?= e($q) ?>" placeholder="vd: DH6C9VT3D2L"></div>
    <button type="submit" class="btn btn-secondary">Tìm đơn hàng</button>
  </form>
</div>

<?php if ($order && $items): ?>
<div class="card" style="max-width:640px;">
  <h2 style="font-size:16px;font-weight:600;margin:0 0 4px;">Đơn gốc: <span style="font-family:monospace;"><?= e($order['code']) ?></span></h2>
  <p class="muted" style="margin:0 0 16px;">Khách hàng: <?= e($order['customer_name'] ?: 'Khách lẻ') ?></p>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <div class="field">
      <label>Chọn sản phẩm</label>
      <select class="input" name="order_item_id" required>
        <?php foreach ($items as $it): ?>
          <option value="<?= (int) $it['id'] ?>">
            <?= e($it['product_name']) ?><?= $it['variant_name'] ? ' (' . e($it['variant_name']) . ')' : '' ?> — SL <?= fmtQty($it['quantity']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Chính sách bảo hành</label>
      <select class="input" name="policy_id">
        <option value="">— Mặc định 12 tháng —</option>
        <?php foreach ($policies as $p): ?>
          <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= (int) $p['duration_months'] ?> tháng)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn">Tạo phiếu bảo hành</button>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
