<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Màn hình khách hàng</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #0f172a; color: #fff; height: 100vh; display: flex; flex-direction: column; }
  .header { padding: 20px 32px; font-size: 22px; font-weight: 700; border-bottom: 1px solid #1e293b; }
  .items { flex: 1; overflow-y: auto; padding: 16px 32px; }
  .item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #1e293b; font-size: 20px; }
  .item .name { flex: 1; }
  .item .qty { color: #94a3b8; margin: 0 16px; }
  .empty { color: #64748b; text-align: center; padding: 60px 0; font-size: 22px; }
  .footer { padding: 24px 32px; border-top: 2px solid #2563eb; }
  .row { display: flex; justify-content: space-between; font-size: 20px; margin-bottom: 6px; color: #cbd5e1; }
  .total-row { display: flex; justify-content: space-between; font-size: 36px; font-weight: 800; color: #60a5fa; margin-top: 8px; }
  .status { text-align: center; padding: 10px; font-size: 14px; color: #64748b; }
</style>
</head>
<body>
  <div class="header">🛒 Xin chào quý khách</div>
  <div class="items" id="items"><div class="empty">Đang chờ nhân viên bán hàng...</div></div>
  <div class="footer">
    <div class="row"><span>Tạm tính</span><span id="subtotal">0</span></div>
    <div class="row"><span>Chiết khấu</span><span id="discount">0</span></div>
    <div class="total-row"><span>TỔNG TIỀN</span><span id="total">0</span></div>
  </div>
  <div class="status" id="status">Chưa kết nối tới màn hình bán hàng</div>

  <script>
    function formatMoney(n) { return Math.round(n).toLocaleString('vi-VN'); }
    const channel = new BroadcastChannel('qlbh2_pos_display');
    channel.onmessage = (e) => {
      const data = e.data;
      document.getElementById('status').textContent = 'Đã kết nối · cập nhật ' + new Date().toLocaleTimeString('vi-VN');
      const itemsEl = document.getElementById('items');
      if (!data.cart || !data.cart.length) {
        itemsEl.innerHTML = '<div class="empty">Đơn hàng chưa có sản phẩm</div>';
      } else {
        itemsEl.innerHTML = data.cart.map(c => `
          <div class="item"><span class="name">${c.name}</span><span class="qty">x${c.qty}</span><span>${formatMoney(c.price * c.qty)}</span></div>
        `).join('');
      }
      document.getElementById('subtotal').textContent = formatMoney(data.subTotal || 0);
      document.getElementById('discount').textContent = formatMoney(data.discount || 0);
      document.getElementById('total').textContent = formatMoney(data.total || 0);
    };
  </script>
</body>
</html>
