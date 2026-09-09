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
    <div id="order-tabs" style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;align-items:center;"></div>

    <div style="position:relative;margin-bottom:16px;">
      <input type="text" id="search-input" class="input" placeholder="Tìm sản phẩm theo tên, SKU hoặc quét mã vạch..." autocomplete="off">
      <div id="search-results" style="position:absolute;z-index:10;margin-top:4px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);max-height:280px;overflow-y:auto;display:none;"></div>
    </div>

    <div style="display:flex;gap:6px;margin-bottom:12px;">
      <button type="button" id="tab-browse-off" class="btn btn-secondary" style="background:#2563eb;color:#fff;">Giỏ hàng</button>
      <button type="button" id="tab-browse-on" class="btn btn-secondary">Danh sách sản phẩm</button>
    </div>

    <div id="product-browse" style="display:none;margin-bottom:16px;">
      <div class="card" style="max-height:360px;overflow-y:auto;">
        <div id="browse-list" class="muted" style="padding:16px;text-align:center;">Đang tải danh sách sản phẩm...</div>
      </div>
    </div>

    <div id="cart-view">
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

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-top:16px;">
      <button type="button" id="qa-add-service" class="btn btn-secondary">Thêm dịch vụ (F9)</button>
      <button type="button" id="qa-promotions" class="btn btn-secondary">Khuyến mại (F8)</button>
      <button type="button" id="qa-gift" class="btn btn-secondary">Đổi quà</button>
      <button type="button" id="qa-clear-cart" class="btn btn-secondary">Xóa toàn bộ sản phẩm</button>
      <a href="customers.php" class="btn btn-secondary" style="text-align:center;">Thông tin khách hàng</a>
      <a href="order_returns.php" class="btn btn-secondary" style="text-align:center;">Đổi trả hàng</a>
      <a href="orders.php" class="btn btn-secondary" style="text-align:center;">Xem danh sách đơn hàng</a>
      <a href="reports.php" class="btn btn-secondary" style="text-align:center;">Xem báo cáo</a>
      <a href="sales_settings.php" class="btn btn-secondary" style="text-align:center;">Thiết lập chung</a>
    </div>
    <div id="service-picker" style="display:none;margin-top:8px;" class="card">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Chọn dịch vụ để thêm vào đơn</label>
      <select id="service-select" class="input">
        <option value="">— Đang tải danh sách dịch vụ —</option>
      </select>
    </div>
    <div id="promotions-panel" style="display:none;margin-top:8px;" class="card"></div>
    <div id="gift-panel" style="display:none;margin-top:8px;" class="card"></div>
    </div>
  </div>

  <div class="card" style="height:fit-content;">
    <div id="pos-message"></div>

    <div class="field">
      <label>SĐT khách hàng (không bắt buộc)</label>
      <input type="text" id="customer-phone" class="input" placeholder="09xxxxxxxx">
      <div id="customer-info" class="muted" style="font-size:12px;margin-top:4px;"></div>
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

    <div class="field">
      <label>Chiết khấu đơn (F6, không bắt buộc)</label>
      <div style="display:flex;gap:8px;">
        <input type="number" min="0" id="manual-discount-value" class="input" placeholder="0" style="flex:1;">
        <select id="manual-discount-type" class="input" style="max-width:90px;">
          <option value="AMOUNT">VNĐ</option>
          <option value="PERCENT">%</option>
        </select>
      </div>
    </div>

    <div class="field">
      <label>Mã giảm giá (không bắt buộc)</label>
      <div style="display:flex;gap:8px;">
        <input type="text" id="coupon-input" class="input" placeholder="vd: SALE10" style="text-transform:uppercase;">
        <button type="button" id="coupon-apply-btn" class="btn btn-secondary" style="white-space:nowrap;">Áp dụng</button>
      </div>
      <div id="coupon-message" style="font-size:12px;margin-top:4px;"></div>
    </div>

    <div class="field">
      <label><input type="checkbox" id="delivery-toggle"> Giao hàng</label>
      <div id="delivery-fields" style="display:none;margin-top:8px;">
        <input type="text" id="delivery-address" class="input" placeholder="Địa chỉ giao hàng" style="margin-bottom:8px;">
        <input type="number" min="0" id="shipping-fee" class="input" placeholder="Phí giao hàng (VNĐ)">
      </div>
    </div>

    <div class="field">
      <label>Ghi chú đơn hàng</label>
      <input type="text" id="order-note" class="input" placeholder="Ghi chú cho đơn hàng này...">
    </div>

    <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;">
      <span>Tạm tính</span>
      <span id="cart-subtotal">0</span>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px;color:#dc2626;">
      <span>Chiết khấu</span>
      <span id="coupon-discount">0</span>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px;" id="shipping-fee-row">
      <span>Phí giao hàng</span>
      <span id="shipping-fee-display">0</span>
    </div>
    <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:12px;margin-bottom:12px;">
      <span>Tổng tiền</span>
      <span id="cart-total" style="font-size:20px;font-weight:700;color:#2563eb;">0</span>
    </div>

    <div class="field">
      <label>Tiền khách đưa (F2)</label>
      <input type="number" min="0" id="cash-given" class="input" placeholder="0">
    </div>
    <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:16px;">
      <span>Tiền thối lại</span>
      <span id="cash-change" style="font-weight:600;">0</span>
    </div>

    <button id="checkout-btn" class="btn" style="width:100%;padding:12px;font-weight:600;" <?= $branchId ? '' : 'disabled' ?>>Thanh toán (F1)</button>
    <p class="muted" style="font-size:11px;margin-top:8px;text-align:center;">
      F1 Thanh toán · F2 Tiền khách đưa · F3 Tìm sản phẩm · F4 SĐT khách · F6 Chiết khấu · F7 Đổi hình thức TT · F8 Khuyến mại · F9 Thêm dịch vụ
    </p>
  </div>
</div>

<script>
const csrfToken = <?= json_encode(csrfToken()) ?>;
const requireCustomerPhone = <?= json_encode(getSetting('require_customer_phone', '0') === '1') ?>;
const autoPrintReceipt = <?= json_encode(getSetting('auto_print_receipt', '0') === '1') ?>;
function makeEmptyOrder() {
  return {
    cart: [], customerPhone: '', priceListId: null, customerId: null, customerPoints: 0, paymentMethod: 'CASH',
    manualDiscountType: 'AMOUNT', manualDiscountValue: '', appliedCoupon: null, couponInput: '',
    isDelivery: false, deliveryAddress: '', shippingFee: '', note: '', cashGiven: '',
  };
}

let orders = [makeEmptyOrder()];
let currentOrderIndex = 0;
let cart = orders[0].cart;

function saveCurrentOrderState() {
  const o = orders[currentOrderIndex];
  o.customerPhone = document.getElementById('customer-phone').value;
  o.priceListId = currentPriceListId;
  o.customerId = currentCustomerId;
  o.customerPoints = currentCustomerPoints;
  o.paymentMethod = document.getElementById('payment-method').value;
  o.manualDiscountType = document.getElementById('manual-discount-type').value;
  o.manualDiscountValue = document.getElementById('manual-discount-value').value;
  o.appliedCoupon = appliedCoupon;
  o.couponInput = document.getElementById('coupon-input').value;
  o.isDelivery = document.getElementById('delivery-toggle').checked;
  o.deliveryAddress = document.getElementById('delivery-address').value;
  o.shippingFee = document.getElementById('shipping-fee').value;
  o.note = document.getElementById('order-note').value;
  o.cashGiven = document.getElementById('cash-given').value;
}

function loadOrderState(idx) {
  const o = orders[idx];
  cart = o.cart;
  currentPriceListId = o.priceListId;
  currentCustomerId = o.customerId;
  currentCustomerPoints = o.customerPoints;
  appliedCoupon = o.appliedCoupon;
  document.getElementById('customer-phone').value = o.customerPhone;
  document.getElementById('customer-info').textContent = '';
  document.getElementById('payment-method').value = o.paymentMethod;
  document.getElementById('manual-discount-type').value = o.manualDiscountType;
  document.getElementById('manual-discount-value').value = o.manualDiscountValue;
  document.getElementById('coupon-input').value = o.couponInput;
  document.getElementById('coupon-message').textContent = appliedCoupon ? `Đã áp dụng mã ${appliedCoupon.code}, giảm ${formatMoney(appliedCoupon.discount)}` : '';
  document.getElementById('coupon-message').style.color = '#059669';
  document.getElementById('delivery-toggle').checked = o.isDelivery;
  document.getElementById('delivery-fields').style.display = o.isDelivery ? 'block' : 'none';
  document.getElementById('delivery-address').value = o.deliveryAddress;
  document.getElementById('shipping-fee').value = o.shippingFee;
  document.getElementById('order-note').value = o.note;
  document.getElementById('cash-given').value = o.cashGiven;
  document.getElementById('pos-message').innerHTML = '';
  renderCart();
  renderTabs();
}

function renderTabs() {
  const el = document.getElementById('order-tabs');
  el.innerHTML = orders.map((o, i) => `
    <span class="order-tab" data-idx="${i}" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;
      background:${i === currentOrderIndex ? '#2563eb' : '#f1f5f9'};color:${i === currentOrderIndex ? '#fff' : '#334155'};">
      Đơn ${i + 1}${o.cart.length ? ' (' + o.cart.length + ')' : ''}
      ${orders.length > 1 ? `<span class="order-tab-close" data-idx="${i}" style="opacity:.7;">×</span>` : ''}
    </span>`).join('') + `<span id="order-tab-add" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;background:#f1f5f9;cursor:pointer;font-weight:700;">+</span>`;

  el.querySelectorAll('.order-tab').forEach(tab => {
    tab.addEventListener('click', (e) => {
      if (e.target.classList.contains('order-tab-close')) return;
      const idx = parseInt(tab.dataset.idx, 10);
      if (idx === currentOrderIndex) return;
      saveCurrentOrderState();
      currentOrderIndex = idx;
      loadOrderState(idx);
    });
  });
  el.querySelectorAll('.order-tab-close').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const idx = parseInt(btn.dataset.idx, 10);
      if (orders[idx].cart.length && !confirm('Đơn này còn sản phẩm chưa thanh toán, đóng và bỏ đơn này?')) return;
      orders.splice(idx, 1);
      if (currentOrderIndex >= idx) { currentOrderIndex = Math.max(0, currentOrderIndex - 1); }
      loadOrderState(currentOrderIndex);
    });
  });
  document.getElementById('order-tab-add').addEventListener('click', () => {
    saveCurrentOrderState();
    orders.push(makeEmptyOrder());
    currentOrderIndex = orders.length - 1;
    loadOrderState(currentOrderIndex);
  });
}

const searchInput = document.getElementById('search-input');
const searchResults = document.getElementById('search-results');
let searchTimer = null;
let currentPriceListId = null;
let currentCustomerId = null;
let currentCustomerPoints = 0;

const customerPhoneInput = document.getElementById('customer-phone');
let phoneTimer = null;
customerPhoneInput.addEventListener('input', () => {
  clearTimeout(phoneTimer);
  const phone = customerPhoneInput.value.trim();
  currentPriceListId = null;
  currentCustomerId = null;
  currentCustomerPoints = 0;
  document.getElementById('customer-info').textContent = '';
  if (phone.length < 6) return;
  phoneTimer = setTimeout(() => {
    fetch('customer_lookup.php?phone=' + encodeURIComponent(phone))
      .then(r => r.json())
      .then(data => {
        const infoEl = document.getElementById('customer-info');
        if (data.found) {
          currentPriceListId = data.price_list_id;
          currentCustomerId = data.id;
          currentCustomerPoints = data.loyalty_points;
          infoEl.textContent = data.name + (data.group_name ? ' · Nhóm: ' + data.group_name : '') + ' · Điểm: ' + data.loyalty_points + (data.price_list_id ? ' · Áp dụng bảng giá riêng' : '');
        } else {
          infoEl.textContent = 'Khách hàng mới';
        }
      });
  }, 400);
});

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = searchInput.value.trim();
  if (!q) { searchResults.style.display = 'none'; return; }
  searchTimer = setTimeout(() => doSearch(q), 250);
});

// Máy quét mã vạch gõ nhanh mã rồi tự bấm Enter — bắt sự kiện này để tự thêm
// đúng 1 sản phẩm khớp mã vào giỏ hàng ngay, không cần chọn bằng chuột.
searchInput.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  clearTimeout(searchTimer);
  const q = searchInput.value.trim();
  if (!q) return;
  fetch(searchUrl(q))
    .then(r => r.json())
    .then(data => {
      if (data.length === 1) {
        const p = data[0];
        addToCart(p.id, p.variant_id, p.name, parseFloat(p.sell_price));
        searchInput.value = '';
        searchResults.style.display = 'none';
      } else if (data.length > 1) {
        const exact = data.find(p => p.sku === q);
        if (exact) {
          addToCart(exact.id, exact.variant_id, exact.name, parseFloat(exact.sell_price));
          searchInput.value = '';
          searchResults.style.display = 'none';
        } else {
          doSearch(q);
        }
      }
    });
});

function searchUrl(q) {
  let url = 'pos_search.php?q=' + encodeURIComponent(q);
  if (currentPriceListId) { url += '&price_list_id=' + currentPriceListId; }
  return url;
}

function doSearch(q) {
  fetch(searchUrl(q))
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
        <td class="text-right"><input type="number" min="0" value="${c.price}" data-idx="${i}" class="price-input" title="Đổi giá bán hàng" style="width:90px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:6px;"></td>
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
    body.querySelectorAll('.price-input').forEach(inp => {
      inp.addEventListener('change', () => {
        const idx = parseInt(inp.dataset.idx, 10);
        cart[idx].price = Math.max(0, parseFloat(inp.value) || 0);
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
  renderTabs();
  updateTotals();
}

let appliedCoupon = null;

function getManualDiscount(subTotal) {
  const val = parseFloat(document.getElementById('manual-discount-value').value) || 0;
  const type = document.getElementById('manual-discount-type').value;
  const amount = type === 'PERCENT' ? subTotal * val / 100 : val;
  return Math.max(0, Math.min(amount, subTotal));
}

function getShippingFee() {
  if (!document.getElementById('delivery-toggle').checked) return 0;
  return parseFloat(document.getElementById('shipping-fee').value) || 0;
}

function updateTotals() {
  const subTotal = cart.reduce((s, c) => s + c.price * c.qty, 0);
  const manualDiscount = getManualDiscount(subTotal);
  const couponDiscount = appliedCoupon ? appliedCoupon.discount : 0;
  const discount = Math.min(subTotal, manualDiscount + couponDiscount);
  const shippingFee = getShippingFee();
  const total = Math.max(0, subTotal - discount) + shippingFee;
  document.getElementById('cart-subtotal').textContent = formatMoney(subTotal);
  document.getElementById('coupon-discount').textContent = formatMoney(discount);
  document.getElementById('shipping-fee-display').textContent = formatMoney(shippingFee);
  document.getElementById('cart-total').textContent = formatMoney(total);
  updateChange();
}

document.getElementById('manual-discount-value').addEventListener('input', updateTotals);
document.getElementById('manual-discount-type').addEventListener('change', updateTotals);
document.getElementById('shipping-fee').addEventListener('input', updateTotals);
document.getElementById('delivery-toggle').addEventListener('change', (e) => {
  document.getElementById('delivery-fields').style.display = e.target.checked ? 'block' : 'none';
  updateTotals();
});

function updateChange() {
  const subTotal = cart.reduce((s, c) => s + c.price * c.qty, 0);
  const discount = Math.min(subTotal, getManualDiscount(subTotal) + (appliedCoupon ? appliedCoupon.discount : 0));
  const total = Math.max(0, subTotal - discount) + getShippingFee();
  const given = parseFloat(document.getElementById('cash-given').value) || 0;
  const change = given - Math.max(0, total);
  document.getElementById('cash-change').textContent = formatMoney(Math.max(0, change));
}
document.getElementById('cash-given').addEventListener('input', updateChange);

document.getElementById('coupon-apply-btn').addEventListener('click', () => {
  const code = document.getElementById('coupon-input').value.trim();
  const msgEl = document.getElementById('coupon-message');
  msgEl.textContent = '';
  if (!code) { appliedCoupon = null; updateTotals(); return; }

  const subTotal = cart.reduce((s, c) => s + c.price * c.qty, 0);
  fetch('coupon_check.php?code=' + encodeURIComponent(code) + '&sub_total=' + subTotal)
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        appliedCoupon = null;
        msgEl.style.color = '#dc2626';
        msgEl.textContent = data.error;
      } else {
        appliedCoupon = { code: data.code, discount: data.discount };
        msgEl.style.color = '#059669';
        msgEl.textContent = `Đã áp dụng mã ${data.code}, giảm ${formatMoney(data.discount)}`;
      }
      updateTotals();
    });
});

document.getElementById('checkout-btn').addEventListener('click', () => {
  const msgBox = document.getElementById('pos-message');
  msgBox.innerHTML = '';
  if (!cart.length) return;
  if (requireCustomerPhone && !document.getElementById('customer-phone').value.trim()) {
    msgBox.innerHTML = '<div class="alert alert-error">Vui lòng nhập SĐT khách hàng trước khi thanh toán (bắt buộc theo cấu hình bán hàng).</div>';
    return;
  }

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
      coupon_code: appliedCoupon ? appliedCoupon.code : null,
      manual_discount_type: document.getElementById('manual-discount-type').value,
      manual_discount_value: parseFloat(document.getElementById('manual-discount-value').value) || 0,
      is_delivery: document.getElementById('delivery-toggle').checked,
      delivery_address: document.getElementById('delivery-address').value,
      shipping_fee: getShippingFee(),
      note: document.getElementById('order-note').value,
    }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        msgBox.innerHTML = `<div class="alert alert-error">${escapeHtml(data.error)}</div>`;
      } else {
        msgBox.innerHTML = `<div class="alert alert-success">Đã tạo đơn hàng ${escapeHtml(data.code)} thành công! <a href="order_print.php?id=${data.order_id}" target="_blank">In hóa đơn</a></div>`;
        if (autoPrintReceipt) { window.open('order_print.php?id=' + data.order_id, '_blank'); }
        // Đơn đã thanh toán xong: đóng tab này (hoặc reset nếu là tab duy nhất) rồi chuyển sang đơn kế tiếp.
        const successMsg = msgBox.innerHTML;
        if (orders.length > 1) {
          orders.splice(currentOrderIndex, 1);
          currentOrderIndex = Math.min(currentOrderIndex, orders.length - 1);
        } else {
          orders[0] = makeEmptyOrder();
          currentOrderIndex = 0;
        }
        loadOrderState(currentOrderIndex);
        msgBox.innerHTML = successMsg;
      }
    })
    .catch(() => { msgBox.innerHTML = '<div class="alert alert-error">Có lỗi xảy ra, vui lòng thử lại.</div>'; })
    .finally(() => { btn.disabled = false; btn.textContent = 'Thanh toán'; });
});

renderTabs();

document.getElementById('tab-browse-off').addEventListener('click', () => setBrowseMode(false));
document.getElementById('tab-browse-on').addEventListener('click', () => setBrowseMode(true));

let browseLoaded = false;
function setBrowseMode(on) {
  document.getElementById('cart-view').style.display = on ? 'none' : 'block';
  document.getElementById('product-browse').style.display = on ? 'block' : 'none';
  const offBtn = document.getElementById('tab-browse-off');
  const onBtn = document.getElementById('tab-browse-on');
  offBtn.style.background = on ? '' : '#2563eb';
  offBtn.style.color = on ? '' : '#fff';
  onBtn.style.background = on ? '#2563eb' : '';
  onBtn.style.color = on ? '#fff' : '';
  if (on && !browseLoaded) {
    browseLoaded = true;
    fetch('pos_search.php?browse=1')
      .then(r => r.json())
      .then(data => {
        const el = document.getElementById('browse-list');
        if (!data.length) { el.innerHTML = '<div class="muted" style="padding:16px;text-align:center;">Chưa có sản phẩm nào.</div>'; return; }
        el.innerHTML = data.map((p, i) => `
          <div class="browse-item" data-i="${i}" style="padding:10px 12px;font-size:14px;cursor:pointer;display:flex;justify-content:space-between;border-top:1px solid #f1f5f9;">
            <span>${escapeHtml(p.name)} <span class="muted" style="font-family:monospace;font-size:12px;">(${escapeHtml(p.sku)})</span></span>
            <span class="muted">${formatMoney(p.sell_price)} · Tồn: ${p.qty}</span>
          </div>`).join('');
        el.querySelectorAll('.browse-item').forEach(row => {
          row.addEventListener('mouseenter', () => row.style.background = '#f8fafc');
          row.addEventListener('mouseleave', () => row.style.background = '#fff');
          row.addEventListener('click', () => {
            const p = data[parseInt(row.dataset.i, 10)];
            addToCart(p.id, p.variant_id, p.name, parseFloat(p.sell_price));
          });
        });
      });
  }
}

document.getElementById('qa-promotions').addEventListener('click', () => {
  const panel = document.getElementById('promotions-panel');
  const isOpen = panel.style.display !== 'none';
  if (isOpen) { panel.style.display = 'none'; return; }
  panel.style.display = 'block';
  panel.innerHTML = '<div class="muted">Đang tải...</div>';
  fetch('pos_promotions.php')
    .then(r => r.json())
    .then(data => {
      if (!data.length) {
        panel.innerHTML = '<div class="muted">Hiện chưa có chương trình khuyến mại tự động nào đang áp dụng.</div>';
        return;
      }
      panel.innerHTML = '<b style="font-size:13px;">Khuyến mại tự động đang áp dụng (tự cộng khi đủ điều kiện):</b>' +
        data.map(p => `<div style="margin-top:6px;font-size:13px;">• ${escapeHtml(p.name)}: giảm ${p.discount_percent}% cho đơn từ ${formatMoney(p.min_order_amount)}</div>`).join('');
    });
});

document.getElementById('qa-gift').addEventListener('click', () => {
  const panel = document.getElementById('gift-panel');
  const isOpen = panel.style.display !== 'none';
  if (isOpen) { panel.style.display = 'none'; return; }
  panel.style.display = 'block';
  if (!currentCustomerId) {
    panel.innerHTML = '<div class="muted">Vui lòng nhập đúng SĐT khách hàng đã có trong hệ thống trước khi đổi quà.</div>';
    return;
  }
  panel.innerHTML = '<div class="muted">Đang tải danh sách quà...</div>';
  fetch('pos_gifts.php')
    .then(r => r.json())
    .then(data => {
      const affordable = data.filter(g => currentCustomerPoints >= g.points_required);
      panel.innerHTML = `<b style="font-size:13px;">Khách hàng đang có ${currentCustomerPoints} điểm</b>`;
      if (!data.length) {
        panel.innerHTML += '<div class="muted" style="margin-top:6px;">Chưa có quà tặng nào trong danh mục. <a href="gifts.php">Thêm quà tặng</a>.</div>';
        return;
      }
      panel.innerHTML += '<div style="margin-top:8px;">' + data.map(g => {
        const can = currentCustomerPoints >= g.points_required;
        return `<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-top:1px solid #f1f5f9;">
          <span style="font-size:13px;${can ? '' : 'color:#94a3b8;'}">${escapeHtml(g.name)} — ${g.points_required} điểm${g.stock_qty !== null ? ' (còn ' + g.stock_qty + ')' : ''}</span>
          <button type="button" class="btn ${can ? '' : 'btn-secondary'}" data-gift-id="${g.id}" data-gift-name="${escapeHtml(g.name)}" ${can ? '' : 'disabled'} style="padding:4px 10px;font-size:12px;">Đổi ngay</button>
        </div>`;
      }).join('') + '</div>';
      panel.querySelectorAll('button[data-gift-id]').forEach(btn => {
        btn.addEventListener('click', () => {
          if (!confirm(`Đổi ${btn.dataset.giftName} cho khách hàng này?`)) return;
          fetch('redeem_gift.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf: csrfToken, customer_id: currentCustomerId, gift_id: parseInt(btn.dataset.giftId, 10) }),
          })
            .then(r => r.json())
            .then(res => {
              if (res.error) {
                alert(res.error);
              } else {
                currentCustomerPoints = res.remaining_points;
                document.getElementById('customer-info').textContent = 'Đã đổi quà "' + res.gift_name + '" — còn lại ' + res.remaining_points + ' điểm';
                panel.style.display = 'none';
              }
            });
        });
      });
    });
});

document.getElementById('qa-clear-cart').addEventListener('click', () => {
  if (!cart.length) return;
  if (!confirm('Xóa toàn bộ sản phẩm khỏi đơn hàng này?')) return;
  cart.length = 0;
  renderCart();
});

let servicesLoaded = false;
document.getElementById('qa-add-service').addEventListener('click', () => {
  const picker = document.getElementById('service-picker');
  const isOpen = picker.style.display !== 'none';
  if (isOpen) { picker.style.display = 'none'; return; }
  picker.style.display = 'block';
  if (servicesLoaded) return;
  fetch('pos_services.php')
    .then(r => r.json())
    .then(data => {
      const sel = document.getElementById('service-select');
      if (!data.length) {
        sel.innerHTML = '<option value="">Chưa có dịch vụ nào — tạo tại Danh sách sản phẩm (loại Dịch vụ)</option>';
        return;
      }
      sel.innerHTML = '<option value="">— Chọn dịch vụ —</option>' + data.map(s =>
        `<option value="${s.id}" data-name="${escapeHtml(s.name)}" data-price="${s.sell_price}">${escapeHtml(s.name)} (${formatMoney(s.sell_price)})</option>`
      ).join('');
      servicesLoaded = true;
    });
});
document.getElementById('service-select').addEventListener('change', (e) => {
  const opt = e.target.selectedOptions[0];
  if (!opt || !opt.value) return;
  addToCart(parseInt(opt.value, 10), null, opt.dataset.name, parseFloat(opt.dataset.price));
  document.getElementById('service-picker').style.display = 'none';
  e.target.value = '';
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

// Phím tắt bán hàng kiểu Sapo
document.addEventListener('keydown', (e) => {
  const key = e.key;
  if (!['F1', 'F2', 'F3', 'F4', 'F6', 'F7', 'F8', 'F9'].includes(key)) return;
  e.preventDefault();
  switch (key) {
    case 'F1':
      document.getElementById('checkout-btn').click();
      break;
    case 'F2':
      document.getElementById('cash-given').focus();
      break;
    case 'F3':
      searchInput.focus();
      break;
    case 'F4':
      document.getElementById('customer-phone').focus();
      break;
    case 'F6':
      document.getElementById('manual-discount-value').focus();
      break;
    case 'F7': {
      const sel = document.getElementById('payment-method');
      sel.selectedIndex = (sel.selectedIndex + 1) % sel.options.length;
      break;
    }
    case 'F8':
      document.getElementById('qa-promotions').click();
      break;
    case 'F9':
      document.getElementById('qa-add-service').click();
      break;
  }
});
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
