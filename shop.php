<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';

$pdo = db();
// Trang cong khai khong dang nhap: xac dinh cua hang theo ?s=<shop_slug> (Cau hinh > Dat hang Online).
// Khong co ?s= thi van la cua hang #1 de cac link cu da gui khach tiep tuc chay.
// ?t=<vi tri> (vd "Ban 3", "Quay") do ma QR in san cho tung ban/quay: duoc ghi vao ghi chu don.
$shopSlug = (string) ($_GET['s'] ?? '');
$shopTenantId = shopTenantFromSlug($shopSlug);
if ($shopTenantId === 0) {
    http_response_code(404);
    exit('Không tìm thấy cửa hàng.');
}
$branch = $pdo->prepare("SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY id LIMIT 1");
$branch->execute([$shopTenantId]);
$branch = $branch->fetch();
$storeName = getSettingForTenant($shopTenantId, 'store_name', 'Cửa hàng');
$storeLogo = getSettingForTenant($shopTenantId, 'store_logo', '');
$enabled = getSettingForTenant($shopTenantId, 'online_shop_enabled', '1') === '1';

$products = [];
if ($branch && $enabled) {
    $products = $pdo->prepare(
        "SELECT p.id, p.name, p.sku, p.sell_price, p.product_type, p.unit, c.name AS category_name,
                COALESCE(i.quantity, 0) AS qty,
                (SELECT filename FROM product_images WHERE product_id = p.id ORDER BY sort_order LIMIT 1) AS image
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN inventory i ON i.product_id = p.id AND i.branch_id = ? AND i.variant_id IS NULL
         WHERE p.tenant_id = ? AND p.is_active = 1 AND p.product_type = 'PRODUCT' AND COALESCE(i.quantity, 0) > 0
         ORDER BY c.name, p.name"
    );
    $products->execute([$branch['id'], $shopTenantId]);
    $products = $products->fetchAll();
}

$tableLabel = mb_substr(trim(preg_replace('/[^\p{L}\p{N} .\-]/u', '', (string) ($_GET['t'] ?? ''))), 0, 40);
$categories = [];
foreach ($products as $p) {
    $categories[$p['category_name'] ?: 'Khác'] = true;
}
$categories = array_keys($categories);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đặt hàng - <?= e($storeName) ?></title>
<?php if ($storeLogo): ?><link rel="icon" href="uploads/store/<?= e($storeLogo) ?>"><?php endif; ?>
<style>
  :root { --brand:#2563eb; --brand-dark:#1d4ed8; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --bg:#f1f5f9; }
  * { box-sizing:border-box; }
  body { font-family:-apple-system,Segoe UI,Roboto,sans-serif; background:var(--bg); margin:0; color:var(--ink); padding-bottom:84px; }
  .top { background:#fff; border-bottom:1px solid var(--line); position:sticky; top:0; z-index:20; }
  .top-in { max-width:640px; margin:0 auto; padding:10px 14px 8px; }
  .brand { display:flex; align-items:center; gap:10px; font-weight:700; font-size:17px; }
  .brand img { width:32px; height:32px; object-fit:contain; border-radius:8px; }
  .where { margin-left:auto; background:#eff6ff; color:var(--brand-dark); font-size:12.5px; font-weight:600; padding:3px 10px; border-radius:20px; white-space:nowrap; }
  .search { width:100%; margin-top:8px; border:1px solid var(--line); border-radius:10px; padding:9px 12px; font-size:15px; background:#f8fafc; }
  .chips { display:flex; gap:8px; overflow-x:auto; padding:8px 0 2px; scrollbar-width:none; }
  .chips::-webkit-scrollbar { display:none; }
  .chip { flex:0 0 auto; border:1px solid var(--line); background:#fff; border-radius:20px; padding:6px 13px; font-size:13.5px; cursor:pointer; color:var(--muted); }
  .chip.on { background:var(--brand); border-color:var(--brand); color:#fff; font-weight:600; }
  .wrap { max-width:640px; margin:0 auto; padding:12px 14px; }
  .alert { padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:12px; }
  .alert-success { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
  .alert-error { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
  .cat-title { font-size:13px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; margin:16px 2px 6px; }
  .item { display:flex; align-items:center; gap:12px; background:#fff; border:1px solid var(--line); border-radius:12px; padding:10px; margin-bottom:8px; }
  .item.in-cart { border-color:var(--brand); background:#f8fbff; }
  .thumb { width:56px; height:56px; border-radius:10px; background:#e2e8f0; object-fit:cover; flex:0 0 56px; display:flex; align-items:center; justify-content:center; font-size:22px; }
  .info { flex:1; min-width:0; }
  .name { font-weight:600; font-size:14.5px; line-height:1.3; }
  .price { color:var(--brand); font-weight:700; font-size:14.5px; margin-top:2px; }
  .price small { color:var(--muted); font-weight:400; }
  .low { color:#b45309; font-size:12px; }
  .stepper { display:flex; align-items:center; flex:0 0 auto; }
  .stepper button { width:34px; height:34px; border:1px solid var(--line); background:#fff; font-size:20px; line-height:1; border-radius:50%; cursor:pointer; color:var(--brand); padding:0; }
  .stepper button.plus { background:var(--brand); color:#fff; border-color:var(--brand); }
  .stepper input { width:42px; text-align:center; border:none; background:transparent; font-size:15px; font-weight:700; padding:0; -moz-appearance:textfield; }
  .stepper input::-webkit-outer-spin-button, .stepper input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
  .stepper.zero input, .stepper.zero .minus { visibility:hidden; width:0; padding:0; border:none; }
  .bar { position:fixed; left:0; right:0; bottom:0; background:#fff; border-top:1px solid var(--line); z-index:30; box-shadow:0 -4px 14px rgba(15,23,42,.08); }
  .bar-in { max-width:640px; margin:0 auto; padding:10px 14px; display:flex; align-items:center; gap:12px; }
  .bar-total { flex:1; font-size:13px; color:var(--muted); }
  .bar-total b { display:block; font-size:18px; color:var(--ink); }
  .go { background:var(--brand); color:#fff; border:none; border-radius:12px; padding:12px 20px; font-size:15px; font-weight:700; cursor:pointer; }
  .go:disabled { background:#cbd5e1; cursor:not-allowed; }
  .sheet { display:none; position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:40; }
  .sheet.open { display:block; }
  .sheet-in { position:absolute; left:0; right:0; bottom:0; max-height:92vh; overflow:auto; background:#fff; border-radius:18px 18px 0 0; padding:16px 16px 22px; max-width:640px; margin:0 auto; }
  .sheet h2 { font-size:18px; margin:0 0 10px; }
  .sum-line { display:flex; justify-content:space-between; gap:10px; font-size:14px; padding:4px 0; border-bottom:1px dashed var(--line); }
  .sum-total { display:flex; justify-content:space-between; font-weight:700; font-size:16px; padding:10px 0 4px; }
  label { display:block; font-size:13px; font-weight:600; margin:12px 0 4px; color:#334155; }
  .f { width:100%; border:1px solid #cbd5e1; border-radius:10px; padding:10px 12px; font-size:15px; font-family:inherit; }
  .seg { display:flex; gap:8px; margin-top:4px; }
  .seg label { flex:1; margin:0; font-weight:600; border:1px solid var(--line); border-radius:10px; padding:10px; text-align:center; cursor:pointer; font-size:14px; }
  .seg input { display:none; }
  .seg label:has(input:checked) { border-color:var(--brand); background:#eff6ff; color:var(--brand-dark); }
  .close { float:right; background:none; border:none; font-size:24px; color:var(--muted); cursor:pointer; }
  .muted { color:var(--muted); font-size:13px; }
  .empty { text-align:center; color:var(--muted); padding:30px 10px; }
</style>
</head>
<body>
<div class="top"><div class="top-in">
  <div class="brand">
    <?php if ($storeLogo): ?><img src="uploads/store/<?= e($storeLogo) ?>" alt=""><?php else: ?><span style="font-size:24px;">🛒</span><?php endif; ?>
    <span><?= e($storeName) ?></span>
    <?php if ($tableLabel !== ''): ?><span class="where">📍 <?= e($tableLabel) ?></span><?php endif; ?>
  </div>
  <?php if ($branch && $enabled && $products): ?>
    <input class="search" id="q" type="search" placeholder="Tìm sản phẩm..." autocomplete="off">
    <?php if (count($categories) > 1): ?>
      <div class="chips" id="chips">
        <div class="chip on" data-cat="">Tất cả</div>
        <?php foreach ($categories as $c): ?><div class="chip" data-cat="<?= e($c) ?>"><?= e($c) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div></div>

<div class="wrap">
  <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success" style="font-size:15px;">
      ✅ <b>Đặt hàng thành công!</b> Mã đơn <b><?= e($_GET['code'] ?? '') ?></b>.<br>
      Cửa hàng sẽ xác nhận với bạn sớm nhất<?= $tableLabel !== '' ? ' và phục vụ tại ' . e($tableLabel) : '' ?>. Cảm ơn bạn!
    </div>
  <?php elseif (isset($_GET['err'])): ?>
    <div class="alert alert-error"><?= e($_GET['err']) ?></div>
  <?php endif; ?>

  <?php if (!$branch || !$enabled): ?>
    <div class="empty">Cửa hàng hiện chưa mở đặt hàng online, vui lòng quay lại sau.</div>
  <?php elseif (!$products): ?>
    <div class="empty">Hiện chưa có sản phẩm nào để đặt hàng.</div>
  <?php else: ?>
  <form method="post" action="shop_order.php" id="orderForm">
    <input type="hidden" name="s" value="<?= e($shopSlug) ?>">
    <input type="hidden" name="t" value="<?= e($tableLabel) ?>">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

    <div id="list">
      <?php $lastCat = null; foreach ($products as $p): $cat = $p['category_name'] ?: 'Khác'; ?>
        <?php if ($cat !== $lastCat): $lastCat = $cat; ?><div class="cat-title" data-cat-title="<?= e($cat) ?>"><?= e($cat) ?></div><?php endif; ?>
        <?php $weighted = in_array(mb_strtolower((string) $p['unit']), ['kg', 'lít', 'lit', 'l', 'm', 'mét'], true); ?>
        <div class="item" data-name="<?= e(mb_strtolower($p['name'] . ' ' . $p['sku'])) ?>" data-cat="<?= e($cat) ?>"
             data-price="<?= (float) $p['sell_price'] ?>" data-label="<?= e($p['name']) ?>" data-max="<?= (float) $p['qty'] ?>" data-step="<?= $weighted ? '0.5' : '1' ?>">
          <?php if ($p['image']): ?><img class="thumb" src="uploads/products/<?= e($p['image']) ?>" alt="" loading="lazy">
          <?php else: ?><div class="thumb">🛍️</div><?php endif; ?>
          <div class="info">
            <div class="name"><?= e($p['name']) ?></div>
            <div class="price"><?= number_format((float) $p['sell_price'], 0, ',', '.') ?>đ<?= $p['unit'] ? ' <small>/ ' . e($p['unit']) . '</small>' : '' ?></div>
            <?php if ((float) $p['qty'] <= 5): ?><div class="low">Chỉ còn <?= fmtQty($p['qty']) ?></div><?php endif; ?>
          </div>
          <div class="stepper zero">
            <button type="button" class="minus" aria-label="Giảm">−</button>
            <input type="number" inputmode="decimal" min="0" step="any" max="<?= (float) $p['qty'] ?>" name="qty[<?= (int) $p['id'] ?>]" value="0" aria-label="Số lượng">
            <button type="button" class="plus" aria-label="Tăng">+</button>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="empty" id="noResult" style="display:none;">Không tìm thấy sản phẩm phù hợp.</div>
    </div>

    <div class="sheet" id="sheet">
      <div class="sheet-in">
        <button type="button" class="close" id="closeSheet" aria-label="Đóng">×</button>
        <h2>Xác nhận đơn hàng</h2>
        <div id="summary"></div>
        <div class="sum-total"><span>Tổng cộng</span><span id="sumTotal">0đ</span></div>

        <label>Hình thức nhận hàng</label>
        <div class="seg">
          <label><input type="radio" name="receive" value="pickup" checked><span><?= $tableLabel !== '' ? 'Phục vụ tại chỗ' : 'Nhận tại cửa hàng' ?></span></label>
          <label><input type="radio" name="receive" value="delivery"><span>Giao tận nơi</span></label>
        </div>
        <div id="addrBox" style="display:none;">
          <label>Địa chỉ nhận hàng *</label>
          <input class="f" type="text" name="customer_address" id="addr" autocomplete="street-address">
        </div>
        <label>Số điện thoại *</label>
        <input class="f" type="tel" inputmode="numeric" name="customer_phone" required pattern="[0-9]{9,11}" autocomplete="tel" placeholder="Để cửa hàng liên hệ xác nhận">
        <label>Tên của bạn</label>
        <input class="f" type="text" name="customer_name" autocomplete="name" placeholder="Không bắt buộc">
        <label>Ghi chú</label>
        <textarea class="f" name="note" rows="2" placeholder="vd: giao giờ hành chính..."></textarea>
        <button type="submit" class="go" style="width:100%;margin-top:16px;">Đặt hàng</button>
        <p class="muted" style="margin:10px 0 0;">Chưa thanh toán online — cửa hàng sẽ xác nhận rồi thu tiền khi giao/phục vụ.</p>
      </div>
    </div>

    <div class="bar"><div class="bar-in">
      <div class="bar-total"><span id="cartCount">Chưa chọn sản phẩm nào</span><b id="cartTotal">0đ</b></div>
      <button type="button" class="go" id="openSheet" disabled>Xem đơn &amp; đặt hàng</button>
    </div></div>
  </form>
  <?php endif; ?>
</div>

<script>
(function () {
  const items = Array.from(document.querySelectorAll('.item'));
  if (!items.length) return;
  const money = (n) => Math.round(n).toLocaleString('vi-VN') + 'đ';
  const esc = (t) => String(t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const sheet = document.getElementById('sheet');
  let activeCat = '';

  function recalc() {
    let total = 0, count = 0, lines = [];
    items.forEach(it => {
      const inp = it.querySelector('input');
      let q = parseFloat(inp.value) || 0;
      const max = parseFloat(it.dataset.max);
      if (q > max) { q = max; inp.value = q; }
      it.querySelector('.stepper').classList.toggle('zero', q <= 0);
      it.classList.toggle('in-cart', q > 0);
      if (q > 0) {
        const line = q * parseFloat(it.dataset.price);
        total += line; count += 1;
        lines.push('<div class="sum-line"><span>' + esc(it.dataset.label) + ' × ' + q + '</span><span>' + money(line) + '</span></div>');
      }
    });
    document.getElementById('cartTotal').textContent = money(total);
    document.getElementById('cartCount').textContent = count ? count + ' sản phẩm' : 'Chưa chọn sản phẩm nào';
    document.getElementById('openSheet').disabled = !count;
    document.getElementById('summary').innerHTML = lines.join('');
    document.getElementById('sumTotal').textContent = money(total);
    if (!count) sheet.classList.remove('open');
  }

  items.forEach(it => {
    const inp = it.querySelector('input'), step = parseFloat(it.dataset.step);
    it.querySelector('.plus').addEventListener('click', () => { inp.value = Math.min(parseFloat(it.dataset.max), (parseFloat(inp.value) || 0) + step); recalc(); });
    it.querySelector('.minus').addEventListener('click', () => { inp.value = Math.max(0, (parseFloat(inp.value) || 0) - step); recalc(); });
    inp.addEventListener('input', recalc);
  });

  function filter() {
    const q = document.getElementById('q').value.trim().toLowerCase();
    let shown = 0;
    items.forEach(it => {
      const ok = (!activeCat || it.dataset.cat === activeCat) && (!q || it.dataset.name.includes(q));
      it.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    document.querySelectorAll('.cat-title').forEach(t => {
      t.style.display = items.some(it => it.dataset.cat === t.dataset.catTitle && it.style.display !== 'none') ? '' : 'none';
    });
    document.getElementById('noResult').style.display = shown ? 'none' : '';
  }
  document.getElementById('q').addEventListener('input', filter);
  document.querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => {
    document.querySelectorAll('.chip').forEach(x => x.classList.remove('on'));
    c.classList.add('on'); activeCat = c.dataset.cat; filter();
  }));

  document.getElementById('openSheet').addEventListener('click', () => sheet.classList.add('open'));
  document.getElementById('closeSheet').addEventListener('click', () => sheet.classList.remove('open'));
  sheet.addEventListener('click', (e) => { if (e.target === sheet) sheet.classList.remove('open'); });
  const addrBox = document.getElementById('addrBox'), addr = document.getElementById('addr');
  document.querySelectorAll('input[name=receive]').forEach(r => r.addEventListener('change', () => {
    const delivery = document.querySelector('input[name=receive]:checked').value === 'delivery';
    addrBox.style.display = delivery ? '' : 'none'; addr.required = delivery;
  }));
  recalc();
})();
</script>
</body>
</html>
