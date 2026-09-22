<?php
/**
 * Tao don GIAO HANG nhap tay (§4.1 dac ta) - danh cho don ban online/dien thoai, khong phai
 * khach dung truoc quay.
 *
 * Vi sao khong tu viet logic tao don: pos_checkout.php da xu ly san combo, bien the, san gia,
 * bang gia theo nhom khach, khuyen mai, ma giam gia, tru ton kho co khoa dong, so quy, phieu bao
 * hanh va diem tich luy - va da duoc va nhieu loi that. Trang nay chi la GIAO DIEN, gui du lieu
 * sang dung endpoint do (kem draft=1, is_delivery=1). Viet lai se sinh ra mot ban sao thieu sot.
 */
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$tenantId = currentTenantId();
$branchId = effectiveBranchId($currentUser);

$branchName = '';
if ($branchId) {
    $bStmt = $pdo->prepare('SELECT name FROM branches WHERE id = ? AND tenant_id = ?');
    $bStmt->execute([$branchId, $tenantId]);
    $branchName = (string) $bStmt->fetchColumn();
}

$channelsStmt = $pdo->prepare('SELECT id, name FROM sales_channels WHERE tenant_id = ? AND is_active = 1 ORDER BY name');
$channelsStmt->execute([$tenantId]);
$channels = $channelsStmt->fetchAll();

$sourcesStmt = $pdo->prepare('SELECT id, name FROM order_sources WHERE tenant_id = ? AND is_active = 1 ORDER BY name');
$sourcesStmt->execute([$tenantId]);
$sources = $sourcesStmt->fetchAll();

// Noi dung nap san khi nguoi dung bam "Sao chep don" o mot don cu (order_copy.php). Lay ra xong
// la xoa ngay khoi session, de F5 lai trang khong bi nap de len viec dang nhap do.
$copy = $_SESSION['order_copy'] ?? null;
unset($_SESSION['order_copy']);
?>

<a href="orders.php" class="muted" style="font-size:14px;">← Danh sách đơn hàng</a>
<h1 style="font-size:24px;font-weight:600;margin:8px 0 4px;">Tạo đơn giao hàng</h1>
<p class="muted" style="margin:0 0 20px;font-size:13px;">
  Dùng cho đơn đặt qua điện thoại / mạng xã hội — đơn được tạo ở trạng thái <b>Đặt hàng</b>, chưa
  thu tiền. Khách đứng trước quầy thì dùng <a href="pos.php">Bán hàng tại quầy</a> sẽ nhanh hơn.
</p>

<?php if (!$branchId): ?>
  <div class="alert alert-error">Tài khoản của bạn chưa được gán chi nhánh nên chưa tạo đơn được. Liên hệ quản trị viên.</div>
<?php else: ?>

<div id="form-message"></div>

<?php if ($copy): ?>
  <div class="alert alert-success" style="margin-bottom:16px;">
    Đã chép nội dung từ đơn <b><?= e($copy['from_code']) ?></b>. Hãy kiểm tra lại số lượng và đơn giá
    (hàng có thể đã hết hoặc giá đã thay đổi) rồi bấm <b>Tạo đơn giao hàng</b>.
  </div>
<?php endif; ?>

<div class="card" style="max-width:720px;margin-bottom:16px;">
  <p class="muted" style="margin:0 0 14px;font-size:13px;">
    Đơn sẽ được tạo tại chi nhánh <b><?= e($branchName) ?></b> và trừ tồn kho của chi nhánh này.
    Muốn bán cho chi nhánh khác, hãy đổi chi nhánh ở màn hình <a href="pos.php">Bán hàng tại quầy</a>.
  </p>
  <div class="grid-2">
    <div class="field">
      <label for="f-phone">Số điện thoại người nhận *</label>
      <input class="input" id="f-phone" placeholder="09xxxxxxxx" value="<?= e($copy['customer_phone'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="f-name">Tên người nhận</label>
      <input class="input" id="f-name" placeholder="vd: Nguyễn Văn A" value="<?= e($copy['customer_name'] ?? '') ?>">
    </div>
  </div>
  <div class="field">
    <label for="f-address">Địa chỉ giao hàng *</label>
    <input class="input" id="f-address" placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành" value="<?= e($copy['address'] ?? '') ?>">
  </div>
  <div class="grid-2">
    <div class="field">
      <label for="f-channel">Kênh bán hàng</label>
      <select class="input" id="f-channel">
        <option value="">— Không chọn —</option>
        <?php foreach ($channels as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-source">Nguồn đơn</label>
      <select class="input" id="f-source">
        <option value="">— Không chọn —</option>
        <?php foreach ($sources as $sr): ?>
          <option value="<?= (int) $sr['id'] ?>"><?= e($sr['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<div style="position:relative;margin-bottom:12px;max-width:720px;">
  <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm theo tên, SKU hoặc quét mã vạch để thêm vào đơn...">
  <div id="search-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:280px;overflow-y:auto;display:none;"></div>
</div>

<div class="card" style="padding:0;overflow-x:auto;max-width:800px;margin-bottom:16px;">
  <table>
    <thead><tr><th>Sản phẩm</th><th class="text-right">SL</th><th class="text-right">Đơn giá</th><th class="text-right">Thành tiền</th><th></th></tr></thead>
    <tbody id="lines-body">
      <tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>
    </tbody>
  </table>
</div>

<div class="card" style="max-width:400px;margin-left:auto;margin-bottom:16px;">
  <div class="field"><label for="f-ship">Phí giao hàng</label><input class="input" type="number" min="0" id="f-ship" value="<?= (float) ($copy['shipping_fee'] ?? 0) ?>"></div>
  <div class="field"><label for="f-note">Ghi chú đơn hàng</label><input class="input" id="f-note" placeholder="vd: giao giờ hành chính" value="<?= e($copy['note'] ?? '') ?>"></div>
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;"><span>Tiền hàng</span><span id="sub-total">0</span></div>
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px;"><span>Phí giao hàng</span><span id="ship-display">0</span></div>
  <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:8px;">
    <span>Tổng tiền</span><b id="grand-total" style="font-size:18px;color:#2563eb;">0</b>
  </div>
</div>

<div style="max-width:800px;text-align:right;">
  <button type="button" id="submit-btn" class="btn" style="padding:10px 24px;font-weight:600;">Tạo đơn giao hàng</button>
</div>

<script>
const csrfToken = <?= json_encode(csrfToken()) ?>;
let lines = <?= json_encode(array_map(static function (array $l): array {
    $l['key'] = $l['id'] . ':' . ($l['variantId'] ?? '');
    return $l;
}, $copy['lines'] ?? []), JSON_UNESCAPED_UNICODE) ?>;
const searchInput = document.getElementById('search-input');
const searchResults = document.getElementById('search-results');
let timer = null;

function esc(t) { return String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function fmt(n) { return new Intl.NumberFormat('vi-VN').format(Math.round(n)); }

searchInput.addEventListener('input', () => {
  clearTimeout(timer);
  const q = searchInput.value.trim();
  if (!q) { searchResults.style.display = 'none'; return; }
  timer = setTimeout(() => {
    fetch('pos_search.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        if (!data.length) { searchResults.style.display = 'none'; return; }
        searchResults.innerHTML = data.map((p, i) => `
          <div class="s-item" data-i="${i}" style="padding:8px 12px;font-size:14px;cursor:pointer;">
            ${esc(p.name)} <span class="muted" style="font-size:12px;">${fmt(p.sell_price)} · Tồn: ${esc(p.qty)}</span>
          </div>`).join('');
        searchResults.style.display = 'block';
        searchResults.querySelectorAll('.s-item').forEach(el => {
          el.addEventListener('click', () => {
            const p = data[parseInt(el.dataset.i, 10)];
            addLine(p.id, p.variant_id, p.name, parseFloat(p.sell_price) || 0);
            searchInput.value = '';
            searchResults.style.display = 'none';
          });
        });
      });
  }, 250);
});

// May quet ma vach go xong tu bam Enter - them thang san pham khop, giong POS va phieu nhap hang.
searchInput.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  const q = searchInput.value.trim();
  if (!q) return;
  fetch('pos_search.php?q=' + encodeURIComponent(q))
    .then(r => r.json())
    .then(data => {
      const p = data.length === 1 ? data[0] : data.find(x => x.sku === q);
      if (!p) return;
      addLine(p.id, p.variant_id, p.name, parseFloat(p.sell_price) || 0);
      searchInput.value = '';
      searchResults.style.display = 'none';
    });
});

function addLine(id, variantId, name, price) {
  const key = id + ':' + (variantId ?? '');
  const existing = lines.find(l => l.key === key);
  if (existing) { existing.qty += 1; } else { lines.push({ key, id, variantId, name, qty: 1, price }); }
  render();
}

function render() {
  const body = document.getElementById('lines-body');
  if (!lines.length) {
    body.innerHTML = '<tr id="empty-row"><td colspan="5" class="text-center muted" style="padding:24px;">Chưa có sản phẩm nào</td></tr>';
  } else {
    body.innerHTML = lines.map((l, i) => `
      <tr>
        <td>${esc(l.name)}</td>
        <td class="text-right"><input type="number" min="0.001" step="0.001" value="${l.qty}" data-i="${i}" data-f="qty" style="width:70px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
        <td class="text-right"><input type="number" min="0" value="${l.price}" data-i="${i}" data-f="price" style="width:110px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
        <td class="text-right">${fmt(l.qty * l.price)}</td>
        <td class="text-right"><a href="#" data-i="${i}" class="remove" style="color:#ef4444;font-size:12px;">Xóa</a></td>
      </tr>`).join('');
    body.querySelectorAll('input[data-f]').forEach(inp => {
      inp.addEventListener('input', () => {
        const i = parseInt(inp.dataset.i, 10);
        const v = parseFloat(inp.value) || 0;
        if (inp.dataset.f === 'qty') lines[i].qty = v; else lines[i].price = v;
        totals();
      });
    });
    body.querySelectorAll('.remove').forEach(a => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        lines.splice(parseInt(a.dataset.i, 10), 1);
        render();
      });
    });
  }
  totals();
}

function totals() {
  const sub = lines.reduce((s, l) => s + l.qty * l.price, 0);
  const ship = parseFloat(document.getElementById('f-ship').value) || 0;
  document.getElementById('sub-total').textContent = fmt(sub);
  document.getElementById('ship-display').textContent = fmt(ship);
  document.getElementById('grand-total').textContent = fmt(sub + ship);
}

// Tu dien phi giao theo bieu phi khai bao o shipping_settings.php: do tu khoa khu vuc trong dia
// chi nguoi dung vua go. Chi dien khi o phi dang de trong hoac dang bang phi goi y truoc do -
// khong bao gio ghi de con so nhan vien da co y sua tay.
const shippingZones = <?= json_encode(json_decode(getSetting('shipping_zones', '[]'), true) ?: [], JSON_UNESCAPED_UNICODE) ?>;
const shippingDefaultFee = <?= (float) getSetting('shipping_default_fee', '0') ?>;
let lastSuggestedFee = null;

function boDau(s) {
  return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
}

function phiTheoDiaChi(address) {
  const a = boDau(address);
  if (!a.trim()) return null;
  for (const z of shippingZones) {
    for (const kw of (z.keywords || [])) {
      if (kw && a.includes(boDau(kw))) return Number(z.fee) || 0;
    }
  }
  return shippingDefaultFee > 0 ? shippingDefaultFee : null;
}

function apDungPhiGoiY(addressEl, feeEl, onChange) {
  const fee = phiTheoDiaChi(addressEl.value);
  if (fee === null) return;
  const cur = parseFloat(feeEl.value) || 0;
  if (cur === 0 || (lastSuggestedFee !== null && cur === lastSuggestedFee)) {
    feeEl.value = fee;
    lastSuggestedFee = fee;
    if (onChange) onChange();
  }
}

document.getElementById('f-ship').addEventListener('input', totals);
(() => {
  const addr = document.getElementById('f-address');
  const fee = document.getElementById('f-ship');
  addr.addEventListener('input', () => apDungPhiGoiY(addr, fee, totals));
})();
render(); // ve san cac dong da chep tu don cu (neu co)

function message(html, isError) {
  document.getElementById('form-message').innerHTML =
    '<div class="alert alert-' + (isError ? 'error' : 'success') + '" style="margin-bottom:16px;">' + html + '</div>';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

const submitBtn = document.getElementById('submit-btn');
submitBtn.addEventListener('click', () => {
  const phone = document.getElementById('f-phone').value.trim();
  const address = document.getElementById('f-address').value.trim();
  if (!phone) { message('Vui lòng nhập số điện thoại người nhận.', true); return; }
  if (!address) { message('Vui lòng nhập địa chỉ giao hàng.', true); return; }
  if (!lines.length) { message('Vui lòng thêm ít nhất 1 sản phẩm vào đơn.', true); return; }

  submitBtn.disabled = true;
  submitBtn.textContent = 'Đang tạo đơn...';
  fetch('pos_checkout.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      csrf: csrfToken,
      draft: true,
      is_delivery: true,
      source: 'ONLINE',
      customer_phone: phone,
      customer_name: document.getElementById('f-name').value.trim(),
      delivery_address: address,
      shipping_fee: parseFloat(document.getElementById('f-ship').value) || 0,
      channel_id: parseInt(document.getElementById('f-channel').value, 10) || 0,
      source_id: parseInt(document.getElementById('f-source').value, 10) || 0,
      note: document.getElementById('f-note').value.trim(),
      payment_method: 'CASH',
      items: lines.map(l => ({ product_id: l.id, variant_id: l.variantId, quantity: l.qty, unit_price: l.price })),
    }),
  })
    .then(r => r.json())
    .then(d => {
      if (d && d.order_id) {
        window.location = 'order_view.php?id=' + d.order_id;
        return;
      }
      message(esc((d && d.error) || 'Không tạo được đơn hàng, vui lòng thử lại.'), true);
      submitBtn.disabled = false;
      submitBtn.textContent = 'Tạo đơn giao hàng';
    })
    .catch(() => {
      message('Lỗi kết nối, vui lòng thử lại.', true);
      submitBtn.disabled = false;
      submitBtn.textContent = 'Tạo đơn giao hàng';
    });
});
</script>

<?php endif; ?>
<?php require_once __DIR__ . '/inc_footer.php'; ?>
