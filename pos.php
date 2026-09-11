<?php
require_once __DIR__ . '/inc_header.php';

$pdo = db();
$branchId = effectiveBranchId($currentUser);
$canSwitchBranch = hasRole('ADMIN', 'MANAGER');
$allBranches = $canSwitchBranch ? $pdo->query('SELECT * FROM branches ORDER BY name')->fetchAll() : [];
$showColStt = getSetting('show_column_stt', '1') === '1';
$showColSku = getSetting('show_column_sku', '0') === '1';
$qa = fn(string $key) => getSetting($key, '1') === '1';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
  <h1 style="font-size:24px;font-weight:600;margin:0;">Bán hàng tại quầy</h1>
  <?php if ($canSwitchBranch): ?>
    <form method="post" action="pos_switch_branch.php" style="display:flex;align-items:center;gap:8px;">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <label class="muted" style="font-size:13px;margin:0;">Đang bán tại:</label>
      <select name="branch_id" class="input" style="max-width:200px;" onchange="this.form.submit()">
        <?php foreach ($allBranches as $b): ?>
          <option value="<?= (int) $b['id'] ?>" <?= $branchId === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>
</div>

<?php if (!$branchId): ?>
  <div class="alert alert-warning">Tài khoản của bạn chưa được gán chi nhánh, không thể bán hàng. Liên hệ quản trị viên.</div>
<?php endif; ?>

<div id="offline-banner" style="display:none;" class="alert alert-warning">
  ⚠️ Đang bán hàng Offline — đơn hàng được lưu tạm trên máy này, sẽ tự động đồng bộ lên hệ thống
  khi có mạng trở lại. <span id="offline-queue-count"></span>
  <a href="#" id="offline-sync-now" style="margin-left:8px;">Đồng bộ ngay</a>
</div>

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
          <tr>
            <?php if ($showColStt): ?><th style="width:36px;">STT</th><?php endif; ?>
            <?php if ($showColSku): ?><th>Mã hàng</th><?php endif; ?>
            <th>Sản phẩm</th><th class="text-right">Đơn giá</th><th class="text-center">SL</th><th class="text-right">Thành tiền</th><th></th>
          </tr>
        </thead>
        <tbody id="cart-body">
          <tr id="cart-empty"><td colspan="<?= 4 + ($showColStt ? 1 : 0) + ($showColSku ? 1 : 0) ?>" class="text-center muted" style="padding:40px;">Đơn hàng chưa có sản phẩm</td></tr>
        </tbody>
      </table>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-top:16px;">
      <?php if ($qa('qa_add_service')): ?><button type="button" id="qa-add-service" class="btn btn-secondary">Thêm dịch vụ (F9)</button><?php endif; ?>
      <?php if ($qa('qa_promotions')): ?><button type="button" id="qa-promotions" class="btn btn-secondary">Khuyến mại (F8)</button><?php endif; ?>
      <?php if ($qa('qa_gift')): ?><button type="button" id="qa-gift" class="btn btn-secondary">Đổi quà</button><?php endif; ?>
      <?php if ($qa('qa_clear_cart')): ?><button type="button" id="qa-clear-cart" class="btn btn-secondary">Xóa toàn bộ sản phẩm</button><?php endif; ?>
      <?php if ($qa('qa_customers')): ?><a href="customers.php" class="btn btn-secondary" style="text-align:center;">Thông tin khách hàng</a><?php endif; ?>
      <?php if ($qa('qa_returns')): ?><a href="order_returns.php" class="btn btn-secondary" style="text-align:center;">Đổi trả hàng</a><?php endif; ?>
      <?php if ($qa('qa_orders')): ?><a href="orders.php" class="btn btn-secondary" style="text-align:center;">Xem danh sách đơn hàng</a><?php endif; ?>
      <?php if ($qa('qa_reports')): ?><a href="reports.php" class="btn btn-secondary" style="text-align:center;">Xem báo cáo</a><?php endif; ?>
      <?php if ($qa('qa_sales_settings')): ?><a href="sales_settings.php" class="btn btn-secondary" style="text-align:center;">Thiết lập chung</a><?php endif; ?>
      <?php if ($qa('qa_cashbook')): ?><a href="cashbook.php" class="btn btn-secondary" style="text-align:center;">Tạo phiếu thu/chi</a><?php endif; ?>
      <?php if ($qa('qa_print_last')): ?><button type="button" id="qa-print-last" class="btn btn-secondary" disabled>In đơn gần nhất (Alt+1)</button><?php endif; ?>
      <?php if ($qa('qa_customer_display')): ?><button type="button" id="qa-customer-display" class="btn btn-secondary">Kết nối màn hình phụ</button><?php endif; ?>
      <?php if ($qa('qa_qr_payment')): ?><button type="button" id="qa-qr-payment" class="btn btn-secondary">Hiện mã QR thanh toán</button><?php endif; ?>
      <?php if ($qa('qa_batches')): ?><button type="button" id="qa-batches" class="btn btn-secondary">Chọn lô tự động (Alt+5)</button><?php endif; ?>
      <?php if ($qa('qa_offline')): ?><button type="button" id="qa-offline" class="btn btn-secondary">Bán hàng Offline</button><?php endif; ?>
    </div>
    <div id="service-picker" style="display:none;margin-top:8px;" class="card">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Chọn dịch vụ để thêm vào đơn</label>
      <select id="service-select" class="input">
        <option value="">— Đang tải danh sách dịch vụ —</option>
      </select>
    </div>
    <div id="promotions-panel" style="display:none;margin-top:8px;" class="card"></div>
    <div id="gift-panel" style="display:none;margin-top:8px;" class="card"></div>
    <div id="qr-panel" style="display:none;margin-top:8px;text-align:center;" class="card"></div>
    <div id="batches-panel" style="display:none;margin-top:8px;" class="card"></div>
    <div id="offline-panel" style="display:none;margin-top:8px;" class="card"></div>
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

    <div class="field">
      <label>Tags đơn hàng (cách nhau bằng dấu phẩy)</label>
      <input type="text" id="order-tags" class="input" placeholder="vd: khach quen, giao gap">
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
      <label><input type="checkbox" id="partial-payment-toggle"> Cho khách nợ một phần</label>
      <div id="partial-payment-fields" style="display:none;margin-top:8px;">
        <input type="number" min="0" id="paid-amount" class="input" placeholder="Khách trả trước (VNĐ)">
        <p class="muted" style="font-size:11px;margin:4px 0 0;">Chỉ áp dụng khi đã chọn khách hàng (nhập SĐT) — phần còn lại sẽ ghi vào công nợ khách hàng.</p>
      </div>
    </div>

    <div class="field">
      <label>Tiền khách đưa (F2)</label>
      <input type="number" min="0" id="cash-given" class="input" placeholder="0">
      <div id="cash-suggestions" style="display:none;gap:6px;margin-top:6px;flex-wrap:wrap;"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:16px;">
      <span>Tiền thối lại</span>
      <span id="cash-change" style="font-weight:600;">0</span>
    </div>

    <button id="checkout-btn" class="btn" style="width:100%;padding:12px;font-weight:600;" <?= $branchId ? '' : 'disabled' ?>>Thanh toán (F1)</button>
    <button id="draft-btn" class="btn btn-secondary" style="width:100%;padding:10px;font-weight:600;margin-top:8px;" <?= $branchId ? '' : 'disabled' ?>>Đặt hàng — xử lý sau (chưa thu tiền, chưa giao)</button>
    <p class="muted" style="font-size:11px;margin-top:8px;text-align:center;">
      F1 Thanh toán · F2 Tiền khách đưa · F3 Tìm sản phẩm · F4 SĐT khách · F6 Chiết khấu · F7 Đổi hình thức TT · F8 Khuyến mại · F9 Thêm dịch vụ · Alt+1 In đơn gần nhất
    </p>
  </div>
</div>

<script>
const csrfToken = <?= json_encode(csrfToken()) ?>;
const requireCustomerPhone = <?= json_encode(getSetting('require_customer_phone', '0') === '1') ?>;
const autoPrintReceipt = <?= json_encode(getSetting('auto_print_receipt', '0') === '1') ?>;
const suggestCashAmounts = <?= json_encode(getSetting('suggest_cash_amounts', '0') === '1') ?>;
const defaultDiscountUnit = <?= json_encode(getSetting('default_discount_unit', 'AMOUNT')) ?>;
const bankCode = <?= json_encode(getSetting('bank_code', '')) ?>;
const bankAccount = <?= json_encode(getSetting('bank_account', '')) ?>;
const bankAccountName = <?= json_encode(getSetting('bank_account_name', '')) ?>;
const showColStt = <?= json_encode($showColStt) ?>;
const showColSku = <?= json_encode($showColSku) ?>;
let lastOrderId = null;
function on(id, ev, fn) { const el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }

const displayChannel = ('BroadcastChannel' in window) ? new BroadcastChannel('qlbh2_pos_display') : null;

// ===== Bán hàng Offline =====
// Khi mất mạng (thật sự mất kết nối, hoặc bật tay để test), đơn hàng được lưu tạm vào
// localStorage thay vì gọi pos_checkout.php ngay. Khi có mạng trở lại, tự động đồng bộ lần lượt
// từng đơn lên server theo đúng thứ tự đã tạo.
const OFFLINE_QUEUE_KEY = 'qlbh2_offline_queue_' + <?= json_encode((int) $branchId) ?>;
let forceOfflineMode = false;
let isSyncing = false;

function isOfflineMode() { return forceOfflineMode || !navigator.onLine; }

function getOfflineQueue() {
  try { return JSON.parse(localStorage.getItem(OFFLINE_QUEUE_KEY) || '[]'); } catch (e) { return []; }
}
function saveOfflineQueue(queue) {
  try { localStorage.setItem(OFFLINE_QUEUE_KEY, JSON.stringify(queue)); } catch (e) {}
}
function addToOfflineQueue(payload) {
  const queue = getOfflineQueue();
  queue.push({ localId: 'off_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8), createdAt: new Date().toISOString(), payload, error: null });
  saveOfflineQueue(queue);
}
function removeFromOfflineQueue(localId) {
  saveOfflineQueue(getOfflineQueue().filter(q => q.localId !== localId));
}

function updateOfflineBanner() {
  const queue = getOfflineQueue();
  const banner = document.getElementById('offline-banner');
  const shouldShow = isOfflineMode() || queue.length > 0;
  banner.style.display = shouldShow ? 'block' : 'none';
  document.getElementById('offline-queue-count').textContent = queue.length ? `(${queue.length} đơn đang chờ đồng bộ)` : '';
}

function syncOfflineQueue() {
  if (isSyncing || isOfflineMode()) return;
  const queue = getOfflineQueue();
  if (!queue.length) { updateOfflineBanner(); return; }
  isSyncing = true;

  const syncNext = (i) => {
    if (i >= queue.length) {
      isSyncing = false;
      updateOfflineBanner();
      return;
    }
    const item = queue[i];
    fetch('pos_checkout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(item.payload),
    })
      .then(r => r.json())
      .then(data => {
        if (data.error) {
          item.error = data.error;
          const all = getOfflineQueue().map(q => q.localId === item.localId ? item : q);
          saveOfflineQueue(all);
        } else {
          removeFromOfflineQueue(item.localId);
        }
        syncNext(i + 1);
      })
      .catch(() => { isSyncing = false; updateOfflineBanner(); });
  };
  syncNext(0);
}

window.addEventListener('online', () => { updateOfflineBanner(); syncOfflineQueue(); });
window.addEventListener('offline', () => { updateOfflineBanner(); });
function makeEmptyOrder() {
  return {
    cart: [], customerPhone: '', priceListId: null, customerId: null, customerPoints: 0, paymentMethod: 'CASH',
    manualDiscountType: defaultDiscountUnit, manualDiscountValue: '', appliedCoupon: null, couponInput: '',
    isDelivery: false, deliveryAddress: '', shippingFee: '', note: '', tags: '', cashGiven: '',
    partialPayment: false, paidAmount: '',
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
  o.tags = document.getElementById('order-tags').value;
  o.cashGiven = document.getElementById('cash-given').value;
  o.partialPayment = document.getElementById('partial-payment-toggle').checked;
  o.paidAmount = document.getElementById('paid-amount').value;
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
  document.getElementById('order-tags').value = o.tags;
  document.getElementById('cash-given').value = o.cashGiven;
  document.getElementById('partial-payment-toggle').checked = o.partialPayment;
  document.getElementById('paid-amount').value = o.paidAmount;
  document.getElementById('partial-payment-fields').style.display = o.partialPayment ? 'block' : 'none';
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
        addToCart(p.id, p.variant_id, p.name, parseFloat(p.sell_price), p.sku);
        searchInput.value = '';
        searchResults.style.display = 'none';
      } else if (data.length > 1) {
        const exact = data.find(p => p.sku === q);
        if (exact) {
          addToCart(exact.id, exact.variant_id, exact.name, parseFloat(exact.sell_price), exact.sku);
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
          addToCart(p.id, p.variant_id, p.name, parseFloat(p.sell_price), p.sku);
          searchInput.value = '';
          searchResults.style.display = 'none';
        });
      });
    });
}

function addToCart(id, variantId, name, price, sku) {
  const key = id + ':' + (variantId ?? '');
  const existing = cart.find(c => c.key === key);
  if (existing) { existing.qty += 1; } else { cart.push({ key, id, variantId, name, price, sku: sku || '', qty: 1 }); }
  renderCart();
}

function renderCart() {
  const body = document.getElementById('cart-body');
  const colspan = 4 + (showColStt ? 1 : 0) + (showColSku ? 1 : 0);
  if (!cart.length) {
    body.innerHTML = `<tr id="cart-empty"><td colspan="${colspan}" class="text-center muted" style="padding:40px;">Đơn hàng chưa có sản phẩm</td></tr>`;
  } else {
    body.innerHTML = cart.map((c, i) => `
      <tr>
        ${showColStt ? `<td class="muted">${i + 1}</td>` : ''}
        ${showColSku ? `<td class="muted" style="font-family:monospace;font-size:12px;">${escapeHtml(c.sku || '')}</td>` : ''}
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
  renderCashSuggestions(total);
  updateChange();
  if (displayChannel) {
    displayChannel.postMessage({ cart, subTotal, discount, total });
  }
}

function renderCashSuggestions(total) {
  const box = document.getElementById('cash-suggestions');
  if (!suggestCashAmounts || total <= 0) { box.style.display = 'none'; box.innerHTML = ''; return; }
  const rounds = [10000, 50000, 100000, 200000, 500000];
  const amounts = new Set([Math.ceil(total / 1000) * 1000]);
  rounds.forEach(r => { if (r >= total) amounts.add(Math.ceil(total / r) * r); });
  const list = Array.from(amounts).filter(a => a >= total).sort((a, b) => a - b).slice(0, 4);
  box.style.display = 'flex';
  box.innerHTML = list.map(a => `<button type="button" class="btn btn-secondary cash-suggest-btn" data-amt="${a}" style="padding:4px 10px;font-size:12px;">${formatMoney(a)}</button>`).join('');
  box.querySelectorAll('.cash-suggest-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('cash-given').value = btn.dataset.amt;
      updateChange();
    });
  });
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

function buildCheckoutPayload() {
  return {
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
    tags: document.getElementById('order-tags').value,
    paid_amount: document.getElementById('partial-payment-toggle').checked
      ? (parseFloat(document.getElementById('paid-amount').value) || 0)
      : null,
  };
}

document.getElementById('partial-payment-toggle').addEventListener('change', (e) => {
  document.getElementById('partial-payment-fields').style.display = e.target.checked ? 'block' : 'none';
});

function resetOrderAfterCheckout() {
  if (orders.length > 1) {
    orders.splice(currentOrderIndex, 1);
    currentOrderIndex = Math.min(currentOrderIndex, orders.length - 1);
  } else {
    orders[0] = makeEmptyOrder();
    currentOrderIndex = 0;
  }
  loadOrderState(currentOrderIndex);
}

document.getElementById('checkout-btn').addEventListener('click', () => {
  const msgBox = document.getElementById('pos-message');
  msgBox.innerHTML = '';
  if (!cart.length) return;
  if (requireCustomerPhone && !document.getElementById('customer-phone').value.trim()) {
    msgBox.innerHTML = '<div class="alert alert-error">Vui lòng nhập SĐT khách hàng trước khi thanh toán (bắt buộc theo cấu hình bán hàng).</div>';
    return;
  }

  const btn = document.getElementById('checkout-btn');

  if (isOfflineMode()) {
    addToOfflineQueue(buildCheckoutPayload());
    msgBox.innerHTML = '<div class="alert alert-success">Không có mạng — đã lưu đơn hàng tạm trên máy này, sẽ tự động đồng bộ khi có mạng trở lại.</div>';
    const successMsg = msgBox.innerHTML;
    resetOrderAfterCheckout();
    msgBox.innerHTML = successMsg;
    updateOfflineBanner();
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Đang xử lý...';

  fetch('pos_checkout.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(buildCheckoutPayload()),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        msgBox.innerHTML = `<div class="alert alert-error">${escapeHtml(data.error)}</div>`;
      } else {
        msgBox.innerHTML = `<div class="alert alert-success">Đã tạo đơn hàng ${escapeHtml(data.code)} thành công! <a href="order_print.php?id=${data.order_id}" target="_blank">In hóa đơn</a></div>`;
        lastOrderId = data.order_id;
        document.getElementById('qa-print-last') && (document.getElementById('qa-print-last').disabled = false);
        if (autoPrintReceipt) { window.open('order_print.php?id=' + data.order_id, '_blank'); }
        const successMsg = msgBox.innerHTML;
        resetOrderAfterCheckout();
        msgBox.innerHTML = successMsg;
      }
    })
    .catch(() => {
      // Mất mạng ngay lúc thanh toán: chuyển sang lưu offline thay vì báo lỗi mất luôn đơn.
      addToOfflineQueue(buildCheckoutPayload());
      msgBox.innerHTML = '<div class="alert alert-success">Không kết nối được máy chủ — đã lưu đơn hàng tạm trên máy này, sẽ tự động đồng bộ khi có mạng trở lại.</div>';
      const successMsg = msgBox.innerHTML;
      resetOrderAfterCheckout();
      msgBox.innerHTML = successMsg;
      updateOfflineBanner();
    })
    .finally(() => { btn.disabled = false; btn.textContent = 'Thanh toán'; });
});

document.getElementById('draft-btn').addEventListener('click', () => {
  const msgBox = document.getElementById('pos-message');
  msgBox.innerHTML = '';
  if (!cart.length) return;

  const btn = document.getElementById('draft-btn');
  btn.disabled = true;
  btn.textContent = 'Đang xử lý...';

  const payload = buildCheckoutPayload();
  payload.draft = true;
  payload.paid_amount = null;

  fetch('pos_checkout.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        msgBox.innerHTML = `<div class="alert alert-error">${escapeHtml(data.error)}</div>`;
      } else {
        msgBox.innerHTML = `<div class="alert alert-success">Đã lưu đơn hàng ${escapeHtml(data.code)} — chờ xử lý. <a href="order_view.php?id=${data.order_id}">Xem đơn hàng</a></div>`;
        const successMsg = msgBox.innerHTML;
        resetOrderAfterCheckout();
        msgBox.innerHTML = successMsg;
      }
    })
    .catch(() => {
      msgBox.innerHTML = '<div class="alert alert-error">Không kết nối được máy chủ, vui lòng thử lại.</div>';
    })
    .finally(() => { btn.disabled = false; btn.textContent = 'Đặt hàng — xử lý sau (chưa thu tiền, chưa giao)'; });
});

document.getElementById('manual-discount-type').value = defaultDiscountUnit;
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
            addToCart(p.id, p.variant_id, p.name, parseFloat(p.sell_price), p.sku);
          });
        });
      });
  }
}

on('qa-promotions', 'click', () => {
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

on('qa-gift', 'click', () => {
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

on('qa-customer-display', 'click', () => {
  if (!displayChannel) { alert('Trình duyệt này không hỗ trợ BroadcastChannel để kết nối màn hình phụ.'); return; }
  window.open('pos_customer_display.php', 'pos_customer_display', 'width=480,height=720');
  updateTotals();
});

on('qa-qr-payment', 'click', () => {
  const panel = document.getElementById('qr-panel');
  const isOpen = panel.style.display !== 'none';
  if (isOpen) { panel.style.display = 'none'; return; }
  if (!bankCode || !bankAccount) {
    panel.style.display = 'block';
    panel.innerHTML = '<div class="muted">Chưa cấu hình tài khoản ngân hàng. <a href="payment_settings.php">Thiết lập ngay</a>.</div>';
    return;
  }
  const subTotal = cart.reduce((s, c) => s + c.price * c.qty, 0);
  const discount = Math.min(subTotal, getManualDiscount(subTotal) + (appliedCoupon ? appliedCoupon.discount : 0));
  const total = Math.round(Math.max(0, subTotal - discount) + getShippingFee());
  if (total <= 0) {
    panel.style.display = 'block';
    panel.innerHTML = '<div class="muted">Giỏ hàng chưa có sản phẩm để tạo mã QR.</div>';
    return;
  }
  const addInfo = encodeURIComponent('Thanh toan don hang');
  const acc = encodeURIComponent(bankAccountName);
  const url = `https://img.vietqr.io/image/${bankCode}-${bankAccount}-compact2.png?amount=${total}&addInfo=${addInfo}&accountName=${acc}`;
  panel.style.display = 'block';
  panel.innerHTML = `<img src="${url}" alt="Mã QR thanh toán" style="max-width:260px;"><div style="margin-top:8px;font-weight:600;">${formatMoney(total)} đ</div><div class="muted" style="font-size:12px;">Quét bằng app ngân hàng bất kỳ — nhân viên tự xác nhận đã nhận tiền trước khi hoàn tất đơn.</div>`;
});

on('qa-batches', 'click', () => {
  const panel = document.getElementById('batches-panel');
  const isOpen = panel.style.display !== 'none';
  if (isOpen) { panel.style.display = 'none'; return; }
  panel.style.display = 'block';
  if (!cart.length) {
    panel.innerHTML = '<div class="muted">Giỏ hàng chưa có sản phẩm.</div>';
    return;
  }
  const productIds = [...new Set(cart.filter(c => !c.variantId).map(c => c.id))];
  panel.innerHTML = '<div class="muted">Đang tải thông tin lô hàng...</div>';
  fetch('pos_batches.php?product_ids=' + productIds.join(','))
    .then(r => r.json())
    .then(data => {
      if (!data.length) {
        panel.innerHTML = '<div class="muted">Các sản phẩm trong giỏ hàng chưa khai báo lô — sẽ bán theo tồn kho thông thường. <a href="batches.php">Khai báo lô hàng</a>.</div>';
        return;
      }
      const byProduct = {};
      data.forEach(b => { (byProduct[b.product_id] = byProduct[b.product_id] || []).push(b); });
      panel.innerHTML = '<b style="font-size:13px;">Lô sẽ tự động được trừ trước (hết hạn sớm nhất trước):</b>' +
        Object.values(byProduct).map(list => {
          const first = list[0];
          return `<div style="margin-top:6px;font-size:13px;">• ${escapeHtml(first.product_name)}: lô <b>${escapeHtml(first.lot_number)}</b>${first.expiry_date ? ' (HSD ' + new Date(first.expiry_date).toLocaleDateString('vi-VN') + ')' : ''} — còn ${first.quantity}</div>`;
        }).join('');
    });
});

on('qa-offline', 'click', () => {
  forceOfflineMode = !forceOfflineMode;
  const btn = document.getElementById('qa-offline');
  if (btn) {
    btn.textContent = forceOfflineMode ? 'Đang Offline (bấm để tắt)' : 'Bán hàng Offline';
    btn.style.background = forceOfflineMode ? '#fef2f2' : '';
    btn.style.color = forceOfflineMode ? '#b91c1c' : '';
  }
  updateOfflineBanner();
  if (!forceOfflineMode) syncOfflineQueue();

  const panel = document.getElementById('offline-panel');
  panel.style.display = 'block';
  const queue = getOfflineQueue();
  if (!queue.length) {
    panel.innerHTML = `<div class="muted">${forceOfflineMode ? 'Đã bật chế độ Offline — đơn hàng thanh toán từ giờ sẽ lưu tạm trên máy này.' : 'Không có đơn nào đang chờ đồng bộ.'}</div>`;
  } else {
    panel.innerHTML = '<b style="font-size:13px;">Đơn hàng đang chờ đồng bộ:</b>' + queue.map(q => `
      <div style="padding:6px 0;border-top:1px solid #f1f5f9;font-size:13px;">
        ${new Date(q.createdAt).toLocaleString('vi-VN')} — ${q.payload.items.length} sản phẩm
        ${q.error ? `<span class="muted" style="color:#dc2626;"> · Lỗi lần trước: ${escapeHtml(q.error)}</span>` : ''}
      </div>`).join('');
  }
});

document.getElementById('offline-sync-now')?.addEventListener('click', (e) => {
  e.preventDefault();
  if (forceOfflineMode) { alert('Đang bật chế độ Offline thủ công — tắt "Bán hàng Offline" trước khi đồng bộ.'); return; }
  syncOfflineQueue();
});

updateOfflineBanner();
syncOfflineQueue();

on('qa-clear-cart', 'click', () => {
  if (!cart.length) return;
  if (!confirm('Xóa toàn bộ sản phẩm khỏi đơn hàng này?')) return;
  cart.length = 0;
  renderCart();
});

let servicesLoaded = false;
on('qa-add-service', 'click', () => {
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

on('qa-print-last', 'click', () => {
  if (lastOrderId) { window.open('order_print.php?id=' + lastOrderId, '_blank'); }
});

// Phím tắt bán hàng kiểu Sapo
document.addEventListener('keydown', (e) => {
  const key = e.key;
  if (e.altKey && key === '1') {
    e.preventDefault();
    document.getElementById('qa-print-last')?.click();
    return;
  }
  if (e.altKey && key === '5') {
    e.preventDefault();
    document.getElementById('qa-batches')?.click();
    return;
  }
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
      document.getElementById('qa-promotions')?.click();
      break;
    case 'F9':
      document.getElementById('qa-add-service')?.click();
      break;
  }
});
</script>

<?php require_once __DIR__ . '/inc_footer.php'; ?>
