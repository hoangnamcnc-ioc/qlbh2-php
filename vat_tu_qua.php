<?php
/**
 * Vat tu tieu hao (tui nilon, hop dung...) va qua tang theo moc hoa don: khai bao quy tac, moi don hoan tat tu tru kho dung so luong.
 * Chi tru toi da so ton hien co (khong tao ton am); huy don hoan lai kho. Qua tang chi ap dung bac cao nhat ma don dat duoc.
 */
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$currentUser = requireRole('ADMIN', 'MANAGER');

$pdo = db();
$tenantId = currentTenantId();
$error = null;
$tab = ($_GET['tab'] ?? '') === 'gift' ? 'GIFT' : 'CONSUMABLE';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $kind = ($_POST['kind'] ?? '') === 'GIFT' ? 'GIFT' : 'CONSUMABLE';
    if ($action === 'add') {
        $pid = (int) ($_POST['product_id'] ?? 0);
        $qty = (float) ($_POST['qty'] ?? 0);
        $min = max(0.0, postFloat('min_total'));
        $own = $pdo->prepare("SELECT id FROM products WHERE id = ? AND tenant_id = ? AND product_type = 'PRODUCT' AND is_active = 1");
        $own->execute([$pid, $tenantId]);
        if (!$own->fetchColumn()) {
            $error = 'Chọn sản phẩm từ danh sách gợi ý.';
        } elseif ($qty <= 0 || $qty > 1000) {
            $error = 'Số lượng phải lớn hơn 0.';
        } elseif ($kind === 'GIFT' && $min <= 0) {
            $error = 'Quà tặng cần mốc hóa đơn lớn hơn 0.';
        } else {
            $pdo->prepare('INSERT INTO auto_deduct_rules (tenant_id, kind, product_id, qty, min_total) VALUES (?,?,?,?,?)')->execute([$tenantId, $kind, $pid, $qty, $min]);
            logActivity('AUTO_RULE_ADD', "$kind p=$pid qty=$qty min=$min");
            redirect('vat_tu_qua.php?tab=' . ($kind === 'GIFT' ? 'gift' : 'consumable') . '&ok=1');
        }
        $tab = $kind;
    } elseif ($action === 'toggle' || $action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'toggle') {
            $pdo->prepare('UPDATE auto_deduct_rules SET is_active = 1 - is_active WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
        } else {
            $pdo->prepare('DELETE FROM auto_deduct_rules WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
        }
        redirect('vat_tu_qua.php?tab=' . ($tab === 'GIFT' ? 'gift' : 'consumable') . '&ok=1');
    }
}

$rules = $pdo->prepare('SELECT r.*, p.name AS pname, COALESCE((SELECT SUM(a.qty) FROM order_auto_deductions a JOIN orders o ON o.id = a.order_id WHERE a.kind = r.kind AND a.product_id = r.product_id AND a.restored = 0 AND o.created_at >= DATE_FORMAT(NOW(), "%Y-%m-01")),0) AS used_month FROM auto_deduct_rules r JOIN products p ON p.id = r.product_id WHERE r.tenant_id = ? AND r.kind = ? ORDER BY r.min_total, r.id');
$rules->execute([$tenantId, $tab]);
$rules = $rules->fetchAll();
$fmt = static fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');

require_once __DIR__ . '/inc_header.php';
echo thanhTabNghiepVu('kho');
$isGift = $tab === 'GIFT';
?>
<h1 style="font-size:24px;font-weight:600;margin-bottom:6px;">Vật tư tiêu hao và quà tặng</h1>
<p class="muted" style="margin:0 0 14px;max-width:780px;font-size:14px;">
  <b>Vật tư tiêu hao</b> (túi nilon, hộp, ly...) tự trừ kho mỗi đơn hoàn tất. <b>Quà tặng theo mốc</b>: đơn đạt mốc tiền thì tự trừ kho phần quà (chỉ áp dụng mốc cao nhất đơn đạt được).
  Chỉ trừ tối đa số tồn hiện có tại chi nhánh bán, không làm tồn âm; hủy đơn sẽ hoàn lại kho. Vật tư và quà là các sản phẩm trong danh mục.
</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Đã lưu.</div><?php endif; ?>
<div style="display:flex;gap:6px;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
  <a href="vat_tu_qua.php?tab=consumable" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;<?= !$isGift ? 'background:var(--brand,#2563eb);color:#fff;font-weight:600;' : 'background:#f1f5f9;color:#334155;' ?>">Vật tư tiêu hao</a>
  <a href="vat_tu_qua.php?tab=gift" style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;<?= $isGift ? 'background:var(--brand,#2563eb);color:#fff;font-weight:600;' : 'background:#f1f5f9;color:#334155;' ?>">Quà tặng theo mốc</a>
</div>

<form method="post" class="card" style="margin-bottom:16px;max-width:860px;">
  <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="kind" value="<?= e($tab) ?>"><input type="hidden" name="product_id" id="pid">
  <div style="font-weight:600;margin-bottom:10px;"><?= $isGift ? 'Thêm mốc quà tặng' : 'Thêm vật tư tiêu hao' ?></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;align-items:end;">
    <div style="position:relative;"><label><?= $isGift ? 'Quà tặng' : 'Vật tư' ?></label><input class="input" id="psearch" autocomplete="off" placeholder="Tìm sản phẩm..."><div id="pres" style="display:none;position:absolute;z-index:10;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;max-height:220px;overflow:auto;"></div>
      <div id="pname" style="font-size:13px;font-weight:600;margin-top:3px;"></div></div>
    <div><label>Số lượng mỗi đơn</label><input class="input" type="number" name="qty" min="0.001" step="any" value="1" required></div>
    <div><label><?= $isGift ? 'Mốc hóa đơn từ (đồng)' : 'Chỉ khi đơn từ (đồng, 0 = mọi đơn)' ?></label><input class="input" type="number" name="min_total" min="0" step="1" value="0"></div>
    <div><button class="btn" type="submit">Thêm</button></div>
  </div>
</form>
<script>
(function () {
  var inp = document.getElementById('psearch'), box = document.getElementById('pres'), t = null;
  function esc(s) { return String(s || '').replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  inp.addEventListener('input', function () {
    clearTimeout(t); var q = inp.value.trim(); if (!q) { box.style.display = 'none'; return; }
    t = setTimeout(function () { fetch('stock_receipt_search.php?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (d) {
      d = d.filter(function (p) { return !p.variant_id; });
      if (!d.length) { box.style.display = 'none'; return; }
      box.innerHTML = d.map(function (p, i) { return '<div data-i="' + i + '" style="padding:8px 12px;font-size:14px;cursor:pointer;">' + esc(p.name) + ' <span class="muted" style="font-size:12px;">' + esc(p.sku) + '</span></div>'; }).join('');
      box.style.display = 'block';
      box.querySelectorAll('[data-i]').forEach(function (el) { el.addEventListener('click', function () { var p = d[+el.dataset.i]; document.getElementById('pid').value = p.id; document.getElementById('pname').textContent = p.name; inp.value = ''; box.style.display = 'none'; }); });
    }); }, 250);
  });
})();
</script>

<div class="card" style="overflow-x:auto;max-width:860px;">
  <table>
    <thead><tr><th><?= $isGift ? 'Quà tặng' : 'Vật tư' ?></th><th style="text-align:right;">SL mỗi đơn</th><th style="text-align:right;"><?= $isGift ? 'Mốc hóa đơn từ' : 'Đơn từ' ?></th><th style="text-align:right;">Đã trừ tháng này</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rules): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:24px;">Chưa có quy tắc nào.</td></tr><?php endif; ?>
    <?php foreach ($rules as $r): $on = (int) $r['is_active'] === 1; ?>
      <tr style="<?= $on ? '' : 'opacity:.55;' ?>">
        <td><?= e($r['pname']) ?></td><td style="text-align:right;"><?= $fmt($r['qty']) ?></td>
        <td style="text-align:right;"><?= (float) $r['min_total'] > 0 ? money($r['min_total']) : 'mọi đơn' ?></td>
        <td style="text-align:right;"><?= $fmt($r['used_month']) ?></td>
        <td style="white-space:nowrap;">
          <form method="post" style="display:inline;margin:0;"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit"><?= $on ? 'Tắt' : 'Bật' ?></button></form>
          <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Xóa quy tắc này?');"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-secondary" style="padding:3px 10px;font-size:12px;" type="submit">Xóa</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
