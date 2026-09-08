<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $productId = (int) ($_POST['product_id'] ?? 0);
    $variantId = (int) ($_POST['variant_id'] ?? 0) ?: null;
    $newCost = postFloat('new_cost_price');
    $reason = post('reason') ?: null;

    if (!$productId) {
        $error = 'Vui lòng chọn sản phẩm';
    } else {
        if ($variantId) {
            $row = $pdo->prepare('SELECT cost_price FROM product_variants WHERE id = ?');
            $row->execute([$variantId]);
        } else {
            $row = $pdo->prepare('SELECT cost_price FROM products WHERE id = ?');
            $row->execute([$productId]);
        }
        $oldCost = (float) ($row->fetchColumn() ?: 0);

        $pdo->prepare('INSERT INTO price_adjustments (product_id, variant_id, old_cost_price, new_cost_price, reason, created_by_id) VALUES (?,?,?,?,?,?)')
            ->execute([$productId, $variantId, $oldCost, $newCost, $reason, $currentUser['id']]);

        if ($variantId) {
            $pdo->prepare('UPDATE product_variants SET cost_price = ? WHERE id = ?')->execute([$newCost, $variantId]);
        } else {
            $pdo->prepare('UPDATE products SET cost_price = ? WHERE id = ?')->execute([$newCost, $productId]);
        }
        redirect('price_adjustments.php');
    }
}

$adjustments = $pdo->query(
    'SELECT a.*, p.name AS product_name, v.name AS variant_name, u.name AS created_by_name
     FROM price_adjustments a
     JOIN products p ON p.id = a.product_id
     LEFT JOIN product_variants v ON v.id = a.variant_id
     JOIN users u ON u.id = a.created_by_id
     ORDER BY a.created_at DESC LIMIT 100'
)->fetchAll();

require_once __DIR__ . '/inc_header.php';
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Điều chỉnh giá vốn</h1>

<div class="card" style="max-width:640px;margin-bottom:24px;">
  <h2 style="font-size:14px;font-weight:600;margin:0 0 12px;">Tạo điều chỉnh</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" id="pa-form">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="product_id" id="pa-product-id">
    <input type="hidden" name="variant_id" id="pa-variant-id">

    <div class="field" style="position:relative;">
      <label>Tìm sản phẩm *</label>
      <input type="text" id="pa-search" class="input" placeholder="Nhập tên hoặc SKU..." autocomplete="off">
      <div id="pa-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:220px;overflow-y:auto;display:none;"></div>
    </div>
    <div class="field"><label>Giá vốn hiện tại</label><input class="input" id="pa-current-cost" disabled value="—"></div>
    <div class="field"><label>Giá vốn mới *</label><input class="input" type="number" min="0" name="new_cost_price" required></div>
    <div class="field"><label>Lý do</label><input class="input" name="reason" placeholder="vd: NCC tăng giá, cập nhật lại chi phí..."></div>
    <button type="submit" class="btn">Lưu điều chỉnh</button>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">Giá vốn cũ</th><th class="text-right">Giá vốn mới</th><th>Lý do</th><th>Người thực hiện</th><th>Ngày</th></tr></thead>
    <tbody>
      <?php if (!$adjustments): ?>
        <tr><td colspan="6" class="text-center muted" style="padding:32px;">Chưa có điều chỉnh nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($adjustments as $a): ?>
        <tr>
          <td><?= e($a['product_name']) ?><?php if ($a['variant_name']): ?> <span class="muted">(<?= e($a['variant_name']) ?>)</span><?php endif; ?></td>
          <td class="text-right muted"><?= money($a['old_cost_price']) ?></td>
          <td class="text-right" style="font-weight:600;"><?= money($a['new_cost_price']) ?></td>
          <td class="muted"><?= e($a['reason'] ?: '—') ?></td>
          <td><?= e($a['created_by_name']) ?></td>
          <td class="muted"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
const paSearch = document.getElementById('pa-search');
const paResults = document.getElementById('pa-results');
let paTimer = null;

paSearch.addEventListener('input', () => {
  clearTimeout(paTimer);
  const q = paSearch.value.trim();
  if (!q) { paResults.style.display = 'none'; return; }
  paTimer = setTimeout(() => {
    fetch('price_adjustment_search.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        if (!data.length) { paResults.style.display = 'none'; return; }
        paResults.innerHTML = data.map((p, i) => `
          <div class="pa-item" data-i="${i}" style="padding:8px 12px;font-size:14px;cursor:pointer;">${esc(p.name)} <span class="muted" style="font-family:monospace;font-size:12px;">(${esc(p.sku)})</span> · Vốn: ${fmt(p.cost_price)}</div>`).join('');
        paResults.style.display = 'block';
        paResults.querySelectorAll('.pa-item').forEach(el => {
          el.addEventListener('click', () => {
            const p = data[parseInt(el.dataset.i, 10)];
            document.getElementById('pa-product-id').value = p.id;
            document.getElementById('pa-variant-id').value = p.variant_id ?? '';
            document.getElementById('pa-current-cost').value = fmt(p.cost_price);
            paSearch.value = p.name;
            paResults.style.display = 'none';
          });
        });
      });
  }, 250);
});

function fmt(n) { return Math.round(n).toLocaleString('vi-VN'); }
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

document.addEventListener('click', (e) => {
  if (!e.target.closest('#pa-search') && !e.target.closest('#pa-results')) paResults.style.display = 'none';
});
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
