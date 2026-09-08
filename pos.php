<?php
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$branchId = (int) ($currentUser['branch_id'] ?? 0);
?>

<h1 style="font-size:24px;font-weight:600;margin-bottom:24px;">Bán hàng tại quầy</h1>

<?php if (!$branchId): ?>
  <div class="alert alert-warning">Tài khoản của bạn chưa được gán chi nhánh, không thể bán hàng. Liên hệ quản trị viên.</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;" id="pos-app">
  <div>
    <div style="position:relative;margin-bottom:16px;">
      <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm theo tên, SKU hoặc quét mã vạch..." autocomplete="off">
      <div id="search-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:280px;overflow-y:auto;display:none;"></div>
    </div>

    <div class="card" style="padding:0;overflow-x:auto;">
      <table>
        <thead>
          <tr><th>Sản phẩm</th><th class="text-right">Đơn giá</th><th class="text-center">SL</th><th class="text-right">Thành tiền</th><th></th></tr>
        </thead>
        <tbody id="cart-body">
          <tr id="cart-empty"><td colspan="5" class="text-center muted" style="padding:40px;">Đơn hàng chưa có sản phẩm</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="height:fit-content;">
    <div id="pos-message"></div>

    <div class="field">
      <label>SĐT khách hàng (không bắt buộc)</label>
      <input type="text" id="customer-phone" class="input" placeholder="09xxxxxxxx">
    </div>

    <div class="field">
      <label>Hình thức thanh toán</label>
      <select id="payment-method" class="input">
        <option value="CASH">Tiền mặt</option>
        <option value="BANK_TRANSFER">Chuyển khoản</option>
        <option value="CARD">Quẹt thẻ</option>
        <option value="QR_CODE">Quét mã QR</option>
      </select>
    </div>

    <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:12px;margin-bottom:16px;">
      <span>Tổng tiền</span>
      <span id="cart-total" style="font-size:20px;font-weight:700;color:#2563eb;">0</span>
    </div>

    <button id="checkout-btn" class="btn" style="width:100%;padding:12px;font-weight:600;" <?= $branchId ? '' : 'disabled' ?>>Thanh toán</button>
  </div>
</div>

<script>
const csrfToken = <?= json_encode(csrfToken()) ?>;
let cart = [];

const searchInput = document.getElementById('search-input');
const searchResults = document.getElementById('search-results');
let searchTimer = null;

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = searchInput.value.trim();
  if (!q) { searchResults.style.display = 'none'; return; }
  searchTimer = setTimeout(() => doSearch(q), 250);
});

function doSearch(q) {
  fetch('pos_search.php?q=' + encodeURIComponent(q))
    .then(r => r.json())
    .then(data => {
      if (!data.length) { searchResults.style.display = 'none'; return; }
      searchResults.innerHTML = data.map((p, i) => `
        <div class="search-item" data-i="${i}"
             style="padding:8px 12px;font-size:14px;cursor:pointer;display:flex;justify-content:space-between;">
          <span>${escapeHtml(p.name)} <span class="muted" style="font-family:monospace;font-size:12px;">(${escapeHtml(p.sku)})</span></span>
          <span class="muted">${formatMoney(p.sell_price)} · Tồn: ${p.qty}</span>
        </div>`).join('');
      searchResults.style.display = 'block';
      searchResults.querySelectorAll('.search-item').forEach(el => {
        el.addEventListener('mouseenter', () => el.style.background = '#f8fafc');
        el.addEventListener('mouseleave', () => el.style.background = '#fff');
        el.addEventListener('click', () => {
          const p = data[parseInt(el.dataset.i, 10)];
          addToCart(p.id, p.variant_id, p.name, parseFloat(p.sell_price));
          searchInput.value = '';
          searchResults.style.display = 'none';
        });
      });
    });
}

function addToCart(id, variantId, name, price) {
  const key = id + ':' + (variantId ?? '');
  const existing = cart.find(c => c.key === key);
  if (existing) { existing.qty += 1; } else { cart.push({ key, id, variantId, name, price, qty: 1 }); }
  renderCart();
}

function renderCart() {
  const body = document.getElementById('cart-body');
  if (!cart.length) {
    body.innerHTML = '<tr id="cart-empty"><td colspan="5" class="text-center muted" style="padding:40px;">Đơn hàng chưa có sản phẩm</td></tr>';
  } else {
    body.innerHTML = cart.map((c, i) => `
      <tr>
        <td>${escapeHtml(c.name)}</td>
        <td class="text-right">${formatMoney(c.price)}</td>
        <td class="text-center"><input type="number" min="1" value="${c.qty}" data-idx="${i}" class="qty-input" style="width:64px;text-align:center;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
        <td class="text-right" style="font-weight:600;">${formatMoney(c.price * c.qty)}</td>
        <td class="text-right"><a href="#" data-idx="${i}" class="remove-item" style="color:#ef4444;font-size:12px;">Xóa</a></td>
      </tr>`).join('');
    body.querySelectorAll('.qty-input').forEach(inp => {
      inp.addEventListener('change', () => {
        const idx = parseInt(inp.dataset.idx, 10);
        cart[idx].qty = Math.max(1, parseInt(inp.value, 10) || 1);
        renderCart();
      });
    });
    body.querySelectorAll('.remove-item').forEach(a => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        cart.splice(parseInt(a.dataset.idx, 10), 1);
        renderCart();
      });
    });
  }
  const total = cart.reduce((s, c) => s + c.price * c.qty, 0);
  document.getElementById('cart-total').textContent = formatMoney(total);
}

document.getElementById('checkout-btn').addEventListener('click', () => {
  const msgBox = document.getElementById('pos-message');
  msgBox.innerHTML = '';
  if (!cart.length) return;

  const btn = document.getElementById('checkout-btn');
  btn.disabled = true;
  btn.textContent = 'Đang xử lý...';

  fetch('pos_checkout.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      csrf: csrfToken,
      items: cart.map(c => ({ product_id: c.id, variant_id: c.variantId, quantity: c.qty, unit_price: c.price })),
      payment_method: document.getElementById('payment-method').value,
      customer_phone: document.getElementById('customer-phone').value,
    }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        msgBox.innerHTML = `<div class="alert alert-error">${escapeHtml(data.error)}</div>`;
      } else {
        msgBox.innerHTML = `<div class="alert alert-success">Đã tạo đơn hàng ${escapeHtml(data.code)} thành công!</div>`;
        cart = [];
        renderCart();
        document.getElementById('customer-phone').value = '';
      }
    })
    .catch(() => { msgBox.innerHTML = '<div class="alert alert-error">Có lỗi xảy ra, vui lòng thử lại.</div>'; })
    .finally(() => { btn.disabled = false; btn.textContent = 'Thanh toán'; });
});

function formatMoney(n) { return Math.round(n).toLocaleString('vi-VN'); }
function escapeHtml(s) {
  const div = document.createElement('div');
  div.textContent = s;
  return div.innerHTML;
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('#search-input') && !e.target.closest('#search-results')) {
    searchResults.style.display = 'none';
  }
});
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
